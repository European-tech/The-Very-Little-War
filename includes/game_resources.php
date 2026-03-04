<?php
/**
 * Game Resources Module
 * Resource production and update functions.
 */

function revenuEnergie($niveau, $joueur, $detail = 0)
{
    static $cache = [];
    $cacheKey = $joueur . '-' . $niveau . '-' . $detail;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];

    global $base;
    global $paliersEnergievore;
    global $bonusMedailles;
    global $nomsRes;

    $constructions = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $joueur);

    $niveauxAtomes = explode(';', $constructions['pointsCondenseur']);
    foreach ($nomsRes as $num => $ressource) {
        ${'niveau' . $ressource} = $niveauxAtomes[$num];
    }

    $producteur = dbFetchOne($base, 'SELECT producteur FROM constructions WHERE login=?', 's', $joueur);

    $idalliance = dbFetchOne($base, 'SELECT idalliance,totalPoints FROM autre WHERE login=?', 's', $joueur);
    $bonusDuplicateur = 1;
    if ($idalliance['idalliance'] > 0) {
        $duplicateur = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
        $bonusDuplicateur = 1 + bonusDuplicateur($duplicateur['duplicateur']);
    }

    // V4: Iode is now a generator catalyst — multiplicative bonus instead of additive energy
    $totalIodeAtoms = 0;
    for ($i = 1; $i <= 4; $i++) {
        $molecules = dbFetchOne($base, 'SELECT iode, nombre FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $joueur, $i);
        if ($molecules) {
            $totalIodeAtoms += $molecules['iode'] * $molecules['nombre'];
        }
    }
    $iodeCatalystBonus = 1.0 + min(IODE_CATALYST_MAX_BONUS, $totalIodeAtoms / IODE_CATALYST_DIVISOR);

    $donneesMedaille = dbFetchOne($base, 'SELECT energieDepensee FROM autre WHERE login=?', 's', $joueur);
    $bonus = 0;

    foreach ($paliersEnergievore as $num => $palier) {
        if ($donneesMedaille['energieDepensee'] >= $palier) {
            $bonus = $bonusMedailles[$num];
        }
    }

    $prodBase = (BASE_ENERGY_PER_LEVEL * $niveau);
    $prodIode = $prodBase * $iodeCatalystBonus; // V4: multiplicative catalyst
    $prodMedaille = (1 + ($bonus / 100)) * $prodIode;
    $prodDuplicateur = $bonusDuplicateur * $prodMedaille;
    $prodPrestige = $prodDuplicateur * prestigeProductionBonus($joueur);

    // Resource node proximity bonus for energy
    $energyNodeBonus = 0;
    $pos = dbFetchOne($base, 'SELECT x, y FROM membre WHERE login=?', 's', $joueur);
    if ($pos && $pos['x'] >= 0 && $pos['y'] >= 0) {
        require_once(__DIR__ . '/resource_nodes.php');
        $energyNodeBonus = getResourceNodeBonus($base, $pos['x'], $pos['y'], 'energie');
    }
    $prodNodes = $prodPrestige * (1 + $energyNodeBonus);

    $prodProducteur = $prodNodes - drainageProducteur($producteur['producteur']);
    if ($detail == 0) {
        $result = max(0, round($prodProducteur));
    } elseif ($detail == 1) {
        $result = round($prodDuplicateur);
    } elseif ($detail == 2) {
        $result = round($prodMedaille);
    } elseif ($detail == 3) {
        $result = round($prodIode);
    } else {
        $result = round($prodBase);
    }
    $cache[$cacheKey] = $result;
    return $result;
}


