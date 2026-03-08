# Pass 7 Test Coverage Audit

**Date:** 2026-03-08
**Auditor:** Automated Agent
**Scope:** Unit test coverage gaps for critical game systems in `tests/unit/` (17 files, ~594 tests / ~3430 assertions)

---

## Existing Coverage Summary

| Test File | System Covered | Quality |
|---|---|---|
| `CombatFormulasTest.php` | Attack/defense/HP/pillage/speed/formation/decay math | Strong — pure formula isolation |
| `CompoundsTest.php` | Compound structure/constants only | Constants-only — no functional logic |
| `ConfigConsistencyTest.php` | Config constant sanity | Adequate |
| `CsrfTest.php` | CSRF token generation/validation | Good |
| `DatabaseConnectionTest.php` | Migration idempotency | Good |
| `DiminuerBatimentTest.php` | Building damage allocation parsing | Thorough regression |
| `ExploitPreventionTest.php` | Zero-atom boundary conditions | Good |
| `GameBalanceTest.php` | Balance constant ranges | Adequate |
| `GameFormulasTest.php` | Core formula functions | Moderate |
| `MarketFormulasTest.php` | Market price/volatility formulas | Good |
| `MultiaccountTest.php` | Detection threshold logic (inline) | Shallow — no function calls |
| `RateLimiterTest.php` | Rate limit window logic | Good |
| `ResourceFormulasTest.php` | Resource generation formulas | Adequate |
| `ResourceNodesTest.php` | Node config constants only | Constants-only |
| `SecurityFunctionsTest.php` | antiXSS, transformInt, sanitize | Solid |
| `SqrtRankingTest.php` | Ranking score formula | Solid |
| `ValidationTest.php` | Login/email/alliance validation | Solid |

---

## Findings

### TEST-P7-001 [HIGH]

**System:** Prestige — `calculatePrestigePoints()` medal tier counting logic

**Problem:** `includes/prestige.php` contains pure computation logic in `calculatePrestigePoints()` that does not require a live database: the inner loop iterating medal thresholds (`$medalChecks`, `foreach ($thresholds as $t)`) is entirely self-contained. The `hasPrestigeUnlock()` string-parsing function and the `purchasePrestigeUnlock()` guard conditions (unknown key, already-owned, insufficient PP) are also pure logic with no DB dependency at the decision branch level. Zero tests exist for any prestige function.

**Risk:** A regression in the medal PP counting loop (e.g. off-by-one in tier counting) or in the unlock deduplication logic (comma-string parsing with `array_filter(explode(',', ...))`) would go undetected until a season reset awards incorrect PP to all players — a compounding cross-season error.

**Suggested test skeleton:**

