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
// HIGH-019: Validate admin session is bound to the IP that authenticated.
// Admin sessions have no membre DB row, so IP binding is the equivalent guard
// against session token theft / fixation attacks.
if (isset($_SESSION['admin_ip']) && !hash_equals((string)$_SESSION['admin_ip'], (string)($_SERVER['REMOTE_ADDR'] ?? ''))) {
	session_unset();
	session_destroy();
	header('Location: index.php');
	exit();
}
$_SESSION['admin_last_activity'] = time();
