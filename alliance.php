<?php
session_start();
$_SESSION['start'] = "start";
if (isset($_SESSION['login'])) {
    include("includes/basicprivatephp.php");
} else {
    include("includes/basicpublicphp.php");
}
include("includes/bbcode.php");
require_once("includes/csrf.php");

// alliance du joueur
if (isset($autre) && isset($autre['idalliance'])) {
    $ex = dbQuery($base, 'SELECT * FROM alliances WHERE id=?', 'i', $autre['idalliance']);
    $allianceJoueur = mysqli_fetch_array($ex);
    if (mysqli_num_rows($ex) == 0) {
        $allianceJoueur['tag'] = -1;
    }
} else {
    $allianceJoueur = ['tag' => -1];
}

// si pas d'id alors on cherche notre alliance
if (!isset($_GET['id'])) {
    $_GET['id'] = antiXSS($allianceJoueur['tag']);
} else {
    $_GET['id'] = antiXSS($_GET['id']);
}

if (isset($_POST['nomalliance']) and isset($_POST['tagalliance']) && $allianceJoueur['tag'] == -1) {
    csrfCheck();
    if (!empty($_POST['nomalliance']) and !empty($_POST['tagalliance'])) {
        $idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);
        if ($idalliance['idalliance'] <= 0) {
            $_POST['nomalliance'] = mysqli_real_escape_string($base, stripslashes(antihtml($_POST['nomalliance'])));
            $_POST['tagalliance'] = mysqli_real_escape_string($base, stripslashes(antihtml($_POST['tagalliance'])));

            if (preg_match("#^[a-zA-Z0-9_]{3,16}$#", $_POST['tagalliance'])) {

                $ex2 = dbQuery($base, 'SELECT nom FROM alliances WHERE tag=? OR nom=?', 'ss', $_POST['tagalliance'], $_POST['nomalliance']);
                $nballiance = mysqli_num_rows($ex2);

                if ($nballiance == 0) {
                    dbExecute($base, 'INSERT INTO alliances VALUES (default, ?, ?, ?, default, ?, default, default, default, default, default, default, default, default)', 'ssss',
                        $_POST['nomalliance'], $_POST['tagalliance'], '', $_SESSION['login']);

                    $nouvellealliance = dbFetchOne($base, 'SELECT id FROM alliances WHERE tag=?', 's', $_POST['tagalliance']);

                    dbExecute($base, 'UPDATE autre SET idalliance=? WHERE login=?', 'is', $nouvellealliance['id'], $_SESSION['login']);

                    logInfo('ALLIANCE', 'Alliance created', ['name' => $_POST['nomalliance'], 'tag' => $_POST['tagalliance'], 'creator' => $_SESSION['login']]);
                    $information = "Votre équipe a été créée.";
                    echo '<script>window.location="alliance.php";</script>';
                } else {
                    $erreur = "Une équipe avec ce nom ou ce tag existe déja.";
                }
            } else {
                $erreur = "Le TAG de l'alliance ne peut être composé que de lettres, nombres, \"_\", entre 3 et 16 caractères.";
            }
        } else {
            $erreur =  "Vous avez déja une équipe";
        }
    } else {
        $erreur = "Tous les champs ne sont pas remplis.";
    }
}
// si notre alliance
if ($_GET['id'] == $allianceJoueur['tag'] && $_GET['id'] != -1) {
    if (isset($_POST['quitter'])) {
        csrfCheck();
        dbExecute($base, 'UPDATE autre SET idalliance=0 WHERE login=?', 's', $_SESSION['login']);
    }

    $idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);
    $duplicateur = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
    $cout = round(10 * pow(2.5, ($duplicateur['duplicateur'] + 1)) * (1 - catalystEffect('duplicateur_discount')));

    if (isset($_POST['augmenterDuplicateur'])) {
        csrfCheck();
        $energieAlliance = dbFetchOne($base, 'SELECT energieAlliance FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);

        if ($energieAlliance['energieAlliance'] >= $cout) {
            $newDup = $duplicateur['duplicateur'] + 1;
            $newEnergie = $energieAlliance['energieAlliance'] - $cout;
            dbExecute($base, 'UPDATE alliances SET duplicateur=?, energieAlliance=? WHERE id=?', 'idi', $newDup, $newEnergie, $idalliance['idalliance']);
            $information = "Vous avez augmenté votre duplicateur au niveau " . ($duplicateur['duplicateur'] + 1) . ".";
        } else {
            $erreur = "Vous n'avez pas assez d'énergie.";
        }
    }
}

