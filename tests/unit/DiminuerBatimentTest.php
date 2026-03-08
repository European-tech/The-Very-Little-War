<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the PASS4-HIGH-001 fix in diminuerBatiment().
 *
 * The bug: PHP closures cannot capture variable-variables (${'points'.$x})
 * via the use clause — they silently resolve to null/0, zeroing out all
 * player atom specialisation allocations whenever a building level is lost
 * in combat.
 *
 * The fix reads pointsProducteur / pointsCondenseur directly from the row
 * already fetched with FOR UPDATE and parses them into local indexed arrays.
 *
 * These tests verify the parsing logic in isolation (no live DB required)
 * and ensure the resulting strings are not all-zeros.
 */
class DiminuerBatimentTest extends TestCase
{
    /** @var list<string> */
    private array $nomsRes;

    protected function setUp(): void
    {
        global $nomsRes;
        // Use real $nomsRes from bootstrap if available, otherwise use the
        // canonical 8-atom list so tests are self-contained.
        $this->nomsRes = $nomsRes ?? [
            'carbone', 'azote', 'hydrogene', 'oxygene',
            'chlore', 'soufre', 'brome', 'iode',
        ];
    }

    // ------------------------------------------------------------------ //
    //  Helpers that mirror the parsing logic now inside the closure       //
    // ------------------------------------------------------------------ //

    /**
     * @return list<float>
     */
    private function parseProducteurAllocs(string $raw): array
    {
        $allocs = array_map('floatval', explode(';', rtrim($raw, ';')));
        while (count($allocs) < count($this->nomsRes)) {
            $allocs[] = 0.0;
        }
        return $allocs;
    }

    /**
     * @return list<int>
     */
    private function parseCondenseurLevels(string $raw): array
    {
        $levels = array_map('intval', explode(';', rtrim($raw, ';')));
        while (count($levels) < count($this->nomsRes)) {
            $levels[] = 0;
        }
        return $levels;
    }

    /**
     * Simulate the producteur deduction loop (the inner loop now uses
     * $producteurAllocs[$num] instead of the broken ${'points'.$ressource}).
     *
     * @param list<float> $allocs
     */
    private function simulateProducteurDeduction(array $allocs, float $pointsAEnlever): string
    {
        $chaine = '';
        foreach ($this->nomsRes as $num => $ressource) {
            if ($pointsAEnlever <= $allocs[$num]) {
                $chaine .= ($allocs[$num] - $pointsAEnlever) . ';';
                $pointsAEnlever = 0;
            } else {
                $chaine .= '0;';
                $pointsAEnlever -= $allocs[$num];
            }
        }
        return $chaine;
    }

    /**
     * Simulate the condenseur deduction loop.
     *
     * @param list<int> $levels
     */
    private function simulateCondenseurDeduction(array $levels, int $pointsAEnlever): string
    {
        $chaine = '';
        foreach ($this->nomsRes as $num => $ressource) {
            $currentLevel = $levels[$num];
            if ($pointsAEnlever > 0 && $currentLevel > 0) {
                $canRemove = min($pointsAEnlever, $currentLevel);
                $chaine .= ($currentLevel - $canRemove) . ';';
                $pointsAEnlever -= $canRemove;
            } else {
                $chaine .= $currentLevel . ';';
            }
        }
        return $chaine;
    }

    // ------------------------------------------------------------------ //
    //  Parsing tests                                                       //
    // ------------------------------------------------------------------ //

    public function testParseProducteurAllocsProducesCorrectValues(): void
    {
        $raw    = '5;3;2;1;0;0;0;0;';
        $allocs = $this->parseProducteurAllocs($raw);

        $this->assertCount(8, $allocs);
        $this->assertSame(5.0, $allocs[0]);
        $this->assertSame(3.0, $allocs[1]);
        $this->assertSame(2.0, $allocs[2]);
        $this->assertSame(1.0, $allocs[3]);
        $this->assertSame(0.0, $allocs[4]);
    }

    public function testParseCondenseurLevelsProducesCorrectValues(): void
    {
        $raw    = '2;1;0;3;0;0;0;0;';
        $levels = $this->parseCondenseurLevels($raw);

        $this->assertCount(8, $levels);
        $this->assertSame(2, $levels[0]);
        $this->assertSame(1, $levels[1]);
        $this->assertSame(0, $levels[2]);
        $this->assertSame(3, $levels[3]);
    }

    public function testParseHandlesTrailingSemicolon(): void
    {
        $allocs = $this->parseProducteurAllocs('5;3;2;1;0;0;0;0;');
        $this->assertCount(8, $allocs);
    }

    public function testParseHandlesMissingSemicolon(): void
    {
        // rtrim removes trailing ';', but if there is none it must still work.
        $allocs = $this->parseProducteurAllocs('5;3;2;1;0;0;0;0');
        $this->assertCount(8, $allocs);
        $this->assertSame(5.0, $allocs[0]);
    }

    public function testParsePadsShortStringToEightElements(): void
    {
        $allocs = $this->parseProducteurAllocs('5;3;');
        $this->assertCount(8, $allocs);
        $this->assertSame(5.0, $allocs[0]);
        $this->assertSame(3.0, $allocs[1]);
        $this->assertSame(0.0, $allocs[2]);
        $this->assertSame(0.0, $allocs[7]);
    }

