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
	$bonusDuplicateurAttaque = 1 + ($duplicateurAttaque['duplicateur'] / 100);
}

$idallianceDef = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $actions['defenseur']);
$bonusDuplicateurDefense = 1;
if ($idallianceDef['idalliance'] > 0) {
	$duplicateurDefense = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', 'i', $idallianceDef['idalliance']);
	$bonusDuplicateurDefense = 1 + ($duplicateurDefense['duplicateur'] / 100);
}


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

// Chemical Reaction Detection — check all class pairs for reaction conditions
// Each reaction grants bonuses when two classes meet atom thresholds
global $CHEMICAL_REACTIONS;
$activeReactionsAtt = [];
$activeReactionsDef = [];

function checkReactions($classes, $nbClasses, &$activeReactions) {
	global $CHEMICAL_REACTIONS;
	for ($a = 1; $a <= $nbClasses; $a++) {
		for ($b = 1; $b <= $nbClasses; $b++) {
			if ($a == $b) continue;
			foreach ($CHEMICAL_REACTIONS as $name => $reaction) {
				$matchA = true;
				foreach ($reaction['condA'] as $atom => $threshold) {
					if (($classes[$a][$atom] ?? 0) < $threshold) { $matchA = false; break; }
				}
				$matchB = true;
				foreach ($reaction['condB'] as $atom => $threshold) {
					if (($classes[$b][$atom] ?? 0) < $threshold) { $matchB = false; break; }
				}
				if ($matchA && $matchB && !isset($activeReactions[$name])) {
					$activeReactions[$name] = $reaction['bonus'];
				}
			}
		}
	}
}

// Build class arrays for reaction checking
$attClasses = [];
$defClasses = [];
for ($c = 1; $c <= $nbClasses; $c++) {
	if (${'classeAttaquant' . $c}['nombre'] > 0) $attClasses[$c] = ${'classeAttaquant' . $c};
	if (${'classeDefenseur' . $c}['nombre'] > 0) $defClasses[$c] = ${'classeDefenseur' . $c};
}
checkReactions($attClasses, $nbClasses, $activeReactionsAtt);
checkReactions($defClasses, $nbClasses, $activeReactionsDef);

// Calculate reaction bonus multipliers
$attReactionAttackBonus = 1.0;
$attReactionHpBonus = 1.0;
$attReactionPillageBonus = 1.0;
$defReactionDefenseBonus = 1.0;
$defReactionHpBonus = 1.0;
foreach ($activeReactionsAtt as $name => $bonuses) {
	if (isset($bonuses['attack'])) $attReactionAttackBonus += $bonuses['attack'];
	if (isset($bonuses['hp'])) $attReactionHpBonus += $bonuses['hp'];
	if (isset($bonuses['pillage'])) $attReactionPillageBonus += $bonuses['pillage'];
	if (isset($bonuses['defense'])) $attReactionAttackBonus += 0; // attackers don't use defense bonus
}
foreach ($activeReactionsDef as $name => $bonuses) {
	if (isset($bonuses['defense'])) $defReactionDefenseBonus += $bonuses['defense'];
	if (isset($bonuses['hp'])) $defReactionHpBonus += $bonuses['hp'];
	if (isset($bonuses['attack'])) $defReactionDefenseBonus += 0; // defenders don't use attack bonus
}

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
		$embuscadeDefBoost = 1.0 + FORMATION_AMBUSH_ATTACK_BONUS; // +25% to defender's effective damage
	}
}

