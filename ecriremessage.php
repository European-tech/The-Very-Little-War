<?php

include("includes/basicprivatephp.php");
include("includes/bbcode.php");
require_once("includes/rate_limiter.php");

if (isset($_POST['titre']) and isset($_POST['destinataire']) and isset($_POST['contenu'])) {
	csrfCheck();
	if (!empty($_POST['titre']) and !empty($_POST['destinataire']) and !empty($_POST['contenu'])) {
		$_POST['titre'] = trim($_POST['titre']);
		// LOW-031: resolve canonical login from DB to avoid case mismatch
	$_POST['destinataire'] = trim($_POST['destinataire']);
		$_POST['contenu'] = trim($_POST['contenu']);
		// Length validation to prevent storage abuse
		if (mb_strlen($_POST['titre'], 'UTF-8') > 200) {
			$erreur = "Le titre est trop long (200 caractères max).";
		} elseif (mb_strlen($_POST['contenu'], 'UTF-8') > MESSAGE_MAX_LENGTH) {
			$erreur = "Le message est trop long (" . MESSAGE_MAX_LENGTH . " caractères max).";
		} else
		if ($_POST['destinataire'] == "[alliance]") {
			// Rate limit: 3 alliance broadcasts per 5 minutes (P5-GAP-022)
			$idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);
			if (empty($idalliance['idalliance']) || $idalliance['idalliance'] <= 0) {
				$erreur = "Vous n'avez pas d'alliance.";
			} elseif (!rateLimitCheck($_SESSION['login'], 'broadcast_alliance', 3, 300)) {
				$erreur = "Vous envoyez trop de messages de masse. Veuillez patienter.";
			} else {
				// MED-030: Wrap all inserts in a transaction so partial sends are rolled back on failure
				$destinataireRows = dbFetchAll($base, 'SELECT * FROM autre WHERE idalliance=? AND login !=?', 'is', $idalliance['idalliance'], $_SESSION['login']);
				$titreMsg   = $_POST['titre'];
				$contenuMsg = $_POST['contenu'];
				$expediteur = $_SESSION['login'];
				withTransaction($base, function() use ($base, $destinataireRows, $titreMsg, $contenuMsg, $expediteur) {
					$now = time();
					foreach ($destinataireRows as $destinataire) {
						dbExecute($base, 'INSERT INTO messages VALUES(default, ?, ?, ?, ?, ?, default)', 'issss', $now, $titreMsg, $contenuMsg, $expediteur, $destinataire['login']);
					}
				});
				// SOCIAL-LOW-001: PRG pattern — store success in session and redirect to prevent duplicate POST on refresh.
				$_SESSION['flash_message'] = "Le message a bien été envoyé à toute l'alliance.";
				header('Location: ecriremessage.php');
				exit();
			}
		} elseif ($_POST['destinataire'] == "[all]") {
			$isAdmin = ($_SESSION['login'] === ADMIN_LOGIN);
			if (!$isAdmin) {
				$erreur = "Accès refusé.";
			} elseif (!rateLimitCheck($_SESSION['login'], 'broadcast_all', 2, 3600)) {
				$erreur = "Vous envoyez trop de messages de masse. Veuillez patienter.";
			} else {
				$allDestinataires = dbFetchAll($base, 'SELECT login FROM autre');
				$titreAll      = $_POST['titre'];
				$contenuAll    = $_POST['contenu'];
				$expediteurAll = $_SESSION['login'];
				withTransaction($base, function() use ($base, $allDestinataires, $titreAll, $contenuAll, $expediteurAll) {
					$now = time();
					foreach ($allDestinataires as $destinataire) {
						dbExecute($base, 'INSERT INTO messages VALUES(default, ?, ?, ?, ?, ?, default)', 'issss', $now, $titreAll, $contenuAll, $expediteurAll, $destinataire['login']);
					}
				});
				// SOCIAL-LOW-001: PRG pattern — store success in session and redirect to prevent duplicate POST on refresh.
				$_SESSION['flash_message'] = "Le message a bien été envoyé à tous les joueurs.";
				header('Location: ecriremessage.php');
				exit();
			}
		} else {
			if (!rateLimitCheck($_SESSION['login'], 'private_msg', 10, 300)) {
				$erreur = "Vous envoyez trop de messages. Veuillez patienter.";
			} else {
			// LOW-031: resolve canonical login from DB (case-insensitive lookup, use stored form)
			$canonicalRow = dbFetchOne($base, 'SELECT login FROM autre WHERE LOWER(login)=LOWER(?)', 's', $_POST['destinataire']);
			if ($canonicalRow) {
				$canonicalLogin = $canonicalRow['login'];
				// P9-MED-019: Self-messaging guard (compare canonical DB login, case-insensitive)
				if (strtolower($canonicalLogin) === strtolower($_SESSION['login'])) {
					$erreur = "Vous ne pouvez pas vous envoyer un message à vous-même.";
				} else {
					// FLOW-SOCIAL MEDIUM-001: Do not deliver messages to banned players
					$recipientMembre = dbFetchOne($base, 'SELECT estExclu FROM membre WHERE login=?', 's', $canonicalLogin);
					if ($recipientMembre && (int)$recipientMembre['estExclu'] === 1) {
						$erreur = "Ce joueur n'existe pas.";
					} else {
						// FLOW-SOCIAL MEDIUM-002: Enforce inbox size cap
						$inboxCount = dbCount($base, 'SELECT COUNT(*) FROM messages WHERE destinataire=? AND deleted_by_recipient=0', 's', $canonicalLogin);
						if ($inboxCount >= INBOX_MAX_MESSAGES) {
							$erreur = "La boîte de réception de ce joueur est pleine.";
						} else {
							$now = time();
							dbExecute($base, 'INSERT INTO messages VALUES(default, ?, ?, ?, ?, ?, default)', 'issss', $now, $_POST['titre'], $_POST['contenu'], $_SESSION['login'], $canonicalLogin);
							// LOW-030: store flash message before redirect
							$_SESSION['flash_message'] = 'Message envoyé avec succès.';
							header('Location: messages.php');
							exit();
						}
					}
				}
			} else {
				$erreur = 'Le joueur ' . htmlspecialchars($_POST['destinataire'], ENT_QUOTES, 'UTF-8') . ' n\'existe pas.';
			}
			} // end rate limit else
		}
	} else {
		$erreur = "Tous les champs ne sont pas remplis.";
	}
}