    public function testParseEmptyStringYieldsAllZeros(): void
    {
        $allocs = $this->parseProducteurAllocs('');
        $this->assertCount(8, $allocs);
        foreach ($allocs as $v) {
            $this->assertSame(0.0, $v);
        }
    }

    // ------------------------------------------------------------------ //
    //  Core regression: allocations must NOT be silently zeroed           //
    // ------------------------------------------------------------------ //

    /**
     * Before the fix the broken variable-variable capture inside the closure
     * meant every element resolved to 0, so the rebuilt string was always
     * "0;0;0;0;0;0;0;0;".  After the fix the string must preserve the
     * non-zero values (reduced only by the portion that is actually removed).
     */
    public function testProducteurDeductionPreservesNonZeroAllocations(): void
    {
        // Seed: player has 5;3;2;1;0;0;0;0; — total 11 points allocated.
        // One producteur level is lost.  Assume pointsProducteurRestants is 0
        // so pointsAEnlever equals $points['producteur'] (e.g. 3 per level).
        $raw            = '5;3;2;1;0;0;0;0;';
        $allocs         = $this->parseProducteurAllocs($raw);
        $pointsAEnlever = 3.0; // cost of one producteur level

        $result = $this->simulateProducteurDeduction($allocs, $pointsAEnlever);

        // The result must NOT be the all-zeros string that the bug produced.
        $this->assertNotEquals('0;0;0;0;0;0;0;0;', $result);

        // The first atom should have been reduced from 5 to 2 (3 removed from it).
        $rebuilt = $this->parseProducteurAllocs($result);
        $this->assertSame(2.0, $rebuilt[0], 'carbone alloc should drop from 5 to 2');
        // Remaining atoms untouched.
        $this->assertSame(3.0, $rebuilt[1], 'azote alloc must remain 3');
        $this->assertSame(2.0, $rebuilt[2], 'hydrogene alloc must remain 2');
        $this->assertSame(1.0, $rebuilt[3], 'oxygene alloc must remain 1');
    }

    public function testProducteurDeductionSpreadAcrossMultipleAtoms(): void
    {
        // pointsAEnlever = 7 is larger than the first atom's allocation (5),
        // so deduction must spill into the second atom.
        $raw            = '5;3;2;1;0;0;0;0;';
        $allocs         = $this->parseProducteurAllocs($raw);
        $pointsAEnlever = 7.0;

        $result  = $this->simulateProducteurDeduction($allocs, $pointsAEnlever);
        $rebuilt = $this->parseProducteurAllocs($result);

        $this->assertSame(0.0, $rebuilt[0], 'carbone fully consumed');
        $this->assertSame(1.0, $rebuilt[1], 'azote partially consumed (3-2=1)');
        $this->assertSame(2.0, $rebuilt[2], 'hydrogene untouched');
    }

    public function testCondenseurDeductionPreservesNonZeroLevels(): void
    {
        // Seed: player has condenseur levels 2;1;0;3;0;0;0;0;
        // One condenseur level is lost; pointsAEnlever = 2.
        $raw            = '2;1;0;3;0;0;0;0;';
        $levels         = $this->parseCondenseurLevels($raw);
        $pointsAEnlever = 2;

        $result = $this->simulateCondenseurDeduction($levels, $pointsAEnlever);

        $this->assertNotEquals('0;0;0;0;0;0;0;0;', $result);

        $rebuilt = $this->parseCondenseurLevels($result);
        // 2 removed entirely from carbone (level 2 → 0).
        $this->assertSame(0, $rebuilt[0], 'carbone fully removed');
        // azote untouched (deduction exhausted).
        $this->assertSame(1, $rebuilt[1], 'azote must remain 1');
        $this->assertSame(3, $rebuilt[3], 'oxygene must remain 3');
    }

    public function testCondenseurDeductionSpreadAcrossMultipleAtoms(): void
    {
        $raw            = '2;1;0;3;0;0;0;0;';
        $levels         = $this->parseCondenseurLevels($raw);
        $pointsAEnlever = 3; // removes carbone(2) then 1 from azote

        $result  = $this->simulateCondenseurDeduction($levels, $pointsAEnlever);
        $rebuilt = $this->parseCondenseurLevels($result);

        $this->assertSame(0, $rebuilt[0], 'carbone fully consumed');
        $this->assertSame(0, $rebuilt[1], 'azote partially consumed (1-1=0)');
        $this->assertSame(3, $rebuilt[3], 'oxygene untouched');
    }

    public function testCondenseurDeductionNeverGoesNegative(): void
    {
        // pointsAEnlever larger than total allocated — must floor at 0 per atom.
        $raw            = '1;0;0;0;0;0;0;0;';
        $levels         = $this->parseCondenseurLevels($raw);
        $pointsAEnlever = 999;

        $result  = $this->simulateCondenseurDeduction($levels, $pointsAEnlever);
        $rebuilt = $this->parseCondenseurLevels($result);

        foreach ($rebuilt as $idx => $level) {
            $this->assertGreaterThanOrEqual(0, $level, "Atom $idx must not go negative");
        }
    }
}
