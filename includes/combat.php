<?php
// Récupération des variables d'attaque, de défense, de coup critiques et de capacité de pillage pour chaque classe
// Pour l'attaquant

$exClasse1 = dbQuery($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC', 's', $actions['defenseur']);

$c = 1;
while ($classeDefenseur = mysqli_fetch_array($exClasse1)) {
	${'classeDefenseur' . $c} = $classeDefenseur;
	${'classeDefenseur' . $c}['nombre'] = ceil(${'classeDefenseur' . $c}['nombre']);

	$c++;
}

$exClasse1 = dbQuery($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC', 's', $actions['attaquant']);

$c = 1;
$chaineExplosee = explode(";", $actions['troupes']);
while ($classeAttaquant = mysqli_fetch_array($exClasse1)) {
	${'classeAttaquant' . $c} = $classeAttaquant;
	${'classeAttaquant' . $c}['nombre'] = ceil($chaineExplosee[$c - 1]); // on prends le nombre d'unites en attaque

	$c++;
}

// recupération des niveaux des atomes

$niveauxAttaquant = dbFetchOne($base, 'SELECT pointsProducteur FROM constructions WHERE login=?', 's', $actions['attaquant']);
$niveauxAttaquant = explode(";", $niveauxAttaquant['pointsProducteur']);
foreach ($nomsRes as $num => $ressource) {
	$niveauxAtt[$ressource] = $niveauxAttaquant[$num];
}

$niveauxDefenseur = dbFetchOne($base, 'SELECT pointsProducteur FROM constructions WHERE login=?', 's', $actions['defenseur']);
$niveauxDefenseur = explode(";", $niveauxDefenseur['pointsProducteur']);
foreach ($nomsRes as $num => $ressource) {
	$niveauxDef[$ressource] = $niveauxDefenseur[$num];
}


$ionisateur = dbFetchOne($base, 'SELECT ionisateur FROM constructions WHERE login=?', 's', $actions['attaquant']);

$champdeforce = dbFetchOne($base, 'SELECT champdeforce FROM constructions WHERE login=?', 's', $actions['defenseur']);

$idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $actions['attaquant']);
$bonusDuplicateurAttaque = 1;
if ($idalliance['idalliance'] > 0) {
	$duplicateurAttaque = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
	$bonusDuplicateurAttaque = 1 + ((0.1 * $duplicateurAttaque['duplicateur']) / 100);
}

$idallianceDef = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $actions['defenseur']);
$bonusDuplicateurDefense = 1;
if ($idallianceDef['idalliance'] > 0) {
	$duplicateurDefense = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', 'i', $idallianceDef['idalliance']);
	$bonusDuplicateurDefense = 1 + ((0.1 * $duplicateurDefense['duplicateur']) / 100);
}


// Calcul des dégâts totaux de l'attaquant (avec coups critiques)
// mettre les niveaux et pour le brome et soufre et tout ca avec les bonnes fonctions
$degatsAttaquant = 0;
$degatsDefenseur = 0;
for ($c = 1; $c <= 4; $c++) {
	$degatsAttaquant += attaque(${'classeAttaquant' . $c}['oxygene'], $niveauxAtt['oxygene'], $actions['attaquant']) * (1 + (($ionisateur['ionisateur'] * 2) / 100)) * $bonusDuplicateurAttaque * ${'classeAttaquant' . $c}['nombre'];
	$degatsDefenseur += defense(${'classeDefenseur' . $c}['carbone'], $niveauxDef['carbone'], $actions['defenseur']) * (1 + (($champdeforce['champdeforce'] * 2) / 100)) * $bonusDuplicateurDefense * ${'classeDefenseur' . $c}['nombre'];
}



// Calcul des pertes

// Attaquants

$degatsUtilises = 0;
$attaquantsRestants = 0;
$defenseursRestants = 0;

