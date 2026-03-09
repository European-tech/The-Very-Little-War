<?php
require_once(__DIR__ . '/session_init.php');
require_once(__DIR__ . '/csp.php');

include("includes/connexion.php");
include("includes/fonctions.php");
require_once(__DIR__ . '/csrf.php');
require_once(__DIR__ . '/validation.php');
require_once(__DIR__ . '/logger.php');

// LOW-008: Idle timeout check runs FIRST — before any game state reads, DB queries,
// or session regeneration — so expired sessions are rejected immediately.
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {
    // SEC-TOK-001: Null the DB session token on timeout so the old token cannot be
    // replayed by an attacker who obtained the session cookie before it expired.
    if (isset($_SESSION['login'])) {
        dbExecute($base, 'UPDATE membre SET session_token = NULL WHERE login = ?', 's', $_SESSION['login']);
    }
    session_destroy();
    header('Location: index.php?erreur=' . urlencode('Session expirée. Veuillez vous reconnecter.'));
    exit();
}

// SESSION-P10-001: Absolute session lifetime — force re-login after 24h regardless of activity.
if (!empty($_SESSION['session_created']) && (time() - (int)$_SESSION['session_created']) > SESSION_ABSOLUTE_TIMEOUT) {
    // SEC-TOK-001: Null the DB session token on absolute timeout for the same reason.
    if (isset($_SESSION['login'])) {
        dbExecute($base, 'UPDATE membre SET session_token = NULL WHERE login = ?', 's', $_SESSION['login']);
    }
    session_destroy();
    header('Location: index.php?erreur=' . urlencode('Session expirée (durée maximale dépassée). Veuillez vous reconnecter.'));
    exit();
}

if (isset($_SESSION['login']) && isset($_SESSION['session_token'])) {
    // H-007: Include AND estExclu = 0 so banned accounts cannot maintain valid sessions.
    // A banned player's session_token still matches in the DB, but this query now returns
    // no row for excluded players, forcing them through the same destroy+redirect path.
    $row = dbFetchOne($base, 'SELECT session_token FROM membre WHERE login = ? AND estExclu = 0', 's', $_SESSION['login']);
    if (!$row || !isset($_SESSION['session_token']) || !$row['session_token'] || !hash_equals($row['session_token'], $_SESSION['session_token'])) {
        session_destroy();
        header('Location: index.php');
        exit();
    }

    // Regenerate session ID periodically (every 30 min) to limit session fixation window
    if (!isset($_SESSION['_last_regeneration']) || (time() - $_SESSION['_last_regeneration']) > SESSION_REGEN_INTERVAL) {
        session_regenerate_id(true);
        $_SESSION['_last_regeneration'] = time();
    }

    // MEDIUM-010: Moderator session IP binding
    // Bind the moderator's IP at first access; invalidate session on IP change.
    $modRow = dbFetchOne($base, 'SELECT moderateur FROM membre WHERE login = ?', 's', $_SESSION['login']);
    if ($modRow && $modRow['moderateur']) {
        if (!isset($_SESSION['mod_ip'])) {
            // First page load as moderator — record the IP
            $_SESSION['mod_ip'] = $_SERVER['REMOTE_ADDR'];
        } elseif (!hash_equals((string)($_SESSION['mod_ip'] ?? ''), (string)($_SERVER['REMOTE_ADDR'] ?? ''))) { // SESSION-P10-002: timing-safe comparison
            // IP changed — destroy session and redirect for security
            session_destroy();
            header('Location: index.php?erreur=' . urlencode('Session invalide. Veuillez vous reconnecter.'));
            exit();
        }
    }
} else {
    session_destroy();
    header('Location: index.php');
    exit();
}

$_SESSION['last_activity'] = time();

