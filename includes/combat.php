<?php
// Récupération des variables d'attaque, de défense, de coup critiques et de capacité de pillage pour chaque classe
// Pour l'attaquant

$rowsDefenseur = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC FOR UPDATE', 's', $actions['defenseur']);

$c = 1;
foreach ($rowsDefenseur as $classeDefenseur) {
	${'classeDefenseur' . $c} = $classeDefenseur;
	${'classeDefenseur' . $c}['nombre'] = ceil(${'classeDefenseur' . $c}['nombre']);

	$c++;
}

$rowsAttaquant = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC FOR UPDATE', 's', $actions['attaquant']);

$c = 1;
$chaineExplosee = explode(";", $actions['troupes']);
foreach ($rowsAttaquant as $classeAttaquant) {
	${'classeAttaquant' . $c} = $classeAttaquant;
	${'classeAttaquant' . $c}['nombre'] = ceil($chaineExplosee[$c - 1]); // on prends le nombre d'unites en attaque

	$c++;
}

// recupération des niveaux des atomes

$niveauxAttaquant = dbFetchOne($base, 'SELECT pointsProducteur FROM constructions WHERE login=? FOR UPDATE', 's', $actions['attaquant']);
if (!$niveauxAttaquant) {
	logError("Combat: missing attacker constructions for " . $actions['attaquant'] . " at line " . __LINE__);
	throw new Exception('Missing attacker constructions');
}
$niveauxAttaquant = explode(";", $niveauxAttaquant['pointsProducteur']);
foreach ($nomsRes as $num => $ressource) {
	$niveauxAtt[$ressource] = $niveauxAttaquant[$num];
}

$niveauxDefenseur = dbFetchOne($base, 'SELECT pointsProducteur FROM constructions WHERE login=? FOR UPDATE', 's', $actions['defenseur']);
if (!$niveauxDefenseur) {
	logError("Combat: missing defender constructions for " . $actions['defenseur'] . " at line " . __LINE__);
	throw new Exception('Missing defender constructions');
}
$niveauxDefenseur = explode(";", $niveauxDefenseur['pointsProducteur']);
foreach ($nomsRes as $num => $ressource) {
	$niveauxDef[$ressource] = $niveauxDefenseur[$num];
}


$ionisateur = dbFetchOne($base, 'SELECT ionisateur FROM constructions WHERE login=? FOR UPDATE', 's', $actions['attaquant']);
if (!$ionisateur) {
	logError("Combat: missing attacker constructions (ionisateur) for " . $actions['attaquant']);
	throw new Exception('Missing attacker constructions');
}

$champdeforce = dbFetchOne($base, 'SELECT champdeforce FROM constructions WHERE login=? FOR UPDATE', 's', $actions['defenseur']);
if (!$champdeforce) {
	logError("Combat: missing defender constructions (champdeforce) for " . $actions['defenseur']);
	throw new Exception('Missing defender constructions');
}

$idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $actions['attaquant']);
$bonusDuplicateurAttaque = 1;
if ($idalliance && $idalliance['idalliance'] > 0) {
	$duplicateurAttaque = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
	$bonusDuplicateurAttaque = $duplicateurAttaque ? 1 + ($duplicateurAttaque['duplicateur'] * DUPLICATEUR_BONUS_PER_LEVEL) : 1;
}

$idallianceDef = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $actions['defenseur']);
$bonusDuplicateurDefense = 1;
if ($idallianceDef && $idallianceDef['idalliance'] > 0) {
	$duplicateurDefense = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', 'i', $idallianceDef['idalliance']);
	$bonusDuplicateurDefense = $duplicateurDefense ? 1 + ($duplicateurDefense['duplicateur'] * DUPLICATEUR_BONUS_PER_LEVEL) : 1;
}

// V4: Pre-compute medal bonuses for pure stat functions
$attMedalData = dbFetchOne($base, 'SELECT pointsAttaque, pointsDefense, ressourcesPillees FROM autre WHERE login=?', 's', $actions['attaquant']);
$defMedalData = dbFetchOne($base, 'SELECT pointsAttaque, pointsDefense, ressourcesPillees FROM autre WHERE login=?', 's', $actions['defenseur']);

$bonusAttaqueMedaille = computeMedalBonus($attMedalData ? $attMedalData['pointsAttaque'] : 0, $paliersAttaque, $bonusMedailles);
$bonusDefenseMedaille = computeMedalBonus($defMedalData ? $defMedalData['pointsDefense'] : 0, $paliersDefense, $bonusMedailles);
$bonusPillageMedaille = computeMedalBonus($attMedalData ? $attMedalData['ressourcesPillees'] : 0, $paliersPillage, $bonusMedailles);

