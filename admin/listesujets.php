<?php
include("redirectionmotdepasse.php");
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<title>The Very Little War - Liste des sujets</title>
<meta charset="UTF-8" />
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

<?php
include("../includes/connexion.php");
require_once("../includes/database.php");
require_once("../includes/csrf.php");

// All actions now require POST + CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();

    if (isset($_POST['supprimersujet'])) {
        $supprimersujet = (int)$_POST['supprimersujet'];
        dbExecute($base, 'DELETE FROM sujets WHERE id = ?', 'i', $supprimersujet);
        dbExecute($base, 'DELETE FROM statutforum WHERE idsujet = ?', 'i', $supprimersujet);
    }
    if (isset($_POST['verouillersujet'])) {
        $verouillersujet = (int)$_POST['verouillersujet'];
        dbExecute($base, 'UPDATE sujets SET statut = 1 WHERE id = ?', 'i', $verouillersujet);
        dbExecute($base, 'DELETE FROM statutforum WHERE idsujet = ?', 'i', $verouillersujet);
    }
    if (isset($_POST['deverouillersujet'])) {
        $deverouillersujet = (int)$_POST['deverouillersujet'];
        dbExecute($base, 'UPDATE sujets SET statut = 0 WHERE id = ?', 'i', $deverouillersujet);
    }
}
?>
<table>
<tr>
<th>Vérouiller</th>
<th>Dévérouiller</th>
<th>Supprimer</th>
<th>Titre</th>
<th>Auteur</th>
<th>Statut</th>
<th>Date</th>
</tr>
<?php
$sujetRows = dbFetchAll($base, 'SELECT * FROM sujets ORDER BY auteur DESC');
foreach ($sujetRows as $donnees)
{
$sujetId = (int)$donnees['id'];
?>
<tr>
<td><form method="post" action="listesujets.php" style="display:inline"><?php echo csrfField(); ?><input type="hidden" name="verouillersujet" value="<?php echo $sujetId; ?>" /><button type="submit">Vérouiller</button></form></td>
<td><form method="post" action="listesujets.php" style="display:inline"><?php echo csrfField(); ?><input type="hidden" name="deverouillersujet" value="<?php echo $sujetId; ?>" /><button type="submit">Dévérouiller</button></form></td>
<td><form method="post" action="listesujets.php" style="display:inline"><?php echo csrfField(); ?><input type="hidden" name="supprimersujet" value="<?php echo $sujetId; ?>" /><button type="submit">Supprimer</button></form></td>
<td><?php echo htmlspecialchars($donnees['titre'], ENT_QUOTES, 'UTF-8'); ?></td>
<td><?php echo htmlspecialchars($donnees['auteur'], ENT_QUOTES, 'UTF-8'); ?></td>
<td><?php if($donnees['statut'] == 0){ echo "Ouvert"; } else { echo "Vérouillé"; } ?></td>
<td><?php echo date('d/m/Y', $donnees['timestamp']); ?></td>
</tr>
<?php
}
?>
</table>
</body>
</html>