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
require_once(__DIR__ . '/../includes/csrf.php');

// Accept IP via POST only (no GET fallback — avoids leaking values in URL/referer/logs).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo '<p>Méthode non autorisée.</p></body></html>';
    exit;
}
csrfCheck();

$rawIp = isset($_POST['ip']) ? trim($_POST['ip']) : '';
// Accept either a real IP address OR a 64-character hex hash (pre-hashed IP from admin UI)
$hashedIp = '';
$erreur = '';
if (filter_var($rawIp, FILTER_VALIDATE_IP)) {
    // Real IP — hash it for lookup
    $hashedIp = hashIpAddress($rawIp);
} elseif (preg_match('/^[0-9a-f]{64}$/', $rawIp)) {
    // Already hashed — use directly
    $hashedIp = $rawIp;
} else {
    $hashedIp = '';
    $erreur = 'Adresse IP invalide.';
}

if ($erreur !== '') {
    http_response_code(400);
    echo '<p>' . htmlspecialchars($erreur, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
    exit;
}

echo '<h4>Pseudos avec l\'ip ' . htmlspecialchars($rawIp, ENT_QUOTES, 'UTF-8') . '\'<p>';

// IP addresses are stored as hashed values in the membre table (via hashIpAddress())
$ipMembreRows = $hashedIp ? dbFetchAll($base, 'SELECT login, email, dateInscription, derniereConnexion, ip FROM membre WHERE ip = ?', 's', $hashedIp) : [];
foreach ($ipMembreRows as $donnees) {
	echo '<a href="../joueur.php?id='.htmlspecialchars($donnees['login'], ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($donnees['login'], ENT_QUOTES, 'UTF-8').'</a><br/>';
}
echo '</p>';
?>
</body>
</html>
