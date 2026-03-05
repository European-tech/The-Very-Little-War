# Audit Remediation Final — Combat Balance + Retention Features

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Implement all remaining audit recommendations from P1-D4 (combat balance) and P1-D8 (retention/engagement), then enable HTTPS as the final step.

**Architecture:** Balance changes are config.php constant tweaks tested via existing unit tests. Retention features add new DB columns (migration), PHP logic, and UI elements following the established patterns (Framework7 Material CSS, French language, `withTransaction()` for data integrity). All new tables use `latin1` charset for FK compatibility.

**Tech Stack:** PHP 8.2, MariaDB 10.11, Apache 2, Framework7 Material CSS, jQuery 3.7.1

---

## Sprint 1: Combat Balance Tuning (config changes only)

### Task 1: Extend beginner protection (P1-D4-029)

**Files:**
- Modify: `includes/config.php:26`
- Test: `tests/unit/ConfigConsistencyTest.php`

**Step 1: Write the failing test**

In `tests/unit/GameBalanceTest.php`, add:
```php
public function testBeginnerProtectionAtLeastFiveDays(): void
{
    $this->assertGreaterThanOrEqual(5 * 86400, BEGINNER_PROTECTION_SECONDS,
        'Beginner protection should be at least 5 days');
}
```

**Step 2: Run test to verify it fails**

Run: `cd /home/guortates/TVLW/The-Very-Little-War && php vendor/bin/phpunit --testsuite Unit --filter testBeginnerProtection`
Expected: FAIL (currently 3 days = 259200 < 432000)

**Step 3: Change the constant**

In `includes/config.php` line 26, change:
```php
define('BEGINNER_PROTECTION_SECONDS', 3 * SECONDS_PER_DAY); // V4: 3 days
```
to:
```php
define('BEGINNER_PROTECTION_SECONDS', 5 * SECONDS_PER_DAY); // P1-D4-029: extended from 3 to 5 days
```

**Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit --testsuite Unit --testsuite Balance`
Expected: All pass

**Step 5: Commit**

```bash
git add includes/config.php tests/unit/GameBalanceTest.php
git commit -m "balance: extend beginner protection from 3 to 5 days (P1-D4-029)"
```

---

### Task 2: Rebalance formations — nerf Phalange, buff Embuscade (P1-D4-021, P1-D4-022)

**Files:**
- Modify: `includes/config.php:274-277`
- Test: `tests/balance/StrategyViabilityTest.php`

**Step 1: Write failing tests**

In `tests/balance/StrategyViabilityTest.php`, add:
```php
public function testPhalanxAbsorbNotOverpowered(): void
{
    $this->assertLessThanOrEqual(0.55, FORMATION_PHALANX_ABSORB,
        'Phalanx absorb should be <= 55% to prevent dominance');
}

public function testAmbushBonusViable(): void
{
    $this->assertGreaterThanOrEqual(0.35, FORMATION_AMBUSH_ATTACK_BONUS,
        'Ambush bonus should be >= 35% to be a viable alternative');
}
```

**Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit --testsuite Balance --filter testPhalanx`
Expected: FAIL (absorb=0.60 > 0.55)

Run: `php vendor/bin/phpunit --testsuite Balance --filter testAmbush`
Expected: FAIL (bonus=0.25 < 0.35)

**Step 3: Change the constants**

In `includes/config.php` lines 274-277:
```php
define('FORMATION_PHALANX_ABSORB', 0.50);        // P1-D4-021: reduced from 0.60 to 0.50
define('FORMATION_PHALANX_DEFENSE_BONUS', 0.20);  // unchanged
// line 277:
define('FORMATION_AMBUSH_ATTACK_BONUS', 0.40);    // P1-D4-022: buffed from 0.25 to 0.40
```

**Step 4: Run all tests**

Run: `php vendor/bin/phpunit --testsuite Unit --testsuite Balance`
Expected: All pass

**Step 5: Commit**

```bash
git add includes/config.php tests/balance/StrategyViabilityTest.php
git commit -m "balance: nerf Phalange absorb 60->50%, buff Embuscade 25->40% (P1-D4-021/022)"
```

---

### Task 3: Buff Stable isotope HP (P1-D4-023)

**Files:**
- Modify: `includes/config.php:293,305`
- Test: `tests/balance/IsotopeSpecializationTest.php`

**Step 1: Write failing test**

In `tests/balance/IsotopeSpecializationTest.php`, add:
```php
public function testStableIsotopeHPBuffSufficient(): void
{
    $this->assertGreaterThanOrEqual(0.40, ISOTOPE_STABLE_HP_MOD,
        'Stable isotope HP bonus should be >= 40% to compete with Reactif attack bonus');
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit --testsuite Balance --filter testStableIsotopeHP`
Expected: FAIL (currently 0.30 < 0.40)

**Step 3: Change the constants**

In `includes/config.php`:
- Line 293: `define('ISOTOPE_STABLE_HP_MOD', 0.40);       // P1-D4-023: buffed from 0.30 to 0.40`
- Line 305: Update description string: `'-5% attaque, +40% points de vie, ...'`

**Step 4: Run all tests**

Run: `php vendor/bin/phpunit --testsuite Unit --testsuite Balance`
Expected: All pass

**Step 5: Commit**

```bash
git add includes/config.php tests/balance/IsotopeSpecializationTest.php
git commit -m "balance: buff Stable isotope HP +30% -> +40% (P1-D4-023)"
```

---

### Task 4: Increase defense reward ratio (P1-D4-027)

**Files:**
- Modify: `includes/config.php:260`
- Test: `tests/balance/CombatFairnessTest.php`

**Step 1: Write failing test**

In `tests/balance/CombatFairnessTest.php`, add:
```php
public function testDefenseRewardRatioSufficient(): void
{
    $this->assertGreaterThanOrEqual(0.30, DEFENSE_REWARD_RATIO,
        'Defense reward should be >= 30% to make defending worthwhile');
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit --testsuite Balance --filter testDefenseRewardRatio`
Expected: FAIL (currently 0.20 < 0.30)

**Step 3: Change the constant**

In `includes/config.php` line 260:
```php
define('DEFENSE_REWARD_RATIO', 0.30);         // P1-D4-027: increased from 0.20 to reduce attack>defense asymmetry
```

**Step 4: Run all tests**

Run: `php vendor/bin/phpunit --testsuite Unit --testsuite Balance`
Expected: All pass

**Step 5: Commit**

