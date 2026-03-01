<?php
/**
 * Game Resources Module
 * Resource production and update functions.
 */

function revenuEnergie($niveau, $joueur, $detail = 0)
{ // BUG ICI
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

    //Prise en compte des revenus par l'iode des molecules
    $totalIode = 0;
    for ($i = 1; $i <= 4; $i++) {
        $molecules = dbFetchOne($base, 'SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $joueur, $i);
        $totalIode += productionEnergieMolecule($molecules['iode'], $niveauiode) * $molecules['nombre'];
        //A FAIRE COMPTER L'IODE TOTALE ET AJOUTER AUX REVENUS
    }

    $donneesMedaille = dbFetchOne($base, 'SELECT energieDepensee FROM autre WHERE login=?', 's', $joueur);
    $bonus = 0;

    foreach ($paliersEnergievore as $num => $palier) {
        if ($donneesMedaille['energieDepensee'] >= $palier) {
            $bonus = $bonusMedailles[$num];
        }
    }

    $prodBase = (65 * $niveau);
    $prodIode = $prodBase + $totalIode;
    $prodMedaille = (1 + ($bonus / 100)) * $prodIode;
    $prodDuplicateur = $bonusDuplicateur * $prodMedaille;
    $prodProducteur = $prodDuplicateur - drainageProducteur($producteur['producteur']);
    if ($detail == 0) {
        return round($prodProducteur);
    } elseif ($detail == 1) {
        return round($prodDuplicateur);
    } elseif ($detail == 2) {
        return round($prodMedaille);
    } elseif ($detail == 3) {
        return round($prodIode);
    } else {
        return round($prodBase);
    }
}


function revenuAtome($num, $joueur)
{
    global $base;

    $pointsProducteur = dbFetchOne($base, 'SELECT pointsProducteur FROM constructions WHERE login=?', 's', $joueur);

    $niveau = explode(';', $pointsProducteur['pointsProducteur'])[$num];

    $idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $joueur);
    $bonusDuplicateur = 1;
    if ($idalliance['idalliance'] > 0) {
        $duplicateur = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
        $bonusDuplicateur = 1 + bonusDuplicateur($duplicateur['duplicateur']);
    }

    return round($bonusDuplicateur * 30 * $niveau);
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
    <script>
    function revenuAtomeJavascript(niveau){
        return Math.round(' . $bonusDuplicateur . '*30*niveau);
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
    $donnees = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $joueur);
    dbExecute($base, 'UPDATE autre SET tempsPrecedent=? WHERE login=?', 'is', time(), $joueur);

    $depot = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $joueur);
    //////////////////////////////////////////////////////////////////////////////////////////////////////////////////ENERGIE

    $revenuenergie = revenuEnergie($depot['generateur'], $joueur);
    $energie = $donnees['energie'] + $revenuenergie * ($nbsecondes / 3600); // On calcule l'energie que l'on doit avoir
    if ($energie >= placeDepot($depot['depot'])) {
        $energie = placeDepot($depot['depot']); // on limite l'energie pouvant être reçu (depots de ressources)
    }
    if ($energie < 0) {
        $energie = 0;
    }
    dbExecute($base, 'UPDATE ressources SET energie=? WHERE login=?', 'ds', $energie, $joueur);

    //////////////////////////////////////////////////////////////////////////////////////////////////////////////////RESSOURCES
    foreach ($nomsRes as $num => $ressource) {
        ${'revenu' . $ressource} = revenuAtome($num, $joueur);
        $$ressource = $donnees[$ressource] + ${'revenu' . $ressource} * ($nbsecondes / 3600);
        if ($$ressource >= placeDepot($depot['depot'])) {
            $$ressource = placeDepot($depot['depot']);
        }
        dbExecute($base, "UPDATE ressources SET $ressource=? WHERE login=?", 'ds', $$ressource, $joueur);
    }

    ///////////////////////////////////////////////////////////////////////////////////////////////////////////////Gestion des molécules disparaissant


    $stabilisateur = dbFetchOne($base, 'SELECT stabilisateur FROM constructions WHERE login=?', 's', $joueur);

    $nbheuresDebut = ($nbsecondes / 3600); // nombre d'heures depuis la derniere connexion

    $donneesMedaille = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $joueur);

    $ex = dbQuery($base, 'SELECT * FROM molecules WHERE proprietaire=?', 's', $joueur);

    $compteur = 0;
    while ($molecules = mysqli_fetch_array($ex)) {

        $moleculesRestantes = (pow(coefDisparition($joueur, $compteur + 1), $nbsecondes) * $molecules['nombre']);
        ${'nombre' . ($compteur + 1)} = $molecules['nombre'];


        dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di', $moleculesRestantes, $molecules['id']);

        $moleculesPerdues = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $joueur);
        dbExecute($base, 'UPDATE autre SET moleculesPerdues=? WHERE login=?', 'ds', ($molecules['nombre'] - $moleculesRestantes + $moleculesPerdues['moleculesPerdues']), $joueur);

        $compteur++;
    }

    if ($nbheuresDebut > 6) {
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
