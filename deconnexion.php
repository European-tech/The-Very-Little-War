<?php
require_once("includes/session_init.php");
require_once("includes/csp.php");
include("includes/connexion.php");
include("includes/fonctions.php");
require_once("includes/csrf.php");
if(isset($_POST['verification']) AND isset($_POST['oui'])) {
	csrfCheck();
	supprimerJoueur($_SESSION['login']);
}

// Clear session token from DB to prevent reuse
if (isset($_SESSION['login'])) {
	dbExecute($base, 'UPDATE membre SET session_token = NULL WHERE login = ?', 's', $_SESSION['login']);
}

session_unset();
session_destroy();

// Clear session cookie to prevent reuse of old session ID
if (ini_get("session.use_cookies")) {
	$params = session_get_cookie_params();
	setcookie(session_name(), '', time() - 42000,
		$params["path"], $params["domain"],
		$params["secure"], $params["httponly"]
	);
}

// CSP header must be emitted before any HTML output
$nonce = cspNonce();
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self';");
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<?php include("includes/meta.php"); ?>
<title>The Very Little War - Deconnexion</title>
</head>
<body>

<script nonce="<?php echo htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8'); ?>">
    localStorage.removeItem("login");
    window.location = "index.php";
</script>
</body>
