<?php
/**
 * Health check endpoint for uptime monitoring.
 * Returns JSON with DB status, disk space, PHP version.
 * Point UptimeRobot or similar at this URL.
 */
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Content-Type-Options: nosniff');

$db_ok = false;

try {
    require_once __DIR__ . '/includes/env.php';
    loadEnv(__DIR__ . '/.env');

    $db_host = getenv('DB_HOST') ?: 'localhost';
    $db_user = getenv('DB_USER');
    $db_pass = getenv('DB_PASS');
    $db_name = getenv('DB_NAME');

    if ($db_user && $db_name !== false) {
        $conn = @mysqli_connect($db_host, $db_user, $db_pass, $db_name);
        if ($conn) {
            $result = mysqli_query($conn, 'SELECT 1');
            $db_ok = ($result !== false);
            mysqli_close($conn);
        }
    }
} catch (\Throwable $e) {
    $db_ok = false;
}

$status = $db_ok ? 'ok' : 'error';
http_response_code($db_ok ? 200 : 503);

$response = [
    'status' => $status,
    'ts'     => time(),
];

// Only expose detailed info to localhost
// LOW-029: PHP version and disk info are restricted to 127.0.0.1/::1 (low-risk).
// Ensure this endpoint is not reachable via a reverse proxy that rewrites REMOTE_ADDR.
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
// Normalize IPv6-mapped IPv4 addresses (e.g. ::ffff:127.0.0.1) to prevent bypass
if (strpos($remoteAddr, '::ffff:') === 0) {
    $remoteAddr = substr($remoteAddr, 7);
}
$isLocalhost = in_array($remoteAddr, ['127.0.0.1', '::1'], true);
if ($isLocalhost) {
    $response['db'] = $db_ok;
    $response['disk_free_gb'] = round(disk_free_space('/') / 1073741824, 1);
    // PHP version included only for localhost health checks (not accessible from internet).
    // Monitor: ensure this endpoint is not accessible via a proxy.
    $response['php'] = PHP_VERSION;
}

echo json_encode($response);
