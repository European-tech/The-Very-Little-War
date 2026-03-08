<?php
include("redirectionmotdepasse.php");
require_once(__DIR__ . '/../includes/csp.php');
$nonce = cspNonce();
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self';");
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
require_once(__DIR__ . '/../includes/multiaccount.php');
$ip = isset($_GET['ip']) ? trim($_GET['ip']) : '';
echo '<h4>Pseudos avec l\'ip '.htmlspecialchars($ip, ENT_QUOTES, 'UTF-8').'\'<p>';

// IP addresses are stored as hashed values in the membre table (via hashIpAddress())
// Must hash the input IP before querying to get matching results
$hashedIp = $ip !== '' ? hashIpAddress($ip) : '';
$ipMembreRows = $hashedIp ? dbFetchAll($base, 'SELECT * FROM membre WHERE ip = ?', 's', $hashedIp) : [];
foreach ($ipMembreRows as $donnees) {
	echo '<a href="../joueur.php?id='.htmlspecialchars($donnees['login'], ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($donnees['login'], ENT_QUOTES, 'UTF-8').'</a><br/>';
}
echo '</p>';
?>
</body>
</html>