// Isotope Modifiers — per-class attack/HP multipliers based on isotope variant
// Also calculate Catalytique ally bonus (boosts OTHER classes by 15%)
$attIsotopeAttackMod = [];
$attIsotopeHpMod = [];
$defIsotopeAttackMod = [];
$defIsotopeHpMod = [];
$attHasCatalytique = false;
$defHasCatalytique = false;

for ($c = 1; $c <= $nbClasses; $c++) {
	$attIso = intval(${'classeAttaquant' . $c}['isotope'] ?? 0);
	$defIso = intval(${'classeDefenseur' . $c}['isotope'] ?? 0);

	$attIsotopeAttackMod[$c] = 1.0;
	$attIsotopeHpMod[$c] = 1.0;
	$defIsotopeAttackMod[$c] = 1.0;
	$defIsotopeHpMod[$c] = 1.0;

	if ($attIso == ISOTOPE_STABLE) {
		$attIsotopeAttackMod[$c] += ISOTOPE_STABLE_ATTACK_MOD;
		$attIsotopeHpMod[$c] += ISOTOPE_STABLE_HP_MOD;
	} elseif ($attIso == ISOTOPE_REACTIF) {
		$attIsotopeAttackMod[$c] += ISOTOPE_REACTIF_ATTACK_MOD;
		$attIsotopeHpMod[$c] += ISOTOPE_REACTIF_HP_MOD;
	} elseif ($attIso == ISOTOPE_CATALYTIQUE) {
		$attIsotopeAttackMod[$c] += ISOTOPE_CATALYTIQUE_ATTACK_MOD;
		$attIsotopeHpMod[$c] += ISOTOPE_CATALYTIQUE_HP_MOD;
		$attHasCatalytique = true;
	}

	if ($defIso == ISOTOPE_STABLE) {
		$defIsotopeAttackMod[$c] += ISOTOPE_STABLE_ATTACK_MOD;
		$defIsotopeHpMod[$c] += ISOTOPE_STABLE_HP_MOD;
	} elseif ($defIso == ISOTOPE_REACTIF) {
		$defIsotopeAttackMod[$c] += ISOTOPE_REACTIF_ATTACK_MOD;
		$defIsotopeHpMod[$c] += ISOTOPE_REACTIF_HP_MOD;
	} elseif ($defIso == ISOTOPE_CATALYTIQUE) {
		$defIsotopeAttackMod[$c] += ISOTOPE_CATALYTIQUE_ATTACK_MOD;
		$defIsotopeHpMod[$c] += ISOTOPE_CATALYTIQUE_HP_MOD;
		$defHasCatalytique = true;
	}
}

// Catalytique: boost non-catalytique classes by ISOTOPE_CATALYTIQUE_ALLY_BONUS
if ($attHasCatalytique) {
	for ($c = 1; $c <= $nbClasses; $c++) {
		if (intval(${'classeAttaquant' . $c}['isotope'] ?? 0) != ISOTOPE_CATALYTIQUE) {
			$attIsotopeAttackMod[$c] += ISOTOPE_CATALYTIQUE_ALLY_BONUS;
			$attIsotopeHpMod[$c] += ISOTOPE_CATALYTIQUE_ALLY_BONUS;
		}
	}
}
if ($defHasCatalytique) {
	for ($c = 1; $c <= $nbClasses; $c++) {
		if (intval(${'classeDefenseur' . $c}['isotope'] ?? 0) != ISOTOPE_CATALYTIQUE) {
			$defIsotopeAttackMod[$c] += ISOTOPE_CATALYTIQUE_ALLY_BONUS;
			$defIsotopeHpMod[$c] += ISOTOPE_CATALYTIQUE_ALLY_BONUS;
		}
	}
}

// Defensive Formation — read defender's chosen formation
$formationData = dbFetchOne($base, 'SELECT formation FROM constructions WHERE login=?', 's', $actions['defenseur']);
$defenderFormation = ($formationData && isset($formationData['formation'])) ? intval($formationData['formation']) : FORMATION_DISPERSEE;

// Formation bonuses — Embuscade now applied as $embuscadeDefBoost after damage calc (FIX FINDING-GAME-018)

// FIX FINDING-GAME-018: Embuscade now correctly boosts defender's EFFECTIVE DAMAGE (defense score)
// instead of misleadingly named $formationDefenseBonus. The bonus increases the defender's
// damage output when they outnumber the attacker, matching the description.
$embuscadeDefBoost = 1.0;
if ($defenderFormation == FORMATION_EMBUSCADE) {
	$totalAttackerMols = 0;
	$totalDefenderMols = 0;
	for ($c = 1; $c <= $nbClasses; $c++) {
		$totalAttackerMols += ${'classeAttaquant' . $c}['nombre'];
		$totalDefenderMols += ${'classeDefenseur' . $c}['nombre'];
	}
	if ($totalDefenderMols > $totalAttackerMols) {
		$embuscadeDefBoost = 1.0 + FORMATION_AMBUSH_ATTACK_BONUS; // P1-D4-022: +40% to defender's effective damage
	}
}

