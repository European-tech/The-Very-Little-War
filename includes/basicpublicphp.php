<?php
include("includes/connexion.php");
include("includes/fonctions.php");
require_once(__DIR__ . '/csrf.php');
require_once(__DIR__ . '/logger.php');
require_once(__DIR__ . '/rate_limiter.php');

require_once(__DIR__ . '/session_init.php');
require_once(__DIR__ . '/csp.php');

// Clear login session data for public pages, but preserve CSRF tokens
// so that forms (login, registration) can verify their submissions
if (isset($_SESSION['login'])) {
	unset($_SESSION['login']);
	unset($_SESSION['session_token']);
}
// Note: We do NOT call session_destroy() here because it would wipe
// the CSRF token needed for form submissions on public pages.

//Si le formulaire de connexion a ete soumis et que le couple mdp login est bon, on se connecte
if (isset($_POST['loginConnexion']) && isset($_POST['passConnexion'])) {
	csrfCheck();
	if (!empty($_POST['loginConnexion']) && !empty($_POST['passConnexion'])) {
		// 10 attempts per 5 minutes per IP
		if (!rateLimitCheck($_SERVER['REMOTE_ADDR'], 'login', RATE_LIMIT_LOGIN_MAX, RATE_LIMIT_LOGIN_WINDOW)) {
			// P9-HIGH-008: Use consistent fallback salt 'tvlw_salt' (not bare 'tvlw')
			logWarn('AUTH', 'Login rate limited', ['ip_hash' => substr(hash_hmac('sha256', ($_SERVER['REMOTE_ADDR'] ?? ''), (defined('SECRET_SALT') ? SECRET_SALT : 'tvlw_salt')), 0, 12)]);
			$erreur = 'Trop de tentatives de connexion. Réessayez dans quelques minutes.';
		} else {

		// INFRA-SEC-MEDIUM-005: Per-account rate limit — prevents brute-force from multiple IPs.
		// Normalize the submitted login the same way authentication does so the key is consistent.
		$rawLoginForLimit = ucfirst(mb_strtolower(trim($_POST['loginConnexion'])));
		if (!rateLimitCheck($rawLoginForLimit, 'login_account', RATE_LIMIT_LOGIN_MAX, RATE_LIMIT_LOGIN_WINDOW)) {
			logWarn('AUTH', 'Login rate limited (per-account)', ['login' => $rawLoginForLimit]);
			$erreur = 'Trop de tentatives de connexion. Réessayez dans quelques minutes.';
		} else {

		$loginInput = ucfirst(mb_strtolower(trim($_POST['loginConnexion'])));
		$passwordInput = $_POST['passConnexion'];

		// Use prepared statement to fetch user (AUTH16-001: include estExclu to reject banned players at login)
		$row = dbFetchOne($base, 'SELECT login, pass_md5, estExclu FROM membre WHERE login = ?', 's', $loginInput);

		if ($row && (int)$row['estExclu'] === 1) {
			// AUTH16-001: Reject banned players immediately at login rather than on next page load.
			$erreur = 'Le couple login-mot de passe est erronn&eacute;';
		} elseif ($row) {
			$storedHash = $row['pass_md5'];
			$authenticated = false;

			// Try bcrypt first (new-style hash)
			if (password_verify($passwordInput, $storedHash)) {
				$authenticated = true;
			}
			// Fall back to legacy MD5 check, then auto-upgrade to bcrypt
			elseif (hash_equals(md5($passwordInput), $storedHash)) {
				$authenticated = true;
				// Auto-upgrade: replace MD5 hash with bcrypt
				$newHash = password_hash($passwordInput, PASSWORD_DEFAULT);
				dbExecute($base, 'UPDATE membre SET pass_md5 = ? WHERE login = ?', 'ss', $newHash, $loginInput);
				$storedHash = $newHash;
			}

			if ($authenticated) {
				// AUTH-MEDIUM-004: Only clean up stale visitor accounts after successful authentication
				// to avoid unnecessary DB writes on every failed login attempt.
				// AUTH-P20-004: Wrap in transaction with FOR UPDATE to prevent two concurrent logins
				// from both selecting the same stale visitors and running duplicate supprimerJoueur calls.
				// LIMIT 50 prevents unbounded cleanup on first login after a long outage.
				withTransaction($base, function() use ($base) {
					$suppRows = dbFetchAll($base, "SELECT login FROM membre WHERE login LIKE ? AND derniereConnexion < ? LIMIT 50 FOR UPDATE", 'si', 'Visiteur%', time() - VISITOR_SESSION_CLEANUP_SECONDS);
					foreach ($suppRows as $supp) {
						supprimerJoueur($supp['login']);
					}
				});

				// session_init.php (loaded above) already started the session; no need to call session_start() again.
				session_regenerate_id(true);
				// Regenerate CSRF token so the pre-login token cannot be reused
				$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
				$_SESSION['login'] = $loginInput;
				$sessionToken = bin2hex(random_bytes(32));
				$_SESSION['session_token'] = $sessionToken;
				$_SESSION['last_activity'] = time();
				$_SESSION['session_created'] = time(); // SESSION-P10-001: absolute lifetime anchor
				dbExecute($base, 'UPDATE membre SET session_token=? WHERE login=?', 'ss', $sessionToken, $loginInput);

				// P9-HIGH-007: Hash IP before storage for GDPR compliance
				// P27-001: require multiaccount.php BEFORE hashIpAddress() which is defined there
				require_once(__DIR__ . '/multiaccount.php');
				dbExecute($base, 'UPDATE membre SET ip = ? WHERE login = ?', 'ss', hashIpAddress($_SERVER['REMOTE_ADDR']), $loginInput);
				logInfo('AUTH', 'Login successful', ['login' => $loginInput]);
				logLoginEvent($base, $loginInput, 'login');

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
		} // end per-account rate limit else
		} // end rate limit else
	} else {
		$erreur = 'Un des deux champs n\'a pas &eacute;t&eacute; rempli';
	}
}

// LOW-015: Stale connectes cleanup moved to scripts/cleanup_old_data.php cron (30-min threshold)
// No per-request DELETE here to reduce write pressure on public pages.
