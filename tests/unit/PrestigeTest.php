<?php
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Prestige system pure logic.
 *
 * Following the project convention (see CombatFormulasTest.php), this file
 * does NOT require includes/prestige.php because that file declares functions
 * that conflict with bootstrap stubs (hasPrestigeUnlock, etc.).
 * Instead we test the underlying logic directly, the same way CombatFormulasTest
 * duplicates formulas rather than calling the live game functions.
 *
 * All game constants are available via the bootstrap (constantesBase.php →
 * config.php), so PRESTIGE_PP_*, STREAK_REWARD_DAY_*, COMEBACK_*, etc. are
 * all in scope without any additional require.
 *
 * The $PRESTIGE_UNLOCKS array is defined in prestige.php, so we declare a
 * local constant copy here that mirrors the production definition.
 *
 * Systems tested:
 *   1. Medal tier counting (calculatePrestigePoints inner loop)
 *   2. hasPrestigeUnlock string parsing (explode / array_filter / in_array)
 *   3. purchasePrestigeUnlock input validation branches (unknown key, already
 *      owned, insufficient PP) — tested as pure conditional logic
 *   4. Streak milestone PP award logic (updateLoginStreak branch table)
 *   5. Comeback bonus eligibility conditions (checkComebackBonus guard clauses)
 *   6. Prestige constants and $PRESTIGE_UNLOCKS structure
 */
class PrestigeTest extends TestCase
{
    /**
     * Local mirror of $PRESTIGE_UNLOCKS from includes/prestige.php.
     * Kept in sync manually; if production adds tiers this must be updated.
     */
    private static function prestigeUnlocks(): array
    {
        return [
            'debutant_rapide' => ['cost' => 50,   'effect' => 'generateur_level_2'],
            'experimente'     => ['cost' => 100,  'effect' => 'production_5pct'],
            'veteran'         => ['cost' => 250,  'effect' => 'extra_protection_day'],
            'maitre_chimiste' => ['cost' => 500,  'effect' => 'combat_5pct'],
            'legende'         => ['cost' => 1000, 'effect' => 'legend_badge'],
        ];
    }

    // =========================================================================
    // 1. MEDAL TIER COUNTING
    //
    // Logic (calculatePrestigePoints in prestige.php):
    //   $tier = 0;
    //   foreach ($thresholds as $t) {
    //       if ($value >= $t) $tier++;
    //   }
    //   $pp += $tier;  // 1 PP per tier reached
    // =========================================================================

    /**
     * Count how many medal tiers are reached for a given stat value.
     */
    private function countTiers(int $value, array $thresholds): int
    {
        $tier = 0;
        foreach ($thresholds as $t) {
            if ($value >= $t) {
                $tier++;
            }
        }
        return $tier;
    }

    public function testMedalTierZeroWhenBelowAllThresholds(): void
    {
        $thresholds = [10, 50, 200, 1000];
        $this->assertEquals(0, $this->countTiers(0, $thresholds));
        $this->assertEquals(0, $this->countTiers(9, $thresholds));
    }

    public function testMedalTierOneAtFirstThreshold(): void
    {
        $thresholds = [10, 50, 200, 1000];
        $this->assertEquals(1, $this->countTiers(10, $thresholds));
        $this->assertEquals(1, $this->countTiers(49, $thresholds));
    }

    public function testMedalTierIncrementsStepwiseAtEachThreshold(): void
    {
        $thresholds = [10, 50, 200, 1000];
        $this->assertEquals(2, $this->countTiers(50, $thresholds));
        $this->assertEquals(3, $this->countTiers(200, $thresholds));
        $this->assertEquals(4, $this->countTiers(1000, $thresholds));
        $this->assertEquals(4, $this->countTiers(999999, $thresholds));
    }

    public function testMedalTierJustBelowBoundaryDoesNotAdvance(): void
    {
        $thresholds = [10, 50, 200, 1000];
        foreach ([9, 49, 199, 999] as $i => $val) {
            $this->assertEquals($i, $this->countTiers($val, $thresholds),
                "Value $val should yield tier $i (just below boundary)");
        }
    }

