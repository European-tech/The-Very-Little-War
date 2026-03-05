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
if (in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'], true)) {
    $response['db'] = $db_ok;
    $response['disk_free_gb'] = round(disk_free_space('/') / 1073741824, 1);
    $response['php'] = PHP_VERSION;
}

echo json_encode($response);
