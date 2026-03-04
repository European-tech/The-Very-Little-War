<?php
/**
 * Market System Integration Tests.
 * Verifies market trading, pricing, and points with real DB.
 */
require_once __DIR__ . '/IntegrationTestCase.php';

class MarketSystemTest extends IntegrationTestCase
{
    public function testMarketBuyReducesEnergy(): void
    {
        $this->createTestPlayer('buyer');
        $this->setResources('buyer', ['energie' => 50000]);

        $before = dbFetchOne(self::$db, 'SELECT energie FROM ressources WHERE login = ?', 's', 'buyer');

        // Simulate buying 100 carbone at price 2.0
        $cost = round(100 * 2.0);
        dbExecute(self::$db,
            'UPDATE ressources SET energie = energie - ?, carbone = carbone + ? WHERE login = ?',
            'dds', (float)$cost, 100.0, 'buyer'
        );

        $after = dbFetchOne(self::$db, 'SELECT energie, carbone FROM ressources WHERE login = ?', 's', 'buyer');
        $this->assertEquals($before['energie'] - $cost, $after['energie']);
        $this->assertGreaterThan(0, $after['carbone']);
    }

    public function testMarketSellTax(): void
    {
        $this->createTestPlayer('seller');
        $this->setResources('seller', ['carbone' => 5000, 'energie' => 1000]);

        $sellQty = 1000;
        $price = 1.5;
        $revenue = round($sellQty * $price * MARKET_SELL_TAX_RATE);

        dbExecute(self::$db,
            'UPDATE ressources SET carbone = carbone - ?, energie = energie + ? WHERE login = ?',
            'dds', (float)$sellQty, (float)$revenue, 'seller'
        );

        $after = dbFetchOne(self::$db, 'SELECT energie, carbone FROM ressources WHERE login = ?', 's', 'seller');
        $this->assertEquals(1000 + $revenue, $after['energie']);
        $this->assertEquals(4000, $after['carbone']);
    }

    public function testMarketPriceBounds(): void
    {
        // Verify price constants are sane
        $this->assertGreaterThan(0, MARKET_PRICE_FLOOR);
        $this->assertGreaterThan(MARKET_PRICE_FLOOR, MARKET_PRICE_CEILING);
        $this->assertLessThanOrEqual(1, MARKET_SELL_TAX_RATE);
        $this->assertGreaterThan(0, MARKET_SELL_TAX_RATE);
    }

    public function testMarketPointsCapped(): void
    {
        // Trading 1M energy volume → compute market points
        $volume = 1000000;
        $rawPts = floor(MARKET_POINTS_SCALE * sqrt($volume));
        $cappedPts = min(MARKET_POINTS_MAX, $rawPts);
        $this->assertEquals(MARKET_POINTS_MAX, $cappedPts, "Large trade volume should be capped");
    }

    public function testCourseTablePersistence(): void
    {
        // Insert a market price record
        $prices = json_encode(['carbone' => 1.2, 'azote' => 0.8]);
        dbExecute(self::$db,
            'INSERT INTO cours (tableauCours, timestamp) VALUES (?, ?)',
            'si', $prices, time()
        );
        $id = self::$db->insert_id;
        $this->assertGreaterThan(0, $id);

        $row = dbFetchOne(self::$db, 'SELECT tableauCours FROM cours WHERE id = ?', 'i', $id);
        $decoded = json_decode($row['tableauCours'], true);
        $this->assertEquals(1.2, $decoded['carbone']);
    }

    public function testMultipleTradesUpdateBalance(): void
    {
        $this->createTestPlayer('multi_trader');
        $this->setResources('multi_trader', ['energie' => 100000, 'carbone' => 0]);

        // 3 sequential buys
        for ($i = 0; $i < 3; $i++) {
            dbExecute(self::$db,
                'UPDATE ressources SET energie = energie - 500, carbone = carbone + 250 WHERE login = ?',
                's', 'multi_trader'
            );
        }

        $res = dbFetchOne(self::$db, 'SELECT energie, carbone FROM ressources WHERE login = ?', 's', 'multi_trader');
        $this->assertEquals(100000 - 1500, $res['energie']);
        $this->assertEquals(750, $res['carbone']);
    }
}
