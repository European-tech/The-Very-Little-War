<?php
session_name('TVLW_ADMIN');
session_start();
include_once(__DIR__ . '/../includes/constantesBase.php');
if (!isset($_SESSION['motdepasseadmin']) or $_SESSION['motdepasseadmin'] !== true) {
	header('Location: index.php');
	exit();
}
