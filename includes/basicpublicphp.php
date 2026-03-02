<?php
include("includes/connexion.php");
include("includes/fonctions.php");
require_once(__DIR__ . '/logger.php');
require_once(__DIR__ . '/rate_limiter.php');

if (!isset($_SESSION['start'])) {
	if (session_status() === PHP_SESSION_NONE) {
		ini_set('session.cookie_httponly', 1);
		ini_set('session.cookie_secure', !empty($_SERVER['HTTPS']) ? 1 : 0);
		ini_set('session.use_strict_mode', 1);
		ini_set('session.cookie_samesite', 'Lax');
	}
	session_start();
}

// Clear login session data for public pages, but preserve CSRF tokens
// so that forms (login, registration) can verify their submissions
if (isset($_SESSION['login'])) {
	unset($_SESSION['login']);
	unset($_SESSION['mdp']);
	unset($_SESSION['session_token']);
}
// Note: We do NOT call session_destroy() here because it would wipe
// the CSRF token needed for form submissions on public pages.

//Si le formulaire de connexion a ete soumis et que le couple mdp login est bon, on se connecte
if (isset($_POST['loginConnexion']) && isset($_POST['passConnexion'])) {
	if (!empty($_POST['loginConnexion']) && !empty($_POST['passConnexion'])) {
		// 10 attempts per 5 minutes per IP
		if (!rateLimitCheck($_SERVER['REMOTE_ADDR'], 'login', 10, 300)) {
			die('<p>Trop de tentatives de connexion. Réessayez dans quelques minutes.</p>');
		}

		$loginInput = ucfirst(mb_strtolower(trim($_POST['loginConnexion'])));
		$passwordInput = $_POST['passConnexion'];

		// Use prepared statement to fetch user
		$row = dbFetchOne($base, 'SELECT login, pass_md5 FROM membre WHERE login = ?', 's', $loginInput);

		$a = dbQuery($base, "SELECT login FROM membre WHERE login LIKE ? AND derniereConnexion < ?", 'si', 'Visiteur%', time() - 3600 * 3);
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
				if (session_status() === PHP_SESSION_NONE) {
					session_start();
				}
				$_SESSION['login'] = $loginInput;
				$sessionToken = bin2hex(random_bytes(32));
				$_SESSION['session_token'] = $sessionToken;
				dbExecute($base, 'UPDATE membre SET session_token=? WHERE login=?', 'ss', $sessionToken, $loginInput);

				dbExecute($base, 'UPDATE membre SET ip = ? WHERE login = ?', 'ss', $_SERVER['REMOTE_ADDR'], $loginInput);
				logInfo('AUTH', 'Login successful', ['login' => $loginInput]);

				header('Location: constructions.php');
				exit();
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
dbExecute($base, 'DELETE FROM connectes WHERE timestamp < ?', 'i', $timestamp_5min);
