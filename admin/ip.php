<?php
include("redirectionmotdepasse.php");
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta http-equiv="content-type" content="text/html; charset=utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>The Very Little War - Ip multiple</title>
</head>
<body>
<?php
include("../includes/connexion.php");
$ip = isset($_GET['ip']) ? $_GET['ip'] : '';
echo '<h4>Pseudos avec l\'ip '.htmlspecialchars($ip, ENT_QUOTES, 'UTF-8').'\'<p>';

$ipMembreRows = dbFetchAll($base, 'SELECT * FROM membre WHERE ip = ?', 's', $ip);
foreach ($ipMembreRows as $donnees) {
	echo '<a href="../joueur.php?id='.htmlspecialchars($donnees['login'], ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($donnees['login'], ENT_QUOTES, 'UTF-8').'</a><br/>';
}
echo '</p>';
?>
</body>
</html>
