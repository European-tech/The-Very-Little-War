# TVLW Gameplay Balance Overhaul — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Transform The Very Little War from a 15-year-old unbalanced game into a strategically rich, multi-playstyle experience — as a gift to the loyal players who stuck with it.

**Architecture:** Pure PHP formula/constant changes + new DB columns/tables + combat resolution rewrite. No framework changes, no frontend rewrite. Every change is backwards-compatible with the existing session/page architecture.

**Tech Stack:** PHP 8.2, MySQL/MariaDB, existing Framework7 frontend, existing `includes/` module system.

---

## Audit Summary

Four parallel deep-analysis agents audited every formula, combat mechanic, economy system, and gameplay loop. Here are the findings that drive this plan:

### Critical Balance Issues Found

| # | Issue | Impact |
|---|-------|--------|
| **C1** | **Terreur medal INVERTS attack cost** — formula is `0.15 * (1 + bonus/100)` instead of `(1 - bonus/100)`. Veterans pay 50% MORE to attack. | Game-breaking. Penalizes the behavior it rewards. |
| **C2** | **Sequential class damage = meat shield exploit** — Attacker class 1 absorbs ALL defender damage, classes 2-4 survive untouched. Combat is "solved". | Eliminates molecule design diversity. |
| **C3** | **Construction points = 85-90% of total score** — Combat, pillage, trade are irrelevant to rankings. | The game is a building simulator, not a strategy game. |
| **C4** | **No defensive rewards** — Defenders gain nothing from surviving attacks. | Turtle playstyle is non-viable. Attack always dominates. |
| **C5** | **Victory points rank 49-50 = 0 but rank 51 = 10** — Formula inversion between brackets. | Broken rank incentives. |
| **C6** | **Resource double-update race condition** — Two tabs = double resources. | Exploitable duplication. |

### High-Priority Issues

| # | Issue | Impact |
|---|-------|--------|
| **H1** | No diminishing returns on economy buildings — rich get richer exponentially | Runaway leader effect |
| **H2** | Max molecule size (1600 atoms) has 1.66hr half-life — unusable | Endgame molecules are disposable |
| **H3** | Espionage is undetectable and cheap — perfect information for attackers | No counter-play for defenders |
| **H4** | No attack cooldown per target — unlimited grief potential | New/weak players get destroyed |
| **H5** | Division by zero: market with 0 active players, revenue with 0 production | Crashes |
| **H6** | Iodine energy production negligible even after 5x buff | Entire atom stat is near-useless |
| **H7** | Storage overflow guaranteed for balanced players (6.67hr fill time) | Punishes offline/casual players |
| **H8** | Zero-troop attacks farm Terreur medal for free | Medal exploit |
| **H9** | `augmenterBatiment`/`diminuerBatiment` use wrong player context for points | Wrong points awarded |

### Gameplay Gaps

| Gap | Current State | Desired State |
|-----|--------------|---------------|
| **Strategic diversity** | 1 dominant strategy (build + raid) | 4-6 viable playstyles |
| **Defense viability** | Defenders gain nothing | Defenders gain resources/points |
| **Combat depth** | Single-round, meat-shield-solved | Formation choice, reaction bonuses, traps |
| **Alliance depth** | Only duplicateur matters | Research tree, war objectives, shared intel |
| **Cross-season progression** | Only top 50 earn VP | Prestige system for ALL active players |
| **Discovery/events** | None | Weekly catalysts, seasonal modifiers |
| **Map strategy** | Flat distance-only | Resource nodes, territory influence |
| **Economic depth** | Build + trade only | Compound synthesis, vault, scarcity zones |

---

## Implementation Phases

The plan is organized into 5 phases, each independently deployable. Each phase improves the game meaningfully even if later phases are never implemented.

---

## PHASE 1: Critical Bug Fixes & Formula Corrections
**Time estimate: 1-2 sessions | Risk: Low | Impact: High**
*Fix everything that is objectively broken before touching balance.*

---

### Task 1: Fix Terreur Medal Inversion

**Files:**
- Modify: `attaquer.php:15`

**Context:** The Terreur medal is supposed to REDUCE attack cost as players attack more. Currently `0.15 * (1 + $bonus / 100)` INCREASES cost. At Diamant Rouge (50%), cost is 0.225 instead of intended 0.075.

**Step 1: Read the current code**

Read `attaquer.php` lines 1-30 to see the full context.

**Step 2: Fix the formula**

Change:
```php
$coutPourUnAtome = 0.15 * (1 + $bonus / 100);
```
To:
```php
$coutPourUnAtome = 0.15 * (1 - $bonus / 100);
```

**Step 3: Verify no other medal formulas have the same bug**

