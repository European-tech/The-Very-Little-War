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
           $MEDAL_THRESHOLDS_PILLAGE, $MEDAL_THRESHOLDS_PERTES, $MEDAL_THRESHOLDS_ENERGIEVORE,
           $MEDAL_THRESHOLDS_PIPELETTE, $MEDAL_THRESHOLDS_CONSTRUCTEUR, $MEDAL_THRESHOLDS_BOMBE;
    // intentionally excluded: $MEDAL_THRESHOLDS_TROLL — no `troll` column exists in `autre`

    $pp = 0;

    // Check if player was active in the final week (logged in within last 7 days)
    // Note: 'derniereConnexion' is updated on each page load; 'timestamp' is only set at registration/reset
    $lastActive = dbFetchOne($base, 'SELECT derniereConnexion FROM membre WHERE login=?', 's', $login);
    if ($lastActive && (time() - $lastActive['derniereConnexion']) < SECONDS_PER_WEEK) {
        $pp += PRESTIGE_PP_ACTIVE_FINAL_WEEK;
    }

    // Medal tiers: count tiers reached dynamically from raw stats in `autre` table
    // All 9 medal categories (10 defined in config; Troll excluded — no DB column):
    //   Terreur, Attaque, Defense, Pillage, Pertes, Energievore (from `autre`)
    //   Constructeur (batMax in `autre`), Bombe (bombe in `autre`)
    //   Pipelette (forum messages — requires separate query on `reponses`)
    //   Troll — intentionally excluded: no troll counter column in `autre` table
    $autre = dbFetchOne($base, 'SELECT * FROM autre WHERE login=?', 's', $login);
    if ($autre) {
        $medalChecks = [
            [$autre['nbattaques'],       $MEDAL_THRESHOLDS_TERREUR],
            [$autre['pointsAttaque'],    $MEDAL_THRESHOLDS_ATTAQUE],
            [$autre['pointsDefense'],    $MEDAL_THRESHOLDS_DEFENSE],
            [$autre['ressourcesPillees'],$MEDAL_THRESHOLDS_PILLAGE],
            [$autre['moleculesPerdues'], $MEDAL_THRESHOLDS_PERTES],
            [$autre['energieDepensee'],  $MEDAL_THRESHOLDS_ENERGIEVORE],
            [$autre['batMax'],           $MEDAL_THRESHOLDS_CONSTRUCTEUR],
            [$autre['bombe'],            $MEDAL_THRESHOLDS_BOMBE],
        ];
        foreach ($medalChecks as [$value, $thresholds]) {
            $tier = 0;
            foreach ($thresholds as $t) {
                if ($value >= $t) $tier++;
            }
            $pp += $tier; // 1 PP per tier reached
        }

        // Pipelette medal: count forum messages from reponses table
        $pipeRow = dbFetchOne($base, 'SELECT COUNT(*) AS nbmessages FROM reponses WHERE auteur=?', 's', $login);
        if ($pipeRow) {
            $pipeTier = 0;
            foreach ($MEDAL_THRESHOLDS_PIPELETTE as $t) {
                if ($pipeRow['nbmessages'] >= $t) $pipeTier++;
            }
            $pp += $pipeTier;
        }

        // Activity-based PP
        if ($autre['nbattaques'] >= PRESTIGE_PP_ATTACK_THRESHOLD) $pp += PRESTIGE_PP_ATTACK_BONUS;
        if ($autre['tradeVolume'] >= PRESTIGE_PP_TRADE_THRESHOLD) $pp += PRESTIGE_PP_TRADE_BONUS;
        if ($autre['energieDonnee'] >= PP_DONATION_MIN_THRESHOLD) $pp += PRESTIGE_PP_DONATION_BONUS;
    }

    return $pp;
}

/**
 * Award prestige points to all active players. Call before remiseAZero().
 * MEDIUM-014: Wrapped in a transaction so all PP awards are atomic.
 */
function awardPrestigePoints() {
    global $base, $PRESTIGE_RANK_BONUSES;

    // Freeze rankings into array to prevent concurrent changes mid-award
    // Exclude inactive/banned players (x = INACTIVE_PLAYER_X sentinel = -1000)
    // Read rankings BEFORE the transaction to avoid long-held locks on autre/membre
    $players = dbFetchAll($base, 'SELECT a.login, a.totalPoints FROM autre a JOIN membre m ON m.login = a.login WHERE m.x != ' . INACTIVE_PLAYER_X . ' ORDER BY a.totalPoints DESC');

    // HIGH-019: Compute true DENSE_RANK (no gaps) so tied players receive the same rank bonus.
    // Increment denseRank by 1 only when the score changes — never by the index offset.
    $denseRank = 1;
    $prevScore = null;
    foreach ($players as &$player) {
        if ($prevScore !== null && $player['totalPoints'] !== $prevScore) {
            $denseRank++;
        }
        $player['dense_rank'] = $denseRank;
        $prevScore = $player['totalPoints'];
    }
    unset($player);

    // Compute all PP values outside the transaction (read-only, no locks needed)
    $awards = [];
    foreach ($players as $player) {
        $pp = calculatePrestigePoints($player['login']);

        // Rank bonus: top players get extra PP (using DENSE_RANK so ties share rank)
        foreach ($PRESTIGE_RANK_BONUSES as $cutoff => $bonus) {
            if ($player['dense_rank'] <= $cutoff) {
                $pp += $bonus;
                break;
            }
        }

        if ($pp > 0) {
            $awards[] = ['login' => $player['login'], 'pp' => $pp];
        }
    }

    // MEDIUM-014: Apply all PP awards atomically in a single transaction
    if (!empty($awards)) {
        withTransaction($base, function() use ($base, $awards) {
            foreach ($awards as $award) {
                // Ensure prestige row exists, then add PP
                dbExecute($base, 'INSERT INTO prestige (login, total_pp) VALUES (?, ?) ON DUPLICATE KEY UPDATE total_pp = total_pp + ?', 'sii', $award['login'], $award['pp'], $award['pp']);
            }
        });
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

        $affected = dbExecute($base, 'UPDATE prestige SET unlocks=?, total_pp = total_pp - ? WHERE login=? AND total_pp >= ?', 'sisi', $newUnlocks, $unlock['cost'], $login, $unlock['cost']);

        if ($affected === 0) {
            $result = 'Erreur lors de l\'achat. Veuillez réessayer.';
            return;
        }
        $result = true;
    });

    return $result;
}

/**
 * Get the prestige production bonus multiplier for a player (from 'experimente' unlock).
 */
function prestigeProductionBonus($login) {
    if (hasPrestigeUnlock($login, 'experimente')) {
        return PRESTIGE_PRODUCTION_BONUS;
    }
    return 1.0;
}

/**
 * Get the prestige combat bonus multiplier for a player (from 'maitre_chimiste' unlock).
 */
function prestigeCombatBonus($login) {
    if (hasPrestigeUnlock($login, 'maitre_chimiste')) {
        return PRESTIGE_COMBAT_BONUS;
    }
    return 1.0;
}

/**
 * Check if player has legend status (for display purposes).
 */
function isPrestigeLegend($login) {
    return hasPrestigeUnlock($login, 'legende');
}
