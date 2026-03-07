<?php
require_once("includes/session_init.php");
if (isset($_SESSION['login'])) {
    include("includes/basicprivatephp.php");
} else {
    include("includes/basicpublicphp.php");
}
include("includes/bbcode.php");
require_once("includes/csrf.php");

// alliance du joueur
if (isset($autre) && isset($autre['idalliance'])) {
    $allianceJoueur = dbFetchOne($base, 'SELECT * FROM alliances WHERE id=?', 'i', $autre['idalliance']);
    if (!$allianceJoueur) {
        $allianceJoueur['tag'] = -1;
    }
} else {
    $allianceJoueur = ['tag' => -1];
}

// si pas d'id alors on cherche notre alliance
if (!isset($_GET['id'])) {
    $_GET['id'] = trim($allianceJoueur['tag']);
} else {
    $_GET['id'] = trim($_GET['id']);
}

if (isset($_POST['nomalliance']) and isset($_POST['tagalliance']) && $allianceJoueur['tag'] == -1) {
    csrfCheck();
    if (!empty($_POST['nomalliance']) and !empty($_POST['tagalliance'])) {
        $idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);
        if ($idalliance['idalliance'] <= 0) {
            $_POST['nomalliance'] = trim($_POST['nomalliance']);
            $_POST['tagalliance'] = trim($_POST['tagalliance']);

            if (preg_match('#^[a-zA-Z0-9_]{' . ALLIANCE_TAG_MIN_LENGTH . ',' . ALLIANCE_TAG_MAX_LENGTH . '}$#', $_POST['tagalliance'])) {
                if (!preg_match('/^[\w\s\-\.\']{3,50}$/u', $_POST['nomalliance'])) {
                    $erreur = "Le nom d'alliance ne peut contenir que des lettres, chiffres, espaces, tirets et apostrophes (3-50 caractères).";
                } elseif (mb_strlen($_POST['nomalliance'], 'UTF-8') > 50) {
                    $erreur = "Le nom de l'alliance est trop long (50 caractères max).";
                } else {
                    try {
                        withTransaction($base, function() use ($base) {
                            // Re-check inside transaction to prevent race condition
                            $allianceCheckRows = dbFetchAll($base, 'SELECT nom FROM alliances WHERE tag=? OR nom=?', 'ss', $_POST['tagalliance'], $_POST['nomalliance']);
                            if (count($allianceCheckRows) > 0) {
                                throw new \RuntimeException('DUPLICATE');
                            }
                            dbExecute($base, 'INSERT INTO alliances VALUES (default, ?, ?, ?, default, ?, default, default, default, default, default, default, default, default)', 'ssss',
                                $_POST['nomalliance'], $_POST['tagalliance'], '', $_SESSION['login']);
                            $allianceId = mysqli_insert_id($base);
                            dbExecute($base, 'UPDATE autre SET idalliance=? WHERE login=?', 'is', $allianceId, $_SESSION['login']);
                        });

                        logInfo('ALLIANCE', 'Alliance created', ['name' => $_POST['nomalliance'], 'tag' => $_POST['tagalliance'], 'creator' => $_SESSION['login']]);
                        $information = "Votre équipe a été créée.";
                        header("Location: alliance.php"); exit;
                    } catch (\RuntimeException $e) {
                        $erreur = "Une équipe avec ce nom ou ce tag existe déja.";
                    }
                }
            } else {
                $erreur = "Le TAG de l'alliance ne peut être composé que de lettres, nombres, \"_\", entre " . ALLIANCE_TAG_MIN_LENGTH . " et " . ALLIANCE_TAG_MAX_LENGTH . " caractères.";
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
        // Check player is not the chef (would orphan the alliance)
        $allianceCheck = dbFetchOne($base, 'SELECT a.chef, au.idalliance FROM alliances a JOIN autre au ON au.idalliance = a.id WHERE au.login=?', 's', $_SESSION['login']);
        if ($allianceCheck && $allianceCheck['chef'] == $_SESSION['login']) {
            $erreur = "Le chef ne peut pas quitter l'alliance. Transférez d'abord le leadership.";
        } else {
            dbExecute($base, 'UPDATE autre SET idalliance=0 WHERE login=?', 's', $_SESSION['login']);
            dbExecute($base, 'DELETE FROM grades WHERE login=?', 's', $_SESSION['login']);
            // PASS1-MEDIUM-016: Clean up pending invitations when a player quits their alliance
            dbExecute($base, 'DELETE FROM invitations WHERE invite=?', 's', $_SESSION['login']);
            // Record leave timestamp for rejoin cooldown (column added by migration 0030)
            try {
                dbExecute($base, 'UPDATE autre SET alliance_left_at=UNIX_TIMESTAMP() WHERE login=?', 's', $_SESSION['login']);
            } catch (\Exception $e) {
                // Column not yet present — migration pending, skip silently
            }
        }
    }

    $idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);
    $duplicateur = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
    $cout = round(DUPLICATEUR_BASE_COST * pow(DUPLICATEUR_COST_FACTOR, ($duplicateur['duplicateur'] + 1)) * (1 - catalystEffect('duplicateur_discount')));

    if (isset($_POST['augmenterDuplicateur'])) {
        csrfCheck();
        // MED-065: Only chef or grade-holding officers may upgrade the duplicateur
        $dupAllianceInfo = dbFetchOne($base, 'SELECT chef FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
        $isDupChef = ($dupAllianceInfo && $dupAllianceInfo['chef'] === $_SESSION['login']);
        $hasDupGrade = false;
        if (!$isDupChef) {
            $dupGradeRow = dbFetchOne($base, 'SELECT grade FROM grades WHERE login=? AND idalliance=?', 'si', $_SESSION['login'], $idalliance['idalliance']);
            $hasDupGrade = ($dupGradeRow !== false && $dupGradeRow !== null);
        }
        if (!$isDupChef && !$hasDupGrade) {
            $erreur = "Permission insuffisante : seul le chef ou un officier gradé peut améliorer le duplicateur.";
        }
        if (!isset($erreur)) :
        $allianceId = $idalliance['idalliance'];
        try {
            $newLevel = withTransaction($base, function() use ($base, $allianceId) {
                $row = dbFetchOne($base, 'SELECT duplicateur, energieAlliance FROM alliances WHERE id=? FOR UPDATE', 'i', $allianceId);
                if (!$row) throw new \RuntimeException('Insufficient energy');
                $lockedCout = round(DUPLICATEUR_BASE_COST * pow(DUPLICATEUR_COST_FACTOR, ($row['duplicateur'] + 1)) * (1 - catalystEffect('duplicateur_discount')));
                if ($row['energieAlliance'] < $lockedCout) {
                    throw new \RuntimeException('Insufficient energy');
                }
                $newDup = $row['duplicateur'] + 1;
                $newEnergie = $row['energieAlliance'] - $lockedCout;
                dbExecute($base, 'UPDATE alliances SET duplicateur=?, energieAlliance=? WHERE id=?', 'idi', $newDup, $newEnergie, $allianceId);
                return $newDup;
            });
            $information = "Vous avez augmenté votre duplicateur au niveau " . $newLevel . ".";
        } catch (\RuntimeException $e) {
            $erreur = "Vous n'avez pas assez d'énergie.";
        }
        endif; // end permission check for augmenterDuplicateur
    }

    // Alliance research upgrades
    global $ALLIANCE_RESEARCH;
    // Whitelist: only allow column names that exist as keys in $ALLIANCE_RESEARCH
    $allowedResearchColumns = array_keys($ALLIANCE_RESEARCH);
    if (isset($_POST['upgradeResearch']) && isset($ALLIANCE_RESEARCH[$_POST['upgradeResearch']])) {
        csrfCheck();
        // PASS1-MEDIUM-014: Only chef or grade-holding officers may trigger research upgrades
        $researchAllianceId = $idalliance['idalliance'] ?? 0;
        $researchAllianceInfo = dbFetchOne($base, 'SELECT chef FROM alliances WHERE id=?', 'i', $researchAllianceId);
        $isResearchChef = ($researchAllianceInfo && $researchAllianceInfo['chef'] === $_SESSION['login']);
        $hasResearchGrade = false;
        if (!$isResearchChef) {
            $gradeRow = dbFetchOne($base, 'SELECT grade FROM grades WHERE login=? AND idalliance=?', 'si', $_SESSION['login'], $researchAllianceId);
            $hasResearchGrade = ($gradeRow !== false && $gradeRow !== null);
        }
        if (!$isResearchChef && !$hasResearchGrade) {
            $erreur = "Permission insuffisante : seul le chef ou un officier gradé peut améliorer les recherches.";
        }
        if (!isset($erreur)) :
        $techName = $_POST['upgradeResearch'];
        if (!in_array($techName, $allowedResearchColumns, true)) {
            throw new \RuntimeException("Invalid research column: $techName");
        }
        $tech = $ALLIANCE_RESEARCH[$techName];
        $allianceId = $idalliance['idalliance'];

        try {
            $resultLevel = withTransaction($base, function() use ($base, $techName, $tech, $allianceId, $allowedResearchColumns) {
                if (!in_array($techName, $allowedResearchColumns, true)) {
                    throw new \RuntimeException("Invalid research column: $techName");
                }
                $allianceData = dbFetchOne($base, 'SELECT ' . $techName . ', energieAlliance FROM alliances WHERE id=? FOR UPDATE', 'i', $allianceId);
                $currentLevel = intval($allianceData[$techName]);
                $researchCost = round($tech['cost_base'] * pow($tech['cost_factor'], $currentLevel + 1));

                // FIX FINDING-GAME-013: Enforce alliance research level cap
                if ($currentLevel >= ALLIANCE_RESEARCH_MAX_LEVEL) {
                    throw new \RuntimeException('max_level');
                }
                if ($allianceData['energieAlliance'] < $researchCost) {
                    throw new \RuntimeException('insufficient_energy');
                }
                $newLevel = $currentLevel + 1;
                $newEnergie = $allianceData['energieAlliance'] - $researchCost;
                dbExecute($base, 'UPDATE alliances SET ' . $techName . '=?, energieAlliance=? WHERE id=?', 'idi', $newLevel, $newEnergie, $allianceId);
                return $newLevel;
            });
            $information = htmlspecialchars($tech['name']) . " amélioré au niveau " . $resultLevel . ".";
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'max_level') {
                $erreur = "Niveau maximum atteint (" . ALLIANCE_RESEARCH_MAX_LEVEL . ").";
            } else {
                $erreur = "Vous n'avez pas assez d'énergie d'alliance.";
            }
        }
        endif; // end permission check for upgradeResearch
    }
}

