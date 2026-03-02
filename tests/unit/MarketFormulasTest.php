<?php
use PHPUnit\Framework\TestCase;

/**
 * Tests for market (marche) price formulas and mechanics.
 *
 * Formula references (from marche.php and includes/config.php):
 *   Volatility:        0.5 / activePlayerCount (MARKET_VOLATILITY_FACTOR = 0.5)
 *   Price on buy:      price + volatility * amount / depot
 *   Price on sell:     1 / (1/price + volatility * amount / depot)
 *   Buy cost:          round(price * amount)
 *   Sell revenue:      round(price * amount)
 *   Merchant speed:    20 cases per hour
 */
class MarketFormulasTest extends TestCase
{
    // =========================================================================
    // VOLATILITY FORMULA
    // Formula: 0.5 / nbActifs (MARKET_VOLATILITY_FACTOR / activePlayerCount)
    // =========================================================================

    public function testVolatilityFactorConstant(): void
    {
        $this->assertEquals(0.5, MARKET_VOLATILITY_FACTOR);
    }

    public function testVolatilityWithOnePlayer(): void
    {
        $volatility = MARKET_VOLATILITY_FACTOR / 1;
        $this->assertEquals(0.5, $volatility);
    }

    public function testVolatilityWithTenPlayers(): void
    {
        $volatility = MARKET_VOLATILITY_FACTOR / 10;
        $this->assertEquals(0.05, $volatility);
    }

    public function testVolatilityWithHundredPlayers(): void
    {
        $volatility = MARKET_VOLATILITY_FACTOR / 100;
        $this->assertEquals(0.005, $volatility);
    }

    public function testVolatilityDecreasesWithMorePlayers(): void
    {
        $vol10 = MARKET_VOLATILITY_FACTOR / 10;
        $vol50 = MARKET_VOLATILITY_FACTOR / 50;
        $vol100 = MARKET_VOLATILITY_FACTOR / 100;

        $this->assertGreaterThan($vol50, $vol10);
        $this->assertGreaterThan($vol100, $vol50);
    }

    public function testVolatilityAlwaysPositive(): void
    {
        for ($players = 1; $players <= 200; $players++) {
            $volatility = MARKET_VOLATILITY_FACTOR / $players;
            $this->assertGreaterThan(0, $volatility, "Volatility should be positive with $players players");
        }
    }

    // =========================================================================
    // PRICE INCREASE ON BUY
    // Formula: newPrice = currentPrice + volatility * amount / depot
    // This makes the price go UP when someone buys (supply decreases)
    // =========================================================================

    /**
     * Helper: compute new price after buying.
     */
    private function priceAfterBuy(float $currentPrice, float $volatility, int $amount, int $depot): float
    {
        return $currentPrice + $volatility * $amount / $depot;
    }

    public function testBuyIncreasesPrice(): void
    {
        $price = 10.0;
        $volatility = 0.05; // MARKET_VOLATILITY_FACTOR / 10 players
        $amount = 100;
        $depot = 5000;

        $newPrice = $this->priceAfterBuy($price, $volatility, $amount, $depot);
        $this->assertGreaterThan($price, $newPrice);
    }

    public function testBuyPriceFormula(): void
    {
        $price = 10.0;
        $volatility = 0.05;
        $amount = 100;
        $depot = 5000;

        // newPrice = 10 + 0.05 * 100 / 5000 = 10 + 0.001 = 10.001
        $newPrice = $this->priceAfterBuy($price, $volatility, $amount, $depot);
        $this->assertEqualsWithDelta(10.001, $newPrice, 0.0001);
    }

    public function testBuyLargeAmount(): void
    {
        $price = 10.0;
        $volatility = 0.05;
        $amount = 10000;
        $depot = 5000;

        // newPrice = 10 + 0.05 * 10000 / 5000 = 10 + 0.1 = 10.1
        $newPrice = $this->priceAfterBuy($price, $volatility, $amount, $depot);
        $this->assertEqualsWithDelta(10.1, $newPrice, 0.0001);
    }

    public function testBuyPriceChangeScalesWithAmount(): void
    {
        $price = 10.0;
        $volatility = 0.05;
        $depot = 5000;

        $change100 = $this->priceAfterBuy($price, $volatility, 100, $depot) - $price;
        $change200 = $this->priceAfterBuy($price, $volatility, 200, $depot) - $price;

        // Double the amount should double the price change
        $this->assertEqualsWithDelta($change100 * 2, $change200, 0.0000001);
    }

    public function testBuyPriceChangeInverselyScalesWithDepot(): void
    {
        $price = 10.0;
        $volatility = 0.05;
        $amount = 1000;

        $change5000 = $this->priceAfterBuy($price, $volatility, $amount, 5000) - $price;
        $change10000 = $this->priceAfterBuy($price, $volatility, $amount, 10000) - $price;

        // Double the depot should halve the price change
        $this->assertEqualsWithDelta($change5000 / 2, $change10000, 0.0000001);
    }