// Calcul des dégâts totaux — V4: covalent synergy formulas + formation bonuses + catalyst
$catalystAttackBonus = 1 + catalystEffect('attack_bonus');
$degatsAttaquant = 0;
$degatsDefenseur = 0;
for ($c = 1; $c <= $nbClasses; $c++) {
	$degatsAttaquant += attaque(${'classeAttaquant' . $c}['oxygene'], ${'classeAttaquant' . $c}['hydrogene'], $niveauxAtt['oxygene'], $bonusAttaqueMedaille) * $attIsotopeAttackMod[$c] * (1 + (($ionisateur['ionisateur'] * IONISATEUR_COMBAT_BONUS_PER_LEVEL) / 100)) * $bonusDuplicateurAttaque * $catalystAttackBonus * ${'classeAttaquant' . $c}['nombre'];
	$defBonusForClass = $defIsotopeAttackMod[$c]; // Apply defender isotope modifier to defense output
	// Phalange: class 1 gets extra defense bonus
	if ($defenderFormation == FORMATION_PHALANGE && $c == 1) {
		$defBonusForClass *= (1.0 + FORMATION_PHALANX_DEFENSE_BONUS);
	}
	$degatsDefenseur += defense(${'classeDefenseur' . $c}['carbone'], ${'classeDefenseur' . $c}['brome'], $niveauxDef['carbone'], $bonusDefenseMedaille) * $defBonusForClass * (1 + (($champdeforce['champdeforce'] * CHAMPDEFORCE_COMBAT_BONUS_PER_LEVEL) / 100)) * $bonusDuplicateurDefense * ${'classeDefenseur' . $c}['nombre'];
}

// Apply prestige combat bonuses (FIX FINDING-GAME-002: these were defined but never called)
$degatsAttaquant *= prestigeCombatBonus($actions['attaquant']);
$degatsDefenseur *= prestigeCombatBonus($actions['defenseur']);

// Apply compound synthesis combat bonuses
require_once(__DIR__ . '/compounds.php');
$compoundAttackBonus = getCompoundBonus($base, $actions['attaquant'], 'attack_boost');
$compoundDefenseBonus = getCompoundBonus($base, $actions['defenseur'], 'defense_boost');
if ($compoundAttackBonus > 0) $degatsAttaquant *= (1 + $compoundAttackBonus);
if ($compoundDefenseBonus > 0) $degatsDefenseur *= (1 + $compoundDefenseBonus);

// Specialization combat modifiers
$specAttackMod = getSpecModifier($actions['attaquant'], 'attack');
$specDefenseMod = getSpecModifier($actions['defenseur'], 'defense');
$degatsAttaquant *= (1 + $specAttackMod);
$degatsDefenseur *= (1 + $specDefenseMod);

// Apply Embuscade bonus to defender's effective damage (FIX FINDING-GAME-018)
$degatsDefenseur *= $embuscadeDefBoost;

// --- Attacker casualties — V4 OVERKILL CASCADE ---
$attaquantsRestants = 0;
$remainingDamage = $degatsDefenseur;
for ($i = 1; $i <= $nbClasses; $i++) {
	${'classe' . $i . 'AttaquantMort'} = 0;
	if (${'classeAttaquant' . $i}['nombre'] > 0 && $remainingDamage > 0) {
		$hpPerMol = pointsDeVieMolecule(${'classeAttaquant' . $i}['brome'], ${'classeAttaquant' . $i}['carbone'], $niveauxAtt['brome'])
					* $bonusDuplicateurAttaque * $attIsotopeHpMod[$i];
		if ($hpPerMol > 0) {
			$kills = min(${'classeAttaquant' . $i}['nombre'], floor($remainingDamage / $hpPerMol));
			${'classe' . $i . 'AttaquantMort'} = $kills;
			$remainingDamage -= $kills * $hpPerMol;
		} else {
			${'classe' . $i . 'AttaquantMort'} = ${'classeAttaquant' . $i}['nombre'];
		}
	}
	$attaquantsRestants += ${'classeAttaquant' . $i}['nombre'] - ${'classe' . $i . 'AttaquantMort'};
}

// --- Defender casualties — V4 OVERKILL CASCADE (formation-aware) ---
$defenseursRestants = 0;

