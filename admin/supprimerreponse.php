<?php
include("../includes/connexion.php");

include("redirectionmotdepasse.php");
if (isset($_GET['supprimer']))
{
$supprimerId = (int)$_GET['supprimer'];
dbExecute($base, 'DELETE FROM reponses WHERE id = ?', 'i', $supprimerId);
}
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="fr" >
<head>
<title>Neocrea - Supprimmer une réponse(Forum)</title>
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
$retour = dbQuery($base, 'SELECT * FROM reponses ORDER BY auteur DESC');
while ($donnees = mysqli_fetch_array($retour))
{
?>
<tr>
<td><?php echo '<a href="supprimerreponse.php?supprimer=' . (int)$donnees['id'] . '">';?>Supprimer</a></td>
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