// Calcul des dégâts totaux avec réactions chimiques + formation bonuses + catalyst
$catalystAttackBonus = 1 + catalystEffect('attack_bonus');
$degatsAttaquant = 0;
$degatsDefenseur = 0;
for ($c = 1; $c <= 4; $c++) {
	$degatsAttaquant += attaque(${'classeAttaquant' . $c}['oxygene'], $niveauxAtt['oxygene'], $actions['attaquant']) * $attReactionAttackBonus * $attIsotopeAttackMod[$c] * (1 + (($ionisateur['ionisateur'] * 2) / 100)) * $bonusDuplicateurAttaque * $catalystAttackBonus * ${'classeAttaquant' . $c}['nombre'];
	$defBonusForClass = $defReactionDefenseBonus; // FIX: removed $defIsotopeAttackMod and $formationDefenseBonus (embuscade now boosts $degatsDefenseur directly)
	// Phalange: class 1 gets extra defense bonus
	if ($defenderFormation == FORMATION_PHALANGE && $c == 1) {
		$defBonusForClass *= (1.0 + FORMATION_PHALANX_DEFENSE_BONUS);
	}
	$degatsDefenseur += defense(${'classeDefenseur' . $c}['carbone'], $niveauxDef['carbone'], $actions['defenseur']) * $defBonusForClass * (1 + (($champdeforce['champdeforce'] * 2) / 100)) * $bonusDuplicateurDefense * ${'classeDefenseur' . $c}['nombre'];
}

// Apply prestige combat bonuses (FIX FINDING-GAME-002: these were defined but never called)
$degatsAttaquant *= prestigeCombatBonus($actions['attaquant']);
$degatsDefenseur *= prestigeCombatBonus($actions['defenseur']);

// Apply Embuscade bonus to defender's effective damage (FIX FINDING-GAME-018)
$degatsDefenseur *= $embuscadeDefBoost;

// Calcul des pertes — PROPORTIONAL DAMAGE DISTRIBUTION
// Damage is spread across classes proportional to each class's total HP pool.
// This prevents meat-shield exploits where class 1 absorbs everything.

$attaquantsRestants = 0;
$defenseursRestants = 0;

// --- Attacker casualties (from defender's damage) ---
$totalAttackerHP = 0;
for ($i = 1; $i <= $nbClasses; $i++) {
	$hpPerMol = pointsDeVieMolecule(${'classeAttaquant' . $i}['brome'], $niveauxAtt['brome']) * $bonusDuplicateurAttaque * $attReactionHpBonus * $attIsotopeHpMod[$i];
	${'attHP' . $i} = $hpPerMol * ${'classeAttaquant' . $i}['nombre'];
	$totalAttackerHP += ${'attHP' . $i};
}

for ($i = 1; $i <= $nbClasses; $i++) {
	${'classe' . $i . 'AttaquantMort'} = 0;
	if (${'classeAttaquant' . $i}['nombre'] > 0 && $degatsDefenseur > 0) {
		$hpPerMol = pointsDeVieMolecule(${'classeAttaquant' . $i}['brome'], $niveauxAtt['brome']) * $bonusDuplicateurAttaque * $attReactionHpBonus * $attIsotopeHpMod[$i];
		// Proportional damage share based on class HP pool
		$damageShare = ($totalAttackerHP > 0) ? $degatsDefenseur * (${'attHP' . $i} / $totalAttackerHP) : 0;
		if ($hpPerMol > 0) {
			${'classe' . $i . 'AttaquantMort'} = min(${'classeAttaquant' . $i}['nombre'], floor($damageShare / $hpPerMol));
		} else {
			// 0 Brome = 0 HP, any damage kills all
			${'classe' . $i . 'AttaquantMort'} = ${'classeAttaquant' . $i}['nombre'];
		}
	}
	$attaquantsRestants += ${'classeAttaquant' . $i}['nombre'] - ${'classe' . $i . 'AttaquantMort'};
}

// --- Defender casualties (from attacker's damage) — FORMATION-AWARE ---
$totalDefenderHP = 0;
for ($i = 1; $i <= $nbClasses; $i++) {
	$hpPerMol = pointsDeVieMolecule(${'classeDefenseur' . $i}['brome'], $niveauxDef['brome']) * $bonusDuplicateurDefense * $defReactionHpBonus * $defIsotopeHpMod[$i];
	${'defHP' . $i} = $hpPerMol * ${'classeDefenseur' . $i}['nombre'];
	$totalDefenderHP += ${'defHP' . $i};
}

