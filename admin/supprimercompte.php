<?php
include("../includes/connexion.php");
include("redirectionmotdepasse.php");
require_once(__DIR__ . '/../includes/database.php');
// P10-HIGH-001: Require ADMIN_LOGIN session match in addition to the password gate
if (!isset($_SESSION['login']) || $_SESSION['login'] !== ADMIN_LOGIN) {
    header('Location: ../index.php');
    exit;
}
include("../includes/fonctions.php");
require_once(__DIR__ . '/../includes/csrf.php');
require_once(__DIR__ . '/../includes/logger.php');
require_once(__DIR__ . '/../includes/csp.php');
$nonce = cspNonce();
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self';");

$joueurExiste = 0;

if (isset($_POST['supprimercompte'])) {
    csrfCheck();
    // Validate login input (P5-GAP-006)
    $loginToDelete = trim($_POST['supprimer'] ?? '');
    if (empty($loginToDelete) || strlen($loginToDelete) > 20 || !preg_match('/^[a-zA-Z0-9_-]+$/', $loginToDelete)) {
        echo "Login invalide.";
    } else {
        // LOW-018: Use FOR UPDATE inside a transaction to eliminate TOCTOU between existence
        // check and deletion. The row lock prevents concurrent deletion or modification.
        withTransaction($base, function() use ($base, $loginToDelete) {
            global $joueurExiste;
            $exists = dbFetchOne($base, 'SELECT 1 FROM membre WHERE login = ? FOR UPDATE', 's', $loginToDelete);
            if ($exists) {
                $joueurExiste = 1;
                supprimerJoueur($loginToDelete);
                logInfo('ADMIN', 'Player account deleted', ['target_login' => $loginToDelete, 'admin' => $_SESSION['login']]);
            } else {
                $joueurExiste = 0;
            }
        });
        if (!$joueurExiste) {
            echo "Ce joueur n'existe pas.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<title>Neocrea - Supprimmer un compte</title>
<meta http-equiv="content-type" content="text/html; charset=utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body>

<h3>Supprimer un compte</h3><br/>
<form method = "post" action = "supprimercompte.php">
<?php echo csrfField(); ?>
<p>
Login : <input type="text" name="supprimer"><br />
<input type="submit" name="supprimercompte" value="Supprimer le compte">
</form>
<?php
if (isset($_POST['supprimercompte']))
{
	if ($_POST['supprimercompte'] == "Supprimer le compte")
	{
		if($joueurExiste > 0 ) {
			echo "Vous avez supprimer le compte " . htmlspecialchars($_POST['supprimer'], ENT_QUOTES, 'UTF-8') . ".";
		}
	}
}
?>
</p>

</body>
</html>
