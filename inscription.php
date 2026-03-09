<?php
include("includes/basicpublicphp.php");
require_once("includes/csrf.php");
require_once("includes/validation.php");

//Si le bouton inscription a ete clique
if (isset($_POST['login'])) {
	// CSRF check must come before rate limit to prevent forged requests from exhausting limits
	csrfCheck();

	// Rate limit: 3 registrations per hour per IP
	if (!rateLimitCheck($_SERVER['REMOTE_ADDR'], 'register', RATE_LIMIT_REGISTER_MAX, RATE_LIMIT_REGISTER_WINDOW)) {
		logWarn('REGISTER', 'Registration rate limited', ['ip_hash' => substr(hash_hmac('sha256', ($_SERVER['REMOTE_ADDR'] ?? ''), (defined('SECRET_SALT') ? SECRET_SALT : 'tvlw_salt')), 0, 12)]);
		$erreur = 'Trop d\'inscriptions depuis cette adresse. R&eacute;essayez plus tard.';
	} else {

	//Si les champs sont vides
	if ((isset($_POST['login']) && !empty($_POST['login'])) && (isset($_POST['pass']) && !empty($_POST['pass'])) && (isset($_POST['pass_confirm']) && !empty($_POST['pass_confirm'])) && (isset($_POST['email']) && !empty($_POST['email']))) {
		//Si les deux mots de passe sont differents
		// LOW-035: Normalize login (first letter uppercase, rest lowercase, trimmed).
		// The normalized value is what will be stored and displayed to the user.
		$loginInput = ucfirst(mb_strtolower(trim($_POST['login'])));
		$loginNormalized = $loginInput; // alias for display in error/success messages
		$passInput = $_POST['pass'];
		$passConfirm = $_POST['pass_confirm'];
		// M-005: Normalize email to lowercase for case-insensitive uniqueness check and storage
		$emailInput = strtolower(trim($_POST['email']));

		// MEDIUM-027: Use shared validatePassword() from validation.php
		$passErrors = validatePassword($passInput, $passConfirm);
		if (!empty($passErrors)) {
			$erreur = htmlspecialchars($passErrors[0], ENT_QUOTES, 'UTF-8');
		} else {
			if (!validateLogin($loginInput)) {
				$erreur = 'Vous ne pouvez pas utiliser de caract&egrave;res sp&eacute;ciaux dans votre login (3-20 caract&egrave;res alphanum&eacute;riques).';
				$erreur .= ' <strong>Votre identifiant sera : ' . htmlspecialchars($loginNormalized, ENT_QUOTES, 'UTF-8') . '</strong>';
			} elseif (stripos($loginInput, 'Visiteur') === 0) {
				// FLOW-REG-MEDIUM-001: Reserve the "Visiteur" prefix for the visitor/guest account
				// namespace used by comptetest.php. Cleanup routines delete accounts matching
				// "Visiteur%" — a real player registering under that prefix would be wiped.
				$erreur = 'Ce pseudo est r&eacute;serv&eacute;.';
			} elseif (!validateEmail($emailInput)) {
				$erreur = 'L\'email n\'est pas correct.';
			} else {
				// FLOW-REG-MEDIUM-003: This SELECT COUNT is an early-exit optimisation only.
				// The UNIQUE KEY `uq_membre_email` on membre.email (migration 0101) is the
				// authoritative guard against concurrent duplicate registrations — inscrire()
				// catches the duplicate-key error and returns 'email_taken'.
				$nbMail = dbCount($base, 'SELECT COUNT(*) AS nb FROM membre WHERE email = ?', 's', $emailInput);
				if ($nbMail > 0) {
					$erreur = 'Ce login ou email est d&eacute;j&agrave; utilis&eacute;.';
				} else {
					$nbLogin = dbCount($base, 'SELECT COUNT(*) FROM membre WHERE login = ?', 's', $loginInput);
					//Si le login est deja utilise
					if ($nbLogin == 0) {
						// inscrire() returns true on success, 'login_taken' / 'email_taken' when the
						// DB UNIQUE constraint fires (catches concurrent registrations), false on error.
						$result = inscrire($loginInput, $passInput, $emailInput);
						if ($result === true) {
							logInfo('REGISTER', 'New player registered', ['login' => $loginInput, 'ip_hash' => substr(hash_hmac('sha256', ($_SERVER['REMOTE_ADDR'] ?? ''), (defined('SECRET_SALT') ? SECRET_SALT : 'tvlw_salt')), 0, 12)]);
							require_once('includes/multiaccount.php');
							// M-002: Wrap logLoginEvent so a logging failure never aborts the post-registration redirect.
							try { logLoginEvent($base, $loginInput, 'register'); } catch (\Exception $e) { logWarn('REGISTER', 'logLoginEvent failed', ['error' => $e->getMessage()]); }
							// H-006: Regenerate session ID after successful registration to prevent session fixation.
							// An attacker who planted a known session ID before the victim registered can no longer
							// reuse it after this point.
							session_regenerate_id(true);
							$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
							$_SESSION['session_created'] = time();
							$_SESSION['last_activity'] = time();
							header("Location: index.php?inscrit=1"); exit;
						} elseif ($result === 'email_taken') {
							$erreur = 'Ce login ou email est d&eacute;j&agrave; utilis&eacute;.';
						} elseif ($result === 'login_taken') {
							$erreur = 'Ce login est d&eacute;j&agrave; utilis&eacute;.';
						} else {
							$erreur = 'Une erreur est survenue lors de l\'inscription. Veuillez r&eacute;essayer.';
						}
					} else {
						$erreur = 'Ce login est d&eacute;j&agrave; utilis&eacute;.';
					}
				}
			}
		}
	} else {
		$erreur = 'Un ou plusieurs champs sont vides.';
	}
	} // end rate limit else
}
include("includes/layout.php");
debutCarte("Inscription");
// LOW-035: If a login was submitted, show the normalized form so the user knows what will be stored.
if (!empty($_POST['login'])) {
    $loginPreview = ucfirst(mb_strtolower(trim($_POST['login'])));
    echo '<p style="margin:8px 5px;color:#1565c0;font-size:13px;">Votre identifiant sera : <strong>' . htmlspecialchars($loginPreview, ENT_QUOTES, 'UTF-8') . '</strong></p>';
}
echo '<form action="inscription.php" method="post" name="inscription">';
echo csrfField();
debutListe();
item(['floating' => true, 'media' => '<img alt="login" src="images/accueil/player.png" class="w32"/>', 'titre' => 'Login', 'input' => '<input type="text" name="login" id="login" maxlength="' . LOGIN_MAX_LENGTH . '" value="Login">', 'after' => submit(['link' => '#', 'titre' => 'G&eacute;n&eacute;rer', 'id' => 'btn-generate'])]);
item(['floating' => true, 'media' => '<img alt="login" src="images/accueil/email.png" class="w32"/>', 'titre' => 'E-mail', 'input' => '<input type="text" name="email" id="email" maxlength="' . EMAIL_MAX_LENGTH . '" class="form-control">', 'after' => popover('popover-mail', 'images/question.png')]);
item(['floating' => true, 'media' => '<img alt="login" src="images/accueil/door-key.png" class="w32"/>', 'titre' => 'Mot de passe', 'input' => '<input type="password" name="pass" id="pass" maxlength="' . PASSWORD_BCRYPT_MAX_LENGTH . '" class="form-control" autocomplete="new-password">']);
item(['floating' => true, 'media' => '<img alt="login" src="images/accueil/door-key.png" class="w32"/>', 'titre' => 'Confirmation', 'input' => '<input type="password" name="pass_confirm" id="pass_confirm" maxlength="' . PASSWORD_BCRYPT_MAX_LENGTH . '" class="form-control" autocomplete="new-password">']);
echo '<p style="margin-left:5px">En vous inscrivant vous acceptez nos <a href="regles.php" class="external lien lienVisible">Conditions G&eacute;n&eacute;rales d\'Utilisation</a></p>';
item(['input' => submit(['form' => 'inscription', 'titre' => 'Inscription'])]);
finListe();
echo '</form>';
finCarte();
?>

<div class="popover popover-mail">
	<div class="popover-angle"></div>
	<div class="popover-inner">
		<div class="content-block">
			<p>Cette adresse sera utilis&eacute;e pour recevoir les <span class="important">notifications de fin de saison</span>.<br /> Elle peut &ecirc;tre chang&eacute;e dans "Mon compte".</p>
		</div>
	</div>
</div>
<?php include("includes/copyright.php"); ?>
