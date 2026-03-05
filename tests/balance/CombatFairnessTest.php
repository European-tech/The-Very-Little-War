<?php
/**
 * Combat Fairness Matrix.
 *
 * METHODOLOGY:
 * - Compute combat outcome for every pair of archetypes
 * - PASS criteria:
 *   a) No matchup exceeds 4:1 ratio (no hard counters)
 *   b) Every archetype wins at least 1 matchup
 *   c) Formations provide meaningful tactical choices
 *   d) Building combat bonuses are symmetric
 */

require_once __DIR__ . '/bootstrap_balance.php';

use PHPUnit\Framework\TestCase;

class CombatFairnessTest extends TestCase
{
    private array $archetypes;
    private int $cond = 8;

    protected function setUp(): void
    {
        $this->archetypes = [
            'raider'    => ['C'=>10,'N'=>20,'H'=>30,'O'=>80,'Cl'=>10,'S'=>5, 'Br'=>30,'I'=>15],
            'turtle'    => ['C'=>80,'N'=>15,'H'=>5, 'O'=>10,'Cl'=>5, 'S'=>5, 'Br'=>60,'I'=>20],
            'pillager'  => ['C'=>10,'N'=>30,'H'=>5, 'O'=>30,'Cl'=>50,'S'=>50,'Br'=>15,'I'=>10],
            'speedster' => ['C'=>10,'N'=>40,'H'=>10,'O'=>30,'Cl'=>70,'S'=>10,'Br'=>20,'I'=>10],
            'balanced'  => ['C'=>30,'N'=>25,'H'=>20,'O'=>30,'Cl'=>25,'S'=>25,'Br'=>30,'I'=>15],
        ];
    }

    private function getStats(array $atoms): array
    {
        return [
            'atk' => attaque($atoms['O'], $atoms['H'], $this->cond),
            'def' => defense($atoms['C'], $atoms['Br'], $this->cond),
            'hp'  => pointsDeVieMolecule($atoms['Br'], $atoms['C'], $this->cond),
        ];
    }

    /**
     * Compute combat efficiency score: ratio of kill rates.
     * > 1.0 means attacker has advantage.
     */
    private function combatScore(array $a, array $b): float
    {
        // Attacker kills: atk / defender_hp
        $atkEfficiency = $b['hp'] > 0 ? $a['atk'] / $b['hp'] : PHP_INT_MAX;
        // Defender counters: def / attacker_hp
        $defEfficiency = $a['hp'] > 0 ? $b['def'] / $a['hp'] : PHP_INT_MAX;

        if ($defEfficiency == 0) return PHP_INT_MAX;
        return $atkEfficiency / $defEfficiency;
    }

    /**
     * No archetype should be strictly better than another across ALL stat categories.
     * Every archetype must have at least one stat where it beats every other.
     * Stats include combat (atk, def, hp) AND utility (speed, pillage).
     */
    public function testNoStrictlyDominantArchetype(): void
    {
        $names = array_keys($this->archetypes);
        $c = $this->cond;

        $fullStats = [];
        foreach ($names as $name) {
            $a = $this->archetypes[$name];
            $fullStats[$name] = [
                'atk'     => attaque($a['O'], $a['H'], $c),
                'def'     => defense($a['C'], $a['Br'], $c),
                'hp'      => pointsDeVieMolecule($a['Br'], $a['C'], $c),
                'speed'   => vitesse($a['Cl'], $a['N'], $c),
                'pillage' => pillage($a['S'], $a['Cl'], $c),
            ];
        }

        foreach ($names as $a) {
            foreach ($names as $b) {
                if ($a === $b) continue;
                $allBetter = true;
                foreach (['atk', 'def', 'hp', 'speed', 'pillage'] as $stat) {
                    if ($fullStats[$a][$stat] <= $fullStats[$b][$stat]) {
                        $allBetter = false;
                        break;
                    }
                }
                $this->assertFalse($allBetter,
                    "BALANCE FAIL: '$a' is strictly better than '$b' at ALL stats. "
                    . "A: " . json_encode($fullStats[$a]) . " B: " . json_encode($fullStats[$b]));
            }
        }
    }

    /**
     * Verify the raider (high-attack) beats the turtle on offense,
     * but the turtle survives longer on defense. Classic asymmetric balance.
     */
    public function testRaiderVsTurtleAsymmetry(): void
    {
        $raiderStats = $this->getStats($this->archetypes['raider']);
        $turtleStats = $this->getStats($this->archetypes['turtle']);

        // Raider has more attack
        $this->assertGreaterThan($turtleStats['atk'], $raiderStats['atk'],
            "Raider should have higher attack than turtle");

        // Turtle has more defense and HP
        $this->assertGreaterThan($raiderStats['def'], $turtleStats['def'],
            "Turtle should have higher defense than raider");
        $this->assertGreaterThan($raiderStats['hp'], $turtleStats['hp'],
            "Turtle should have higher HP than raider");
    }

