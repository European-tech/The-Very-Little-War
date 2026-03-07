<?php

include("includes/basicprivatephp.php");
include("includes/bbcode.php");
require_once("includes/rate_limiter.php");

if (isset($_POST['titre']) and isset($_POST['destinataire']) and isset($_POST['contenu'])) {
	csrfCheck();
	if (!empty($_POST['titre']) and !empty($_POST['destinataire']) and !empty($_POST['contenu'])) {
		$_POST['titre'] = trim($_POST['titre']);
		$_POST['destinataire'] = ucfirst(trim($_POST['destinataire']));
		$_POST['contenu'] = trim($_POST['contenu']);
		// Length validation to prevent storage abuse
		if (mb_strlen($_POST['titre'], 'UTF-8') > 200) {
			$erreur = "Le titre est trop long (200 caractères max).";
		} elseif (mb_strlen($_POST['contenu'], 'UTF-8') > 10000) {
			$erreur = "Le message est trop long (10000 caractères max).";
		} else
		if ($_POST['destinataire'] == "[alliance]") {
			// Rate limit: 3 alliance broadcasts per 5 minutes (P5-GAP-022)
			$idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);
			if (empty($idalliance['idalliance']) || $idalliance['idalliance'] <= 0) {
				$erreur = "Vous n'avez pas d'alliance.";
			} elseif (!rateLimitCheck($_SESSION['login'], 'broadcast_alliance', 3, 300)) {
				$erreur = "Vous envoyez trop de messages de masse. Veuillez patienter.";
			} else {
				$destinataireRows = dbFetchAll($base, 'SELECT * FROM autre WHERE idalliance=? AND login !=?', 'is', $idalliance['idalliance'], $_SESSION['login']);
				foreach ($destinataireRows as $destinataire) {
					$now = time();
					dbExecute($base, 'INSERT INTO messages VALUES(default, ?, ?, ?, ?, ?, default)', 'issss', $now, $_POST['titre'], $_POST['contenu'], $_SESSION['login'], $destinataire['login']);
				}
				$information = "Le message a bien été envoyé à toute l'alliance.";
			}
		} elseif ($_POST['destinataire'] == "[all]") {
			$isAdmin = (dbCount($base, "SELECT COUNT(*) FROM membre WHERE login = ? AND role = 'admin'", 's', $_SESSION['login']) > 0);
			if (!$isAdmin) {
				$erreur = "Accès refusé.";
			} elseif (!rateLimitCheck($_SESSION['login'], 'broadcast_all', 2, 3600)) {
				$erreur = "Vous envoyez trop de messages de masse. Veuillez patienter.";
			} else {
				$allDestinataires = dbFetchAll($base, 'SELECT login FROM autre');
				foreach ($allDestinataires as $destinataire) {
					$now = time();
					dbExecute($base, 'INSERT INTO messages VALUES(default, ?, ?, ?, ?, ?, default)', 'issss', $now, $_POST['titre'], $_POST['contenu'], $_SESSION['login'], $destinataire['login']);
				}
				$information = "Le message a bien été envoyé à tous les joueurs.";
			}
		} else {
			if (!rateLimitCheck($_SESSION['login'], 'private_msg', 10, 300)) {
				$erreur = "Vous envoyez trop de messages. Veuillez patienter.";
			} else {
			$joueurExiste = dbCount($base, 'SELECT count(*) as nb FROM autre WHERE login=?', 's', $_POST['destinataire']);
			if ($joueurExiste > 0) {
				$now = time();
				dbExecute($base, 'INSERT INTO messages VALUES(default, ?, ?, ?, ?, ?, default)', 'issss', $now, $_POST['titre'], $_POST['contenu'], $_SESSION['login'], $_POST['destinataire']);
				$information =  "Le message a bien été envoyé.";
				header('Location: messages.php');
			exit();
			} else {
				$erreur = 'Le joueur ' . htmlspecialchars($_POST['destinataire'], ENT_QUOTES, 'UTF-8') . ' n\'existe pas.';
			}
			} // end rate limit else
		}
	} else {
		$erreur = "Tous les champs ne sont pas remplis.";
	}
}

include("includes/layout.php");

if (isset($_GET['id'])) {
	$_GET['id'] = (int)$_GET['id'];
	$message = dbFetchOne($base, 'SELECT expeditaire, contenu, destinataire FROM messages WHERE id=? AND (destinataire=? OR expeditaire=?)', 'iss', $_GET['id'], $_SESSION['login'], $_SESSION['login']);
} elseif (isset($_POST['id'])) {
	$_POST['id'] = (int)$_POST['id'];
	$message = dbFetchOne($base, 'SELECT expeditaire, contenu, destinataire FROM messages WHERE id=? AND (destinataire=? OR expeditaire=?)', 'iss', $_POST['id'], $_SESSION['login'], $_SESSION['login']);
} else {
	$message = null;
}

if (!$message) {
	$message = ['contenu' => '', 'expeditaire' => '', 'destinataire' => $_SESSION['login']];
}

if ($message['destinataire'] != $_SESSION['login']) {
	$erreur = "Vous ne pouvez pas répondre à un message qui ne vous est pas destiné.";
	$message['expeditaire'] = "";
	$message['contenu'] = "";
}


debutCarte("Ecrire un message");
echo '<form action="ecriremessage.php" method="post" name="formEcrire">';
echo csrfField();
debutListe();
$valueTitre = "";
if (isset($_GET['reponse'])) {
	$valueTitre = '[Réponse]';
}
if (isset($_POST['titre'])) {
	$valueTitre = $_POST['titre'];
}
item(['floating' => true, 'titre' => 'Titre', 'input' => '<input type="text" class="form-control" name="titre" id="titre" value="' . htmlspecialchars($valueTitre, ENT_QUOTES, 'UTF-8') . '" />']);

$valueDestinataire = trim($message['expeditaire']);
if (isset($_GET['destinataire'])) {
	$valueDestinataire = trim($_GET['destinataire']);
}
if (isset($_POST['destinataire'])) {
	$valueDestinataire = trim($_POST['destinataire']);
}
item(['floating' => true, 'titre' => 'Destinataire', 'input' => '<input type="text" class="form-control" name="destinataire" id="destinataire" value="' . htmlspecialchars($valueDestinataire, ENT_QUOTES, 'UTF-8') . '" />']);

if (isset($_GET['id'])) {
	creerBBcode("contenu", $message['contenu'], 1);
	$options = $message['contenu'];
} elseif (isset($_POST['contenu'])) {
	creerBBcode("contenu", preg_replace('#(\\\r\\\n|\\\r|\\\n)#', "\n", ($_POST['contenu'])));
	$options = preg_replace('#(\\\r\\\n|\\\r|\\\n)#', "\n", ($_POST['contenu']));
} else {
	creerBBcode("contenu");
	$options = "";
}

item(['floating' => true, 'titre' => "Contenu", 'input' => '<textarea name="contenu" id="contenu" rows="10" cols="50">' . htmlspecialchars($options, ENT_QUOTES, 'UTF-8') . '</textarea>']);


item(['input' => submit(['form' => 'formEcrire', 'titre' => 'Envoyer'])]);
echo '</form>';
finListe();
finCarte();
include("includes/copyright.php");
