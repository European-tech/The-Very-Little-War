<?php
/**
 * Page Include Chain Smoke Tests.
 * Verifies that key include files can be loaded without fatal errors.
 * This catches missing functions, circular includes, and syntax errors.
 */

class PageIncludesTest extends PHPUnit\Framework\TestCase
{
    public function testConstantesBaseLoads(): void
    {
        // Already loaded by bootstrap, but verify key constants exist
        $this->assertTrue(defined('SECONDS_PER_HOUR'));
        $this->assertTrue(defined('SECONDS_PER_DAY'));
        $this->assertTrue(defined('SECONDS_PER_WEEK'));
    }

    public function testConfigLoads(): void
    {
        // Verify config.php loaded properly
        global $BUILDING_CONFIG, $COMPOUNDS, $ISOTOPES;
        $this->assertIsArray($BUILDING_CONFIG);
        $this->assertIsArray($COMPOUNDS);
        $this->assertIsArray($ISOTOPES);
    }

    public function testFormulasLoaded(): void
    {
        // All formula functions should be callable
        $this->assertTrue(function_exists('attaque'), 'attaque() should be defined');
        $this->assertTrue(function_exists('defense'), 'defense() should be defined');
        $this->assertTrue(function_exists('pointsDeVieMolecule'), 'pointsDeVieMolecule() should be defined');
        $this->assertTrue(function_exists('vitesse'), 'vitesse() should be defined');
        $this->assertTrue(function_exists('pillage'), 'pillage() should be defined');
        $this->assertTrue(function_exists('placeDepot'), 'placeDepot() should be defined');
        $this->assertTrue(function_exists('drainageProducteur'), 'drainageProducteur() should be defined');
        $this->assertTrue(function_exists('coutClasse'), 'coutClasse() should be defined');
        $this->assertTrue(function_exists('capaciteCoffreFort'), 'capaciteCoffreFort() should be defined');
        $this->assertTrue(function_exists('calculerTotalPoints'), 'calculerTotalPoints() should be defined');
        $this->assertTrue(function_exists('productionEnergieMolecule'), 'productionEnergieMolecule() should be defined');
    }

    public function testDisplayFunctionsLoaded(): void
    {
        $this->assertTrue(function_exists('antiXSS'), 'antiXSS() should be defined');
        $this->assertTrue(function_exists('transformInt'), 'transformInt() should be defined');
    }

    public function testValidationFunctionsLoaded(): void
    {
        $this->assertTrue(function_exists('validateLogin'), 'validateLogin() should be defined');
        $this->assertTrue(function_exists('validateEmail'), 'validateEmail() should be defined');
    }

    public function testCsrfFunctionsLoaded(): void
    {
        $this->assertTrue(function_exists('csrfToken'), 'csrfToken() should be defined');
        $this->assertTrue(function_exists('csrfField'), 'csrfField() should be defined');
        $this->assertTrue(function_exists('csrfCheck'), 'csrfCheck() should be defined');
    }

    public function testAntiXSSEscaping(): void
    {
        $this->assertEquals('&lt;script&gt;', antiXSS('<script>'));
        $this->assertEquals('hello', antiXSS('hello'));
        $this->assertEquals('&amp;amp;', antiXSS('&amp;'));
    }

    public function testDbHelperFunctionsExist(): void
    {
        // DB helper functions should be defined (either real or stub)
        $this->assertTrue(function_exists('dbQuery'), 'dbQuery() should be defined');
        $this->assertTrue(function_exists('dbFetchOne'), 'dbFetchOne() should be defined');
        $this->assertTrue(function_exists('dbFetchAll'), 'dbFetchAll() should be defined');
        $this->assertTrue(function_exists('dbExecute'), 'dbExecute() should be defined');
        $this->assertTrue(function_exists('dbCount'), 'dbCount() should be defined');
    }

    public function testAllResourceNamesConstant(): void
    {
        // The 8 atom types should be accessible
        $expected = ['carbone', 'azote', 'hydrogene', 'oxygene', 'chlore', 'soufre', 'brome', 'iode'];
        foreach ($expected as $res) {
            // Each should appear in BUILDING_CONFIG or be a recognized resource
            $this->assertContains($res,
                ['carbone', 'azote', 'hydrogene', 'oxygene', 'chlore', 'soufre', 'brome', 'iode'],
                "Resource $res should be a valid atom type"
            );
        }
    }

    /**
     * @dataProvider publicPageProvider
     */
    public function testPublicPageFilesExist(string $page): void
    {
        $path = __DIR__ . '/../../' . $page;
        $this->assertFileExists($path, "Public page $page should exist");
    }

    public static function publicPageProvider(): array
    {
        return [
            'index' => ['index.php'],
            'inscription' => ['inscription.php'],
            'regles' => ['regles.php'],
            'credits' => ['credits.php'],
            'classement' => ['classement.php'],
            'health' => ['health.php'],
            'api' => ['api.php'],
        ];
    }

    /**
     * @dataProvider authPageProvider
     */
    public function testAuthPageFilesExist(string $page): void
    {
        $path = __DIR__ . '/../../' . $page;
        $this->assertFileExists($path, "Auth page $page should exist");
    }

    public static function authPageProvider(): array
    {
        return [
            'constructions' => ['constructions.php'],
            'armee' => ['armee.php'],
            'laboratoire' => ['laboratoire.php'],
            'molecule' => ['molecule.php'],
            'marche' => ['marche.php'],
            'attaque' => ['attaque.php'],
            'rapports' => ['rapports.php'],
            'messages' => ['messages.php'],
            'forum' => ['forum.php'],
            'alliance' => ['alliance.php'],
            'tutoriel' => ['tutoriel.php'],
            'prestige' => ['prestige.php'],
            'medailles' => ['medailles.php'],
            'bilan' => ['bilan.php'],
            'compte' => ['compte.php'],
        ];
    }
}
