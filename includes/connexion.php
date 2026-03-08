<?php
require_once(__DIR__ . '/env.php');
loadEnv(__DIR__ . '/../.env');

$db_host = getenv('DB_HOST') ?: 'localhost';
$db_user = getenv('DB_USER');
$db_pass = getenv('DB_PASS');
$db_name = getenv('DB_NAME');
if (!$db_user || $db_name === false) {
    error_log('TVLW: DB credentials not loaded from .env — check file exists and is readable');
    die('Configuration error. Contact administrator.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$base = mysqli_connect($db_host, $db_user, $db_pass, $db_name);

if (!$base) {
    // ERR-P7-003: Return proper HTTP 503 so load balancers and monitors detect the outage
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    die('<h1>Service temporairement indisponible</h1><p>Connexion à la base de données impossible. Veuillez réessayer dans quelques instants.</p>');
}

mysqli_set_charset($base, 'latin1');

require_once(__DIR__ . '/database.php');
