# V4 Gameplay Balance Overhaul — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Implement the complete V4 mathematical model from the Gemini audit (`docs/game balance audit/TXT.txt`): all 20 systemic flaws + the unified covalent synergy formula engine + exponential economy + asymptotic decay.

**Architecture:** Replace the existing `$CHEMICAL_REACTIONS` system with covalent synergy formulas baked into each stat function. Every atom gets a primary AND secondary role, forcing hybrid builds. Building costs/times go exponential, storage follows. Decay power drops from 2→1.5, stabilisateur becomes asymptotic. All changes preserve existing isotope, specialization, and prestige systems (the V4 audit didn't know about those — we keep them).

**Tech Stack:** PHP 8.2, MariaDB 10.11, existing helpers (dbFetchOne, dbExecute, withTransaction)

**Source document:** `docs/game balance audit/TXT.txt` — Master Game Design Document V4.0

**Audit coverage:**
- #1 Exponential costs → Task 1 + Task 8
- #2 Exponential storage → Task 1 + Task 5
- #3 Coffre-fort % → Task 1 + Task 5 + Task 8
- #4 Iode catalyst → Task 9
- #5 Lieur linear → Task 5
- #6 Duplicateur cost → Task 1
- #7 Market slippage global → Task 3
- #8 Alt-feeding inversion → Task 4
- #9 Sell overflow → Task 2
- #10 Covalent synergies → Task 5 (replaces $CHEMICAL_REACTIONS)
- #11 Min 10 HP → Task 5
- #12 Overkill cascade → Task 6
- #13 Mass critical → REMOVED (reactions replaced by covalent synergies)
- #14 Polynomial building HP → Task 5
- #15 Terror medal → ALREADY FIXED (attaquer.php:18)
- #16 Asymptotic stabilisateur → Task 5
- #17 Neutrino decay → Task 9
- #18 Mass combat points → Task 6
- #19 Unified sqrt ranking → SEPARATE PLAN (Task 12)
- #20 Beginner protection → Task 1

**V4 model coverage (Part II):**
- ECO_GROWTH constants → Task 1
- Building matrix (exponential costs + times) → Task 1 + Task 8
- Covalent synergy formulas (all 7 stat functions) → Task 5
- modCond() extraction → Task 5
- tempsFormation with Iode co-factor → Task 5
- Asymptotic decay (power 1.5 + 0.98^stab) → Task 5
- Exponential placeDepot/drainageProducteur → Task 5
- Polynomial building HP → Task 5
- Iode catalyst (not standalone energy) → Task 9
- Neutrino decay → Task 9

---

## Covalent Synergy Reference Table

Every atom now has a dual role — primary stat + secondary synergy to another:

| Stat | Primary Atom | Secondary Atom | Formula |
|------|-------------|----------------|---------|
| Attack | O (Oxygène) | H (Hydrogène) | `(pow(O, 1.2) + O) * (1 + H/100)` |
| Defense | C (Carbone) | Br (Brome) | `(pow(C, 1.2) + C) * (1 + Br/100)` |
| HP Molecule | Br (Brome) | C (Carbone) | `10 + (pow(Br, 1.2) + Br) * (1 + C/100)` |
| Destruction | H (Hydrogène) | O (Oxygène) | `(pow(H, 1.2) + H) * (1 + O/100)` |
| Pillage | S (Soufre) | Cl (Chlore) | `(pow(S, 1.2) + S) * (1 + Cl/100)` |
| Speed | Cl (Chlore) | N (Azote) | `1 + (Cl * 0.5) + ((Cl * N) / 200)` |
| Formation | N (Azote) | I (Iode) | `(1 + pow(N, 1.1) * (1 + I/200))` |

---

## Batch A: Config & Market Fixes (Safe, No Formula Changes)

### Task 1: V4 Constants Foundation + Config Cleanup

**Audit items:** Foundation for all other tasks. Adds V4 economy constants, updates $BUILDING_CONFIG, removes $CHEMICAL_REACTIONS, updates beginner protection (#20), duplicateur cost (#6).

**Files:**
- Modify: `includes/config.php`

**Step 1: Add V4 economy growth constants**

After the GAME LIMITS section (~line 28), add:

```php
// --- V4 ECONOMIC GROWTH BASES ---
define('ECO_GROWTH_BASE', 1.15); // Standard building cost/storage growth
define('ECO_GROWTH_ADV', 1.20);  // Strategic buildings (champdeforce, ionisateur, condenseur, lieur)
define('ECO_GROWTH_ULT', 1.25);  // Stabilisateur (strong exponential)
```

**Step 2: Add V4 formula constants**

In the MOLECULE STAT FORMULAS section (~line 58), add:

```php
// V4 Covalent synergy: universal condenseur modifier
define('COVALENT_CONDENSEUR_DIVISOR', 50); // modCond = 1 + (nivCond / 50)
// V4 Covalent exponent for all base stat formulas
define('COVALENT_BASE_EXPONENT', 1.2); // pow(atom, 1.2) + atom
// V4 Cross-atom synergy divisor
define('COVALENT_SYNERGY_DIVISOR', 100); // (1 + secondary / 100)
// V4 Minimum HP — prevents 0-brome insta-wipe (audit #11)
define('MOLECULE_MIN_HP', 10);
```

**Step 3: Add V4 decay constants**

In the MOLECULE DECAY section (~line 101), add:

```php
define('STABILISATEUR_ASYMPTOTE', 0.98);  // V4: pow(0.98, level) — never negative
define('DECAY_MASS_EXPONENT', 1.5);       // V4: power 1.5 (was 2) — large molecules last longer
```

**Step 4: Add V4 building HP constants**

In the BUILDING HP section (~line 110), add:

```php
define('BUILDING_HP_POLY_EXP', 2.5);      // V4: polynomial 50 * level^2.5
```

Update base values:
```php
// OLD:
define('BUILDING_HP_BASE', 20);
define('FORCEFIELD_HP_BASE', 50);

// NEW:
define('BUILDING_HP_BASE', 50);     // V4: 50 * level^2.5
define('FORCEFIELD_HP_BASE', 125);  // V4: 125 * level^2.5 (2.5x stronger)
```

**Step 5: Add V4 storage/vault constants**

```php
define('BASE_STORAGE_INITIAL', 1000);      // V4: 1000 * pow(1.15, level)
define('VAULT_PCT_PER_LEVEL', 0.02);       // V4: 2% of depot per vault level
define('VAULT_MAX_PROTECTION_PCT', 0.50);  // V4: cap at 50%
```

**Step 6: Add V4 economy/market constants**

```php
define('MARKET_GLOBAL_ECONOMY_DIVISOR', 10000); // V4: global scale for price impact
define('COMBAT_MASS_DIVISOR', 100);              // V4: divisor for mass-based combat points
define('IODE_CATALYST_DIVISOR', 50000);          // V4: 50k iode atoms = +100% generator
define('IODE_CATALYST_MAX_BONUS', 1.0);          // V4: max +100% bonus from iode
define('LIEUR_LINEAR_BONUS_PER_LEVEL', 0.15);    // V4: linear bonus replaces exponential
```

**Step 7: Update beginner protection (audit #20)**

```php
// OLD (line 26):
define('BEGINNER_PROTECTION_SECONDS', 5 * SECONDS_PER_DAY);
// NEW:
define('BEGINNER_PROTECTION_SECONDS', 3 * SECONDS_PER_DAY); // V4: 3 days (was 5)
```

**Step 8: Update duplicateur cost (audit #6)**

```php
// OLD (lines 384-385):
define('DUPLICATEUR_BASE_COST', 10);
define('DUPLICATEUR_COST_FACTOR', 2.0);
// NEW:
define('DUPLICATEUR_BASE_COST', 100);  // V4: smoother curve
define('DUPLICATEUR_COST_FACTOR', 1.5); // V4: 100 * 1.5^level (was 10 * 2.0^level)
```

**Step 9: Update $BUILDING_CONFIG to V4 model**

Replace the entire `$BUILDING_CONFIG` array (lines 127-234) with the V4 version. Key changes:
- Replace individual `cost_energy_exp`/`cost_atoms_exp` with unified `cost_growth_base`
- Replace polynomial `time_exp` with V4 exponential time (implicit `1.10^level`)
- Keep points_base, points_level_factor, descriptions, and special fields (bonus_per_level, etc.)

```php
$BUILDING_CONFIG = [
    'generateur' => [
        'cost_energy_base'  => 50,
        'cost_atoms_base'   => 75,
        'cost_growth_base'  => ECO_GROWTH_BASE,
        'time_base'         => 60,
        'time_growth_base'  => 1.10,  // V4: time = time_base * 1.10^level
        'time_level1'       => 10,
        'points_base'       => 1,
        'points_level_factor' => 0.1,
        'description'       => 'Generates energy',
    ],
    'producteur' => [
        'cost_energy_base'  => 75,
        'cost_atoms_base'   => 50,
        'cost_growth_base'  => ECO_GROWTH_BASE,
        'time_base'         => 40,
        'time_growth_base'  => 1.10,
        'time_level1'       => 10,
        'points_base'       => 1,
        'points_level_factor' => 0.1,
        'points_per_level'  => 8,
        'description'       => 'Produces atoms (drains energy)',
    ],
    'depot' => [
        'cost_energy_base'  => 100,
        'cost_atoms_base'   => 0,
        'cost_growth_base'  => ECO_GROWTH_BASE,
        'time_base'         => 80,
        'time_growth_base'  => 1.10,
        'points_base'       => 1,
        'points_level_factor' => 0.1,
        'description'       => 'Increases max resource storage',
    ],
    'champdeforce' => [
        'cost_carbone_base' => 100,
        'cost_growth_base'  => ECO_GROWTH_ADV,
        'time_base'         => 120,
        'time_growth_base'  => 1.10,
        'points_base'       => 1,
        'points_level_factor' => 0.075,
        'bonus_per_level'   => 2,
        'description'       => 'Provides defense bonus, absorbs damage first if highest level',
    ],
    'ionisateur' => [
        'cost_oxygene_base' => 100,
        'cost_growth_base'  => ECO_GROWTH_ADV,
        'time_base'         => 120,
        'time_growth_base'  => 1.10,
        'points_base'       => 1,
        'points_level_factor' => 0.075,
        'bonus_per_level'   => 2,
        'description'       => 'Provides attack bonus',
    ],
    'condenseur' => [
        'cost_energy_base'  => 25,
        'cost_atoms_base'   => 100,
        'cost_growth_base'  => ECO_GROWTH_ADV,
        'time_base'         => 150,
        'time_growth_base'  => 1.10,
        'points_base'       => 2,
        'points_level_factor' => 0.1,
        'points_per_level'  => 5,
        'description'       => 'Improves atom effectiveness via levels',
    ],
    'lieur' => [
        'cost_azote_base'   => 100,
        'cost_growth_base'  => ECO_GROWTH_ADV,
        'time_base'         => 150,
        'time_growth_base'  => 1.10,
        'points_base'       => 2,
        'points_level_factor' => 0.1,
        'description'       => 'Reduces molecule formation time',
    ],
    'stabilisateur' => [
        'cost_atoms_base'   => 75,
        'cost_growth_base'  => ECO_GROWTH_ULT,
        'time_base'         => 180,
        'time_growth_base'  => 1.10,
        'points_base'       => 3,
        'points_level_factor' => 0.1,
        'stability_per_level' => 1.5,
        'description'       => 'Reduces molecule decay rate',
    ],
    'coffrefort' => [
        'cost_energy_base'  => 150,
        'cost_atoms_base'   => 0,
        'cost_growth_base'  => ECO_GROWTH_BASE,
        'time_base'         => 90,
        'time_growth_base'  => 1.10,
        'points_base'       => 1,
        'points_level_factor' => 0.1,
        'protection_per_level' => 100, // legacy — now using VAULT_PCT_PER_LEVEL
        'description'       => 'Protects resources from pillage',
    ],
];
```

**Step 10: Remove $CHEMICAL_REACTIONS**

Delete the entire `$CHEMICAL_REACTIONS` array (lines 320-351) and its section header comment.

**Step 11: Commit**

```bash
git add includes/config.php
git commit -m "balance: V4 config foundation — growth constants, building matrix, remove chemical reactions"
```

---

### Task 2: Sell Overflow Fix

**Audit item:** #9 — Selling atoms when energy is nearly full deducts all atoms but caps energy, destroying value.

**Files:**
- Modify: `marche.php:273-330`

**Step 1: Read marche.php sell section**

Read `marche.php` lines 258-357 to understand current sell logic.

**Step 2: Calculate max sellable quantity**

Inside the sell transaction (after the `FOR UPDATE` re-read), add a max-sellable calculation:

```php
// V4: Overflow fix — only sell atoms that fit in remaining energy space
$energySpace = $placeDepot - $locked['energie'];
if ($energySpace <= 0) {
    throw new Exception('ENERGY_FULL');
}
$maxSellable = floor($energySpace / ($tabCours[$numRes] * MARKET_SELL_TAX_RATE));
$actualSold = min($_POST['nombreRessourceAVendre'], $maxSellable, $locked['res']);
if ($actualSold <= 0) {
    throw new Exception('ENERGY_FULL');
}
```

Then use `$actualSold` everywhere instead of `$_POST['nombreRessourceAVendre']`:
- Resource subtraction: `$newResVal = $locked['res'] - $actualSold;`
- Energy gain: `$energyGained = round($tabCours[$numRes] * $actualSold * MARKET_SELL_TAX_RATE);`
- Price impact formula
- Success message

**Step 3: Add exception handler**

```php
} elseif ($e->getMessage() === 'ENERGY_FULL') {
    $erreur = "Votre stockage d'énergie est plein.";
}
```

**Step 4: Commit**

```bash
git add marche.php
git commit -m "balance: sell overflow fix — only deduct atoms matching energy space (audit #9)"
```

---

### Task 3: Market Slippage Based on Global Economy

**Audit item:** #7 — Price impact based on buyer's storage lets rich players manipulate prices.

**Files:**
- Modify: `marche.php` — buy and sell price impact formulas

**Step 1: Read marche.php buy and sell price impact**

Find all `$volatilite` price impact calculations (in both buy and sell sections).

**Step 2: Replace `$placeDepot` with `MARKET_GLOBAL_ECONOMY_DIVISOR`**

In both buy and sell price impact formulas, change:
```php
// OLD:
$ajout = 1 / (1 / $tabCours[$num] + $volatilite * $quantite / $placeDepot);
// NEW:
$ajout = 1 / (1 / $tabCours[$num] + $volatilite * $quantite / MARKET_GLOBAL_ECONOMY_DIVISOR);
```

Apply to both buy and sell sections.

**Step 3: Commit**

```bash
git add marche.php
git commit -m "balance: market slippage uses global economy divisor (audit #7)"
```

---

### Task 4: Reverse Alt-Feeding Transfer Ratio

**Audit item:** #8 — Current ratio punishes big→small transfers but allows small alt→big main at 100%.

**Files:**
- Modify: `marche.php:62-75`

**Step 1: Read current transfer ratio code**

Read `marche.php` lines 55-80 to understand current ratio logic.

**Step 2: Invert energy ratio**

```php
// OLD (punishes big→small, allows alt→main):
if ($revenuEnergie >= revenuEnergie($constructionsJoueur['generateur'], $_POST['destinataire'])) {
    $rapportEnergie = revenuEnergie($constructionsJoueur['generateur'], $_POST['destinataire']) / $revenuEnergie;
} else {
    $rapportEnergie = 1;
}

// NEW (punishes alt→main, allows big→small):
$receiverEnergyRev = revenuEnergie($constructionsJoueur['generateur'], $_POST['destinataire']);
if ($receiverEnergyRev > $revenuEnergie) {
    $rapportEnergie = min(1.0, $revenuEnergie / max(1, $receiverEnergyRev));
} else {
    $rapportEnergie = 1;
}
```

**Step 3: Invert atom ratios**

Same inversion for all 8 atom ratios:
```php
// OLD:
foreach ($nomsRes as $num => $ressource) {
    if ($revenu[$ressource] >= revenuAtome($num, $_POST['destinataire'])) {
        ${'rapport' . $ressource} = revenuAtome($num, $_POST['destinataire']) / $revenu[$ressource];
    } else {
        ${'rapport' . $ressource} = 1;
    }
}

// NEW:
foreach ($nomsRes as $num => $ressource) {
    $receiverAtomRev = revenuAtome($num, $_POST['destinataire']);
    if ($receiverAtomRev > $revenu[$ressource]) {
        ${'rapport' . $ressource} = min(1.0, $revenu[$ressource] / max(1, $receiverAtomRev));
    } else {
        ${'rapport' . $ressource} = 1;
    }
}
```

**Step 4: Commit**

```bash
git add marche.php
git commit -m "balance: invert transfer ratio to penalize alt-feeding (audit #8)"
```

---

## Batch B: V4 Formula Engine Rewrite

**CRITICAL:** Tasks 5-9 form a unit. After Task 5, all formula callers break until Tasks 6-9 update them. Do NOT deploy between Task 5 and Task 9.

### Task 5: Rewrite formulas.php — V4 Covalent Synergies + All Formula Changes

**Audit items:** #10 (covalent synergies), #11 (min HP), #5 (lieur linear), #16 (asymptotic stab), #14 (polynomial building HP), #1/#2 (exponential storage/drain), V4 model (formula scaling, modCond, decay power 1.5)

**Files:**
- Modify: `includes/formulas.php` — near-complete rewrite of formula functions

**Important context:**
- Current stat formulas (attaque, defense, etc.) use single-atom quadratic `(COEF * atom)^2 + atom` with DB lookups for medals.
- V4 formulas use cross-atom covalent synergies `pow(atom, 1.2) + atom) * (1 + secondary / 100)` and take pre-computed medal bonus as parameter (pure functions).
- We KEEP: isotope modifiers (applied by callers in combat.php), specializations, prestige, weekly catalyst effects.
- We REMOVE: internal DB lookups from stat formulas. Callers will pre-compute medal bonuses.
- **CATALYST MIGRATION:** Current `pillage()` calls `catalystEffect('pillage_bonus')` internally. When `pillage()` becomes pure, this must move to callers (combat.php applies it after calling `pillage()`). Same pattern as `catalystEffect('attack_bonus')` already handled in combat.php:218.

**Step 1: Add modCond helper function**

After `bonusDuplicateur()` (~line 72), add:

```php
/**
 * V4: Universal condenseur modifier.
 * Replaces individual LEVEL_DIVISOR constants.
 */
function modCond($niveauCondenseur)
{
    return 1 + ($niveauCondenseur / COVALENT_CONDENSEUR_DIVISOR);
}
```

**Step 2: Rewrite attaque() — O + H covalent synergy**

Replace the current `attaque()` function (lines 79-98) with:

```php
/**
 * V4 Attack: Oxygène powered by Hydrogène.
 * Pure function — caller provides pre-computed medal bonus.
 *
 * @param int $O Oxygene atom count
 * @param int $H Hydrogene atom count (covalent synergy)
 * @param int $nivCondO Condenseur level for Oxygene
 * @param float $bonusMedaille Medal bonus percentage (0-50)
 * @return int Attack value
 */
function attaque($O, $H, $nivCondO, $bonusMedaille = 0)
{
    $base = (pow($O, COVALENT_BASE_EXPONENT) + $O) * (1 + $H / COVALENT_SYNERGY_DIVISOR);
    return round($base * modCond($nivCondO) * (1 + $bonusMedaille / 100));
}
```

**Step 3: Rewrite defense() — C + Br covalent synergy**

Replace the current `defense()` function (lines 100-118) with:

```php
/**
 * V4 Defense: Carbone softened by Brome.
 */
function defense($C, $Br, $nivCondC, $bonusMedaille = 0)
{
    $base = (pow($C, COVALENT_BASE_EXPONENT) + $C) * (1 + $Br / COVALENT_SYNERGY_DIVISOR);
    return round($base * modCond($nivCondC) * (1 + $bonusMedaille / 100));
}
```

**Step 4: Rewrite pointsDeVieMolecule() — Br + C + min 10 HP**

Replace current (lines 121-124):

```php
/**
 * V4 HP: Brome protected by Carbone. Min 10 HP prevents 0-brome insta-wipe.
 */
function pointsDeVieMolecule($Br, $C, $nivCondBr)
{
    $base = MOLECULE_MIN_HP + (pow($Br, COVALENT_BASE_EXPONENT) + $Br) * (1 + $C / COVALENT_SYNERGY_DIVISOR);
    return round($base * modCond($nivCondBr));
}
```

**Step 5: Rewrite potentielDestruction() — H + O**

Replace current (lines 126-129):

```php
/**
 * V4 Destruction: Hydrogène powered by Oxygène.
 */
function potentielDestruction($H, $O, $nivCondH)
{
    $base = (pow($H, COVALENT_BASE_EXPONENT) + $H) * (1 + $O / COVALENT_SYNERGY_DIVISOR);
    return round($base * modCond($nivCondH));
}
```

**Step 6: Rewrite pillage() — S + Cl**

Replace current (lines 131-151):

```php
/**
 * V4 Pillage: Soufre secured by Chlore.
 * Pure function — caller provides pre-computed medal bonus.
 */
function pillage($S, $Cl, $nivCondS, $bonusMedaille = 0)
{
    $base = (pow($S, COVALENT_BASE_EXPONENT) + $S) * (1 + $Cl / COVALENT_SYNERGY_DIVISOR);
    return round($base * modCond($nivCondS) * (1 + $bonusMedaille / 100));
}
```

**Step 7: Rewrite vitesse() — Cl + N**

Replace current (lines 162-165):

```php
/**
 * V4 Speed: Chlore powered by Azote.
 */
function vitesse($Cl, $N, $nivCondCl)
{
    $base = 1 + ($Cl * 0.5) + (($Cl * $N) / 200);
    return max(1.0, floor($base * modCond($nivCondCl) * 100) / 100);
}
```

**Step 8: Rewrite productionEnergieMolecule() — display only**

Replace current (lines 153-160). Iode becomes a catalyst (handled in game_resources.php), so this function becomes a display helper:

```php
/**
 * V4: Iode is now a catalyst (see game_resources.php).
 * This function shows iode atom count as contribution indicator for display.
 */
function productionEnergieMolecule($iode, $niveau)
{
    return round($iode); // Raw iode atoms as display indicator
}
```

**Step 9: Rewrite bonusLieur() — linear**

Replace current (lines 167-170):

```php
/**
 * V4 Lieur: Linear bonus instead of exponential.
 * Divides formation time by this value.
 */
function bonusLieur($niveau)
{
    return 1 + $niveau * LIEUR_LINEAR_BONUS_PER_LEVEL;
}
```

**Step 10: Rewrite tempsFormation() — N + I covalent + linear lieur**

Replace current (lines 172-179):

```php
/**
 * V4 Formation: Azote powered by Iode co-factor.
 * Takes pre-computed lieur level instead of doing DB lookup.
 *
 * @param int $ntotal Total atoms in molecule recipe
 * @param int $azote Azote atom count
 * @param int $iode Iode atom count (V4 co-factor)
 * @param int $nivCondN Condenseur level for Azote
 * @param int $nivLieur Lieur building level
 * @param string|null $joueur Player login (for alliance/catalyst bonuses, null for raw calc)
 * @return float Formation time in hours
 */
function tempsFormation($ntotal, $azote, $iode, $nivCondN, $nivLieur, $joueur = null)
{
    $bonus_lieur = bonusLieur($nivLieur);
    $vitesse_form = (1 + pow($azote, 1.1) * (1 + $iode / 200)) * modCond($nivCondN) * $bonus_lieur;

    if ($joueur !== null) {
        $catalystSpeedBonus = 1 + catalystEffect('formation_speed');
        $allianceCatalyseurBonus = 1 + allianceResearchBonus($joueur, 'formation_speed');
        $vitesse_form *= $catalystSpeedBonus * $allianceCatalyseurBonus;
    }

    return ceil(($ntotal / $vitesse_form) * 100) / 100;
}
```

**Step 11: Rewrite coefDisparition() — asymptotic stab + decay power 1.5**

Replace the decay formula inside `coefDisparition()` (line 216). Keep the existing function signature, caching, DB lookups, and isotope/catalyst modifiers. Only change the math:

```php
// OLD (line 216):
$baseDecay = pow(pow(DECAY_BASE, pow(1 + $nbAtomes / DECAY_ATOM_DIVISOR, 2) / DECAY_POWER_DIVISOR), (1 - ($bonus / 100)) * (1 - ($stabilisateur['stabilisateur'] * STABILISATEUR_BONUS_PER_LEVEL)));

// NEW — V4: decay power 1.5 + asymptotic stabilisateur:
$rawDecay = pow(DECAY_BASE, pow(1 + $nbAtomes / DECAY_ATOM_DIVISOR, DECAY_MASS_EXPONENT) / DECAY_POWER_DIVISOR);
$modStab = pow(STABILISATEUR_ASYMPTOTE, $stabilisateur['stabilisateur']); // 0.98^level, never negative
$modMedal = 1 - ($bonus / 100);
$baseDecay = pow($rawDecay, $modStab * $modMedal);
```

Keep ALL the isotope and catalyst modifier code that follows unchanged.

**Step 12: Rewrite placeDepot() — exponential**

Replace current (lines 274-277):

```php
/**
 * V4: Exponential storage to match exponential costs.
 */
function placeDepot($niveau)
{
    return round(BASE_STORAGE_INITIAL * pow(ECO_GROWTH_BASE, $niveau));
}
```

**Step 13: Rewrite drainageProducteur() — exponential**

Replace current (lines 74-77):

```php
/**
 * V4: Exponential drain to match exponential production scaling.
 */
function drainageProducteur($niveau)
{
    return round(PRODUCTEUR_DRAIN_PER_LEVEL * pow(ECO_GROWTH_BASE, $niveau));
}
```

**Step 14: Rewrite pointsDeVie() and vieChampDeForce() — polynomial**

Replace current (lines 249-267):

```php
/**
 * V4: Polynomial building HP = BASE * level^2.5 (destructible but tough)
 */
function pointsDeVie($niveau, $joueur = null)
{
    $base_hp = round(BUILDING_HP_BASE * pow(max(1, $niveau), BUILDING_HP_POLY_EXP));
    if ($joueur !== null) {
        $fortBonus = 1 + allianceResearchBonus($joueur, 'building_hp');
        return round($base_hp * $fortBonus);
    }
    return $base_hp;
}

function vieChampDeForce($niveau, $joueur = null)
{
    $base_hp = round(FORCEFIELD_HP_BASE * pow(max(1, $niveau), BUILDING_HP_POLY_EXP));
    if ($joueur !== null) {
        $fortBonus = 1 + allianceResearchBonus($joueur, 'building_hp');
        return round($base_hp * $fortBonus);
    }
    return $base_hp;
}
```

**Step 15: Add capaciteCoffreFort() function**

After `placeDepot()`, add:

```php
/**
 * V4: Vault protects a percentage of depot capacity.
 */
function capaciteCoffreFort($nivCoffre, $nivDepot)
{
    $pct = min(VAULT_MAX_PROTECTION_PCT, $nivCoffre * VAULT_PCT_PER_LEVEL);
    return round(placeDepot($nivDepot) * $pct);
}
```

**Step 16: Commit**

```bash
git add includes/formulas.php
git commit -m "balance: V4 formula engine — covalent synergies, polynomial HP, exponential economy, asymptotic decay"
```

---

### Task 6: Update combat.php — Remove Reactions + Overkill Cascade + Mass Combat Points

**Audit items:** #10 (remove reactions — replaced by covalent synergies), #12 (overkill cascade), #18 (mass combat points), #3 (vault %)

**Files:**
- Modify: `includes/combat.php`

**Caller map for combat.php formula calls:**
- `attaque()` — line ~222 (4 classes attacker + 4 classes defender damage calc)
- `defense()` — line ~228 (same loop)
- `pointsDeVieMolecule()` — lines ~248, 256, 272, 310 (casualty calculations)
- `potentielDestruction()` — lines ~440-443, 465, 473, 482 (building damage)
- `pillage()` — lines ~346, 407-410 (pillage calculations)
- `vitesse()` — not directly in combat.php (handled in attaquer.php)
- Vault protection — line ~397

**Step 1: Read combat.php fully to understand current structure**

Read all of `includes/combat.php`.

**Step 2: Pre-compute medal bonuses at the top**

After the condenseur/ionisateur lookups (~line 60), add medal bonus pre-computation:

```php
// V4: Pre-compute medal bonuses for pure stat functions
$attMedalData = dbFetchOne($base, 'SELECT pointsAttaque, pointsDefense, ressourcesPillees FROM autre WHERE login=?', 's', $actions['attaquant']);
$defMedalData = dbFetchOne($base, 'SELECT pointsAttaque, pointsDefense, ressourcesPillees FROM autre WHERE login=?', 's', $actions['defenseur']);

// Compute attack medal bonus
$bonusAttaqueMedaille = 0;
foreach ($paliersAttaque as $num => $palier) {
    if ($attMedalData['pointsAttaque'] >= $palier) $bonusAttaqueMedaille = $bonusMedailles[$num];
}
$bonusAttaqueMedaille = min($bonusAttaqueMedaille, MAX_CROSS_SEASON_MEDAL_BONUS);

// Compute defense medal bonus
$bonusDefenseMedaille = 0;
foreach ($paliersDefense as $num => $palier) {
    if ($defMedalData['pointsDefense'] >= $palier) $bonusDefenseMedaille = $bonusMedailles[$num];
}
$bonusDefenseMedaille = min($bonusDefenseMedaille, MAX_CROSS_SEASON_MEDAL_BONUS);

// Compute pillage medal bonus
$bonusPillageMedaille = 0;
foreach ($paliersPillage as $num => $palier) {
    if ($attMedalData['ressourcesPillees'] >= $palier) $bonusPillageMedaille = $bonusMedailles[$num];
}
$bonusPillageMedaille = min($bonusPillageMedaille, MAX_CROSS_SEASON_MEDAL_BONUS);
```

**Step 3: Remove chemical reactions system**

Delete:
- The `checkReactions` function definition (lines ~147-169)
- The class arrays for reaction checking (lines ~172-179)
- The `checkReactions()` calls (line ~178-179)
- All reaction bonus multiplier variables and their application (lines ~182-197)

Remove variables: `$attReactionAttackBonus`, `$attReactionHpBonus`, `$attReactionPillageBonus`, `$defReactionDefenseBonus`, `$defReactionHpBonus`, `$activeReactionsAtt`, `$activeReactionsDef`.

**Step 4: Update all stat formula calls to V4 signatures**

For each class in the damage calculation loop, update:

```php
// OLD: attaque($classeAttaquant['oxygene'], $niveauxAtt['oxygene'], $actions['attaquant'], $medalDataAtt)
// NEW: attaque($classeAttaquant['oxygene'], $classeAttaquant['hydrogene'], $niveauxAtt['oxygene'], $bonusAttaqueMedaille)

// OLD: defense($classeDefenseur['carbone'], $niveauxDef['carbone'], $actions['defenseur'], $medalDataDef)
// NEW: defense($classeDefenseur['carbone'], $classeDefenseur['brome'], $niveauxDef['carbone'], $bonusDefenseMedaille)

// OLD: pointsDeVieMolecule($classe['brome'], $niveaux['brome'])
// NEW: pointsDeVieMolecule($classe['brome'], $classe['carbone'], $niveaux['brome'])

// OLD: potentielDestruction($classe['hydrogene'], $niveaux['hydrogene'])
// NEW: potentielDestruction($classe['hydrogene'], $classe['oxygene'], $niveaux['hydrogene'])

// OLD: pillage($classe['soufre'], $niveaux['soufre'], $joueur, $medalData)
// NEW: pillage($classe['soufre'], $classe['chlore'], $niveaux['soufre'], $bonusPillageMedaille)
```

Remove all reaction bonus multipliers from damage calculations (e.g., remove `* $attReactionAttackBonus`, `* $defReactionDefenseBonus`, `* $attReactionHpBonus`, `* $defReactionHpBonus`).

Keep isotope modifiers (`$attIsotopeAttackMod[$c]`, `$attIsotopeHpMod[$c]`, etc.) — these are NOT removed.

**Step 5: Implement overkill cascade for attacker casualties**

Replace the proportional attacker casualty calculation (lines ~253-267) with:

```php
// V4: OVERKILL CASCADE — surplus damage carries to next class
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
```

**Step 6: Implement overkill cascade for defender casualties (formation-aware)**

Replace defender casualty section (lines ~270-319) with formation-aware cascade:
- **Phalange:** Class 1 absorbs `FORMATION_PHALANX_ABSORB` of damage with defense bonus. If wiped, overkill carries to remaining classes.
- **Dispersée:** Equal split across active classes, overkill cascades within.
- **Embuscade/default:** Straight cascade through all classes.

See the detailed implementation in the audit Part III section A (combat.php cascade code). Adapt to use V4 `pointsDeVieMolecule($Br, $C, $nivCondBr)` calls.

**Step 7: Implement mass-based combat points**

After casualty calculations, replace molecule-count points with mass-based:

```php
// V4: Points based on mass destroyed, not molecule count
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
```

**Step 8: Migrate catalystEffect('pillage_bonus') from pillage() to combat.php**

The weekly Volatilité catalyst gives +25% pillage. Currently this is inside `pillage()` but V4 makes it pure. Apply it in combat.php after calling `pillage()`:

```php
// After computing pillage for each class, apply weekly catalyst:
$catalystPillageBonus = 1 + catalystEffect('pillage_bonus');
// Multiply pillage capacity by catalyst bonus
$capacitePillage *= $catalystPillageBonus;
```

Note: `catalystEffect('attack_bonus')` is already applied in combat.php:218 — keep that as-is.

**Step 9: Update vault protection to percentage-based**

```php
// OLD:
$vaultProtection = VAULT_PROTECTION_PER_LEVEL * $vaultLevel;

// NEW:
$depotDefLevel = $constructionsDef['depot'] ?? 1;
$vaultProtection = capaciteCoffreFort($vaultLevel, $depotDefLevel);
```

**Step 10: Commit**

```bash
git add includes/combat.php
git commit -m "balance: V4 combat — remove reactions, overkill cascade, mass points, vault % (audit #10,#12,#18,#3)"
```

---

### Task 7: Update Display & Action Callers

**Files:**
- Modify: `molecule.php` — stat display (~lines 39-47)
- Modify: `includes/tout.php` — stat summary (~lines 80-87)
- Modify: `api.php` — API stat endpoints (~lines 57-78)
- Modify: `includes/basicprivatehtml.php` — storage displays (~lines 38, 60, 438, 442, 453, 457)
- Modify: `attaquer.php` — vitesse calls (~lines 131, 476)
- Modify: `armee.php` — tempsFormation call (~line 134)
- Modify: `includes/game_actions.php` — pointsDeVie/vieChampDeForce calls (~lines 406, 413, 419, 425)

**Step 1: Read all affected files**

Read each file to find exact call locations.

**Step 2: Update molecule.php stat display calls**

Currently (~line 39-47):
```php
attaque($molecule['oxygene'], $niveauCondenseur['oxygene'], $joueur)
defense($molecule['carbone'], $niveauCondenseur['carbone'], $joueur)
pointsDeVieMolecule($molecule['brome'], $niveauCondenseur['brome'])
potentielDestruction($molecule['hydrogene'], $niveauCondenseur['hydrogene'])
pillage($molecule['soufre'], $niveauCondenseur['soufre'], $joueur)
vitesse($molecule['chlore'], $niveauCondenseur['chlore'])
tempsFormation($molecule['azote'], $niveauCondenseur['azote'], $ntotal, $joueur)
```

Change to V4 signatures. Pre-compute medal bonuses once at top of molecule display:
```php
// Pre-compute medal bonuses for display
$medalData = dbFetchOne($base, 'SELECT pointsAttaque, pointsDefense, ressourcesPillees FROM autre WHERE login=?', 's', $joueur);
$bonusAttaque = computeMedalBonus($medalData['pointsAttaque'], $paliersAttaque, $bonusMedailles);
$bonusDefense = computeMedalBonus($medalData['pointsDefense'], $paliersDefense, $bonusMedailles);
$bonusPillage = computeMedalBonus($medalData['ressourcesPillees'], $paliersPillage, $bonusMedailles);
$constructions = dbFetchOne($base, 'SELECT lieur FROM constructions WHERE login=?', 's', $joueur);
```

Then update each call:
```php
attaque($molecule['oxygene'], $molecule['hydrogene'], $niveauCondenseur['oxygene'], $bonusAttaque)
defense($molecule['carbone'], $molecule['brome'], $niveauCondenseur['carbone'], $bonusDefense)
pointsDeVieMolecule($molecule['brome'], $molecule['carbone'], $niveauCondenseur['brome'])
potentielDestruction($molecule['hydrogene'], $molecule['oxygene'], $niveauCondenseur['hydrogene'])
pillage($molecule['soufre'], $molecule['chlore'], $niveauCondenseur['soufre'], $bonusPillage)
vitesse($molecule['chlore'], $molecule['azote'], $niveauCondenseur['chlore'])
tempsFormation($ntotal, $molecule['azote'], $molecule['iode'], $niveauCondenseur['azote'], $constructions['lieur'], $joueur)
```

**Step 3: Add computeMedalBonus helper**

Add a small helper function to avoid code duplication across display callers. Put it in `includes/formulas.php` or at the top of each file:

```php
function computeMedalBonus($points, $paliers, $bonusMedailles) {
    $bonus = 0;
    foreach ($paliers as $num => $palier) {
        if ($points >= $palier) $bonus = $bonusMedailles[$num];
    }
    return min($bonus, MAX_CROSS_SEASON_MEDAL_BONUS);
}
```

This helper should go in `includes/formulas.php` since it's used by multiple files.

**Step 4: Update tout.php the same way**

Same pattern as molecule.php. Pre-compute medal bonuses, update all stat calls.

**Step 5: Update api.php the same way**

Same pattern. Pre-compute medal bonuses at top of stats endpoint.

**Step 6: Update attaquer.php vitesse calls**

```php
// OLD (~line 131):
vitesse($molecule['chlore'], $niveauCondenseur['chlore'])
// NEW:
vitesse($molecule['chlore'], $molecule['azote'], $niveauCondenseur['chlore'])
```

Same for the JS display vitesse call (~line 476).

**Step 7: Update armee.php tempsFormation call**

```php
// OLD (~line 134):
tempsFormation($molecule['azote'], $niveauCondenseur['azote'], $ntotal, $joueur)
// NEW:
$constructions = dbFetchOne($base, 'SELECT lieur FROM constructions WHERE login=?', 's', $joueur);
tempsFormation($ntotal, $molecule['azote'], $molecule['iode'], $niveauCondenseur['azote'], $constructions['lieur'], $joueur)
```

**Step 8: Update game_actions.php — pointsDeVie/vieChampDeForce calls**

These functions kept their signature, so only verify they still work. The building HP values will change (exponential → polynomial) but the function interface is the same.

**Step 9: Update basicprivatehtml.php — placeDepot calls**

`placeDepot()` kept its signature, so no caller changes needed. But verify that the exponential values display correctly.

**Step 10: Commit**

```bash
git add molecule.php includes/tout.php api.php attaquer.php armee.php includes/game_actions.php includes/basicprivatehtml.php includes/formulas.php
git commit -m "balance: V4 caller updates — all stat formula calls use covalent signatures"
```

---

### Task 8: Update player.php — Exponential Costs + Times + Displays

**Audit items:** #1 (exponential costs), V4 model (exponential times), #3 (vault display), #5 (lieur display), #16 (stab display)

**Files:**
- Modify: `includes/player.php`

**Caller map for player.php:**
- `placeDepot()` — lines ~166, 363 (storage checks)
- `drainageProducteur()` — line ~349 (energy drain display)
- `pointsDeVie()` — lines ~47, 332, 353, 370, 522, 603, 924, 958
- `vieChampDeForce()` — lines ~48, 388, 520, 601, 924
- `bonusLieur()` — lines ~430-431 (lieur display)
- Building cost calculations — lines ~316-470 (ALL 9 buildings)

**Step 1: Read player.php fully**

Read `includes/player.php` to find all building cost/time/display code.

**Step 2: Update building cost formulas — exponential**

Every building's `coutEnergie` and `coutAtomes` calculation changes from polynomial to exponential:

```php
// OLD pattern:
round((1 - ($bonus / 100)) * $BUILDING_CONFIG['X']['cost_energy_base'] * pow($niveau, $BUILDING_CONFIG['X']['cost_energy_exp']))

// NEW pattern (V4):
round((1 - ($bonus / 100)) * $BUILDING_CONFIG['X']['cost_energy_base'] * pow($BUILDING_CONFIG['X']['cost_growth_base'], $niveau))
```

Apply to ALL 9 buildings. For atom-specific costs (champdeforce uses carbone, ionisateur uses oxygene, lieur uses azote), the same pattern applies with `cost_carbone_base`, `cost_oxygene_base`, `cost_azote_base`.

**Step 3: Update building time formulas — exponential**

```php
// OLD pattern:
round($BUILDING_CONFIG['X']['time_base'] * pow($niveau + $offset, $BUILDING_CONFIG['X']['time_exp']))

// NEW pattern (V4):
round($BUILDING_CONFIG['X']['time_base'] * pow($BUILDING_CONFIG['X']['time_growth_base'], $niveau))
```

Keep `time_level1` special case for generateur/producteur level 1.

**Step 4: Update stabilisateur display**

```php
// OLD:
'revenu' => ... $BUILDING_CONFIG['stabilisateur']['stability_per_level'] * level ...
// NEW:
'revenu' => round((1 - pow(STABILISATEUR_ASYMPTOTE, $constructions['stabilisateur'])) * 100, 1) . '% de réduction ...'
'revenu1' => round((1 - pow(STABILISATEUR_ASYMPTOTE, $niveauActuelStab + 1)) * 100, 1) . '% de réduction ...'
```

**Step 5: Update lieur display**

```php
// NEW:
'revenu' => chip('-' . round((1 - 1/bonusLieur($constructions['lieur'])) * 100) . '%', ...),
'revenu1' => chip('-' . round((1 - 1/bonusLieur($niveauActuel + 1)) * 100) . '%', ...),
```

**Step 6: Update coffrefort display**

```php
// NEW:
'revenu' => number_format(capaciteCoffreFort($constructions['coffrefort'] ?? 0, $constructions['depot'])) . ' atomes protégés (' . min(50, ($constructions['coffrefort'] ?? 0) * 2) . '% du stockage)',
'revenu1' => number_format(capaciteCoffreFort($niveauActuel + 1, $constructions['depot'])) . ' atomes protégés (' . min(50, ($niveauActuel + 1) * 2) . '% du stockage)',
```

**Step 7: Commit**

```bash
git add includes/player.php
git commit -m "balance: V4 building costs exponential, times exponential, updated displays"
```

---

### Task 9: Update game_resources.php — Iode Catalyst + Neutrino Decay

**Audit items:** #4 (iode catalyst), #17 (neutrino decay)

**Files:**
- Modify: `includes/game_resources.php`

**Step 1: Read game_resources.php**

Read the file, focusing on `revenuEnergie()` and `updateRessources()`.

**Step 2: Change iode from standalone producer to generator catalyst**

In `revenuEnergie()`, change the iode calculation:

```php
// OLD: iode produces energy independently via productionEnergieMolecule()
$totalIode = 0;
for ($i = 1; $i <= 4; $i++) {
    $molecules = dbFetchOne(...);
    $totalIode += productionEnergieMolecule($molecules['iode'], $niveauiode) * $molecules['nombre'];
}
// ... $prodIode = $prodBase + $totalIode;

// NEW: iode gives a multiplicative bonus to generator output
$totalIodeAtoms = 0;
for ($i = 1; $i <= 4; $i++) {
    $molecules = dbFetchOne($base, 'SELECT iode, nombre FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $joueur, $i);
    $totalIodeAtoms += $molecules['iode'] * $molecules['nombre'];
}
$iodeCatalystBonus = 1.0 + min(IODE_CATALYST_MAX_BONUS, $totalIodeAtoms / IODE_CATALYST_DIVISOR);
// Apply: $prodTotal = $prodBase * $iodeCatalystBonus * otherBonuses;
```

Update the detail display modes to show the catalyst multiplier.

**Step 3: Add neutrino decay**

In `updateRessources()`, after the molecule decay section (~line 202), add:

```php
// V4: Neutrino decay — treated as mass-1 molecule
$neutrinoData = dbFetchOne($base, 'SELECT neutrinos FROM autre WHERE login=?', 's', $joueur);
if ($neutrinoData && $neutrinoData['neutrinos'] > 0) {
    $coefNeutrino = coefDisparition($joueur, 1, 1); // type=1, nbAtomes=1
    $neutrinosRestants = floor(pow($coefNeutrino, $nbsecondes) * $neutrinoData['neutrinos']);
    if ($neutrinosRestants != $neutrinoData['neutrinos']) {
        dbExecute($base, 'UPDATE autre SET neutrinos=? WHERE login=?', 'is', $neutrinosRestants, $joueur);
    }
}
```

**Step 4: Commit**

```bash
git add includes/game_resources.php
git commit -m "balance: iode becomes generator catalyst, neutrinos now decay (audit #4, #17)"
```

---

## Batch C: Tests & Deploy

### Task 10: Update Test Suite for V4 Formulas

**Files:**
- Modify: `tests/unit/ExploitPreventionTest.php`
- Modify: `tests/unit/GameBalanceTest.php`

**Step 1: Read current tests**

Read both test files to find all formula assertions.

**Step 2: Update formula call signatures in tests**

Every test that calls a stat formula needs the V4 signature:

```php
// OLD:
$hp = pointsDeVieMolecule(100, 5);
// NEW:
$hp = pointsDeVieMolecule(100, 50, 5); // Br=100, C=50, nivCond=5

// OLD:
$atk = attaque(100, 5, 'testplayer');
// NEW:
$atk = attaque(100, 50, 5, 0); // O=100, H=50, nivCond=5, medalBonus=0

// OLD:
$speed = vitesse(100, 5);
// NEW:
$speed = vitesse(100, 50, 5); // Cl=100, N=50, nivCond=5
```

**Step 3: Update expected values**

Recalculate expected values using V4 formulas:
- `placeDepot(10)` = `round(1000 * pow(1.15, 10))` = 4046 (was 5000)
- `drainageProducteur(10)` = `round(8 * pow(1.15, 10))` = 32 (was 80)
- `bonusLieur(10)` = `1 + 10 * 0.15` = 2.5 (was `pow(1.07, 10)` = 1.96)
- `pointsDeVieMolecule(100, 50, 5)` = `round((10 + (pow(100, 1.2) + 100) * 1.5) * 1.1)` — compute exact value
- etc.

**Step 4: Add V4-specific tests**

Add tests for:
- `modCond()` returns correct values
- Covalent synergy: attack with H=0 vs H=100 shows meaningful boost
- Min 10 HP: `pointsDeVieMolecule(0, 0, 0)` >= 10
- Asymptotic stab: `coefDisparition` at level 67+ doesn't go negative
- `capaciteCoffreFort()` at max level caps at 50%

**Step 5: Run tests**

```bash
cd /home/guortates/TVLW/The-Very-Little-War && php vendor/bin/phpunit --no-coverage
```

All tests must pass.

**Step 6: Commit**

```bash
git add tests/
git commit -m "test: update test suite for V4 formula engine"
```

---

### Task 11: Push + Deploy + Smoke Test

**Step 1: Run full test suite**

```bash
cd /home/guortates/TVLW/The-Very-Little-War && php vendor/bin/phpunit --no-coverage 2>&1 | tail -20
```

**Step 2: Push to GitHub**

```bash
git push origin main
```

**Step 3: Deploy to VPS**

```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "cd /var/www/html && git pull origin main"
```

**Step 4: Smoke test all critical pages**

```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "for page in classement.php marche.php molecule.php attaquer.php armee.php api.php; do echo -n \"\$page: \"; curl -sS -o /dev/null -w '%{http_code}' http://localhost/\$page; echo; done"
```

Expected: 200 for classement, 302 for auth-required pages.

**Step 5: Check PHP error log**

```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "tail -20 /var/log/apache2/error.log"
```

Should show no new PHP errors.

---

## Future Plan: Unified Sqrt Ranking

### Task 12: Write Separate Plan for Unified Sqrt Ranking (Audit #19)

**Audit item:** #19 — Construction points are additive and uncapped, making pacifist builders always win. The V4 model proposes `sqrt()` over all point categories for equity.

**This task is to WRITE the plan, not implement it.** The unified ranking is a standalone project that should be playtested after the economy changes settle.

**Step 1: Write plan to `docs/plans/YYYY-MM-DD-unified-sqrt-ranking.md`**

The plan should cover:
1. New DB columns in `autre` table: `historique_energie_depensee`, `masse_atomique_detruite_offensive`, `masse_atomique_detruite_defensive`, `ressources_historiques_pillees`, `volume_energie_historique_marche`
2. SQL migration to add columns with defaults
3. Rewrite `ajouterPoints()` in `includes/player.php` to track cumulative historical values
4. New `calculerTotalPoints()` function using sqrt over all categories
5. Update `classement.php` to generate rankings dynamically from historical data
6. Update all point sources: combat.php (attack/defense points), marche.php (trade volume), player.php (construction energy)
7. Migration strategy for existing players (populate historical data from current point totals)

**Step 2: Commit the plan**

```bash
git add docs/plans/
git commit -m "plan: unified sqrt ranking system (audit #19) — separate from V4 balance overhaul"
```
