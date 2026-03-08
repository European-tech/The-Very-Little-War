<?php
/**
 * Multi-account detection system.
 * Logs login events, analyzes patterns, flags suspicious accounts.
 *
 * Detection methods:
 * - Same IP: accounts sharing IPs within 30 days
 * - Same fingerprint: accounts with identical UA+accept-language hash
 * - Coordinated attacks: same-IP accounts attacking same target within 30min
 * - Transfer patterns: one-sided resource flows (5+ sends, near-zero reciprocity)
 * - Timing correlation: flagged accounts never online simultaneously
 */

require_once(__DIR__ . '/database.php');
require_once(__DIR__ . '/logger.php');

/**
 * Log a login/register event and run detection checks.
 */
function logLoginEvent($base, $login, $eventType = 'login')
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $fingerprint = hash('sha256', $ua . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    $timestamp = time();

    dbExecute($base,
        'INSERT INTO login_history (login, ip, user_agent, fingerprint, timestamp, event_type) VALUES (?, ?, ?, ?, ?, ?)',
        'ssssis', $login, $ip, substr($ua, 0, 512), $fingerprint, $timestamp, $eventType
    );

    checkSameIpAccounts($base, $login, $ip, $timestamp);
    checkSameFingerprintAccounts($base, $login, $fingerprint, $timestamp);
    checkTimingCorrelation($base, $login, $timestamp);
}

/**
 * Detect other accounts that logged in from this IP in the last 30 days.
 */
function checkSameIpAccounts($base, $login, $ip, $timestamp)
{
    $cutoff = $timestamp - (30 * 86400);
    $others = dbFetchAll($base,
        'SELECT DISTINCT login FROM login_history WHERE ip = ? AND login != ? AND timestamp > ?',
        'ssi', $ip, $login, $cutoff
    );

    foreach ($others as $other) {
        $existing = dbFetchOne($base,
            'SELECT id FROM account_flags WHERE login = ? AND related_login = ? AND flag_type = ? AND status != ?',
            'ssss', $login, $other['login'], 'same_ip', 'dismissed'
        );
        if (!$existing) {
            $evidence = json_encode([
                'shared_ip' => $ip,
                'detection_time' => $timestamp,
                'login_a' => $login,
                'login_b' => $other['login']
            ]);
            dbExecute($base,
                'INSERT INTO account_flags (login, flag_type, related_login, evidence, severity, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                'sssssi', $login, 'same_ip', $other['login'], $evidence, 'medium', $timestamp
            );
            createAdminAlert($base, 'same_ip',
                "Comptes sur la même IP: $login et {$other['login']} ($ip)",
                $evidence, 'warning'
            );
            logInfo('MULTIACCOUNT', 'Same IP detected', ['login_a' => $login, 'login_b' => $other['login'], 'ip_hash' => substr(hash('sha256', $ip . (defined('SECRET_SALT') ? SECRET_SALT : 'tvlw')), 0, 12)]);
        }
    }
}

/**
 * Detect other accounts with the same browser fingerprint in the last 30 days.
 */
function checkSameFingerprintAccounts($base, $login, $fingerprint, $timestamp)
{
    $cutoff = $timestamp - (30 * 86400);
    $others = dbFetchAll($base,
        'SELECT DISTINCT login FROM login_history WHERE fingerprint = ? AND login != ? AND timestamp > ?',
        'ssi', $fingerprint, $login, $cutoff
    );

    foreach ($others as $other) {
        $existing = dbFetchOne($base,
            'SELECT id FROM account_flags WHERE login = ? AND related_login = ? AND flag_type = ? AND status != ?',
            'ssss', $login, $other['login'], 'same_fingerprint', 'dismissed'
        );
        if (!$existing) {
            $evidence = json_encode([
                'shared_fingerprint' => $fingerprint,
                'detection_time' => $timestamp,
                'login_a' => $login,
                'login_b' => $other['login']
            ]);
            dbExecute($base,
                'INSERT INTO account_flags (login, flag_type, related_login, evidence, severity, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                'sssssi', $login, 'same_fingerprint', $other['login'], $evidence, 'high', $timestamp
            );
            createAdminAlert($base, 'same_fingerprint',
                "Même empreinte navigateur: $login et {$other['login']}",
                $evidence, 'warning'
            );
            logInfo('MULTIACCOUNT', 'Same fingerprint detected', ['login_a' => $login, 'login_b' => $other['login']]);
        }
    }
}