    public function testMedalTierAtExactBoundaryAdvances(): void
    {
        $thresholds = [10, 50, 200, 1000];
        foreach ([10, 50, 200, 1000] as $i => $val) {
            $this->assertEquals($i + 1, $this->countTiers($val, $thresholds),
                "Value $val should yield tier " . ($i + 1) . " (at exact boundary)");
        }
    }

    public function testMedalTierWithSingleThreshold(): void
    {
        $this->assertEquals(0, $this->countTiers(0, [100]));
        $this->assertEquals(1, $this->countTiers(100, [100]));
        $this->assertEquals(1, $this->countTiers(100000, [100]));
    }

    public function testMedalTierWithEmptyThresholdsAlwaysZero(): void
    {
        $this->assertEquals(0, $this->countTiers(0, []));
        $this->assertEquals(0, $this->countTiers(999999, []));
    }

    public function testMedalTierMaxEqualsThresholdCount(): void
    {
        $thresholds = [1, 2, 3, 4, 5];
        $this->assertEquals(5, $this->countTiers(PHP_INT_MAX, $thresholds));
    }

    // =========================================================================
    // 2. hasPrestigeUnlock STRING PARSING
    //
    // Logic:
    //   $unlocks = array_filter(explode(',', $prestige['unlocks']));
    //   return in_array($unlockKey, $unlocks);
    // =========================================================================

    /**
     * Direct implementation of the hasPrestigeUnlock inner logic,
     * accepting the unlock string instead of calling getPrestige().
     */
    private function parseHasUnlock(string $unlocksString, string $key): bool
    {
        $unlocks = array_filter(explode(',', $unlocksString));
        return in_array($key, $unlocks);
    }

    public function testUnlockParsingEmptyStringReturnsFalse(): void
    {
        $this->assertFalse($this->parseHasUnlock('', 'debutant_rapide'));
    }

    public function testUnlockParsingCommaOnlyReturnsFalse(): void
    {
        $this->assertFalse($this->parseHasUnlock(',', 'debutant_rapide'));
        $this->assertFalse($this->parseHasUnlock(',,,,', 'veteran'));
    }

    public function testUnlockParsingSingleKeyFound(): void
    {
        $this->assertTrue($this->parseHasUnlock('debutant_rapide', 'debutant_rapide'));
    }

    public function testUnlockParsingSingleKeyNotFound(): void
    {
        $this->assertFalse($this->parseHasUnlock('debutant_rapide', 'veteran'));
    }

    public function testUnlockParsingMultipleKeysAllDetected(): void
    {
        $unlocks = 'debutant_rapide,experimente,veteran';
        $this->assertTrue($this->parseHasUnlock($unlocks, 'debutant_rapide'));
        $this->assertTrue($this->parseHasUnlock($unlocks, 'experimente'));
        $this->assertTrue($this->parseHasUnlock($unlocks, 'veteran'));
        $this->assertFalse($this->parseHasUnlock($unlocks, 'maitre_chimiste'));
        $this->assertFalse($this->parseHasUnlock($unlocks, 'legende'));
    }

    public function testUnlockParsingDoesNotMatchSubstring(): void
    {
        // 'veteran' must not match 'veteran_extra' or 'pre_veteran'
        $this->assertFalse($this->parseHasUnlock('veteran_extra', 'veteran'));
        $this->assertFalse($this->parseHasUnlock('pre_veteran', 'veteran'));
    }

    // =========================================================================
    // 3. purchasePrestigeUnlock VALIDATION BRANCHES
    //
    // Tested as pure conditional logic (no DB calls needed):
    //   a. Unknown key   → 'Amélioration inconnue.'
    //   b. Already owned → 'Vous avez déjà cette amélioration.'
    //   c. Insufficient  → 'Pas assez de points de prestige (X/Y).'
    // =========================================================================