```bash
git add includes/config.php tests/balance/CombatFairnessTest.php
git commit -m "balance: increase defense reward ratio 20% -> 30% (P1-D4-027)"
```

---

### Task 5: Improve vault ROI (P1-D4-030)

**Files:**
- Modify: `includes/config.php:111`
- Test: `tests/balance/EconomyProgressionTest.php`

**Step 1: Write failing test**

In `tests/balance/EconomyProgressionTest.php`, add:
```php
public function testVaultProtectionPerLevelReasonable(): void
{
    // At 3% per level, a level 17 vault gives 50% protection (max cap)
    // This is achievable mid-season vs previous 25 levels needed
    $this->assertGreaterThanOrEqual(0.03, VAULT_PCT_PER_LEVEL,
        'Vault should give >= 3% protection per level for reasonable ROI');
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit --testsuite Balance --filter testVaultProtection`
Expected: FAIL (currently 0.02 < 0.03)

**Step 3: Change the constant**

In `includes/config.php` line 111:
```php
define('VAULT_PCT_PER_LEVEL', 0.03); // P1-D4-030: buffed from 0.02 for better ROI (cap 50% at level 17 vs 25)
```

**Step 4: Run all tests**

Run: `php vendor/bin/phpunit --testsuite Unit --testsuite Balance`
Expected: All pass

**Step 5: Commit**

```bash
git add includes/config.php tests/balance/EconomyProgressionTest.php
git commit -m "balance: improve vault ROI — 2% -> 3% per level (P1-D4-030)"
```

---

### Task 6: Add pillage friction — pillage tax (P1-D4-031)

**Files:**
- Modify: `includes/config.php` (add new constant)
- Modify: `includes/combat.php` (apply tax)
- Test: `tests/balance/CombatFairnessTest.php`

**Step 1: Write failing test**

In `tests/balance/CombatFairnessTest.php`, add:
```php
public function testPillageTaxExists(): void
{
    $this->assertTrue(defined('PILLAGE_TAX_RATE'), 'PILLAGE_TAX_RATE must be defined');
    $this->assertGreaterThan(0, PILLAGE_TAX_RATE, 'Pillage tax must be > 0');
    $this->assertLessThanOrEqual(0.30, PILLAGE_TAX_RATE, 'Pillage tax must be <= 30%');
}
```

**Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit --testsuite Balance --filter testPillageTax`
Expected: FAIL (constant not defined)

**Step 3: Add constant and apply in combat**

In `includes/config.php`, after line 260 (DEFENSE_REWARD_RATIO), add:
```php
define('PILLAGE_TAX_RATE', 0.15);             // P1-D4-031: 15% pillage tax to reduce wealth concentration
```

In `includes/combat.php`, find where pillage resources are awarded to attacker (search for `pillage` assignment). After the pillage amount is calculated, multiply by `(1 - PILLAGE_TAX_RATE)`:

Find the line like:
```php
$pillageAmount = round($pillageBase * ...);
```
Change to:
```php
$pillageAmount = round($pillageBase * ... * (1 - PILLAGE_TAX_RATE));
```

The exact location: search `includes/combat.php` for where `soufre` or `pillage` value is applied to resources. Apply the tax multiplier there.

**Step 4: Run all tests**

Run: `php vendor/bin/phpunit --testsuite Unit --testsuite Balance`
Expected: All pass

**Step 5: Commit**

```bash
git add includes/config.php includes/combat.php tests/balance/CombatFairnessTest.php
git commit -m "balance: add 15% pillage tax to reduce wealth concentration (P1-D4-031)"
```

---

### Task 7: Push + deploy Sprint 1

**Step 1: Run full test suite**

Run: `php vendor/bin/phpunit --testsuite Unit --testsuite Balance --testsuite Functional`
Expected: All pass

**Step 2: Push and deploy**

```bash
git push origin main
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 'cd /var/www/html && git pull origin main'
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 'curl -s -o /dev/null -w "%{http_code}" http://localhost/health.php'
```
Expected: `200`

---

## Sprint 2: Daily Login Streak System (P1-D8-041)

### Task 8: Create streak migration

**Files:**
- Create: `migrations/0027_add_login_streak.sql`

**Step 1: Write the migration**

```sql
-- 0027: Add daily login streak tracking (P1-D8-041)
ALTER TABLE autre
  ADD COLUMN streak_days INT NOT NULL DEFAULT 0,
  ADD COLUMN streak_last_date DATE NULL DEFAULT NULL;
```

**Step 2: Run migration on VPS**

```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "mysql -u tvlw -pmLLIoTy2ByGTBNb9RQpTGnqPhXQfUR tvlw < /var/www/html/migrations/0027_add_login_streak.sql"
```

**Step 3: Commit**

```bash
git add migrations/0027_add_login_streak.sql
git commit -m "migration: add streak_days and streak_last_date to autre (P1-D8-041)"
```

---

### Task 9: Add streak constants to config

**Files:**
- Modify: `includes/config.php`

**Step 1: Add constants**

After the PRESTIGE section (~line 552), add:
```php
// === DAILY LOGIN STREAK (P1-D8-041) ===
define('STREAK_REWARD_DAY_1', 1);    // PP for logging in (any day)
define('STREAK_REWARD_DAY_3', 2);    // PP bonus at 3-day streak
define('STREAK_REWARD_DAY_7', 5);    // PP bonus at 7-day streak
define('STREAK_REWARD_DAY_14', 10);  // PP bonus at 14-day streak
define('STREAK_REWARD_DAY_21', 15);  // PP bonus at 21-day streak
define('STREAK_REWARD_DAY_28', 25);  // PP bonus at full month streak
$STREAK_MILESTONES = [
    1  => STREAK_REWARD_DAY_1,
    3  => STREAK_REWARD_DAY_3,
    7  => STREAK_REWARD_DAY_7,
    14 => STREAK_REWARD_DAY_14,
    21 => STREAK_REWARD_DAY_21,
    28 => STREAK_REWARD_DAY_28,
];
```

**Step 2: Commit**

```bash
git add includes/config.php
git commit -m "config: add daily login streak reward constants (P1-D8-041)"
```

---

### Task 10: Implement streak logic in player.php

**Files:**
- Modify: `includes/player.php`
- Test: `tests/unit/GameBalanceTest.php`

**Step 1: Write the failing test**

In `tests/unit/GameBalanceTest.php`, add:
```php
public function testStreakMilestonesAreDefined(): void
{
    global $STREAK_MILESTONES;
    $this->assertNotEmpty($STREAK_MILESTONES);
    $this->assertArrayHasKey(1, $STREAK_MILESTONES);
    $this->assertArrayHasKey(7, $STREAK_MILESTONES);
    $this->assertArrayHasKey(28, $STREAK_MILESTONES);
}

