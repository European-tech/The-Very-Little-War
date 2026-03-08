<?php
// Récupération des variables d'attaque, de défense, de coup critiques et de capacité de pillage pour chaque classe
// Pour l'attaquant

// LOW-015: Use explicit arrays instead of variable-variables ($$varName) for class data.
// This eliminates the fragile ${'classeAttaquant'.$c} pattern with a whitelisted array lookup.
$rowsDefenseur = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC FOR UPDATE', 's', $actions['defenseur']);

$classeDefenseur = []; // LOW-015: array-based class storage
$c = 1;
foreach ($rowsDefenseur as $defRow) {
	$defRow['nombre'] = ceil($defRow['nombre']);
	$classeDefenseur[$c] = $defRow;
	$c++;
}

$rowsAttaquant = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC FOR UPDATE', 's', $actions['attaquant']);

$classeAttaquant = []; // LOW-015: array-based class storage
$c = 1;
$chaineExplosee = explode(";", $actions['troupes']);
foreach ($rowsAttaquant as $attRow) {
	$attRow['nombre'] = ceil($chaineExplosee[$c - 1]); // on prends le nombre d'unites en attaque
	$classeAttaquant[$c] = $attRow;
	$c++;
}

// recupération des niveaux des atomes

// MED-024: Hoist all constructions queries to 2 FOR UPDATE at the top of the transaction.
// Previously there were up to 10 individual SELECT queries against constructions for the same
// two logins. Now we do exactly one per player with FOR UPDATE and reuse the row throughout.
$constructionsAtt = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=? FOR UPDATE', 's', $actions['attaquant']);
if (!$constructionsAtt) {
	logError("Combat: missing attacker constructions for " . $actions['attaquant'] . " at line " . __LINE__);
	throw new Exception('Missing attacker constructions');
}
$constructionsDef = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=? FOR UPDATE', 's', $actions['defenseur']);
if (!$constructionsDef) {
	logError("Combat: missing defender constructions for " . $actions['defenseur'] . " at line " . __LINE__);
	throw new Exception('Missing defender constructions');
}

// Derive the individual values previously fetched by separate queries
$niveauxAttaquant = explode(";", $constructionsAtt['pointsCondenseur']);
foreach ($nomsRes as $num => $ressource) {
	$niveauxAtt[$ressource] = $niveauxAttaquant[$num];
}

$niveauxDefenseur = explode(";", $constructionsDef['pointsCondenseur']);
foreach ($nomsRes as $num => $ressource) {
	$niveauxDef[$ressource] = $niveauxDefenseur[$num];
}

$ionisateur  = $constructionsAtt; // alias — ['ionisateur'] column available
$champdeforce = $constructionsDef; // alias — ['champdeforce'] column available

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
	$attIso = intval($classeAttaquant[$c]['isotope'] ?? 0);
	$defIso = intval($classeDefenseur[$c]['isotope'] ?? 0);

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
		if (intval($classeAttaquant[$c]['isotope'] ?? 0) != ISOTOPE_CATALYTIQUE) {
			$attIsotopeAttackMod[$c] += ISOTOPE_CATALYTIQUE_ALLY_BONUS;
			$attIsotopeHpMod[$c] += ISOTOPE_CATALYTIQUE_ALLY_BONUS;
		}
	}
}
if ($defHasCatalytique) {
	for ($c = 1; $c <= $nbClasses; $c++) {
		if (intval($classeDefenseur[$c]['isotope'] ?? 0) != ISOTOPE_CATALYTIQUE) {
			$defIsotopeAttackMod[$c] += ISOTOPE_CATALYTIQUE_ALLY_BONUS;
			$defIsotopeHpMod[$c] += ISOTOPE_CATALYTIQUE_ALLY_BONUS;
		}
	}
}

// Defensive Formation — use hoisted $constructionsDef (MED-024)
$defenderFormation = isset($constructionsDef['formation']) ? intval($constructionsDef['formation']) : FORMATION_DISPERSEE;

// Formation bonuses — Embuscade now applied as $embuscadeDefBoost after damage calc (FIX FINDING-GAME-018)