for ($i = 1; $i <= $nbClasses; $i++) {
	${'classe' . $i . 'AttaquantMort'} = 0;
	if (${'classeAttaquant' . $i}['nombre'] > 0 and $degatsUtilises < $degatsDefenseur) {
		if (${'classeAttaquant' . $i}['brome'] > 0) {
			${'classe' . $i . 'AttaquantMort'} = floor(($degatsDefenseur - $degatsUtilises) / (pointsDeVieMolecule(${'classeAttaquant' . $i}['brome'], $niveauxAtt['brome']) * $bonusDuplicateurAttaque));
			if (${'classe' . $i . 'AttaquantMort'} >= ${'classeAttaquant' . $i}['nombre']) {
				${'classe' . $i . 'AttaquantMort'} = ${'classeAttaquant' . $i}['nombre'];
			}
		} else {
			if ($degatsDefenseur > 0) {
				${'classe' . $i . 'AttaquantMort'} = ${'classeAttaquant' . $i}['nombre'];
			}
		}

		if (${'classe' . $i . 'AttaquantMort'} < ${'classeAttaquant' . $i}['nombre']) {
			$degatsUtilises = $degatsAttaquant;
		} else {
			$degatsUtilises = $degatsUtilises + ${'classe' . $i . 'AttaquantMort'} * pointsDeVieMolecule(${'classeAttaquant' . $i}['brome'], $niveauxAtt['brome']) * $bonusDuplicateurAttaque;
		}
	}
	$attaquantsRestants += ${'classeAttaquant' . $i}['nombre'] - ${'classe' . $i . 'AttaquantMort'};
}

$degatsUtilises = 0;
for ($i = 1; $i <= 4; $i++) {
	${'classe' . $i . 'DefenseurMort'} = 0;
	if (${'classeDefenseur' . $i}['nombre'] > 0 and $degatsUtilises < $degatsAttaquant) {
		if (${'classeDefenseur' . $i}['brome'] > 0) {
			${'classe' . $i . 'DefenseurMort'} = floor(($degatsAttaquant - $degatsUtilises) / (pointsDeVieMolecule(${'classeDefenseur' . $i}['brome'], $niveauxDef['brome']) * $bonusDuplicateurDefense));
			if (${'classe' . $i . 'DefenseurMort'} >= ${'classeDefenseur' . $i}['nombre']) {
				${'classe' . $i . 'DefenseurMort'} = ${'classeDefenseur' . $i}['nombre'];
			}
		} else {
			if ($degatsAttaquant > 0) {
				${'classe' . $i . 'DefenseurMort'} = ${'classeDefenseur' . $i}['nombre'];
			}
		}

		if (${'classe' . $i . 'DefenseurMort'} < ${'classeDefenseur' . $i}['nombre']) {
			$degatsUtilises = $degatsAttaquant;
		} else {
			$degatsUtilises = $degatsUtilises + ${'classe' . $i . 'DefenseurMort'} * pointsDeVieMolecule(${'classeDefenseur' . $i}['brome'], $niveauxDef['brome']) * $bonusDuplicateurDefense;
		}
	}
	$defenseursRestants += ${'classeDefenseur' . $i}['nombre'] - ${'classe' . $i . 'DefenseurMort'};
}

if ($attaquantsRestants == 0) {
	if ($defenseursRestants == 0) {
		$gagnant = 0;
	} else {
		$gagnant = 1;
	}
} else {
	if ($defenseursRestants == 0) {
		$gagnant = 2;
	} else {
		$gagnant = 0;
	}
}

// On met à jour les troupes des deux joueurs

//$actions['troupes'] //
$chaine = '';
for ($i = 1; $i <= $nbClasses; $i++) {
	$chaine = $chaine . (${'classeAttaquant' . $i}['nombre'] - ${'classe' . $i . 'AttaquantMort'}) . ';';
}

$actions['troupes'] = $chaine;
dbExecute($base, 'UPDATE actionsattaques SET troupes=? WHERE id=?', 'si', $chaine, $actions['id']);

// defenseur
dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di', ($classeDefenseur1['nombre'] - $classe1DefenseurMort), $classeDefenseur1['id']);
dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di', ($classeDefenseur2['nombre'] - $classe2DefenseurMort), $classeDefenseur2['id']);
dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di', ($classeDefenseur3['nombre'] - $classe3DefenseurMort), $classeDefenseur3['id']);
dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di', ($classeDefenseur4['nombre'] - $classe4DefenseurMort), $classeDefenseur4['id']);

// Gestion du pillage
$ressourcesDefenseur = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $actions['defenseur']);

