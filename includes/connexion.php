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
// INFRA-DB-HIGH-001: Catch all uncaught mysqli exceptions to prevent schema info leaking.
// database.php's if(!$stmt) guards are dead code under STRICT mode — exceptions always fire first.
set_exception_handler(function(\Throwable $e) {
    if ($e instanceof \mysqli_sql_exception) {
        error_log('DB Exception [' . $e->getCode() . ']: ' . $e->getMessage());
        http_response_code(500);
        die('<h1>Erreur interne</h1><p>Une erreur de base de données s\'est produite. Veuillez réessayer.</p>');
    }
    // Re-throw non-DB exceptions
    throw $e;
});
$base = mysqli_connect($db_host, $db_user, $db_pass, $db_name);

if (!$base) {
    // ERR-P7-003: Return proper HTTP 503 so load balancers and monitors detect the outage
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    die('<h1>Service temporairement indisponible</h1><p>Connexion à la base de données impossible. Veuillez réessayer dans quelques instants.</p>');
}

mysqli_set_charset($base, 'latin1');

require_once(__DIR__ . '/database.php');
