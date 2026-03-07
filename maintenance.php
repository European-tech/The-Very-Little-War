<?php
require_once("includes/session_init.php");
include("includes/connexion.php");
include("includes/fonctions.php");
require_once("includes/csp.php");
require_once("includes/constantesBase.php");

// LOW-024: Restrict maintenance page to admin only.
// Non-admin visitors are shown a 403 to avoid information disclosure.
if (!isset($_SESSION['login']) || $_SESSION['login'] !== ADMIN_LOGIN) {
    http_response_code(403);
    exit('Accès refusé.');
}

include("includes/layout.php");
debutCarte("Maintenance");
$donnees = dbFetchOne($base, 'SELECT * FROM news ORDER BY id DESC LIMIT 0, 1');
if (!$donnees) {
    echo '<p>Aucune annonce disponible.</p>';
    finCarte();
    include("includes/copyright.php");
    exit;
}
$allowedTags = '<a><br><br/><strong><b><i><em><p><div><span><img><hr>';
$contenu = strip_tags($donnees['contenu'], $allowedTags);
// Strip event handlers and dangerous attributes (P5-GAP-012)
$contenu = preg_replace('/\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $contenu);
$contenu = preg_replace('/\s+style\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $contenu);
$contenu = preg_replace('/href\s*=\s*["\']?\s*javascript\s*:/i', 'href="', $contenu);
$contenu = nl2br($contenu);
echo important(htmlspecialchars($donnees['titre'], ENT_QUOTES, 'UTF-8') . '<em> le ' . date('d/m/Y à H\hi', $donnees['timestamp']) . '</em>');
echo '
<p>
<br/>
' . $contenu . '
</p>
';

finCarte();
include("includes/copyright.php");