    /** Replicate the unknown-key check. */
    private function validateUnlockKey(string $key): ?string
    {
        $unlocks = self::prestigeUnlocks();
        if (!isset($unlocks[$key])) {
            return 'Amélioration inconnue.';
        }
        return null;
    }

    /** Replicate the already-owned check. */
    private function validateNotAlreadyOwned(string $key, string $ownedCsv): ?string
    {
        $owned = array_filter(explode(',', $ownedCsv));
        if (in_array($key, $owned)) {
            return 'Vous avez déjà cette amélioration.';
        }
        return null;
    }

    /** Replicate the insufficient-PP check. */
    private function validateSufficientPP(string $key, int $playerPP): ?string
    {
        $unlocks = self::prestigeUnlocks();
        $cost = $unlocks[$key]['cost'];
        if ($playerPP < $cost) {
            return "Pas assez de points de prestige ($playerPP/$cost).";
        }
        return null;
    }

    public function testPurchaseUnknownKeyReturnsError(): void
    {
        $this->assertNotNull($this->validateUnlockKey('nonexistent_key'));
        $this->assertStringContainsString('inconnue', $this->validateUnlockKey('nonexistent_key'));
    }

    public function testPurchaseKnownKeyPassesKeyValidation(): void
    {
        foreach (array_keys(self::prestigeUnlocks()) as $key) {
            $this->assertNull($this->validateUnlockKey($key),
                "Key '$key' should pass validation");
        }
    }

    public function testPurchaseAlreadyOwnedReturnsError(): void
    {
        $err = $this->validateNotAlreadyOwned('veteran', 'debutant_rapide,veteran,experimente');
        $this->assertNotNull($err);
        $this->assertStringContainsString('déjà', $err);
    }

    public function testPurchaseNotYetOwnedPassesOwnershipCheck(): void
    {
        $err = $this->validateNotAlreadyOwned('legende', 'debutant_rapide,veteran');
        $this->assertNull($err);
    }

    public function testPurchaseInsufficientPPReturnsError(): void
    {
        // veteran costs 250; player has 100
        $err = $this->validateSufficientPP('veteran', 100);
        $this->assertNotNull($err);
        $this->assertStringContainsString('100/250', $err);
    }

    public function testPurchaseExactPPPassesCheck(): void
    {
        // Exactly at cost — should pass
        $err = $this->validateSufficientPP('veteran', 250);
        $this->assertNull($err);
    }

    public function testPurchaseMoreThanEnoughPPPassesCheck(): void
    {
        $err = $this->validateSufficientPP('debutant_rapide', 10000);
        $this->assertNull($err);
    }

    // =========================================================================
    // 4. STREAK MILESTONE PP AWARD LOGIC
    //
    // Logic (updateLoginStreak in player.php):
    //   if (isset($STREAK_MILESTONES[$currentStreak])) {
    //       $ppEarned = $STREAK_MILESTONES[$currentStreak];
    //   } else {
    //       $ppEarned = STREAK_REWARD_DAY_1;
    //   }
    // =========================================================================

    private function computeStreakPP(int $streak): int
    {
        global $STREAK_MILESTONES;
        return isset($STREAK_MILESTONES[$streak])
            ? $STREAK_MILESTONES[$streak]
            : STREAK_REWARD_DAY_1;
    }

    public function testStreakDay1EarnsBaseLoginPP(): void
    {
        $this->assertEquals(STREAK_REWARD_DAY_1, $this->computeStreakPP(1));
    }

    public function testStreakNonMilestoneDaysEarnBaseLoginPP(): void
    {
        foreach ([2, 4, 5, 6, 8, 9, 10, 11, 29, 60] as $day) {
            $this->assertEquals(STREAK_REWARD_DAY_1, $this->computeStreakPP($day),
                "Day $day (non-milestone) should earn base streak PP");
        }
    }

    public function testStreakMilestonesEarnBonusPP(): void
    {
        $this->assertEquals(STREAK_REWARD_DAY_3,  $this->computeStreakPP(3));
        $this->assertEquals(STREAK_REWARD_DAY_7,  $this->computeStreakPP(7));
        $this->assertEquals(STREAK_REWARD_DAY_14, $this->computeStreakPP(14));
        $this->assertEquals(STREAK_REWARD_DAY_21, $this->computeStreakPP(21));
        $this->assertEquals(STREAK_REWARD_DAY_28, $this->computeStreakPP(28));
    }

