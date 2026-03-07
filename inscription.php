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
		logWarn('REGISTER', 'Registration rate limited', ['ip' => $_SERVER['REMOTE_ADDR']]);
		$erreur = 'Trop d\'inscriptions depuis cette adresse. R&eacute;essayez plus tard.';
	} else {

	//Si les champs sont vides
	if ((isset($_POST['login']) && !empty($_POST['login'])) && (isset($_POST['pass']) && !empty($_POST['pass'])) && (isset($_POST['pass_confirm']) && !empty($_POST['pass_confirm'])) && (isset($_POST['email']) && !empty($_POST['email']))) {
		//Si les deux mots de passe sont differents
		$loginInput = ucfirst(mb_strtolower(trim($_POST['login'])));
		$passInput = $_POST['pass'];
		$passConfirm = $_POST['pass_confirm'];
		$emailInput = trim($_POST['email']);

		if (mb_strlen($passInput) > PASSWORD_BCRYPT_MAX_LENGTH) {
			$erreur = 'Le mot de passe est trop long (' . PASSWORD_BCRYPT_MAX_LENGTH . ' caract&egrave;res max).';
		} elseif (mb_strlen($passInput) < PASSWORD_MIN_LENGTH) {
			$erreur = 'Le mot de passe doit contenir au moins ' . PASSWORD_MIN_LENGTH . ' caract&egrave;res.';
		} elseif ($passInput != $passConfirm) {
			$erreur = 'Les deux mots de passe sont diff&eacute;rents.';
		} else {
			if (!validateLogin($loginInput)) {
				$erreur = 'Vous ne pouvez pas utiliser de caract&egrave;res sp&eacute;ciaux dans votre login (3-20 caract&egrave;res alphanum&eacute;riques).';
			} elseif (!validateEmail($emailInput)) {
				$erreur = 'L\'email n\'est pas correct.';
			} else {
				$nbMail = dbCount($base, 'SELECT COUNT(*) AS nb FROM membre WHERE email = ?', 's', $emailInput);
				if ($nbMail > 0) {
					$erreur = 'L\'email est d&eacute;j&agrave; utilis&eacute;.';
				} else {
					$nbLogin = dbCount($base, 'SELECT COUNT(*) FROM membre WHERE login = ?', 's', $loginInput);
					//Si le login est deja utilise
					if ($nbLogin == 0) {
						// inscrire() returns true on success, 'login_taken' / 'email_taken' when the
						// DB UNIQUE constraint fires (catches concurrent registrations), false on error.
						$result = inscrire($loginInput, $passInput, $emailInput);
						if ($result === true) {
							logInfo('REGISTER', 'New player registered', ['login' => $loginInput, 'ip' => $_SERVER['REMOTE_ADDR']]);
							require_once('includes/multiaccount.php');
							logLoginEvent($base, $loginInput, 'register');
							header("Location: index.php?inscrit=1"); exit;
						} elseif ($result === 'email_taken') {
							$erreur = 'L\'email est d&eacute;j&agrave; utilis&eacute;.';
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
echo '<form action="inscription.php" method="post" name="inscription">';
echo csrfField();
debutListe();
item(['floating' => true, 'media' => '<img alt="login" src="images/accueil/player.png" class="w32"/>', 'titre' => 'Login', 'input' => '<input type="text" name="login" id="login" maxlength="' . LOGIN_MAX_LENGTH . '" value="Login">', 'after' => submit(['link' => '#', 'titre' => 'G&eacute;n&eacute;rer', 'id' => 'btn-generate'])]);
item(['floating' => true, 'media' => '<img alt="login" src="images/accueil/email.png" class="w32"/>', 'titre' => 'E-mail', 'input' => '<input type="text" name="email" id="email" maxlength="100" class="form-control">', 'after' => popover('popover-mail', 'images/question.png')]);
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
			<p>Un mail sera envoy&eacute; &agrave; cette adresse pour <span class="important">confirmer votre inscription</span> et vous pr&eacute;venir du d&eacute;but d'une nouvelle partie.<br /> Il peut &ecirc;tre chang&eacute; dans "Mon compte".</p>
		</div>
	</div>
</div>
<?php include("includes/copyright.php"); ?>
