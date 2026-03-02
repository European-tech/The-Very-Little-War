<?php
require_once(__DIR__ . '/env.php');
loadEnv(__DIR__ . '/../.env');

$db_host = getenv('DB_HOST') ?: 'localhost';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') ?: '';
$db_name = getenv('DB_NAME') ?: 'theveryl_theverylittlewar';

$base = mysqli_connect($db_host, $db_user, $db_pass, $db_name);

if (!$base) {
    die('Erreur de connexion à la base de données.');
}

mysqli_set_charset($base, 'utf8');

require_once(__DIR__ . '/database.php');