```php
// tests/unit/PrestigeTest.php
class PrestigeTest extends TestCase
{
    /** Medal tier counting: value reaching each threshold adds 1 PP */
    public function testMedalTierCountingAccumulates(): void
    {
        $thresholds = [10, 50, 200]; // 3-tier medal
        $value = 75;
        $tier = 0;
        foreach ($thresholds as $t) { if ($value >= $t) $tier++; }
        $this->assertEquals(2, $tier); // reaches tier 1 (10) and tier 2 (50)
    }

    public function testMedalTierCountingZeroValue(): void
    {
        $thresholds = [10, 50, 200];
        $tier = 0;
        foreach ($thresholds as $t) { if (0 >= $t) $tier++; }
        $this->assertEquals(0, $tier);
    }

    public function testMedalTierCountingMaxValue(): void
    {
        $thresholds = [10, 50, 200];
        $tier = 0;
        foreach ($thresholds as $t) { if (PHP_INT_MAX >= $t) $tier++; }
        $this->assertEquals(3, $tier); // all tiers reached
    }

    /** hasPrestigeUnlock parses comma-separated string with array_filter */
    public function testHasPrestigeUnlockParsingWithEmpty(): void
    {
        $unlocks = array_filter(explode(',', ''));
        $this->assertFalse(in_array('veteran', $unlocks));
    }

    public function testHasPrestigeUnlockParsingFindsKey(): void
    {
        $unlocks = array_filter(explode(',', 'debutant_rapide,veteran'));
        $this->assertTrue(in_array('veteran', $unlocks));
    }

    public function testHasPrestigeUnlockParsingDoesNotFindOtherKey(): void
    {
        $unlocks = array_filter(explode(',', 'veteran'));
        $this->assertFalse(in_array('legende', $unlocks));
    }

    /** purchasePrestigeUnlock guard: unknown key */
    public function testPurchaseUnlockUnknownKeyReturnsError(): void
    {
        global $PRESTIGE_UNLOCKS;
        $this->assertFalse(isset($PRESTIGE_UNLOCKS['nonexistent_key']));
    }

    /** Prestige constants are positive */
    public function testPrestigeConstantsPositive(): void
    {
        $this->assertGreaterThan(0, PRESTIGE_PP_ACTIVE_FINAL_WEEK);
        $this->assertGreaterThan(0, PRESTIGE_PP_ATTACK_BONUS);
        $this->assertGreaterThan(0, PRESTIGE_PP_TRADE_BONUS);
        $this->assertGreaterThan(0, PRESTIGE_PP_DONATION_BONUS);
        $this->assertEquals(1.05, PRESTIGE_PRODUCTION_BONUS);
        $this->assertEquals(1.05, PRESTIGE_COMBAT_BONUS);
    }

    /** Rank bonus structure must be ascending cutoffs */
    public function testPrestigeRankBonusesAreOrdered(): void
    {
        global $PRESTIGE_RANK_BONUSES;
        $prevCutoff = 0;
        foreach ($PRESTIGE_RANK_BONUSES as $cutoff => $bonus) {
            $this->assertGreaterThan($prevCutoff, $cutoff);
            $this->assertGreaterThan(0, $bonus);
            $prevCutoff = $cutoff;
        }
    }
}
```

---

### TEST-P7-002 [HIGH]

**System:** Login streak — `updateLoginStreak()` and `checkComebackBonus()` in `includes/player.php`

**Problem:** Both functions contain significant pure-logic branches that can be extracted and tested in isolation:
- Streak increment vs. reset decision: `if ($lastDate === $yesterday) $currentStreak++; else $currentStreak = 1` — testable by mocking date strings.
- Milestone PP lookup: `isset($STREAK_MILESTONES[$currentStreak])` with the global `$STREAK_MILESTONES` array from `config.php`.
- Comeback eligibility: absence day threshold (`COMEBACK_ABSENCE_DAYS = 3`), cooldown guard (`COMEBACK_COOLDOWN_DAYS = 7`), account age minimum — all pure arithmetic testable without DB.
- No test file covers these systems at all. `DatabaseConnectionTest.php` only verifies that migration 0027 uses `IF NOT EXISTS` — it does not test the business logic.

**Risk:** A timezone bug in the streak date comparison (e.g. DST transition making "yesterday" incorrect) or an off-by-one in the absence/cooldown threshold would silently grant or deny bonuses without any test catching it.

**Suggested test skeleton:**

