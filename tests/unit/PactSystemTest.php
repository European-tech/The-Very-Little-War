<?php
use PHPUnit\Framework\TestCase;

/**
 * Tests for pact/alliance grade permission parsing.
 *
 * The grade system stores permissions as a dot-separated string of 5 bits:
 *   "inviter.guerre.pacte.bannir.description"
 *   Index 0 = invite players
 *   Index 1 = declare war
 *   Index 2 = manage pacts   ← the pact permission
 *   Index 3 = ban members
 *   Index 4 = edit description
 *
 * Each segment is '1' (granted) or '0' (denied).
 *
 * Code references:
 *   validerpacte.php lines 23-24:
 *       $bits = explode('.', $gradeRow['grade']);
 *       $hasPactPerm = (isset($bits[2]) && $bits[2] === '1');
 *
 *   allianceadmin.php lines 30-35:
 *       $bits = explode('.', $grade['grade'] ?? '');
 *       if (count($bits) !== 5) {
 *           [$inviter, $guerre, $pacte, $bannir, $description] = [false, ...];
 *       } else {
 *           [$inviter, $guerre, $pacte, $bannir, $description] = array_map(fn($b) => $b === '1', $bits);
 *       }
 *
 * Report content format (allianceadmin.php line 290):
 *   "... [PACT_ID:N]"
 *   Extracted in rapports.php via: preg_match('/\[PACT_ID:(\d+)\]/', $content, $pactMatch)
 */
class PactSystemTest extends TestCase
{
    // =========================================================================
    // GRADE STRING PARSING — 5-segment dot-separated format
    // =========================================================================

    /**
     * Helper: parse a grade string the same way validerpacte.php does.
     * Returns the pact permission bit (index 2), or false if invalid.
     */
    private function parsePactPermission(string $grade): bool
    {
        $bits = explode('.', $grade);
        return isset($bits[2]) && $bits[2] === '1';
    }

    /**
     * Helper: parse all 5 grade segments the same way allianceadmin.php does.
     * Returns named bool array, or all-false on malformed input.
     *
     * @return array{inviter:bool, guerre:bool, pacte:bool, bannir:bool, description:bool}
     */
    private function parseGradeSegments(string $grade): array
    {
        $bits = explode('.', $grade ?? '');
        if (count($bits) !== 5) {
            return [
                'inviter'     => false,
                'guerre'      => false,
                'pacte'       => false,
                'bannir'      => false,
                'description' => false,
            ];
        }
        [$inviter, $guerre, $pacte, $bannir, $description] = array_map(
            fn($b) => $b === '1',
            $bits
        );
        return compact('inviter', 'guerre', 'pacte', 'bannir', 'description');
    }

    // --- Valid 5-segment strings ---

    public function testAllPermissionsGranted(): void
    {
        $grade = '1.1.1.1.1';
        $parsed = $this->parseGradeSegments($grade);
        $this->assertTrue($parsed['inviter']);
        $this->assertTrue($parsed['guerre']);
        $this->assertTrue($parsed['pacte']);
        $this->assertTrue($parsed['bannir']);
        $this->assertTrue($parsed['description']);
    }

    public function testAllPermissionsDenied(): void
    {
        $grade = '0.0.0.0.0';
        $parsed = $this->parseGradeSegments($grade);
        $this->assertFalse($parsed['inviter']);
        $this->assertFalse($parsed['guerre']);
        $this->assertFalse($parsed['pacte']);
        $this->assertFalse($parsed['bannir']);
        $this->assertFalse($parsed['description']);
    }

    public function testOnlyPactPermissionGranted(): void
    {
        $grade = '0.0.1.0.0';
        $parsed = $this->parseGradeSegments($grade);
        $this->assertFalse($parsed['inviter']);
        $this->assertFalse($parsed['guerre']);
        $this->assertTrue($parsed['pacte']);
        $this->assertFalse($parsed['bannir']);
        $this->assertFalse($parsed['description']);
    }