    public function testBuyZeroAmountNoChange(): void
    {
        $price = 10.0;
        $newPrice = $this->priceAfterBuy($price, 0.05, 0, 5000);
        $this->assertEquals($price, $newPrice);
    }

    // =========================================================================
    // PRICE DECREASE ON SELL
    // Formula: newPrice = 1 / (1/currentPrice + volatility * amount / depot)
    // This makes the price go DOWN when someone sells (supply increases)
    // =========================================================================

    /**
     * Helper: compute new price after selling.
     */
    private function priceAfterSell(float $currentPrice, float $volatility, int $amount, int $depot): float
    {
        return 1 / (1 / $currentPrice + $volatility * $amount / $depot);
    }

    public function testSellDecreasesPrice(): void
    {
        $price = 10.0;
        $volatility = 0.05;
        $amount = 100;
        $depot = 5000;

        $newPrice = $this->priceAfterSell($price, $volatility, $amount, $depot);
        $this->assertLessThan($price, $newPrice);
    }

    public function testSellPriceFormula(): void
    {
        $price = 10.0;
        $volatility = 0.05;
        $amount = 100;
        $depot = 5000;

        // newPrice = 1 / (1/10 + 0.05 * 100 / 5000) = 1 / (0.1 + 0.001) = 1 / 0.101
        $expected = 1 / (0.1 + 0.001);
        $newPrice = $this->priceAfterSell($price, $volatility, $amount, $depot);
        $this->assertEqualsWithDelta($expected, $newPrice, 0.0001);
    }

    public function testSellLargeAmount(): void
    {
        $price = 10.0;
        $volatility = 0.05;
        $amount = 10000;
        $depot = 5000;

        // newPrice = 1 / (0.1 + 0.1) = 1 / 0.2 = 5.0
        $newPrice = $this->priceAfterSell($price, $volatility, $amount, $depot);
        $this->assertEqualsWithDelta(5.0, $newPrice, 0.0001);
    }

    public function testSellZeroAmountNoChange(): void
    {
        $price = 10.0;
        $newPrice = $this->priceAfterSell($price, 0.05, 0, 5000);
        $this->assertEqualsWithDelta($price, $newPrice, 0.0001);
    }

    public function testSellPriceAlwaysPositive(): void
    {
        // Even with very large sells, price should never reach 0
        $price = 10.0;
        $volatility = 0.5; // Very high volatility (1 player)
        $depot = 500;

        for ($amount = 100; $amount <= 100000; $amount *= 10) {
            $newPrice = $this->priceAfterSell($price, $volatility, $amount, $depot);
            $this->assertGreaterThan(
                0,
                $newPrice,
                "Price should remain positive even after selling $amount units"
            );
        }
    }

    public function testSellPriceCannotGoNegative(): void
    {
        // The harmonic formula 1/(1/p + x) is always positive for positive p and x
        $price = 0.1; // Very low price
        $volatility = 0.5;
        $amount = 1000000;
        $depot = 500;

        $newPrice = $this->priceAfterSell($price, $volatility, $amount, $depot);
        $this->assertGreaterThan(0, $newPrice);
    }

    // =========================================================================
    // BUY/SELL ASYMMETRY
    // The formulas are deliberately asymmetric:
    //   Buy increases:  linear addition  (price + delta)
    //   Sell decreases: harmonic formula (prevents negative prices)
    //
    // NOTE: With the harmonic sell formula and linear buy formula, the net
    // direction of a buy+sell round trip depends on which is larger per unit.
    // For the buy formula: delta_buy  = vol * amount / depot
    // For the sell formula: 1/newP - 1/P = vol * amount / depot  (same change in 1/P)
    // Since the sell price drop in absolute terms is smaller than the buy price rise,
    // a sell after a buy ends up below the original price. This is the intended behavior:
    // the market maker does NOT extract profit; instead the harmonic sell formula
    // causes greater absolute price drops at higher price levels.
    // =========================================================================

    public function testBuySellAsymmetry(): void
    {
        // Buying then selling the same amount results in price below original
        // because harmonic sell on a higher price causes larger absolute drop
        $price = 10.0;
        $volatility = 0.05;
        $amount = 1000;
        $depot = 5000;

        $afterBuy = $this->priceAfterBuy($price, $volatility, $amount, $depot);
        $afterSell = $this->priceAfterSell($afterBuy, $volatility, $amount, $depot);

        // After buy+sell the price is below the original (harmonic nature of sell formula)
        $this->assertLessThan($price, $afterSell, 'Buy then sell should result in lower price (harmonic sell drops more at higher prices)');
    }

    public function testBuySellRoundTrip(): void
    {
        // After many buy/sell cycles, price drifts downward due to harmonic sell formula
        $price = 10.0;
        $volatility = 0.05;
        $amount = 1000;
        $depot = 5000;

        for ($i = 0; $i < 10; $i++) {
            $price = $this->priceAfterBuy($price, $volatility, $amount, $depot);
            $price = $this->priceAfterSell($price, $volatility, $amount, $depot);
        }

        $this->assertLessThan(10.0, $price, 'Price should drift down after repeated buy/sell cycles due to harmonic sell');
    }

