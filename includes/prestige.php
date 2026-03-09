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

    // Check if player was active in the final week of the season.
    // M-014: We must check against the season's final week, NOT 7 days from now.
    // If admin triggers season-end late (e.g. 10 days after season ended), a wall-clock
    // check of (time() - derniereConnexion) < SECONDS_PER_WEEK would wrongly exclude
    // players who were active during the season's last week.
    // Fix: read the season start timestamp from statistiques.debut, compute season end
    // as debut + SECONDS_PER_MONTH (31 days), and check whether derniereConnexion falls
    // within the final week of that computed season interval.
    // Note: 'derniereConnexion' is updated on each page load; 'timestamp' is only set at registration/reset
    $lastActive = dbFetchOne($base, 'SELECT derniereConnexion FROM membre WHERE login=?', 's', $login);
    $seasonRow  = dbFetchOne($base, 'SELECT debut FROM statistiques LIMIT 1', '');
    // FLOW-SEASON HIGH-002: Phase 1 overwrites statistiques.debut with the maintenance
    // start timestamp (the moment a new calendar month was detected), so debut no longer
    // holds the real season start. The real season END is the start of the current calendar
    // month (i.e. midnight on the 1st of the month in which debut falls), because that is
    // exactly when the month rolled over and maintenance was triggered.
    // Using debut + SECONDS_PER_MONTH would give a future date ~31 days from now, making
    // the final-week window start in the future and excluding all active players.
    if ($seasonRow && $seasonRow['debut'] > 0) {
        // SEASON_RESET MEDIUM-004: Use current calendar month (not debut month) so that
        // a manual reset with a stale debut value still produces the correct season end.
        // The season ended at midnight on the 1st of the CURRENT month regardless of
        // when debut was last written.
        $seasonEnd = mktime(0, 0, 0, (int)date('n'), 1, (int)date('Y'));
    } else {
        $seasonEnd = time();
    }
    $seasonFinalWeekStart = $seasonEnd - SECONDS_PER_WEEK;
    if ($lastActive && (int)$lastActive['derniereConnexion'] >= $seasonFinalWeekStart) {
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
            $pp += $tier * PRESTIGE_PP_PER_MEDAL_TIER; // PP per tier from config (PRST16-001)
        }

        // Pipelette medal: count forum messages from reponses table
        $pipeRow = dbFetchOne($base, 'SELECT COUNT(*) AS nbmessages FROM reponses WHERE auteur=?', 's', $login);
        if ($pipeRow) {
            $pipeTier = 0;
            foreach ($MEDAL_THRESHOLDS_PIPELETTE as $t) {
                if ($pipeRow['nbmessages'] >= $t) $pipeTier++;
            }
            $pp += $pipeTier * PRESTIGE_PP_PER_MEDAL_TIER; // consistent with other medals (PRST16-001)
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

    // P9-INFO-004: Idempotency guard — do not award PP twice for the same season.
    // The current season number is MAX(season_number)+1 from season_recap (consistent
    // with archiveSeasonData()). We store the last awarded season in statistiques so
    // a retry or double-call within the same season is a no-op.
    // NOTE: Season number here is +1 vs season_recap table. Season N in prestige log = Season N-1 in recap.
    // This offset is intentional and documented (Pass 7 LOW-027).
    // Explanation: season_recap stores the season that just ENDED (MAX(season_number) = last completed season).
    // prestige_awarded_season stores the season for which PP was just AWARDED, which is MAX+1 (the one ending now).
    $lastRecap  = dbFetchOne($base, 'SELECT MAX(season_number) AS max_s FROM season_recap', '', '');
    $currentSeason = ($lastRecap && $lastRecap['max_s']) ? (int)$lastRecap['max_s'] + 1 : 1;
    $statsRow = dbFetchOne($base, 'SELECT prestige_awarded_season FROM statistiques', '', '');
    // H-009: Handle the case where the statistiques table is empty (no row yet).
    // If null, the season was never recorded — treat prestige_awarded_season as 0 (proceed with award).
    // This prevents skipping the idempotency guard when the table row does not exist,
    // which would allow double-awarding PP on a fresh installation or after a data loss event.
    if ($statsRow === null) {
        // No statistiques row exists — table is empty; proceed with award.
        // The UPDATE at the end will be a no-op (0 rows affected), so we track this
        // state and INSERT the row instead after the transaction completes.
        $statsRowMissing = true;
    } elseif ((int)$statsRow['prestige_awarded_season'] >= $currentSeason) {
        // Already awarded for this season — skip silently.
        return;
    } else {
        $statsRowMissing = false;
    }

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
        // M-021/L-005: ksort() ensures ascending cutoff order regardless of insertion order
        // in config.php — the loop breaks on the first match, so the smallest cutoff (highest
        // reward) must appear first. Without sort, a reordering in config would silently give
        // top-5 players the top-50 bonus instead of the top-5 bonus.
        ksort($PRESTIGE_RANK_BONUSES);
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
    // SEASON-HIGH-001: Mark season as awarded INSIDE transaction — atomic with PP grants.
    // H-009: statsRowMissing flag controls whether we UPDATE or INSERT the idempotency stamp.
    if (!empty($awards)) {
        withTransaction($base, function() use ($base, $awards, $currentSeason, $statsRowMissing) {
            foreach ($awards as $award) {
                // Ensure prestige row exists, then add PP
                dbExecute($base, 'INSERT INTO prestige (login, total_pp) VALUES (?, ?) ON DUPLICATE KEY UPDATE total_pp = total_pp + ?', 'sii', $award['login'], $award['pp'], $award['pp']);
            }
            // P9-INFO-004: Mark this season as awarded so retries are idempotent.
            // Inside the transaction so idempotency flag and PP grants are atomic.
            // H-009: If the statistiques row did not exist, INSERT it; otherwise UPDATE.
            if ($statsRowMissing) {
                dbExecute($base, 'INSERT INTO statistiques (inscrits, maintenance, debut, numerovisiteur, tailleCarte, nbDerniere, prestige_awarded_season) SELECT inscrits, maintenance, debut, numerovisiteur, tailleCarte, nbDerniere, ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM statistiques)', 'i', $currentSeason);
            } else {
                dbExecute($base, 'UPDATE statistiques SET prestige_awarded_season = ?', 'i', $currentSeason);
            }
        });
    } else {
        // No awards but still mark to prevent re-checking next cron tick.
        // H-009: If the statistiques row did not exist, we cannot INSERT here without knowing all
        // required NOT NULL column values. In this edge case (no players, no awards, no stats row)
        // we log a warning and skip — the row will be created by the game on next season start.
        if ($statsRowMissing) {
            logWarn('PRESTIGE', 'awardPrestigePoints: statistiques row missing and no awards to grant — idempotency stamp not persisted');
        } else {
            dbExecute($base, 'UPDATE statistiques SET prestige_awarded_season = ?', 'i', $currentSeason);
        }
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

        // PRES-P7-003: GREATEST(0, ...) provides a DB-level floor against negative PP,
        // complementing the application-level WHERE total_pp >= ? guard above.
        $affected = dbExecute($base, 'UPDATE prestige SET unlocks=?, total_pp = GREATEST(0, total_pp - ?) WHERE login=? AND total_pp >= ?', 'sisi', $newUnlocks, $unlock['cost'], $login, $unlock['cost']);

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
