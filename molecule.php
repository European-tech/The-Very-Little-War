<?php
include("includes/basicprivatephp.php");

include("includes/tout.php");

if(isset($_GET['id']) AND !empty($_GET['id'])) {
	$_GET['id'] = (int)$_GET['id'];
	$ex = dbQuery($base, 'SELECT * FROM molecules WHERE id=? AND proprietaire=?', 'is', $_GET['id'], $_SESSION['login']);
	if (!$ex) {
		error_log("SQL error fetching molecule stats");
		$molecule = null;
		$nb_resultats = 0;
	} else {
		$molecule = mysqli_fetch_array($ex);
		$nb_resultats = mysqli_num_rows($ex);
	}

    debutCarte('Statistiques de la classe');

	if($nb_resultats > 0){

    $totalAtomes = 0;
	$mx = $molecule['oxygene'];
	foreach($nomsRes as $num => $ressource) {
		$mx = max($mx,$molecule[$ressource]);
        $totalAtomes += $molecule[$ressource];
	}
	foreach($nomsRes as $num => $ressource) {
		if($mx == $molecule[$ressource]) {
			$img = $ressource;
		}
	}

        // V4: Pre-compute medal bonuses and lieur level for covalent formulas
        $medalData = dbFetchOne($base, 'SELECT pointsAttaque, pointsDefense, ressourcesPillees FROM autre WHERE login=?', 's', $_SESSION['login']);
        $bonusAttaque = computeMedalBonus($medalData ? $medalData['pointsAttaque'] : 0, $paliersAttaque, $bonusMedailles);
        $bonusDefense = computeMedalBonus($medalData ? $medalData['pointsDefense'] : 0, $paliersDefense, $bonusMedailles);
        $bonusPillage = computeMedalBonus($medalData ? $medalData['ressourcesPillees'] : 0, $paliersPillage, $bonusMedailles);
        $lieurData = dbFetchOne($base, 'SELECT lieur FROM constructions WHERE login=?', 's', $_SESSION['login']);
        $nivLieur = ($lieurData && isset($lieurData['lieur'])) ? $lieurData['lieur'] : 0;

        $demivie = affichageTemps(demiVie($_SESSION['login'],$molecule['numeroclasse']));

        debutListe();
        item(['titre' => 'Formule', 'soustitre' => couleurFormule($molecule['formule']), 'media' => '<img alt="moelcule" src="images/molecule/formule.png" class="imageMedia"/>']);
        item(['titre' => 'Quantité', 'soustitre' => (separerZeros($molecule['nombre'])), 'media' => '<img alt="moelcule" src="images/molecule/molecule.png" class="imageMedia"/>']);
	    item(['titre' => 'Attaque', 'soustitre' => attaque($molecule['oxygene'],$molecule['hydrogene'],$niveauoxygene,$bonusAttaque), 'media' => '<img alt="moelcule" src="images/molecule/sword.png" class="imageMedia"/>']);
        item(['titre' => 'Défense', 'soustitre' => defense($molecule['carbone'],$molecule['brome'],$niveaucarbone,$bonusDefense), 'media' => '<img alt="moelcule" src="images/molecule/shield.png" class="imageMedia"/>']);
        item(['titre' => 'Points de vie', 'soustitre' => pointsDeVieMolecule($molecule['brome'],$molecule['carbone'],$niveaubrome), 'media' => '<img alt="moelcule" src="images/molecule/sante.png" class="imageMedia"/>']);
        item(['titre' => 'Vitesse', 'soustitre' => vitesse($molecule['chlore'],$molecule['azote'],$niveauchlore).' cases/heure', 'media' => '<img alt="moelcule" src="images/molecule/vitesse.png" class="imageMedia"/>']);
        item(['titre' => 'Dégâts aux bâtiments', 'soustitre' => potentielDestruction($molecule['hydrogene'],$molecule['oxygene'],$niveauhydrogene), 'media' => '<img alt="moelcule" src="images/molecule/fire.png" class="imageMedia"/>']);
        item(['titre' => 'Temps de formation', 'soustitre' => affichageTemps(tempsFormation($totalAtomes,$molecule['azote'],$molecule['iode'],$niveauazote,$nivLieur,$_SESSION['login']),true), 'media' => '<img alt="moelcule" src="images/molecule/temps.png" class="imageMedia"/>']);
        // affichage en petitTemps si <60 secondes dans affichageTemps(..,true)
        item(['titre' => 'Capacité de pillage', 'soustitre' => pillage($molecule['soufre'],$molecule['chlore'],$niveausoufre,$bonusPillage), 'media' => '<img alt="moelcule" src="images/molecule/bag.png" class="imageMedia"/>']);
        item(['titre' => 'Production d\'énergie', 'soustitre' => nombreEnergie('<span style="color:green">+'.productionEnergieMolecule($molecule['iode'],$niveauiode).'/h</span>'), 'media' => '<img alt="moelcule" src="images/energie.png" class="imageMedia"/>']);
        item(['titre' => 'Demi-vie', 'soustitre' => $demivie, 'media' => '<img alt="moelcule" src="images/molecule/demivie.png" class="imageMedia"/>']);
        // Display isotope variant
        global $ISOTOPES;
        $isoType = intval($molecule['isotope'] ?? 0);
        $isoName = isset($ISOTOPES[$isoType]) ? $ISOTOPES[$isoType]['name'] : 'Normal';
        $isoDesc = isset($ISOTOPES[$isoType]) ? $ISOTOPES[$isoType]['desc'] : '';
        item(['titre' => 'Isotope', 'soustitre' => '<strong>' . htmlspecialchars($isoName) . '</strong>' . ($isoDesc ? ' — <small>' . htmlspecialchars($isoDesc) . '</small>' : ''), 'media' => '<img alt="isotope" src="images/molecule/molecule.png" class="imageMedia"/>']);
        finListe();
	}
	else {
		echo "Cette molecule n'existe pas ou ne vous appartient pas.";
	}
    finCarte();
}
else {
    debutCarte("Bonjour");
    debutContent();
	echo "Trés amusant de changer les variables dans la barre URL non ?";
    finContent();
    finCarte('Au revoir');
}

include("includes/copyright.php"); ?>
