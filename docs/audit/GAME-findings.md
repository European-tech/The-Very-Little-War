# TVLW Game Logic Audit - Comprehensive Findings

**Date:** 2026-03-02
**Auditor:** claude-opus-4-6 Code Review Agent
**Scope:** All gameplay systems - combat, resources, market, buildings, alliances, molecules, prestige, rankings, season reset, medals

**Status:** FIXES IMPLEMENTED 2026-03-02

---

## Summary

| Severity | Count | Fixed |
|----------|-------|-------|
| CRITICAL | 5     | 5     |
| HIGH     | 11    | 7 (4 retracted/by-design) |
| MEDIUM   | 14    | 7 (5 retracted/deferred) |
| LOW      | 8     | 3 (3 retracted/deferred) |
| **Total** | **38** | **22 fixed** |

### Fixes Applied:
- FINDING-GAME-001: Prestige medal calculation -- replaced broken medailles table query with dynamic threshold calculation
- FINDING-GAME-002: Prestige unlocks -- wired prestigeProductionBonus() and prestigeCombatBonus() into game_resources.php and combat.php
- FINDING-GAME-003: Defense isotope modifier -- removed $defIsotopeAttackMod from defense calculation
- FINDING-GAME-004: Building destruction -- now only on attacker WIN, using surviving troop count
- FINDING-GAME-005: Market sell tax -- added 5% sell fee to prevent buy-sell arbitrage
- FINDING-GAME-006: Dispersee formation -- damage now only splits among classes with molecules
- FINDING-GAME-007: Attack cooldown -- now applies on draws AND losses, not just losses
- FINDING-GAME-008: Pillage storage cap -- attacker resources capped at depot limit after pillage
- FINDING-GAME-009: Delivery storage cap -- received resources capped at receiver's depot limit
- FINDING-GAME-010: Negative points -- pointsAttaque/pointsDefense clamped at 0 minimum
- FINDING-GAME-011: Defender pillage stat -- removed incorrect subtraction from defender's ressourcesPillees
- FINDING-GAME-013: Alliance research cap -- added ALLIANCE_RESEARCH_MAX_LEVEL = 25
- FINDING-GAME-018: Embuscade formation -- now boosts defender's effective damage instead of defense stat
- FINDING-GAME-020: demiVie division by zero -- returns PHP_INT_MAX when decay coefficient >= 1.0
- FINDING-GAME-022: Class validation -- changed <= 5 to <= MAX_MOLECULE_CLASSES (4)
- FINDING-GAME-024: coutClasse -- removed unnecessary global $base
- FINDING-GAME-025: Producteur points minimum -- changed from 1 to 0
- FINDING-GAME-026: Defender resources -- clamped at 0 after pillage
- FINDING-GAME-029: Market send revenuAtome -- fixed wrong first argument (was level, now atom index)
- FINDING-GAME-033: Report apostrophe -- fixed escaped apostrophe in combat report
- FINDING-GAME-034: Catalyst caching -- added static cache to getActiveCatalyst()

---

## CRITICAL Findings

### FINDING-GAME-001: [CRITICAL] - Prestige medal calculation references non-existent `medailles` table

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/prestige.php:58`

**Description:** The `calculatePrestigePoints()` function queries `SELECT * FROM medailles WHERE login=?` and expects columns like `terreur`, `explorateur`, `commercial`, `alchimiste`, `demolisseur`, `energetique`. However, this game does not have a `medailles` table -- medals are calculated dynamically from raw stats in the `autre` table (nbattaques, pointsAttaque, pointsDefense, etc.) and displayed in `medailles.php` using threshold arrays. The query will silently fail (return null), meaning **no medal-based prestige points are ever awarded**, making the prestige system significantly weaker than designed.

**Expected behavior:** Medal tiers contribute PP based on how many tiers the player reached across all medal categories.

**Actual behavior:** The `dbFetchOne` returns null/false, `$medailles` is falsy, the foreach block is skipped entirely, and players get 0 PP from medals.

**Remediation:**
```php
// Replace lines 57-67 in prestige.php with dynamic medal tier calculation:
function calculatePrestigePoints($login) {
    global $base, $paliersTerreur, $paliersAttaque, $paliersDefense,
           $paliersPillage, $paliersPertes, $paliersEnergievore,
           $paliersConstructeur, $paliersPipelette;

    $pp = 0;

    // Active during final week
    $lastActive = dbFetchOne($base, 'SELECT timestamp FROM membre WHERE login=?', 's', $login);
    if ($lastActive && (time() - $lastActive['timestamp']) < SECONDS_PER_WEEK) {
        $pp += 5;
    }

    // Medal tiers: count how many tiers reached across all categories
    $autre = dbFetchOne($base, 'SELECT * FROM autre WHERE login=?', 's', $login);
    if ($autre) {
        $medalChecks = [
            [$autre['nbattaques'], $paliersTerreur],
            [$autre['pointsAttaque'], $paliersAttaque],
            [$autre['pointsDefense'], $paliersDefense],
            [$autre['ressourcesPillees'], $paliersPillage],
            [$autre['moleculesPerdues'], $paliersPertes],
            [$autre['energieDepensee'], $paliersEnergievore],
        ];
        foreach ($medalChecks as [$value, $thresholds]) {
            $tier = 0;
            foreach ($thresholds as $t) {
                if ($value >= $t) $tier++;
            }
            $pp += $tier; // 1 PP per tier
        }

        // Activity-based PP
        if ($autre['nbattaques'] >= 10) $pp += 5;
        if ($autre['tradeVolume'] >= 20) $pp += 3;
        if ($autre['energieDonnee'] > 0) $pp += 2;
    }

    return $pp;
}
```

---

### FINDING-GAME-002: [CRITICAL] - Prestige unlock `experimente` (+5% production) and `maitre_chimiste` (+5% combat) are never applied

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/prestige.php:172-187` (defined), but NOT called in `/home/guortates/TVLW/The-Very-Little-War/includes/game_resources.php` or `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php`

**Description:** The functions `prestigeProductionBonus()` and `prestigeCombatBonus()` exist in prestige.php and return 1.05 if the player has the unlock, but they are **never called** anywhere in the actual resource production or combat calculations. Players who spend 100 or 500 PP on these unlocks receive no actual gameplay benefit.

**Expected behavior:** Players with `experimente` unlock get +5% resource production. Players with `maitre_chimiste` get +5% combat stats.

**Actual behavior:** The unlocks are purchased and stored in the database but have zero effect on gameplay.

**Remediation:**

