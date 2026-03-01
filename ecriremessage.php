<?php

include("includes/basicprivatephp.php");
include("includes/bbcode.php");

if (isset($_POST['titre']) and isset($_POST['destinataire']) and isset($_POST['contenu'])) {
	csrfCheck();
	if (!empty($_POST['titre']) and !empty($_POST['destinataire']) and !empty($_POST['contenu'])) {
		$_POST['titre'] = mysqli_real_escape_string($base, antihtml($_POST['titre']));
		$_POST['destinataire'] = ucfirst(mysqli_real_escape_string($base, $_POST['destinataire']));
		$_POST['contenu'] = mysqli_real_escape_string($base, $_POST['contenu']);
		if ($_POST['destinataire'] == "[alliance]") {
			$idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);
			$ex = dbQuery($base, 'SELECT * FROM autre WHERE idalliance=? AND login !=?', 'is', $idalliance['idalliance'], $_SESSION['login']);
			while ($destinataire = mysqli_fetch_array($ex)) {
				$now = time();
				dbExecute($base, 'INSERT INTO messages VALUES(default, ?, ?, ?, ?, ?, default)', 'issss', $now, $_POST['titre'], $_POST['contenu'], $_SESSION['login'], $destinataire['login']);
			}
			$information = "Le message a bien été envoyé à toute l'alliance.";
		} elseif ($_POST['destinataire'] == "[all]" && $_SESSION['login'] == "Guortates") {
			$ex = dbQuery($base, 'SELECT * FROM autre');
			while ($destinataire = mysqli_fetch_array($ex)) {
				$now = time();
				dbExecute($base, 'INSERT INTO messages VALUES(default, ?, ?, ?, ?, ?, default)', 'issss', $now, $_POST['titre'], $_POST['contenu'], $_SESSION['login'], $destinataire['login']);
			}
			$information = "Le message a bien été à tous les joueurs.";
		} else {
			$joueurExiste = dbCount($base, 'SELECT count(*) as nb FROM autre WHERE login=?', 's', $_POST['destinataire']);
			if ($joueurExiste > 0) {
				$now = time();
				dbExecute($base, 'INSERT INTO messages VALUES(default, ?, ?, ?, ?, ?, default)', 'issss', $now, $_POST['titre'], $_POST['contenu'], $_SESSION['login'], $_POST['destinataire']);
				$information =  "Le message a bien été envoyé.";
				echo '
                <script>
                document.location.href="messages.php?information=' . $information . '"
                </script>';
			} else {
				$erreur = 'Le joueur ' . mysqli_real_escape_string($base, stripslashes(antihtml($_POST['destinataire']))) . ' n\'existe pas.';
			}
		}
	} else {
		$erreur = "Tous les champs ne sont pas remplis.";
	}
}

include("includes/tout.php");

if (isset($_GET['id'])) {
	$_GET['id'] = antiXSS($_GET['id']);
	$message = dbFetchOne($base, 'SELECT expeditaire, contenu, destinataire FROM messages WHERE id=?', 'i', $_GET['id']);
} elseif (isset($_POST['id'])) {
	$_POST['id'] = antiXSS($_POST['id']);
	$message = dbFetchOne($base, 'SELECT expeditaire, contenu, destinataire FROM messages WHERE id=?', 'i', $_POST['id']);
} else {
	$message['contenu'] = "";
	$message['expeditaire'] = "";
	$message['destinataire'] = $_SESSION['login'];
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
item(['floating' => true, 'titre' => 'Titre', 'input' => '<input type="text" class="form-control" name="titre" id="titre" value="' . $valueTitre . '" />']);

$valueDestinataire = antiXSS($message['expeditaire']);
if (isset($_GET['destinataire'])) {
	$valueDestinataire = antiXSS($_GET['destinataire']);
}
if (isset($_POST['destinataire'])) {
	$valueDestinataire = antiXSS($_POST['destinataire']);
}
item(['floating' => true, 'titre' => 'Destinataire', 'input' => '<input type="text" class="form-control" name="destinataire" id="destinataire" value="' . $valueDestinataire . '" />']);

if (isset($_GET['id'])) {
	creerBBcode("contenu", $message['contenu'], 1);
	$options = $message['contenu'];
} elseif (isset($_POST['contenu'])) {
	creerBBcode("contenu", stripslashes(preg_replace('#(\\\r\\\n|\\\r|\\\n)#', "\n", ($_POST['contenu']))));
	$options = stripslashes(preg_replace('#(\\\r\\\n|\\\r|\\\n)#', "\n", ($_POST['contenu'])));
} else {
	creerBBcode("contenu");
	$options = "";
}

item(['floating' => true, 'titre' => "Contenu", 'input' => '<textarea name="contenu" id="contenu" rows="10" cols="50">' . $options . '</textarea>']);


item(['input' => submit(['form' => 'formEcrire', 'titre' => 'Envoyer'])]);
echo '<form/>';
finListe();
finCarte();
include("includes/copyright.php");
