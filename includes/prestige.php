<?php
/**
 * Prestige System — Cross-Season Progression
 *
 * Prestige Points (PP) are earned at end of each season based on activity.
 * PP accumulate across seasons and unlock permanent bonuses.
 */

// Prestige unlock thresholds and effects
$PRESTIGE_UNLOCKS = [
    'debutant_rapide' => [
        'cost' => 50,
        'name' => 'Débutant Rapide',
        'desc' => 'Commence avec le Générateur niveau 2',
        'effect' => 'generateur_level_2'
    ],
    'experimente' => [
        'cost' => 100,
        'name' => 'Expérimenté',
        'desc' => '+5% de production de ressources',
        'effect' => 'production_5pct'
    ],
    'veteran' => [
        'cost' => 250,
        'name' => 'Vétéran',
        'desc' => '+1 jour de protection débutant',
        'effect' => 'extra_protection_day'
    ],
    'maitre_chimiste' => [
        'cost' => 500,
        'name' => 'Maître Chimiste',
        'desc' => '+5% aux stats de combat',
        'effect' => 'combat_5pct'
    ],
    'legende' => [
        'cost' => 1000,
        'name' => 'Légende',
        'desc' => 'Badge unique + nom coloré',
        'effect' => 'legend_badge'
    ],
];

/**
 * Calculate prestige points earned for a player at end of season.
 */
function calculatePrestigePoints($login) {
    global $base, $MEDAL_THRESHOLDS_TERREUR, $MEDAL_THRESHOLDS_ATTAQUE, $MEDAL_THRESHOLDS_DEFENSE,
           $MEDAL_THRESHOLDS_PILLAGE, $MEDAL_THRESHOLDS_PERTES, $MEDAL_THRESHOLDS_ENERGIEVORE;

    $pp = 0;

    // Check if player was active in the final week (logged in within last 7 days)
    $lastActive = dbFetchOne($base, 'SELECT timestamp FROM membre WHERE login=?', 's', $login);
    if ($lastActive && (time() - $lastActive['timestamp']) < SECONDS_PER_WEEK) {
        $pp += 5; // Active during final week
    }

    // Medal tiers: count tiers reached dynamically from raw stats in `autre` table
    // FIX: was querying non-existent `medailles` table, now uses actual medal threshold arrays
    $autre = dbFetchOne($base, 'SELECT * FROM autre WHERE login=?', 's', $login);
    if ($autre) {
        $medalChecks = [
            [$autre['nbattaques'], $MEDAL_THRESHOLDS_TERREUR],
            [$autre['pointsAttaque'], $MEDAL_THRESHOLDS_ATTAQUE],
            [$autre['pointsDefense'], $MEDAL_THRESHOLDS_DEFENSE],
            [$autre['ressourcesPillees'], $MEDAL_THRESHOLDS_PILLAGE],
            [$autre['moleculesPerdues'], $MEDAL_THRESHOLDS_PERTES],
            [$autre['energieDepensee'], $MEDAL_THRESHOLDS_ENERGIEVORE],
        ];
        foreach ($medalChecks as [$value, $thresholds]) {
            $tier = 0;
            foreach ($thresholds as $t) {
                if ($value >= $t) $tier++;
            }
            $pp += $tier; // 1 PP per tier reached
        }

        // Activity-based PP
        if ($autre['nbattaques'] >= 10) $pp += 5;
        if ($autre['tradeVolume'] >= 20) $pp += 3;
        if ($autre['energieDonnee'] > 0) $pp += 2;
    }

    return $pp;
}

/**
 * Award prestige points to all active players. Call before remiseAZero().
 */
function awardPrestigePoints() {
    global $base;

    // Get all players ranked by totalPoints (for rank bonus)
    $players = dbQuery($base, 'SELECT login, totalPoints FROM autre ORDER BY totalPoints DESC');
    $rank = 1;
    while ($player = mysqli_fetch_array($players)) {
        $pp = calculatePrestigePoints($player['login']);

        // Rank bonus: top 50 get extra PP
        if ($rank <= 5) {
            $pp += 50;
        } elseif ($rank <= 10) {
            $pp += 30;
        } elseif ($rank <= 25) {
            $pp += 20;
        } elseif ($rank <= 50) {
            $pp += 10;
        }

        if ($pp > 0) {
            // Ensure prestige row exists, then add PP
            dbExecute($base, 'INSERT INTO prestige (login, total_pp) VALUES (?, ?) ON DUPLICATE KEY UPDATE total_pp = total_pp + ?', 'sii', $player['login'], $pp, $pp);
        }

        $rank++;
    }
}

/**
 * Get a player's prestige data.
 */
function getPrestige($login) {
    global $base;
    $data = dbFetchOne($base, 'SELECT * FROM prestige WHERE login=?', 's', $login);
    if (!$data) {
        return ['total_pp' => 0, 'unlocks' => ''];
    }
    return $data;
}

/**
 * Check if a player has a specific prestige unlock.
 */
function hasPrestigeUnlock($login, $unlockKey) {
    $prestige = getPrestige($login);
    $unlocks = array_filter(explode(',', $prestige['unlocks']));
    return in_array($unlockKey, $unlocks);
}

/**
 * Purchase a prestige unlock. Returns true on success, error string on failure.
 */
function purchasePrestigeUnlock($login, $unlockKey) {
    global $base, $PRESTIGE_UNLOCKS;

    if (!isset($PRESTIGE_UNLOCKS[$unlockKey])) {
        return 'Amélioration inconnue.';
    }

    $unlock = $PRESTIGE_UNLOCKS[$unlockKey];

    // Use transaction + row lock to prevent TOCTOU double-spend
    $result = null;
    withTransaction($base, function() use ($base, $login, $unlockKey, $unlock, &$result) {
        $prestige = dbFetchOne($base, 'SELECT total_pp, unlocks FROM prestige WHERE login=? FOR UPDATE', 's', $login);

        if (!$prestige) {
            $result = 'Données de prestige introuvables.';
            return;
        }

        $unlocks = array_filter(explode(',', $prestige['unlocks']));

        if (in_array($unlockKey, $unlocks)) {
            $result = 'Vous avez déjà cette amélioration.';
            return;
        }

        if ($prestige['total_pp'] < $unlock['cost']) {
            $result = 'Pas assez de points de prestige (' . $prestige['total_pp'] . '/' . $unlock['cost'] . ').';
            return;
        }

        $unlocks[] = $unlockKey;
        $newUnlocks = implode(',', $unlocks);

        dbExecute($base, 'UPDATE prestige SET unlocks=?, total_pp = total_pp - ? WHERE login=? AND total_pp >= ?', 'sisi', $newUnlocks, $unlock['cost'], $login, $unlock['cost']);

        $result = true;
    });

    return $result;
}

/**
 * Get the prestige production bonus multiplier for a player (from 'experimente' unlock).
 */
function prestigeProductionBonus($login) {
    if (hasPrestigeUnlock($login, 'experimente')) {
        return 1.05; // +5%
    }
    return 1.0;
}

/**
 * Get the prestige combat bonus multiplier for a player (from 'maitre_chimiste' unlock).
 */
function prestigeCombatBonus($login) {
    if (hasPrestigeUnlock($login, 'maitre_chimiste')) {
        return 1.05; // +5%
    }
    return 1.0;
}

/**
 * Check if player has legend status (for display purposes).
 */
function isPrestigeLegend($login) {
    return hasPrestigeUnlock($login, 'legende');
}