public function testStreakRewardsEscalate(): void
{
    $this->assertLessThan(STREAK_REWARD_DAY_7, STREAK_REWARD_DAY_3);
    $this->assertLessThan(STREAK_REWARD_DAY_14, STREAK_REWARD_DAY_7);
    $this->assertLessThan(STREAK_REWARD_DAY_28, STREAK_REWARD_DAY_14);
}
```

**Step 2: Run tests to verify they pass (config already added)**

Run: `php vendor/bin/phpunit --testsuite Unit --filter testStreak`
Expected: PASS

**Step 3: Add streak update function to player.php**

In `includes/player.php`, add at the end:
```php
/**
 * Update daily login streak. Call once per login (guarded by date check).
 * Returns array ['streak' => int, 'pp_earned' => int, 'milestone' => bool]
 */
function updateLoginStreak($base, $login) {
    global $STREAK_MILESTONES;
    $today = date('Y-m-d');

    $row = dbFetchOne($base, 'SELECT streak_days, streak_last_date FROM autre WHERE login = ?', 's', $login);
    if (!$row) return ['streak' => 0, 'pp_earned' => 0, 'milestone' => false];

    $lastDate = $row['streak_last_date'];
    $currentStreak = (int)$row['streak_days'];

    // Already logged in today
    if ($lastDate === $today) {
        return ['streak' => $currentStreak, 'pp_earned' => 0, 'milestone' => false];
    }

    $yesterday = date('Y-m-d', strtotime('-1 day'));

    if ($lastDate === $yesterday) {
        // Consecutive day — increment streak
        $currentStreak++;
    } else {
        // Gap — reset to 1
        $currentStreak = 1;
    }

    // Update DB
    dbExecute($base, 'UPDATE autre SET streak_days = ?, streak_last_date = ? WHERE login = ?',
        'iss', $currentStreak, $today, $login);

    // Calculate PP earned
    $ppEarned = 0;
    $isMilestone = false;
    if (isset($STREAK_MILESTONES[$currentStreak])) {
        $ppEarned = $STREAK_MILESTONES[$currentStreak];
        $isMilestone = true;
    } elseif ($currentStreak >= 1) {
        $ppEarned = STREAK_REWARD_DAY_1; // base daily reward
    }

    // Award PP (add to points in autre)
    if ($ppEarned > 0) {
        dbExecute($base, 'UPDATE autre SET points = points + ? WHERE login = ?', 'is', $ppEarned, $login);
    }

    return ['streak' => $currentStreak, 'pp_earned' => $ppEarned, 'milestone' => $isMilestone];
}
```

**Step 4: Call streak update from basicprivatehtml.php**

In `includes/basicprivatehtml.php`, after the session authentication succeeds and player is confirmed logged in, add:
```php
// Daily login streak (P1-D8-041)
$streakResult = updateLoginStreak($base, $_SESSION['login']);
if ($streakResult['milestone']) {
    $_SESSION['streak_milestone'] = $streakResult;
}
```

**Step 5: Run tests**

Run: `php vendor/bin/phpunit --testsuite Unit --testsuite Balance`
Expected: All pass

**Step 6: Commit**

```bash
git add includes/player.php includes/basicprivatehtml.php tests/unit/GameBalanceTest.php
git commit -m "feat: implement daily login streak with PP rewards (P1-D8-041)"
```

---

### Task 11: Add streak display to prestige page

**Files:**
- Modify: `prestige.php`

**Step 1: Read prestige.php to find where to add streak section**

Read `prestige.php` to understand its layout.

**Step 2: Add streak section**

After the existing PP balance section, add a streak display card:
```php
<?php
// Streak display
$streakRow = dbFetchOne($base, 'SELECT streak_days, streak_last_date FROM autre WHERE login = ?', 's', $_SESSION['login']);
$currentStreak = $streakRow ? (int)$streakRow['streak_days'] : 0;
$nextMilestone = null;
$nextReward = 0;
foreach ($STREAK_MILESTONES as $day => $reward) {
    if ($day > $currentStreak) {
        $nextMilestone = $day;
        $nextReward = $reward;
        break;
    }
}
?>
<div class="card">
    <div class="card-header">Connexion quotidienne</div>
    <div class="card-content card-content-padding">
        <p><strong>Serie actuelle :</strong> <?= $currentStreak ?> jour<?= $currentStreak > 1 ? 's' : '' ?></p>
        <?php if ($nextMilestone): ?>
        <p>Prochain palier : <strong><?= $nextMilestone ?> jours</strong> (+<?= $nextReward ?> PP)</p>
        <div class="progressbar" data-progress="<?= min(100, round($currentStreak / $nextMilestone * 100)) ?>">
            <span></span>
        </div>
        <?php else: ?>
        <p>Tous les paliers atteints !</p>
        <?php endif; ?>
        <p class="text-color-gray" style="margin-top:8px;font-size:12px;">
            Connectez-vous chaque jour pour accumuler des PP bonus.
            Paliers : 3j (+<?= STREAK_REWARD_DAY_3 ?>PP), 7j (+<?= STREAK_REWARD_DAY_7 ?>PP),
            14j (+<?= STREAK_REWARD_DAY_14 ?>PP), 21j (+<?= STREAK_REWARD_DAY_21 ?>PP),
            28j (+<?= STREAK_REWARD_DAY_28 ?>PP)
        </p>
    </div>
</div>
```

**Step 3: Commit**

```bash
git add prestige.php
git commit -m "feat: add streak display with progress bar to prestige page (P1-D8-041)"
```

---

## Sprint 3: Comeback & Welcome-Back Mechanics (P1-D8-044, P1-D8-047)

### Task 12: Create comeback migration

**Files:**
- Create: `migrations/0028_add_comeback_tracking.sql`

**Step 1: Write the migration**

```sql
-- 0028: Add comeback tracking for welcome-back bonus (P1-D8-044/047)
ALTER TABLE autre
  ADD COLUMN last_catch_up INT NOT NULL DEFAULT 0,
  ADD COLUMN comeback_shield_until INT NOT NULL DEFAULT 0;
