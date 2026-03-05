<?php include("includes/connexion.php");
include("includes/fonctions.php");

include("includes/layout.php");
debutCarte("Maintenance");
$donnees = dbFetchOne($base, 'SELECT * FROM news ORDER BY id DESC LIMIT 0, 1');
$allowedTags = '<a><br><br/><strong><b><i><em><p><div><span><img><hr>';
$contenu = strip_tags($donnees['contenu'], $allowedTags);
// Strip event handlers and dangerous attributes (P5-GAP-012)
$contenu = preg_replace('/\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $contenu);
$contenu = preg_replace('/\s+style\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $contenu);
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