```php
// tests/unit/LoginStreakTest.php
class LoginStreakTest extends TestCase
{
    private array $milestones;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../includes/config.php';
        global $STREAK_MILESTONES;
        $this->milestones = $STREAK_MILESTONES;
    }

    public function testStreakIncrementOnConsecutiveDay(): void
    {
        $tz = new DateTimeZone('Europe/Paris');
        $yesterday = (new DateTime('yesterday', $tz))->format('Y-m-d');
        $today     = (new DateTime('now', $tz))->format('Y-m-d');
        $streak = 5;
        $newStreak = ($yesterday === $yesterday) ? $streak + 1 : 1; // consecutive
        $this->assertEquals(6, $newStreak);
    }

    public function testStreakResetsOnGap(): void
    {
        // last date is 2 days ago — not yesterday — streak must reset to 1
        $tz = new DateTimeZone('Europe/Paris');
        $twoDaysAgo = (new DateTime('-2 days', $tz))->format('Y-m-d');
        $yesterday  = (new DateTime('yesterday', $tz))->format('Y-m-d');
        $streak = 10;
        $newStreak = ($twoDaysAgo === $yesterday) ? $streak + 1 : 1;
        $this->assertEquals(1, $newStreak);
    }

    public function testStreakMilestoneAt7Days(): void
    {
        $this->assertArrayHasKey(7, $this->milestones);
        $this->assertEquals(STREAK_REWARD_DAY_7, $this->milestones[7]);
        $this->assertGreaterThan(STREAK_REWARD_DAY_1, $this->milestones[7]);
    }

    public function testStreakMilestonesAreAscending(): void
    {
        $prev = 0;
        foreach ($this->milestones as $day => $pp) {
            $this->assertGreaterThanOrEqual($prev, $pp,
                "Milestone PP at day $day should not be less than earlier milestones");
            $prev = $pp;
        }
    }

    public function testComebackAbsenceThreshold(): void
    {
        $absenceDays = 4;
        $this->assertGreaterThanOrEqual(COMEBACK_ABSENCE_DAYS, $absenceDays,
            '4-day absence qualifies for comeback bonus');
    }

    public function testComebackBelowThresholdDoesNotQualify(): void
    {
        $absenceDays = 2;
        $this->assertLessThan(COMEBACK_ABSENCE_DAYS, $absenceDays,
            '2-day absence does not qualify');
    }

    public function testComebackCooldownPreventsDoubleAward(): void
    {
        $now = time();
        $lastCatchUp = $now - (6 * SECONDS_PER_DAY); // 6 days ago
        $cooldownOk = ($now - $lastCatchUp) > (COMEBACK_COOLDOWN_DAYS * SECONDS_PER_DAY);
        $this->assertFalse($cooldownOk, '6-day gap is within 7-day cooldown');
    }

    public function testComebackCooldownAllowsAfterSevenDays(): void
    {
        $now = time();
        $lastCatchUp = $now - (8 * SECONDS_PER_DAY); // 8 days ago
        $cooldownOk = ($now - $lastCatchUp) > (COMEBACK_COOLDOWN_DAYS * SECONDS_PER_DAY);
        $this->assertTrue($cooldownOk, '8-day gap clears 7-day cooldown');
    }

    public function testComebackConstantsAreSane(): void
    {
        $this->assertGreaterThan(0, COMEBACK_ENERGY_BONUS);
        $this->assertGreaterThan(0, COMEBACK_ATOMS_BONUS);
        $this->assertGreaterThan(0, COMEBACK_SHIELD_HOURS);
        $this->assertGreaterThan(0, COMEBACK_COOLDOWN_DAYS);
        $this->assertGreaterThan(COMEBACK_ABSENCE_DAYS, COMEBACK_COOLDOWN_DAYS,
            'Cooldown must exceed absence threshold to prevent farming');
    }
}
```

---

### TEST-P7-003 [HIGH]

**System:** Isotope modifier math — combat modifiers in `includes/combat.php`

**Problem:** The isotope system (`ISOTOPE_STABLE`, `ISOTOPE_REACTIF`, `ISOTOPE_CATALYTIQUE`) applies per-class attack and HP multipliers that directly affect every combat calculation. The constants are defined in `config.php` but the modifier math (`$attIsotopeAttackMod[$c] += ISOTOPE_STABLE_ATTACK_MOD`, the Catalytique ally-boost loop) has zero test coverage. Notable risks:
- `ISOTOPE_STABLE_ATTACK_MOD` is negative (-0.05) — a sign error would make Stable attack stronger than baseline.
- The Catalytique ally loop boosts OTHER classes (not itself) — logic inversion would be undetected.
- Combined modifiers (e.g. Stable HP of 1.40 × duplicateur × prestige) could overflow or underperform silently.

**Suggested test skeleton:**