// Calculate damage share per class based on formation
$defDamageShares = [];
if ($defenderFormation == FORMATION_DISPERSEE) {
	// FIX FINDING-GAME-006: Only split damage among classes that have molecules
	$activeDefClasses = 0;
	for ($i = 1; $i <= $nbClasses; $i++) {
		if (${'classeDefenseur' . $i}['nombre'] > 0) $activeDefClasses++;
	}
	$sharePerClass = ($activeDefClasses > 0) ? 1.0 / $activeDefClasses : 0.25;
	for ($i = 1; $i <= $nbClasses; $i++) {
		if (${'classeDefenseur' . $i}['nombre'] > 0) {
			$defDamageShares[$i] = $degatsAttaquant * $sharePerClass;
		} else {
			$defDamageShares[$i] = 0;
		}
	}
} elseif ($defenderFormation == FORMATION_PHALANGE) {
	// Class 1 absorbs 70%, remaining 30% split equally among classes 2-4
	$defDamageShares[1] = $degatsAttaquant * FORMATION_PHALANX_ABSORB;
	$remainingShare = (1.0 - FORMATION_PHALANX_ABSORB) / max(1, $nbClasses - 1);
	for ($i = 2; $i <= $nbClasses; $i++) {
		$defDamageShares[$i] = $degatsAttaquant * $remainingShare;
	}
} else {
	// Default proportional distribution (or Embuscade which affects damage, not distribution)
	for ($i = 1; $i <= $nbClasses; $i++) {
		$defDamageShares[$i] = ($totalDefenderHP > 0) ? $degatsAttaquant * (${'defHP' . $i} / $totalDefenderHP) : 0;
	}
}