/**
 * Detect coordinated attacks: same-IP accounts attacking the same target within a time window.
 */
function checkCoordinatedAttacks($base, $attacker, $defender, $timestamp)
{
    $window = 1800; // 30 minutes
    $recentAttacks = dbFetchAll($base,
        'SELECT DISTINCT attaquant FROM actionsattaques WHERE defenseur = ? AND attaquant != ? AND tempsAttaque BETWEEN ? AND ?',
        'ssii', $defender, $attacker, $timestamp - $window, $timestamp + $window
    );

    foreach ($recentAttacks as $other) {
        // Check if these two accounts share IP
        $ipOverlap = dbFetchOne($base,
            'SELECT COUNT(*) AS cnt FROM login_history a INNER JOIN login_history b ON a.ip = b.ip WHERE a.login = ? AND b.login = ? AND a.timestamp > ?',
            'ssi', $attacker, $other['attaquant'], time() - (30 * 86400)
        );

        if ($ipOverlap && $ipOverlap['cnt'] > 0) {
            $existing = dbFetchOne($base,
                'SELECT id FROM account_flags WHERE login = ? AND related_login = ? AND flag_type = ? AND status != ?',
                'ssss', $attacker, $other['attaquant'], 'coord_attack', 'dismissed'
            );
            if (!$existing) {
                $evidence = json_encode([
                    'attacker_a' => $attacker,
                    'attacker_b' => $other['attaquant'],
                    'defender' => $defender,
                    'time_window' => $window,
                    'attack_time' => $timestamp,
                    'shared_ip' => true
                ]);
                dbExecute($base,
                    'INSERT INTO account_flags (login, flag_type, related_login, evidence, severity, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                    'sssssi', $attacker, 'coord_attack', $other['attaquant'], $evidence, 'critical', time()
                );
                createAdminAlert($base, 'coord_attack',
                    "ALERTE: Attaque coordonnée sur $defender par $attacker et {$other['attaquant']} (même IP)",
                    $evidence, 'critical'
                );
                logWarn('MULTIACCOUNT', 'Coordinated attack detected', [
                    'attacker_a' => $attacker, 'attacker_b' => $other['attaquant'], 'defender' => $defender
                ]);
            }
        }
    }
}

/**
 * Detect one-sided resource transfer patterns (5+ sends, near-zero reciprocity in 7 days).
 */
function checkTransferPatterns($base, $sender, $receiver, $timestamp)
{
    $cutoff = $timestamp - (7 * 86400);
    $transferCount = dbFetchOne($base,
        'SELECT COUNT(*) AS cnt FROM actionsenvoi WHERE envoyeur = ? AND receveur = ? AND tempsArrivee > ?',
        'ssi', $sender, $receiver, $cutoff
    );

    if ($transferCount && $transferCount['cnt'] >= 5) {
        $reverseCount = dbFetchOne($base,
            'SELECT COUNT(*) AS cnt FROM actionsenvoi WHERE envoyeur = ? AND receveur = ? AND tempsArrivee > ?',
            'ssi', $receiver, $sender, $cutoff
        );

        $ratio = $reverseCount ? $reverseCount['cnt'] : 0;
        if ($ratio < 2) {
            $existing = dbFetchOne($base,
                'SELECT id FROM account_flags WHERE login = ? AND related_login = ? AND flag_type = ? AND status != ? AND created_at > ?',
                'ssssi', $sender, $receiver, 'coord_transfer', 'dismissed', $cutoff
            );
            if (!$existing) {
                $evidence = json_encode([
                    'sender' => $sender,
                    'receiver' => $receiver,
                    'transfers_7d' => $transferCount['cnt'],
                    'reverse_transfers_7d' => $ratio,
                    'period_start' => $cutoff,
                    'period_end' => $timestamp
                ]);
                dbExecute($base,
                    'INSERT INTO account_flags (login, flag_type, related_login, evidence, severity, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                    'sssssi', $sender, 'coord_transfer', $receiver, $evidence, 'high', $timestamp
                );
                createAdminAlert($base, 'coord_transfer',
                    "Transferts suspects: $sender → $receiver ({$transferCount['cnt']}x en 7j, quasi aucun retour)",
                    $evidence, 'warning'
                );
                logWarn('MULTIACCOUNT', 'One-sided transfers detected', [
                    'sender' => $sender, 'receiver' => $receiver, 'count' => $transferCount['cnt']
                ]);
            }
        }
    }
}

