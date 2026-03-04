<?php
/**
 * Resource Nodes — map-based production bonuses.
 *
 * Nodes are scattered across the map each season. Players within a node's
 * radius receive a % production bonus for the matching resource type.
 */

require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/database.php');

/**
 * Generate random resource nodes for a new season.
 * Clears existing nodes and creates RESOURCE_NODE_MIN_COUNT..MAX_COUNT new ones.
 *
 * @param mysqli $base  Database connection
 * @param int $mapSize  Current map size (tailleCarte)
 */
function generateResourceNodes($base, $mapSize)
{
    global $nomsRes;

    // Clear old nodes
    dbExecute($base, 'DELETE FROM resource_nodes');

    $count = mt_rand(RESOURCE_NODE_MIN_COUNT, RESOURCE_NODE_MAX_COUNT);
    $margin = 1; // avoid placing on the very edge
    $placed = [];

    // Resource types: 8 atom types + energy
    $resourceTypes = array_merge($nomsRes, ['energie']);

    for ($i = 0; $i < $count; $i++) {
        $maxAttempts = 50;
        $attempts = 0;
        $x = 0;
        $y = 0;
        $valid = false;

        while ($attempts < $maxAttempts) {
            $x = mt_rand($margin, $mapSize - 1 - $margin);
            $y = mt_rand($margin, $mapSize - 1 - $margin);

            // Check minimum distance from all placed nodes
            $tooClose = false;
            foreach ($placed as $node) {
                $dist = sqrt(pow($x - $node[0], 2) + pow($y - $node[1], 2));
                if ($dist < RESOURCE_NODE_MIN_DISTANCE) {
                    $tooClose = true;
                    break;
                }
            }

            if (!$tooClose) {
                $valid = true;
                break;
            }
            $attempts++;
        }

        if (!$valid) {
            continue; // skip if can't place (small map)
        }

        $resourceType = $resourceTypes[array_rand($resourceTypes)];
        $placed[] = [$x, $y];

        dbExecute($base,
            'INSERT INTO resource_nodes (x, y, resource_type, bonus_pct, radius) VALUES (?, ?, ?, ?, ?)',
            'iisdi',
            $x, $y, $resourceType, RESOURCE_NODE_DEFAULT_BONUS_PCT, RESOURCE_NODE_DEFAULT_RADIUS
        );
    }
}

/**
 * Get total resource node bonus for a player at position (px, py) for a given resource.
 *
 * @param mysqli $base  Database connection
 * @param int $px       Player X coordinate
 * @param int $py       Player Y coordinate
 * @param string $resourceName  Resource name (e.g. 'carbone', 'energie')
 * @return float  Bonus multiplier (e.g. 0.10 for +10%)
 */
function getResourceNodeBonus($base, $px, $py, $resourceName)
{
    static $nodesCache = null;

    // Cache all active nodes (they change only on season reset)
    if ($nodesCache === null) {
        $nodesCache = dbFetchAll($base, 'SELECT x, y, resource_type, bonus_pct, radius FROM resource_nodes WHERE active = 1');
        if (!$nodesCache) {
            $nodesCache = [];
        }
    }

    $totalBonus = 0.0;
    foreach ($nodesCache as $node) {
        if ($node['resource_type'] !== $resourceName) {
            continue;
        }
        $dist = sqrt(pow($px - $node['x'], 2) + pow($py - $node['y'], 2));
        if ($dist <= $node['radius']) {
            $totalBonus += $node['bonus_pct'] / 100.0;
        }
    }

    return $totalBonus;
}

/**
 * Get all active resource nodes (for map display).
 *
 * @param mysqli $base  Database connection
 * @return array  Array of node rows
 */
function getActiveResourceNodes($base)
{
    return dbFetchAll($base, 'SELECT id, x, y, resource_type, bonus_pct, radius FROM resource_nodes WHERE active = 1');
}