if ($defenderFormation == FORMATION_PHALANGE) {
	// Phalange: Class 1 absorbs FORMATION_PHALANX_ABSORB% with defense bonus; overkill carries to classes 2-4
	$phalanxDamage = $degatsAttaquant * FORMATION_PHALANX_ABSORB;
	$otherDamage = $degatsAttaquant - $phalanxDamage;

	// Class 1 takes phalanx share
	$hpPerMol1 = pointsDeVieMolecule($classeDefenseur1['brome'], $classeDefenseur1['carbone'], $niveauxDef['brome'])
				 * $bonusDuplicateurDefense * $defIsotopeHpMod[1];
	$classe1DefenseurMort = 0;
	$phalanxOverflow = 0;
	if ($classeDefenseur1['nombre'] > 0 && $hpPerMol1 > 0) {
		$kills1 = min($classeDefenseur1['nombre'], floor($phalanxDamage / $hpPerMol1));
		$classe1DefenseurMort = $kills1;
		$phalanxOverflow = max(0, $phalanxDamage - $kills1 * $hpPerMol1);
	} elseif ($classeDefenseur1['nombre'] > 0) {
		$classe1DefenseurMort = $classeDefenseur1['nombre'];
		$phalanxOverflow = $phalanxDamage;
	}

	// Remaining classes absorb damage sequentially + phalanx overflow, cascade between them
	$remainingDamage = $otherDamage + $phalanxOverflow;
	for ($i = 2; $i <= $nbClasses; $i++) {
		${'classe' . $i . 'DefenseurMort'} = 0;
		if (${'classeDefenseur' . $i}['nombre'] > 0 && $remainingDamage > 0) {
			$hpPerMol = pointsDeVieMolecule(${'classeDefenseur' . $i}['brome'], ${'classeDefenseur' . $i}['carbone'], $niveauxDef['brome'])
						* $bonusDuplicateurDefense * $defIsotopeHpMod[$i];
			if ($hpPerMol > 0) {
				$kills = min(${'classeDefenseur' . $i}['nombre'], floor($remainingDamage / $hpPerMol));
				${'classe' . $i . 'DefenseurMort'} = $kills;
				$remainingDamage -= $kills * $hpPerMol;
			} else {
				${'classe' . $i . 'DefenseurMort'} = ${'classeDefenseur' . $i}['nombre'];
			}
		}
	}
} elseif ($defenderFormation == FORMATION_DISPERSEE) {
	// Dispersée: Equal split across active classes, overkill cascades within
	$activeDefClasses = 0;
	for ($i = 1; $i <= $nbClasses; $i++) {
		if (${'classeDefenseur' . $i}['nombre'] > 0) $activeDefClasses++;
	}
	$sharePerClass = ($activeDefClasses > 0) ? $degatsAttaquant / $activeDefClasses : 0;
	$overflow = 0;
	for ($i = 1; $i <= $nbClasses; $i++) {
		${'classe' . $i . 'DefenseurMort'} = 0;
		if (${'classeDefenseur' . $i}['nombre'] > 0) {
			$damageForClass = $sharePerClass + $overflow;
			$overflow = 0;
			$hpPerMol = pointsDeVieMolecule(${'classeDefenseur' . $i}['brome'], ${'classeDefenseur' . $i}['carbone'], $niveauxDef['brome'])
						* $bonusDuplicateurDefense * $defIsotopeHpMod[$i];
			if ($hpPerMol > 0) {
				$kills = min(${'classeDefenseur' . $i}['nombre'], floor($damageForClass / $hpPerMol));
				${'classe' . $i . 'DefenseurMort'} = $kills;
				$overflow = max(0, $damageForClass - $kills * $hpPerMol);
			} else {
				${'classe' . $i . 'DefenseurMort'} = ${'classeDefenseur' . $i}['nombre'];
			}
		}
	}
} else {
	// Embuscade/default: Straight cascade through all classes
	$remainingDamage = $degatsAttaquant;
	for ($i = 1; $i <= $nbClasses; $i++) {
		${'classe' . $i . 'DefenseurMort'} = 0;
		if (${'classeDefenseur' . $i}['nombre'] > 0 && $remainingDamage > 0) {
			$hpPerMol = pointsDeVieMolecule(${'classeDefenseur' . $i}['brome'], ${'classeDefenseur' . $i}['carbone'], $niveauxDef['brome'])
						* $bonusDuplicateurDefense * $defIsotopeHpMod[$i];
			if ($hpPerMol > 0) {
				$kills = min(${'classeDefenseur' . $i}['nombre'], floor($remainingDamage / $hpPerMol));
				${'classe' . $i . 'DefenseurMort'} = $kills;
				$remainingDamage -= $kills * $hpPerMol;
			} else {
				${'classe' . $i . 'DefenseurMort'} = ${'classeDefenseur' . $i}['nombre'];
			}
		}
	}
}

