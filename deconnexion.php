<?php
require_once("includes/session_init.php");
include("includes/connexion.php");
include("includes/fonctions.php");
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
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="fr" >
<head>
<?php include("includes/meta.php"); ?>
<title>The Very Little War - Deconnexion</title>
</head>
<body>

<script>
    localStorage.removeItem("login");
    window.location = "index.php";
</script>
</body>