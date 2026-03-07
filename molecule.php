<?php
include("includes/basicprivatephp.php");

include("includes/layout.php");

if(isset($_GET['id']) AND !empty($_GET['id'])) {
	$_GET['id'] = (int)$_GET['id'];
	$molecule = dbFetchOne($base, 'SELECT * FROM molecules WHERE id=? AND proprietaire=?', 'is', $_GET['id'], $_SESSION['login']);
	$nb_resultats = $molecule ? 1 : 0;

    debutCarte('Statistiques de la classe');

	if($nb_resultats > 0){

    $totalAtomes = 0;
	$mx = 0;
	foreach($nomsRes as $num => $ressource) {
		$mx = max($mx,$molecule[$ressource]);
        $totalAtomes += $molecule[$ressource];
	}
	foreach($nomsRes as $num => $ressource) {
		if($mx == $molecule[$ressource]) {
			$img = $ressource;
		}
	}

        // MED-055: Ensure condenseur-level variables are initialized even if initPlayer()
        // did not set them (e.g. player has no constructions row).
        // initPlayer() sets these via pointsCondenseur; default 0 is safe (no bonus).
        foreach ($nomsRes as $_resName) {
            $varName = 'niveau' . $_resName;
            if (!isset($$varName)) {
                $$varName = 0;
            }
        }
        unset($_resName, $varName);

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
	    item(['titre' => '<span title="(O^1.2 + O) × (1 + H/' . COVALENT_SYNERGY_DIVISOR . ') × modCond(nivO) × médaille">Attaque</span>', 'soustitre' => attaque($molecule['oxygene'],$molecule['hydrogene'],$niveauoxygene,$bonusAttaque), 'media' => '<img alt="moelcule" src="images/molecule/sword.png" class="imageMedia"/>']);
        item(['titre' => '<span title="(C^1.2 + C) × (1 + Br/' . COVALENT_SYNERGY_DIVISOR . ') × modCond(nivC) × médaille">Défense</span>', 'soustitre' => defense($molecule['carbone'],$molecule['brome'],$niveaucarbone,$bonusDefense), 'media' => '<img alt="moelcule" src="images/molecule/shield.png" class="imageMedia"/>']);
        item(['titre' => '<span title="' . MOLECULE_MIN_HP . ' + (Br^1.2 + Br) × (1 + C/' . COVALENT_SYNERGY_DIVISOR . ') × modCond(nivBr)">Points de vie</span>', 'soustitre' => pointsDeVieMolecule($molecule['brome'],$molecule['carbone'],$niveaubrome), 'media' => '<img alt="moelcule" src="images/molecule/sante.png" class="imageMedia"/>']);
        item(['titre' => '<span title="(1 + Cl×0.5 + Cl×N/200) × modCond(nivCl)">Vitesse</span>', 'soustitre' => vitesse($molecule['chlore'],$molecule['azote'],$niveauchlore).' cases/heure', 'media' => '<img alt="moelcule" src="images/molecule/vitesse.png" class="imageMedia"/>']);
        item(['titre' => '<span title="(H^1.2 + H) × (1 + O/' . COVALENT_SYNERGY_DIVISOR . ') × modCond(nivH)">Dégâts aux bâtiments</span>', 'soustitre' => potentielDestruction($molecule['hydrogene'],$molecule['oxygene'],$niveauhydrogene), 'media' => '<img alt="moelcule" src="images/molecule/fire.png" class="imageMedia"/>']);
        item(['titre' => '<span title="totalAtomes / ((1 + N^1.1 × (1+I/200)) × modCond(nivN) × bonusLieur)">Temps de formation</span>', 'soustitre' => affichageTemps(tempsFormation($totalAtomes,$molecule['azote'],$molecule['iode'],$niveauazote,$nivLieur,$_SESSION['login']),true), 'media' => '<img alt="moelcule" src="images/molecule/temps.png" class="imageMedia"/>']);
        // affichage en petitTemps si <60 secondes dans affichageTemps(..,true)
        item(['titre' => '<span title="(S^1.2 + S) × (1 + Cl/' . COVALENT_SYNERGY_DIVISOR . ') × modCond(nivS) × médaille">Capacité de pillage</span>', 'soustitre' => pillage($molecule['soufre'],$molecule['chlore'],$niveausoufre,$bonusPillage), 'media' => '<img alt="moelcule" src="images/molecule/bag.png" class="imageMedia"/>']);
        item(['titre' => '<span title="(' . IODE_QUADRATIC_COEFFICIENT . '×I² + ' . IODE_ENERGY_COEFFICIENT . '×I) × (1 + nivI/' . IODE_LEVEL_DIVISOR . ')">Production d\'énergie</span>', 'soustitre' => nombreEnergie('<span style="color:green">+'.productionEnergieMolecule($molecule['iode'],$niveauiode).'/h</span>'), 'media' => '<img alt="moelcule" src="images/energie.png" class="imageMedia"/>']);
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