    public function testStreakMilestonesEscalate(): void
    {
        $milestones = [1, 3, 7, 14, 21, 28];
        $rewards = array_map([$this, 'computeStreakPP'], $milestones);
        for ($i = 1; $i < count($rewards); $i++) {
            $this->assertGreaterThanOrEqual($rewards[$i - 1], $rewards[$i],
                "Milestone day {$milestones[$i]} should award at least as much PP as day {$milestones[$i-1]}");
        }
    }

    public function testStreakDay28IsFinalMilestone(): void
    {
        $this->assertEquals(STREAK_REWARD_DAY_28, $this->computeStreakPP(28));
        // Days beyond 28 fall back to base login PP
        $this->assertEquals(STREAK_REWARD_DAY_1, $this->computeStreakPP(29));
    }

    public function testAllStreakConstantsArePositive(): void
    {
        $this->assertGreaterThan(0, STREAK_REWARD_DAY_1);
        $this->assertGreaterThan(0, STREAK_REWARD_DAY_3);
        $this->assertGreaterThan(0, STREAK_REWARD_DAY_7);
        $this->assertGreaterThan(0, STREAK_REWARD_DAY_14);
        $this->assertGreaterThan(0, STREAK_REWARD_DAY_21);
        $this->assertGreaterThan(0, STREAK_REWARD_DAY_28);
    }

    // =========================================================================
    // 5. COMEBACK BONUS CONDITIONS
    //
    // Logic (checkComebackBonus in player.php):
    //   $absentDays = ($now - $prevConnexion) / SECONDS_PER_DAY;
    //   $cooldownOk = ($now - $lastCatchUp) > (COMEBACK_COOLDOWN_DAYS * SECONDS_PER_DAY);
    //   if ($absentDays < COMEBACK_ABSENCE_DAYS || !$cooldownOk) → not applied
    // =========================================================================

    private function isEligibleForComeback(
        float $absentSeconds,
        float $secondsSinceLastBonus
    ): bool {
        $absentDays = $absentSeconds / SECONDS_PER_DAY;
        $cooldownOk = $secondsSinceLastBonus > (COMEBACK_COOLDOWN_DAYS * SECONDS_PER_DAY);
        return $absentDays >= COMEBACK_ABSENCE_DAYS && $cooldownOk;
    }

    public function testComebackNotTriggeredIfAbsenceTooShort(): void
    {
        $absent   = 1 * SECONDS_PER_DAY;          // 1 day < threshold (3)
        $cooldown = 99 * SECONDS_PER_DAY;
        $this->assertFalse($this->isEligibleForComeback($absent, $cooldown));
    }

    public function testComebackNotTriggeredIfCooldownNotExpired(): void
    {
        $absent   = 10 * SECONDS_PER_DAY;
        $cooldown = 2 * SECONDS_PER_DAY;          // 2 days < COMEBACK_COOLDOWN_DAYS (7)
        $this->assertFalse($this->isEligibleForComeback($absent, $cooldown));
    }

    public function testComebackTriggeredWhenBothConditionsMet(): void
    {
        $absent   = COMEBACK_ABSENCE_DAYS * SECONDS_PER_DAY;
        $cooldown = (COMEBACK_COOLDOWN_DAYS + 1) * SECONDS_PER_DAY;
        $this->assertTrue($this->isEligibleForComeback($absent, $cooldown));
    }

    public function testComebackTriggeredForLongAbsence(): void
    {
        $absent   = 30 * SECONDS_PER_DAY;
        $cooldown = 99 * SECONDS_PER_DAY;
        $this->assertTrue($this->isEligibleForComeback($absent, $cooldown));
    }

