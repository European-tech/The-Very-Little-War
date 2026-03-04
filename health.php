<?php
/**
 * Health check endpoint for uptime monitoring.
 * Returns JSON with DB status, disk space, PHP version.
 * Point UptimeRobot or similar at this URL.
 */
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$db_ok = false;

try {
    require_once __DIR__ . '/includes/connexion.php';
    $result = mysqli_query($base, 'SELECT 1');
    $db_ok = ($result !== false);
} catch (Exception $e) {
    $db_ok = false;
}

$status = $db_ok ? 'ok' : 'error';
http_response_code($db_ok ? 200 : 503);

echo json_encode([
    'status'       => $status,
    'db'           => $db_ok,
    'disk_free_gb' => round(disk_free_space('/') / 1073741824, 1),
    'php'          => PHP_VERSION,
    'ts'           => time(),
]);