```

**Step 2: Run migration on VPS**

```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "mysql -u tvlw -pmLLIoTy2ByGTBNb9RQpTGnqPhXQfUR tvlw < /var/www/html/migrations/0028_add_comeback_tracking.sql"
```

**Step 3: Commit**

```bash
git add migrations/0028_add_comeback_tracking.sql
git commit -m "migration: add comeback tracking columns to autre (P1-D8-044/047)"
```

---

### Task 13: Add comeback constants + logic

**Files:**
- Modify: `includes/config.php`
- Modify: `includes/player.php`
- Modify: `includes/basicprivatehtml.php`

**Step 1: Add constants to config.php**

After the streak constants, add:
```php
// === COMEBACK / WELCOME-BACK (P1-D8-044/047) ===
define('COMEBACK_ABSENCE_DAYS', 3);             // Days absent to trigger comeback bonus
define('COMEBACK_SHIELD_HOURS', 24);            // Hours of attack protection on return
define('COMEBACK_ENERGY_BONUS', 500);           // Energy granted on return
define('COMEBACK_ATOMS_BONUS', 100);            // Each atom type granted on return
define('COMEBACK_COOLDOWN_DAYS', 7);            // Min days between comeback bonuses
define('UNDERDOG_RANK_THRESHOLD', 50);          // Rank difference to trigger underdog bonus
define('UNDERDOG_POINTS_MULTIPLIER', 1.5);      // Combat point multiplier for underdog wins
```

**Step 2: Add comeback function to player.php**

```php
/**
 * Check and apply welcome-back bonus for returning players.
 * Call once per login after streak update.
 * Returns array ['applied' => bool, 'energy' => int, 'atoms' => int, 'shield_hours' => int]
 */
function checkComebackBonus($base, $login) {
    $membre = dbFetchOne($base, 'SELECT derniereConnexion FROM membre WHERE login = ?', 's', $login);
    $autre = dbFetchOne($base, 'SELECT last_catch_up FROM autre WHERE login = ?', 's', $login);
    if (!$membre || !$autre) return ['applied' => false];

    $lastLogin = (int)$membre['derniereConnexion'];
    $lastCatchUp = (int)$autre['last_catch_up'];
    $now = time();
    $absentDays = ($now - $lastLogin) / SECONDS_PER_DAY;
    $cooldownOk = ($now - $lastCatchUp) > (COMEBACK_COOLDOWN_DAYS * SECONDS_PER_DAY);

    if ($absentDays < COMEBACK_ABSENCE_DAYS || !$cooldownOk) {
        return ['applied' => false];
    }

    // Apply comeback bonus in transaction
    withTransaction($base, function() use ($base, $login, $now) {
        // Grant energy + atoms
        dbExecute($base, 'UPDATE ressources SET energie = energie + ?, carbone = carbone + ?,
            azote = azote + ?, hydrogene = hydrogene + ?, oxygene = oxygene + ?,
            chlore = chlore + ?, soufre = soufre + ?, brome = brome + ?, iode = iode + ?
            WHERE login = ?',
            'ddddddddds',
            (float)COMEBACK_ENERGY_BONUS,
            (float)COMEBACK_ATOMS_BONUS, (float)COMEBACK_ATOMS_BONUS,
            (float)COMEBACK_ATOMS_BONUS, (float)COMEBACK_ATOMS_BONUS,
            (float)COMEBACK_ATOMS_BONUS, (float)COMEBACK_ATOMS_BONUS,
            (float)COMEBACK_ATOMS_BONUS, (float)COMEBACK_ATOMS_BONUS,
            $login
        );

        // Set comeback shield + timestamp
        $shieldUntil = $now + (COMEBACK_SHIELD_HOURS * SECONDS_PER_HOUR);
        dbExecute($base, 'UPDATE autre SET last_catch_up = ?, comeback_shield_until = ? WHERE login = ?',
            'iis', $now, $shieldUntil, $login);
    });

    logInfo('COMEBACK', 'Welcome-back bonus applied', ['login' => $login, 'absent_days' => round($absentDays, 1)]);

    return [
        'applied' => true,
        'energy' => COMEBACK_ENERGY_BONUS,
        'atoms' => COMEBACK_ATOMS_BONUS,
        'shield_hours' => COMEBACK_SHIELD_HOURS,
    ];
}

/**
 * Check if player is under comeback shield protection.
 */
function hasActiveShield($base, $login) {
    $row = dbFetchOne($base, 'SELECT comeback_shield_until FROM autre WHERE login = ?', 's', $login);
    return $row && (int)$row['comeback_shield_until'] > time();
}
```

**Step 3: Wire comeback check into basicprivatehtml.php**

After the streak update call, add:
```php
// Welcome-back bonus (P1-D8-044/047)
$comebackResult = checkComebackBonus($base, $_SESSION['login']);
if ($comebackResult['applied']) {
    $_SESSION['comeback_bonus'] = $comebackResult;
}
```

**Step 4: Add shield check to combat initiation**

In `includes/game_actions.php`, in the attack validation section (before combat starts), add:
```php
// Check comeback shield on defender
if (hasActiveShield($base, $actions['defenseur'])) {
    $erreur = "Ce joueur est sous protection de retour. Revenez plus tard.";
    return;
}
```

**Step 5: Run tests**

Run: `php vendor/bin/phpunit --testsuite Unit --testsuite Balance`
Expected: All pass

**Step 6: Commit**

```bash
git add includes/config.php includes/player.php includes/basicprivatehtml.php includes/game_actions.php
git commit -m "feat: add comeback bonus (shield + resources) for returning players (P1-D8-044/047)"
```

---

### Task 14: Push + deploy Sprint 2-3

```bash
git push origin main
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 'cd /var/www/html && git pull origin main'
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "mysql -u tvlw -pmLLIoTy2ByGTBNb9RQpTGnqPhXQfUR tvlw < /var/www/html/migrations/0027_add_login_streak.sql"
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "mysql -u tvlw -pmLLIoTy2ByGTBNb9RQpTGnqPhXQfUR tvlw < /var/www/html/migrations/0028_add_comeback_tracking.sql"
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 'curl -s -o /dev/null -w "%{http_code}" http://localhost/health.php'
```
Expected: `200`

---

## Sprint 4: UX Polish — Post-Defeat, Prestige, Progress Bars (P1-D8-045, P1-D8-048, P1-D8-053)

### Task 15: Add post-defeat recovery message to rapports.php (P1-D8-045)

**Files:**
- Modify: `rapports.php`

**Step 1: Read rapports.php**

Find where combat reports are displayed. Look for the section that shows defeat outcomes.

**Step 2: Add recovery card after defeat display**

After the section that displays "Vous avez perdu" or similar defeat text, add:
```php
<?php if ($rapport['resultat'] === 'defaite'): ?>
<div class="card" style="background:#fff3e0;border-left:4px solid #ff9800;">
    <div class="card-content card-content-padding">
        <p><strong>Ne baissez pas les bras !</strong></p>
        <p>Votre armee peut etre reformee rapidement.</p>
        <ul>
            <li><a href="molecules.php">Reformer vos molecules</a></li>
            <li><a href="regles.php#formations">Apprendre les formations defensives</a></li>
            <li>Pensez a espionner avant d'attaquer : <a href="attaquer.php">Espionnage</a></li>
        </ul>
    </div>
