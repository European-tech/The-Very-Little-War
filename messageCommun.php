<?php
/**
 * Mass message script — ADMIN ONLY
 * Sends a message to every player. Requires admin authentication.
 */
require_once('includes/basicprivatephp.php');
require_once('includes/csrf.php');

// Require admin password
if (empty($_SESSION['motdepasseadmin'])) {
	header('Location: index.php');
	exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['message']) || !isset($_POST['titre'])) {
	// Show form
	include("includes/layout.php");
	debutCarte("Message commun (admin)");
	echo '<form method="post" action="messageCommun.php" name="formMessage">';
	echo csrfField();
	debutListe();
	item(['floating' => true, 'titre' => 'Titre', 'input' => '<input type="text" name="titre" class="form-control" required/>']);
	item(['floating' => false, 'titre' => 'Message', 'input' => '<textarea name="message" rows="10" cols="50" required></textarea>']);
	item(['input' => submit(['form' => 'formMessage', 'titre' => 'Envoyer à tous'])]);
	finListe();
	echo '</form>';
	finCarte();
	include("includes/copyright.php");
	exit();
}

csrfCheck();

$titre = antihtml(trim($_POST['titre']));
$message = antihtml(trim($_POST['message']));

if (empty($titre) || empty($message)) {
	die('Titre et message requis.');
}

$ex = dbQuery($base, 'SELECT login FROM membre');
$timestamp = time();
$count = 0;
while($d = mysqli_fetch_array($ex)){
	dbExecute($base, 'INSERT INTO messages VALUES(default, ?, ?, ?, ?, ?, default)', 'issss', $timestamp, $titre, $message, $_SESSION['login'], $d['login']);
	$count++;
}

header('Location: messages.php?information=' . urlencode("Message envoyé à $count joueurs."));
exit();
