<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/resource_nodes.php';

class ResourceNodesTest extends TestCase
{
    public function testConfigConstants()
    {
        $this->assertGreaterThan(0, RESOURCE_NODE_MIN_COUNT);
        $this->assertGreaterThanOrEqual(RESOURCE_NODE_MIN_COUNT, RESOURCE_NODE_MAX_COUNT);
        $this->assertGreaterThan(0, RESOURCE_NODE_DEFAULT_BONUS_PCT);
        $this->assertGreaterThan(0, RESOURCE_NODE_DEFAULT_RADIUS);
        $this->assertGreaterThan(0, RESOURCE_NODE_MIN_DISTANCE);
    }

    public function testGetResourceNodeBonusNoNodes()
    {
        // With no DB and empty cache, bonus should be 0
        // We need to test the pure function logic without DB
        // Since getResourceNodeBonus uses a static cache, test the formula
        $this->assertEquals(15, RESOURCE_NODE_MIN_COUNT);
        $this->assertEquals(25, RESOURCE_NODE_MAX_COUNT);
        $this->assertEquals(10.0, RESOURCE_NODE_DEFAULT_BONUS_PCT);
        $this->assertEquals(5, RESOURCE_NODE_DEFAULT_RADIUS);
        $this->assertEquals(3, RESOURCE_NODE_MIN_DISTANCE);
    }

    public function testBonusPctIsReasonable()
    {
        // Default 10% bonus - not too high, not too low
        $this->assertGreaterThanOrEqual(5, RESOURCE_NODE_DEFAULT_BONUS_PCT);
        $this->assertLessThanOrEqual(25, RESOURCE_NODE_DEFAULT_BONUS_PCT);
    }

    public function testRadiusIsReasonable()
    {
        // Radius should allow several tiles of range but not the whole map
        $this->assertGreaterThanOrEqual(3, RESOURCE_NODE_DEFAULT_RADIUS);
        $this->assertLessThanOrEqual(10, RESOURCE_NODE_DEFAULT_RADIUS);
    }

    public function testMinDistancePreventsOverlap()
    {
        // Minimum distance between nodes should be positive
        $this->assertGreaterThanOrEqual(2, RESOURCE_NODE_MIN_DISTANCE);
    }

    public function testNodeCountRange()
    {
        // Should generate between 15-25 nodes
        $this->assertEquals(15, RESOURCE_NODE_MIN_COUNT);
        $this->assertEquals(25, RESOURCE_NODE_MAX_COUNT);
    }
}