</div>
<?php endif; ?>
```

**Step 3: Commit**

```bash
git add rapports.php
git commit -m "ux: add post-defeat recovery message with action links (P1-D8-045)"
```

---

### Task 16: Add prestige mention to tutorial (P1-D8-048)

**Files:**
- Modify: `tutoriel.php`

**Step 1: Read tutoriel.php**

Find the later tutorial missions (mission 15+) and add prestige messaging.

**Step 2: Add prestige info to a late tutorial mission**

Find a mission around index 15-19 and add a hint about prestige:
```php
// Inside the appropriate mission display section:
echo '<div class="card" style="background:#e8f5e9;border-left:4px solid #4caf50;">
    <div class="card-content card-content-padding">
        <p><strong>Astuce :</strong> Gagnez des medailles pour debloquer des bonus PERMANENTS pour la saison suivante !
        <a href="prestige.php">Voir le systeme de prestige</a></p>
    </div>
</div>';
```

**Step 3: Commit**

```bash
git add tutoriel.php
git commit -m "ux: add prestige/medal hint to late tutorial missions (P1-D8-048)"
```

---

### Task 17: Add medal progress bars to medailles.php (P1-D8-053)

**Files:**
- Modify: `medailles.php`

**Step 1: Read medailles.php**

Understand how medals are currently displayed.

**Step 2: Add progress bars**

For each medal category, after the current tier display, add a progress bar showing how close the player is to the next tier:

```php
<?php
// For each medal category, calculate progress to next tier
function medalProgress($currentValue, $thresholds) {
    for ($i = 0; $i < count($thresholds); $i++) {
        if ($currentValue < $thresholds[$i]) {
            $prevThreshold = $i > 0 ? $thresholds[$i - 1] : 0;
            $pct = round(($currentValue - $prevThreshold) / ($thresholds[$i] - $prevThreshold) * 100);
            return ['next_tier' => $i, 'pct' => min(100, max(0, $pct)), 'remaining' => $thresholds[$i] - $currentValue];
        }
    }
    return ['next_tier' => -1, 'pct' => 100, 'remaining' => 0]; // maxed
}
?>
```

Then for each medal row, add:
```php
<?php
$prog = medalProgress($playerValue, $thresholdArray);
if ($prog['next_tier'] >= 0):
?>
<div class="progressbar" data-progress="<?= $prog['pct'] ?>"><span></span></div>
<small class="text-color-gray">Encore <?= number_format($prog['remaining'], 0, ' ', ' ') ?> pour <?= $paliersMedailles[$prog['next_tier']] ?></small>
<?php endif; ?>
```

**Step 3: Commit**

```bash
git add medailles.php
git commit -m "ux: add medal progress bars with remaining count (P1-D8-053)"
```

---

## Sprint 5: Social Features — Last Seen, Forum Widget, Alliance Discovery (P1-D8-050, P1-D8-051, P1-D8-046)

### Task 18: Add "last seen" to player profiles (P1-D8-050)

**Files:**
- Modify: `joueur.php`

**Step 1: Read joueur.php**

Find where player profile info is displayed.

**Step 2: Add last seen display**

After the existing profile info section, add:
```php
<?php
$lastConn = (int)$joueurRow['derniereConnexion'];
$diff = time() - $lastConn;
if ($diff < 300) {
    $lastSeenText = 'En ligne';
    $lastSeenColor = '#4caf50';
} elseif ($diff < SECONDS_PER_HOUR) {
    $lastSeenText = 'Il y a ' . floor($diff / 60) . ' min';
    $lastSeenColor = '#8bc34a';
} elseif ($diff < SECONDS_PER_DAY) {
    $lastSeenText = 'Il y a ' . floor($diff / SECONDS_PER_HOUR) . 'h';
    $lastSeenColor = '#ff9800';
} else {
    $lastSeenText = 'Il y a ' . floor($diff / SECONDS_PER_DAY) . ' jours';
    $lastSeenColor = '#9e9e9e';
}
?>
<p><span style="color:<?= $lastSeenColor ?>;">&#9679;</span> <?= htmlspecialchars($lastSeenText) ?></p>
```

**Step 3: Commit**

```bash
git add joueur.php
git commit -m "feat: add 'last seen' timestamp to player profiles (P1-D8-050)"
```

---

### Task 19: Add forum widget to homepage (P1-D8-051)

**Files:**
- Modify: `index.php`

**Step 1: Read index.php**

Find the main content area where widgets can be added.

**Step 2: Add forum latest threads widget**

After existing content blocks, add:
```php
<?php
// Forum widget — latest 3 threads
$latestThreads = dbFetchAll($base, 'SELECT id, titre, login, date FROM sujet WHERE ferme = 0 ORDER BY date DESC LIMIT 3', '', '');
if ($latestThreads):
?>
<div class="card">
    <div class="card-header">Dernieres discussions</div>
    <div class="card-content">
        <div class="list">
            <ul>
                <?php foreach ($latestThreads as $thread): ?>
                <li>
                    <a href="sujet.php?id=<?= (int)$thread['id'] ?>" class="item-link item-content">
                        <div class="item-inner">
                            <div class="item-title"><?= htmlspecialchars($thread['titre']) ?></div>
                            <div class="item-after"><?= htmlspecialchars($thread['login']) ?></div>
                        </div>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <div class="card-footer"><a href="listesujets.php?id=1">Voir tout le forum</a></div>
</div>
<?php endif; ?>
```

**Step 3: Commit**

```bash
git add index.php
git commit -m "feat: add forum latest threads widget to homepage (P1-D8-051)"
```

---

### Task 20: Add alliance discovery page (P1-D8-046)

**Files:**
- Create: `alliance_discovery.php`
- Modify: `includes/layout.php` (add nav link)

**Step 1: Create alliance_discovery.php**

```php
<?php
require('includes/basicprivatehtml.php');
require('includes/constantesBase.php');