for ($i = 1; $i <= $nbClasses; $i++) {
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

$gagnantLabels = [0 => 'draw', 1 => 'defender', 2 => 'attacker'];
if (function_exists('logInfo')) {
	logInfo('COMBAT', 'Combat resolved', ['attacker' => $actions['attaquant'], 'defender' => $actions['defenseur'], 'winner' => $gagnantLabels[$gagnant], 'attacker_remaining' => $attaquantsRestants, 'defender_remaining' => $defenseursRestants]);
}

// Defensive rewards — when defender wins, they earn bonus resources and points
$defenseRewardEnergy = 0;
if ($gagnant == 1) { // Defender wins
	// Calculate what attacker would have pillaged (as a proxy for battle value)
	$totalAttackerPillage = 0;
	for ($c = 1; $c <= $nbClasses; $c++) {
		$totalAttackerPillage += ${'classeAttaquant' . $c}['nombre'] * pillage(${'classeAttaquant' . $c}['soufre'], ${'classeAttaquant' . $c}['chlore'], $niveauxAtt['soufre'], $bonusPillageMedaille);
	}
	$defenseRewardEnergy = floor($totalAttackerPillage * DEFENSE_REWARD_RATIO);
}

// BAL-CROSS C4: Cooldown on ALL outcomes (4h loss/draw, 1h win) to prevent chain-bullying
if ($gagnant != 2) { // Attacker lost or draw
	$cooldownExpires = time() + ATTACK_COOLDOWN_SECONDS;
} else { // Attacker won
	$cooldownExpires = time() + ATTACK_COOLDOWN_WIN_SECONDS;
}
dbExecute($base, 'INSERT INTO attack_cooldowns (attacker, defender, expires) VALUES (?, ?, ?)
	ON DUPLICATE KEY UPDATE expires = ?',
	'ssii', $actions['attaquant'], $actions['defenseur'], $cooldownExpires, $cooldownExpires);

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
if (!$ressourcesDefenseur) {
	logError("Combat: missing defender resources for " . $actions['defenseur']);
	throw new Exception('Missing defender resources');
}

$ressourcesJoueur = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $actions['attaquant']);
if (!$ressourcesJoueur) {
	logError("Combat: missing attacker resources for " . $actions['attaquant']);
	throw new Exception('Missing attacker resources');
}

// Vault protection — defender's coffrefort protects resources from pillage
$vaultLevel = 0;
$vaultData = dbFetchOne($base, 'SELECT coffrefort FROM constructions WHERE login=?', 's', $actions['defenseur']);
if ($vaultData && isset($vaultData['coffrefort'])) {
	$vaultLevel = $vaultData['coffrefort'];
}
$depotDefLevel = 1;
$depotDefData = dbFetchOne($base, 'SELECT depot FROM constructions WHERE login=?', 's', $actions['defenseur']);
if ($depotDefData && isset($depotDefData['depot'])) {
	$depotDefLevel = $depotDefData['depot'];
}
$vaultProtection = capaciteCoffreFort($vaultLevel, $depotDefLevel);

