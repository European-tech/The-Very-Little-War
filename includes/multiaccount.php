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
 * Normalize and hash an IP address for storage/comparison.
 * IPv4 and IPv6 addresses are canonical-normalized first, then HMAC-SHA256 hashed.
 * Uses SECRET_SALT if defined, otherwise falls back to 'tvlw_salt'.
 */
function hashIpAddress($ip) {
    $packed = @inet_pton($ip);
    $canonicalIp = ($packed !== false) ? inet_ntop($packed) : $ip;
    $salt = defined('SECRET_SALT') ? SECRET_SALT : 'tvlw_salt';
    return hash_hmac('sha256', $canonicalIp, $salt);
}

/**
 * Log a login/register event and run detection checks.
 *
 * NOTE: Detection runs synchronously on login. For high-traffic servers, consider
 * deferring to an async job queue or caching result in session for the day.
 * Current latency impact is acceptable at current player count (Pass 7 LOW-035).
 */
function logLoginEvent($base, $login, $eventType = 'login')
{
    // Direct connection assumed (TRUSTED_PROXY_IPS is empty in config.php).
    // If TRUSTED_PROXY_IPS is ever populated, update this line to extract real client IP from X-Forwarded-For.
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    // P9-HIGH-007: Hash IP before storage for GDPR compliance
    $hashedIp = hashIpAddress($ip);
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $fingerprint = hash('sha256', $ua . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    $timestamp = time();

    // MEDIUM-018: Store only browser family instead of full User-Agent (GDPR compliance)
    $browserFamily = 'Unknown';
    if (strpos($ua, 'Firefox/') !== false) $browserFamily = 'Firefox';
    elseif (strpos($ua, 'Chrome/') !== false) $browserFamily = 'Chrome';
    elseif (strpos($ua, 'Safari/') !== false) $browserFamily = 'Safari';
    elseif (strpos($ua, 'Edge/') !== false) $browserFamily = 'Edge';
    elseif (strpos($ua, 'MSIE') !== false || strpos($ua, 'Trident/') !== false) $browserFamily = 'IE';
    $storedUa = $browserFamily;

    dbExecute($base,
        'INSERT INTO login_history (login, ip, user_agent, fingerprint, timestamp, event_type) VALUES (?, ?, ?, ?, ?, ?)',
        'ssssis', $login, $hashedIp, $storedUa, $fingerprint, $timestamp, $eventType
    );

    checkSameIpAccounts($base, $login, $hashedIp, $timestamp);
    checkSameFingerprintAccounts($base, $login, $fingerprint, $timestamp);
    checkTimingCorrelation($base, $login, $timestamp);

    // P9-MED-022: Probabilistic GC — purge login_history older than 30 days (1-in-200 chance per login)
    if (mt_rand(1, 200) === 1) {
        dbExecute($base, 'DELETE FROM login_history WHERE timestamp < ?', 'i', time() - 30 * SECONDS_PER_DAY);
    }
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
        // P9-MED-021: Check both orderings to prevent duplicate flags for A→B and B→A pairs
        $existing = dbFetchOne($base,
            'SELECT id FROM account_flags WHERE status != ? AND flag_type = ?
             AND ((login = ? AND related_login = ?) OR (login = ? AND related_login = ?))',
            'ssssss', 'dismissed', 'same_ip', $login, $other['login'], $other['login'], $login
        );
        if (!$existing) {
            // $ip is already an HMAC-SHA256 hash (from hashIpAddress); use first 12 chars as display token
            $ipDisplay = substr($ip, 0, 12);
            $evidence = json_encode([
                'shared_ip' => $ipDisplay,
                'detection_time' => $timestamp,
                'login_a' => $login,
                'login_b' => $other['login']
            ]);
            dbExecute($base,
                'INSERT INTO account_flags (login, flag_type, related_login, evidence, severity, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                'sssssi', $login, 'same_ip', $other['login'], $evidence, 'medium', $timestamp
            );
            createAdminAlert($base, 'same_ip',
                "Comptes sur la même IP: $login et {$other['login']} ($ipDisplay)",
                $evidence, 'warning'
            );
            // P9-HIGH-008: Use consistent salt via hashIpAddress; $ip is already the hash
            logInfo('MULTIACCOUNT', 'Same IP detected', ['login_a' => $login, 'login_b' => $other['login'], 'ip_hash' => $ipDisplay]);
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
        // MEDIUM-025: Symmetric dedup — check both orderings (A→B and B→A) to avoid duplicate flags
        $existing = dbFetchOne($base,
            'SELECT id FROM account_flags WHERE ((login = ? AND related_login = ?) OR (login = ? AND related_login = ?)) AND flag_type = ? AND status != ?',
            'ssssss', $login, $other['login'], $other['login'], $login, 'same_fingerprint', 'dismissed'
        );
        if (!$existing) {
            $evidence = json_encode([
                'shared_fingerprint' => substr($fingerprint, 0, 12),
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
            // MEDIUM-034: Symmetric dedup — check both orderings to avoid duplicate flags
            $existing = dbFetchOne($base,
                'SELECT id FROM account_flags WHERE ((login = ? AND related_login = ?) OR (login = ? AND related_login = ?)) AND flag_type = ? AND status != ?',
                'ssssss', $attacker, $other['attaquant'], $other['attaquant'], $attacker, 'coord_attack', 'dismissed'
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
                    'sssssi', $attacker, 'coord_attack', $other['attaquant'], $evidence, 'critical', $timestamp
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
        // P9-MED-023: Widen simultaneous window to ±15 min (was ±5 min) to reduce false positives
        $overlap = dbFetchOne($base,
            'SELECT COUNT(*) AS cnt FROM login_history a WHERE a.login = ? AND a.timestamp > ?
             AND EXISTS (SELECT 1 FROM login_history b WHERE b.login = ? AND b.timestamp > ?
                         AND b.timestamp BETWEEN a.timestamp - 900 AND a.timestamp + 900)',
            'sisi', $login, $cutoff, $other, $cutoff
        );

        $aLogins = dbFetchOne($base, 'SELECT COUNT(*) AS cnt FROM login_history WHERE login = ? AND timestamp > ?', 'si', $login, $cutoff);
        $bLogins = dbFetchOne($base, 'SELECT COUNT(*) AS cnt FROM login_history WHERE login = ? AND timestamp > ?', 'si', $other, $cutoff);

        // P9-MED-023: Raise minimum login count threshold to 20 (was 10) to reduce false positives
        if ($aLogins && $bLogins && $overlap && $aLogins['cnt'] > 20 && $bLogins['cnt'] > 20 && $overlap['cnt'] == 0) {
            // P9-MED-023: Exclude dismissed flags from dedup check so they can be re-opened
            $existing = dbFetchOne($base,
                'SELECT id FROM account_flags WHERE login = ? AND related_login = ? AND flag_type = ? AND status != ?',
                'ssss', $login, $other, 'timing_correlation', 'dismissed'
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
function createAdminAlert($base, $alertType, $message, $details, $severity = 'warning')
{
    // MEDIUM-019: Deduplicate alerts — skip if same type alerted within last 24 hours
    $existing = dbCount($base,
        'SELECT COUNT(*) FROM admin_alerts WHERE alert_type = ? AND created_at > NOW() - INTERVAL 24 HOUR',
        's', $alertType);
    if ($existing > 0) return; // Already alerted within 24 hours

    dbExecute($base,
        'INSERT INTO admin_alerts (alert_type, message, details, severity, created_at) VALUES (?, ?, ?, ?, ?)',
        'ssssi', $alertType, $message, $details, $severity, time()
    );

    if ($severity === 'critical') {
        sendAdminAlertEmail("[TVLW] $alertType", $message);
    }
}

/**
 * Send email notification to admin for critical alerts.
 */
function sendAdminAlertEmail($subject, $body)
{
    $adminEmail = getenv('ADMIN_ALERT_EMAIL') ?: 'theverylittlewar@gmail.com';
    $subject = str_replace(["\r", "\n"], '', $subject);
    $headers = "From: noreply@theverylittlewar.com\r\nContent-Type: text/plain; charset=UTF-8";
    $sent = mail($adminEmail, $subject, $body, $headers);
    if (!$sent) {
        logWarn('MULTI_ALERT', 'Admin alert email failed to send', ['subject' => $subject]);
    }
}
