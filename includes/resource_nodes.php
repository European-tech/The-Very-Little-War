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
 * @param int|null $mapSize  Map boundary to scatter nodes within. Defaults to MAP_INITIAL_SIZE
 *                           from config.php so nodes always span the full configured map area
 *                           even if the caller does not pass an explicit size.
 */
function generateResourceNodes($base, $mapSize = null)
{
    global $nomsRes;

    // Default to the config-defined initial map size so hardcoded boundaries are never used.
    // Callers may pass a larger value if the map has grown, but MAP_INITIAL_SIZE is the
    // authoritative minimum (HIGH-023).
    if ($mapSize === null || $mapSize < 2) {
        $mapSize = MAP_INITIAL_SIZE;
    }

    // Pre-generate node placement data before the transaction to avoid rand calls inside
    $count = mt_rand(RESOURCE_NODE_MIN_COUNT, RESOURCE_NODE_MAX_COUNT);
    $margin = 1;
    $placed = [];
    $nodesToInsert = [];
    $resourceTypes = array_merge($nomsRes, ['energie']);

    for ($i = 0; $i < $count; $i++) {
        $maxAttempts = 50;
        $attempts = 0;
        $valid = false;
        $x = 0;
        $y = 0;

        while ($attempts < $maxAttempts) {
            $x = mt_rand($margin, $mapSize - 1 - $margin);
            $y = mt_rand($margin, $mapSize - 1 - $margin);

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
            continue;
        }

        $resourceType = $resourceTypes[array_rand($resourceTypes)];
        $placed[] = [$x, $y];
        $nodesToInsert[] = [$x, $y, $resourceType];
    }

    // Atomic: clear old nodes and insert new ones in a single transaction
    withTransaction($base, function() use ($base, $nodesToInsert) {
        dbExecute($base, 'DELETE FROM resource_nodes');
        foreach ($nodesToInsert as [$x, $y, $resourceType]) {
            dbExecute($base,
                'INSERT INTO resource_nodes (x, y, resource_type, bonus_pct, radius) VALUES (?, ?, ?, ?, ?)',
                'iisdi',
                $x, $y, $resourceType, RESOURCE_NODE_DEFAULT_BONUS_PCT, RESOURCE_NODE_DEFAULT_RADIUS
            );
        }
    });

    // MED-053: Invalidate the in-process static node cache so any subsequent call to
    // getResourceNodeBonus() within the same request picks up the newly generated nodes.
    clearResourceNodeCache();
}

/**
 * Invalidate the static node cache inside getResourceNodeBonus().
 * Must be called after generateResourceNodes() so the new nodes are picked up
 * on the next call within the same PHP request (e.g. during season reset).
 */
function clearResourceNodeCache()
{
    // PHP static variables can be reset by passing a reference via a nested function.
    // The simplest cross-version approach: use a process-wide flag that
    // getResourceNodeBonus() checks to know it must refetch.
    getResourceNodeBonus(null, 0, 0, '__INVALIDATE__');
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

    // MED-053: Allow cache invalidation (called by clearResourceNodeCache() after regeneration)
    if ($resourceName === '__INVALIDATE__') {
        $nodesCache = null;
        return 0.0;
    }

    // Cache all active nodes (they change only on season reset)
    if ($nodesCache === null) {
        $nodesCache = dbFetchAll($base, 'SELECT x, y, resource_type, bonus_pct, radius FROM resource_nodes WHERE active = 1');
        if (!$nodesCache) {
            $nodesCache = [];
        }
    }

    $totalBonus = 0.0;
    $mapBound = defined('MAP_SIZE') ? MAP_SIZE : 100;
    foreach ($nodesCache as $node) {
        if ($node['resource_type'] !== $resourceName) {
            continue;
        }
        // LOW-024: Skip nodes with invalid/out-of-bounds coordinates (corrupt DB rows)
        if ($node['x'] < 0 || $node['y'] < 0 ||
            $node['x'] >= $mapBound ||
            $node['y'] >= $mapBound) {
            continue;
        }
        $dist = sqrt(pow($px - $node['x'], 2) + pow($py - $node['y'], 2));
        if ($dist <= $node['radius']) {
            $totalBonus += $node['bonus_pct'] / 100.0;
        }
    }

    return min($totalBonus, RESOURCE_NODE_MAX_BONUS_PCT / 100.0);
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