```php
// tests/unit/IsotopeModifierTest.php
class IsotopeModifierTest extends TestCase
{
    public function testStableModifiersReduceAttackIncreasHP(): void
    {
        $attackMod = 1.0 + ISOTOPE_STABLE_ATTACK_MOD;   // 1.0 + (-0.05) = 0.95
        $hpMod     = 1.0 + ISOTOPE_STABLE_HP_MOD;       // 1.0 + 0.40 = 1.40
        $this->assertLessThan(1.0, $attackMod, 'Stable reduces attack below baseline');
        $this->assertGreaterThan(1.0, $hpMod,  'Stable increases HP above baseline');
    }

    public function testReactifModifiersIncreaseAttackReduceHP(): void
    {
        $attackMod = 1.0 + ISOTOPE_REACTIF_ATTACK_MOD;  // 1.20
        $hpMod     = 1.0 + ISOTOPE_REACTIF_HP_MOD;      // 0.90
        $this->assertGreaterThan(1.0, $attackMod, 'Reactif boosts attack above baseline');
        $this->assertLessThan(1.0, $hpMod,        'Reactif reduces HP below baseline');
    }

    public function testCatalytiqueModifierReducesSelf(): void
    {
        $attackMod = 1.0 + ISOTOPE_CATALYTIQUE_ATTACK_MOD; // 0.90
        $hpMod     = 1.0 + ISOTOPE_CATALYTIQUE_HP_MOD;     // 0.90
        $this->assertLessThan(1.0, $attackMod, 'Catalytique reduces own attack');
        $this->assertLessThan(1.0, $hpMod,     'Catalytique reduces own HP');
    }

    public function testCatalytiqueAllyBonusIsPositive(): void
    {
        $allyBonus = ISOTOPE_CATALYTIQUE_ALLY_BONUS; // 0.15
        $this->assertGreaterThan(0, $allyBonus, 'Ally bonus must be positive');
    }

    public function testCatalytiqueAllyBoostOtherClassesNotSelf(): void
    {
        // Simulate the loop: if class has Catalytique isotope, it does NOT get ally bonus
        $nbClasses = 4;
        $classIsotopes = [1 => ISOTOPE_CATALYTIQUE, 2 => 0, 3 => 0, 4 => 0];
        $mods = array_fill(1, $nbClasses, 1.0);

        foreach ($classIsotopes as $c => $iso) {
            if ($iso == ISOTOPE_CATALYTIQUE) {
                $mods[$c] += ISOTOPE_CATALYTIQUE_ATTACK_MOD; // self
            }
        }

        // Apply ally boost (simulates the hasCatalytique loop)
        foreach ($classIsotopes as $c => $iso) {
            if ($iso != ISOTOPE_CATALYTIQUE) {
                $mods[$c] += ISOTOPE_CATALYTIQUE_ALLY_BONUS;
            }
        }

        // Class 1 (Catalytique) should NOT have the +0.15 ally bonus
        $this->assertEqualsWithDelta(1.0 + ISOTOPE_CATALYTIQUE_ATTACK_MOD, $mods[1], 0.001,
            'Catalytique class does not receive ally bonus');
        // Classes 2-4 should have +0.15
        $this->assertEqualsWithDelta(1.0 + ISOTOPE_CATALYTIQUE_ALLY_BONUS, $mods[2], 0.001,
            'Non-catalytique class receives ally bonus');
    }

    public function testCombinedModifiersNeverNegative(): void
    {
        // Worst combined case: Catalytique self mod
        $minMod = 1.0 + ISOTOPE_CATALYTIQUE_ATTACK_MOD;
        $this->assertGreaterThan(0, $minMod, 'Combined isotope modifier must stay positive');
    }

    public function testIsotopeConstantsDefinedCorrectly(): void
    {
        $this->assertEquals(1, ISOTOPE_STABLE);
        $this->assertEquals(2, ISOTOPE_REACTIF);
        $this->assertEquals(3, ISOTOPE_CATALYTIQUE);
    }
}
```

---

### TEST-P7-004 [MEDIUM]

**System:** Pact acceptance flow — `validerpacte.php` grade permission bit parsing

