<?php
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'theveryl_theverylittlewar';

$base = mysqli_connect($db_host, $db_user, $db_pass, $db_name);

if (!$base) {
    die('Erreur de connexion à la base de données.');
}

mysqli_set_charset($base, 'utf8');

require_once(__DIR__ . '/database.php');