Search the codebase for all patterns matching `(1 + $bonus / 100)` and verify each is correct:
- `revenuEnergie()` in `game_resources.php:49` — CORRECT (production bonus should increase)
- `attaque()` in `formulas.php:95` — CORRECT (attack power should increase)
- `defense()` in `formulas.php:113` — CORRECT (defense power should increase)
- `pillage()` in `formulas.php:141` — CORRECT (pillage capacity should increase)

Only `attaquer.php:15` needs the fix.

**Step 4: Commit**

```bash
git add attaquer.php
git commit -m "fix: invert Terreur medal formula — reduce attack cost instead of increasing it"
```

---

### Task 2: Fix Victory Points Rank Inversion

**Files:**
- Modify: `includes/formulas.php:8-33`

**Context:** Ranks 49-50 get 0 VP (formula: `floor(15 - (rank-20)*0.5)` = 0), but rank 51 gets 10 VP (formula: `max(1, floor(15 - (rank-20)*0.15))` = 10). The transition between the 21-50 and 51-100 brackets is broken.

**Step 1: Read `pointsVictoireJoueur()` in full**

Read `includes/formulas.php` lines 8-33.

**Step 2: Fix the formula to create a smooth curve**

Replace the 21-50 bracket:
```php
if ($classement <= 50) {
    return floor(15 - ($classement - 20) * 0.5);
}
```
With:
```php
if ($classement <= 50) {
    return max(1, floor(15 - ($classement - 20) * 0.4));
}
```

This gives rank 50 = `max(1, floor(15 - 12)) = 3` VP instead of 0. Rank 21 = 15, rank 30 = 11, rank 40 = 7, rank 50 = 3.

Also fix the 51-100 bracket to start from 3 instead of jumping to 10:
```php
if ($classement <= 100) {
    return max(1, floor(3 - ($classement - 50) * 0.04));
}
```

This gives rank 51 = 3, rank 75 = 2, rank 100 = 1. Smooth transition.

**Step 3: Remove unused `$actifs` variable**

Line 10: `$actifs = compterActifs();` is called but never used. Remove it to save a DB query.

**Step 4: Commit**

```bash
git add includes/formulas.php
git commit -m "fix: smooth victory points curve — eliminate rank 49-51 inversion, remove unused query"
```

---

### Task 3: Fix Resource Double-Update Race Condition

**Files:**
- Modify: `includes/game_resources.php:104-127`

**Context:** `updateRessources()` reads `tempsPrecedent`, calculates elapsed time, then updates. Two concurrent page loads can both read the same timestamp and double resources.

**Step 1: Read the current update flow**

Read `includes/game_resources.php` lines 104-127.

**Step 2: Add atomic timestamp swap**

Replace the current read-then-update pattern:
```php
$donnees = dbFetchOne($base, 'SELECT tempsPrecedent FROM autre WHERE login=?', 's', $joueur);
$nbsecondes = time() - $donnees['tempsPrecedent'];
// ...
dbExecute($base, 'UPDATE autre SET tempsPrecedent=? WHERE login=?', 'is', time(), $joueur);
```

With an atomic compare-and-swap:
```php
$donnees = dbFetchOne($base, 'SELECT tempsPrecedent FROM autre WHERE login=?', 's', $joueur);
$nbsecondes = time() - $donnees['tempsPrecedent'];

if ($nbsecondes < 1) {
    return; // Too fast, skip update
}

// Atomic: only update if tempsPrecedent hasn't changed since we read it
$result = dbExecute($base, 'UPDATE autre SET tempsPrecedent=? WHERE login=? AND tempsPrecedent=?', 'isi', time(), $joueur, $donnees['tempsPrecedent']);

if (mysqli_affected_rows($base) === 0) {
    return; // Another request already updated — skip to prevent double resources
}
```

**Step 3: Commit**

```bash
git add includes/game_resources.php
git commit -m "fix: prevent resource duplication from concurrent page loads via atomic timestamp swap"
```

---

### Task 4: Fix Division-by-Zero Bugs

**Files:**
- Modify: `marche.php:7` (market volatility)
- Modify: `includes/player.php:175` (revenue display)

**Step 1: Fix market volatility**

In `marche.php`, change:
```php
$volatilite = 0.3 / $actifs['nbActifs'];
```
To:
```php
$volatilite = 0.3 / max(1, $actifs['nbActifs']);
```

**Step 2: Fix revenue display**

In `includes/player.php`, find the line dividing by `$revenu[$ressource]` and wrap with `max(1, ...)`:
```php
$max = max($max, 3600 * ($placeDepot - $ressources[$ressource]) / max(1, $revenu[$ressource]));
```

**Step 3: Commit**

```bash
git add marche.php includes/player.php
git commit -m "fix: prevent division by zero in market volatility and revenue display"
```

---

### Task 5: Fix Zero-Troop Attack Exploit

**Files:**
- Modify: `attaquer.php`

