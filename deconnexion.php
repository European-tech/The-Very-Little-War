<?php
require_once("includes/session_init.php");
require_once("includes/csp.php");
include("includes/connexion.php");
include("includes/fonctions.php");
require_once("includes/csrf.php");

// Validate session token before any destructive action to prevent CSRF-triggered logouts
if (isset($_SESSION['login']) && isset($_SESSION['session_token'])) {
	$tokenDb = dbFetchOne($base, 'SELECT session_token FROM membre WHERE login = ?', 's', $_SESSION['login']);
	if (!$tokenDb || !hash_equals((string)$tokenDb['session_token'], (string)$_SESSION['session_token'])) {
		session_unset();
		session_destroy();
		header('Location: index.php');
		exit;
	}
}

// AUTH11-001: Require POST + CSRF for logout/deletion to prevent CSRF logout via GET link.
// A bare GET to deconnexion.php (e.g. via <img> tag) must not destroy the session.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	header('Location: index.php');
	exit;
}
csrfCheck();

// Account deletion: only after CSRF + session token DB validation above
// Server-side cooldown re-check: enforce 7-day minimum regardless of how this endpoint is reached.
// This prevents bypassing compte.php's form guard by POSTing directly to deconnexion.php.
if(isset($_POST['verification']) AND isset($_POST['oui'])) {
	if (isset($_SESSION['login'])) {
		$memberTimestamp = dbFetchOne($base, 'SELECT timestamp FROM membre WHERE login = ?', 's', $_SESSION['login']);
		if ($memberTimestamp && (time() - (int)$memberTimestamp['timestamp']) > SECONDS_PER_WEEK) {
			supprimerJoueur($_SESSION['login']);
		}
		// If cooldown not met, silently skip deletion and proceed to normal logout below.
	}
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
	// AUTH-P30-C001: Use array-format setcookie to include SameSite=Strict
	setcookie(session_name(), '', array_merge($params, ['expires' => time() - 42000]));
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