function revenuAtome($num, $joueur)
{
    static $cache = [];
    $cacheKey = $joueur . '-' . $num;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];

    global $base;
    global $nomsRes;

    $pointsProducteur = dbFetchOne($base, 'SELECT pointsProducteur FROM constructions WHERE login=?', 's', $joueur);

    $niveau = explode(';', $pointsProducteur['pointsProducteur'])[$num];

    $idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $joueur);
    $bonusDuplicateur = 1;
    if ($idalliance['idalliance'] > 0) {
        $duplicateur = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
        $bonusDuplicateur = 1 + bonusDuplicateur($duplicateur['duplicateur']);
    }

    // Resource node proximity bonus
    $nodeBonus = 0;
    $pos = dbFetchOne($base, 'SELECT x, y FROM membre WHERE login=?', 's', $joueur);
    if ($pos && $pos['x'] >= 0 && $pos['y'] >= 0) {
        require_once(__DIR__ . '/resource_nodes.php');
        $nodeBonus = getResourceNodeBonus($base, $pos['x'], $pos['y'], $nomsRes[$num]);
    }

    $result = round($bonusDuplicateur * BASE_ATOMS_PER_POINT * $niveau * prestigeProductionBonus($joueur) * (1 + $nodeBonus));
    $cache[$cacheKey] = $result;
    return $result;
}

function revenuAtomeJavascript($joueur)
{
    global $base;

    $idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $joueur);
    $bonusDuplicateur = 1;
    if ($idalliance['idalliance'] > 0) {
        $duplicateur = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
        $bonusDuplicateur = 1 + bonusDuplicateur($duplicateur['duplicateur']);
    }

    echo '
    ' . cspScriptTag() . '
    function revenuAtomeJavascript(niveau){
        return Math.round(' . $bonusDuplicateur . '*' . BASE_ATOMS_PER_POINT . '*niveau);
    }
    </script>
    ';
}

