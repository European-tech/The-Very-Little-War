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
    session_destroy();
    header('Location: index.php?erreur=' . urlencode('Session expirée. Veuillez vous reconnecter.'));
    exit();
}

if (isset($_SESSION['login']) && isset($_SESSION['session_token'])) {
    $row = dbFetchOne($base, 'SELECT session_token FROM membre WHERE login = ?', 's', $_SESSION['login']);
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
        } elseif ($_SESSION['mod_ip'] !== $_SERVER['REMOTE_ADDR']) {
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
$posAct = dbFetchOne($base, 'SELECT x, y FROM membre WHERE login = ?', 's', $_SESSION['login']);
if ($posAct && $posAct['x'] == -1000) {
    $position = coordonneesAleatoires();
    dbExecute($base, 'UPDATE membre SET x = ?, y = ? WHERE login = ?', 'iis', $position['x'], $position['y'], $_SESSION['login']);
}
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
    $now = time();
    dbExecute($base, 'INSERT INTO connectes (ip, timestamp) VALUES (?, ?) ON DUPLICATE KEY UPDATE timestamp=VALUES(timestamp)', 'si', $_SERVER['REMOTE_ADDR'], $now);

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
        // NEW-003: Use FILTER_SANITIZE_URL to avoid PHP_SELF injection via path-info
        header('Location: ' . filter_var($_SERVER['PHP_SELF'], FILTER_SANITIZE_URL)); exit;
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
    }
}