// si c'est la premiere connexion depuis la derniere partie, on le replace
// MAPS-HIGH-001: Wrap the -1000 check + coordinate assign + UPDATE in a transaction
// with FOR UPDATE so two concurrent logins for the same player cannot both assign
// coordinates (TOCTOU race between SELECT x=-1000 and UPDATE membre SET x,y).
$sessionLogin = $_SESSION['login'];
withTransaction($base, function() use ($base, $sessionLogin) {
    $posAct = dbFetchOne($base, 'SELECT x, y FROM membre WHERE login = ? FOR UPDATE', 's', $sessionLogin);
    if ($posAct && $posAct['x'] == -1000) {
        $position = coordonneesAleatoires();
        dbExecute($base, 'UPDATE membre SET x = ?, y = ? WHERE login = ?', 'iis', $position['x'], $position['y'], $sessionLogin);
    }
});
require_once(__DIR__ . '/constantesBase.php');
initPlayer($_SESSION['login']);

/////////////////////////////////////////////////////


if (isset($_GET['information'])) {
    $information = antiXSS($_GET['information']);
}

if (isset($_GET['erreur'])) {
    $erreur = antiXSS($_GET['erreur']);
}
//////////////////////////////////////////////////////////// Gestion des connectés
// Throttle: only update once per 60 seconds to reduce DB writes
if (!isset($_SESSION['last_online_update']) || time() - $_SESSION['last_online_update'] > ONLINE_UPDATE_THROTTLE_SECONDS) {
    // LOW-003: ON DUPLICATE KEY UPDATE avoids SELECT+INSERT/UPDATE race condition
    // LOW-002: Hash IP address before storing to avoid plaintext PII in connectes table.
    require_once(__DIR__ . '/multiaccount.php');
    $now = time();
    $hashedIpForConnectes = hashIpAddress($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    dbExecute($base, 'INSERT INTO connectes (ip, timestamp) VALUES (?, ?) ON DUPLICATE KEY UPDATE timestamp=VALUES(timestamp)', 'si', $hashedIpForConnectes, $now);

    $_SESSION['last_online_update'] = time();
}

// Ajout de Yojim
// On vérifie si le joueur connecté est en vacance
$joueurEnVac = dbFetchOne($base, 'SELECT vacance FROM membre WHERE login = ?', 's', $_SESSION['login']);

// Central vacation mode check — restrict access to safe pages only while on vacation
// Pages that do not modify game state are permitted; all others redirect to index
if (isset($joueurEnVac['vacance']) && $joueurEnVac['vacance'] == 1) {
    $vacationAllowedPages = [
        'compte.php', 'regles.php', 'prestige.php', 'maintenance.php',
        'deconnexion.php', 'bilan.php', 'classement.php',
        'index.php', 'joueur.php', 'saison.php',
        'alliance_discovery.php', 'season_recap.php',
    ];
    $currentPage = basename($_SERVER['PHP_SELF']);
    if (!in_array($currentPage, $vacationAllowedPages)) {
        header('Location: index.php?msg=vacation');
        exit;
    }
}

// Capture derniereConnexion BEFORE overwriting it (needed for comeback bonus)
$prevConnRow = dbFetchOne($base, 'SELECT derniereConnexion FROM membre WHERE login = ?', 's', $_SESSION['login']);
$prevConnexion = $prevConnRow ? (int)$prevConnRow['derniereConnexion'] : 0;

// Si le joueur n'est pas en vacance on fait la mise a jour des ressources ...
if (!$joueurEnVac['vacance']) {
    updateRessources($_SESSION['login']); // mise a jour
    $now = time();
    dbExecute($base, 'UPDATE membre SET derniereConnexion = ? WHERE login = ?', 'is', $now, $_SESSION['login']);

    $donnees = dbFetchOne($base, 'SELECT tempsPrecedent FROM autre WHERE login = ?', 's', $_SESSION['login']);
    updateActions($_SESSION['login']);
    // Refresh player data after resource/action updates (invalidate cache first)
    unset($GLOBALS['_initPlayerCache'][$_SESSION['login']]);
    initPlayer($_SESSION['login']);
}
// Ajout par Yojim
// Si le joueur est encore en mode vacances
else {
    // On récupère la date de fin du mode vacances
    $vac = dbFetchOne($base, 'SELECT dateFin FROM vacances WHERE idJoueur IN (SELECT id FROM membre WHERE login = ?)', 's', $_SESSION['login']);
    if (!$vac) {
        // Orphaned vacance=1 with no vacances row — clear the flag
        dbExecute($base, 'UPDATE membre SET vacance = 0 WHERE login = ?', 's', $_SESSION['login']);
        // INFRA-TEMPLATES-M1: Validate against known-safe pages before redirecting.
        // basename() alone does not prevent CRLF header injection via crafted URL path-info.
        // Only redirect to a page in the allowlist; fall back to index.php for unknown pages.
        $unsafePage = basename($_SERVER['PHP_SELF']);
        $vacationSafeRedirectPages = [
            'index.php', 'compte.php', 'regles.php', 'prestige.php', 'maintenance.php',
            'deconnexion.php', 'bilan.php', 'classement.php', 'joueur.php', 'saison.php',
            'alliance_discovery.php', 'season_recap.php', 'attaquer.php', 'alliance.php',
            'constructions.php', 'laboratoire.php', 'marche.php', 'carte.php', 'forum.php',
            'sondage.php', 'voter.php', 'troupes.php', 'espionner.php',
        ];
        $safePage = in_array($unsafePage, $vacationSafeRedirectPages, true) ? $unsafePage : 'index.php';
        header('Location: ' . $safePage); exit;
    }
    // On calcul la différence entre la date de fin et la date actuelle
    $diff = dbFetchOne($base, 'SELECT DATEDIFF(CURDATE(), ?) AS d', 's', $vac['dateFin']);
    $now = time();
    dbExecute($base, 'UPDATE membre SET derniereConnexion = ? WHERE login = ?', 'is', $now, $_SESSION['login']);
    // Si la date de fin du mode vacances est passee, on enleve le mode vacances
    if ($diff['d'] >= 0) {
        // Mise à jour du champ vacances
        dbExecute($base, 'UPDATE membre SET vacance = 0 WHERE login = ?', 's', $_SESSION['login']);
        // Supression du tuple de vacances
        dbExecute($base, 'DELETE FROM vacances WHERE idJoueur IN (SELECT id FROM membre WHERE login = ?)', 's', $_SESSION['login']);
        $now = time();
        dbExecute($base, 'UPDATE autre SET tempsPrecedent = ? WHERE login = ?', 'is', $now, $_SESSION['login']);
        // GAME_CORE-MEDIUM: Invalidate initPlayer cache so vacation-exit is reflected immediately
        unset($GLOBALS['_initPlayerCache'][$_SESSION['login']]);
    }
}

// Daily login streak (P1-D8-041)
if (isset($_SESSION['login'])) {
    $streakResult = updateLoginStreak($base, $_SESSION['login']);
    // PRES-P7-002: Only overwrite streak_pp_today when PP was actually earned (first load of
    // the calendar day). On subsequent page loads updateLoginStreak returns pp_earned=0 because
    // streak_last_date already equals today — we must NOT overwrite the session var in that case
    // or the banner disappears after the very first navigation away from the earning page.
    // Store alongside today's date so the value auto-expires at midnight even in a long session.
    $streakTz = new DateTimeZone('Europe/Paris');
    $streakToday = (new DateTime('now', $streakTz))->format('Y-m-d');
    $ppEarned = $streakResult['pp_earned'] ?? 0;
    if ($ppEarned > 0) {
        // Fresh PP earned — store value and stamp the date
        $_SESSION['streak_pp_today'] = $ppEarned;
        $_SESSION['streak_pp_date']  = $streakToday;
    } elseif (!isset($_SESSION['streak_pp_date']) || $_SESSION['streak_pp_date'] !== $streakToday) {
        // New calendar day with no PP (non-milestone streak or first-ever login on this day)
        // — reset so stale yesterday's value is not shown
        $_SESSION['streak_pp_today'] = 0;
        $_SESSION['streak_pp_date']  = $streakToday;
    }
    // else: same day, no new PP — leave existing session value intact
    if (!empty($streakResult['milestone'])) {
        $_SESSION['streak_milestone'] = $streakResult;
    }
}

// Welcome-back bonus (P1-D8-044/047) — checked once per session to avoid per-page overhead
if (!isset($_SESSION['comeback_checked'])) {
    $comebackResult = checkComebackBonus($base, $_SESSION['login'], $prevConnexion);
    if ($comebackResult['applied']) {
        $_SESSION['comeback_bonus'] = $comebackResult;
    }
    $_SESSION['comeback_checked'] = true;
}

// Unread attack count for navbar badge (P1-D8-049)
$recentAttackCount = dbCount($base,
    'SELECT COUNT(*) AS cnt FROM rapports WHERE destinataire = ? AND statut = 0 AND image LIKE ?',
    'ss', $_SESSION['login'], '%sword.png%');
if ($recentAttackCount > 0) {
    $_SESSION['unread_attacks'] = $recentAttackCount;
} else {
    unset($_SESSION['unread_attacks']);
}

//////////////////////////////////////////////////////////// Gestion des ressources
//Vérification si nouveau mois le lendemain
// FIX2-DEBUT: Also select maintenance_started_at (migration 0109) so Phase 2 trigger
// uses the maintenance trigger time, not the season start time (debut).
$debutRow = dbFetchOne($base, 'SELECT debut, maintenance_started_at FROM statistiques');
$debut = $debutRow;

$maintenanceRow = dbFetchOne($base, 'SELECT maintenance FROM statistiques');
$maintenance = $maintenanceRow;

// FIX2-DEBUT: Use maintenance_started_at for the 24h countdown, not debut.
// debut = real season start; maintenance_started_at = when Phase 1 fired.
$maintenanceStartedAt = (int)($debut['maintenance_started_at'] ?? 0);
if ($maintenance['maintenance'] == 1 && $maintenanceStartedAt > 0 && (time() - $maintenanceStartedAt) >= SEASON_MAINTENANCE_PAUSE_SECONDS) {
    // Phase 2: 24h have passed since maintenance was set, proceed with full reset.
    //
    // AUTH-P20-001: Season reset is triggered only by admin/index.php (TVLW_ADMIN session)
    // or a CLI cron job. All player requests during Phase 2 see the maintenance page.
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Le jeu est en maintenance'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        exit;
    }
    http_response_code(503);
    header('Retry-After: 3600');
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
        . '<title>Maintenance — The Very Little War</title>'
        . '<meta name="robots" content="noindex">'
        . '<style>body{font-family:sans-serif;text-align:center;padding:3em;background:#1a1a2e;color:#e0e0e0}'
        . 'h1{color:#f5a623}p{font-size:1.1em}</style></head><body>'
        . '<h1>Maintenance en cours</h1>'
        . '<p>Une nouvelle partie commencera dans les prochaines 24 heures.</p>'
        . '<p><a href="index.php" style="color:#f5a623;">Retour à l\'accueil</a></p>'
        . '</body></html>';
    exit;

} elseif (date('n', time()) != date('n', $debut["debut"]) && $maintenance['maintenance'] == 0) {
    // Phase 1: New month detected, enable maintenance and start 24h countdown.
    //
    // MIN_SEASON_DAYS guard: if the season started near end-of-month (e.g. Jan 29),
    // the month number changes after only a few days (Feb 1). We must have run for at
    // least MIN_SEASON_DAYS before allowing the trigger.
    $seasonStart = (int)($debut['debut'] ?? 0);
    $minSeasonSeconds = MIN_SEASON_DAYS * SECONDS_PER_DAY;
    if ($seasonStart > 0 && (time() - $seasonStart) < $minSeasonSeconds) {
        // Season is too young — suppress phase-1 trigger and continue normally.
        goto season_check_done;
    }
    //
    // MED-012: Use an advisory lock so only one concurrent request sets maintenance=1.
    // GET_LOCK(..., 0) returns 1 if the lock was acquired, 0 if already held by another
    // connection, NULL on error. If we cannot acquire it, another request beat us here —
    // skip the UPDATE and fall through to the maintenance message shown below.
    $phase1Lock = dbFetchOne($base, "SELECT GET_LOCK('tvlw_season_reset', 0) as locked", '');
    if ($phase1Lock && $phase1Lock['locked'] == 1) {
        // Double-check maintenance is still 0 now that we hold the lock (avoid re-entry)
        $maintenanceRecheck = dbFetchOne($base, 'SELECT maintenance FROM statistiques');
        if ($maintenanceRecheck && $maintenanceRecheck['maintenance'] == 0) {
            dbExecute($base, 'UPDATE statistiques SET maintenance = 1');
            $now = time();
            // FIX2-DEBUT: Write maintenance trigger time to maintenance_started_at (migration 0109),
            // NOT to debut. The debut column must remain the real season start timestamp so that
            // season-duration calculations (prestige final-week check, MIN_SEASON_DAYS guard) are
            // not corrupted by the maintenance trigger time.
            dbExecute($base, 'UPDATE statistiques SET maintenance_started_at = ?', 'i', $now);
            logInfo('SEASON', 'Phase 1 maintenance triggered', ['login' => $_SESSION['login'] ?? 'unknown']);
        }
        dbFetchOne($base, "SELECT RELEASE_LOCK('tvlw_season_reset')");
    }
    $erreur = "Une nouvelle partie recommencera dans 24 heures.";
    // MED-013: Block ALL requests (GET and POST) for non-admin players during maintenance
    if (!isset($_SESSION['login']) || $_SESSION['login'] !== ADMIN_LOGIN) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Le jeu est en maintenance'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
            exit;
        }
        // SR-001: Block GET requests too — return 503 so uptime monitors and CDNs detect
        // maintenance, and game-state-mutating code further down does not execute.
        http_response_code(503);
        header('Retry-After: 3600');
        echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
            . '<title>Maintenance — The Very Little War</title>'
            . '<meta name="robots" content="noindex">'
            . '</head><body style="font-family:sans-serif;text-align:center;padding:60px">'
            . '<h1>Maintenance en cours</h1>'
            . '<p>Une nouvelle partie commencera dans les prochaines 24 heures.</p>'
            . '</body></html>';
        exit;
    }
} elseif ($maintenance['maintenance'] == 1 && ($maintenanceStartedAt === 0 || (time() - $maintenanceStartedAt) < SEASON_MAINTENANCE_PAUSE_SECONDS)) {
    // Still in maintenance period, 24h have not yet passed
    // MED-013: Block ALL requests (GET and POST) for non-admin players during maintenance
    if (!isset($_SESSION['login']) || $_SESSION['login'] !== ADMIN_LOGIN) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Le jeu est en maintenance'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
            exit;
        }
        // For GET requests: output a self-contained maintenance page and halt.
        // Avoids loading the full game layout (which requires DB-initialised player data).
        http_response_code(503);
        header('Retry-After: 3600');
        echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
            . '<title>Maintenance — The Very Little War</title>'
            . '<meta name="robots" content="noindex">'
            . '<style>body{font-family:sans-serif;text-align:center;padding:3em;background:#1a1a2e;color:#e0e0e0}'
            . 'h1{color:#f5a623}p{font-size:1.1em}</style></head><body>'
            . '<h1>Maintenance en cours</h1>'
            . '<p>Une nouvelle partie recommencera dans moins de 24&nbsp;heures.</p>'
            . '<p><a href="index.php" style="color:#f5a623;">Retour à l\'accueil</a></p>'
            . '</body></html>';
        exit;
    }
}
season_check_done:

// HIGH-017: Probabilistic email queue drain (1-in-EMAIL_QUEUE_DRAIN_PROB_DENOM requests).
// Keeps the queue draining without dedicating a cron job or blocking any single request.
// MEDIUM-024: denominator is now a config constant (default 100 = 1%).
if (mt_rand(1, EMAIL_QUEUE_DRAIN_PROB_DENOM) === 1) {
    processEmailQueue($base);
}
