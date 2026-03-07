<?php
/**
 * Mass message script — ADMIN ONLY
 * Sends a message to every player. Requires admin authentication.
 */
require_once('includes/basicprivatephp.php');
require_once('includes/csrf.php');

// Require game owner account (runs in player session, not admin session)
if ($_SESSION['login'] !== ADMIN_LOGIN) {
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

$titre = trim($_POST['titre']);
$texte = trim($_POST['message']);

if (empty($titre) || empty($texte)) {
	header('Location: messageCommun.php?erreur=' . urlencode('Titre et message requis.'));
	exit();
}

// MED-032: Length validation on admin broadcast message
if (mb_strlen($texte) > MESSAGE_MAX_LENGTH) {
	header('Location: messageCommun.php?erreur=' . urlencode('Message trop long.'));
	exit();
}

$message = $texte;

$membres = dbFetchAll($base, 'SELECT login FROM membre');
$timestamp = time();
$count = 0;
foreach($membres as $d){
	dbExecute($base, 'INSERT INTO messages VALUES(default, ?, ?, ?, ?, ?, default)', 'issss', $timestamp, $titre, $message, $_SESSION['login'], $d['login']);
	$count++;
}

header('Location: messages.php?information=' . urlencode("Message envoyé à $count joueurs."));
exit();
