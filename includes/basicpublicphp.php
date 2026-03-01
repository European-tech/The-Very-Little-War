<?php
include("includes/connexion.php");
include("includes/fonctions.php");
require_once(__DIR__ . '/logger.php');

if (!isset($_SESSION['start'])) {
	session_start();
}

//Si une session existe elle est detruite
session_unset();
session_destroy();

//Si le formulaire de connexion a ete soumis et que le couple mdp login est bon, on se connecte
if (isset($_POST['loginConnexion']) && isset($_POST['passConnexion'])) {
	if (!empty($_POST['loginConnexion']) && !empty($_POST['passConnexion'])) {
		$loginInput = ucfirst(mb_strtolower(antiXSS($_POST['loginConnexion'])));
		$passwordInput = $_POST['passConnexion'];

		// Use prepared statement to fetch user
		$row = dbFetchOne($base, 'SELECT login, pass_md5 FROM membre WHERE login = ?', 's', $loginInput);

		$a = mysqli_query($base, "SELECT login FROM membre WHERE login LIKE 'Visiteur%' AND derniereConnexion < " . (time() - 3600 * 3) . "");
		while ($supp = mysqli_fetch_array($a)) {
			supprimerJoueur($supp['login']);
		}

		if ($row) {
			$storedHash = $row['pass_md5'];
			$authenticated = false;

			// Try bcrypt first (new-style hash)
			if (password_verify($passwordInput, $storedHash)) {
				$authenticated = true;
			}
			// Fall back to legacy MD5 check, then auto-upgrade to bcrypt
			elseif (md5($passwordInput) === $storedHash) {
				$authenticated = true;
				// Auto-upgrade: replace MD5 hash with bcrypt
				$newHash = password_hash($passwordInput, PASSWORD_DEFAULT);
				dbExecute($base, 'UPDATE membre SET pass_md5 = ? WHERE login = ?', 'ss', $newHash, $loginInput);
				$storedHash = $newHash;
			}

			if ($authenticated) {
				session_start();
				$_SESSION['login'] = $loginInput;
				$_SESSION['mdp'] = $storedHash;

				dbExecute($base, 'UPDATE membre SET ip = ? WHERE login = ?', 'ss', $_SERVER['REMOTE_ADDR'], $loginInput);
				logInfo('AUTH', 'Login successful', ['login' => $loginInput]);

				$joueur = dbFetchOne($base, 'SELECT niveaututo FROM autre WHERE login = ?', 's', $_SESSION['login']);
				echo '
            <script>
                localStorage.setItem("login", "' . $_SESSION['login'] . '");
                window.location = "constructions.php";
            </script>';
			} else {
				logWarn('AUTH', 'Login failed - bad password', ['login' => $loginInput]);
				$erreur = 'Le couple login-mot de passe est erronn&eacute;';
			}
		} else {
			logWarn('AUTH', 'Login failed - user not found', ['login' => $loginInput]);
			$erreur = 'Le couple login-mot de passe est erronn&eacute;';
		}
	} else {
		$erreur = 'Un des deux champs n\'a pas &eacute;t&eacute; rempli';
	}
}

// Toutes les entrees vieilles de plus de 5 minutes sont supprimees (nombres de connectes)
$timestamp_5min = time() - (60 * 5); // 60 * 5 = nombre de secondes ecoulees en 5 minutes
mysqli_query($base, 'DELETE FROM connectes WHERE timestamp < ' . $timestamp_5min);
