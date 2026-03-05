<?php
// Vérifie si le joueur connecté est en vacances — server-side redirect
$joueurEnVac = dbFetchOne($base, 'SELECT vacance FROM membre WHERE login=?', 's', $_SESSION['login']);
if ($joueurEnVac && $joueurEnVac['vacance']) {
	header('Location: vacance.php');
	exit();
}
?>