In `game_resources.php` `revenuEnergie()`, near the final return:
```php
$prodProducteur *= prestigeProductionBonus($joueur);
```

In `game_resources.php` `revenuAtome()`:
```php
return round($bonusDuplicateur * BASE_ATOMS_PER_POINT * $niveau * prestigeProductionBonus($joueur));
```

In `combat.php`, multiply attack and defense totals:
```php
$degatsAttaquant *= prestigeCombatBonus($actions['attaquant']);
$degatsDefenseur *= prestigeCombatBonus($actions['defenseur']);
```

---

### FINDING-GAME-003: [CRITICAL] - Defender's defense stat uses `$defIsotopeAttackMod` instead of defense-specific modifier

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php:204`

**Description:** On line 204, the defender's defense bonus calculation uses `$defIsotopeAttackMod[$c]`:
```php
$defBonusForClass = $defReactionDefenseBonus * $formationDefenseBonus * $defIsotopeAttackMod[$c];
```
The variable `$defIsotopeAttackMod` was computed using attack modifiers (ISOTOPE_STABLE_ATTACK_MOD = -0.10, ISOTOPE_REACTIF_ATTACK_MOD = +0.20). This means a Stable isotope defender gets their defense REDUCED by 10% (the attack penalty) instead of getting a defense boost. The isotope system defines separate attack and HP modifiers, but defense calculation incorrectly uses the attack modifier array.

Since the isotope system only has attack and HP modifiers (no explicit defense modifier), and defense is a distinct stat from attack, the intent is likely that isotope modifiers should apply the attack modifier to attack damage only, and HP modifier to HP. Defense itself should NOT be modified by isotope attack modifiers.

**Expected behavior:** Defense calculation should not be penalized by isotope attack modifiers. Defense stat should remain unmodified by isotope (or use a dedicated defense modifier if one existed).

**Actual behavior:** Stable isotope defenders lose 10% defense (wrong direction). Reactif isotope defenders gain 20% defense (also wrong -- they should be glass cannons).

**Remediation:**
```php
// Line 204: remove the isotope attack modifier from defense calculation
$defBonusForClass = $defReactionDefenseBonus * $formationDefenseBonus;
```

---

### FINDING-GAME-004: [CRITICAL] - Building destruction happens even when the attacker LOSES the combat

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php:410`

**Description:** The building destruction section (lines 410-497) checks `if ($hydrogeneTotal > 0)` but does NOT check whether the attacker won (`$gagnant == 2`). The `$hydrogeneTotal` is calculated from surviving attacker molecules (nombre - mort), which would be 0 if the attacker loses completely (`$attaquantsRestants == 0`). However, in a DRAW scenario (`$gagnant == 0`, both sides have survivors), the attacker's surviving molecules STILL deal building damage even though the battle was a draw. This is a design inconsistency -- in a draw, the attacker should not be able to damage buildings.

Even worse: the damage calculation on line 416 uses `${'classeAttaquant'.$i}['nombre']` (the ORIGINAL number sent) instead of the surviving count. Let me re-examine...

Actually, looking more carefully at line 416:
```php
$degatsAMettre = potentielDestruction(${'classeAttaquant'.$i}['hydrogene'], $niveauxAtt['hydrogene']) * ${'classeAttaquant'.$i}['nombre'];
```
The `['nombre']` here is the original troop count sent to combat (from the `troupes` string), NOT the surviving count. The surviving count would be `${'classeAttaquant'.$i}['nombre'] - ${'classe'.$i.'AttaquantMort'}`. This means the FULL pre-combat army's hydrogen potential is used for building destruction, regardless of how many died. Even if 99% of the attacking army is wiped out, the building damage is calculated from the full army.

**Expected behavior:** Building damage should only be calculated from surviving attacker molecules, and only if the attacker wins.

**Actual behavior:** Full pre-combat army hydrogen potential is applied to buildings even in draws.

**Remediation:**
```php
// Wrap the building destruction block at line 410:
if ($gagnant == 2 && $hydrogeneTotal > 0) {
    // Recalculate hydrogeneTotal from SURVIVING attackers only
    $hydrogeneTotal = 0;
    for ($i = 1; $i <= $nbClasses; $i++) {
        $surviving = ${'classeAttaquant'.$i}['nombre'] - ${'classe'.$i.'AttaquantMort'};
        $hydrogeneTotal += $surviving * potentielDestruction(${'classeAttaquant'.$i}['hydrogene'], $niveauxAtt['hydrogene']);
    }
    // ... rest of building damage logic, also replacing ${'classeAttaquant'.$i}['nombre']
    // with (${'classeAttaquant'.$i}['nombre'] - ${'classe'.$i.'AttaquantMort'}) in the inner loops
```

---

### FINDING-GAME-005: [CRITICAL] - Market sell does NOT award trade points, creating a buy-sell arbitrage exploit

**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php:229-295` (sell block) vs lines 145-227 (buy block)

**Description:** The buy block (lines 196-212) awards trade points via `tradeVolume` and updates `totalPoints`. The sell block (lines 229-295) does NOT update `tradeVolume` or `totalPoints` at all. The comment at line 199 says "Award trade points based on energy spent (not atom volume, to prevent buy-sell exploits)" -- but selling does not cost energy, it earns it. However, the asymmetry still creates a problem: a player can repeatedly buy resources (gaining trade points and totalPoints) and then sell them back (recovering most of their energy since mean-reversion keeps prices near 1.0). The net cost is minimal due to mean-reversion, but the trade points keep accumulating.

With `MARKET_POINTS_MAX = 80` as a cap, this is bounded but still exploitable: a player can reach 80 totalPoints purely from market cycling without any actual gameplay.

**Expected behavior:** Trade points should be harder to farm or the market should have transaction fees.

**Actual behavior:** Players can cycle buy/sell to farm up to 80 totalPoints with near-zero net resource cost.

**Remediation:**
```php
// Add a transaction fee to selling (e.g., 5% loss)
$energyGained = round($tabCours[$numRes] * $_POST['nombreRessourceAVendre'] * 0.95);
$newEnergie = $ressources['energie'] + $energyGained;
```
Or add a cooldown between market transactions, or make mean-reversion only happen on buys (not sells).

---

## HIGH Findings

### FINDING-GAME-006: [HIGH] - Dispersee formation splits damage equally even when some classes have 0 molecules

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php:255-259`

