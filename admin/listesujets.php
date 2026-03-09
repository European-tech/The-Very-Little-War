<?php
include("redirectionmotdepasse.php");
include("../includes/connexion.php");
require_once("../includes/database.php");
require_once("../includes/csrf.php");
require_once(__DIR__ . '/../includes/logger.php');
// ADMIN-HIGH-001: Add CSP headers to admin page.
require_once(__DIR__ . '/../includes/csp.php');
$nonce = cspNonce();
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self'; frame-ancestors 'none'; form-action 'self';");
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

// All actions now require POST + CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();

    if (isset($_POST['supprimersujet'])) {
        $supprimersujet = (int)$_POST['supprimersujet'];
        // LOW-012: Fetch all reply authors before deletion so we can decrement their message counters.
        $replyAuthors = dbFetchAll($base, 'SELECT auteur FROM reponses WHERE idsujet = ?', 'i', $supprimersujet);
        withTransaction($base, function() use ($base, $supprimersujet, $replyAuthors) {
            $topicRow = dbFetchOne($base, 'SELECT auteur FROM sujets WHERE id = ? FOR UPDATE', 'i', $supprimersujet);
            dbExecute($base, 'DELETE FROM reponses WHERE idsujet = ?', 'i', $supprimersujet);
            dbExecute($base, 'DELETE FROM sujets WHERE id = ?', 'i', $supprimersujet);
            dbExecute($base, 'DELETE FROM statutforum WHERE idsujet = ?', 'i', $supprimersujet);
            // Decrement nbMessages for the topic author.
            if ($topicRow && !empty($topicRow['auteur']) && $topicRow['auteur'] !== '[supprimé]') {
                dbExecute($base, 'UPDATE autre SET nbMessages = GREATEST(0, nbMessages - 1) WHERE login = ?', 's', $topicRow['auteur']);
            }
            // Decrement nbMessages for each reply author.
            foreach ($replyAuthors as $authorRow) {
                if (!empty($authorRow['auteur'])) {
                    // FORUM-MED-002: nbMessages lives on autre, not membre.
                    dbExecute($base, 'UPDATE autre SET nbMessages = GREATEST(0, nbMessages - 1) WHERE login = ?', 's', $authorRow['auteur']);
                }
            }
        });
        logInfo('ADMIN', 'Topic deleted', ['topic_id' => $supprimersujet]);
    }
    if (isset($_POST['verouillersujet'])) {
        $verouillersujet = (int)$_POST['verouillersujet'];
        withTransaction($base, function() use ($base, $verouillersujet) { // ADMIN-P26-001: atomic lock+cleanup
            dbExecute($base, 'UPDATE sujets SET statut = 1 WHERE id = ?', 'i', $verouillersujet);
            dbExecute($base, 'DELETE FROM statutforum WHERE idsujet = ?', 'i', $verouillersujet);
        });
        logInfo('ADMIN', 'Topic locked', ['topic_id' => $verouillersujet]);
    }
    if (isset($_POST['deverouillersujet'])) {
        $deverouillersujet = (int)$_POST['deverouillersujet'];
        withTransaction($base, function() use ($base, $deverouillersujet) { // ADMIN-P26-005: atomic unlock+cleanup
            dbExecute($base, 'UPDATE sujets SET statut = 0 WHERE id = ?', 'i', $deverouillersujet);
            dbExecute($base, 'DELETE FROM statutforum WHERE idsujet = ?', 'i', $deverouillersujet);
        });
        logInfo('ADMIN', 'Topic unlocked', ['topic_id' => $deverouillersujet]);
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