    public function testPactPermissionDeniedAllOthersGranted(): void
    {
        $grade = '1.1.0.1.1';
        $parsed = $this->parseGradeSegments($grade);
        $this->assertTrue($parsed['inviter']);
        $this->assertTrue($parsed['guerre']);
        $this->assertFalse($parsed['pacte']);
        $this->assertTrue($parsed['bannir']);
        $this->assertTrue($parsed['description']);
    }

    public function testMixedPermissions(): void
    {
        // Officer who can invite and edit description but not war/pact/ban
        $grade = '1.0.0.0.1';
        $parsed = $this->parseGradeSegments($grade);
        $this->assertTrue($parsed['inviter']);
        $this->assertFalse($parsed['guerre']);
        $this->assertFalse($parsed['pacte']);
        $this->assertFalse($parsed['bannir']);
        $this->assertTrue($parsed['description']);
    }

    // --- Pact permission bit extraction (index 2) ---

    public function testPactBitTrueWhenIndexTwoIsOne(): void
    {
        $this->assertTrue($this->parsePactPermission('0.0.1.0.0'));
        $this->assertTrue($this->parsePactPermission('1.1.1.1.1'));
        $this->assertTrue($this->parsePactPermission('0.1.1.0.0'));
    }

    public function testPactBitFalseWhenIndexTwoIsZero(): void
    {
        $this->assertFalse($this->parsePactPermission('0.0.0.0.0'));
        $this->assertFalse($this->parsePactPermission('1.1.0.1.1'));
    }

    public function testPactBitFalseWhenStringIsEmpty(): void
    {
        // Empty string → explode gives [''] → bits[2] not set → false
        $this->assertFalse($this->parsePactPermission(''));
    }

    public function testPactBitFalseWhenTooFewSegments(): void
    {
        // Only 2 segments — index 2 does not exist
        $this->assertFalse($this->parsePactPermission('1.1'));
    }

    // --- Malformed / edge-case grade strings ---

    public function testMalformedGradeTooFewSegments(): void
    {
        // 3 segments instead of 5 — allianceadmin.php falls back to all-false
        $parsed = $this->parseGradeSegments('1.1.1');
        $this->assertFalse($parsed['inviter']);
        $this->assertFalse($parsed['guerre']);
        $this->assertFalse($parsed['pacte']);
        $this->assertFalse($parsed['bannir']);
        $this->assertFalse($parsed['description']);
    }

    public function testMalformedGradeTooManySegments(): void
    {
        // 6 segments — count($bits) !== 5 → all-false
        $parsed = $this->parseGradeSegments('1.1.1.1.1.1');
        $this->assertFalse($parsed['inviter']);
        $this->assertFalse($parsed['pacte']);
    }

    public function testMalformedGradeEmptyString(): void
    {
        // Empty string → 1 segment → not 5 → all-false
        $parsed = $this->parseGradeSegments('');
        foreach (['inviter', 'guerre', 'pacte', 'bannir', 'description'] as $perm) {
            $this->assertFalse($parsed[$perm], "Permission '$perm' should be false for empty grade");
        }
    }

    public function testMalformedGradeWithNonBinaryValues(): void
    {
        // Non-'1' values such as '2', 'yes', 'true' must NOT be treated as granted
        // Only the literal string '1' grants a permission
        $parsed = $this->parseGradeSegments('2.yes.true.on.1');
        $this->assertFalse($parsed['inviter'],     "'2' should not grant permission");
        $this->assertFalse($parsed['guerre'],      "'yes' should not grant permission");
        $this->assertFalse($parsed['pacte'],       "'true' should not grant permission");
        $this->assertFalse($parsed['bannir'],      "'on' should not grant permission");
        $this->assertTrue($parsed['description'],  "'1' should grant permission");
    }

    public function testMalformedGradeWithSpaces(): void
    {
        // Segment ' 1' (leading space) must NOT equal '1'
        $parsed = $this->parseGradeSegments(' 1.0.1 .1.0');
        $this->assertFalse($parsed['inviter'], "' 1' (with space) should not grant permission");
        $this->assertFalse($parsed['pacte'],   "'1 ' (with space) should not grant permission");
    }

    // --- Segment index positions ---

