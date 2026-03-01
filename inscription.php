<?php
include("includes/basicpublicphp.php");
require_once("includes/csrf.php");
require_once("includes/validation.php");

// Start a fresh session for CSRF token generation on registration page
session_start();

//Si le bouton inscription a ete clique
if (isset($_POST['login'])) {
	// Rate limit: 3 registrations per hour per IP
	if (!rateLimitCheck($_SERVER['REMOTE_ADDR'], 'register', 3, 3600)) {
		die('<p>Trop d\'inscriptions depuis cette adresse. Réessayez plus tard.</p>');
	}

	// CSRF check
	csrfCheck();

	//Si les champs sont vides
	if ((isset($_POST['login']) && !empty($_POST['login'])) && (isset($_POST['pass']) && !empty($_POST['pass'])) && (isset($_POST['pass_confirm']) && !empty($_POST['pass_confirm'])) && (isset($_POST['email']) && !empty($_POST['email']))) {
		//Si les deux mots de passe sont differents
		$loginInput = ucfirst(mb_strtolower(antiXSS($_POST['login'])));
		$passInput = $_POST['pass'];
		$passConfirm = $_POST['pass_confirm'];
		$emailInput = antiXSS($_POST['email']);

		if ($passInput != $passConfirm) {
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
						inscrire($loginInput, $passInput, $emailInput);
						logInfo('REGISTER', 'New player registered', ['login' => $loginInput, 'email' => $emailInput]);
						echo '<script type="text/javascript">
						window.location.href = "index.php?inscrit=1";
						</script>';
						exit();
					} else {
						$erreur = 'Ce login est d&eacute;j&agrave; utilis&eacute;.';
					}
				}
			}
		}
	} else {
		$erreur = 'Un ou plusieurs champs sont vides.';
	}
}
include("includes/tout.php");
debutCarte("Inscription");
echo '<form action="inscription.php" method="post" name="inscription">';
echo csrfField();
debutListe();
item(['floating' => true, 'media' => '<img alt="login" src="images/accueil/player.png" class="w32"/>', 'titre' => 'Login', 'input' => '<input type="text" name="login" id="login" maxlength="13" value="Login">', 'after' => submit(['link' => 'javascript:generate()', 'titre' => 'G&eacute;n&eacute;rer'])]);
item(['floating' => true, 'media' => '<img alt="login" src="images/accueil/email.png" class="w32"/>', 'titre' => 'E-mail', 'input' => '<input type="text" name="email" id="email" maxlength="100" class="form-control">', 'after' => popover('popover-mail', 'images/question.png')]);
item(['floating' => true, 'media' => '<img alt="login" src="images/accueil/door-key.png" class="w32"/>', 'titre' => 'Mot de passe', 'input' => '<input type="password" name="pass" id="pass" class="form-control">']);
item(['floating' => true, 'media' => '<img alt="login" src="images/accueil/door-key.png" class="w32"/>', 'titre' => 'Confirmation', 'input' => '<input type="password" name="pass_confirm" id="pass_confirm" class="form-control">']);
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
