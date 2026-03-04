<?php
session_name('TVLW_MOD');
require_once(__DIR__ . '/../includes/session_init.php');
include_once(__DIR__ . '/../includes/constantesBase.php');
if (!isset($_SESSION['motdepasseadmin']) or $_SESSION['motdepasseadmin'] !== true) {
	header('Location: index.php');
	exit();
}