**Description:** The Dispersee formation splits attacker damage as 25% to each of the 4 classes. If a defender has 0 molecules in classes 3 and 4, 50% of the attacker's damage is wasted (dealt to empty slots). This is a significant game balance issue that makes Dispersee always the optimal formation since half the attacker's damage is automatically nullified against 2-class armies.

**Expected behavior:** Dispersee should split damage evenly among classes that HAVE molecules.

**Actual behavior:** 25% per class regardless of whether molecules exist there. Empty classes absorb damage that is simply lost.

**Remediation:**
```php
if ($defenderFormation == FORMATION_DISPERSEE) {
    $activeClasses = 0;
    for ($i = 1; $i <= $nbClasses; $i++) {
        if (${'classeDefenseur'.$i}['nombre'] > 0) $activeClasses++;
    }
    $sharePerClass = ($activeClasses > 0) ? 1.0 / $activeClasses : 0.25;
    for ($i = 1; $i <= $nbClasses; $i++) {
        if (${'classeDefenseur'.$i}['nombre'] > 0) {
            $defDamageShares[$i] = $degatsAttaquant * $sharePerClass;
        } else {
            $defDamageShares[$i] = 0;
        }
    }
}
```

---

### FINDING-GAME-007: [HIGH] - Attack cooldown only set when DEFENDER wins, not on draws

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php:309-321`

**Description:** The attack cooldown (`ATTACK_COOLDOWN_SECONDS = 4 hours`) is only inserted when `$gagnant == 1` (defender wins). When the result is a draw (`$gagnant == 0`), no cooldown is applied. This means an attacker who draws can immediately send another attack to the same target, allowing rapid harassment even when the attacker isn't winning.

**Expected behavior:** Cooldown should apply when the attacker fails to win (both draws and losses).

**Actual behavior:** No cooldown on draws, allowing unlimited attack spam.

**Remediation:**
```php
// Change line 309 condition:
if ($gagnant == 1 || $gagnant == 0) { // Cooldown on loss AND draw
```

---

### FINDING-GAME-008: [HIGH] - Attacker pillage ignores storage cap -- stolen resources can exceed depot limit

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php:547-558`

**Description:** When pillaged resources are added to the attacker, the update is:
```php
$setParams[] = ($ressourcesJoueur[$ressource] + ${$ressource . 'Pille'});
```
There is no check against `placeDepot()`. The attacker can receive more of each resource than their storage allows. This bypasses the fundamental storage mechanic.

**Expected behavior:** Pillaged resources should be capped at the attacker's storage limit per resource.

**Actual behavior:** Attacker resources can exceed their depot maximum after pillage.

**Remediation:**
```php
$depotAtt = dbFetchOne($base, 'SELECT depot FROM constructions WHERE login=?', 's', $actions['attaquant']);
$maxStorage = placeDepot($depotAtt['depot']);
foreach ($nomsRes as $num => $ressource) {
    $setClauses[] = "$ressource=?";
    $setTypes .= 'd';
    $setParams[] = min($maxStorage, ($ressourcesJoueur[$ressource] + ${$ressource . 'Pille'}));
}
```

---

### FINDING-GAME-009: [HIGH] - Resource delivery (market trades) bypasses storage limits

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php:500-523`

**Description:** When a resource shipment arrives (actionsenvoi), resources are added to the receiver without checking storage limits:
```php
$envoiParams[] = round($ressourcesDestinataire[$ressource] + $recues[$num]);
```
A player can send themselves (via an alt) or receive large resource shipments that push them above their depot capacity.

**Expected behavior:** Received resources should be capped at storage limit.

**Actual behavior:** Resources can exceed depot maximum via trades.

**Remediation:**
```php
$depotReceveur = dbFetchOne($base, 'SELECT depot FROM constructions WHERE login=?', 's', $actions['receveur']);
$maxStorageRecv = placeDepot($depotReceveur['depot']);
foreach ($nomsRes as $num => $ressource) {
    $envoiSetClauses[] = "$ressource=?";
    $envoiTypes .= 'd';
    $envoiParams[] = min($maxStorageRecv, round($ressourcesDestinataire[$ressource] + $recues[$num]));
}
```

---

### FINDING-GAME-010: [HIGH] - Negative pointsAttaque or pointsDefense causes sqrt of negative number in rankings

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php:83-91`

**Description:** In `ajouterPoints()` type 1 (attack) and type 2 (defense), the raw points can go negative after a lost battle. The `pointsAttaque()` and `pointsDefense()` functions call `sqrt(abs($pts))` which handles negative input, BUT the `$points['pointsAttaque'] + $nb` can become negative itself. When this negative raw value is stored and later used in `totalPoints` calculation, the delta `pointsAttaque(negative) - pointsAttaque(old)` creates incorrect totalPoints changes.

Specifically, `pointsAttaque($pts)` returns 0 when `$pts <= 0`, but `$points['pointsAttaque'] + $nb` could be stored as a negative number in the database. This means `totalPoints` will subtract the old contribution correctly but add 0 for the new one, which is fine. However, the raw `pointsAttaque` field being negative is unexpected and could cause display bugs.

**Expected behavior:** Raw attack/defense points should be clamped at 0 minimum.

**Actual behavior:** pointsAttaque and pointsDefense fields in `autre` table can go negative.

**Remediation:**
```php
if ($type == 1) {
    $newPoints = max(0, $points['pointsAttaque'] + $nb);
    dbExecute($base, 'UPDATE autre SET pointsAttaque=?, totalPoints=? WHERE login=?', 'dds',
        $newPoints,
        ($points['totalPoints'] - pointsAttaque($points['pointsAttaque']) + pointsAttaque($newPoints)),
        $joueur);
}
```
Apply same fix for type 2 (defense).

---

### FINDING-GAME-011: [HIGH] - ressourcesPillees can go negative (defender gets negative pillage contribution)

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php:537`

**Description:** Line 537 calls `ajouterPoints(-$totalPille, $actions['defenseur'], 3)` which subtracts from the defender's `ressourcesPillees`. Since this value is also used for medal calculation and the `pointsPillage()` formula, it can go negative, causing `pointsPillage(negative)` which uses `tanh(negative / DIVISOR)` -- this produces a negative value, potentially making the defender's totalPoints decrease.

**Expected behavior:** `ressourcesPillees` should never go negative; it represents a cumulative stat.

**Actual behavior:** Each time a player is pillaged, their `ressourcesPillees` stat is DECREASED, which makes no logical sense (this stat tracks how much YOU pillaged, not how much was stolen from you).

Actually, reviewing more carefully: `ajouterPoints` type 3 adds to `ressourcesPillees` for the attacker (+$totalPille) and subtracts for the defender (-$totalPille). The field name `ressourcesPillees` means "resources pillaged" -- for the defender, subtracting from their pillage stat makes no sense. This appears to be a design error where the defender's pillage stat is incorrectly penalized when they get raided.

**Remediation:**
```php
// Line 537: Do NOT subtract pillage points from the defender
// The defender losing resources is already handled by the resource update
// Remove line 537 entirely:
// ajouterPoints(-$totalPille, $actions['defenseur'], 3);  // DELETE THIS LINE
```

---

### FINDING-GAME-012: [HIGH] - Sending resources: exchange rate allows pumping resources to alt accounts

**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php:62-93`

**Description:** The exchange rate when sending resources depends on the ratio of the sender's and receiver's revenue. If the sender has HIGH revenue and the receiver has LOW revenue, `$rapportEnergie = revenuEnergie(receiver) / revenuEnergie(sender)`, which is less than 1.0, meaning the receiver gets LESS than what was sent. But if the sender has LOW revenue and receiver has HIGH revenue, `$rapportEnergie = 1` (capped at 1.0, line 65).

However, there's no cap the other direction. A high-level player can send resources to a low-level alt at a 1:1 ratio (sender revenue < receiver revenue), the alt accumulates resources, levels up their buildings (increasing their revenue), and then the reverse direction also becomes 1:1. The IP check on line 28 is the only protection, and it can be easily bypassed with VPN/different networks.

**Expected behavior:** Some form of anti-abuse beyond IP check.

**Actual behavior:** IP-based anti-abuse is easily circumvented, and the exchange rate system is asymmetric in a way that allows resource pumping.

**Remediation:**
- Add a flat 10-20% tax on all resource transfers (reduce received amount)
- Add a daily transfer limit per player
- Track total resources sent per day and flag anomalies

---

### FINDING-GAME-013: [HIGH] - Alliance research has no level cap -- infinite scaling

**File:** `/home/guortates/TVLW/The-Very-Little-War/alliance.php:94-110`

**Description:** Alliance research techs (catalyseur, fortification, reseau, radar, bouclier) have no maximum level check. The cost scales exponentially (`cost_base * pow(cost_factor, level + 1)`), but a well-funded alliance can keep upgrading indefinitely. At high levels, effects become overpowered:
- Catalyseur at level 50: 100% formation speed reduction (instant formation)
- Radar at level 50: 100% espionage cost reduction (free spying)
- Bouclier at level 50: 50% pillage defense reduction

**Expected behavior:** Alliance research should have a level cap (e.g., 20-30).

**Actual behavior:** No level cap; effects can exceed 100% at extreme levels.

**Remediation:**
```php
// Add max level check in alliance.php research upgrade handler:
define('ALLIANCE_RESEARCH_MAX_LEVEL', 25);
if ($currentLevel >= ALLIANCE_RESEARCH_MAX_LEVEL) {
    $erreur = "Niveau maximum atteint.";
} elseif ($allianceData['energieAlliance'] >= $researchCost) {
    // ... proceed with upgrade
}
```

---

### FINDING-GAME-014: [HIGH] - Duplicate defense reward energy can overwrite previously-set resource values

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php:569-576`

**Description:** The defense reward energy block adds an additional `energie=?` clause to the defender resource update. But the foreach loop on lines 564-567 already adds `energie` (since `energie` is NOT in `$nomsRes` -- wait, let me check). Actually, `$nomsRes` contains only the 8 atom types, not `energie`. So the loop handles atoms correctly, and line 571 adds energy separately. This could result in `energie=?` appearing twice in the SET clause if it already exists from the loop. But since the loop only iterates over `$nomsRes` (atoms), not `energie`, this should be fine.

Actually, looking more carefully: the loop on line 564 iterates `$nomsRes` and subtracts pillaged resources from the defender. Energy is handled separately. BUT there's a subtle bug: the `$ressourcesDefenseur['energie']` is read once at line 341, and the loop on line 567 sets the new resource values. Then lines 570-575 add a SECOND `energie=?` clause. The final SQL will have `carbone=?, azote=?, ..., iode=?, energie=?, login=?` -- the `energie` clause works correctly. But wait -- energy was ALREADY potentially modified by pillage (if energy was in the atom list). Let me verify: energy is NOT in `$nomsRes`, so the loop does not touch energy. The energy subtraction from pillage is done via `${$ressource.'Pille'}` -- but `energie` is not in the `$nomsRes` list, so energy is NEVER pillaged directly by the loop.

Actually this is fine -- energy is not in `$nomsRes` and is handled separately. I'm downgrading this to informational. No actual bug.

**RETRACTED -- Not a bug.**

---

### FINDING-GAME-015: [HIGH] - Season reset does not clear `victoires` (cumulative victory points) -- they accumulate infinitely

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php:732`

**Description:** In `remiseAZero()`, the `autre` table update resets many fields but notably does NOT reset `victoires`:
```php
dbExecute($base, 'UPDATE autre SET points=0, niveaututo=1, nbattaques=0, neutrinos=default,moleculesPerdues=0, energieDepensee=0, energieDonnee=0, bombe=0, batMax=1, totalPoints=0, pointsAttaque=0, pointsDefense=0, ressourcesPillees=0, tradeVolume=0, missions=\'\'');
```
The `victoires` field is intentionally not reset because it's supposed to be a cumulative cross-season stat. However, just before the reset (lines 169-186 of basicprivatephp.php), victory points are ADDED to `victoires`. This means `victoires` keeps growing season after season, giving veteran players a permanently higher score in the `victoires` ranking column that new players can never catch up to.

This is by design (victory points are cumulative), but the ranking display in `classement.php` line 193 shows the current `victoires` value plus the projected gain (`+pointsVictoireJoueur($compteur)`), which correctly represents the current season's potential gain. So this is intentional but worth noting as a game design consideration -- it permanently advantages veteran players.

**Expected behavior:** Intentional cumulative stat.

**Actual behavior:** Working as designed, but creates a permanent advantage for veteran players.

**Remediation (optional):** Consider displaying "this season's victory points" separately from "all-time victory points" to avoid confusion.

---

### FINDING-GAME-016: [HIGH] - `totalPoints` reset to 0 but `victoires` not reset creates inconsistency in rankings

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php:732` and `/home/guortates/TVLW/The-Very-Little-War/classement.php:186-189`

**Description:** After reset, `totalPoints` is 0 but `victoires` retains its accumulated value. The ranking page sorts by `totalPoints` (default) but also shows `victoires` column. Since `totalPoints` is the sum of construction + attack + defense + pillage + trade points, and it starts at 0, the early-season ranking is meaningless. But a player who ranked #1 last season with 1000 victory points will still show those in the victoires column, which is the intended cross-season metric.

However, the `pointsVictoireJoueur($compteur)` displayed on line 193 shows the projected VP gain based on the CURRENT ranking position. Early in a season when everyone has 0 totalPoints, ranking is essentially random, and the displayed "+100" for rank 1 is misleading.

**Actual behavior:** Early-season rankings show misleading projected VP gains.

**Remediation:** Only display projected VP gains after a minimum threshold of game activity (e.g., after the first week).

---

## MEDIUM Findings

### FINDING-GAME-017: [MEDIUM] - Halogénation reaction bonus `speed` is never applied in combat

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php:169-179` and `/home/guortates/TVLW/The-Very-Little-War/includes/config.php:331-333`

**Description:** The Halogenation chemical reaction grants `'speed' => 0.20` (+20% fleet speed). However, in combat.php, the reaction bonus processing loops (lines 169-179) only check for `attack`, `hp`, `pillage`, and `defense` bonuses. The `speed` bonus is never extracted or applied. Fleet speed is calculated at attack launch time in `attaquer.php`, not during combat resolution, and the reaction system is only evaluated during combat.

**Expected behavior:** Speed bonus from Halogenation should be applied somewhere meaningful (perhaps during attack travel time calculation or as a dodge mechanic).

**Actual behavior:** The speed bonus is defined but never used, making Halogenation an inert reaction.

**Remediation:** Either:
1. Apply speed bonus during attack travel time by checking reactions at launch time
2. Change the Halogenation bonus to something that IS evaluated in combat (e.g., `'hp' => 0.15`)
3. Add a dodge/evasion mechanic in combat that uses speed

---

### FINDING-GAME-018: [MEDIUM] - Embuscade formation applies defense bonus to attacker damage, not defender attack

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php:186-196`

**Description:** The Embuscade (Ambush) formation is described as giving "+25% attack bonus" when the defender has more molecules. However, the variable `$formationDefenseBonus` is set to `1.0 + FORMATION_AMBUSH_ATTACK_BONUS`, and on line 204, this multiplies the defender's DEFENSE total:
```php
$defBonusForClass = $defReactionDefenseBonus * $formationDefenseBonus * ...
```
Defense and attack are different stats. The description says "+25% attack" but it's applied as "+25% defense". While this still helps the defender survive better (higher defense means the attacker deals less relative damage), it doesn't match the documented behavior. The defender's defense is boosted, not their "counter-attack" power.

**Expected behavior:** Embuscade should boost defender's effective damage output (offense), not their defense.

**Actual behavior:** Embuscade boosts defense instead of attack, which doesn't match its description or the constant name `FORMATION_AMBUSH_ATTACK_BONUS`.

**Remediation:** To make Embuscade affect the defender's offensive output, it should reduce the attacker's effective HP or increase `$degatsDefenseur`:
```php
if ($defenderFormation == FORMATION_EMBUSCADE && $totalDefenderMols > $totalAttackerMols) {
    $degatsDefenseur *= (1.0 + FORMATION_AMBUSH_ATTACK_BONUS);
}
```
And remove `$formationDefenseBonus` from the defense calculation.

---

### FINDING-GAME-019: [MEDIUM] - Molecule decay during attack transit uses class INDEX, not class NUMBER

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php:90`

**Description:** During attack transit decay calculation:
```php
$moleculesRestantes = (pow(coefDisparition($actions['attaquant'], $compteur), $nbsecondes) * $molecules[$compteur - 1]);
```
The `$compteur` variable starts at 1 and increments, but `coefDisparition()` with type=0 (default) does:
```php
$donnees = dbFetchOne($base, 'SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $joueur, $classeOuNbTotal);
```
This uses `$compteur` as `numeroclasse`, which is correct IF the molecule classes are 1-4 in order. However, the `$molecules` array comes from `explode(";", $actions['troupes'])` which is 0-indexed. `$molecules[$compteur - 1]` correctly maps index to troop count. The `coefDisparition` call correctly uses `$compteur` (1-4) as class number. So the logic is actually correct.

Wait -- looking again, the issue is that `coefDisparition($actions['attaquant'], $compteur)` queries the molecule data to calculate decay based on the molecule's total atoms. But the troop counts in `$actions['troupes']` might be DIFFERENT from the current molecule composition (if the player changed their molecule between sending the attack and it arriving). The decay is calculated using the molecule's current composition, not what it was when sent.

**Expected behavior:** Decay during transit should use the molecule composition at time of attack launch.

**Actual behavior:** Uses current molecule composition, which could change if the player deletes and recreates the class.

**Remediation:** Store molecule composition snapshot in `actionsattaques` table at launch time, or accept this as a minor edge case.

---

### FINDING-GAME-020: [MEDIUM] - `demiVie()` function division by zero when decay coefficient equals 1.0

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/formulas.php:222-225`

**Description:** The half-life calculation:
```php
function demiVie($joueur, $classeOuNbTotal, $type = 0) {
    return round((log(0.5, 0.99) / log(coefDisparition($joueur, $classeOuNbTotal, $type), 0.99)));
}
```
If `coefDisparition()` returns exactly 1.0 (no decay), then `log(1.0, 0.99)` = 0, causing a division by zero. This can happen with a very high stabilisateur level combined with medal bonuses that reduce decay to 0.

**Expected behavior:** Return infinity or a very large number when there is no decay.

**Actual behavior:** PHP warning/error for division by zero.

**Remediation:**
```php
function demiVie($joueur, $classeOuNbTotal, $type = 0) {
    $coef = coefDisparition($joueur, $classeOuNbTotal, $type);
    if ($coef >= 1.0) return PHP_INT_MAX; // No decay
    return round((log(0.5, 0.99) / log($coef, 0.99)));
}
```

---

### FINDING-GAME-021: [MEDIUM] - Condenseur point redistribution on building destruction can leave points below 0

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php:521-541`

**Description:** In `diminuerBatiment()` for condenseur, when points need to be removed from atom levels:
```php
$canRemove = min($pointsAEnlever, $currentLevel);
$chaine = $chaine . ($currentLevel - $canRemove) . ";";
```
This correctly prevents going below 0 per atom. However, if the total `$pointsAEnlever` exceeds the total available points across all atoms (which shouldn't normally happen but could in edge cases with concurrent requests), some points might not be fully deducted, leaving an inconsistency between the actual level and the stored points.

**Expected behavior:** Points redistribution should always maintain consistency.

**Actual behavior:** Generally works but lacks a final consistency check.

**Remediation:** Add a sanity check after the loop ensuring total redistributed points equal what was expected.

---

### FINDING-GAME-022: [MEDIUM] - No validation on the number of classes that can exist for a player

**File:** `/home/guortates/TVLW/The-Very-Little-War/armee.php:151`

**Description:** When creating a new molecule class, the check `$_POST['emplacementmoleculecreer1'] <= 5` allows class numbers up to 5, but the game only supports 4 classes (`MAX_MOLECULE_CLASSES = 4`). A POST request with `emplacementmoleculecreer1=5` would attempt to update a molecule row with `numeroclasse=5`, which doesn't exist (only 1-4 are created at registration). The UPDATE would affect 0 rows silently, and the player's energy would still be deducted.

**Expected behavior:** Reject class numbers > 4.

**Actual behavior:** Energy deducted, molecule not created, `niveauclasse` incremented for nothing.

**Remediation:**
```php
// Change validation from <= 5 to <= MAX_MOLECULE_CLASSES (4)
if (isset($_POST['emplacementmoleculecreer1']) and !empty($_POST['emplacementmoleculecreer1'])
    and preg_match("#^[0-9]*$#", $_POST['emplacementmoleculecreer1'])
    and $_POST['emplacementmoleculecreer1'] <= MAX_MOLECULE_CLASSES
    and $_POST['emplacementmoleculecreer1'] >= 1) {
```

Same fix needed for `emplacementmoleculesupprimer` and `emplacementmoleculeformer` on lines 5 and 87.

---

### FINDING-GAME-023: [MEDIUM] - War casualty tracking can accumulate on wrong side due to re-fetch race

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php:586-601`

**Description:** The war casualty tracking queries `declarations` and updates `pertes1`/`pertes2` based on which alliance is `alliance1` in the declaration. However, the query `SELECT * FROM declarations WHERE type=0 AND fin=0 AND ((alliance1=? AND alliance2=?) OR ...)` could match multiple wars if two alliances have declared war on each other (one declares, then the other declares back). `mysqli_fetch_array` would only get the first result, and `mysqli_num_rows` checks if there's at least 1.

If two separate war declarations exist between the same alliances (which shouldn't happen due to the duplicate check in allianceadmin.php, but could if data is inconsistent), casualties could be logged to the wrong war record.

**Expected behavior:** Only one active war between any two alliances.

**Actual behavior:** If duplicate wars exist, casualties go to the first found.

**Remediation:** Use `LIMIT 1` in the query and add a unique constraint on active wars between the same pair.

---

### FINDING-GAME-024: [MEDIUM] - `coutClasse()` ignores the `$base` global it declares

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/formulas.php:248-252`

**Description:**
```php
function coutClasse($numero) {
    global $base;
    return (pow($numero + 1, 4));
}
```
The function declares `global $base` but never uses it. This is a minor code smell but not a bug. The function is pure math and doesn't need DB access.

**Expected behavior:** No DB access needed.

**Actual behavior:** Unnecessary global declaration; no functional issue.

**Remediation:** Remove `global $base;` line.

---

### FINDING-GAME-025: [MEDIUM] - Producteur points redistribution on downgrade uses minimum of 1, but 0 should be valid

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php:509-516`

**Description:** When the producteur is downgraded and points need to be removed:
```php
if ($pointsAEnlever <= ${'points' . $ressource} - 1) {
    $chaine = $chaine . (${'points' . $ressource} - $pointsAEnlever) . ";";
    $pointsAEnlever = 0;
} else {
    $chaine = $chaine . "1;";
    $pointsAEnlever = $pointsAEnlever - (${'points' . $ressource} - 1);
}
```
This enforces a minimum of 1 point per atom type when redistributing after destruction. But if a player had 0 points in some atoms (they never invested in them), this would set them to 1, effectively GIVING them free production. The minimum should be 0, not 1.

**Expected behavior:** Minimum of 0 points per atom type.

**Actual behavior:** Minimum of 1, potentially granting free production in atoms the player never invested in.

**Remediation:**
```php
if ($pointsAEnlever <= ${'points' . $ressource}) {
    $chaine = $chaine . (${'points' . $ressource} - $pointsAEnlever) . ";";
    $pointsAEnlever = 0;
} else {
    $chaine = $chaine . "0;";
    $pointsAEnlever = $pointsAEnlever - ${'points' . $ressource};
}
```

---

### FINDING-GAME-026: [MEDIUM] - Defender's negative resources after pillage not clamped

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php:564-567`

**Description:** The defender resource update:
```php
$setParams[] = ($ressourcesDefenseur[$ressource] - ${$ressource . 'Pille'});
```
While the pillage calculation on line 379 uses `floor($pillageable)` which should never exceed available resources above vault protection, there's a subtle race condition: the defender's resources were read at line 341, but could have changed by the time the UPDATE executes (if another concurrent request modified them). This could result in negative resource values.

**Expected behavior:** Resources should never go negative.

**Actual behavior:** Race condition can cause negative resources.

**Remediation:**
```php
$setParams[] = max(0, ($ressourcesDefenseur[$ressource] - ${$ressource . 'Pille'}));
```

---

### FINDING-GAME-027: [MEDIUM] - `pointsVictoireAlliance` returns wrong values for ranks 4-9

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/formulas.php:34-50`

**Description:**
```php
function pointsVictoireAlliance($classement) {
    if ($classement == 1) return 15;
    if ($classement == 2) return 10;
    if ($classement == 3) return 7;
    if ($classement < 10) return 10 - $classement;
    return 0;
}
```
For rank 4: `10 - 4 = 6`. For rank 5: `10 - 5 = 5`. For rank 9: `10 - 9 = 1`. This gives ranks 4-9 values of 6,5,4,3,2,1. However, rank 3 gives 7, and rank 4 gives 6 -- this is correct and forms a smooth curve.

BUT: rank 3 is handled by the explicit check AND also matches `$classement < 10`. The explicit check takes priority due to the if-chain. So rank 3 correctly returns 7.

Actually, looking again: `if ($classement < 10)` includes ranks 4,5,6,7,8,9 (since 1,2,3 are handled above). This is correct. No bug here.

**RETRACTED -- Not a bug.**

---

### FINDING-GAME-028: [MEDIUM] - Season reset doesn't clear `nbMessages` from `autre` table

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php:732`

**Description:** The `remiseAZero()` resets combat and economic stats but does not reset `nbMessages` (forum post count). While forum posts are a cumulative stat, the forum messages themselves are deleted (`DELETE FROM messages`), creating an inconsistency where the counter shows a count but the messages no longer exist.

Note: `messages` is private messages, not forum posts. Forum posts (`reponses`, `sujets`) are NOT deleted. So `nbMessages` counting forum replies is consistent. However, private messages ARE deleted, which is a separate concern.

**Expected behavior:** Consistent state after reset.

**Actual behavior:** Private messages deleted but forum stats preserved. This is actually correct behavior since forum posts persist across seasons.

**RETRACTED -- Working as designed.**

---

### FINDING-GAME-029: [MEDIUM] - `$revenuEnergie` variable used before initialization in marche.php send block

**File:** `/home/guortates/TVLW/The-Very-Little-War/marche.php:62`

**Description:** On line 62:
```php
if ($revenuEnergie >= revenuEnergie($constructionsJoueur['generateur'], $_POST['destinataire'])) {
```
The variable `$revenuEnergie` is a global set in `initPlayer()` during `basicprivatephp.php` include. It contains the SENDER's energy revenue. This works correctly because `basicprivatephp.php` calls `initPlayer($_SESSION['login'])` before marche.php's code runs.

However, on line 70:
```php
if ($revenu[$ressource] >= revenuAtome($revenusJoueur[$num], $_POST['destinataire'])) {
```
The call `revenuAtome($revenusJoueur[$num], $_POST['destinataire'])` passes the receiver's producteur LEVEL as the first argument, but `revenuAtome()` expects the atom INDEX (0-7), not a level value. The function signature is `revenuAtome($num, $joueur)` where `$num` is the index into `$nomsRes`.

Wait -- looking at `revenuAtome()`:
```php
function revenuAtome($num, $joueur) {
    $niveau = explode(';', $pointsProducteur['pointsProducteur'])[$num];
    return round($bonusDuplicateur * BASE_ATOMS_PER_POINT * $niveau);
}
```
The first parameter `$num` is used as an array index into the pointsProducteur string. On line 70 of marche.php, `$revenusJoueur[$num]` is the actual LEVEL for that atom (e.g., 15), but it's being passed as the FIRST argument to `revenuAtome()`, which treats it as an index. This will either produce an incorrect result (indexing into the wrong position) or an "undefined offset" error if the level > 7.

**Expected behavior:** Should calculate the receiver's atom revenue for each resource correctly.

**Actual behavior:** `revenuAtome()` is called with the wrong first argument type -- a level value instead of an atom index. The function indexes into `pointsProducteur` using this as an offset, producing garbage results.

**Remediation:**
```php
// Line 70: Use the correct atom index, not the level
if ($revenu[$ressource] >= revenuAtome($num, $_POST['destinataire'])) {
    ${'rapport' . $ressource} = revenuAtome($num, $_POST['destinataire']) / $revenu[$ressource];
```

---

### FINDING-GAME-030: [MEDIUM] - Building damage targeting is biased toward champdeforce when it's the highest level

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php:413-441`

**Description:** The building targeting logic on lines 413-441 has a special case: if champdeforce is the highest level building, ALL hydrogen damage goes to champdeforce. Otherwise, each class's damage is randomly assigned to one of 4 buildings. This means a player with a high champdeforce is guaranteed to have it targeted, while a player with all buildings at equal levels gets random distribution.

This is by design (the champdeforce is described as absorbing damage when it's the highest), but it creates a perverse incentive: raising champdeforce ABOVE other buildings makes it the target every time, potentially making it worse to have a high champdeforce. A level 20 champdeforce with level 10 everything else will ALWAYS be targeted, while a level 15 champdeforce with level 15 everything else gets random targeting (75% chance of not being targeted).

**Expected behavior:** By design, but creates counterintuitive optimization.

**Actual behavior:** Working as documented but may surprise players.

**Remediation (optional):** Consider having champdeforce absorb a percentage of damage (e.g., 50% goes to champdeforce, 50% random) rather than all-or-nothing.

---

## LOW Findings

### FINDING-GAME-031: [LOW] - `pointsVictoireJoueur` returns 0 for ranks 101+ but formula produces negative for high ranks

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/formulas.php:8-32`

**Description:** The function handles ranks 51-100 as:
```php
if ($classement <= 100) {
    return max(1, floor(3 - ($classement - 50) * 0.04));
}
```
For rank 51: `floor(3 - 1*0.04) = floor(2.96) = 2` -> `max(1, 2) = 2`
For rank 75: `floor(3 - 25*0.04) = floor(2) = 2` -> `max(1, 2) = 2`
For rank 100: `floor(3 - 50*0.04) = floor(1) = 1` -> `max(1, 1) = 1`

This is correct and always returns at least 1. For ranks > 100, returns 0. No bug.

**RETRACTED -- Not a bug.**

---

### FINDING-GAME-032: [LOW] - Spy alert sent even on failed espionage

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php:424-428`

**Description:** The spy alert report to the defender is only sent when espionage SUCCEEDS (line 424: `if ($espionageThreshold < $actions['nombreneutrinos'])`). On failed espionage, only the attacker gets a "failed" report and the defender gets nothing. This is actually correct behavior -- the defender doesn't know about a failed spy attempt.

**RETRACTED -- Working as designed.**

---

### FINDING-GAME-033: [LOW] - Report HTML uses `$information` variable inside heredoc incorrectly

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/game_actions.php:178`

**Description:** Line 178 in the combat report building:
```php
$information
```
This uses a variable `$information` which is set on line 140:
```php
$information = "<strong>Aucune mol\u00e9cule n\'est revenue !</strong><br/><br/>";
```
But this is inside a double-quoted string concatenation, so it works. However, the escaped apostrophe `\'` in a double-quoted string will produce a literal `\'` in the output rather than just `'`. This is a display issue in combat reports.

**Expected behavior:** Clean apostrophe in report text.

**Actual behavior:** Literal `\'` appears in the report.

**Remediation:**
```php
$information = "<strong>Aucune mol\u00e9cule n'est revenue !</strong><br/><br/>";
```

---

### FINDING-GAME-034: [LOW] - Catalyst `getActiveCatalyst()` called multiple times per page load (performance)

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/catalyst.php:76-78`

**Description:** `catalystEffect()` calls `getActiveCatalyst()` which queries the database EVERY time. During combat resolution, `catalystEffect` is called multiple times (for attack_bonus, pillage_bonus, decay_increase, etc.), each triggering a separate DB query. Since the catalyst is global and changes weekly, it should be cached.

**Expected behavior:** Single DB query per page load for catalyst data.

**Actual behavior:** Multiple redundant DB queries.

**Remediation:**
```php
function getActiveCatalyst() {
    static $cachedCatalyst = null;
    if ($cachedCatalyst !== null) return $cachedCatalyst;
    // ... rest of the function
    $cachedCatalyst = $catalyst;
    return $cachedCatalyst;
}
```

---

### FINDING-GAME-035: [LOW] - Medal DB queries in `attaque()` and `defense()` functions are called per-class per-combat

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/formulas.php:79-95`

**Description:** The `attaque()` function queries `SELECT pointsAttaque FROM autre WHERE login=?` for EVERY class of every player in every combat. With 4 classes per side, that's 8 calls to `attaque()` for the attacker and 8 calls to `defense()` for the defender, each doing a DB query. These values don't change during a single combat resolution.

**Expected behavior:** Medal bonus should be fetched once and cached.

**Actual behavior:** 16+ redundant DB queries per combat.

**Remediation:** Cache the medal data per player at the start of combat resolution.

---

### FINDING-GAME-036: [LOW] - `batMax` in `autre` table updated every page load

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/player.php:164`

**Description:** `initPlayer()` calls `batMax()` and updates the `autre` table with the result on EVERY page load:
```php
$plusHaut = batMax($joueur);
dbExecute($base, 'UPDATE autre SET batmax=? WHERE login=?', 'is', $plusHaut, $joueur);
```
This is an unnecessary write on every request. Building levels only change when constructions complete or buildings are destroyed.

**Expected behavior:** Only update `batmax` when building levels change.

**Actual behavior:** DB write on every authenticated page load.

**Remediation:** Only call this update when a construction completes or a building is damaged.

---

### FINDING-GAME-037: [LOW] - `$nbClasses` variable referenced but defined in constantesBase.php (fragile global)

**File:** `/home/guortates/TVLW/The-Very-Little-War/includes/combat.php:69` and many other files

**Description:** The variable `$nbClasses` is used throughout combat.php (lines 69, 105, 131, etc.) but is defined as a global in `constantesBase.php`. If the include order changes or the variable is accidentally overwritten, combat breaks silently.

**Expected behavior:** Use the constant `MAX_MOLECULE_CLASSES` from config.php instead.

**Actual behavior:** Relies on a fragile global variable.

**Remediation:** Replace `$nbClasses` references with `MAX_MOLECULE_CLASSES` constant.

---

### FINDING-GAME-038: [LOW] - Alliance can be deleted from classement.php ranking page

**File:** `/home/guortates/TVLW/The-Very-Little-War/classement.php:358-359`

**Description:** In the alliance ranking display, if an alliance has 0 members:
```php
if ($nbjoueurs != 0) {
    // display
} else {
    dbExecute($base, 'DELETE FROM alliances WHERE id=?', 'i', $donnees['id']);
    dbExecute($base, 'DELETE FROM invitations WHERE idalliance=?', 'i', $donnees['id']);
}
```
This auto-cleanup is a side-effect of viewing the ranking page. While functional, it means viewing the alliance rankings can modify the database by deleting alliances and invitations. This should be handled by a dedicated cleanup cron job or admin action, not triggered by page views.

**Expected behavior:** Read-only ranking page.

**Actual behavior:** Side-effect: deletes empty alliances when anyone views the ranking.

**Remediation:** Move this cleanup to a scheduled task or the season reset function.

---

## Design Observations (Not Bugs)

### OBS-001: Attacker isotope modifiers not applied to attacker HP in the same way

In combat.php, the attacker's HP calculation at line 224 correctly uses `$attIsotopeHpMod[$i]`. The defender's HP at line 248 also correctly uses `$defIsotopeHpMod[$i]`. This is correct.

### OBS-002: Chemical reaction symmetry

Chemical reactions check all class pairs including (a,b) and (b,a), so the same reaction can only trigger once due to the `!isset($activeReactions[$name])` guard. This is correct.

### OBS-003: Formation Phalange class 1 absorbs 70% damage

The Phalange formation assigns 70% of attacker damage to class 1 and splits the remaining 30% among classes 2-4. Class 1 also gets +30% defense bonus. This means Phalange is extremely powerful with a high-Brome/Carbone class 1 and could be the dominant meta. Consider whether this needs balancing.

### OBS-004: Vacuum protection (coffrefort) is per-resource

The vault protects `VAULT_PROTECTION_PER_LEVEL * level` of EACH resource. At level 10, that's 1000 of each resource protected. With 8 atom types, total protected resources = 8000, which is significant. This is by design and seems reasonable.

### OBS-005: Duplicateur combat bonus structure

In combat.php line 49: `$bonusDuplicateurAttaque = 1 + ($duplicateurAttaque['duplicateur'] / 100)`. In game_resources.php line 28: `$bonusDuplicateur = 1 + bonusDuplicateur($duplicateur['duplicateur'])` where `bonusDuplicateur($niveau) = $niveau / 100`. These are equivalent, both giving 1% per level for both combat and production. Consistent.

---

## Priority Fix Order

1. **FINDING-GAME-004** (CRITICAL) - Building damage on non-wins / wrong troop count
2. **FINDING-GAME-003** (CRITICAL) - Isotope defense modifier wrong variable
3. **FINDING-GAME-001** (CRITICAL) - Prestige medal table doesn't exist
4. **FINDING-GAME-002** (CRITICAL) - Prestige unlocks never applied
5. **FINDING-GAME-005** (CRITICAL) - Market buy-sell point farming
6. **FINDING-GAME-006** (HIGH) - Dispersee damage wasted on empty classes
7. **FINDING-GAME-008** (HIGH) - Pillage exceeds storage cap
8. **FINDING-GAME-009** (HIGH) - Resource delivery exceeds storage cap
9. **FINDING-GAME-011** (HIGH) - Defender pillage stat incorrectly decremented
10. **FINDING-GAME-010** (HIGH) - Negative attack/defense points
11. **FINDING-GAME-013** (HIGH) - Alliance research no level cap
12. **FINDING-GAME-029** (MEDIUM) - Wrong argument to revenuAtome in market send
13. **FINDING-GAME-022** (MEDIUM) - Class number validation off-by-one
14. **FINDING-GAME-017** (MEDIUM) - Halogenation speed bonus unused
15. **FINDING-GAME-018** (MEDIUM) - Embuscade applies to wrong stat