**Context:** Players can send attacks with 0 troops in all classes, farming Terreur medal progress for free.

**Step 1: Add minimum troop validation**

After the existing troop validation loop, add:
```php
$totalTroops = 0;
for ($c = 1; $c <= 4; $c++) {
    $totalTroops += intval($_POST['nbclasse' . $c]);
}
if ($totalTroops < 1) {
    $bool = 0;
}
```

**Step 2: Commit**

```bash
git add attaquer.php
git commit -m "fix: require at least 1 molecule to launch an attack"
```

---

### Task 6: Fix `augmenterBatiment`/`diminuerBatiment` Player Context Bug

**Files:**
- Modify: `includes/player.php:430-540`

**Context:** These functions use global `$listeConstructions` which references `$_SESSION['login']`, not the `$joueur` parameter. During combat building destruction, the wrong player's data is used.

**Step 1: Read the functions**

Read `includes/player.php` lines 420-540.

**Step 2: Fix by passing player context explicitly**

In `diminuerBatiment($nom, $joueur)`, ensure `initPlayer($joueur)` is called before `ajouterPoints`/`retirerPoints`, and that the global `$listeConstructions` is properly set for `$joueur`, then restored after:

```php
function diminuerBatiment($nom, $joueur) {
    global $listeConstructions;

    // Save current player context
    $savedList = $listeConstructions;

    // Switch to target player context
    initPlayer($joueur);

    // ... existing logic using $listeConstructions ...

    // Restore original player context
    $listeConstructions = $savedList;
    if ($joueur !== $_SESSION['login']) {
        initPlayer($_SESSION['login']);
    }
}
```

**Step 3: Commit**

```bash
git add includes/player.php
git commit -m "fix: use correct player context in building level change functions"
```

---

## PHASE 2: Combat Rebalance
**Time estimate: 2-3 sessions | Risk: Medium | Impact: Very High**
*Break the meat-shield meta, make defense viable, add strategic depth to combat.*

---

### Task 7: Implement Distributed Damage (Break Meat Shield)

**Files:**
- Modify: `includes/combat.php` (casualty resolution)

**Context:** Currently, defender damage is applied to attacker class 1 first, then class 2, etc. This allows a meat shield in class 1 to absorb everything while glass cannons in classes 2-4 survive untouched. Combat is "solved".

**Design:** Replace sequential class damage with **proportional distribution**:
- Defender's total defense damage is distributed across attacker classes proportional to each class's total HP.
- If class 1 has 60% of total HP, it receives 60% of the damage.
- If class 2 has 0 HP (0 Brome), it takes its proportional share of damage and ALL molecules in that class die (since any damage kills 0-HP molecules).

**Formula:**
```
For each attacker class i:
  classHP_i = HP_per_molecule_i * count_i
  totalHP = sum(classHP_i for all classes)
  damageShare_i = defenderTotalDamage * (classHP_i / totalHP)
  kills_i = floor(damageShare_i / HP_per_molecule_i)  (if HP > 0)
  kills_i = count_i                                    (if HP == 0 and damageShare_i > 0)
```

**Impact:**
- Meat shields still help (high-HP classes absorb more damage proportionally) but cannot completely protect 0-HP classes
- Players MUST give all classes at least SOME Brome to survive
- Hybrid molecules become viable because you need HP on every class
- Attackers face real trade-offs: pure glass cannons die instantly

**Step 1: Read combat.php casualty resolution**

Read the entire `includes/combat.php` to understand the current damage distribution loop.

**Step 2: Implement proportional damage distribution**

Replace the sequential loop with proportional distribution for BOTH attacker and defender casualties.

**Step 3: Test with worked examples**

Verify:
- Old meta (1000 shields + 500 glass cannons) now loses glass cannons
- Balanced build (200 O + 50 Br on attackers) performs better than pure glass cannon
- Edge case: all classes have 0 Br = all die to any damage

**Step 4: Commit**

```bash
git add includes/combat.php
git commit -m "feat: replace sequential damage with proportional distribution — breaks meat shield meta"
```

---

### Task 8: Add Defensive Rewards

**Files:**
- Modify: `includes/combat.php` (combat resolution)
- Modify: `includes/config.php` (new constants)

**Context:** Defenders currently gain nothing from surviving attacks. This makes defense non-viable as a playstyle.

