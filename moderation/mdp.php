<?php
session_name('TVLW_MOD');
require_once(__DIR__ . '/../includes/session_init.php');
include_once(__DIR__ . '/../includes/constantesBase.php');
if (!isset($_SESSION['motdepasseadmin']) or $_SESSION['motdepasseadmin'] !== true) {
	header('Location: index.php');
	exit();
}
$currentIp = $_SERVER['REMOTE_ADDR'] ?? '';
if (!hash_equals($_SESSION['mod_ip'] ?? '', $currentIp)) {
	session_destroy();
	header('Location: index.php?expired=1');
	exit();
}
