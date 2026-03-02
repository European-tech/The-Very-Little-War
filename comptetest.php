<?php
session_start();
include("includes/connexion.php");
include("includes/fonctions.php");
require_once("includes/csrf.php");
require_once("includes/database.php");

if (isset($_GET['inscription'])) {
	$nb = dbFetchOne($base, 'SELECT numerovisiteur FROM statistiques');
	dbExecute($base, 'UPDATE statistiques SET numerovisiteur = ?', 'i', $nb['numerovisiteur'] + 1);
	$log = 'Visiteur' . ($nb['numerovisiteur'] - 1);
	$time = dbFetchOne($base, 'SELECT timestamp FROM membre WHERE login = ?', 's', $log);
	if ($time && time() - $time['timestamp'] > 60) { // pour éviter d'avoir trop de joueurs
		inscrire("Visiteur" . $nb['numerovisiteur'], "Visiteur" . $nb['numerovisiteur'], "Visiteur" . $nb['numerovisiteur'] . "@tvlw.com");
		$_SESSION['login'] = ucfirst(mb_strtolower("Visiteur" . $nb['numerovisiteur']));
		// Use password_hash for visitor accounts too
		$visitorPass = "Visiteur" . $nb['numerovisiteur'];
		$hashedPass = password_hash($visitorPass, PASSWORD_DEFAULT);
		dbExecute($base, 'UPDATE membre SET pass_md5 = ? WHERE login = ?', 'ss', $hashedPass, $_SESSION['login']);
		$sessionToken = bin2hex(random_bytes(32));
		$_SESSION['session_token'] = $sessionToken;
		dbExecute($base, 'UPDATE membre SET session_token = ? WHERE login = ?', 'ss', $sessionToken, $_SESSION['login']);
		header('Location: tutoriel.php?deployer=1');
		exit();
	} else {
		header('Location: index.php?att=1');
		exit();
	}
} else {
	// CSRF check for POST registration
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		csrfCheck();
	}

	$erreur = '';
	//Si les champs sont vides
	if ((isset($_POST['login']) && !empty($_POST['login'])) && (isset($_POST['pass']) && !empty($_POST['pass'])) && (isset($_POST['pass_confirm']) && !empty($_POST['pass_confirm'])) && (isset($_POST['email']) && !empty($_POST['email']))) {
		//Si les deux mots de passe sont différents
		if ($_POST['pass'] != $_POST['pass_confirm']) {
			$erreur = 'Les deux mots de passe sont différents.';
		} else {
			if (preg_match("#^[A-Za-z0-9]*$#", $_POST['login'])) {
				if (preg_match("#^[a-z0-9._-]+@[a-z0-9._-]{2,}\.[a-z]{2,4}$#", $_POST['email'])) {
					$_POST['login'] = ucfirst(mb_strtolower($_POST['login']));
					$loginClean = $_POST['login'];

					$data = dbFetchOne($base, 'SELECT count(*) AS cnt FROM membre WHERE login = ?', 's', $loginClean);
					//Si le login est déjà utilisé
					if ($data['cnt'] == 0) {
						$oldLogin = $_SESSION['login'];
						$newLogin = $loginClean;
						$email = trim($_POST['email']);
						// Use password_hash instead of MD5
						$hashedPassword = password_hash($_POST['pass'], PASSWORD_DEFAULT);

						// Update all tables with prepared statements
						dbExecute($base, 'UPDATE autre SET login = ? WHERE login = ?', 'ss', $newLogin, $oldLogin);
						dbExecute($base, 'UPDATE grade SET login = ? WHERE login = ?', 'ss', $newLogin, $oldLogin);
						dbExecute($base, 'UPDATE constructions SET login = ? WHERE login = ?', 'ss', $newLogin, $oldLogin);
						dbExecute($base, 'UPDATE invitations SET invite = ? WHERE invite = ?', 'ss', $newLogin, $oldLogin);
						dbExecute($base, 'UPDATE membre SET login = ?, pass_md5 = ?, email = ? WHERE login = ?', 'ssss', $newLogin, $hashedPassword, $email, $oldLogin);
						dbExecute($base, 'UPDATE messages SET destinataire = ? WHERE destinataire = ?', 'ss', $newLogin, $oldLogin);
						dbExecute($base, 'UPDATE messages SET expeditaire = ? WHERE expeditaire = ?', 'ss', $newLogin, $oldLogin);
						dbExecute($base, 'UPDATE moderation SET destinataire = ? WHERE destinataire = ?', 'ss', $newLogin, $oldLogin);
						dbExecute($base, 'UPDATE molecules SET proprietaire = ? WHERE proprietaire = ?', 'ss', $newLogin, $oldLogin);
						dbExecute($base, 'UPDATE rapports SET destinataire = ? WHERE destinataire = ?', 'ss', $newLogin, $oldLogin);
						dbExecute($base, 'UPDATE reponses SET auteur = ? WHERE auteur = ?', 'ss', $newLogin, $oldLogin);
						dbExecute($base, 'UPDATE ressources SET login = ? WHERE login = ?', 'ss', $newLogin, $oldLogin);
						dbExecute($base, 'UPDATE sanctions SET joueur = ? WHERE joueur = ?', 'ss', $newLogin, $oldLogin);
						dbExecute($base, 'UPDATE statutforum SET login = ? WHERE login = ?', 'ss', $newLogin, $oldLogin);
						dbExecute($base, 'UPDATE sujets SET auteur = ? WHERE auteur = ?', 'ss', $newLogin, $oldLogin);
						dbExecute($base, 'UPDATE autre SET niveaututo = 8 WHERE login = ?', 's', $newLogin);

						$_SESSION['login'] = $newLogin;
						$sessionToken = bin2hex(random_bytes(32));
						$_SESSION['session_token'] = $sessionToken;
						dbExecute($base, 'UPDATE membre SET session_token = ? WHERE login = ?', 'ss', $sessionToken, $newLogin);

						echo '<script type="text/javascript">
						window.location.href = "index.php?inscrit=1";
						</script>';
						exit();
					} else {
						$erreur = 'Ce login est déjà utilisé.';
					}
				} else {
					$erreur = 'L\'email n\'est pas correct.';
				}
			} else {
				$erreur = 'Vous ne pouvez pas utiliser de caractères spéciaux dans votre login';
			}
		}
	} else {
		$erreur = 'Un ou plusieurs champs sont vides.';
	}
    echo '<script>document.location.href="constructions.php?erreur=' . urlencode($erreur) . '";</script>';
}