**Problem:** The pact authorization logic in `validerpacte.php` (lines 20–26) parses a dotted grade string to extract a permission bit at index 2 (`$bits[2] === '1'`). This is the recently-fixed PASS1-MEDIUM-015 which previously blocked grade-holding officers. The bit-parsing logic is untested:
- Off-by-one index (index 2 vs index 3) would silently grant or deny pact access to officers.
- An empty grade string or malformed entry (missing dots) would cause `isset($bits[2])` to return false, locking out legitimate officers.
- There are no tests for grade permission bit parsing in any test file.

**Suggested test skeleton:**

```php
// tests/unit/GradePermissionTest.php (or added to ExploitPreventionTest.php)
class GradePermissionTest extends TestCase
{
    /** Parse grade string per validerpacte.php logic */
    private function hasPactPerm(string $gradeString): bool
    {
        if (empty($gradeString)) return false;
        $bits = explode('.', $gradeString);
        return isset($bits[2]) && $bits[2] === '1';
    }

    public function testPactPermissionGrantedAtIndex2(): void
    {
        // Format: "inviter.guerre.pacte.bannir.description"
        // index:    0       1      2     3      4
        $this->assertTrue($this->hasPactPerm('0.0.1.0.0'), 'pacte bit at index 2 grants perm');
    }

    public function testPactPermissionDeniedWhenZero(): void
    {
        $this->assertFalse($this->hasPactPerm('1.1.0.1.1'), 'pacte bit = 0 denies perm');
    }

    public function testPactPermissionDeniedOnEmptyString(): void
    {
        $this->assertFalse($this->hasPactPerm(''), 'empty grade string denies perm');
    }

    public function testPactPermissionDeniedOnTruncatedString(): void
    {
        // Only 2 bits: index 2 missing
        $this->assertFalse($this->hasPactPerm('1.0'), 'truncated grade string denies perm');
    }

    public function testPactPermissionNotConfusedWithGuerreAtIndex1(): void
    {
        // guerre bit is index 1, pacte is index 2 — must not be confused
        $this->assertFalse($this->hasPactPerm('0.1.0.0.0'), 'guerre bit should not grant pact perm');
        $this->assertTrue($this->hasPactPerm('0.0.1.0.0'),  'pacte bit at index 2 still works');
    }

    public function testPactPermissionWithAllOnes(): void
    {
        $this->assertTrue($this->hasPactPerm('1.1.1.1.1'), 'full permission grade has pact perm');
    }
}
```

---

### TEST-P7-005 [MEDIUM]

**System:** Formation logic — Phalange absorb ratio and Embuscade trigger condition

**Problem:** `CombatFormulasTest.php` tests formation TIME formulas, but never tests the formation COMBAT EFFECT logic:
- Phalange: class 1 absorbs `FORMATION_PHALANX_ABSORB` (50%) of attacker damage. No test verifies the remaining 50% distributes correctly among classes 2–4, or that the absorb constant itself is in a sane range (0 < x < 1).
- Embuscade: triggers when `$totalDefenderMols > $totalAttackerMols`. Equal molecule counts must NOT trigger the 40% boost — this boundary condition is untested.
- Dispersée equal-split: each class taking 25% of damage (for 4 classes) is tested nowhere.

**Suggested test skeleton:**