**Design:** When the defender wins or draws:
1. **Resource recovery:** Defender regains 20% of resources the attacker attempted to pillage (from the destroyed attacker molecules' "carried loot"), paid by the system (not taken from attacker).
2. **Defense points bonus:** Defender earns `floor(1.5 * combatPoints)` instead of the normal `combatPoints` for a defensive victory.
3. **Attacker deterrent:** After a failed attack, the attacker cannot attack the same target for 4 hours (cooldown).

**New constants in config.php:**
```php
define('DEFENSE_REWARD_RATIO', 0.20);        // 20% resource bonus on successful defense
define('DEFENSE_POINTS_MULTIPLIER_BONUS', 1.5); // 1.5x combat points for defenders
define('ATTACK_COOLDOWN_HOURS', 4);           // Hours before same attacker can hit same target
```

**Step 1: Implement defense rewards in combat resolution**

After determining the winner in `combat.php`, add:
```php
if ($winner === 'defender') {
    // Reward defender with bonus resources
    $bonusEnergy = $totalPillagePotential * DEFENSE_REWARD_RATIO;
    // Add to defender's resources (capped by depot)

    // Enhanced defense points
    $defensePoints = floor(DEFENSE_POINTS_MULTIPLIER_BONUS * $combatPoints);

    // Set cooldown
    dbExecute($base, 'INSERT INTO attack_cooldowns (attacker, defender, expires) VALUES (?, ?, ?)',
        'ssi', $attacker, $defender, time() + ATTACK_COOLDOWN_HOURS * 3600);
}
```

**Step 2: Add attack cooldown check in attaquer.php**

Before allowing an attack:
```php
$cooldown = dbFetchOne($base, 'SELECT expires FROM attack_cooldowns WHERE attacker=? AND defender=? AND expires > ?', 'ssi', $joueur, $target, time());
if ($cooldown) {
    // Show "You must wait X hours before attacking this player again"
    return;
}
```

**Step 3: Create the cooldown table**

```sql
CREATE TABLE attack_cooldowns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    attacker VARCHAR(50),
    defender VARCHAR(50),
    expires INT,
    INDEX idx_attacker_defender (attacker, defender)
);
```

**Step 4: Commit**

```bash
git add includes/combat.php includes/config.php attaquer.php
git commit -m "feat: add defensive rewards — resource bonus, enhanced points, attack cooldown"
```

---

### Task 9: Add Defensive Formations

**Files:**
- Modify: `includes/combat.php` (apply formation effects)
- Modify: `constructions.php` (formation selection UI)
- Modify: `includes/config.php` (formation constants)
- Modify: DB schema (add column)

**Context:** Defenders currently have no pre-battle choices. Adding formations creates a counter-play layer.

**Design — 3 formations:**

| Formation | Effect | Best Against |
|-----------|--------|-------------|
| **Dispersée** (Dispersed) | Damage split equally across all classes (25% each) instead of proportional to HP | Concentrated single-class attacks |
| **Phalange** (Phalanx) | Class 1 absorbs 70% of damage, gets +30% defense bonus | Many small attacks |
| **Embuscade** (Ambush) | If defender has more total molecules than attacker, +25% attack bonus | Overconfident raiders |

**New column:** `ALTER TABLE constructions ADD COLUMN formation TINYINT DEFAULT 0` (0=Dispersée, 1=Phalange, 2=Embuscade)

**New constants:**
```php
define('FORMATION_PHALANX_ABSORB', 0.70);
define('FORMATION_PHALANX_DEFENSE_BONUS', 0.30);
define('FORMATION_AMBUSH_ATTACK_BONUS', 0.25);
```

**Step 1: Add formation column to DB**

Create migration file.

**Step 2: Add formation selector to constructions page**

Radio buttons or select in the constructions interface.

**Step 3: Apply formation effects in combat.php**

Before damage distribution, check defender's formation and modify the distribution algorithm accordingly.

**Step 4: Show formation in espionage reports**

When a spy succeeds, reveal the target's formation choice.

**Step 5: Commit**

```bash
git add includes/combat.php includes/config.php constructions.php
git commit -m "feat: add 3 defensive formations — dispersed, phalanx, ambush"
```

---

### Task 10: Add Espionage Notification

**Files:**
- Modify: `attaquer.php` (espionage section)

**Context:** Espionage is currently undetectable. Defenders should know they've been spied on to add counter-play.

**Design:** When espionage succeeds, the defender receives a report:
- "An unknown agent has spied on your base."
- Does NOT reveal the spy's identity
- Gives the defender a 2-hour window to change formation, build traps, or move resources

**Step 1: After successful espionage, insert defender report**

```php
if ($espionageSuccess) {
    $titreRapport = 'Tentative d\'espionnage détectée';
    $contenuRapport = 'Un agent inconnu a espionné votre base. Vos défenses, ressources et compositions moléculaires ont été observées.';
    dbExecute($base, 'INSERT INTO rapports VALUES(default, ?, ?, ?, ?, default, ?)',
        'issss', time(), $titreRapport, $contenuRapport, $defender,
        '<img alt="spy" src="images/rapports/rapportespionnage.png" class="imageAide"/>');
}
```

**Step 2: Commit**

```bash
git add attaquer.php
git commit -m "feat: notify defenders when they are spied on (anonymous)"
```

---

## PHASE 3: Points & Progression Rebalance
**Time estimate: 1-2 sessions | Risk: Low | Impact: High**
*Make combat, trading, and defense contribute meaningfully to rankings.*

---

### Task 11: Rebalance Points Distribution

**Files:**
- Modify: `includes/config.php`
- Modify: `includes/formulas.php`
- Modify: relevant display files

**Context:** Construction = 85-90% of points. Combat, trade, pillage are negligible.

**Design — New formula weights:**

```
totalPoints = constructionPoints                              // unchanged
            + round(5.0 * sqrt(rawAttackPoints))             // was 3.0 → 5.0
            + round(5.0 * sqrt(rawDefensePoints))            // was 3.0 → 5.0
            + round(tanh(ressourcesPillees / 50000) * 80)    // was /100000 * 50 → /50000 * 80
            + min(80, floor(0.08 * sqrt(tradeVolume)))       // was min(40, 0.05*) → min(80, 0.08*)
            + round(tanh(defenseRewards / 30000) * 60)       // NEW: defense reward points
```

**Impact at typical play levels:**
- Active fighter: +200-400 points from combat (was +80-150)
- Active defender: +150-300 points from new defense category
- Active trader: +60-80 points (was +30-40)
- Pure builder: unchanged

This brings combat contributions from ~10% to ~25-30% of total, making fights matter.

**Step 1: Update constants**

```php
define('ATTACK_POINTS_MULTIPLIER', 5.0);    // was 3.0
define('DEFENSE_POINTS_MULTIPLIER', 5.0);   // was 3.0
define('PILLAGE_POINTS_DIVISOR', 50000);    // was 100000
define('PILLAGE_POINTS_MULTIPLIER', 80);    // was 50
define('MARKET_POINTS_SCALE', 0.08);        // was 0.05
define('MARKET_POINTS_CAP', 80);            // was 40
define('DEFENSE_REWARD_POINTS_DIVISOR', 30000);  // NEW
define('DEFENSE_REWARD_POINTS_MULTIPLIER', 60);  // NEW
```

**Step 2: Update totalPoints calculation in classement.php**

Add the new defense reward term.

**Step 3: Add `defenseRewards` column to `autre` table**

Track cumulative defense reward resources earned.

**Step 4: Commit**

```bash
git add includes/config.php includes/formulas.php classement.php
git commit -m "feat: rebalance points — boost combat, trade, and add defense scoring category"
```

---

### Task 12: Add Prestige System (Cross-Season Progression)

**Files:**
- Create: `includes/prestige.php`
- Modify: `includes/config.php`
- Modify: `inscrire.php` (apply prestige bonuses at round start)
- Modify: `classement.php` (display prestige)
- Create: DB migration for `prestige` table

**Context:** Only the top ~50 players earn VP. Bottom 50% get nothing cross-season. The prestige system rewards ALL active players.

**Design:**

**Prestige Points earned per season:**
| Activity | PP |
|----------|-----|
| Active during final week | 5 |
| Each medal tier reached | 1 per tier |
| Launched 10+ attacks | 5 |
| Defended 5+ attacks | 5 |
| Traded 20+ times on market | 3 |
| Donated to alliance | 2 |
| Top 50 rank bonus | 10-50 based on rank |

**Prestige unlocks (cumulative, permanent):**
| Unlock | Cost | Effect |
|--------|------|--------|
| Débutant Rapide | 50 PP | Start with Generateur level 2 |
| Expérimenté | 100 PP | +5% resource production |
| Vétéran | 250 PP | Start with 1 extra day of beginner protection |
| Maître Chimiste | 500 PP | +5% combat stats |
| Légende | 1000 PP | Unique badge + name color |

**DB schema:**
```sql
CREATE TABLE prestige (
    login VARCHAR(50) PRIMARY KEY,
    total_pp INT DEFAULT 0,
    unlocks VARCHAR(255) DEFAULT ''
);
```

**Step 1: Create prestige calculation function**

**Step 2: Integrate with round-end logic**

**Step 3: Apply bonuses at game start**

**Step 4: Add prestige display to rankings/profile**

**Step 5: Commit**

```bash
git add includes/prestige.php includes/config.php inscrire.php classement.php
git commit -m "feat: add prestige system — cross-season progression for all active players"
```

---

## PHASE 4: Strategic Depth Features
**Time estimate: 3-4 sessions | Risk: Medium | Impact: Very High**
*Add the features that create distinct playstyles and strategic diversity.*

---

### Task 13: Add Chemical Reaction Bonuses

**Files:**
- Modify: `includes/combat.php`
- Modify: `includes/config.php`
- Create: reaction bonus display in combat reports

**Context:** Currently, molecule classes don't interact. Adding reaction bonuses when specific atom combinations are deployed together creates incentive for complementary molecule design.

**Design — 5 reactions:**

| Reaction | Condition | Bonus |
|----------|-----------|-------|
| **Combustion** | Class A: O≥100, Class B: C≥100 | +15% attack for both |
| **Hydrogénation** | Class A: H≥100, Class B: Br≥100 | +15% HP for both |
| **Halogénation** | Class A: Cl≥80, Class B: I≥80 | +20% fleet speed |
| **Sulfuration** | Class A: S≥100, Class B: N≥50 | +20% pillage capacity |
| **Neutralisation** | Class A: O≥80, Class B: H≥80 AND C≥80 | +15% defense for both |

**Implementation:** After loading molecule stats in combat.php, check all class pairs for reaction conditions. Apply bonuses as multipliers.

**Step 1: Define reaction table in config**

**Step 2: Implement reaction detection in combat resolution**

**Step 3: Display active reactions in combat reports**

**Step 4: Add reaction hints on molecule creation page**

**Step 5: Commit**

```bash
git add includes/combat.php includes/config.php
git commit -m "feat: add 5 chemical reaction bonuses for complementary molecule design"
```

---

### Task 14: Add Isotope Variants

**Files:**
- Modify: `molecules.php` (molecule creation)
- Modify: `includes/combat.php` (apply isotope modifiers)
- Modify: `includes/formulas.php` (decay modifier for Stable)
- Modify: DB schema (add `isotope` column to molecules table)
- Modify: `includes/config.php`

**Design — 3 isotopes:**

| Isotope | Attack | HP | Decay | Special |
|---------|--------|----|-------|---------|
| **Stable** | -10% | +20% | -30% decay rate | Tank/defender role |
| **Réactif** | +20% | -10% | +50% decay rate | Glass cannon role |
| **Catalytique** | -10% | -10% | normal | +15% to ALL stats of other classes in same army |

**Step 1: Add isotope column**

```sql
ALTER TABLE molecules ADD COLUMN isotope TINYINT DEFAULT 0; -- 0=none, 1=stable, 2=reactive, 3=catalytic
```

**Step 2: Add isotope selector to molecule creation UI**

**Step 3: Apply isotope modifiers in combat resolution**

**Step 4: Apply Stable isotope decay modifier in coefDisparition**

**Step 5: Commit**

```bash
git add molecules.php includes/combat.php includes/formulas.php includes/config.php
git commit -m "feat: add 3 isotope variants — Stable, Reactive, Catalytic"
```

---

### Task 15: Add Vault Building (Resource Protection)

**Files:**
- Modify: `includes/config.php` (add building to $BUILDING_CONFIG)
- Modify: `includes/combat.php` (subtract vault protection from pillage)
- Modify: `constructions.php` (display vault)
- Modify: DB schema (add `coffrefort` column to constructions)

**Design:** Vault protects `100 * level` of each resource from pillage. At level 20, 2,000 of each atom + 2,000 energy are safe from raiders.

**Building config:**
```php
'coffrefort' => [
    'nom' => 'Coffre-fort',
    'description' => 'Protège une partie de vos ressources contre le pillage',
    'coutBase' => 150,
    'exposantCout' => 0.7,
    'coutAtome' => 0,     // energy only
    'tempsBase' => 2,
    'exposantTemps' => 1.2,
    'points' => 1,
    'exposantPoints' => 0.1,
]
```

**Step 1: Add building to config and DB**

**Step 2: Modify pillage calculation in combat.php**

```php
$vaultProtection = 100 * $defenderBuildings['coffrefort'];
foreach ($nomsRes as $num => $ressource) {
    $pillageable = max(0, $defenderResources[$ressource] - $vaultProtection);
    // Only pillage from $pillageable, not total resources
}
```

**Step 3: Add UI display**

**Step 4: Commit**

```bash
git add includes/config.php includes/combat.php constructions.php
git commit -m "feat: add Vault building — protects resources from pillage"
```

---

### Task 16: Add Weekly Catalyst System

**Files:**
- Modify: `includes/config.php`
- Create: `includes/catalyst.php`
- Modify: relevant formula files to check active catalyst
- Modify: `includes/basicprivatehtml.php` (display active catalyst)

**Design — 6 catalysts, one active per week:**

| Catalyst | Effect |
|----------|--------|
| **Combustion** | All attacks deal +10% damage |
| **Synthèse** | Molecule formation 20% faster |
| **Équilibre** | Market prices drift to 1.0 50% faster |
| **Fusion** | Alliance duplicateur costs -25% |
| **Cristallisation** | Building construction time -15% |
| **Volatilité** | Molecule decay +30% faster, pillage capacity +25% |

**Implementation:** Store current catalyst in `statistiques` table. Rotate every Monday via cron or on first access each week. Apply as multiplier in relevant formula.

**Step 1: Create catalyst system**

**Step 2: Add catalyst display to game header**

**Step 3: Apply catalyst modifiers to relevant formulas**

**Step 4: Commit**

```bash
git add includes/catalyst.php includes/config.php includes/basicprivatehtml.php
git commit -m "feat: add weekly catalyst system — rotating global bonuses"
```

---

### Task 17: Add Alliance Research Tree

**Files:**
- Modify: `alliance.php` (research UI)
- Modify: `includes/config.php` (research definitions)
- Modify: DB schema (add research columns to alliances table)
- Modify: relevant formula files

**Design — 5 additional alliance technologies (alongside existing Duplicateur):**

| Technology | Effect per level | Cost base |
|------------|-----------------|-----------|
| **Catalyseur** | -2% molecule formation time | 2.0x exponential |
| **Fortification** | +1% building HP | 2.0x exponential |
| **Réseau** | +5% trade point earning | 1.8x exponential |
| **Radar** | -2% neutrino requirement for espionage | 2.5x exponential |
| **Bouclier** | -1% pillage losses when defending | 2.0x exponential |

**New columns:**
```sql
ALTER TABLE alliances ADD COLUMN catalyseur INT DEFAULT 0;
ALTER TABLE alliances ADD COLUMN fortification INT DEFAULT 0;
ALTER TABLE alliances ADD COLUMN reseau INT DEFAULT 0;
ALTER TABLE alliances ADD COLUMN radar INT DEFAULT 0;
ALTER TABLE alliances ADD COLUMN bouclier INT DEFAULT 0;
```

**Step 1: Add research definitions to config**

**Step 2: Add research UI to alliance page**

**Step 3: Apply research bonuses in relevant formulas**

**Step 4: Commit**

```bash
git add alliance.php includes/config.php
git commit -m "feat: add 5 alliance research technologies"
```

---

### Task 18: Add Atom Specialization System

**Files:**
- Create: `includes/specialization.php`
- Modify: `constructions.php` (specialization selection UI)
- Modify: relevant formula files
- Modify: DB schema

**Design — 3 specialization tracks unlocked at building milestones:**

| Track | Unlocked At | Option A | Option B |
|-------|-------------|----------|----------|
| **Combat** | Ionisateur 15 | Oxydant: +10% attack, -5% defense | Réducteur: +10% defense, -5% attack |
| **Economy** | Producteur 20 | Industriel: +20% atoms, -10% energy | Énergétique: +20% energy, -10% atoms |
| **Research** | Condenseur 15 | Théorique: +2 cond pts/level, -20% formation speed | Appliqué: +20% formation speed, -1 cond pt/level |

Irreversible within a round. Resets with season.

**Step 1: Create specialization system**

**Step 2: Add selection UI at milestone**

**Step 3: Apply modifiers in formulas**

**Step 4: Commit**

```bash
git add includes/specialization.php constructions.php includes/config.php
git commit -m "feat: add 3 specialization tracks with irreversible round choices"
```

---

## PHASE 5: Economy & Map Enhancements
**Time estimate: 2-3 sessions | Risk: Medium | Impact: High**
*Add resource nodes, compound synthesis, and seasonal variety.*

---

### Task 19: Add Resource Nodes to Map

**Files:**
- Modify: `carte.php` (map display)
- Modify: `includes/game_resources.php` (resource production with node bonus)
- Modify: `includes/config.php`
- Create: DB migration for `resource_nodes` table

**Design:** 15-25 resource nodes placed randomly on map at round start. Each node:
- Produces one atom type
- Grants +50% production of that atom to the nearest player
- Grants +25% to the second-nearest player
- Visible on map with atom icon

**Step 1: Create resource nodes table and generation logic**

**Step 2: Modify map to display nodes**

**Step 3: Modify atom production to include node bonus**

**Step 4: Commit**

```bash
git add carte.php includes/game_resources.php includes/config.php
git commit -m "feat: add resource nodes to map — strategic positions with production bonuses"
```

---

### Task 20: Add Compound Synthesis (Laboratoire)

**Files:**
- Create: `laboratoire_synthese.php` (synthesis UI)
- Create: `includes/compounds.php` (compound logic)
- Modify: `includes/config.php` (compound recipes)
- Modify: relevant formula files (apply compound effects)
- Create: DB migration for compounds table

**Design — 5 consumable compounds:**

| Compound | Recipe | Effect | Duration |
|----------|--------|--------|----------|
| H₂O (Eau) | 200 H + 100 O | +20% energy production | 4 hours |
| NaCl (Sel) | 100 Cl + 100 S | +15% storage capacity | 4 hours |
| CO₂ | 100 C + 200 O | -10% enemy building HP in attack | 1 attack |
| NH₃ (Ammoniac) | 100 N + 150 H | -20% formation time | 4 hours |
| H₂SO₄ (Acide) | 200 H + 100 S + 400 O | +30% pillage capacity | 1 attack |

**Step 1: Create compound definitions and synthesis logic**

**Step 2: Create synthesis UI page**

**Step 3: Apply compound effects in relevant formulas/combat**

**Step 4: Commit**

```bash
git add laboratoire_synthese.php includes/compounds.php includes/config.php
git commit -m "feat: add compound synthesis — consume atoms for temporary buffs"
```

---

### Task 21: Add Seasonal Modifiers (Round Themes)

**Files:**
- Modify: `includes/config.php`
- Create: `includes/season_theme.php`
- Modify: `cron.php` or round-start logic

**Design — 5 round themes, randomly selected:**

| Theme | Effect |
|-------|--------|
| **Accéléré** | All production +50%, round = 3 weeks |
| **Pénurie** | Storage -30%, starting prices = 2.0 |
| **Guerre** | Attack cost halved, building damage doubled |
| **Diplomatique** | Alliance cap = 30, pacts grant +5% production |
| **Classique** | No modifiers |

**Step 1: Create theme system and storage**

**Step 2: Apply theme modifiers at round start**

**Step 3: Display active theme in game header**

**Step 4: Commit**

```bash
git add includes/season_theme.php includes/config.php
git commit -m "feat: add seasonal round themes — each round plays differently"
```

---

### Task 22: Tune Existing Formula Constants

**Files:**
- Modify: `includes/config.php`
- Modify: `includes/formulas.php`

**Context:** Based on the audit, several constants need adjustment for better balance even without new features.

**Changes:**

| Constant | Old | New | Reason |
|----------|-----|-----|--------|
| `STABILISATEUR_BONUS_PER_LEVEL` | 0.01 | 0.015 | Stabilisateur is overpriced for its effect |
| `IODE_ENERGY_COEFFICIENT` | 0.05 | 0.10 | Iodine is still near-useless even after last buff |
| `DEPOT_PLACES_PER_LEVEL` | 500 | 750 | Reduce overnight overflow punishment |
| Decay `DECAY_ATOM_DIVISOR` | 100 | 150 | Make large molecules slightly more viable |
| Max atom cap per type in molecule | 200 | 200 | Keep (no change — isotopes and reactions add variety instead) |

**Step 1: Update constants in config.php**

**Step 2: Update formulas.php if any formula structure changed**

**Step 3: Commit**

```bash
git add includes/config.php includes/formulas.php
git commit -m "feat: tune formula constants — stabilisateur, iodine, depot, decay"
```

---

## Execution Plan Summary

| Phase | Tasks | Focus | Sessions |
|-------|-------|-------|----------|
| **1** | Tasks 1-6 | Bug fixes, race conditions, exploits | 1-2 |
| **2** | Tasks 7-10 | Combat rebalance, formations, defense | 2-3 |
| **3** | Tasks 11-12 | Points rebalance, prestige system | 1-2 |
| **4** | Tasks 13-18 | Reactions, isotopes, vault, catalysts, alliances, specializations | 3-4 |
| **5** | Tasks 19-22 | Map nodes, compounds, seasonal themes, constant tuning | 2-3 |

**Total: 22 tasks across 5 phases.**

Each phase is independently deployable. Phase 1 should ship immediately (bug fixes). Phase 2 is the highest-impact gameplay change. Phases 3-5 add depth progressively.

---

## Playstyle Viability After Implementation

| Playstyle | Before | After | Key Enablers |
|-----------|--------|-------|-------------|
| **L'Oxydant** (Raider) | Dominant | Viable (not dominant) | Still strong, but formations + cooldowns add risk |
| **Le Gaz Noble** (Defender) | Non-viable | Viable | Defense rewards + formations + vault |
| **Le Solvant** (Trader) | Weak | Viable | Higher trade point cap + alliance research |
| **Le Catalyseur** (Coordinator) | N/A | Viable | Reaction bonuses + shared intel + catalytic isotope |
| **Le Polymère** (Builder) | Dominant | Viable (not dominant) | Still strong, but combat points now matter more |
| **L'Isotope** (Wildcard) | N/A | Viable | Isotope variants + specializations |

---

## Deployment Notes

- **Database migrations:** Each task that adds columns/tables should include a migration script in `migrations/`
- **Backwards compatibility:** All new columns should have sensible defaults so existing player data is not corrupted
- **Testing:** Each combat change should be verified with worked examples before deployment
- **Communication:** Major changes (combat rebalance, formations, prestige) should be announced to players via in-game news (`messagemj.php`)
- **Rollback:** Constants can be reverted instantly. New features can be disabled by setting their constants to neutral values (e.g., `DEFENSE_REWARD_RATIO = 0`)

---

*This plan was generated from comprehensive audits by 4 specialized analysis agents covering: balance formulas, combat/strategy, bugs/exploits, and gameplay design.*
