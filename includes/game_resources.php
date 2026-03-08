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

    $producteur = $constructions; // reuse $constructions — already has producteur column

    $autreRow = dbFetchOne($base, 'SELECT idalliance, totalPoints, energieDepensee FROM autre WHERE login=?', 's', $joueur);
    $idalliance = $autreRow; // single query replaces two separate autre queries
    $bonusDuplicateur = 1;
    if ($idalliance['idalliance'] > 0) {
        $duplicateur = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
        $bonusDuplicateur = 1 + bonusDuplicateur($duplicateur['duplicateur']);
    }

    // V4: Iode is now a generator catalyst — multiplicative bonus instead of additive energy
    // MED-045: Single aggregated query replaces 4 per-class DB calls (N+1 eliminated)
    $iodeSumRow = dbFetchOne($base, 'SELECT SUM(iode * nombre) AS total_iode FROM molecules WHERE proprietaire=?', 's', $joueur);
    $totalIodeAtoms = ($iodeSumRow && $iodeSumRow['total_iode'] !== null) ? (float)$iodeSumRow['total_iode'] : 0.0;
    $iodeCatalystBonus = 1.0 + min(IODE_CATALYST_MAX_BONUS, $totalIodeAtoms / IODE_CATALYST_DIVISOR);

    $donneesMedaille = $autreRow; // reuse cached autre row — has energieDepensee
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

    // Compound synthesis boost (production_boost)
    require_once(__DIR__ . '/compounds.php');
    $compoundProdBonus = getCompoundBonus($base, $joueur, 'production_boost');
    $prodCompound = $prodNodes * (1 + $compoundProdBonus);

    // Specialization: energy_production modifier
    $specEnergyMod = getSpecModifier($joueur, 'energy_production');
    $prodSpec = $prodCompound * (1 + $specEnergyMod);

    $prodProducteur = $prodSpec - drainageProducteur($producteur['producteur']);
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


function revenuAtome($num, $joueur, $constructions = null)
{
    static $cache = [];
    $cacheKey = $joueur . '-' . $num;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];

    global $base;
    global $nomsRes;

    if ($constructions === null) {
        $constructions = dbFetchOne($base, 'SELECT pointsProducteur FROM constructions WHERE login=?', 's', $joueur);
    }

    $niveau = explode(';', $constructions['pointsProducteur'])[$num];

    static $sharedCache = [];
    if (!isset($sharedCache[$joueur])) {
        $idallianceRow = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $joueur);
        $bonusDup = 1;
        if (!empty($idallianceRow['idalliance'])) {
            $dupRow = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', 'i', $idallianceRow['idalliance']);
            $bonusDup = 1 + bonusDuplicateur($dupRow['duplicateur'] ?? 0);
        }
        $pos = dbFetchOne($base, 'SELECT x, y FROM membre WHERE login=?', 's', $joueur);
        $sharedCache[$joueur] = ['bonusDuplicateur' => $bonusDup, 'pos' => $pos];
    }
    $bonusDuplicateur = $sharedCache[$joueur]['bonusDuplicateur'];
    $pos = $sharedCache[$joueur]['pos'];

    // Resource node proximity bonus
    $nodeBonus = 0;
    if ($pos && $pos['x'] >= 0 && $pos['y'] >= 0) {
        require_once(__DIR__ . '/resource_nodes.php');
        $nodeBonus = getResourceNodeBonus($base, $pos['x'], $pos['y'], $nomsRes[$num]);
    }

    // Compound synthesis boost (production_boost)
    require_once(__DIR__ . '/compounds.php');
    $compoundProdBonus = getCompoundBonus($base, $joueur, 'production_boost');

    // Specialization: atom_production modifier
    $specAtomMod = getSpecModifier($joueur, 'atom_production');

    $result = max(0, round($bonusDuplicateur * BASE_ATOMS_PER_POINT * $niveau * prestigeProductionBonus($joueur) * (1 + $nodeBonus) * (1 + $compoundProdBonus) * (1 + $specAtomMod)));
    $cache[$cacheKey] = $result;
    return $result;
}

