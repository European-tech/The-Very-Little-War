<?php
include("includes/basicprivatephp.php");
include("includes/bbcode.php");

// Validate and sanitize ID early
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$type = isset($_GET['type']) ? (int)$_GET['type'] : 0;

// On regarde si l'utilisateur est un modérateur
$moderateur = dbFetchOne($base, 'SELECT moderateur FROM membre WHERE login = ?', 's', $_SESSION['login']);

// On recherche le sujet que l'on souhaite éditer
$sujet = dbFetchOne($base, 'SELECT idsujet FROM reponses WHERE id = ?', 'i', $id);

// Suppression - require POST with CSRF
if ($type == 3 AND $id > 0 AND $_SERVER['REQUEST_METHOD'] === 'POST') {
	csrfCheck();
	$auteur = dbFetchOne($base, 'SELECT auteur FROM reponses WHERE id = ?', 'i', $id);

	$modo = dbFetchOne($base, 'SELECT count(*) AS modo FROM membre WHERE login = ? AND moderateur = 1', 's', $_SESSION['login']);
	if ($auteur && ($auteur['auteur'] == $_SESSION['login'] OR $modo['modo'] >= 1)) {
		dbExecute($base, 'DELETE FROM reponses WHERE id = ?', 'i', $id);
		$nbMessages = dbFetchOne($base, 'SELECT nbMessages FROM autre WHERE login = ?', 's', $_SESSION['login']);
		$newNbMessages = $nbMessages['nbMessages'] - 1;
		dbExecute($base, 'UPDATE autre SET nbMessages = ? WHERE login = ?', 'is', $newNbMessages, $_SESSION['login']);
		$sujetId = $sujet ? (int)$sujet['idsujet'] : 0;
		echo "<script>window.location.replace(\"sujet.php?id=" . $sujetId . "\")</script>";
	} else {
		$erreur = "Vous ne pouvez pas supprimer une réponse dont vous n'êtes pas l'auteur.";
	}
}

// Si on souhaite masquer un message - require POST with CSRF
if ($type == 5 AND $id > 0 AND $_SERVER['REQUEST_METHOD'] === 'POST') {
	csrfCheck();
	dbExecute($base, 'UPDATE reponses SET visibilite = 0 WHERE id = ?', 'i', $id);
	$sujetId = $sujet ? (int)$sujet['idsujet'] : 0;
	echo "<script>window.location.replace(\"sujet.php?id=" . $sujetId . "\")</script>";
}
// Si on souhaite afficher un message - require POST with CSRF
if ($type == 4 AND $id > 0 AND $_SERVER['REQUEST_METHOD'] === 'POST') {
	csrfCheck();
	dbExecute($base, 'UPDATE reponses SET visibilite = 1 WHERE id = ?', 'i', $id);
	$sujetId = $sujet ? (int)$sujet['idsujet'] : 0;
	echo "<script>window.location.replace(\"sujet.php?id=" . $sujetId . "\")</script>";
}

if (isset($_POST['contenu']) AND !empty($_POST['contenu']) AND $id > 0 AND $type > 0) {
	csrfCheck();
	$contenu = $_POST['contenu'];
	if (isset($_POST['titre']) AND !empty($_POST['titre'])) { // alors c'est un sujet
		$titre = $_POST['titre'];
		$auteur = dbFetchOne($base, 'SELECT auteur FROM sujets WHERE id = ?', 'i', $id);
		if ($type == 1) {
			if ($auteur && $auteur['auteur'] == $_SESSION['login']) {
				dbExecute($base, 'UPDATE sujets SET contenu = ?, titre = ? WHERE id = ?', 'ssi', $contenu, $titre, $id);
				$information = "Le sujet a bien été modifié";
				dbExecute($base, 'DELETE FROM statutforum WHERE idsujet = ?', 'i', $id);
                ?>
                <script>
                    window.location.replace("sujet.php?id=<?php echo $id; ?>");
                </script> <?php
			} else {
				$erreur = "Vous ne pouvez modifier un sujet donc vous n'êtes pas l'auteur";
			}
		}
	}
	if ($type == 2) {
		// Rajout de Yojim
		if ($moderateur['moderateur'] == '0') {
			$auteur = dbFetchOne($base, 'SELECT auteur FROM reponses WHERE id = ?', 'i', $id);
			if ($auteur && $auteur['auteur'] == $_SESSION['login']) {
				dbExecute($base, 'UPDATE reponses SET contenu = ? WHERE auteur = ? AND id = ?', 'ssi', $contenu, $_SESSION['login'], $id);
				$information = "La réponse a bien été modifiée";
				$reponse = dbFetchOne($base, 'SELECT * FROM reponses WHERE id = ?', 'i', $id);
				if ($reponse) {
					dbExecute($base, 'DELETE FROM statutforum WHERE idsujet = ?', 'i', $reponse['idsujet']);
				}

                $sujetRow = dbFetchOne($base, 'SELECT idsujet FROM reponses WHERE id = ?', 'i', $id);
                if ($sujetRow) {
                ?>
                <script>
                    window.location.replace("sujet.php?id=<?php echo (int)$sujetRow['idsujet']; ?>");
                </script> <?php
                }
			} else {
				$erreur = "Vous ne pouvez pas modifier une réponse donc vous n'êtes pas l'auteur";
			}
		} else {
			dbExecute($base, 'UPDATE reponses SET contenu = ? WHERE id = ?', 'si', $contenu, $id);
			$information = "La réponse a bien été modifiée";
			$reponse = dbFetchOne($base, 'SELECT * FROM reponses WHERE id = ?', 'i', $id);
			if ($reponse) {
				dbExecute($base, 'DELETE FROM statutforum WHERE idsujet = ?', 'i', $reponse['idsujet']);
			}
		}
	}
}

include("includes/layout.php");

debutCarte("Editer");

if ($id > 0 AND $type > 0) {
	// Modification du sujet
	if ($type == 1) {
		$ex = dbQuery($base, 'SELECT * FROM sujets WHERE id = ?', 'i', $id);
	}
	// Modification d'un des messages
	else {
		$ex = dbQuery($base, 'SELECT * FROM reponses WHERE id = ?', 'i', $id);
	}
	$reponse = mysqli_fetch_array($ex);
	$nbReponses = mysqli_num_rows($ex);
	if ($id != 1 && $type == 2) { // si c'est un message il n'y a pas de titre
		$reponse['titre'] = "";
	}

	if ($nbReponses == 1) {
		debutListe();
        echo '<form method="post" action="" name="formEditer">';
        echo csrfField();
		if ($type == 1) {
            item(['titre' => 'Titre', "floating" => true, 'input'=> '<input type="text" name="titre" id="titre" value="'.htmlspecialchars($reponse['titre'], ENT_QUOTES, 'UTF-8').'"/>']);
		}

        creerBBcode("contenu", $reponse['contenu']);
        item(['floating' => false, 'titre' => "Réponse", 'input' => '<textarea name="contenu" id="contenu" rows="10" cols="50">'.htmlspecialchars($reponse['contenu'], ENT_QUOTES, 'UTF-8').'</textarea>']);
        item(['input' => submit(['titre' => 'Editer', 'form'=>'formEditer'])]);
        echo '</form>';
		finListe();
	} else {
		if ($id != 3) {
			echo 'Ce sujet ou cette réponse n\'existe pas !';
		}
	}
} else {
	echo 'Stop jouer avec la barre URL espèce de troll !';
}

finCarte();
include("includes/copyright.php");