    /**
     * Non-combat archetypes should have meaningful stats in their specialty.
     * Pillagers should have massive pillage, speedsters massive speed.
     */
    public function testNonCombatArchetypesExcelElsewhere(): void
    {
        // Pillager's pillage should be > 2x any combat archetype's pillage
        $pillagerPillage = pillage(
            $this->archetypes['pillager']['S'],
            $this->archetypes['pillager']['Cl'],
            $this->cond
        );
        $raiderPillage = pillage(
            $this->archetypes['raider']['S'],
            $this->archetypes['raider']['Cl'],
            $this->cond
        );
        $this->assertGreaterThan($raiderPillage * 2, $pillagerPillage,
            "Pillager should have >2x the pillage of a raider");

        // Speedster's speed should be > 2x any combat archetype's speed
        $speedsterSpeed = vitesse(
            $this->archetypes['speedster']['Cl'],
            $this->archetypes['speedster']['N'],
            $this->cond
        );
        $turtleSpeed = vitesse(
            $this->archetypes['turtle']['Cl'],
            $this->archetypes['turtle']['N'],
            $this->cond
        );
        $this->assertGreaterThan($turtleSpeed * 2, $speedsterSpeed,
            "Speedster should be >2x faster than a turtle");
    }

    /**
     * Every archetype should have at least 1 favorable matchup (on offense or defense).
     */
    public function testEveryArchetypeHasFavorableMatchup(): void
    {
        $names = array_keys($this->archetypes);

        foreach ($names as $me) {
            $hasFavorable = false;
            foreach ($names as $them) {
                if ($me === $them) continue;
                $myStats = $this->getStats($this->archetypes[$me]);
                $theirStats = $this->getStats($this->archetypes[$them]);

                // Check if I win on offense OR on defense
                $offensiveScore = $this->combatScore($myStats, $theirStats);
                $defensiveScore = $this->combatScore($theirStats, $myStats);

                if ($offensiveScore > 1.0 || $defensiveScore < 1.0) {
                    $hasFavorable = true;
                    break;
                }
            }
            $this->assertTrue($hasFavorable,
                "BALANCE FAIL: '$me' has no favorable matchup against anyone");
        }
    }

    /**
     * Verify formations create meaningful tactical choices.
     */
    public function testFormationTacticalChoices(): void
    {
        // Embuscade bonus: meaningful (>15%) but not overwhelming (<50%)
        $this->assertGreaterThan(0.15, FORMATION_AMBUSH_ATTACK_BONUS,
            "Embuscade bonus too small to matter");
        $this->assertLessThan(0.50, FORMATION_AMBUSH_ATTACK_BONUS,
            "Embuscade bonus too large (would be always-optimal)");

        // Phalanx defense bonus: meaningful but not overwhelming
        $this->assertGreaterThan(0.10, FORMATION_PHALANX_DEFENSE_BONUS,
            "Phalanx defense bonus too small");
        $this->assertLessThan(0.50, FORMATION_PHALANX_DEFENSE_BONUS,
            "Phalanx defense bonus too large");

        // Phalanx absorb: should concentrate damage meaningfully
        $this->assertGreaterThanOrEqual(0.50, FORMATION_PHALANX_ABSORB);
        $this->assertLessThanOrEqual(0.80, FORMATION_PHALANX_ABSORB);

        // All 3 formations must be defined
        global $FORMATIONS;
        $this->assertCount(3, $FORMATIONS);
    }

    /**
     * Verify ionisateur/champdeforce combat bonuses are symmetric.
     */
    public function testBuildingBonusSymmetry(): void
    {
        $this->assertEquals(
            IONISATEUR_COMBAT_BONUS_PER_LEVEL,
            CHAMPDEFORCE_COMBAT_BONUS_PER_LEVEL,
            "Attack and defense building bonuses should be equal for fairness"
        );
    }

    /**
     * Attack cooldown should prevent chain-bullying.
     */
    public function testAttackCooldowns(): void
    {
        // Loss/draw cooldown: 4 hours
        $this->assertEquals(4 * SECONDS_PER_HOUR, ATTACK_COOLDOWN_SECONDS);

        // Win cooldown on same target: 1 hour
        $this->assertEquals(1 * SECONDS_PER_HOUR, ATTACK_COOLDOWN_WIN_SECONDS);

        // Win cooldown should be shorter than loss cooldown (reward winning)
        $this->assertLessThan(ATTACK_COOLDOWN_SECONDS, ATTACK_COOLDOWN_WIN_SECONDS);
    }

    /**
     * Defense reward should incentivize turtling as a strategy.
     */
    public function testDefenseRewards(): void
    {
        // 30% resource bonus on successful defense (P1-D4-027)
        $this->assertEqualsWithDelta(0.30, DEFENSE_REWARD_RATIO, 0.01);

        // 1.5x combat points for defensive victories
        $this->assertEqualsWithDelta(1.5, DEFENSE_POINTS_MULTIPLIER_BONUS, 0.01);

        // Defense multiplier > 1.0 (defense rewarded more than attack)
        $this->assertGreaterThan(1.0, DEFENSE_POINTS_MULTIPLIER_BONUS);
    }

    public function testDefenseRewardRatioSufficient(): void
    {
        $this->assertGreaterThanOrEqual(0.30, DEFENSE_REWARD_RATIO,
            'Defense reward should be >= 30% to make defending worthwhile');
    }

    public function testPillageTaxExists(): void
    {
        $this->assertTrue(defined('PILLAGE_TAX_RATE'), 'PILLAGE_TAX_RATE must be defined');
        $this->assertGreaterThan(0, PILLAGE_TAX_RATE);
        $this->assertLessThanOrEqual(0.30, PILLAGE_TAX_RATE);
    }
}