if ($_GET['id'] == -1) { // si pas d'alliance alors invitations
    if (isset($_POST['actioninvitation']) and isset($_POST['idinvitation'])) {
        csrfCheck();
        $_POST['idinvitation'] = (int)$_POST['idinvitation'];
        $idalliance = dbFetchOne($base, 'SELECT idalliance FROM invitations WHERE id=? AND invite=?', 'is', $_POST['idinvitation'], $_SESSION['login']);
        if (!$idalliance) {
            $erreur = "Cette invitation n'existe pas.";
        } else {

        if ($_POST['actioninvitation'] == "Accepter") {
            // Check rejoin cooldown (column added by migration 0030)
            try {
                $leftAtRow = dbFetchOne($base, 'SELECT alliance_left_at FROM autre WHERE login=?', 's', $_SESSION['login']);
                if ($leftAtRow && !empty($leftAtRow['alliance_left_at'])) {
                    $cooldown = defined('ALLIANCE_REJOIN_COOLDOWN_SECONDS') ? ALLIANCE_REJOIN_COOLDOWN_SECONDS : SECONDS_PER_DAY;
                    if ((time() - (int)$leftAtRow['alliance_left_at']) < $cooldown) {
                        $heuresRestantes = ceil(($cooldown - (time() - (int)$leftAtRow['alliance_left_at'])) / 3600);
                        $erreur = "Vous devez attendre encore " . $heuresRestantes . "h avant de rejoindre une alliance.";
                    }
                }
            } catch (\Exception $e) {
                // Column not yet present — migration pending, skip cooldown check
            }
            if (!isset($erreur)) try {
                withTransaction($base, function() use ($base, $idalliance, $joueursEquipe) {
                    // LOW-019: Re-verify inside transaction that the accepting player has no alliance
                    $currentAlliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
                    if (!$currentAlliance || (int)$currentAlliance['idalliance'] !== 0) {
                        throw new \RuntimeException('ALREADY_IN_ALLIANCE');
                    }
                    // Count members inside transaction to prevent race condition
                    $count = dbCount($base, 'SELECT COUNT(*) AS nb FROM autre WHERE idalliance=?', 'i', $idalliance['idalliance']);
                    if ($count >= $joueursEquipe) {
                        throw new \RuntimeException('FULL');
                    }
                    dbExecute($base, 'UPDATE autre SET idalliance=? WHERE login=?', 'is', $idalliance['idalliance'], $_SESSION['login']);
                    // HIGH-037: Clear all pending invitations for this player after joining
                    dbExecute($base, 'DELETE FROM invitations WHERE invite=?', 's', $_SESSION['login']);
                });
                $information = "Vous avez accepté l'invitation.";
                header("Location: alliance.php"); exit;
            } catch (\RuntimeException $e) {
                if ($e->getMessage() === 'ALREADY_IN_ALLIANCE') {
                    $erreur = "Vous appartenez déjà à une alliance.";
                } else {
                    $erreur = "Le nombre maximal de joueurs dans l'équipe est atteint.";
                }
            }
        } else {
            dbExecute($base, 'DELETE FROM invitations WHERE id=?', 'i', $_POST['idinvitation']);
        }
        } // end invitation ownership check
    }
}
include("includes/layout.php");