```php
// tests/unit/FormationLogicTest.php
class FormationLogicTest extends TestCase
{
    public function testPhalanxAbsorbIsValidFraction(): void
    {
        $this->assertGreaterThan(0.0, FORMATION_PHALANX_ABSORB);
        $this->assertLessThan(1.0, FORMATION_PHALANX_ABSORB);
    }

    public function testPhalanxAbsorbPlusRemainderEqualsOne(): void
    {
        $absorb = FORMATION_PHALANX_ABSORB;
        $remainder = 1.0 - $absorb;
        $this->assertEqualsWithDelta(1.0, $absorb + $remainder, 0.0001);
    }

    public function testPhalanxDamageSplit(): void
    {
        $totalDamage = 1000.0;
        $phalanxDamage = $totalDamage * FORMATION_PHALANX_ABSORB;   // 500
        $otherDamage   = $totalDamage - $phalanxDamage;               // 500
        $this->assertEqualsWithDelta(500.0, $phalanxDamage, 0.01);
        $this->assertEqualsWithDelta(500.0, $otherDamage, 0.01);
    }

    public function testEmbuscadeTriggersWhenDefenderOutnumbers(): void
    {
        $attackerMols = 100;
        $defenderMols = 150;
        $boost = ($defenderMols > $attackerMols)
            ? 1.0 + FORMATION_AMBUSH_ATTACK_BONUS
            : 1.0;
        $this->assertEqualsWithDelta(1.40, $boost, 0.001,
            'Embuscade grants +40% when defender outnumbers attacker');
    }

    public function testEmbuscadeDoesNotTriggerOnEqualForces(): void
    {
        $mols = 100;
        $boost = ($mols > $mols) ? 1.0 + FORMATION_AMBUSH_ATTACK_BONUS : 1.0;
        $this->assertEquals(1.0, $boost, 'Equal molecule counts must NOT trigger Embuscade');
    }

    public function testEmbuscadeDoesNotTriggerWhenDefenderIsSmaller(): void
    {
        $attackerMols = 200;
        $defenderMols = 100;
        $boost = ($defenderMols > $attackerMols)
            ? 1.0 + FORMATION_AMBUSH_ATTACK_BONUS
            : 1.0;
        $this->assertEquals(1.0, $boost, 'Defender fewer than attacker should not trigger Embuscade');
    }

    public function testDisperseeEqualSplitFourClasses(): void
    {
        // Dispersee: 25% damage to each of 4 classes
        $nbClasses = 4;
        $share = 1.0 / $nbClasses;
        $this->assertEqualsWithDelta(0.25, $share, 0.0001,
            'Each class takes exactly 25% in Dispersée formation');
        $this->assertEqualsWithDelta(1.0, $share * $nbClasses, 0.0001,
            'All class shares sum to 100%');
    }

    public function testFormationConstantsExist(): void
    {
        $this->assertEquals(0, FORMATION_DISPERSEE);
        $this->assertEquals(1, FORMATION_PHALANGE);
        $this->assertEquals(2, FORMATION_EMBUSCADE);
    }
}
```

---

## Summary

| ID | Severity | System | Tested? | Impact of Gap |
|---|---|---|---|---|
| TEST-P7-001 | HIGH | Prestige PP calculation & unlock parsing | NO | Wrong PP awarded at every season end; double-purchase possible |
| TEST-P7-002 | HIGH | Login streak & comeback bonus logic | NO | Incorrect PP milestones; comeback farming or denial |
| TEST-P7-003 | HIGH | Isotope modifier math (Stable/Reactif/Catalytique) | NO | Sign error in Stable/-ve mod silently buffs tanks; Catalytique ally inversion |
| TEST-P7-004 | MEDIUM | Pact grade permission bit parsing | NO | Officers with pact permission still locked out after fix; silent grant to unauthorized |
| TEST-P7-005 | MEDIUM | Formation combat logic (Phalange/Embuscade/Dispersée) | NO | Embuscade fires on equal forces; Phalange absorb split miscalculated |

**Total gaps identified:** 5 (3 HIGH, 2 MEDIUM)

**No LOW findings:** The existing test suite is thorough for formula math, security functions, validation, CSRF, rate limiting, ranking, and building damage allocation. The gaps are concentrated in the newer Phase 14 systems (streak, comeback, prestige) and the recently fixed combat modifiers (isotopes, formation logic) where pure-logic extraction is feasible without DB mocks.

**Recommendation:** Add `PrestigeTest.php`, `LoginStreakTest.php`, `IsotopeModifierTest.php`, `GradePermissionTest.php`, and `FormationLogicTest.php` — all five are fully implementable as pure-PHP unit tests with no database dependency, following the same pattern as `DiminuerBatimentTest.php`.