$ressourcesJoueur = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $actions['attaquant']);
if ($gagnant == 2 || $gagnant == 0) { // Si le joueur gagnant est l'attaquant
	$ressourcesTotalesDefenseur = 0;
	foreach ($nomsRes as $num => $ressource) {
		$ressourcesTotalesDefenseur = $ressourcesTotalesDefenseur +	$ressourcesDefenseur[$ressource];
	} // On calcule les ressources totales du défenseur

	if ($ressourcesTotalesDefenseur != 0) { // Si elles sont différentes de zéro (pas de division par zéro)
		$ressourcesAPiller = ($classeAttaquant1['nombre'] - $classe1AttaquantMort) * pillage($classeAttaquant1['soufre'], $niveauxAtt['soufre'], $actions['attaquant']) + // Calcul des ressources totales que peut piller l'attaquant
			($classeAttaquant2['nombre'] - $classe2AttaquantMort) * pillage($classeAttaquant2['soufre'], $niveauxAtt['soufre'], $actions['attaquant']) +
			($classeAttaquant3['nombre'] - $classe3AttaquantMort) * pillage($classeAttaquant3['soufre'], $niveauxAtt['soufre'], $actions['attaquant']) +
			($classeAttaquant4['nombre'] - $classe4AttaquantMort) * pillage($classeAttaquant4['soufre'], $niveauxAtt['soufre'], $actions['attaquant']);

		// Calcul du pourcentage de chaque ressource chez le défenseur
		foreach ($nomsRes as $num => $ressource) {
			${'rapport' . $ressource} = $ressourcesDefenseur[$ressource] / $ressourcesTotalesDefenseur;
			if ($ressourcesTotalesDefenseur > $ressourcesAPiller) {
				${$ressource . 'Pille'} = floor($ressourcesAPiller * ${'rapport' . $ressource});
			} else {
				${$ressource . 'Pille'} = floor($ressourcesDefenseur[$ressource]);
			}
		}
	} else {
		foreach ($nomsRes as $num => $ressource) {
			${$ressource . 'Pille'} = 0;
		}
	}
} else {
	foreach ($nomsRes as $num => $ressource) {
		${$ressource . 'Pille'} = 0;
	}
}

//Gestion de la destruction des bâtiments ennemis
$hydrogeneTotal = ($classeAttaquant1['nombre'] - $classe1AttaquantMort) * potentielDestruction($classeAttaquant1['hydrogene'], $niveauxAtt['hydrogene']) + // Calcul des degats que va faire l'attaquant
	($classeAttaquant2['nombre'] - $classe2AttaquantMort) * potentielDestruction($classeAttaquant2['hydrogene'], $niveauxAtt['hydrogene']) +
	($classeAttaquant3['nombre'] - $classe3AttaquantMort) * potentielDestruction($classeAttaquant3['hydrogene'], $niveauxAtt['hydrogene']) +
	($classeAttaquant4['nombre'] - $classe4AttaquantMort) * potentielDestruction($classeAttaquant4['hydrogene'], $niveauxAtt['hydrogene']);
$degatsGenEnergie = 0;
$degatschampdeforce = 0;
$degatsDepot = 0;
$degatsProducteur = 0;
$pointsDefenseur = 0;
$points = 0;
$destructionGenEnergie = "Non endommagé";
$destructionProducteur = "Non endommagé";
$destructionchampdeforce = "Non endommagé";
$destructionDepot = "Non endommagé";

$constructions = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $actions['defenseur']);