// Verification que le chef de l'alliance existe, sinon on supprimmer l'alliance et les invitations et les numeros dans autre
$idalliance = dbFetchOne($base, 'SELECT id as idalliance FROM alliances WHERE tag=?', 's', $_GET['id']);
if ($_GET['id'] != -1) {
    if ($idalliance) {
        $chef = dbFetchOne($base, 'SELECT chef FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);

        $idalliancechef = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $chef['chef']);
        $chefExiste = $idalliancechef ? 1 : 0;

        if ($chefExiste == 0 or $idalliancechef['idalliance'] != $idalliance['idalliance']) {
            supprimerAlliance($idalliance['idalliance']);
?>
            <script nonce="<?php echo htmlspecialchars(cspNonce(), ENT_QUOTES, 'UTF-8'); ?>">
                window.location = "alliance.php";
            </script>
        <?php
            exit();
        }
        //////////////////////////////////////////////////////////////////////////////////////////////////////////////////
        $allianceJoueurPage = dbFetchOne($base, 'SELECT * FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);

        $joueurPointsRows = dbFetchAll($base, 'SELECT totalPoints FROM autre WHERE idalliance=? ORDER BY points DESC', 'i', $idalliance['idalliance']);
        $nbjoueurs = count($joueurPointsRows);
        $pointstotaux = 0;
        foreach ($joueurPointsRows as $joueur) {
            $pointstotaux = $joueur['totalPoints'] + $pointstotaux;
        }

        debutCarte(htmlspecialchars($allianceJoueurPage['nom'], ENT_QUOTES, 'UTF-8'));

        $rangRows = dbFetchAll($base, 'SELECT tag FROM alliances ORDER BY pointstotaux DESC');
        $rang = 1;

        foreach ($rangRows as $rangEx) {
            if ($rangEx['tag'] == $allianceJoueurPage['tag']) {
                break;
            }
            $rang++;
        }

        echo important('Informations');
        echo chipInfo('<span class="important">Rang : </span>' . imageClassement($rang), 'images/alliance/up.png') . '<br/>';
        echo chipInfo('<span class="important">TAG : </span>' . htmlspecialchars($allianceJoueurPage['tag'], ENT_QUOTES, 'UTF-8'), 'images/alliance/post-it.png') . '<br/>';
        echo chipInfo('<span class="important">Membres : </span>' . $nbjoueurs, 'images/alliance/sommejoueurs.png') . '<br/>';
        echo chipInfo('<span class="important">Points : </span>' . $pointstotaux, 'images/alliance/points.png') . '<br/>';
        echo chipInfo('<span class="important">Moyenne : </span>' . ($nbjoueurs > 0 ? floor($pointstotaux / $nbjoueurs) : 0), 'images/alliance/sommepoints.png') . '<br/>';
        echo chipInfo('<span class="important">Chef : </span>' . joueur($allianceJoueurPage['chef']), 'images/alliance/crown.png') . '<br/>';
        echo chipInfo('<span class="important">Points de victoire : </span>' . $allianceJoueurPage['pointsVictoire'], 'images/classement/victoires.png') . '<br/>';
        echo nombreEnergie('<span class="important">Energie : </span>' . number_format(floor($allianceJoueurPage['energieAlliance']), 0, ' ', ' ')) . '<br/>';

        $gradesRows = dbFetchAll($base, 'SELECT * FROM grades WHERE idalliance=?', 'i', $allianceJoueurPage['id']);
        $nb = count($gradesRows);

        if ($nb > 0) {
            echo '<br/>' . important("Grades");
            foreach ($gradesRows as $grades) {
                $safeGradeNom = htmlspecialchars($grades['nom'], ENT_QUOTES, 'UTF-8');
                $safeGradeLogin = htmlspecialchars($grades['login'], ENT_QUOTES, 'UTF-8');
                echo '<span class="subimportant">' . $safeGradeNom . ' : </span><a href="joueur.php?id=' . $safeGradeLogin . '">' . $safeGradeLogin . '</a><br/>';
            }
        }
        ?>

        <?php
        $guerreRows = dbFetchAll($base, 'SELECT * FROM declarations WHERE type=0 AND (alliance1=? OR alliance2=?) AND fin=0', 'ii', $allianceJoueurPage['id'], $allianceJoueurPage['id']);
        $nb = count($guerreRows);
        if ($nb > 0) {
            echo '<br/><br/>' . important("Guerres");
            foreach ($guerreRows as $guerre) {
                if ($guerre['alliance1'] == $allianceJoueurPage['id']) {
                    $allianceJoueurAdverse = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id=?', 'i', $guerre['alliance2']);
                    echo '<br/>- <a href="guerre.php?id=' . (int)$guerre['id'] . '"> contre ' . htmlspecialchars($allianceJoueurAdverse['tag'], ENT_QUOTES, 'UTF-8') . '</a>';
                } else {
                    $allianceJoueurAdverse = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id=?', 'i', $guerre['alliance1']);
                    echo '<br/>- <a href="guerre.php?id=' . (int)$guerre['id'] . '"> contre ' . htmlspecialchars($allianceJoueurAdverse['tag'], ENT_QUOTES, 'UTF-8') . '</a>';
                }
            }
        }

        $pacteRows = dbFetchAll($base, 'SELECT * FROM declarations WHERE type=1 AND (alliance1=? OR alliance2=?) AND valide!=0', 'ii', $allianceJoueurPage['id'], $allianceJoueurPage['id']);
        $nb = count($pacteRows);
        if ($nb > 0) {
            echo '<br/><br/>' . important("Pactes");
            foreach ($pacteRows as $pacte) {
                if ($pacte['alliance1'] == $allianceJoueurPage['id']) {
                    $allianceJoueurAllie = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id=?', 'i', $pacte['alliance2']);
                    $safeTag = htmlspecialchars($allianceJoueurAllie['tag'], ENT_QUOTES, 'UTF-8');
                    echo '<br/>- avec <a href="alliance.php?id=' . $safeTag . '">' . $safeTag . '</a>';
                } else {
                    $allianceJoueurAllie = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id=?', 'i', $pacte['alliance1']);
                    $safeTag = htmlspecialchars($allianceJoueurAllie['tag'], ENT_QUOTES, 'UTF-8');
                    echo '<br/>- avec <a href="alliance.php?id=' . $safeTag . '">' . $safeTag . '</a>';
                }
            }
        }

        // On regarde si le joueur a un grade si il est dans l'alliance
        if ($_GET['id'] == $allianceJoueur['tag']) {
            // HIGH-038: Re-fetch alliance from DB — $allianceJoueur may be stale if the alliance was
            // auto-deleted above (chef missing). Use the freshly-loaded $allianceJoueurPage instead.
            $freshAlliance = $allianceJoueurPage ?? null;
            if (!$freshAlliance) {
                $erreur = "L'alliance n'existe plus.";
                header("Location: alliance.php"); exit;
            }
            $gradeCheckRows = dbFetchAll($base, 'SELECT login FROM grades WHERE login=? AND idalliance=?', 'si', $_SESSION['login'], $freshAlliance['id']);
            $grade = count($gradeCheckRows);
            $admin = '';
            if ($freshAlliance['chef'] == $_SESSION['login'] or $grade > 0) {
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
            <div class="table-responsive">
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

            // Alliance Research Tree
            global $ALLIANCE_RESEARCH;
            $allianceResearchData = dbFetchOne($base, 'SELECT catalyseur, fortification, reseau, radar, bouclier, energieAlliance FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
            debutCarte('Recherches');
            debutListe();
            foreach ($ALLIANCE_RESEARCH as $techName => $tech) {
                $currentLevel = intval($allianceResearchData[$techName] ?? 0);
                $researchCost = round($tech['cost_base'] * pow($tech['cost_factor'], $currentLevel + 1));
                $currentBonus = round($currentLevel * $tech['effect_per_level'] * 100, 1);
                $nextBonus = round(($currentLevel + 1) * $tech['effect_per_level'] * 100, 1);

                $canAfford = ($allianceResearchData['energieAlliance'] >= $researchCost);
                $upgradeButton = $canAfford
                    ? '<form action="alliance.php" method="post" style="display:inline">' . csrfField() .
                      '<input type="hidden" name="upgradeResearch" value="' . $techName . '"/>' .
                      submit(['titre' => 'Niveau ' . ($currentLevel + 1), 'form' => 'form_' . $techName, 'image' => 'images/boutons/arrow.png']) .
                      '</form>'
                    : '<span style="color:#999">Énergie insuffisante</span>';

                item([
                    'titre' => htmlspecialchars($tech['name']),
                    'media' => '<img src="' . htmlspecialchars($tech['icon']) . '" alt="' . htmlspecialchars($techName) . '" style="width:50px;height:50px;"/>',
                    'soustitre' => '<strong>Niveau ' . $currentLevel . '</strong> (' . $currentBonus . '%)',
                    'accordion' => debutContent(true, true) . htmlspecialchars($tech['desc']) . finContent(true, true) .
                        '<br/><br/>' . debutContent(false, true) .
                        $currentBonus . '% au <strong>niveau ' . $currentLevel . '</strong><br/>' .
                        $nextBonus . '% au <strong>niveau ' . ($currentLevel + 1) . '</strong>' .
                        finContent(false, true) . '<br/><br/>' .
                        important('Améliorer') . ' ' . nombreEnergie($researchCost) . '<br/><br/>' .
                        $upgradeButton
                ]);
            }
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
                    $joueur1Rows = dbFetchAll($base, 'SELECT * FROM autre WHERE idalliance=? ORDER BY ' . $order . ' DESC', 'i', $idalliance['idalliance']);
                    $c = 1;
                    foreach ($joueur1Rows as $joueur1) {
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
        item(['floating' => true, 'titre' => 'TAG de l\'équipe', 'input' => '<input type="text" name="tagalliance" id="tagalliance" maxlength="' . ALLIANCE_TAG_MAX_LENGTH . '"/>']);
        item(['input' => submit(['form' => 'creerallianceForm', 'titre' => 'Créer'])]);
        finListe(); ?>

    </form>
<?php
    finCarte();

    if (isset($_SESSION['login'])) {
    debutCarte('Invitations');
    $invitationRows = dbFetchAll($base, 'SELECT * FROM invitations WHERE invite=?', 's', $_SESSION['login']);
    $nbinvitations = count($invitationRows);
    if ($nbinvitations > 0) {
        foreach ($invitationRows as $invitation) {
            $safeInvTag = htmlspecialchars($invitation['tag'], ENT_QUOTES, 'UTF-8');
            echo '
            <form action="alliance.php" method="post">' . csrfField() . 'Invitation de l\'équipe ' . $safeInvTag . ' : <input type="submit" class="w32" style="background-image: url(\'images/yes.png\');background-size:contain;vertical-align:middle;margin-left:15px;margin-right:15px;background-color: Transparent;color: Transparent;background-repeat:no-repeat;border: none;cursor:pointer;overflow: hidden;outline:none;" name="actioninvitation" value="Accepter"/><input class="w32" style="background-image: url(\'images/croix.png\');background-size:contain;vertical-align:middle;background-color: Transparent;color: Transparent;background-repeat:no-repeat;border: none;cursor:pointer;overflow: hidden;outline:none;" type ="submit" name="actioninvitation" value="Refuser"/><input type="hidden" name="idinvitation" value="' . (int)$invitation['id'] . '"/></form>';
        }
    } else {
        echo "Vous n'avez aucune invitation d'équipe.";
    }

    finCarte();
    } // end isset login for invitations
}

include("includes/copyright.php"); ?>