// FIX FINDING-GAME-018: Embuscade now correctly boosts defender's EFFECTIVE DAMAGE (defense score)
// instead of misleadingly named $formationDefenseBonus. The bonus increases the defender's
// damage output when they outnumber the attacker, matching the description.
$embuscadeDefBoost = 1.0;
if ($defenderFormation == FORMATION_EMBUSCADE) {
	$totalAttackerMols = 0;
	$totalDefenderMols = 0;
	for ($c = 1; $c <= $nbClasses; $c++) {
		$totalAttackerMols += $classeAttaquant[$c]['nombre'];
		$totalDefenderMols += $classeDefenseur[$c]['nombre'];
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
	$degatsAttaquant += attaque($classeAttaquant[$c]['oxygene'], $classeAttaquant[$c]['hydrogene'], $niveauxAtt['oxygene'], $bonusAttaqueMedaille) * $attIsotopeAttackMod[$c] * (1 + (($ionisateur['ionisateur'] * IONISATEUR_COMBAT_BONUS_PER_LEVEL) / 100)) * $bonusDuplicateurAttaque * $catalystAttackBonus * $classeAttaquant[$c]['nombre'];
	$defBonusForClass = $defIsotopeAttackMod[$c]; // Apply defender isotope modifier to defense output
	$degatsDefenseur += defense($classeDefenseur[$c]['carbone'], $classeDefenseur[$c]['brome'], $niveauxDef['carbone'], $bonusDefenseMedaille) * $defBonusForClass * (1 + (($champdeforce['champdeforce'] * CHAMPDEFORCE_COMBAT_BONUS_PER_LEVEL) / 100)) * $bonusDuplicateurDefense * $classeDefenseur[$c]['nombre'];
}

// Apply prestige combat bonuses (FIX FINDING-GAME-002: these were defined but never called)
$degatsAttaquant *= prestigeCombatBonus($actions['attaquant']);
$degatsDefenseur *= prestigeCombatBonus($actions['defenseur']);

// Apply compound synthesis combat bonuses.
// HIGH-024: Use the snapshotted values stored at attack-launch time (columns added by
// migration 0039) rather than re-querying live compound state. This prevents a player
// from activating a compound AFTER launching an attack to retroactively benefit from it.
// Rows inserted before migration 0039 have DEFAULT 0.0, so legacy attacks are unaffected.
$compoundAttackBonus  = isset($actions['compound_atk_bonus']) ? (float)$actions['compound_atk_bonus'] : 0.0;
$compoundDefenseBonus = isset($actions['compound_def_bonus']) ? (float)$actions['compound_def_bonus'] : 0.0;
if ($compoundAttackBonus > 0) $degatsAttaquant *= (1 + $compoundAttackBonus);
if ($compoundDefenseBonus > 0) $degatsDefenseur *= (1 + $compoundDefenseBonus);

// Specialization combat modifiers (MEDIUM-018: cross-role modifiers added)
$specAttackMod      = getSpecModifier($actions['attaquant'], 'attack');   // attacker's attack spec → more attacker damage
$specDefenseMod     = getSpecModifier($actions['defenseur'], 'defense');  // defender's defense spec → more defender counter-damage
$specAttackMod_def  = getSpecModifier($actions['defenseur'], 'attack');   // defender's attack spec → boosts their counter-damage
$specDefenseMod_att = getSpecModifier($actions['attaquant'], 'defense');  // attacker's defense spec → reduces damage they take
$degatsAttaquant *= (1 + $specAttackMod);
$degatsDefenseur *= (1 + $specDefenseMod);
$degatsDefenseur *= (1 + $specAttackMod_def);                              // defender's attack specialization boosts counter-damage
$degatsDefenseur /= max(1.0, 1.0 + $specDefenseMod_att);                  // attacker's defense specialization reduces damage taken

// MEDIUM-017: Apply global catalyst attack bonus to defender's counter-damage too
// The weekly catalyst (e.g., Combustion +10% attack) is a global effect — symmetrical for both sides
$degatsDefenseur *= (1 + catalystEffect('attack_bonus'));

// Apply Embuscade bonus to defender's effective damage (FIX FINDING-GAME-018)
$degatsDefenseur *= $embuscadeDefBoost;

// --- Attacker casualties — V4 OVERKILL CASCADE ---
// LOW-015: Explicit arrays replace variable-variables for kills tracking
$attaquantMort = []; // kills per class for attacker
$defenseursRestants = 0; // initialise early; computed after all formation branches
$defenseurMort = []; // kills per class for defender
$attaquantsRestants = 0;
$remainingDamage = $degatsDefenseur;
for ($i = 1; $i <= $nbClasses; $i++) {
	$attaquantMort[$i] = 0;
	if ($classeAttaquant[$i]['nombre'] > 0 && $remainingDamage > 0) {
		$hpPerMol = pointsDeVieMolecule($classeAttaquant[$i]['brome'], $classeAttaquant[$i]['carbone'], $niveauxAtt['brome'])
					* $bonusDuplicateurAttaque * $attIsotopeHpMod[$i];
		if ($hpPerMol > 0) {
			$kills = min($classeAttaquant[$i]['nombre'], (int)floor($remainingDamage / $hpPerMol));
			$remainder = fmod($remainingDamage, $hpPerMol);
			if ($remainder > 0 && (random_int(0, 1000000) / 1000000.0) < $remainder / $hpPerMol && $kills < $classeAttaquant[$i]['nombre']) $kills++;
			$attaquantMort[$i] = $kills;
			$remainingDamage -= $kills * $hpPerMol;
			$remainingDamage = max(0.0, $remainingDamage); // guard against float underflow
		} else {
			$attaquantMort[$i] = $classeAttaquant[$i]['nombre'];
		}
	}
	$attaquantsRestants += $classeAttaquant[$i]['nombre'] - $attaquantMort[$i];
}

// --- Defender casualties — V4 OVERKILL CASCADE (formation-aware) ---
if ($defenderFormation == FORMATION_PHALANGE) {
	// Phalange: Class 1 absorbs FORMATION_PHALANX_ABSORB% with defense bonus; overkill carries to classes 2-4
	$phalanxDamage = $degatsAttaquant * FORMATION_PHALANX_ABSORB;
	$otherDamage = $degatsAttaquant - $phalanxDamage;

	// Class 1 takes phalanx share
	$hpPerMol1 = pointsDeVieMolecule($classeDefenseur[1]['brome'], $classeDefenseur[1]['carbone'], $niveauxDef['brome'])
				 * $bonusDuplicateurDefense * $defIsotopeHpMod[1]
				 * (1.0 + FORMATION_PHALANX_DEFENSE_BONUS); // Phalange: class 1 harder to kill
	$defenseurMort[1] = 0;
	$phalanxOverflow = 0;
	if ($classeDefenseur[1]['nombre'] > 0 && $hpPerMol1 > 0) {
		$kills1 = min($classeDefenseur[1]['nombre'], (int)floor($phalanxDamage / $hpPerMol1));
		$remainder1 = fmod($phalanxDamage, $hpPerMol1);
		if ($remainder1 > 0 && (random_int(0, 1000000) / 1000000.0) < $remainder1 / $hpPerMol1 && $kills1 < $classeDefenseur[1]['nombre']) $kills1++;
		$defenseurMort[1] = $kills1;
		$phalanxOverflow = max(0.0, $phalanxDamage - $kills1 * $hpPerMol1);
	} elseif ($classeDefenseur[1]['nombre'] > 0) {
		$defenseurMort[1] = $classeDefenseur[1]['nombre'];
		$phalanxOverflow = $phalanxDamage;
	} else {
		// Class 1 slot is empty — entire phalanx share cascades to remaining classes
		$phalanxOverflow = $phalanxDamage;
	}

	// Remaining classes absorb damage sequentially + phalanx overflow, cascade between them
	$remainingDamage = $otherDamage + $phalanxOverflow;
	for ($i = 2; $i <= $nbClasses; $i++) {
		$defenseurMort[$i] = 0;
		if ($classeDefenseur[$i]['nombre'] > 0 && $remainingDamage > 0) {
			$hpPerMol = pointsDeVieMolecule($classeDefenseur[$i]['brome'], $classeDefenseur[$i]['carbone'], $niveauxDef['brome'])
						* $bonusDuplicateurDefense * $defIsotopeHpMod[$i];
			if ($hpPerMol > 0) {
				$kills = min($classeDefenseur[$i]['nombre'], (int)floor($remainingDamage / $hpPerMol));
				$rem = fmod($remainingDamage, $hpPerMol);
				if ($rem > 0 && (random_int(0, 1000000) / 1000000.0) < $rem / $hpPerMol && $kills < $classeDefenseur[$i]['nombre']) $kills++;
				$defenseurMort[$i] = $kills;
				$remainingDamage -= $kills * $hpPerMol;
				$remainingDamage = max(0.0, $remainingDamage); // guard against float underflow
			} else {
				$defenseurMort[$i] = $classeDefenseur[$i]['nombre'];
			}
		}
	}
} elseif ($defenderFormation == FORMATION_DISPERSEE) {
	// Dispersée: Equal split across active classes, overkill cascades within
	$activeDefClasses = 0;
	for ($i = 1; $i <= $nbClasses; $i++) {
		if ($classeDefenseur[$i]['nombre'] > 0) $activeDefClasses++;
	}
	$sharePerClass = ($activeDefClasses > 0) ? $degatsAttaquant / $activeDefClasses : 0;
	// Equal split: each active class receives its share; overkill cascades to remaining classes
	$disperseeOverkill = 0;
	for ($i = 1; $i <= $nbClasses; $i++) {
		$defenseurMort[$i] = 0;
		if ($classeDefenseur[$i]['nombre'] > 0 && $sharePerClass > 0) {
			// Bug 2 fix: recount live classes AHEAD of $i to get the correct denominator
			// for overkill redistribution — only classes that still have surviving molecules count.
			$liveClassesAhead = 0;
			for ($j = $i + 1; $j <= $nbClasses; $j++) {
				if (($classeDefenseur[$j]['nombre'] - ($defenseurMort[$j] ?? 0)) > 0) $liveClassesAhead++;
			}
			$classDamage = $sharePerClass;
			if ($disperseeOverkill > 0 && $liveClassesAhead > 0) {
				// Spread accumulated overkill across remaining classes including this one
				// (this class hasn't died yet, so count it as 1 + ahead)
				$spreadDenominator = 1 + $liveClassesAhead;
				$classDamage += $disperseeOverkill / $spreadDenominator;
				$disperseeOverkill -= $disperseeOverkill / $spreadDenominator; // consumed portion
			}
			$hpPerMol = pointsDeVieMolecule($classeDefenseur[$i]['brome'], $classeDefenseur[$i]['carbone'], $niveauxDef['brome'])
						* $bonusDuplicateurDefense * $defIsotopeHpMod[$i];
			if ($hpPerMol > 0) {
				$kills = min($classeDefenseur[$i]['nombre'], (int)floor($classDamage / $hpPerMol));
				$rem = fmod($classDamage, $hpPerMol);
				if ($rem > 0 && (random_int(0, 1000000) / 1000000.0) < $rem / $hpPerMol && $kills < $classeDefenseur[$i]['nombre']) $kills++;
				$defenseurMort[$i] = $kills;
				// Overkill: damage beyond what killed the last unit carries forward
				$damageUsed = $kills * $hpPerMol;
				if ($kills >= $classeDefenseur[$i]['nombre']) {
					$disperseeOverkill += max(0.0, $classDamage - $damageUsed);
				} else {
					$disperseeOverkill = 0;
				}
			} else {
				$defenseurMort[$i] = $classeDefenseur[$i]['nombre'];
				$disperseeOverkill += $classDamage;
			}
		}
	}
	// After the loop: apply remaining overkill to the last active class that still has HP
	if ($disperseeOverkill > 0) {
		for ($ci = $nbClasses; $ci >= 1; $ci--) {
			$remaining = ($classeDefenseur[$ci]['nombre'] ?? 0) - ($defenseurMort[$ci] ?? 0);
			if ($remaining > 0) {
				$hpPerMol = pointsDeVieMolecule($classeDefenseur[$ci]['brome'], $classeDefenseur[$ci]['carbone'], $niveauxDef['brome'])
							* $bonusDuplicateurDefense * $defIsotopeHpMod[$ci];
				if ($hpPerMol > 0) {
					$killsFromOverkill = min($remaining, (int)floor($disperseeOverkill / $hpPerMol));
					$rem = fmod($disperseeOverkill, $hpPerMol);
					if ($rem > 0 && $killsFromOverkill < $remaining && (random_int(0, 1000000) / 1000000.0) < ($rem / $hpPerMol)) {
						$killsFromOverkill++;
					}
					$defenseurMort[$ci] = ($defenseurMort[$ci] ?? 0) + $killsFromOverkill;
				}
				break;
			}
		}
	}
} else {
	// Embuscade/default: Straight cascade through all classes
	$remainingDamage = $degatsAttaquant;
	for ($i = 1; $i <= $nbClasses; $i++) {
		$defenseurMort[$i] = 0;
		if ($classeDefenseur[$i]['nombre'] > 0 && $remainingDamage > 0) {
			$hpPerMol = pointsDeVieMolecule($classeDefenseur[$i]['brome'], $classeDefenseur[$i]['carbone'], $niveauxDef['brome'])
						* $bonusDuplicateurDefense * $defIsotopeHpMod[$i];
			if ($hpPerMol > 0) {
				$kills = min($classeDefenseur[$i]['nombre'], (int)floor($remainingDamage / $hpPerMol));
				$rem = fmod($remainingDamage, $hpPerMol);
				if ($rem > 0 && (random_int(0, 1000000) / 1000000.0) < $rem / $hpPerMol && $kills < $classeDefenseur[$i]['nombre']) $kills++;
				$defenseurMort[$i] = $kills;
				$remainingDamage -= $kills * $hpPerMol;
				$remainingDamage = max(0.0, $remainingDamage); // guard against float underflow
			} else {
				$defenseurMort[$i] = $classeDefenseur[$i]['nombre'];
			}
		}
	}
}

for ($i = 1; $i <= $nbClasses; $i++) {
	$defenseursRestants += $classeDefenseur[$i]['nombre'] - $defenseurMort[$i];
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
		$totalAttackerPillage += $classeAttaquant[$c]['nombre'] * pillage($classeAttaquant[$c]['soufre'], $classeAttaquant[$c]['chlore'], $niveauxAtt['soufre'], $bonusPillageMedaille);
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
	$chaine = $chaine . ($classeAttaquant[$i]['nombre'] - $attaquantMort[$i]) . ';';
}

$actions['troupes'] = $chaine;
dbExecute($base, 'UPDATE actionsattaques SET troupes=? WHERE id=?', 'si', $chaine, $actions['id']);

// defenseur
for ($di = 1; $di <= $nbClasses; $di++) {
	dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di', ($classeDefenseur[$di]['nombre'] - $defenseurMort[$di]), $classeDefenseur[$di]['id']);
}

// Gestion du pillage
$ressourcesDefenseur = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=? FOR UPDATE', 's', $actions['defenseur']);
if (!$ressourcesDefenseur) {
	logError("Combat: missing defender resources for " . $actions['defenseur']);
	throw new Exception('Missing defender resources');
}

$ressourcesJoueur = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=? FOR UPDATE', 's', $actions['attaquant']);
if (!$ressourcesJoueur) {
	logError("Combat: missing attacker resources for " . $actions['attaquant']);
	throw new Exception('Missing attacker resources');
}

// Vault protection — use hoisted $constructionsDef (MED-024)
$vaultLevel   = isset($constructionsDef['coffrefort']) ? (int)$constructionsDef['coffrefort'] : 0;
$depotDefLevel = isset($constructionsDef['depot'])     ? (int)$constructionsDef['depot']      : 1;
$vaultProtection = capaciteCoffreFort($vaultLevel, $depotDefLevel);

if ($gagnant == 2) { // Si le joueur gagnant est l'attaquant
	$ressourcesTotalesDefenseur = 0;
	foreach ($nomsRes as $num => $ressource) {
		// Only count resources above vault protection as pillageable
		$ressourcesTotalesDefenseur += max(0, $ressourcesDefenseur[$ressource] - $vaultProtection);
	} // On calcule les ressources pillables du défenseur

	if ($ressourcesTotalesDefenseur != 0) { // Si elles sont différentes de zéro (pas de division par zéro)
		$ressourcesAPiller = 0;
		for ($pi = 1; $pi <= $nbClasses; $pi++) {
			$ressourcesAPiller += ($classeAttaquant[$pi]['nombre'] - $attaquantMort[$pi]) * pillage($classeAttaquant[$pi]['soufre'], $classeAttaquant[$pi]['chlore'], $niveauxAtt['soufre'], $bonusPillageMedaille);
		}

		// V4: Apply weekly catalyst pillage bonus (migrated from pillage() which is now pure)
		$catalystPillageBonus = 1 + catalystEffect('pillage_bonus');
		$ressourcesAPiller *= $catalystPillageBonus;

		// Compound synthesis pillage boost.
		// HIGH-024 (pillage): Use the snapshotted value stored at attack-launch time
		// (column added by migration 0053) rather than querying live compound state.
		// This prevents retroactive activation of H2SO4 after the attack is already in-flight.
		// Rows inserted before migration 0053 have DEFAULT 0.0, so legacy attacks are unaffected.
		$compoundPillageBonus = (float)($actions['compound_pillage_bonus'] ?? 0.0);
		if ($compoundPillageBonus > 0) $ressourcesAPiller *= (1 + $compoundPillageBonus);

		// Alliance Bouclier research reduces pillage losses for defender
		$bouclierReduction = allianceResearchBonus($actions['defenseur'], 'pillage_defense');
		if ($bouclierReduction > 0) {
			$ressourcesAPiller = round($ressourcesAPiller * (1 - $bouclierReduction));
		}

		// P1-D4-031: Pillage tax — reduces wealth concentration
		$ressourcesAPiller = round($ressourcesAPiller * (1 - PILLAGE_TAX_RATE));

		// Calcul du pourcentage de chaque ressource pillable (above vault protection)
		// LOW-015: Use explicit $ressourcePille array instead of variable-variables
		$ressourcePille = [];
		foreach ($nomsRes as $num => $ressource) {
			$pillageable = max(0, $ressourcesDefenseur[$ressource] - $vaultProtection);
			$rapport = $pillageable / $ressourcesTotalesDefenseur;
			if ($ressourcesTotalesDefenseur > $ressourcesAPiller) {
				$ressourcePille[$ressource] = floor($ressourcesAPiller * $rapport);
			} else {
				$ressourcePille[$ressource] = floor($pillageable);
			}
		}
	} else {
		$ressourcePille = [];
		foreach ($nomsRes as $num => $ressource) {
			$ressourcePille[$ressource] = 0;
		}
	}
} else {
	// LOW-011: No pillage on draw ($gagnant==0) or defender win ($gagnant==1).
	// Ensures draw condition asymmetry is eliminated — neither side pillages on a draw.
	$ressourcePille = [];
	foreach ($nomsRes as $num => $ressource) {
		$ressourcePille[$ressource] = 0;
	}
}

//Gestion de la destruction des bâtiments ennemis
// $hydrogeneTotal is recalculated from surviving attackers inside the damage block below;
// initialise to 0 here so the variable is defined for the guard condition.
$hydrogeneTotal = 0;
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

// MED-024: Use the hoisted $constructionsDef — no additional query needed
$constructions = $constructionsDef;

if ($gagnant == 2) { // Only damage buildings when attacker WINS
	// Calculate hydrogeneTotal from SURVIVING attackers
	$hydrogeneTotal = 0;
	for ($i = 1; $i <= $nbClasses; $i++) {
		$surviving = $classeAttaquant[$i]['nombre'] - $attaquantMort[$i];
		$hydrogeneTotal += $surviving * potentielDestruction($classeAttaquant[$i]['hydrogene'], $classeAttaquant[$i]['oxygene'], $niveauxAtt['hydrogene']);
	}

	// gestion des degats infligés
	// V4: Weighted building targeting — higher-level buildings attract more fire
	// LOW-011: Only include buildings at level >= 1 (level-0 buildings are invalid targets)
	$buildingTargets = array_filter([
		'generateur' => (int)$constructions['generateur'],
		'champdeforce' => (int)$constructions['champdeforce'],
		'producteur' => (int)$constructions['producteur'],
		'depot' => (int)$constructions['depot'],
		'ionisateur' => (int)$constructions['ionisateur'],
	], fn($v) => $v > 0);
	if (empty($buildingTargets)) $buildingTargets = ['generateur' => 1]; // fallback if all at level 0
	$totalWeight = array_sum($buildingTargets);

	// MED-023: Roll per unit (chunk size = 1) so damage is spread across buildings
	// rather than one bad roll concentrating an entire class's damage on a single building.
	for ($i = 1; $i <= $nbClasses; $i++) {
		$surviving = $classeAttaquant[$i]['nombre'] - $attaquantMort[$i];
		if ($classeAttaquant[$i]['hydrogene'] > 0 && $surviving > 0) {
			$degatsParUnite = potentielDestruction($classeAttaquant[$i]['hydrogene'], $classeAttaquant[$i]['oxygene'], $niveauxAtt['hydrogene']);
			for ($u = 0; $u < $surviving; $u++) {
				$roll = random_int(1, $totalWeight);
				$cumul = 0;
				foreach ($buildingTargets as $building => $weight) {
					$cumul += $weight;
					if ($roll <= $cumul) {
						switch ($building) {
							case 'generateur':  $degatsGenEnergie    += $degatsParUnite; break;
							case 'champdeforce': $degatschampdeforce  += $degatsParUnite; break;
							case 'producteur':  $degatsProducteur    += $degatsParUnite; break;
							case 'depot':       $degatsDepot         += $degatsParUnite; break;
							case 'ionisateur':  $degatsIonisateur    += $degatsParUnite; break;
						}
						break;
					}
				}
			}
		}
	}

	//gestion des destructions de batiments

	if ($degatsGenEnergie > 0) {
		// LOW-014: Show only damage status to attacker, not exact HP values (defender intel)
		if ($degatsGenEnergie >= $constructions['vieGenerateur']) {
			if ($constructions['generateur'] > 1) {
				diminuerBatiment("generateur", $actions['defenseur']);
				$destructionGenEnergie = "détruit";
			} else {
				$degatsGenEnergie = 0;
				$destructionGenEnergie = "Niveau minimum";
			}
		} else {
			dbExecute($base, 'UPDATE constructions SET vieGenerateur=? WHERE login=?', 'ds', ($constructions['vieGenerateur'] - $degatsGenEnergie), $actions['defenseur']);
			$destructionGenEnergie = "endommagé";
		}
	}
	if ($degatschampdeforce > 0) {
		// LOW-014: Show only damage status to attacker, not exact HP values (defender intel)
		if ($degatschampdeforce >= $constructions['vieChampdeforce']) {
			if ($constructions['champdeforce'] > 1) {
				diminuerBatiment("champdeforce", $actions['defenseur']);
				$destructionchampdeforce = "détruit";
			} else {
				$degatschampdeforce = 0;
				$destructionchampdeforce = "Niveau minimum";
			}
		} else {
			dbExecute($base, 'UPDATE constructions SET vieChampdeforce=? WHERE login=?', 'ds', ($constructions['vieChampdeforce'] - $degatschampdeforce), $actions['defenseur']);
			$destructionchampdeforce = "endommagé";
		}
	}
	if ($degatsProducteur > 0) {
		// LOW-014: Show only damage status to attacker, not exact HP values (defender intel)
		if ($degatsProducteur >= $constructions['vieProducteur']) {
			if ($constructions['producteur'] > 1) {
				diminuerBatiment("producteur", $actions['defenseur']);
				$destructionProducteur = "détruit";
			} else {
				$degatsProducteur = 0;
				$destructionProducteur = "Niveau minimum";
			}
		} else {
			dbExecute($base, 'UPDATE constructions SET vieProducteur=? WHERE login=?', 'ds', ($constructions['vieProducteur'] - $degatsProducteur), $actions['defenseur']);
			$destructionProducteur = "endommagé";
		}
	}
	if ($degatsDepot > 0) {
		// LOW-014: Show only damage status to attacker, not exact HP values (defender intel)
		if ($degatsDepot >= $constructions['vieDepot']) {
			if ($constructions['depot'] > 1) {
				diminuerBatiment("depot", $actions['defenseur']);
				$destructionDepot = "détruit";
			} else {
				$degatsDepot = 0;
				$destructionDepot = "Niveau minimum";
			}
		} else {
			dbExecute($base, 'UPDATE constructions SET vieDepot=? WHERE login=?', 'ds', ($constructions['vieDepot'] - $degatsDepot), $actions['defenseur']);
			$destructionDepot = "endommagé";
		}
	}
	if ($degatsIonisateur > 0) {
		// LOW-014: Show only damage status to attacker, not exact HP values (defender intel)
		if ($degatsIonisateur >= $constructions['vieIonisateur']) {
			if ($constructions['ionisateur'] > 1) {
				diminuerBatiment("ionisateur", $actions['defenseur']);
				$destructionIonisateur = "détruit";
			} else {
				$degatsIonisateur = 0;
				$destructionIonisateur = "Niveau minimum";
			}
		} else {
			dbExecute($base, 'UPDATE constructions SET vieIonisateur=? WHERE login=?', 'ds', ($constructions['vieIonisateur'] - $degatsIonisateur), $actions['defenseur']);
			$destructionIonisateur = "endommagé";
		}
	}
}

// calcul des stats de combat

$pertesAttaquant = 0;
$pertesDefenseur = 0;
for ($pi = 1; $pi <= $nbClasses; $pi++) {
	$pertesAttaquant += $attaquantMort[$pi];
	$pertesDefenseur += $defenseurMort[$pi];
}

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
		$attAtoms += $classeAttaquant[$i][$ressource];
		$defAtoms += $classeDefenseur[$i][$ressource];
	}
	$massDestroyedAttacker += $attaquantMort[$i] * $attAtoms;
	$massDestroyedDefender += $defenseurMort[$i] * $defAtoms;
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
        $jeuData = dbFetchOne($base, 'SELECT debut FROM statistiques LIMIT 1', '');
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