if ($hydrogeneTotal > 0) { // si il y a de l'hydrogène
	// gestion des degats infligés

	if ($constructions['champdeforce'] > $constructions['generateur'] && $constructions['champdeforce'] > $constructions['producteur'] && $constructions['champdeforce'] > $constructions['depot']) {
		for ($i = 1; $i <= $nbClasses; $i++) {
			if (${'classeAttaquant' . $i}['hydrogene'] > 0) {
				$degatsAMettre = potentielDestruction(${'classeAttaquant' . $i}['hydrogene'], $niveauxAtt['hydrogene']) * ${'classeAttaquant' . $i}['nombre'];
				$degatschampdeforce += $degatsAMettre;
			}
		}
	} else {
		for ($i = 1; $i <= $nbClasses; $i++) {
			if (${'classeAttaquant' . $i}['hydrogene'] > 0) {
				$bat = rand(1, 4);
				$degatsAMettre = potentielDestruction(${'classeAttaquant' . $i}['hydrogene'], $niveauxAtt['hydrogene']) * ${'classeAttaquant' . $i}['nombre'];
				switch ($bat) {
					case 1:
						$degatsGenEnergie += $degatsAMettre;
						break;
					case 2:
						$degatschampdeforce += $degatsAMettre;
						break;
					case 3:
						$degatsProducteur += $degatsAMettre;
						break;
					default:
						$degatsDepot += $degatsAMettre;
						break;
				}
			}
		}
	}

	//gestion des destructions de batiments

	if ($degatsGenEnergie > 0) {
		$destructionGenEnergie = round($constructions['vieGenerateur'] / pointsDeVie($constructions['generateur']) * 100) . "% <img alt=\"fleche\" src=\"images/attaquer/arrow.png\"/ class=\"w16\" style=\"vertical-align:middle\"> " . max(round(($constructions['vieGenerateur'] - $degatsGenEnergie) / pointsDeVie($constructions['generateur']) * 100), 0) . "%";
		if ($degatsGenEnergie >= $constructions['vieGenerateur']) {
			if ($constructions['generateur'] > 1) {
				diminuerBatiment("generateur", $actions['defenseur']);
			} else {
				$degatsGenEnergie = 0;
				$destructionGenEnergie = "Niveau minimum";
			}
		} else {
			dbExecute($base, 'UPDATE constructions SET vieGenerateur=? WHERE login=?', 'ds', ($constructions['vieGenerateur'] - $degatsGenEnergie), $actions['defenseur']);
		}
	}
	if ($degatschampdeforce > 0) {
		$destructionchampdeforce = round($constructions['vieChampdeforce'] / vieChampDeForce($constructions['champdeforce']) * 100) . "% <img alt=\"fleche\" src=\"images/attaquer/arrow.png\"/ class=\"w16\" style=\"vertical-align:middle\"> " . max(round(($constructions['vieChampdeforce'] - $degatschampdeforce) / vieChampDeForce($constructions['champdeforce']) * 100), 0) . "%";
		if ($degatschampdeforce >= $constructions['vieChampdeforce']) {
			if ($constructions['champdeforce'] > 0) {
				diminuerBatiment("champdeforce", $actions['defenseur']);
			} else {
				$degatschampdeforce = 0;
				$destructionchampdeforce = "Niveau minimum";
			}
		} else {
			dbExecute($base, 'UPDATE constructions SET vieChampdeforce=? WHERE login=?', 'ds', ($constructions['vieChampdeforce'] - $degatschampdeforce), $actions['defenseur']);
		}
	}
	if ($degatsProducteur > 0) {
		$destructionProducteur = round($constructions['vieProducteur'] / pointsDeVie($constructions['producteur']) * 100) . "% <img alt=\"fleche\" src=\"images/attaquer/arrow.png\"/ class=\"w16\" style=\"vertical-align:middle\"> " . max(round(($constructions['vieProducteur'] - $degatsProducteur) / pointsDeVie($constructions['producteur']) * 100), 0) . "%";
		if ($degatsProducteur >= $constructions['vieProducteur']) {
			if ($constructions['producteur'] > 0) {
				diminuerBatiment("producteur", $actions['defenseur']);
			} else {
				$degatsProducteur = 0;
				$destructionProducteur = "Niveau minimum";
			}
		} else {
			dbExecute($base, 'UPDATE constructions SET vieProducteur=? WHERE login=?', 'ds', ($constructions['vieProducteur'] - $degatsProducteur), $actions['defenseur']);
		}
	}
	if ($degatsDepot > 0) {
		$destructionDepot = round($constructions['vieDepot'] / pointsDeVie($constructions['depot']) * 100) . "% <img alt=\"fleche\" src=\"images/attaquer/arrow.png\"/ class=\"w16\" style=\"vertical-align:middle\"> " . max(round(($constructions['vieDepot'] - $degatsDepot) / pointsDeVie($constructions['depot']) * 100), 0) . "%";
		if ($degatsDepot >= $constructions['vieDepot']) {
			if ($constructions['depot'] > 1) {
				diminuerBatiment("depot", $actions['defenseur']);
			} else {
				$degatsDepot = 0;
				$destructionDepot = "Niveau minimum";
			}
		} else {
			dbExecute($base, 'UPDATE constructions SET vieDepot=? WHERE login=?', 'ds', ($constructions['vieDepot'] - $degatsDepot), $actions['defenseur']);
		}
	}
}

// calcul des stats de combat

$pertesAttaquant = $classe1AttaquantMort + $classe2AttaquantMort + $classe3AttaquantMort + $classe4AttaquantMort;
$pertesDefenseur = $classe1DefenseurMort + $classe2DefenseurMort + $classe3DefenseurMort + $classe4DefenseurMort;

$pointsAttaquant = 0;
$pointsDefenseur = 0;

$pointsBDAttaquant = dbFetchOne($base, 'SELECT points,pointsAttaque,pointsDefense,totalPoints FROM autre WHERE login=?', 's', $actions['attaquant']);
$pointsBDDefenseur = dbFetchOne($base, 'SELECT points,pointsAttaque,pointsDefense,totalPoints FROM autre WHERE login=?', 's', $actions['defenseur']);

