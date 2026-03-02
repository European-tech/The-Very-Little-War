<?php
include("mdp.php");
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<title>The Very Little War - Ip multiple</title>
<link rel="stylesheet" type="text/css" href="../style.css" >
</head>
<body>
<div class="panel panel-default margin-10 text-center pattern-bg">
<div class="panel-heading">
<h4>Multicomptes</h4></div>
<div class="panel-body">
<?php
include("../includes/connexion.php");
$ip = isset($_GET['ip']) ? $_GET['ip'] : '';
echo '<h4>Pseudos avec l\'ip '.htmlspecialchars($ip, ENT_QUOTES, 'UTF-8').'\'</h4><p>';

$retour = dbQuery($base, 'SELECT * FROM membre WHERE ip = ?', 's', $ip);
while ($donnees = mysqli_fetch_array($retour)) {
	echo '<a href="../joueur.php?id='.htmlspecialchars($donnees['login'], ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($donnees['login'], ENT_QUOTES, 'UTF-8').'</a><br/>';
}
echo '</p>';
?>
</div>
</div>
</body>
</html>