$totalPille = array_sum($ressourcePille);

// update des stats de combat

ajouterPoints($pointsAttaquant, $actions['attaquant'], 1);
ajouterPoints($totalPille, $actions['attaquant'], 3);
ajouterPoints($pointsDefenseur, $actions['defenseur'], 2);
// FIX FINDING-GAME-011: Do NOT subtract pillage from defender's ressourcesPillees stat.
// That stat tracks how much a player has pillaged (offensive), not how much was stolen FROM them.
// Removing: ajouterPoints(-$totalPille, $actions['defenseur'], 3);

// COMB-002: Atomic increment to avoid read-modify-write race on moleculesPerdues.
// The previous SELECT+absolute-write pattern was a race condition; inside the transaction,
// an atomic += is both safer and eliminates two unnecessary SELECT round-trips.
if ($pertesAttaquant > 0) {
    dbExecute($base, 'UPDATE autre SET moleculesPerdues = moleculesPerdues + ? WHERE login=?', 'ds', $pertesAttaquant, $actions['attaquant']);
}
if ($pertesDefenseur > 0) {
    dbExecute($base, 'UPDATE autre SET moleculesPerdues = moleculesPerdues + ? WHERE login=?', 'ds', $pertesDefenseur, $actions['defenseur']);
}




// On met à jour les ressources
// Build the SET clause and parameters dynamically for attacker
// FIX FINDING-GAME-008: Cap pillaged resources at attacker's storage limit
// MED-024: Use hoisted $constructionsAtt instead of re-querying
$maxStorageAtt = placeDepot(isset($constructionsAtt['depot']) ? (int)$constructionsAtt['depot'] : 1);
$setClauses = [];
$setTypes = '';
$setParams = [];
foreach ($nomsRes as $num => $ressource) {
	if (!in_array($ressource, $nomsRes, true)) {
		throw new \RuntimeException("Invalid column: $ressource");
	}
	$setClauses[] = "$ressource=?";
	$setTypes .= 'd';
	$setParams[] = min($maxStorageAtt, ($ressourcesJoueur[$ressource] + ($ressourcePille[$ressource] ?? 0)));
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
	$setParams[] = max(0, ($ressourcesDefenseur[$ressource] - ($ressourcePille[$ressource] ?? 0))); // FIX FINDING-GAME-026: clamp at 0
}
// Add defense reward energy to defender
if ($defenseRewardEnergy > 0) {
	$setClauses[] = "energie=?";
	$setTypes .= 'd';
	// MED-024: Use hoisted $constructionsDef instead of re-querying
	$maxEnergy = placeDepot(isset($constructionsDef['depot']) ? (int)$constructionsDef['depot'] : 1);
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
	// NEW-001: Atomic increments prevent concurrent battle results overwriting each other
	if ($guerre['alliance1'] == $joueurAlliance) {
		dbExecute($base, 'UPDATE declarations SET pertes1 = pertes1 + ?, pertes2 = pertes2 + ? WHERE id=?', 'ddi', $pertesAttaquant, $pertesDefenseur, $guerre['id']);
	} else {
		dbExecute($base, 'UPDATE declarations SET pertes1 = pertes1 + ?, pertes2 = pertes2 + ? WHERE id=?', 'ddi', $pertesDefenseur, $pertesAttaquant, $guerre['id']);
	}
}
