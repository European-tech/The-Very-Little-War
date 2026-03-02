<?php
include("../includes/connexion.php");
include("redirectionmotdepasse.php");
include("../includes/fonctions.php");
require_once(__DIR__ . '/../includes/csrf.php');

$joueurExiste = 0;

if (isset($_POST['supprimercompte'])) {
    csrfCheck();
    $joueurExiste = dbCount($base, 'SELECT count(*) FROM membre WHERE login = ?', 's', $_POST['supprimer']);

if($joueurExiste > 0 ) {
		supprimerJoueur($_POST['supprimer']);
	}
	else {
		echo "Ce joueur n'existe pas.";
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