if ($_GET['id'] == -1) { // si pas d'alliance alors invitations
    if (isset($_POST['actioninvitation']) and isset($_POST['idinvitation'])) {
        csrfCheck();
        $_POST['idinvitation'] = antiXSS($_POST['idinvitation']);
        $idalliance = dbFetchOne($base, 'SELECT idalliance FROM invitations WHERE id=?', 'i', $_POST['idinvitation']);

        $ex = dbQuery($base, 'SELECT login FROM autre WHERE idalliance=?', 'i', $idalliance['idalliance']);
        $nombreJoueurs = mysqli_num_rows($ex);
        if ($nombreJoueurs < $joueursEquipe) {
            if ($_POST['actioninvitation'] == "Accepter") {
                dbExecute($base, 'UPDATE autre SET idalliance=? WHERE login=?', 'is', $idalliance['idalliance'], $_SESSION['login']);
                $information = "Vous avez accepté l'invitation.";
                echo '<script>window.location="alliance.php";</script>';
            }
            dbExecute($base, 'DELETE FROM invitations WHERE id=?', 'i', $_POST['idinvitation']);
        } else {
            $erreur = "Le nombre maximal de joueurs dans l'équipe est atteint.";
        }
    }
}
include("includes/tout.php");

// Verification que le chef de l'alliance existe, sinon on supprimmer l'alliance et les invitations et les numeros dans autre
$ex = dbQuery($base, 'SELECT id as idalliance FROM alliances WHERE tag=?', 's', $_GET['id']);
$idalliance = mysqli_fetch_array($ex);
if ($_GET['id'] != -1) {
    if (mysqli_num_rows($ex) > 0) {
        $chef = dbFetchOne($base, 'SELECT chef FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);

        $ex2 = dbQuery($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $chef['chef']);
        $idalliancechef = mysqli_fetch_array($ex2);
        $chefExiste = mysqli_num_rows($ex2);

        if ($chefExiste == 0 or $idalliancechef['idalliance'] != $idalliance['idalliance']) {
            supprimerAlliance($idalliance['idalliance']);
?>
            <script LANGUAGE="JavaScript">
                window.location = "alliance.php";
            </script>
        <?php
            exit();
        }
        //////////////////////////////////////////////////////////////////////////////////////////////////////////////////
        $allianceJoueurPage = dbFetchOne($base, 'SELECT * FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);

        $ex2 = dbQuery($base, 'SELECT totalPoints FROM autre WHERE idalliance=? ORDER BY points DESC', 'i', $idalliance['idalliance']);
        $nbjoueurs = mysqli_num_rows($ex2);
        $pointstotaux = 0;
        while ($joueur = mysqli_fetch_array($ex2)) {
            $pointstotaux = $joueur['totalPoints'] + $pointstotaux;
        }

        debutCarte(htmlspecialchars(stripslashes($allianceJoueurPage['nom']), ENT_QUOTES, 'UTF-8'));

        $rangQuery = dbQuery($base, 'SELECT tag FROM alliances ORDER BY pointstotaux DESC');
        $rang = 1;

        while ($rangEx = mysqli_fetch_array($rangQuery)) {
            if ($rangEx['tag'] == $allianceJoueurPage['tag']) {
                break;
            }
            $rang++;
        }

        echo important('Informations');
        echo chipInfo('<span class="important">Rang : </span>' . imageClassement($rang), 'images/alliance/up.png') . '<br/>';
        echo chipInfo('<span class="important">TAG : </span>' . htmlspecialchars(stripslashes($allianceJoueurPage['tag']), ENT_QUOTES, 'UTF-8'), 'images/alliance/post-it.png') . '<br/>';
        echo chipInfo('<span class="important">Membres : </span>' . $nbjoueurs, 'images/alliance/sommejoueurs.png') . '<br/>';
        echo chipInfo('<span class="important">Points : </span>' . $pointstotaux, 'images/alliance/points.png') . '<br/>';
        echo chipInfo('<span class="important">Moyenne : </span>' . floor($pointstotaux / $nbjoueurs), 'images/alliance/sommepoints.png') . '<br/>';
        echo chipInfo('<span class="important">Chef : </span>' . joueur($allianceJoueurPage['chef']), 'images/alliance/crown.png') . '<br/>';
        echo chipInfo('<span class="important">Points de victoire : </span>' . $allianceJoueurPage['pointsVictoire'], 'images/classement/victoires.png') . '<br/>';
        echo nombreEnergie('<span class="important">Energie : </span>' . number_format(floor($allianceJoueurPage['energieAlliance']), 0, ' ', ' ')) . '<br/>';

        $ex = dbQuery($base, 'SELECT * FROM grades WHERE idalliance=?', 'i', $allianceJoueurPage['id']);
        $nb = mysqli_num_rows($ex);

        if ($nb > 0) {
            echo '<br/>' . important("Grades");
            while ($grades = mysqli_fetch_array($ex)) {
                echo '<span class="subimportant">' . $grades['nom'] . ' : </span><a href="joueur.php?id=' . $grades['login'] . '">' . $grades['login'] . '</a><br/>';
            }
        }
        ?>

        <?php
        $ex = dbQuery($base, 'SELECT * FROM declarations WHERE type=0 AND (alliance1=? OR alliance2=?) AND fin=0', 'ii', $allianceJoueurPage['id'], $allianceJoueurPage['id']);
        $nb = mysqli_num_rows($ex);
        if ($nb > 0) {
            echo '<br/><br/>' . important("Guerres");
            while ($guerre = mysqli_fetch_array($ex)) {
                if ($guerre['alliance1'] == $allianceJoueurPage['id']) {
                    $allianceJoueurAdverse = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id=?', 'i', $guerre['alliance2']);
                    echo '<br/>- <a href="guerre.php?id=' . $guerre['id'] . '"> contre ' . $allianceJoueurAdverse['tag'] . '</a>';
                } else {
                    $allianceJoueurAdverse = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id=?', 'i', $guerre['alliance1']);
                    echo '<br/>- <a href="guerre.php?id=' . $guerre['id'] . '"> contre ' . $allianceJoueurAdverse['tag'] . '</a>';
                }
            }
        }

        $ex = dbQuery($base, 'SELECT * FROM declarations WHERE type=1 AND (alliance1=? OR alliance2=?) AND valide!=0', 'ii', $allianceJoueurPage['id'], $allianceJoueurPage['id']);
        $nb = mysqli_num_rows($ex);
        if ($nb > 0) {
            echo '<br/><br/>' . important("Pactes");
            while ($pacte = mysqli_fetch_array($ex)) {
                if ($pacte['alliance1'] == $allianceJoueurPage['id']) {
                    $allianceJoueurAllie = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id=?', 'i', $pacte['alliance2']);
                    echo '<br/>- avec <a href="alliance.php?id=' . $allianceJoueurAllie['tag'] . '">' . $allianceJoueurAllie['tag'] . '</a>';
                } else {
                    $allianceJoueurAllie = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id=?', 'i', $pacte['alliance1']);
                    echo '<br/>- avec <a href="alliance.php?id=' . $allianceJoueurAllie['tag'] . '">' . $allianceJoueurAllie['tag'] . '</a>';
                }
            }
        }

        // On regarde si le joueur a un grade si il est dans l'alliance
        if ($_GET['id'] == $allianceJoueur['tag']) {
            $ex = dbQuery($base, 'SELECT login FROM grades WHERE login=? AND idalliance=?', 'si', $_SESSION['login'], $allianceJoueur['id']);
            $grade = mysqli_num_rows($ex);
            $admin = '';
            if (mysqli_real_escape_string($base, stripslashes(antihtml($allianceJoueur['chef']))) == $_SESSION['login'] or $grade > 0) {
                $admin = '<a href="allianceadmin.php" class="lienSousMenu"><img alt="admin" src="images/alliance/admin.png" title="Administration" class="imageSousMenu"/><br/><span class="labelSousMenu"  style="color:black">Administration</span></a>';
            }

            echo '<form action="alliance.php" method="post">';
            echo csrfField();
            finCarte($admin . '
            <a href="ecriremessage.php?destinataire=[alliance]" class="lienSousMenu"><img alt="message" src="images/alliance/message_ferme.png" title="Ecrire un message à l\'équipe" class="imageSousMenu"/><br/><span class="labelSousMenu"  style="color:black">Message</span></a>
            <a href="don.php" class="lienSousMenu"><img alt="dpn" src="images/alliance/give.png" title="Faire un don" class="imageSousMenu"/><br/><span class="labelSousMenu"  style="color:black">Donner</span></a>
            <a class=lienSousMenu><input class="imageSousMenu" src="images/alliance/doorway.png" alt="quitteralliance" type="image" value="Quitter l\'équipe" name="quitteralliance" title="Quitter l\'équipe"><br/><span class="labelSousMenu"  style="color:black">Quitter</span></a>
            <input type="hidden" name="quitter"/>');
            echo '</form>';
        } else {
            finCarte();
        }

        debutCarte("Description");
        ?>
        <p>
            <div class="table-reponsive">
                <?php echo BBcode($allianceJoueurPage['description']) ?>
            </div>
        </p>
        <?php
        finCarte();

        if ($_GET['id'] == $allianceJoueur['tag']) {
            debutCarte('Duplicateur');
            debutListe();
            item([
                'titre' => 'Duplicateur',
                'media' => '<img src="images/alliance/duplicateur.png" alt="duplicateur" style="width:50px;height:50px;"/>',
                'soustitre' => '<strong>Niveau ' . $allianceJoueur['duplicateur'] . '</strong>',
                'accordion' => debutContent(true, true) . 'Le duplicateur est un bâtiment propre aux équipes et qui doit être construit collectivement. L\'énergie nécessaire est celle du pot commun de l\'alliance.' . finContent(true, true) .
                    '<br/><br/>' . debutContent(false, true) .
                    '+' . (100 * bonusDuplicateur($allianceJoueur['duplicateur'])) . '% de production de toute les ressources<br/>' .
                    '+ ' . (100 * bonusDuplicateur($allianceJoueur['duplicateur'])) . '% de de défense et d\'attaque<br/>au <strong>niveau ' . $allianceJoueur['duplicateur'] . '</strong><br/><br/>
                  +' . (100 * bonusDuplicateur($allianceJoueur['duplicateur'] + 1)) . '% de production de toute les ressources<br/>' .
                    '+ ' . (100 * bonusDuplicateur($allianceJoueur['duplicateur'] + 1)) . '% de de défense et d\'attaque<br/>au <strong>niveau ' . ($allianceJoueur['duplicateur'] + 1) . '</strong>
                  <br/><br/>' . finContent(false, true) . '
                  <form action="alliance.php" method="post" name="augmenterDuplicateur">' . csrfField() .
                    important('Augmenter') . '
                  ' . nombreEnergie($cout) . '<br/><br/>
                  ' . submit(['titre' => 'niveau ' . ($allianceJoueur['duplicateur'] + 1), 'image' => 'images/boutons/arrow.png', 'form' => 'augmenterDuplicateur']) . '
                <input type="hidden" value="bla" name="augmenterDuplicateur"/></form>'
            ]);
            finListe();
            finCarte();
        }

        debutCarte('Membres'); ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th><img src="images/classement/up.png" alt="up" class="imageSousMenu" /><br /><span class="labelClassement">Rang</span></th>
                        <th><img src="images/classement/joueur.png" alt="joueur" title="Joueur" class="imageSousMenu" /><br /><span class="labelClassement">Joueur</span></th>
                        <th><a href="alliance.php?&clas=6"><img src="images/alliance/give.png" alt="dons" title="Dons" class="imageSousMenu" /><br /><span class="labelClassement">Dons</span></a></th>
                        <th><a href="alliance.php"><img src="images/classement/points.png" alt="points" title="Points" class="imageSousMenu" /><br /><span class="labelClassement">Points</span></a></th>
                        <th><a href="alliance.php?clas=5"><img src="images/classement/museum.png" alt="pointCs" title="Points de construction" class="imageSousMenu" /><br /><span class="labelClassement">Constructions</span></a></th>
                        <th><a href="alliance.php?&clas=2"><img src="images/classement/sword.png" alt="att" title="Attaque" class="imageSousMenu" /><br /><span class="labelClassement">Attaque</span></a></th>
                        <th><a href="alliance.php?&clas=3"><img src="images/classement/shield.png" alt="def" title="Défense" class="imageSousMenu" /><br /><span class="labelClassement">Défense</span></a></th>
                        <th><a href="alliance.php?&clas=4"><img src="images/classement/bag.png" alt="bag" title="Pillage" class="imageSousMenu" /><br /><span class="labelClassement">Pillage</span></a></th>
                        <th><a href="alliance.php?&clas=1"><img src="images/classement/victoires.png" alt="victoires" title="Points de victoire" class="imageSousMenu" /><br /><span class="labelClassement">Victoire</span></a></th>
                    </tr>
                </thead>
                <tbody>
                    <?php

                    if (!isset($_GET['clas'])) {
                        $_GET['clas'] = 0;
                    }
                    switch ($_GET['clas']) {
                        case 0:
                            $order = 'totalPoints';
                            break;
                        case 1:
                            $order = 'victoires';
                            break;
                        case 2:
                            $order = 'pointsAttaque';
                            break;
                        case 3:
                            $order = 'pointsDefense';
                            break;
                        case 4:
                            $order = 'ressourcesPillees';
                            break;
                        case 5:
                            $order = 'points';
                            break;
                        case 6:
                            $order = 'energieDonnee';
                            break;
                        default:
                            $order = 'totalPoints';
                            break;
                    }

                    // $order is from a whitelist, safe to use in query. idalliance is parameterized.
                    $ex3 = dbQuery($base, 'SELECT * FROM autre WHERE idalliance=? ORDER BY ' . $order . ' DESC', 'i', $idalliance['idalliance']);
                    $c = 1;
                    while ($joueur1 = mysqli_fetch_array($ex3)) {
                    ?>
                        <tr>
                            <td><?php echo imageClassement($c); ?></td>
                            <td><?php echo joueur($joueur1['login']); ?></td>
                            <td><?php if ($allianceJoueurPage['energieTotaleRecue'] > 0) {
                                    echo round($joueur1['energieDonnee'] / $allianceJoueurPage['energieTotaleRecue'] * 100);
                                } else {
                                    echo "0";
                                } ?>%</td>
                            <td><?php echo $joueur1['totalPoints']; ?></td>
                            <td><?php echo $joueur1['points']; ?></td>
                            <td><?php echo pointsAttaque($joueur1['pointsAttaque']); ?></td>
                            <td><?php echo pointsDefense($joueur1['pointsDefense']); ?></td>
                            <td><?php echo $joueur1['ressourcesPillees']; ?></td>
                            <td><?php echo $joueur1['victoires']; ?></td>
                        </tr>
                    <?php
                        $c++;
                    }
                    ?>
                </tbody>
            </table>
        </div>
    <?php
        finCarte();
    } else {
        debutCarte('Inconnue');
        debutContent();
        echo 'Cette alliance n\'existe pas.';
        finContent();
        finCarte();
    }
} else {
    debutCarte('Créer une équipe');
    ?>
    Vous n'appartenez à aucune alliance. Envoyez votre candidature au chef de l'alliance que vous voulez intégrer ou créez en une ci dessous.<br /><br />
    <form action="alliance.php" method="post" name="creerallianceForm">
        <?php echo csrfField(); ?>
        <input type="hidden" name="creeralliance" />
        <?php
        debutListe();
        item(['floating' => true, 'titre' => 'Nom de l\'équipe', 'input' => '<input type="text" name="nomalliance" id="nomalliance"/>']);
        item(['floating' => true, 'titre' => 'TAG de l\'équipe', 'input' => '<input type="text" name="tagalliance" id="tagalliance" maxlength=10/>']);
        item(['input' => submit(['form' => 'creerallianceForm', 'titre' => 'Créer'])]);
        finListe(); ?>

    </form>
<?php
    finCarte();

    if (isset($_SESSION['login'])) {
    debutCarte('Invitations');
    $ex = dbQuery($base, 'SELECT * FROM invitations WHERE invite=?', 's', $_SESSION['login']);
    $nbinvitations = mysqli_num_rows($ex);
    if ($nbinvitations > 0) {
        while ($invitation = mysqli_fetch_array($ex)) {
            echo '
            <form action="alliance.php" method="post">' . csrfField() . 'Invitation de l\'équipe ' . $invitation['tag'] . ' : <input type="submit" class="w32" style="background-image: url(\'images/yes.png\');background-size:contain;vertical-align:middle;margin-left:15px;margin-right:15px;background-color: Transparent;color: Transparent;background-repeat:no-repeat;border: none;cursor:pointer;overflow: hidden;outline:none;" name="actioninvitation" value="Accepter"/><input class="w32" style="background-image: url(\'images/croix.png\');background-size:contain;vertical-align:middle;background-color: Transparent;color: Transparent;background-repeat:no-repeat;border: none;cursor:pointer;overflow: hidden;outline:none;" type ="submit" name="actioninvitation" value="Refuser"/><input type="hidden" name="idinvitation" value="' . $invitation['id'] . '"/></form>';
        }
    } else {
        echo "Vous n'avez aucune invitation d'équipe.";
    }

    finCarte();
    } // end isset login for invitations
}

include("includes/copyright.php"); ?>