<?php
include("../includes/connexion.php");

include("redirectionmotdepasse.php");
require_once(__DIR__ . '/../includes/csrf.php');
// ADMIN-HIGH-001: Add CSP headers to admin page.
require_once(__DIR__ . '/../includes/csp.php');
$nonce = cspNonce();
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self'; frame-ancestors 'none'; form-action 'self';");

if (isset($_POST['supprimer'])) {
    csrfCheck();
    $supprimerId = (int)$_POST['supprimer'];
    // LOW-011: Fetch author before deletion so we can decrement their message counter.
    $replyRow = dbFetchOne($base, 'SELECT auteur FROM reponses WHERE id = ?', 'i', $supprimerId);
    dbExecute($base, 'DELETE FROM reponses WHERE id = ?', 'i', $supprimerId);
    if ($replyRow && !empty($replyRow['auteur'])) {
        $authorLogin = $replyRow['auteur'];
        // FORUM-MED-002: nbMessages lives on autre, not membre.
        dbExecute($base, 'UPDATE autre SET nbMessages = GREATEST(0, nbMessages - 1) WHERE login = ?', 's', $authorLogin);
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
<title>TVLW - Supprimer une réponse (Forum)</title>
<meta http-equiv="content-type" content="text/html; charset=utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1">
<style type="text/css">
h3, th, td
{
text-align:center;
}
table
{
border-collapse:collapse;
border:2px solid black;
margin:auto;
}
th, td
{
border:1px solid black;
}
</style>
</head>
<body>

<table>
<tr>
<th>Supprimer</th>
<th>Contenu</th>
<th>Auteur</th>
<th>Date</th>
</tr>
<?php
$reponseRows = dbFetchAll($base, 'SELECT * FROM reponses ORDER BY auteur DESC LIMIT 200');
foreach ($reponseRows as $donnees)
{
?>
<tr>
<td><form method="post" action="supprimerreponse.php" style="display:inline"><?php echo csrfField(); ?><input type="hidden" name="supprimer" value="<?php echo (int)$donnees['id']; ?>" /><input type="submit" value="Supprimer" /></form></td>
<td><?php echo htmlspecialchars($donnees['contenu'], ENT_QUOTES, 'UTF-8'); ?></td>
<td><?php echo htmlspecialchars($donnees['auteur'], ENT_QUOTES, 'UTF-8'); ?></td>
<td><?php echo date('d/m/Y', $donnees['timestamp']); ?></td>
</tr>
<?php
}
?>
</table>

</body>
</html>
