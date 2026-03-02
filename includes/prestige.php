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
    global $base;

    $pp = 0;

    // Check if player was active in the final week (logged in within last 7 days)
    $lastActive = dbFetchOne($base, 'SELECT timestamp FROM membre WHERE login=?', 's', $login);
    if ($lastActive && (time() - $lastActive['timestamp']) < SECONDS_PER_WEEK) {
        $pp += 5; // Active during final week
    }

    // Medal tiers reached (each medal tier = 1 PP)
    $medailles = dbFetchOne($base, 'SELECT * FROM medailles WHERE login=?', 's', $login);
    if ($medailles) {
        // Medal columns: 'terreur', 'explorateur', 'commercial', 'alchimiste', 'demolisseur', 'energetique'
        $medalColumns = ['terreur', 'explorateur', 'commercial', 'alchimiste', 'demolisseur', 'energetique'];
        foreach ($medalColumns as $medal) {
            if (isset($medailles[$medal])) {
                $pp += intval($medailles[$medal]); // 1 PP per tier
            }
        }
    }

    // Activity-based PP
    $autre = dbFetchOne($base, 'SELECT nbattaques, moleculesPerdues, tradeVolume, energieDonnee FROM autre WHERE login=?', 's', $login);
    if ($autre) {
        // Launched 10+ attacks
        if ($autre['nbattaques'] >= 10) $pp += 5;

        // Traded 20+ times (use tradeVolume as proxy — each trade adds to it)
        if ($autre['tradeVolume'] >= 20) $pp += 3;

        // Donated to alliance
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
    $prestige = getPrestige($login);

    if (hasPrestigeUnlock($login, $unlockKey)) {
        return 'Vous avez déjà cette amélioration.';
    }

    if ($prestige['total_pp'] < $unlock['cost']) {
        return 'Pas assez de points de prestige (' . $prestige['total_pp'] . '/' . $unlock['cost'] . ').';
    }

    // Add unlock to list
    $unlocks = array_filter(explode(',', $prestige['unlocks']));
    $unlocks[] = $unlockKey;
    $newUnlocks = implode(',', $unlocks);

    dbExecute($base, 'UPDATE prestige SET unlocks=? WHERE login=?', 'ss', $newUnlocks, $login);

    return true;
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