    public function testSegmentIndicesAreCorrect(): void
    {
        // Verify the order: inviter=0, guerre=1, pacte=2, bannir=3, description=4
        // by toggling each bit individually and confirming only the expected perm is true.
        $permOrder = ['inviter', 'guerre', 'pacte', 'bannir', 'description'];
        foreach ($permOrder as $idx => $permName) {
            $bits = ['0', '0', '0', '0', '0'];
            $bits[$idx] = '1';
            $grade = implode('.', $bits);
            $parsed = $this->parseGradeSegments($grade);
            foreach ($permOrder as $checkName) {
                if ($checkName === $permName) {
                    $this->assertTrue($parsed[$checkName],
                        "Bit $idx should grant '$checkName'");
                } else {
                    $this->assertFalse($parsed[$checkName],
                        "Bit $idx should NOT grant '$checkName'");
                }
            }
        }
    }

    // =========================================================================
    // PACT_ID EXTRACTION FROM REPORT CONTENT
    //
    // Format stored by allianceadmin.php:
    //   "... vous propose un pacte. [PACT_ID:42]"
    //
    // Extracted by rapports.php:
    //   preg_match('/\[PACT_ID:(\d+)\]/', $content, $pactMatch)
    //   $declId = (int)$pactMatch[1];
    // =========================================================================

    /**
     * Helper: extract pact ID from report content string.
     * Returns null if no pact tag is found.
     */
    private function extractPactId(string $content): ?int
    {
        if (preg_match('/\[PACT_ID:(\d+)\]/', $content, $m)) {
            return (int)$m[1];
        }
        return null;
    }

    public function testExtractPactIdFromTypicalContent(): void
    {
        $content = "L'alliance [TAG] vous propose un pacte. [PACT_ID:42]";
        $this->assertEquals(42, $this->extractPactId($content));
    }

    public function testExtractPactIdMinimalContent(): void
    {
        $this->assertEquals(1, $this->extractPactId('[PACT_ID:1]'));
        $this->assertEquals(99999, $this->extractPactId('[PACT_ID:99999]'));
    }

    public function testExtractPactIdZero(): void
    {
        // ID 0 is technically valid for the regex even if the game never stores it
        $this->assertEquals(0, $this->extractPactId('[PACT_ID:0]'));
    }

    public function testExtractPactIdReturnsNullWhenAbsent(): void
    {
        $this->assertNull($this->extractPactId('Alliance vous propose une guerre.'));
        $this->assertNull($this->extractPactId(''));
        $this->assertNull($this->extractPactId('PACT_ID:42'));       // no brackets
        $this->assertNull($this->extractPactId('[PACT_ID:]'));        // empty id
        $this->assertNull($this->extractPactId('[PACT_ID:abc]'));     // non-numeric
    }

    public function testExtractPactIdPicksFirstOccurrence(): void
    {
        // If multiple tags appear, preg_match returns the first one
        $content = '[PACT_ID:10] and later [PACT_ID:20]';
        $this->assertEquals(10, $this->extractPactId($content));
    }

    public function testExtractPactIdPreservesLargeIds(): void
    {
        // The DB auto-increment can grow large; extraction must not truncate
        $largeId = 123456789;
        $content = "proposition de pacte [PACT_ID:{$largeId}]";
        $this->assertEquals($largeId, $this->extractPactId($content));
    }

    public function testPactTagFormatUsedByAllianceAdmin(): void
    {
        // Verify the tag format produced in allianceadmin.php line 290 is parseable
        $tag       = 'TST';
        $alliName  = 'Test Alliance';
        $idDeclaration = 7;
        // Mimick the format: allianceadmin.php uses htmlspecialchars on the tag
        $safeTag   = htmlspecialchars($tag, ENT_QUOTES, 'UTF-8');
        $content   = 'L\'alliance <a href="alliance.php?id=' . urlencode($safeTag)
                   . '">' . $safeTag . '</a> vous propose un pacte. [PACT_ID:' . $idDeclaration . ']';
        $this->assertEquals($idDeclaration, $this->extractPactId($content));
    }
}
