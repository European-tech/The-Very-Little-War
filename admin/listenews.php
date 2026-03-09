<?php
include("redirectionmotdepasse.php");
require_once(__DIR__ . '/../includes/csrf.php');
require_once(__DIR__ . '/../includes/database.php');
include("../includes/connexion.php");
// LOW-016: CSP header for admin page.
require_once(__DIR__ . '/../includes/csp.php');
$nonce = cspNonce();
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self';");
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta http-equiv="content-type" content="text/html; charset=utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>The Very Little War - Liste des news</title>
    <style type="text/css">
        h3,
        th,
        td {
            text-align: center;
        }

        table {
            border-collapse: collapse;
            border: 2px solid black;
            margin: auto;
        }

        th,
        td {
            border: 1px solid black;
        }
    </style>
</head>

<body>

    <h3><a href="redigernews.php">Ajouter une news</a></h3>
    <?php

    // CSRF check for POST actions only (GET loads the page)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrfCheck();
    }

    //-----------------------------------------------------
    // Vérification 1 : est-ce qu'on veut poster une news ?
    //-----------------------------------------------------
    if (isset($_POST['titre']) and isset($_POST['contenu'])) {
        $titre = mb_substr(trim($_POST['titre']), 0, 255);
        $contenu = mb_substr(trim($_POST['contenu']), 0, 10000);
        $id_news = (int)$_POST['id_news'];
        // On vérifie si c'est une modification de news ou non.
        if ($id_news == 0) {
            // Ce n'est pas une modification, on crée une nouvelle entrée dans la table.
            $timestamp = time();
            dbExecute($base, "INSERT INTO news VALUES(default, ?, ?, ?)", 'ssi', $titre, $contenu, $timestamp);
        } else {
            // C'est une modification, on met juste à jour le titre et le contenu.
            dbExecute($base, "UPDATE news SET titre = ?, contenu = ? WHERE id = ?", 'ssi', $titre, $contenu, $id_news);
        }
    }

    //--------------------------------------------------------
    // Vérification 2 : est-ce qu'on veut supprimer une news ?
    //--------------------------------------------------------
    if (isset($_POST['supprimer_news'])) {
        $supprimerNewsId = (int)$_POST['supprimer_news'];
        dbExecute($base, 'DELETE FROM news WHERE id = ?', 'i', $supprimerNewsId);
    }
    ?>
    <table>
        <tr>
            <th>Modifier</th>
            <th>Supprimer</th>
            <th>Titre</th>
            <th>Date</th>
        </tr>
        <?php
        $newsRows = dbFetchAll($base, 'SELECT * FROM news ORDER BY id DESC');
        foreach ($newsRows as $donnees) // On fait une boucle pour lister les news.
        {
        ?>
            <tr>
                <td><?php echo '<a href="redigernews.php?modifier_news=' . (int)$donnees['id'] . '">'; ?>Modifier</a></td>
                <td>
                    <form action="listenews.php" method="post" style="display:inline">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="supprimer_news" value="<?php echo (int)$donnees['id']; ?>" />
                        <input type="submit" value="Supprimer" />
                    </form>
                </td>
                <td><?php echo htmlspecialchars($donnees['titre'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo date('d/m/Y', $donnees['timestamp']); ?></td>
            </tr>
        <?php
        } // Fin de la boucle qui liste les news.
        ?>
    </table>
</body>

</html>
