<?php
// AUTH-MEDIUM-002: This page creates visitor sessions. It must never be disabled or removed
// without also removing the visitor flow from index.php. It is NOT a test/debug file despite
// the legacy name — it is the visitor account creation and permanent-account conversion page.
// Access is restricted: visitor creation requires POST+CSRF+rate-limit; conversion requires
// an active Visitor* session. No anonymous browsing of this page is possible.
require_once("includes/session_init.php");
include("includes/connexion.php");
include("includes/fonctions.php");
require_once("includes/csrf.php");
require_once("includes/database.php");
require_once("includes/rate_limiter.php");
require_once("includes/validation.php");

// MEDIUM-030: Visitor account creation must be POST + CSRF to prevent CSRF-triggered creation.
if (isset($_POST['inscription']) || isset($_GET['inscription'])) {
	// Require POST method — reject GET to enforce CSRF token requirement.
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		header('Location: index.php');
		exit();
	}
	// CSRF check — must come before any state mutation.
	csrfCheck();
	// Rate limit visitor account creation: 3 per 5 minutes per IP
	if (!rateLimitCheck($_SERVER['REMOTE_ADDR'], 'visitor_reg', 3, 300)) {
		header('Location: index.php?att=1');
		exit();
	}
	// Atomic read-and-increment using LAST_INSERT_ID to prevent TOCTOU race
	dbExecute($base, 'UPDATE statistiques SET numerovisiteur = LAST_INSERT_ID(numerovisiteur) + 1');
	$visitorNumRow = dbFetchOne($base, 'SELECT LAST_INSERT_ID() AS num');
	$visitorNum = $visitorNumRow['num']; // original value before increment
	$log = 'Visiteur' . ($visitorNum - 1);

	// C-002: Wrap the stale check + INSERT in a transaction with a FOR UPDATE lock so that
	// two concurrent requests checking the same previous-slot row cannot both proceed to create
	// a new visitor account. Only the request that acquires the row lock first will proceed.
	$shouldCreate = false;
	withTransaction($base, function() use ($base, $log, &$shouldCreate) {
		// Lock the previous visitor's row (or nothing if it doesn't exist yet)
		$time = dbFetchOne($base, 'SELECT timestamp, estExclu FROM membre WHERE login = ? FOR UPDATE', 's', $log);
		if ((!$time || time() - $time['timestamp'] > 1800) && (!$time || $time['estExclu'] == 0)) { // H-015: 30 min threshold — only recreate truly stale, non-banned visitors
			$shouldCreate = true;
		}
	});

	if ($shouldCreate) {
		inscrire("Visiteur" . $visitorNum, "Visiteur" . $visitorNum, "Visiteur" . $visitorNum . "@tvlw.com");
		session_regenerate_id(true);
		$_SESSION['login'] = ucfirst(mb_strtolower("Visiteur" . $visitorNum));
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
		$_SESSION['session_created'] = time();
		$_SESSION['last_activity'] = time();
		// LOW-001: Use a random password for visitor accounts (not predictable username-as-password).
		$visitorPass = bin2hex(random_bytes(8));
		$hashedPass = password_hash($visitorPass, PASSWORD_DEFAULT);
		$affected1 = dbExecute($base, 'UPDATE membre SET pass_md5 = ? WHERE login = ?', 'ss', $hashedPass, "Visiteur" . $visitorNum);
		if ($affected1 === false) {
			header('Location: index.php?att=1');
			exit();
		}
		$sessionToken = bin2hex(random_bytes(32));
		$_SESSION['session_token'] = $sessionToken;
		$affected2 = dbExecute($base, 'UPDATE membre SET session_token = ? WHERE login = ?', 'ss', $sessionToken, "Visiteur" . $visitorNum);
		if ($affected2 === false) {
			header('Location: index.php?att=1');
			exit();
		}
		require_once('includes/multiaccount.php');
		try { logLoginEvent($base, "Visiteur" . $visitorNum, 'visitor_create'); } catch (\Exception $e) { logWarn('COMPTETEST', 'logLoginEvent failed on visitor create', ['error' => $e->getMessage()]); } // AUTH-P26-007
		header('Location: tutoriel.php?deployer=1');
		exit();
	} else {
		header('Location: index.php?att=1');
		exit();
	}
} else {
	// Must be logged in as a visitor to rename
	if (!isset($_SESSION['login']) || !preg_match('/^Visiteur[0-9]+$/i', $_SESSION['login'])) {
		header('Location: index.php');
		exit();
	}
	// CSRF check for POST registration
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		csrfCheck();
	}

	$erreur = '';
	//Si les champs sont vides
	if ((isset($_POST['login']) && !empty($_POST['login'])) && (isset($_POST['pass']) && !empty($_POST['pass'])) && (isset($_POST['pass_confirm']) && !empty($_POST['pass_confirm'])) && (isset($_POST['email']) && !empty($_POST['email']))) {
		// AUTH-M1: Use shared validatePassword() for consistent password validation.
		$passErrors = validatePassword($_POST['pass'], $_POST['pass_confirm']);
		if (!empty($passErrors)) {
			$erreur = $passErrors[0];
		} else {
			if (!validateLogin($_POST['login'])) {
				$erreur = 'Le login doit contenir entre ' . LOGIN_MIN_LENGTH . ' et ' . LOGIN_MAX_LENGTH . ' caractères alphanumériques.';
			} else {
				if (validateEmail($_POST['email'])) {
					$email = strtolower(trim($_POST['email']));
					$nbMail = dbCount($base, 'SELECT COUNT(*) AS nb FROM membre WHERE email = ?', 's', $email);
					if ($nbMail > 0) {
						$erreur = 'L\'email est déjà utilisé.';
					} else {
						$_POST['login'] = ucfirst(mb_strtolower($_POST['login']));
						$loginClean = $_POST['login'];

						$data = dbFetchOne($base, 'SELECT count(*) AS cnt FROM membre WHERE login = ?', 's', $loginClean);
						//Si le login est déjà utilisé
						if ($data['cnt'] == 0) {
							$oldLogin = $_SESSION['login'];
							$newLogin = $loginClean;
						// Use password_hash instead of MD5
						$hashedPassword = password_hash($_POST['pass'], PASSWORD_DEFAULT);

						// Wrap all 15 table renames in a transaction for atomicity
						$sessionToken = bin2hex(random_bytes(32));
						withTransaction($base, function() use ($base, $newLogin, $oldLogin, $hashedPassword, $email, $sessionToken) {
							// Lock source row to prevent concurrent renames (GAP-013)
							$locked = dbFetchOne($base, 'SELECT login, estExclu FROM membre WHERE login = ? FOR UPDATE', 's', $oldLogin);
							if (!$locked) {
								throw new \RuntimeException('Source account not found');
							}
							// Fix 3: Refuse conversion for banned visitor accounts
							if ($locked['estExclu'] == 1) {
								throw new \RuntimeException('BANNED');
							}
							// Fix 4: Re-check login uniqueness inside the transaction (TOCTOU guard)
							$loginConflict = dbFetchOne($base, 'SELECT login FROM membre WHERE login = ? FOR UPDATE', 's', $newLogin);
							if ($loginConflict) {
								throw new \RuntimeException('LOGIN_TAKEN');
							}
							dbExecute($base, 'UPDATE autre SET login = ? WHERE login = ?', 'ss', $newLogin, $oldLogin);
							dbExecute($base, 'UPDATE grades SET login = ? WHERE login = ?', 'ss', $newLogin, $oldLogin);
							dbExecute($base, 'UPDATE constructions SET login = ? WHERE login = ?', 'ss', $newLogin, $oldLogin);
							dbExecute($base, 'UPDATE invitations SET invite = ? WHERE invite = ?', 'ss', $newLogin, $oldLogin);
							// Fix 5: Check that the critical membre UPDATE actually succeeded
							$affected = dbExecute($base, 'UPDATE membre SET login = ?, pass_md5 = ?, email = ?, session_token = ? WHERE login = ?', 'sssss', $newLogin, $hashedPassword, $email, $sessionToken, $oldLogin);
							if ($affected === false) {
								throw new \RuntimeException('DB_ERROR');
							}
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
							dbExecute($base, 'UPDATE prestige SET login = ? WHERE login = ?', 'ss', $newLogin, $oldLogin);
							// P28-CRIT-003: attack_cooldowns uses 'attacker'/'defender' columns, not 'login'
							dbExecute($base, 'UPDATE attack_cooldowns SET attacker = ? WHERE attacker = ?', 'ss', $newLogin, $oldLogin);
							dbExecute($base, 'UPDATE attack_cooldowns SET defender = ? WHERE defender = ?', 'ss', $newLogin, $oldLogin);
							dbExecute($base, 'UPDATE actionsattaques SET attaquant = ? WHERE attaquant = ?', 'ss', $newLogin, $oldLogin);
							dbExecute($base, 'UPDATE actionsattaques SET defenseur = ? WHERE defenseur = ?', 'ss', $newLogin, $oldLogin);
							dbExecute($base, 'UPDATE actionsformation SET login = ? WHERE login = ?', 'ss', $newLogin, $oldLogin);
							dbExecute($base, 'UPDATE actionsconstruction SET login = ? WHERE login = ?', 'ss', $newLogin, $oldLogin);
							dbExecute($base, 'UPDATE actionsenvoi SET envoyeur = ? WHERE envoyeur = ?', 'ss', $newLogin, $oldLogin);
							dbExecute($base, 'UPDATE actionsenvoi SET receveur = ? WHERE receveur = ?', 'ss', $newLogin, $oldLogin);
							dbExecute($base, 'UPDATE player_compounds SET login = ? WHERE login = ?', 'ss', $newLogin, $oldLogin);
							dbExecute($base, 'UPDATE login_history SET login = ? WHERE login = ?', 'ss', $newLogin, $oldLogin);
							dbExecute($base, 'UPDATE account_flags SET login = ? WHERE login = ?', 'ss', $newLogin, $oldLogin);
							dbExecute($base, 'UPDATE account_flags SET related_login = ? WHERE related_login = ?', 'ss', $newLogin, $oldLogin);
							dbExecute($base, 'UPDATE autre SET niveaututo = 8, streak_last_date = CURDATE(), streak_days = 0 WHERE login = ?', 's', $newLogin);
						});

						session_regenerate_id(true);
						$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
						$_SESSION['session_created'] = time();
						$_SESSION['last_activity'] = time();
						$_SESSION['login'] = $newLogin;
						$_SESSION['session_token'] = $sessionToken;

						require_once('includes/multiaccount.php');
						try { logLoginEvent($base, $newLogin, 'visitor_convert'); } catch (\Exception $e) { logWarn('COMPTETEST', 'logLoginEvent failed on visitor convert', ['error' => $e->getMessage()]); } // AUTH-P26-008
						header("Location: index.php?inscrit=1"); exit;
						} else {
							$erreur = 'Ce login est déjà utilisé.';
						}
					} // end email-not-duplicate check
				} else {
					$erreur = 'L\'email n\'est pas correct.';
				}
			}
		}
	} else {
		$erreur = 'Un ou plusieurs champs sont vides.';
	}
    if (!empty($erreur)) {
        header("Location: tutoriel.php?erreur=" . urlencode($erreur)); exit;
    }
}
