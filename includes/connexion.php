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

$base = mysqli_connect($db_host, $db_user, $db_pass, $db_name);

if (!$base) {
    die('Erreur de connexion à la base de données.');
}

mysqli_set_charset($base, 'latin1');

require_once(__DIR__ . '/database.php');