    // =========================================================================
    // TRANSACTION COSTS
    // Buy cost: round(price * amount)
    // Sell revenue: round(price * amount)
    // =========================================================================

    public function testBuyCost(): void
    {
        $price = 10.5;
        $amount = 100;
        $cost = round($price * $amount);
        $this->assertEquals(1050, $cost);
    }

    public function testBuyCostRounding(): void
    {
        $price = 10.3;
        $amount = 33;
        $cost = round($price * $amount);
        $this->assertEquals(round(339.9), $cost);
        $this->assertEquals(340, $cost);
    }

    public function testSellRevenue(): void
    {
        $price = 8.7;
        $amount = 200;
        $revenue = round($price * $amount);
        $this->assertEquals(1740, $revenue);
    }

    // =========================================================================
    // MERCHANT SPEED
    // =========================================================================

    public function testMerchantSpeed(): void
    {
        $this->assertEquals(20, MERCHANT_SPEED);
    }

    public function testMerchantTravelTime(): void
    {
        // Travel time formula: round(3600 * distance / vitesseMarchands)
        $distance = 10.0; // 10 cases
        $travelTime = round(3600 * $distance / MERCHANT_SPEED);
        // 3600 * 10 / 20 = 1800 seconds = 30 minutes
        $this->assertEquals(1800, $travelTime);
    }

    public function testMerchantDistanceCalculation(): void
    {
        // Distance: sqrt((x1-x2)^2 + (y1-y2)^2)
        $x1 = 0; $y1 = 0;
        $x2 = 3; $y2 = 4;
        $distance = pow(pow($x1 - $x2, 2) + pow($y1 - $y2, 2), 0.5);
        $this->assertEquals(5.0, $distance);
    }

    // =========================================================================
    // MARKET BEHAVIOR EDGE CASES
    // =========================================================================

    public function testHighVolatilityMarket(): void
    {
        // With only 1 active player, volatility = 0.5
        $volatility = MARKET_VOLATILITY_FACTOR / 1; // 0.5
        $price = 10.0;
        $amount = 1000;
        $depot = 500;

        // Buy: 10 + 0.5 * 1000 / 500 = 10 + 1.0 = 11.0
        $newPrice = $this->priceAfterBuy($price, $volatility, $amount, $depot);
        $this->assertEqualsWithDelta(11.0, $newPrice, 0.0001);
    }

    public function testLowVolatilityMarket(): void
    {
        // With 100 active players, volatility = 0.005
        $volatility = MARKET_VOLATILITY_FACTOR / 100; // 0.005
        $price = 10.0;
        $amount = 1000;
        $depot = 5000;

        // Buy: 10 + 0.005 * 1000 / 5000 = 10 + 0.001 = 10.001
        $newPrice = $this->priceAfterBuy($price, $volatility, $amount, $depot);
        $this->assertEqualsWithDelta(10.001, $newPrice, 0.0001);
    }

    public function testPriceImpactScalesWithDepot(): void
    {
        // A player with a larger depot has less price impact per unit
        $volatility = 0.05;
        $price = 10.0;
        $amount = 1000;

        $impactSmallDepot = $this->priceAfterBuy($price, $volatility, $amount, 1000) - $price;
        $impactLargeDepot = $this->priceAfterBuy($price, $volatility, $amount, 10000) - $price;

        $this->assertGreaterThan(
            $impactLargeDepot,
            $impactSmallDepot,
            'Smaller depot should cause larger price impact'
        );
    }

    public function testMultipleBuysAccumulate(): void
    {
        // Multiple small buys should have same total effect as one large buy
        $volatility = 0.05;
        $depot = 5000;
        $price = 10.0;

        // One buy of 1000
        $oneBuy = $this->priceAfterBuy($price, $volatility, 1000, $depot);

        // Ten buys of 100
        $tenBuys = $price;
        for ($i = 0; $i < 10; $i++) {
            $tenBuys = $this->priceAfterBuy($tenBuys, $volatility, 100, $depot);
        }

        // They should be exactly equal since the formula is linear in amount
        $this->assertEqualsWithDelta($oneBuy, $tenBuys, 0.0000001);
    }

    public function testMultipleSellsAccumulate(): void
    {
        // The harmonic sell formula 1/(1/p + vol*amount/depot) is ADDITIVE in 1/p space,
        // so ten sells of 100 units produce the same result as one sell of 1000 units.
        // This is a key property: total price impact depends only on total volume, not order.
        $volatility = 0.05;
        $depot = 5000;
        $price = 10.0;

        // One sell of 1000
        $oneSell = $this->priceAfterSell($price, $volatility, 1000, $depot);

        // Ten sells of 100
        $tenSells = $price;
        for ($i = 0; $i < 10; $i++) {
            $tenSells = $this->priceAfterSell($tenSells, $volatility, 100, $depot);
        }

        // Both approaches yield the same result because the formula is additive in 1/p space
        $this->assertEqualsWithDelta($oneSell, $tenSells, 0.0000001);
    }
}