/**
 * Detect accounts that are never online simultaneously despite high activity.
 * Only checks against already-flagged related accounts to avoid false positives.
 */
function checkTimingCorrelation($base, $login, $timestamp)
{
    $cutoff = $timestamp - (30 * 86400);
    $related = dbFetchAll($base,
        'SELECT DISTINCT related_login FROM account_flags WHERE login = ? AND status != ?',
        'ss', $login, 'dismissed'
    );

    foreach ($related as $rel) {
        $other = $rel['related_login'];
        // Check overlap: did both accounts have login events within 5 min of each other?
        $overlap = dbFetchOne($base,
            'SELECT COUNT(*) AS cnt FROM login_history a INNER JOIN login_history b ON ABS(a.timestamp - b.timestamp) < 300 WHERE a.login = ? AND b.login = ? AND a.timestamp > ? AND b.timestamp > ?',
            'ssii', $login, $other, $cutoff, $cutoff
        );

        $aLogins = dbFetchOne($base, 'SELECT COUNT(*) AS cnt FROM login_history WHERE login = ? AND timestamp > ?', 'si', $login, $cutoff);
        $bLogins = dbFetchOne($base, 'SELECT COUNT(*) AS cnt FROM login_history WHERE login = ? AND timestamp > ?', 'si', $other, $cutoff);

        if ($aLogins && $bLogins && $overlap && $aLogins['cnt'] > 10 && $bLogins['cnt'] > 10 && $overlap['cnt'] == 0) {
            $existing = dbFetchOne($base,
                'SELECT id FROM account_flags WHERE login = ? AND related_login = ? AND flag_type = ?',
                'sss', $login, $other, 'timing_correlation'
            );
            if (!$existing) {
                $evidence = json_encode([
                    'login_a' => $login,
                    'login_b' => $other,
                    'a_logins_30d' => $aLogins['cnt'],
                    'b_logins_30d' => $bLogins['cnt'],
                    'simultaneous_logins' => 0,
                    'analysis' => 'Never online at same time despite high activity'
                ]);
                dbExecute($base,
                    'INSERT INTO account_flags (login, flag_type, related_login, evidence, severity, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                    'sssssi', $login, 'timing_correlation', $other, $evidence, 'critical', $timestamp
                );
                createAdminAlert($base, 'timing_correlation',
                    "ALERTE: $login et $other jamais en ligne en même temps (multi-compte probable)",
                    $evidence, 'critical'
                );
                logWarn('MULTIACCOUNT', 'Timing correlation detected', ['login_a' => $login, 'login_b' => $other]);
            }
        }
    }
}

/**
 * Check if two accounts have an active HIGH/CRITICAL flag between them.
 */
function areFlaggedAccounts($base, $loginA, $loginB)
{
    $flag = dbFetchOne($base,
        'SELECT id FROM account_flags WHERE ((login = ? AND related_login = ?) OR (login = ? AND related_login = ?)) AND status IN (?, ?) AND severity IN (?, ?)',
        'ssssssss', $loginA, $loginB, $loginB, $loginA, 'open', 'investigating', 'high', 'critical'
    );
    return !empty($flag);
}

/**
 * Create an admin alert. Sends email for critical alerts.
 */
function createAdminAlert($base, $type, $message, $details, $severity = 'warning')
{
    dbExecute($base,
        'INSERT INTO admin_alerts (alert_type, message, details, severity, created_at) VALUES (?, ?, ?, ?, ?)',
        'ssssi', $type, $message, $details, $severity, time()
    );

    if ($severity === 'critical') {
        sendAdminAlertEmail("[TVLW] $type", $message);
    }
}

/**
 * Send email notification to admin for critical alerts.
 */
function sendAdminAlertEmail($subject, $body)
{
    $adminEmail = getenv('ADMIN_ALERT_EMAIL') ?: 'theverylittlewar@gmail.com';
    $headers = "From: noreply@theverylittlewar.com\r\nContent-Type: text/plain; charset=UTF-8";
    @mail($adminEmail, $subject, $body, $headers);
}