if ($gagnant == 1) { // DEFENSEUR
	if ($pointsBDAttaquant['totalPoints'] >= $pointsBDDefenseur['totalPoints']) {
		$pointsAttaquant += -1;
		$pointsDefenseur += 1;
	}
	if ($pointsBDAttaquant['pointsAttaque'] >= $pointsBDDefenseur['pointsDefense']) {
		$pointsAttaquant += -1;
		$pointsDefenseur += 1;
	}
} else if ($gagnant == 2 && $pertesDefenseur > 0) { // ATTAQUANT
	if ($pointsBDAttaquant['totalPoints'] <= $pointsBDDefenseur['totalPoints']) {
		$pointsAttaquant += 1;
		$pointsDefenseur += -1;
	}
	if ($pointsBDAttaquant['pointsAttaque'] <= $pointsBDDefenseur['pointsDefense']) {
		$pointsAttaquant += 1;
		$pointsDefenseur += -1;
	}
}

$totalPille = 0;
foreach ($nomsRes as $num => $ressource) {
	$totalPille += ${$ressource . 'Pille'};
}

// update des stats de combat

$perduesAttaquant = dbFetchOne($base, 'SELECT moleculesPerdues,ressourcesPillees FROM autre WHERE login=?', 's', $actions['attaquant']);

$perduesDefenseur = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $actions['defenseur']);

$attaquePts = ajouterPoints($pointsAttaquant, $actions['attaquant'], 1);
$pillagePts = ajouterPoints($totalPille, $actions['attaquant'], 3);
$defensePts = ajouterPoints($pointsDefenseur, $actions['defenseur'], 2);
$pillagePts1 = ajouterPoints(-$totalPille, $actions['defenseur'], 3);

dbExecute($base, 'UPDATE autre SET moleculesPerdues=? WHERE login=?', 'ds', ($pertesAttaquant + $perduesAttaquant['moleculesPerdues']), $actions['attaquant']);
dbExecute($base, 'UPDATE autre SET moleculesPerdues=? WHERE login=?', 'ds', ($pertesDefenseur + $perduesDefenseur['moleculesPerdues']), $actions['defenseur']);




// On met à jour les ressources
// Build the SET clause and parameters dynamically for attacker
$setClauses = [];
$setTypes = '';
$setParams = [];
foreach ($nomsRes as $num => $ressource) {
	$setClauses[] = "$ressource=?";
	$setTypes .= 'd';
	$setParams[] = ($ressourcesJoueur[$ressource] + ${$ressource . 'Pille'});
}
$setParams[] = $actions['attaquant'];
$setTypes .= 's';
$sql = 'UPDATE ressources SET ' . implode(',', $setClauses) . ' WHERE login=?';
dbExecute($base, $sql, $setTypes, ...$setParams);

// Build the SET clause and parameters dynamically for defender
$setClauses = [];
$setTypes = '';
$setParams = [];
foreach ($nomsRes as $num => $ressource) {
	$setClauses[] = "$ressource=?";
	$setTypes .= 'd';
	$setParams[] = ($ressourcesDefenseur[$ressource] - ${$ressource . 'Pille'});
}
$setParams[] = $actions['defenseur'];
$setTypes .= 's';
$sql1 = 'UPDATE ressources SET ' . implode(',', $setClauses) . ' WHERE login=?';
dbExecute($base, $sql1, $setTypes, ...$setParams);

$nbattaques = dbFetchOne($base, 'SELECT nbattaques FROM autre WHERE login=?', 's', $actions['attaquant']);

dbExecute($base, 'UPDATE autre SET nbattaques=? WHERE login=?', 'is', ($nbattaques['nbattaques'] + 1), $actions['attaquant']);

// Si les alliances sont en guerre on inscrit les pertes

$joueur = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $actions['attaquant']);

$idallianceAutre = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $actions['defenseur']);

$exGuerre = dbQuery($base, 'SELECT * FROM declarations WHERE type=0 AND fin=0 AND ((alliance1=? AND alliance2=?) OR (alliance2=? AND alliance1=?))', 'iiii', $joueur['idalliance'], $idallianceAutre['idalliance'], $joueur['idalliance'], $idallianceAutre['idalliance']);
$guerre = mysqli_fetch_array($exGuerre);
$nbGuerres = mysqli_num_rows($exGuerre);
if ($nbGuerres >=  1) {
	if ($guerre['alliance1'] == $joueur['idalliance']) {
		dbExecute($base, 'UPDATE declarations SET pertes1=?, pertes2=? WHERE id=?', 'ddi', ($guerre['pertes1'] + $pertesAttaquant), ($guerre['pertes2'] + $pertesDefenseur), $guerre['id']);
	} else {
		dbExecute($base, 'UPDATE declarations SET pertes1=?, pertes2=? WHERE id=?', 'ddi', ($guerre['pertes1'] + $pertesDefenseur), ($guerre['pertes2'] + $pertesAttaquant), $guerre['id']);
	}
}