    public function testComebackNotTriggeredJustBelowAbsenceThreshold(): void
    {
        $absent   = (COMEBACK_ABSENCE_DAYS * SECONDS_PER_DAY) - 1;
        $cooldown = 99 * SECONDS_PER_DAY;
        $this->assertFalse($this->isEligibleForComeback($absent, $cooldown));
    }

    public function testComebackNotTriggeredWhenCooldownAtExactBoundary(): void
    {
        // Strict greater-than: exactly COMEBACK_COOLDOWN_DAYS is NOT enough
        $absent   = 10 * SECONDS_PER_DAY;
        $cooldown = COMEBACK_COOLDOWN_DAYS * SECONDS_PER_DAY;
        $this->assertFalse($this->isEligibleForComeback($absent, $cooldown));
    }

    public function testComebackConstantsAreSelfConsistent(): void
    {
        $this->assertGreaterThan(0, COMEBACK_ABSENCE_DAYS);
        $this->assertGreaterThan(0, COMEBACK_COOLDOWN_DAYS);
        $this->assertGreaterThan(0, COMEBACK_ENERGY_BONUS);
        $this->assertGreaterThan(0, COMEBACK_ATOMS_BONUS);
        $this->assertGreaterThan(0, COMEBACK_SHIELD_HOURS);
        // Cooldown must be at least as long as the absence threshold
        $this->assertGreaterThanOrEqual(COMEBACK_ABSENCE_DAYS, COMEBACK_COOLDOWN_DAYS,
            'COMEBACK_COOLDOWN_DAYS must be >= COMEBACK_ABSENCE_DAYS to prevent immediate re-trigger');
    }

    // =========================================================================
    // 6. PRESTIGE UNLOCKS STRUCTURE
    // =========================================================================

    public function testAllExpectedPrestigeKeysExist(): void
    {
        $unlocks = self::prestigeUnlocks();
        foreach (['debutant_rapide', 'experimente', 'veteran', 'maitre_chimiste', 'legende'] as $key) {
            $this->assertArrayHasKey($key, $unlocks, "Expected prestige key '$key' to exist");
        }
    }

    public function testPrestigeUnlockCostsArePositive(): void
    {
        foreach (self::prestigeUnlocks() as $key => $unlock) {
            $this->assertGreaterThan(0, $unlock['cost'],
                "Prestige unlock '$key' must have a positive PP cost");
        }
    }

    public function testPrestigeUnlockCostsAscend(): void
    {
        $costs = array_column(self::prestigeUnlocks(), 'cost');
        for ($i = 1; $i < count($costs); $i++) {
            $this->assertGreaterThanOrEqual($costs[$i - 1], $costs[$i],
                'Prestige unlock costs should not decrease from tier to tier');
        }
    }

    public function testPrestigeProductionBonusConstantIsAboveOne(): void
    {
        $this->assertGreaterThan(1.0, PRESTIGE_PRODUCTION_BONUS);
        $this->assertEqualsWithDelta(1.05, PRESTIGE_PRODUCTION_BONUS, 0.001);
    }

    public function testPrestigeCombatBonusConstantIsAboveOne(): void
    {
        $this->assertGreaterThan(1.0, PRESTIGE_COMBAT_BONUS);
        $this->assertEqualsWithDelta(1.05, PRESTIGE_COMBAT_BONUS, 0.001);
    }

    public function testActivityThresholdsAndBonusesArePositive(): void
    {
        $this->assertGreaterThan(0, PRESTIGE_PP_ACTIVE_FINAL_WEEK);
        $this->assertGreaterThan(0, PRESTIGE_PP_ATTACK_THRESHOLD);
        $this->assertGreaterThan(0, PRESTIGE_PP_ATTACK_BONUS);
        $this->assertGreaterThan(0, PRESTIGE_PP_TRADE_THRESHOLD);
        $this->assertGreaterThan(0, PRESTIGE_PP_TRADE_BONUS);
        $this->assertGreaterThan(0, PRESTIGE_PP_DONATION_BONUS);
        $this->assertGreaterThan(0, PP_DONATION_MIN_THRESHOLD);
    }
}