// SOCIAL-LOW-001: Consume flash message from PRG redirect and expose as $information for display.
if (isset($_SESSION['flash_message']) && !isset($information)) {
	$information = $_SESSION['flash_message'];
	unset($_SESSION['flash_message']);
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
item(['floating' => true, 'titre' => 'Titre', 'input' => '<input type="text" class="form-control" name="titre" id="titre" maxlength="200" value="' . htmlspecialchars($valueTitre, ENT_QUOTES, 'UTF-8') . '" />']); // P9-MED-020: maxlength matches server-side 200-char limit

$valueDestinataire = trim($message['expeditaire']);
if (isset($_GET['destinataire'])) {
	$valueDestinataire = trim($_GET['destinataire']);
}
if (isset($_POST['destinataire'])) {
	$valueDestinataire = trim($_POST['destinataire']);
}
item(['floating' => true, 'titre' => 'Destinataire', 'input' => '<input type="text" class="form-control" name="destinataire" id="destinataire" value="' . htmlspecialchars($valueDestinataire, ENT_QUOTES, 'UTF-8') . '" />']);

if (isset($_GET['id'])) {
	// P9-MED-018 SECURITY: $message['contenu'] here is raw DB content passed as the second arg to creerBBcode().
	// creerBBcode()'s second arg is a pre-fill value; it is NOT rendered as HTML by creerBBcode() itself —
	// it is only placed into the <textarea> below via htmlspecialchars($options). This is safe as long as
	// the textarea value is always escaped before output (see item() call below). Do NOT pass this value to
	// any rendering function that outputs raw HTML without escaping.
	creerBBcode("contenu", $message['contenu'], 1);
	$options = $message['contenu'];
} elseif (isset($_POST['contenu'])) {
	creerBBcode("contenu", preg_replace('#(\\\r\\\n|\\\r|\\\n)#', "\n", ($_POST['contenu'])));
	$options = preg_replace('#(\\\r\\\n|\\\r|\\\n)#', "\n", ($_POST['contenu']));
} else {
	creerBBcode("contenu");
	$options = "";
}

item(['floating' => true, 'titre' => "Contenu", 'input' => '<textarea name="contenu" id="contenu" rows="10" cols="50" maxlength="' . MESSAGE_MAX_LENGTH . '">' . htmlspecialchars($options, ENT_QUOTES, 'UTF-8') . '</textarea>']); // P9-MED-020: maxlength matches MESSAGE_MAX_LENGTH constant


item(['input' => submit(['form' => 'formEcrire', 'titre' => 'Envoyer'])]);
echo '</form>';
finListe();
finCarte();
include("includes/copyright.php");
