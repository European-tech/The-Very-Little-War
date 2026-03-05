<?php
session_name('TVLW_ADMIN');
require_once(__DIR__ . '/../includes/session_init.php');
include_once(__DIR__ . '/../includes/constantesBase.php');
if (!isset($_SESSION['motdepasseadmin']) or $_SESSION['motdepasseadmin'] !== true) {
	header('Location: index.php');
	exit();
}
// Admin idle timeout (same as player sessions)
if (isset($_SESSION['admin_last_activity']) && (time() - $_SESSION['admin_last_activity']) > SESSION_IDLE_TIMEOUT) {
	unset($_SESSION['motdepasseadmin']);
	header('Location: index.php');
	exit();
}
$_SESSION['admin_last_activity'] = time();
