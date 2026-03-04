<?php
include("../includes/connexion.php");

include("redirectionmotdepasse.php");
require_once(__DIR__ . '/../includes/csrf.php');

if (isset($_POST['supprimer'])) {
    csrfCheck();
    $supprimerId = (int)$_POST['supprimer'];
    dbExecute($base, 'DELETE FROM reponses WHERE id = ?', 'i', $supprimerId);
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
$reponseRows = dbFetchAll($base, 'SELECT * FROM reponses ORDER BY auteur DESC');
foreach ($reponseRows as $donnees)
{
?>
<tr>
<td><form method="post" action="supprimerreponse.php" style="display:inline"><?php echo csrfField(); ?><input type="hidden" name="supprimer" value="<?php echo (int)$donnees['id']; ?>" /><input type="submit" value="Supprimer" /></form></td>
<td><?php echo htmlspecialchars(stripslashes($donnees['contenu']), ENT_QUOTES, 'UTF-8'); ?></td>
<td><?php echo htmlspecialchars(stripslashes($donnees['auteur']), ENT_QUOTES, 'UTF-8'); ?></td>
<td><?php echo date('d/m/Y', $donnees['timestamp']); ?></td>
</tr>
<?php
}
?>
</table>

</body>
</html>