function updateRessources($joueur)
{
    global $nomsRes;
    global $base;
    global $bonusMedailles;
    global $paliersPertes;

    $donnees = dbFetchOne($base, 'SELECT tempsPrecedent FROM autre WHERE login=?', 's', $joueur);
    $nbsecondes = time() - $donnees['tempsPrecedent']; // On calcule la différence de secondes

    if ($nbsecondes < 1) {
        return; // Too fast, skip update
    }

    // Atomic: only update if tempsPrecedent hasn't changed since we read it
    dbExecute($base, 'UPDATE autre SET tempsPrecedent=? WHERE login=? AND tempsPrecedent=?', 'isi', time(), $joueur, $donnees['tempsPrecedent']);
    if (mysqli_affected_rows($base) === 0) {
        return; // Another request already updated — skip to prevent double resources
    }

    $donnees = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $joueur);

    $depot = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $joueur);
    //////////////////////////////////////////////////////////////////////////////////////////////////////////////////ENERGIE

    $revenuenergie = revenuEnergie($depot['generateur'], $joueur);
    $energie = $donnees['energie'] + $revenuenergie * ($nbsecondes / SECONDS_PER_HOUR); // On calcule l'energie que l'on doit avoir
    if ($energie >= placeDepot($depot['depot'])) {
        $energie = placeDepot($depot['depot']); // on limite l'energie pouvant être reçu (depots de ressources)
    }
    if ($energie < 0) {
        $energie = 0;
    }
    dbExecute($base, 'UPDATE ressources SET energie=? WHERE login=?', 'ds', $energie, $joueur);

    //////////////////////////////////////////////////////////////////////////////////////////////////////////////////RESSOURCES
    // Optimization: build one UPDATE with all 8 atom columns instead of 8 separate queries.
    $placeMax = placeDepot($depot['depot']);
    $sqlParts = [];
    $sqlTypes = '';
    $sqlParams = [];
    $allowedColumns = ['carbone', 'azote', 'hydrogene', 'oxygene', 'chlore', 'soufre', 'brome', 'iode'];
    foreach ($nomsRes as $num => $ressource) {
        if (!in_array($ressource, $allowedColumns, true)) {
            throw new \RuntimeException("Invalid column: $ressource");
        }
        ${'revenu' . $ressource} = revenuAtome($num, $joueur);
        $$ressource = $donnees[$ressource] + ${'revenu' . $ressource} * ($nbsecondes / SECONDS_PER_HOUR);
        if ($$ressource >= $placeMax) {
            $$ressource = $placeMax;
        }
        $sqlParts[] = "$ressource=?";
        $sqlTypes .= 'd';
        $sqlParams[] = $$ressource;
    }
    $sqlParams[] = $joueur;
    $sqlTypes .= 's';
    dbExecute($base, 'UPDATE ressources SET ' . implode(', ', $sqlParts) . ' WHERE login=?', $sqlTypes, ...$sqlParams);

    ///////////////////////////////////////////////////////////////////////////////////////////////////////////////Gestion des molécules disparaissant


    $stabilisateur = dbFetchOne($base, 'SELECT stabilisateur FROM constructions WHERE login=?', 's', $joueur);

    $nbheuresDebut = ($nbsecondes / SECONDS_PER_HOUR); // nombre d'heures depuis la derniere connexion

    $donneesMedaille = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $joueur);

    $moleculesRows = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=?', 's', $joueur);

    $compteur = 0;
    $totalMoleculesPerdues = 0;
    foreach ($moleculesRows as $molecules) {

        $moleculesRestantes = (pow(coefDisparition($joueur, $compteur + 1), $nbsecondes) * $molecules['nombre']);
        ${'nombre' . ($compteur + 1)} = $molecules['nombre'];

        dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di', $moleculesRestantes, $molecules['id']);

        $totalMoleculesPerdues += ($molecules['nombre'] - $moleculesRestantes);

        $compteur++;
    }
    // Batch: single atomic UPDATE instead of N SELECT+UPDATE pairs
    if ($totalMoleculesPerdues > 0) {
        dbExecute($base, 'UPDATE autre SET moleculesPerdues = moleculesPerdues + ? WHERE login = ?', 'ds', $totalMoleculesPerdues, $joueur);
    }

    // V4: Neutrino decay — treated as mass-1 molecule
    $neutrinoData = dbFetchOne($base, 'SELECT neutrinos FROM autre WHERE login=?', 's', $joueur);
    if ($neutrinoData && isset($neutrinoData['neutrinos']) && $neutrinoData['neutrinos'] > 0) {
        $coefNeutrino = coefDisparition($joueur, 1, 1); // type=1, nbAtomes=1
        $neutrinosRestants = floor(pow($coefNeutrino, $nbsecondes) * $neutrinoData['neutrinos']);
        if ($neutrinosRestants != $neutrinoData['neutrinos']) {
            dbExecute($base, 'UPDATE autre SET neutrinos=? WHERE login=?', 'is', $neutrinosRestants, $joueur);
        }
    }

    if ($nbheuresDebut > ABSENCE_REPORT_THRESHOLD_HOURS) {
        $donnees5 = dbFetchOne($base, 'SELECT nombre, formule FROM molecules WHERE proprietaire=? AND numeroclasse=1', 's', $joueur);
        $donnees6 = dbFetchOne($base, 'SELECT nombre, formule FROM molecules WHERE proprietaire=? AND numeroclasse=2', 's', $joueur);
        $donnees7 = dbFetchOne($base, 'SELECT nombre, formule FROM molecules WHERE proprietaire=? AND numeroclasse=3', 's', $joueur);
        $donnees8 = dbFetchOne($base, 'SELECT nombre, formule FROM molecules WHERE proprietaire=? AND numeroclasse=4', 's', $joueur);
        if (($nombre1 - $donnees5['nombre']) != 0 or ($nombre2 - $donnees6['nombre']) != 0 or ($nombre3 - $donnees7['nombre']) != 0 or ($nombre4 - $donnees8['nombre']) != 0) {
            $titreRapport = 'Rapport des pertes durant votre absence';
            $contenuRapport = 'Durant votre absence de ' . $nbheuresDebut . ' heures, vos pertes de molécules ont été : <br/>
			' . couleurFormule($donnees5['formule']) . ' : ' . number_format(($nombre1 - $donnees5['nombre']), 0, ' ', ' ') . ' molécules<br/>
			' . couleurFormule($donnees6['formule']) . ' : ' . number_format(($nombre2 - $donnees6['nombre']), 0, ' ', ' ') . ' molécules<br/>
			' . couleurFormule($donnees7['formule']) . ' : ' . number_format(($nombre3 - $donnees7['nombre']), 0, ' ', ' ') . ' molécules<br/>
			' . couleurFormule($donnees8['formule']) . ' : ' . number_format(($nombre4 - $donnees8['nombre']), 0, ' ', ' ') . ' molécules';
            dbExecute($base, 'INSERT INTO rapports VALUES(default, ?, ?, ?, ?, default, ?)', 'issss', time(), $titreRapport, $contenuRapport, $joueur, '<img alt="skull" src="images/rapports/rapportpertes.png"/ class="imageAide">');
        }
    }
}