if ($gagnant == 2) { // Si le joueur gagnant est l'attaquant
	$ressourcesTotalesDefenseur = 0;
	foreach ($nomsRes as $num => $ressource) {
		// Only count resources above vault protection as pillageable
		$ressourcesTotalesDefenseur += max(0, $ressourcesDefenseur[$ressource] - $vaultProtection);
	} // On calcule les ressources pillables du défenseur

	if ($ressourcesTotalesDefenseur != 0) { // Si elles sont différentes de zéro (pas de division par zéro)
		$ressourcesAPiller = (($classeAttaquant1['nombre'] - $classe1AttaquantMort) * pillage($classeAttaquant1['soufre'], $classeAttaquant1['chlore'], $niveauxAtt['soufre'], $bonusPillageMedaille) +
			($classeAttaquant2['nombre'] - $classe2AttaquantMort) * pillage($classeAttaquant2['soufre'], $classeAttaquant2['chlore'], $niveauxAtt['soufre'], $bonusPillageMedaille) +
			($classeAttaquant3['nombre'] - $classe3AttaquantMort) * pillage($classeAttaquant3['soufre'], $classeAttaquant3['chlore'], $niveauxAtt['soufre'], $bonusPillageMedaille) +
			($classeAttaquant4['nombre'] - $classe4AttaquantMort) * pillage($classeAttaquant4['soufre'], $classeAttaquant4['chlore'], $niveauxAtt['soufre'], $bonusPillageMedaille));

		// V4: Apply weekly catalyst pillage bonus (migrated from pillage() which is now pure)
		$catalystPillageBonus = 1 + catalystEffect('pillage_bonus');
		$ressourcesAPiller *= $catalystPillageBonus;

		// Compound synthesis pillage boost
		$compoundPillageBonus = getCompoundBonus($base, $actions['attaquant'], 'pillage_boost');
		if ($compoundPillageBonus > 0) $ressourcesAPiller *= (1 + $compoundPillageBonus);

		// Alliance Bouclier research reduces pillage losses for defender
		$bouclierReduction = allianceResearchBonus($actions['defenseur'], 'pillage_defense');
		if ($bouclierReduction > 0) {
			$ressourcesAPiller = round($ressourcesAPiller * (1 - $bouclierReduction));
		}

		// P1-D4-031: Pillage tax — reduces wealth concentration
		$ressourcesAPiller = round($ressourcesAPiller * (1 - PILLAGE_TAX_RATE));

		// Calcul du pourcentage de chaque ressource pillable (above vault protection)
		foreach ($nomsRes as $num => $ressource) {
			$pillageable = max(0, $ressourcesDefenseur[$ressource] - $vaultProtection);
			${'rapport' . $ressource} = $pillageable / $ressourcesTotalesDefenseur;
			if ($ressourcesTotalesDefenseur > $ressourcesAPiller) {
				${$ressource . 'Pille'} = floor($ressourcesAPiller * ${'rapport' . $ressource});
			} else {
				${$ressource . 'Pille'} = floor($pillageable);
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
$hydrogeneTotal = ($classeAttaquant1['nombre'] - $classe1AttaquantMort) * potentielDestruction($classeAttaquant1['hydrogene'], $classeAttaquant1['oxygene'], $niveauxAtt['hydrogene']) + // Calcul des degats que va faire l'attaquant
	($classeAttaquant2['nombre'] - $classe2AttaquantMort) * potentielDestruction($classeAttaquant2['hydrogene'], $classeAttaquant2['oxygene'], $niveauxAtt['hydrogene']) +
	($classeAttaquant3['nombre'] - $classe3AttaquantMort) * potentielDestruction($classeAttaquant3['hydrogene'], $classeAttaquant3['oxygene'], $niveauxAtt['hydrogene']) +
	($classeAttaquant4['nombre'] - $classe4AttaquantMort) * potentielDestruction($classeAttaquant4['hydrogene'], $classeAttaquant4['oxygene'], $niveauxAtt['hydrogene']);
$degatsGenEnergie = 0;
$degatschampdeforce = 0;
$degatsDepot = 0;
$degatsProducteur = 0;
$degatsIonisateur = 0;
$pointsDefenseur = 0;
$destructionGenEnergie = "Non endommagé";
$destructionProducteur = "Non endommagé";
$destructionchampdeforce = "Non endommagé";
$destructionDepot = "Non endommagé";
$destructionIonisateur = "Non endommagé";

$constructions = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $actions['defenseur']);
if (!$constructions) {
	logError("Combat: missing defender constructions for building damage, " . $actions['defenseur']);
	throw new Exception('Missing defender constructions for damage phase');
}

if ($gagnant == 2 && $hydrogeneTotal > 0) { // Only damage buildings when attacker WINS
	// Recalculate hydrogeneTotal from SURVIVING attackers (FIX: was using pre-combat count)
	$hydrogeneTotal = 0;
	for ($i = 1; $i <= $nbClasses; $i++) {
		$surviving = ${'classeAttaquant' . $i}['nombre'] - ${'classe' . $i . 'AttaquantMort'};
		$hydrogeneTotal += $surviving * potentielDestruction(${'classeAttaquant' . $i}['hydrogene'], ${'classeAttaquant' . $i}['oxygene'], $niveauxAtt['hydrogene']);
	}

	// gestion des degats infligés
	// V4: Weighted building targeting — higher-level buildings attract more fire
	$buildingTargets = [
		'generateur' => max(1, $constructions['generateur']),
		'champdeforce' => max(1, $constructions['champdeforce']),
		'producteur' => max(1, $constructions['producteur']),
		'depot' => max(1, $constructions['depot']),
		'ionisateur' => max(1, $constructions['ionisateur']),
	];
	$totalWeight = array_sum($buildingTargets);

	for ($i = 1; $i <= $nbClasses; $i++) {
		$surviving = ${'classeAttaquant' . $i}['nombre'] - ${'classe' . $i . 'AttaquantMort'};
		if (${'classeAttaquant' . $i}['hydrogene'] > 0 && $surviving > 0) {
			$degatsAMettre = potentielDestruction(${'classeAttaquant' . $i}['hydrogene'], ${'classeAttaquant' . $i}['oxygene'], $niveauxAtt['hydrogene']) * $surviving;
			$roll = mt_rand(1, $totalWeight);
			$cumul = 0;
			foreach ($buildingTargets as $building => $weight) {
				$cumul += $weight;
				if ($roll <= $cumul) {
					switch ($building) {
						case 'generateur': $degatsGenEnergie += $degatsAMettre; break;
						case 'champdeforce': $degatschampdeforce += $degatsAMettre; break;
						case 'producteur': $degatsProducteur += $degatsAMettre; break;
						case 'depot': $degatsDepot += $degatsAMettre; break;
						case 'ionisateur': $degatsIonisateur += $degatsAMettre; break;
					}
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
			if ($constructions['champdeforce'] > 1) {
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
			if ($constructions['producteur'] > 1) {
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
	if ($degatsIonisateur > 0) {
		$destructionIonisateur = round($constructions['vieIonisateur'] / vieIonisateur($constructions['ionisateur']) * 100) . "% <img alt=\"fleche\" src=\"images/attaquer/arrow.png\"/ class=\"w16\" style=\"vertical-align:middle\"> " . max(round(($constructions['vieIonisateur'] - $degatsIonisateur) / vieIonisateur($constructions['ionisateur']) * 100), 0) . "%";
		if ($degatsIonisateur >= $constructions['vieIonisateur']) {
			if ($constructions['ionisateur'] > 1) {
				diminuerBatiment("ionisateur", $actions['defenseur']);
			} else {
				$degatsIonisateur = 0;
				$destructionIonisateur = "Niveau minimum";
			}
		} else {
			dbExecute($base, 'UPDATE constructions SET vieIonisateur=? WHERE login=?', 'ds', ($constructions['vieIonisateur'] - $degatsIonisateur), $actions['defenseur']);
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
if (!$pointsBDAttaquant || !$pointsBDDefenseur) {
	logError("Combat: missing player stats for points update, att=" . $actions['attaquant'] . " def=" . $actions['defenseur']);
	throw new Exception('Missing player stats');
}

// V4: Points based on mass destroyed (total atoms), not molecule count
$massDestroyedAttacker = 0;
$massDestroyedDefender = 0;
for ($i = 1; $i <= $nbClasses; $i++) {
	$attAtoms = 0;
	$defAtoms = 0;
	foreach ($nomsRes as $num => $ressource) {
		$attAtoms += ${'classeAttaquant' . $i}[$ressource];
		$defAtoms += ${'classeDefenseur' . $i}[$ressource];
	}
	$massDestroyedAttacker += ${'classe' . $i . 'AttaquantMort'} * $attAtoms;
	$massDestroyedDefender += ${'classe' . $i . 'DefenseurMort'} * $defAtoms;
}
$totalMassDestroyed = $massDestroyedAttacker + $massDestroyedDefender;
$battlePoints = min(COMBAT_POINTS_MAX_PER_BATTLE, floor(COMBAT_POINTS_BASE + COMBAT_POINTS_CASUALTY_SCALE * sqrt($totalMassDestroyed / COMBAT_MASS_DIVISOR)));

if ($gagnant == 1) { // DEFENSEUR wins — enhanced defense points
    $pointsDefenseur = floor($battlePoints * DEFENSE_POINTS_MULTIPLIER_BONUS);
    $pointsAttaquant = -$battlePoints;
} else if ($gagnant == 2 && $pertesDefenseur > 0) { // ATTAQUANT wins
    $pointsAttaquant = $battlePoints;
    $pointsDefenseur = -$battlePoints;
}
// Draw ($gagnant == 0): both stay at 0

// Catchup weekend multiplier (P1-D8-043)
if (CATCHUP_WEEKEND_ENABLED) {
    $dayOfWeek = (int)date('N'); // 1=Mon ... 6=Sat, 7=Sun
    if ($dayOfWeek >= 6) {
        $jeuData = dbFetchOne($base, 'SELECT debut FROM jeu LIMIT 1', '');
        $seasonStart = $jeuData ? (int)$jeuData['debut'] : time();
        $seasonDay = (int)floor((time() - $seasonStart) / SECONDS_PER_DAY);
        if ($seasonDay >= CATCHUP_WEEKEND_START_DAY && $seasonDay <= CATCHUP_WEEKEND_END_DAY) {
            if ($pointsAttaquant > 0) {
                $pointsAttaquant = (int)floor($pointsAttaquant * CATCHUP_WEEKEND_MULTIPLIER);
            }
            if ($pointsDefenseur > 0) {
                $pointsDefenseur = (int)floor($pointsDefenseur * CATCHUP_WEEKEND_MULTIPLIER);
            }
        }
    }
}

$totalPille = 0;
foreach ($nomsRes as $num => $ressource) {
	$totalPille += ${$ressource . 'Pille'};
}

// update des stats de combat

$perduesAttaquant = dbFetchOne($base, 'SELECT moleculesPerdues,ressourcesPillees FROM autre WHERE login=?', 's', $actions['attaquant']);
if (!$perduesAttaquant) { $perduesAttaquant = ['moleculesPerdues' => 0, 'ressourcesPillees' => 0]; }

$perduesDefenseur = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $actions['defenseur']);
if (!$perduesDefenseur) { $perduesDefenseur = ['moleculesPerdues' => 0]; }

ajouterPoints($pointsAttaquant, $actions['attaquant'], 1);
ajouterPoints($totalPille, $actions['attaquant'], 3);
ajouterPoints($pointsDefenseur, $actions['defenseur'], 2);
// FIX FINDING-GAME-011: Do NOT subtract pillage from defender's ressourcesPillees stat.
// That stat tracks how much a player has pillaged (offensive), not how much was stolen FROM them.
// Removing: ajouterPoints(-$totalPille, $actions['defenseur'], 3);

dbExecute($base, 'UPDATE autre SET moleculesPerdues=? WHERE login=?', 'ds', ($pertesAttaquant + $perduesAttaquant['moleculesPerdues']), $actions['attaquant']);
dbExecute($base, 'UPDATE autre SET moleculesPerdues=? WHERE login=?', 'ds', ($pertesDefenseur + $perduesDefenseur['moleculesPerdues']), $actions['defenseur']);




// On met à jour les ressources
// Build the SET clause and parameters dynamically for attacker
// FIX FINDING-GAME-008: Cap pillaged resources at attacker's storage limit
$depotAtt = dbFetchOne($base, 'SELECT depot FROM constructions WHERE login=?', 's', $actions['attaquant']);
$maxStorageAtt = placeDepot($depotAtt ? $depotAtt['depot'] : 1);
$setClauses = [];
$setTypes = '';
$setParams = [];
foreach ($nomsRes as $num => $ressource) {
	if (!in_array($ressource, $nomsRes, true)) {
		throw new \RuntimeException("Invalid column: $ressource");
	}
	$setClauses[] = "$ressource=?";
	$setTypes .= 'd';
	$setParams[] = min($maxStorageAtt, ($ressourcesJoueur[$ressource] + ${$ressource . 'Pille'}));
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
	if (!in_array($ressource, $nomsRes, true)) {
		throw new \RuntimeException("Invalid column: $ressource");
	}
	$setClauses[] = "$ressource=?";
	$setTypes .= 'd';
	$setParams[] = max(0, ($ressourcesDefenseur[$ressource] - ${$ressource . 'Pille'})); // FIX FINDING-GAME-026: clamp at 0
}
// Add defense reward energy to defender
if ($defenseRewardEnergy > 0) {
	$setClauses[] = "energie=?";
	$setTypes .= 'd';
	$depotDef = dbFetchOne($base, 'SELECT depot FROM constructions WHERE login=?', 's', $actions['defenseur']);
	$maxEnergy = placeDepot($depotDef ? $depotDef['depot'] : 1);
	$setParams[] = min($maxEnergy, $ressourcesDefenseur['energie'] + $defenseRewardEnergy);
}
$setParams[] = $actions['defenseur'];
$setTypes .= 's';
$sql1 = 'UPDATE ressources SET ' . implode(',', $setClauses) . ' WHERE login=?';
dbExecute($base, $sql1, $setTypes, ...$setParams);

// Atomic increment nbattaques
dbExecute($base, 'UPDATE autre SET nbattaques = nbattaques + 1 WHERE login=?', 's', $actions['attaquant']);

// Si les alliances sont en guerre on inscrit les pertes
$joueur = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $actions['attaquant']);
$idallianceAutre = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $actions['defenseur']);

$joueurAlliance = ($joueur && isset($joueur['idalliance'])) ? $joueur['idalliance'] : 0;
$autreAlliance = ($idallianceAutre && isset($idallianceAutre['idalliance'])) ? $idallianceAutre['idalliance'] : 0;

$guerres = dbFetchAll($base, 'SELECT * FROM declarations WHERE type=0 AND fin=0 AND ((alliance1=? AND alliance2=?) OR (alliance2=? AND alliance1=?))', 'iiii', $joueurAlliance, $autreAlliance, $joueurAlliance, $autreAlliance);
$guerre = !empty($guerres) ? $guerres[0] : null;
$nbGuerres = count($guerres);
if ($nbGuerres >=  1) {
	if ($guerre['alliance1'] == $joueurAlliance) {
		dbExecute($base, 'UPDATE declarations SET pertes1=?, pertes2=? WHERE id=?', 'ddi', ($guerre['pertes1'] + $pertesAttaquant), ($guerre['pertes2'] + $pertesDefenseur), $guerre['id']);
	} else {
		dbExecute($base, 'UPDATE declarations SET pertes1=?, pertes2=? WHERE id=?', 'ddi', ($guerre['pertes1'] + $pertesDefenseur), ($guerre['pertes2'] + $pertesAttaquant), $guerre['id']);
	}
}