function revenuAtomeJavascript($joueur)
{
    global $base;
    global $nomsRes;

    // Duplicateur (alliance) bonus
    $idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $joueur);
    $bonusDuplicateur = 1;
    if ($idalliance['idalliance'] > 0) {
        $duplicateur = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
        $bonusDuplicateur = 1 + bonusDuplicateur($duplicateur['duplicateur']);
    }

    // MED-001: Per-type node bonus — compute individually per atom type for accurate JS display
    $pos = dbFetchOne($base, 'SELECT x, y FROM membre WHERE login=?', 's', $joueur);
    $nodeBonusByType = [];
    foreach ($nomsRes as $num => $resName) {
        $nodeBonusByType[$num] = 0.0;
    }
    if ($pos && $pos['x'] >= 0 && $pos['y'] >= 0) {
        require_once(__DIR__ . '/resource_nodes.php');
        foreach ($nomsRes as $num => $resName) {
            $nodeBonusByType[$num] = getResourceNodeBonus($base, $pos['x'], $pos['y'], $resName);
        }
    }

    // Prestige production bonus
    $prestigeBonus = prestigeProductionBonus($joueur);

    // Compound production boost
    require_once(__DIR__ . '/compounds.php');
    $compoundProdBonus = getCompoundBonus($base, $joueur, 'production_boost');

    // Specialization: atom_production modifier
    $specAtomMod = getSpecModifier($joueur, 'atom_production');

    // Base multiplier (without per-type node bonus — node bonus applied per-type in JS)
    $baseMultiplier = $prestigeBonus * (1 + $compoundProdBonus) * (1 + $specAtomMod);

    // Emit per-type node bonus as a JSON object indexed by atom number
    $nodeBonusJson = json_encode($nodeBonusByType);

    echo '
    ' . cspScriptTag() . '
    var _nodeBonusByType = ' . $nodeBonusJson . ';
    function revenuAtomeJavascript(niveau, atomNum){
        var nodeBonus = (atomNum !== undefined && _nodeBonusByType[atomNum] !== undefined) ? _nodeBonusByType[atomNum] : 0;
        return Math.round(' . $bonusDuplicateur . '*' . BASE_ATOMS_PER_POINT . '*' . $baseMultiplier . '*(1+nodeBonus)*niveau);
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

    // ECO-001: Cap offline time to prevent total molecule wipeout after very long absences.
    // Without this cap, a player offline for weeks would lose all molecules to exponential decay.
    $nbsecondes = min($nbsecondes, MAX_OFFLINE_SECONDS);

    // Atomic: only update if tempsPrecedent hasn't changed since we read it
    dbExecute($base, 'UPDATE autre SET tempsPrecedent=? WHERE login=? AND tempsPrecedent=?', 'isi', time(), $joueur, $donnees['tempsPrecedent']);
    if (mysqli_affected_rows($base) === 0) {
        return; // Another request already updated — skip to prevent double resources
    }

    $depot = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $joueur);
    $placeMax = placeDepot($depot['depot']);

    //////////////////////////////////////////////////////////////////////////////////////////////////////////////////ENERGIE
    // Use atomic SQL increment (LEAST/GREATEST) to prevent race with concurrent combat/market updates
    $revenuenergie = revenuEnergie($depot['generateur'], $joueur);
    $energieDelta = $revenuenergie * ($nbsecondes / SECONDS_PER_HOUR);
    dbExecute($base, 'UPDATE ressources SET energie = LEAST(GREATEST(0, energie + ?), ?) WHERE login=?', 'dds', $energieDelta, $placeMax, $joueur);

    //////////////////////////////////////////////////////////////////////////////////////////////////////////////////RESSOURCES
    // Atomic incremental UPDATE — no read-modify-write race condition possible
    $sqlParts = [];
    $sqlTypes = '';
    $sqlParams = [];
    $allowedColumns = ['carbone', 'azote', 'hydrogene', 'oxygene', 'chlore', 'soufre', 'brome', 'iode'];
    foreach ($nomsRes as $num => $ressource) {
        if (!in_array($ressource, $allowedColumns, true)) {
            throw new \RuntimeException("Invalid column: $ressource");
        }
        $delta = revenuAtome($num, $joueur) * ($nbsecondes / SECONDS_PER_HOUR);
        $sqlParts[] = "$ressource = LEAST(GREATEST(0, $ressource + ?), ?)";
        $sqlTypes .= 'dd';
        $sqlParams[] = $delta;
        $sqlParams[] = $placeMax;
    }
    $sqlParams[] = $joueur;
    $sqlTypes .= 's';
    dbExecute($base, 'UPDATE ressources SET ' . implode(', ', $sqlParts) . ' WHERE login=?', $sqlTypes, ...$sqlParams);

    // Re-read ressources so molecule decay and downstream code sees accurate values
    $donnees = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $joueur);

    ///////////////////////////////////////////////////////////////////////////////////////////////////////////////Gestion des molécules disparaissant


    $stabilisateur = dbFetchOne($base, 'SELECT stabilisateur FROM constructions WHERE login=?', 's', $joueur);

    $nbheuresDebut = ($nbsecondes / SECONDS_PER_HOUR); // nombre d'heures depuis la derniere connexion
    // LOW-014: formatted absence duration (hours + minutes, no floating-point display)
    $absenceHeures = (int)floor($nbsecondes / SECONDS_PER_HOUR);
    $absenceMinutes = (int)floor(($nbsecondes % SECONDS_PER_HOUR) / 60);
    $absenceDureeStr = $absenceHeures . ' h ' . $absenceMinutes . ' min';

    $donneesMedaille = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $joueur);

    $moleculesRows = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC', 's', $joueur);

    // PASS1-LOW-034: Wrap all molecule decay UPDATEs in a transaction so a partial failure
    // does not leave some molecules decayed and the moleculesPerdues counter out of sync.
    // $nombreAvant[cls] captures pre-decay counts for the absence report (keyed by numeroclasse).
    $compteur = 0;
    $totalMoleculesPerdues = 0;
    $nombreAvant = [];
    withTransaction($base, function() use ($base, $joueur, $moleculesRows, $nbsecondes, &$compteur, &$totalMoleculesPerdues, &$nombreAvant) {
        foreach ($moleculesRows as $molecules) {
            $moleculesRestantes = max(0, floor(pow(coefDisparition($joueur, $compteur + 1), $nbsecondes) * $molecules['nombre']));
            // Store pre-decay count keyed by numeroclasse for the absence report
            $nombreAvant[(int)$molecules['numeroclasse']] = $molecules['nombre'];

            dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di', $moleculesRestantes, $molecules['id']);

            $totalMoleculesPerdues += max(0, $molecules['nombre'] - $moleculesRestantes);

            $compteur++;
        }
        // Batch: single atomic UPDATE instead of N SELECT+UPDATE pairs
        if ($totalMoleculesPerdues > 0) {
            dbExecute($base, 'UPDATE autre SET moleculesPerdues = moleculesPerdues + ? WHERE login = ?', 'ds', $totalMoleculesPerdues, $joueur);
        }

        // V4: Neutrino decay — treated as mass-1 molecule (included in same transaction for atomicity)
        $neutrinoData = dbFetchOne($base, 'SELECT neutrinos FROM autre WHERE login=?', 's', $joueur);
        if ($neutrinoData && isset($neutrinoData['neutrinos']) && $neutrinoData['neutrinos'] > 0) {
            $coefNeutrino = coefDisparition($joueur, 1, 1); // type=1, nbAtomes=1
            $neutrinosRestants = floor(pow($coefNeutrino, $nbsecondes) * $neutrinoData['neutrinos']);
            if ($neutrinosRestants != $neutrinoData['neutrinos']) {
                dbExecute($base, 'UPDATE autre SET neutrinos=? WHERE login=?', 'is', $neutrinosRestants, $joueur);
            }
        }
    });

    if ($nbheuresDebut > ABSENCE_REPORT_THRESHOLD_HOURS && $compteur > 0) {
        $lossLines = '';
        $hasLosses = false;
        $afterRows = dbFetchAll($base, 'SELECT nombre, formule, numeroclasse FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC', 's', $joueur);
        foreach ($afterRows as $afterMol) {
            $cls = (int)$afterMol['numeroclasse'];
            $before = $nombreAvant[$cls] ?? 0;
            $lost = $before - $afterMol['nombre'];
            if ($lost != 0) $hasLosses = true;
            $lossLines .= couleurFormule($afterMol['formule']) . ' : ' . number_format($lost, 0, ' ', ' ') . ' molécules<br/>';
        }
        if ($hasLosses) {
            $titreRapport = 'Rapport des pertes durant votre absence';
            $contenuRapport = 'Durant votre absence de ' . $absenceDureeStr . ', vos pertes de molécules ont été : <br/>' . $lossLines;
            dbExecute($base, 'INSERT INTO rapports VALUES(default, ?, ?, ?, ?, default, ?)', 'issss', time(), $titreRapport, $contenuRapport, $joueur, '<img alt="skull" src="images/rapports/rapportpertes.png" class="imageAide"/>');
        }
    }

    // Probabilistic garbage collection (COMPOUND_GC_PROBABILITY chance per call)
    if (mt_rand(1, 1000) <= (int)(COMPOUND_GC_PROBABILITY * 1000)) {
        // Trim market history to MARKET_HISTORY_LIMIT rows
        $keepId = dbFetchOne($base, 'SELECT id FROM cours ORDER BY id DESC LIMIT 1 OFFSET ' . MARKET_HISTORY_LIMIT);
        if ($keepId) {
            dbExecute($base, 'DELETE FROM cours WHERE id < ?', 'i', $keepId['id']);
        }
        // Cleanup expired compounds
        require_once(__DIR__ . '/compounds.php');
        cleanupExpiredCompounds($base);
    }
}