// Daily login streak (P1-D8-041)
if (isset($_SESSION['login'])) {
    $streakResult = updateLoginStreak($base, $_SESSION['login']);
    // LOW-016: Persist streak PP earned today to session for prestige.php display.
    // updateLoginStreak returns pp_earned=0 if streak was already processed today
    // (guarded by streak_last_date == today check), so this is safe to overwrite
    // on every page load — it will be 0 except on the first load of each new day.
    $_SESSION['streak_pp_today'] = $streakResult['pp_earned'] ?? 0;
    if ($streakResult['milestone']) {
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
$debutRow = dbFetchOne($base, 'SELECT debut FROM statistiques');
$debut = $debutRow;

$maintenanceRow = dbFetchOne($base, 'SELECT maintenance FROM statistiques');
$maintenance = $maintenanceRow;

if ($maintenance['maintenance'] == 1 && (time() - $debut["debut"]) >= SEASON_MAINTENANCE_PAUSE_SECONDS) {
    // Phase 2: 24h have passed since maintenance was set, proceed with full reset.
    //
    // HIGH-016: Admin gate — only the administrator account (or a cron/CLI context
    // without a session) may trigger the actual performSeasonEnd() call during a live
    // page request. Regular players see the maintenance message instead.
    // This prevents a race where any authenticated player reaching this branch could
    // inadvertently (or maliciously) trigger the season reset.
    $isAdminRequest = (!isset($_SESSION['login']) || $_SESSION['login'] === ADMIN_LOGIN);
    if (!$isAdminRequest) {
        // Non-admin player: inform them that maintenance is in progress and skip the reset.
        $erreur = "Une nouvelle partie recommencera dans 24 heures.";
    } else {
    // AUTH-C-001: performSeasonEnd() manages its own advisory lock ('tvlw_season_reset')
    // internally with GET_LOCK/RELEASE_LOCK in a try/finally. The outer GET_LOCK here was
    // causing a double-acquisition on the same connection (MariaDB re-entrant lock), and
    // performSeasonEnd()'s finally released it prematurely, leaving the email queue below
    // unprotected. Remove the outer lock and rely entirely on performSeasonEnd()'s lock.
    $vainqueurManche = null;
    $seasonResetOk = false;
    try {
    // Full season-end flow: archive, VP, prestige, reset, news
    // Throws RuntimeException if lock not acquired (another reset in progress).
    $vainqueurManche = performSeasonEnd();
    $seasonResetOk = true;
    } catch (\Exception $e) {
    // RuntimeException = lock not acquired or reset failed
    logError('SEASON', 'performSeasonEnd() failed: ' . $e->getMessage());
    if (!$seasonResetOk) {
        logError('SEASON', 'Season reset failed — maintenance flag kept at 1 for admin review');
    }
    }

    // HIGH-017: Queue season reset emails instead of sending synchronously.
    // Sending to every player inline in a page request risks HTTP timeouts and
    // leaves the game in a degraded state if mail() is slow.
    // Emails are inserted into email_queue and sent by processEmailQueue() which
    // is called probabilistically (1% of requests) on subsequent page loads.
    // Guard: only queue emails when the reset actually succeeded and we have a winner.
    if ($seasonResetOk && $vainqueurManche !== null) {
    $mailRows = dbFetchAll($base, 'SELECT email, login FROM membre', '');
    $winnerName = $vainqueurManche ?? 'Personne';
    $resetDate  = date('d/m/Y à H\hi', time());
    foreach ($mailRows as $donnees) {
        $recipientEmail = $donnees['email'];
        $recipientLogin = $donnees['login'];

        $message_html = "<html><head></head><body>Bonjour " . htmlspecialchars($recipientLogin, ENT_QUOTES, 'UTF-8') . " ! <b>" . htmlspecialchars($winnerName, ENT_QUOTES, 'UTF-8') . "</b> vient de remporter la partie en cours le " . $resetDate . ". Les points de tous les joueurs vont être remis à zéro et"
            . " vous pourrez commencer à rejouer la nouvelle partie à partir du <b>" . $resetDate . "</b> ! Ne manquez pas cette occasion de prendre la tête du classement. Je vous souhaite donc bonne chance pour la suite"
            . " et à bientôt sur <a href=\"https://www.theverylittlewar.com\">The Very Little War</a> !<br/><br/><br/><br/>"
            . "<i>Si vous ne souhaitez plus recevoir ce genre de mail il suffit de changer votre adresse e-mail sur <a href=\"https://www.theverylittlewar.com\">www.theverylittlewar.com</a> dans la partie \"Mon compte\".</i></body></html>";

        $sujet = "=?UTF-8?B?" . base64_encode("Début d'une nouvelle partie") . "?=";

        dbExecute($base,
            'INSERT INTO email_queue (recipient_email, subject, body_html, created_at) VALUES (?, ?, ?, ?)',
            'sssi', $recipientEmail, $sujet, $message_html, time()
        );
    }
    if (!empty($mailRows)) {
        logInfo('SEASON', 'Season reset emails queued', ['count' => count($mailRows)]);
    }
    } // end if ($seasonResetOk && $vainqueurManche !== null)

    } // end admin gate else block

} elseif (date('n', time()) != date('n', $debut["debut"]) && $maintenance['maintenance'] == 0) {
    // Phase 1: New month detected, enable maintenance and start 24h countdown.
    // MED-012: Use an advisory lock so only one concurrent request sets maintenance=1.
    // GET_LOCK(..., 0) returns 1 if the lock was acquired, 0 if already held by another
    // connection, NULL on error. If we cannot acquire it, another request beat us here —
    // skip the UPDATE and fall through to the maintenance message shown below.
    $phase1Lock = dbFetchOne($base, "SELECT GET_LOCK('tvlw_season_phase1', 0) as locked", '');
    if ($phase1Lock && $phase1Lock['locked'] == 1) {
        // Double-check maintenance is still 0 now that we hold the lock (avoid re-entry)
        $maintenanceRecheck = dbFetchOne($base, 'SELECT maintenance FROM statistiques');
        if ($maintenanceRecheck && $maintenanceRecheck['maintenance'] == 0) {
            dbExecute($base, 'UPDATE statistiques SET maintenance = 1');
            $now = time();
            dbExecute($base, 'UPDATE statistiques SET debut = ?', 'i', $now);
            logInfo('SEASON', 'Phase 1 maintenance triggered', ['login' => $_SESSION['login'] ?? 'unknown']);
        }
        dbFetchOne($base, "SELECT RELEASE_LOCK('tvlw_season_phase1')");
    }
    $erreur = "Une nouvelle partie recommencera dans 24 heures.";
    // MED-013: Block ALL requests (GET and POST) for non-admin players during maintenance
    if (!isset($_SESSION['login']) || $_SESSION['login'] !== ADMIN_LOGIN) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Le jeu est en maintenance']);
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
} elseif ($maintenance['maintenance'] == 1 && (time() - $debut["debut"]) < SEASON_MAINTENANCE_PAUSE_SECONDS) {
    // Still in maintenance period, 24h have not yet passed
    // MED-013: Block ALL requests (GET and POST) for non-admin players during maintenance
    if (!isset($_SESSION['login']) || $_SESSION['login'] !== ADMIN_LOGIN) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Le jeu est en maintenance']);
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

// HIGH-017: Probabilistic email queue drain (1% of requests).
// Keeps the queue draining without dedicating a cron job or blocking any single request.
if (mt_rand(1, 100) === 1) {
    processEmailQueue($base);
}