$alliances = dbFetchAll($base,
    'SELECT a.id, a.nom, a.tag, a.duplicateur, a.chef,
            COUNT(au.login) AS membres,
            AVG(au.totalPoints) AS avg_points
     FROM alliance a
     LEFT JOIN autre au ON au.idalliance = a.id AND au.idalliance > 0
     GROUP BY a.id
     ORDER BY avg_points DESC',
    '', '');

$titre = 'Alliances';
require('includes/layout.php');
function contenu() {
    global $alliances, $joueur;
?>
<div class="card">
    <div class="card-header">Trouver une alliance</div>
    <div class="card-content">
        <?php if (!empty($alliances)): ?>
        <div class="data-table">
            <table>
                <thead><tr>
                    <th>Tag</th><th>Nom</th><th>Membres</th>
                    <th>Duplicateur</th><th>Rang moyen</th><th>Chef</th>
                </tr></thead>
                <tbody>
                <?php foreach ($alliances as $a): ?>
                <tr>
                    <td><strong>[<?= htmlspecialchars($a['tag']) ?>]</strong></td>
                    <td><?= htmlspecialchars($a['nom']) ?></td>
                    <td><?= (int)$a['membres'] ?>/<?= MAX_ALLIANCE_MEMBERS ?></td>
                    <td>Niv. <?= (int)$a['duplicateur'] ?></td>
                    <td><?= number_format((float)$a['avg_points'], 0, ',', ' ') ?> pts</td>
                    <td><?= htmlspecialchars($a['chef']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="text-color-gray" style="padding:16px;">Aucune alliance active.</p>
        <?php endif; ?>
    </div>
</div>

<?php if ($joueur['idalliance'] == 0): ?>
<div class="card" style="background:#e3f2fd;border-left:4px solid #2196f3;">
    <div class="card-content card-content-padding">
        <p>Rejoindre une alliance vous donne acces au <strong>Duplicateur</strong> (+1% production par niveau)
        et aux <strong>Recherches</strong> (catalyseur, fortification, radar, bouclier, reseau).</p>
        <p>Contactez un chef d'alliance via le forum ou les messages prives !</p>
    </div>
</div>
<?php endif; ?>
<?php
}
contenu();
require('includes/layout.php');
?>
```

Note: The exact layout pattern (header include, contenu function, footer) must match the existing page structure. Read an existing page like `classement.php` to follow the exact pattern.

**Step 2: Add navigation link**

In `includes/layout.php`, in the sidebar nav menu, add after the alliance link:
```php
<li><a href="alliance_discovery.php" class="panel-close">Trouver une alliance</a></li>
```

**Step 3: Commit**

```bash
git add alliance_discovery.php includes/layout.php
git commit -m "feat: add alliance discovery page with rankings (P1-D8-046)"
```

---

### Task 21: Push + deploy Sprint 4-5

```bash
git push origin main
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 'cd /var/www/html && git pull origin main'
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 'curl -s -o /dev/null -w "%{http_code}" http://localhost/health.php'
```

---

## Sprint 6: Daily Leaderboard + Attack Notifications (P1-D8-058, P1-D8-049)

### Task 22: Add daily leaderboard view to classement.php (P1-D8-058)

**Files:**
- Modify: `classement.php`

**Step 1: Read classement.php**

Understand current leaderboard structure.

**Step 2: Add daily leaderboard tab**

Add a toggle/tab UI at the top of the leaderboard:
```php
<?php
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'total';
?>
<div class="segmented" style="margin:8px 0;">
    <a href="classement.php?mode=total" class="button <?= $mode === 'total' ? 'button-active' : '' ?>">Total</a>
    <a href="classement.php?mode=daily" class="button <?= $mode === 'daily' ? 'button-active' : '' ?>">Aujourd'hui</a>
</div>
```

For the daily mode, query the ranking data with a WHERE clause on today's activity:
```php
<?php if ($mode === 'daily'): ?>
<?php
// Daily leaderboard: show players sorted by points gained today
// Use derniereConnexion >= midnight today as a proxy for active today
$midnightToday = strtotime('today midnight');
$dailyPlayers = dbFetchAll($base,
    'SELECT a.login, a.totalPoints, a.pointsAttaque, a.pointsDefense, a.tradeVolume
     FROM autre a
     JOIN membre m ON m.login = a.login
     WHERE m.derniereConnexion >= ? AND m.x != -1000
     ORDER BY a.totalPoints DESC
     LIMIT 20',
    'i', $midnightToday);
?>
<!-- Render daily table -->
<?php endif; ?>
```

Note: True daily delta tracking requires storing yesterday's points. For V1, showing "most active today" (logged in today, sorted by total points) is sufficient. A future migration can add `daily_points_snapshot` for real deltas.

**Step 3: Commit**

```bash
git add classement.php
git commit -m "feat: add daily leaderboard view showing today's active players (P1-D8-058)"
```

---

### Task 23: Add attack notification on login (P1-D8-049)

**Files:**
- Modify: `includes/basicprivatehtml.php`

**Step 1: Add unread attack report check**

After the streak/comeback checks in basicprivatehtml.php, add:
```php
// Attack notification: check for unread combat reports (P1-D8-049)
$unreadAttacks = dbCount($base,
    'SELECT COUNT(*) AS nb FROM rapports WHERE defenseur = ? AND lu = 0',
    's', $_SESSION['login']);
if ($unreadAttacks > 0) {
    $_SESSION['unread_attacks'] = $unreadAttacks;
}
```

**Step 2: Display notification in layout**

In `includes/layout.php`, in the top navbar area, add:
```php
<?php if (!empty($_SESSION['unread_attacks']) && $_SESSION['unread_attacks'] > 0): ?>
<a href="rapports.php" class="link" style="position:relative;">
    Rapports
    <span class="badge color-red" style="position:absolute;top:-5px;right:-10px;">
        <?= (int)$_SESSION['unread_attacks'] ?>
    </span>
</a>
<?php endif; ?>
```

Note: Check if `rapports` table has a `lu` (read) column. If not, track read status via `derniereConnexion` timestamp comparison against report date. Adjust query accordingly.

**Step 3: Commit**

```bash
git add includes/basicprivatehtml.php includes/layout.php
git commit -m "feat: add unread attack notification badge in navbar (P1-D8-049)"
```

---

## Sprint 7: Market Tutorial + Catchup Weekends (P1-D8-054, P1-D8-043)

### Task 24: Add market tutorial hint to marche.php (P1-D8-054)

**Files:**
- Modify: `marche.php`

**Step 1: Read marche.php**

Find the market page display area.

**Step 2: Add market tip for new players**

At the top of the market content, add a tip card visible to players under level 10:
```php
<?php
$joueurConstructions = dbFetchOne($base, 'SELECT generateur FROM constructions WHERE login = ?', 's', $_SESSION['login']);
if ($joueurConstructions && (int)$joueurConstructions['generateur'] < 10):
?>
<div class="card" style="background:#e8f5e9;border-left:4px solid #4caf50;margin-bottom:12px;">
    <div class="card-content card-content-padding">
        <p><strong>Conseil marche :</strong> Les prix fluctuent ! Achetez quand le cours est bas
        (pres de <?= MARKET_PRICE_FLOOR ?>), vendez quand il monte. Regardez l'historique du graphique
        pour reperer les tendances.</p>
    </div>
</div>
<?php endif; ?>
```

**Step 3: Commit**

```bash
git add marche.php
git commit -m "ux: add market tutorial hint for new players (P1-D8-054)"
```

---

### Task 25: Add 2x XP catchup weekends (P1-D8-043)

**Files:**
- Modify: `includes/config.php`
- Modify: `includes/game_actions.php`

**Step 1: Add config constants**

```php
// === CATCHUP WEEKENDS (P1-D8-043) ===
define('CATCHUP_WEEKEND_ENABLED', true);
define('CATCHUP_WEEKEND_MULTIPLIER', 2.0);  // 2x combat points on weekends of weeks 2 & 3
define('CATCHUP_WEEKEND_START_DAY', 7);     // Season day 7 = start of week 2
define('CATCHUP_WEEKEND_END_DAY', 21);      // Season day 21 = end of week 3
```

**Step 2: Add multiplier logic**

In `includes/game_actions.php`, where combat points are awarded (find `pointsAttaque` and `pointsDefense` assignment), add:
```php
// Catchup weekend multiplier (P1-D8-043)
if (CATCHUP_WEEKEND_ENABLED) {
    $dayOfWeek = (int)date('N'); // 1=Mon, 6=Sat, 7=Sun
    $seasonDay = floor((time() - $seasonStart) / SECONDS_PER_DAY);
    if ($dayOfWeek >= 6 && $seasonDay >= CATCHUP_WEEKEND_START_DAY && $seasonDay <= CATCHUP_WEEKEND_END_DAY) {
        $pointsAttaque = floor($pointsAttaque * CATCHUP_WEEKEND_MULTIPLIER);
        $pointsDefense = floor($pointsDefense * CATCHUP_WEEKEND_MULTIPLIER);
    }
}
```

Note: `$seasonStart` must be calculated from the maintenance table or a config-defined season start timestamp. Check how the existing season countdown in `index.php` / `js/countdown.js` determines the current season start.

**Step 3: Commit**

```bash
git add includes/config.php includes/game_actions.php
git commit -m "feat: add 2x combat points on catchup weekends in weeks 2-3 (P1-D8-043)"
```

---

### Task 26: Push + deploy Sprint 6-7

```bash
git push origin main
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 'cd /var/www/html && git pull origin main'
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 'curl -s -o /dev/null -w "%{http_code}" http://localhost/health.php'
```

---

## Sprint 8: Season Recap Page (P1-D8-052)

### Task 27: Create season_recap migration

**Files:**
- Create: `migrations/0029_create_season_recap.sql`

**Step 1: Write migration**

```sql
-- 0029: Season recap archive table (P1-D8-052)
CREATE TABLE IF NOT EXISTS season_recap (
    id INT AUTO_INCREMENT PRIMARY KEY,
    season_number INT NOT NULL,
    login VARCHAR(255) NOT NULL,
    final_rank INT NOT NULL DEFAULT 0,
    total_points INT NOT NULL DEFAULT 0,
    points_attaque INT NOT NULL DEFAULT 0,
    points_defense INT NOT NULL DEFAULT 0,
    trade_volume DOUBLE NOT NULL DEFAULT 0,
    ressources_pillees BIGINT NOT NULL DEFAULT 0,
    nb_attaques INT NOT NULL DEFAULT 0,
    victoires INT NOT NULL DEFAULT 0,
    molecules_perdues DOUBLE NOT NULL DEFAULT 0,
    alliance_name VARCHAR(255) DEFAULT NULL,
    streak_max INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_season (season_number),
    INDEX idx_login (login)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
```

**Step 2: Commit**

```bash
git add migrations/0029_create_season_recap.sql
git commit -m "migration: create season_recap table for end-of-season archives (P1-D8-052)"
```

---

### Task 28: Add recap archiving to season reset + create recap page

**Files:**
- Modify: `includes/game_actions.php` (season reset function)
- Create: `season_recap.php`

**Step 1: Add archiving before season wipe**

In the `performSeasonEnd()` function (or equivalent season reset logic), BEFORE wiping player data, insert recap rows:

```php
// Archive season data before reset (P1-D8-052)
$seasonNumber = dbFetchOne($base, 'SELECT MAX(season_number) + 1 AS next FROM season_recap', '', '');
$nextSeason = $seasonNumber ? (int)$seasonNumber['next'] : 1;

$allPlayers = dbFetchAll($base,
    'SELECT a.login, a.totalPoints, a.pointsAttaque, a.pointsDefense, a.tradeVolume,
            a.ressourcesPillees, a.nbattaques, a.victoires, a.moleculesPerdues,
            a.streak_days, al.nom AS alliance_name
     FROM autre a
     LEFT JOIN alliance al ON al.id = a.idalliance AND a.idalliance > 0
     JOIN membre m ON m.login = a.login AND m.x != -1000
     ORDER BY a.totalPoints DESC', '', '');

$rank = 0;
foreach ($allPlayers as $p) {
    $rank++;
    dbExecute($base,
        'INSERT INTO season_recap (season_number, login, final_rank, total_points, points_attaque,
         points_defense, trade_volume, ressources_pillees, nb_attaques, victoires,
         molecules_perdues, alliance_name, streak_max) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
        'isiiiidiiidsi',
        $nextSeason, $p['login'], $rank, (int)$p['totalPoints'],
        (int)$p['pointsAttaque'], (int)$p['pointsDefense'],
        (float)$p['tradeVolume'], (int)$p['ressourcesPillees'],
        (int)$p['nbattaques'], (int)$p['victoires'],
        (float)$p['moleculesPerdues'], $p['alliance_name'] ?? '',
        (int)($p['streak_days'] ?? 0)
    );
}
```

**Step 2: Create season_recap.php page**

Follow the existing page pattern (basicprivatehtml.php + layout.php). Display:
- Player's past season stats
- Best rank achieved
- Medal progress
- Comparison with previous season

```php
<?php
require('includes/basicprivatehtml.php');
require('includes/constantesBase.php');

$login = $_SESSION['login'];
$recaps = dbFetchAll($base,
    'SELECT * FROM season_recap WHERE login = ? ORDER BY season_number DESC LIMIT 5',
    's', $login);

$titre = 'Historique des saisons';
require('includes/layout.php');
// ... display recaps in cards with stats ...
```

**Step 3: Add nav link in layout.php**

```php
<li><a href="season_recap.php" class="panel-close">Historique saisons</a></li>
```

**Step 4: Commit**

```bash
git add migrations/0029_create_season_recap.sql season_recap.php includes/game_actions.php includes/layout.php
git commit -m "feat: add season recap archive + history page (P1-D8-052)"
```

---

## Sprint 9: Update Rules Page + Final Polish

### Task 29: Update regles.php with new mechanics

**Files:**
- Modify: `regles.php`

**Step 1: Read regles.php**

Find existing sections.

**Step 2: Add sections for new features**

Add sections for:
- Daily login streak system (how it works, PP tiers)
- Comeback bonus (3-day absence triggers welcome-back)
- Catchup weekends (2x points on weekends weeks 2-3)
- Pillage tax (15% friction)
- Updated formation balance (Phalange 50%, Embuscade 40%)
- Updated isotope balance (Stable +40% HP)
- Updated vault (3% per level)
- Beginner protection (5 days)

**Step 3: Commit**

```bash
git add regles.php
git commit -m "docs: update rules page with all new balance and retention mechanics"
```

---

### Task 30: Final full test + push + deploy

**Step 1: Run full local test suite**

```bash
cd /home/guortates/TVLW/The-Very-Little-War
php vendor/bin/phpunit --testsuite Unit --testsuite Balance --testsuite Functional
```
Expected: All pass

**Step 2: Push**

```bash
git push origin main
```

**Step 3: Deploy + run migrations**

```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 'cd /var/www/html && git pull origin main'
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 "mysql -u tvlw -pmLLIoTy2ByGTBNb9RQpTGnqPhXQfUR tvlw < /var/www/html/migrations/0029_create_season_recap.sql"
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 'curl -s -o /dev/null -w "%{http_code}" http://localhost/health.php'
```
Expected: `200`

**Step 4: Visual smoke test via Chrome DevTools**

Navigate to each modified page on the live site and verify:
- [ ] prestige.php — streak counter displays
- [ ] medailles.php — progress bars show
- [ ] rapports.php — defeat card shows for losses
- [ ] marche.php — new player tip shows
- [ ] classement.php — daily tab works
- [ ] joueur.php — last seen displays
- [ ] index.php — forum widget displays
- [ ] alliance_discovery.php — loads with alliance list
- [ ] season_recap.php — loads (empty if no archived data)
- [ ] regles.php — new sections visible

---

## Sprint 10: HTTPS (Final, after all testing)

### Task 31: Enable HTTPS with Certbot

**Prerequisite:** DNS for theverylittlewar.com must point to 212.227.38.111. Verify with:
```bash
dig +short theverylittlewar.com
```
Expected: `212.227.38.111`

**Step 1: Run Certbot**

```bash
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 'certbot --apache -d theverylittlewar.com -d www.theverylittlewar.com --non-interactive --agree-tos -m en.prie@pm.me'
```

**Step 2: Enable HSTS + secure cookies**

In `includes/config.php`, add:
```php
define('FORCE_HTTPS', true);
```

In `includes/basicprivatehtml.php` and `includes/basicpublicphp.php`, add at top:
```php
if (FORCE_HTTPS && !isset($_SERVER['HTTPS'])) {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
    exit;
}
if (FORCE_HTTPS) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
```

Set secure cookie flag in session config:
```php
ini_set('session.cookie_secure', FORCE_HTTPS ? 1 : 0);
```

**Step 3: Commit + deploy**

```bash
git add includes/config.php includes/basicprivatehtml.php includes/basicpublicphp.php
git commit -m "security: enable HTTPS redirect, HSTS, and secure cookies"
git push origin main
ssh -i ~/.ssh/claude_vps_tvlw root@212.227.38.111 'cd /var/www/html && git pull origin main'
```

**Step 4: Verify HTTPS works**

```bash
curl -sI https://theverylittlewar.com | head -10
```
Expected: `HTTP/2 200` or `HTTP/1.1 200` with `Strict-Transport-Security` header.

---

## Summary

| Sprint | Tasks | Focus | Est. Time |
|--------|-------|-------|-----------|
| 1 | 1-7 | Combat balance config tweaks | ~1h |
| 2 | 8-11 | Daily login streak system | ~1.5h |
| 3 | 12-14 | Comeback/welcome-back mechanics | ~1.5h |
| 4 | 15-17 | UX polish (defeat msg, prestige, medals) | ~1h |
| 5 | 18-21 | Social (last seen, forum, alliance discovery) | ~1.5h |
| 6 | 22-23 | Daily leaderboard + attack notifications | ~1h |
| 7 | 24-26 | Market tutorial + catchup weekends | ~1h |
| 8 | 27-28 | Season recap archive + page | ~1.5h |
| 9 | 29 | Rules page update | ~30min |
| 10 | 30-31 | Final deploy + HTTPS | ~30min |
| **Total** | **31 tasks** | **All audit recommendations** | **~10-11h** |

### Deferred Items (not in this plan — need playtesting first)
- P1-D4-020: Dominant glass cannon meta (needs combat system redesign, not a config tweak)
- P1-D4-028: Cooldown tuning (needs data on attack frequency)
- P1-D4-032: Chlore cooldown effect (complex combat formula change)
- P1-D8-042: Weekly challenges (large feature, defer to V2)
- P1-D8-055: Battle simulator (large feature, defer to V2)
- P1-D8-056: Tutorial skill curve redesign (large UX project, defer to V2)
- P1-D8-057: Alliance war dashboard (medium feature, defer to V2)
- P1-D8-059: Spectator mode (large feature, defer to V2)
- P1-D8-060: Seasonal narrative (content creation, defer to V2)