for ($i = 1; $i <= $nbClasses; $i++) {
	${'classe' . $i . 'DefenseurMort'} = 0;
	if (${'classeDefenseur' . $i}['nombre'] > 0 && $degatsAttaquant > 0) {
		$hpPerMol = pointsDeVieMolecule(${'classeDefenseur' . $i}['brome'], $niveauxDef['brome']) * $bonusDuplicateurDefense * $defReactionHpBonus * $defIsotopeHpMod[$i];
		$damageShare = $defDamageShares[$i];
		if ($hpPerMol > 0) {
			${'classe' . $i . 'DefenseurMort'} = min(${'classeDefenseur' . $i}['nombre'], floor($damageShare / $hpPerMol));
		} else {
			${'classe' . $i . 'DefenseurMort'} = ${'classeDefenseur' . $i}['nombre'];
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
		$totalAttackerPillage += ${'classeAttaquant' . $c}['nombre'] * pillage(${'classeAttaquant' . $c}['soufre'], $niveauxAtt['soufre'], $actions['attaquant']);
	}
	$defenseRewardEnergy = floor($totalAttackerPillage * DEFENSE_REWARD_RATIO);
}

// FIX FINDING-GAME-007: Set attack cooldown on loss AND draw (not just defender wins)
if ($gagnant != 2) { // Attacker did not win (draw or loss)
	$cooldownExpires = time() + ATTACK_COOLDOWN_SECONDS;
	dbExecute($base, 'INSERT INTO attack_cooldowns (attacker, defender, expires) VALUES (?, ?, ?)',
		'ssi', $actions['attaquant'], $actions['defenseur'], $cooldownExpires);
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

// Vault protection — defender's coffrefort protects resources from pillage
$vaultLevel = 0;
$vaultData = dbFetchOne($base, 'SELECT coffrefort FROM constructions WHERE login=?', 's', $actions['defenseur']);
if ($vaultData && isset($vaultData['coffrefort'])) {
	$vaultLevel = $vaultData['coffrefort'];
}
$vaultProtection = VAULT_PROTECTION_PER_LEVEL * $vaultLevel;

if ($gagnant == 2) { // Si le joueur gagnant est l'attaquant
	$ressourcesTotalesDefenseur = 0;
	foreach ($nomsRes as $num => $ressource) {
		// Only count resources above vault protection as pillageable
		$ressourcesTotalesDefenseur += max(0, $ressourcesDefenseur[$ressource] - $vaultProtection);
	} // On calcule les ressources pillables du défenseur

	if ($ressourcesTotalesDefenseur != 0) { // Si elles sont différentes de zéro (pas de division par zéro)
		$ressourcesAPiller = (($classeAttaquant1['nombre'] - $classe1AttaquantMort) * pillage($classeAttaquant1['soufre'], $niveauxAtt['soufre'], $actions['attaquant']) +
			($classeAttaquant2['nombre'] - $classe2AttaquantMort) * pillage($classeAttaquant2['soufre'], $niveauxAtt['soufre'], $actions['attaquant']) +
			($classeAttaquant3['nombre'] - $classe3AttaquantMort) * pillage($classeAttaquant3['soufre'], $niveauxAtt['soufre'], $actions['attaquant']) +
			($classeAttaquant4['nombre'] - $classe4AttaquantMort) * pillage($classeAttaquant4['soufre'], $niveauxAtt['soufre'], $actions['attaquant'])) * $attReactionPillageBonus;

		// Alliance Bouclier research reduces pillage losses for defender
		$bouclierReduction = allianceResearchBonus($actions['defenseur'], 'pillage_defense');
		if ($bouclierReduction > 0) {
			$ressourcesAPiller = round($ressourcesAPiller * (1 - $bouclierReduction));
		}

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
$hydrogeneTotal = ($classeAttaquant1['nombre'] - $classe1AttaquantMort) * potentielDestruction($classeAttaquant1['hydrogene'], $niveauxAtt['hydrogene']) + // Calcul des degats que va faire l'attaquant
	($classeAttaquant2['nombre'] - $classe2AttaquantMort) * potentielDestruction($classeAttaquant2['hydrogene'], $niveauxAtt['hydrogene']) +
	($classeAttaquant3['nombre'] - $classe3AttaquantMort) * potentielDestruction($classeAttaquant3['hydrogene'], $niveauxAtt['hydrogene']) +
	($classeAttaquant4['nombre'] - $classe4AttaquantMort) * potentielDestruction($classeAttaquant4['hydrogene'], $niveauxAtt['hydrogene']);
$degatsGenEnergie = 0;
$degatschampdeforce = 0;
$degatsDepot = 0;
$degatsProducteur = 0;
$pointsDefenseur = 0;
$destructionGenEnergie = "Non endommagé";
$destructionProducteur = "Non endommagé";
$destructionchampdeforce = "Non endommagé";
$destructionDepot = "Non endommagé";

$constructions = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $actions['defenseur']);

if ($gagnant == 2 && $hydrogeneTotal > 0) { // Only damage buildings when attacker WINS
	// Recalculate hydrogeneTotal from SURVIVING attackers (FIX: was using pre-combat count)
	$hydrogeneTotal = 0;
	for ($i = 1; $i <= $nbClasses; $i++) {
		$surviving = ${'classeAttaquant' . $i}['nombre'] - ${'classe' . $i . 'AttaquantMort'};
		$hydrogeneTotal += $surviving * potentielDestruction(${'classeAttaquant' . $i}['hydrogene'], $niveauxAtt['hydrogene']);
	}

	// gestion des degats infligés
	if ($constructions['champdeforce'] > $constructions['generateur'] && $constructions['champdeforce'] > $constructions['producteur'] && $constructions['champdeforce'] > $constructions['depot']) {
		for ($i = 1; $i <= $nbClasses; $i++) {
			$surviving = ${'classeAttaquant' . $i}['nombre'] - ${'classe' . $i . 'AttaquantMort'};
			if (${'classeAttaquant' . $i}['hydrogene'] > 0 && $surviving > 0) {
				$degatsAMettre = potentielDestruction(${'classeAttaquant' . $i}['hydrogene'], $niveauxAtt['hydrogene']) * $surviving;
				$degatschampdeforce += $degatsAMettre;
			}
		}
	} else {
		for ($i = 1; $i <= $nbClasses; $i++) {
			$surviving = ${'classeAttaquant' . $i}['nombre'] - ${'classe' . $i . 'AttaquantMort'};
			if (${'classeAttaquant' . $i}['hydrogene'] > 0 && $surviving > 0) {
				$bat = rand(1, 4);
				$degatsAMettre = potentielDestruction(${'classeAttaquant' . $i}['hydrogene'], $niveauxAtt['hydrogene']) * $surviving;
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
}

// calcul des stats de combat

$pertesAttaquant = $classe1AttaquantMort + $classe2AttaquantMort + $classe3AttaquantMort + $classe4AttaquantMort;
$pertesDefenseur = $classe1DefenseurMort + $classe2DefenseurMort + $classe3DefenseurMort + $classe4DefenseurMort;

$pointsAttaquant = 0;
$pointsDefenseur = 0;

$pointsBDAttaquant = dbFetchOne($base, 'SELECT points,pointsAttaque,pointsDefense,totalPoints FROM autre WHERE login=?', 's', $actions['attaquant']);
$pointsBDDefenseur = dbFetchOne($base, 'SELECT points,pointsAttaque,pointsDefense,totalPoints FROM autre WHERE login=?', 's', $actions['defenseur']);

// Scale combat points with battle size (total casualties)
$totalCasualties = $pertesAttaquant + $pertesDefenseur;
$battlePoints = min(COMBAT_POINTS_MAX_PER_BATTLE, floor(COMBAT_POINTS_BASE + COMBAT_POINTS_CASUALTY_SCALE * sqrt($totalCasualties)));

if ($gagnant == 1) { // DEFENSEUR wins — enhanced defense points
    $pointsDefenseur = floor($battlePoints * DEFENSE_POINTS_MULTIPLIER_BONUS);
    $pointsAttaquant = -$battlePoints;
} else if ($gagnant == 2 && $pertesDefenseur > 0) { // ATTAQUANT wins
    $pointsAttaquant = $battlePoints;
    $pointsDefenseur = -$battlePoints;
}
// Draw ($gagnant == 0): both stay at 0

$totalPille = 0;
foreach ($nomsRes as $num => $ressource) {
	$totalPille += ${$ressource . 'Pille'};
}

// update des stats de combat

$perduesAttaquant = dbFetchOne($base, 'SELECT moleculesPerdues,ressourcesPillees FROM autre WHERE login=?', 's', $actions['attaquant']);

$perduesDefenseur = dbFetchOne($base, 'SELECT moleculesPerdues FROM autre WHERE login=?', 's', $actions['defenseur']);

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
$maxStorageAtt = placeDepot($depotAtt['depot']);
$setClauses = [];
$setTypes = '';
$setParams = [];
foreach ($nomsRes as $num => $ressource) {
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
	$setClauses[] = "$ressource=?";
	$setTypes .= 'd';
	$setParams[] = max(0, ($ressourcesDefenseur[$ressource] - ${$ressource . 'Pille'})); // FIX FINDING-GAME-026: clamp at 0
}
// Add defense reward energy to defender
if ($defenseRewardEnergy > 0) {
	$setClauses[] = "energie=?";
	$setTypes .= 'd';
	$depotDef = dbFetchOne($base, 'SELECT depot FROM constructions WHERE login=?', 's', $actions['defenseur']);
	$maxEnergy = placeDepot($depotDef['depot']);
	$setParams[] = min($maxEnergy, $ressourcesDefenseur['energie'] + $defenseRewardEnergy);
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
