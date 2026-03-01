<?php
include("redirectionmotdepasse.php");
?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="fr" >
<head>
<meta http-equiv="content-type" content="text/html; charset=utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>The Very Little War - Ip multiple</title>
<!--[if IE]><script src="http://html5shiv.googlecode.com/svn/trunk/html5.js"></script><![endif]-->
</head>
<body>
<?php
include("../includes/connexion.php");
$ip = isset($_GET['ip']) ? $_GET['ip'] : '';
echo '<h4>Pseudos avec l\'ip '.htmlspecialchars($ip, ENT_QUOTES, 'UTF-8').'\'<p>';

$retour = dbQuery($base, 'SELECT * FROM membre WHERE ip = ?', 's', $ip);
while ($donnees = mysqli_fetch_array($retour)) {
	echo '<a href="../joueur.php?id='.htmlspecialchars($donnees['login'], ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($donnees['login'], ENT_QUOTES, 'UTF-8').'</a><br/>';
}
echo '</p>';
?>
</body>
</html>
