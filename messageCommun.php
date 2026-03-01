<?php
include("includes/connexion.php");

$ex = dbQuery($base, 'SELECT login FROM membre');

$timestamp = time();
while($d = mysqli_fetch_array($ex)){
	dbExecute($base, 'INSERT INTO messages VALUES(default, ?, "Bienvenue", "Bienvenue à tous les nouveaux joueurs et merci à SVJ ! Vous avez vu que le premier joueur est loin devant dans le classement mais ne vous découragez pas, une nouvelle partie recommence tous les mois. Prenez donc le temps de cette fin de partie du mois d\'octobre pour vous entrainer ! Bon jeu et bonne chance sur The Very Little War", "Guortates", ?, default)', 'is', $timestamp, $d['login']);
}
