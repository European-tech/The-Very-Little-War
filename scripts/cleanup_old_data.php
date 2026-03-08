<?php
/**
 * scripts/cleanup_old_data.php
 *
 * Periodic cleanup script for stale / expired data.
 * Originally the cleanup logic lived inline in migration 0013_myisam_to_innodb_and_charset.sql.
 * LOW-044: Moved here so it can be run by cron on a recurring schedule.
 *
 * Suggested crontab entry (hourly):
 *   0 * * * * php /var/www/html/scripts/cleanup_old_data.php >> /var/www/html/logs/cleanup.log 2>&1
 *
 * Usage:
 *   php scripts/cleanup_old_data.php
 *
 * Exit codes:
 *   0 — success
 *   1 — fatal error (DB connection failed, etc.)
 */

// -------------------------------------------------------
// Safety: CLI only — never run this via HTTP
// -------------------------------------------------------
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

// -------------------------------------------------------
// Bootstrap — load DB connection and config
// -------------------------------------------------------
define('TVLW_ROOT', dirname(__DIR__));

require_once TVLW_ROOT . '/includes/config.php';
require_once TVLW_ROOT . '/includes/connexion.php';
require_once TVLW_ROOT . '/includes/database.php';

if (!isset($base) || !$base) {
    fwrite(STDERR, "[cleanup] FATAL: DB connection not available.\n");
    exit(1);
}

$now = time();
$startTs = date('Y-m-d H:i:s', $now);
echo "[cleanup] Started at {$startTs}\n";

$totalDeleted = 0;

// -------------------------------------------------------
// 1. Expired attack cooldowns
//    (origin: migration 0013, column 'expires' is a unix timestamp)
// -------------------------------------------------------
// LOW-008: Use prepared statement to avoid any risk of interpolation
$stmt = $base->prepare("DELETE FROM attack_cooldowns WHERE expires < ?");
if ($stmt) {
    $stmt->bind_param('i', $now);
    $stmt->execute();
    $rows = $stmt->affected_rows;
    $stmt->close();
    echo "[cleanup] attack_cooldowns: removed {$rows} expired row(s).\n";
    $totalDeleted += $rows;
} else {
    fwrite(STDERR, "[cleanup] ERROR preparing attack_cooldowns cleanup: " . $base->error . "\n");
}

// -------------------------------------------------------
// 2. Stale connectes entries (players last seen > 30 minutes ago)
//    connectes tracks currently-online players; stale rows accumulate
//    when sessions end without an explicit logout.
// -------------------------------------------------------
$staleThreshold = $now - (30 * 60); // 30 minutes
$stmt = $base->prepare("DELETE FROM connectes WHERE timestamp < ?");
if ($stmt) {
    $stmt->bind_param('i', $staleThreshold);
    $stmt->execute();
    $rows = $stmt->affected_rows;
    $stmt->close();
    echo "[cleanup] connectes: removed {$rows} stale row(s).\n";
    $totalDeleted += $rows;
} else {
    fwrite(STDERR, "[cleanup] ERROR preparing connectes cleanup: " . $base->error . "\n");
}

// -------------------------------------------------------
// 3. Expired compound buffs
//    compounds table stores timed buffs; rows with expires_at in the
//    past are no longer active and can be pruned.
// -------------------------------------------------------
if ($base->query("SHOW TABLES LIKE 'player_compounds'")->num_rows > 0) {
    $stmt = $base->prepare("DELETE FROM player_compounds WHERE expires_at IS NOT NULL AND expires_at < ?");
    if ($stmt) {
        $stmt->bind_param('i', $now);
        $stmt->execute();
        $rows = $stmt->affected_rows;
        $stmt->close();
        echo "[cleanup] player_compounds: removed {$rows} expired row(s).\n";
        $totalDeleted += $rows;
    } else {
        fwrite(STDERR, "[cleanup] ERROR preparing player_compounds cleanup: " . $base->error . "\n");
    }
} else {
    echo "[cleanup] player_compounds: table not found, skipping.\n";
}

// -------------------------------------------------------
// 4. Old rate-limiter flat files (data/rates/)
//    Files older than RATE_LIMIT_REGISTER_WINDOW seconds are safe to delete.
// -------------------------------------------------------
$ratesDir = TVLW_ROOT . '/data/rates';
if (is_dir($ratesDir)) {
    $window = defined('RATE_LIMIT_REGISTER_WINDOW') ? RATE_LIMIT_REGISTER_WINDOW : 3600;
    $cutoff = $now - $window;
    $fileCount = 0;
    foreach (glob($ratesDir . '/*.json') as $file) {
        if (filemtime($file) < $cutoff) {
            if (@unlink($file)) {
                $fileCount++;
            }
        }
    }
    echo "[cleanup] data/rates: removed {$fileCount} stale rate-limit file(s).\n";
    $totalDeleted += $fileCount;
} else {
    echo "[cleanup] data/rates: directory not found, skipping.\n";
}

// -------------------------------------------------------
// Summary
// -------------------------------------------------------
$endTs = date('Y-m-d H:i:s');
echo "[cleanup] Finished at {$endTs}. Total items removed: {$totalDeleted}.\n";
exit(0);
