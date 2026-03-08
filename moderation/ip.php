<?php
// P9-HIGH-009: Use standard moderation auth guard (replaces legacy mdp.php include)
include("redirectionmotdepasse.php");
include("../includes/connexion.php");
require_once("../includes/database.php");
require_once("../includes/multiaccount.php");
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
$ip = isset($_GET['ip']) ? $_GET['ip'] : '';
echo '<h4>Pseudos avec l\'ip '.htmlspecialchars($ip, ENT_QUOTES, 'UTF-8').'\'</h4><p>';

// P9-HIGH-007: IPs are stored as HMAC-SHA256 hashes — hash the lookup value before querying
$hashedIp = hashIpAddress($ip);
$ipMembreRows = dbFetchAll($base, 'SELECT * FROM membre WHERE ip = ?', 's', $hashedIp);
foreach ($ipMembreRows as $donnees) {
	echo '<a href="../joueur.php?id='.htmlspecialchars($donnees['login'], ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($donnees['login'], ENT_QUOTES, 'UTF-8').'</a><br/>';
}
echo '</p>';
?>
</div>
</div>
</body>
</html>
