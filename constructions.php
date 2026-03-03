<?php
include("includes/basicprivatephp.php");
include("includes/redirectionVacance.php");

// traitement des points à placer pour le producteur et le generateur
if (isset($_POST['nbPointshydrogene'])) { // un au hasard juste pour le formulaire
    csrfCheck();
    $somme = 0;
    $bool = true;

    foreach ($nomsRes as $num => $ressource) {
        $_POST['nbPoints' . $ressource] = intval($_POST['nbPoints' . $ressource]);
        if ($_POST['nbPoints' . $ressource] < 0) {
            $bool = false;
            echo intval($_POST['nbPoints' . $ressource]);
        } else {
            $somme = $somme + $_POST['nbPoints' . $ressource];
        }
    }


    if ($bool && $somme <= $constructions['pointsProducteurRestants']) {
        $chaine = "";
        foreach ($nomsRes as $num => $ressource) {
            $plus = "";
            if ($num - 1 < sizeof($nomsRes)) {
                $plus = ";";
            }

            $chaine = $chaine . ($_POST['nbPoints' . $ressource] + ${'points' . $ressource}) . $plus;
        }

        $newPoints = $constructions['pointsProducteurRestants'] - $somme;
        dbExecute($base, 'UPDATE constructions SET pointsProducteurRestants=?, pointsProducteur=? WHERE login=?', 'iss', $newPoints, $chaine, $_SESSION['login']);

        $information = "Les points du producteur ont été sauvegardés.";
        header('Location: constructions.php?information=' . urlencode($information));
        exit();
    } else {
        $erreur = "Le nombre de points n'est pas valide.";
    }
}

if (isset($_POST['nbPointsCondenseurhydrogene'])) { // un au hasard juste pour le formulaire
    csrfCheck();
    $somme = 0;
    $bool = true;

    foreach ($nomsRes as $num => $ressource) {
        $_POST['nbPointsCondenseur' . $ressource] = intval($_POST['nbPointsCondenseur' . $ressource]);
        if ($_POST['nbPointsCondenseur' . $ressource] < 0) {
            $bool = false;
            echo intval($_POST['nbPointsCondenseur' . $ressource]);
        } else {
            $somme = $somme + $_POST['nbPointsCondenseur' . $ressource];
        }
    }


    if ($bool && $somme <= $constructions['pointsCondenseurRestants']) {
        $chaine = "";
        foreach ($nomsRes as $num => $ressource) {
            $plus = "";
            if ($num - 1 < sizeof($nomsRes)) {
                $plus = ";";
            }

            $chaine = $chaine . ($_POST['nbPointsCondenseur' . $ressource] + ${'niveau' . $ressource}) . $plus;
        }

        $newPoints = $constructions['pointsCondenseurRestants'] - $somme;
        dbExecute($base, 'UPDATE constructions SET pointsCondenseurRestants=?, pointsCondenseur=? WHERE login=?', 'iss', $newPoints, $chaine, $_SESSION['login']);

        $information = "Les points du condenseur ont été sauvegardés.";
        header('Location: constructions.php?information=' . urlencode($information));
        exit();
    } else {
        $erreur = "Le nombre de points n'est pas valide.";
    }
}

// Handle formation change
if (isset($_POST['formation'])) {
    csrfCheck();
    $newFormation = intval($_POST['formation']);
    if ($newFormation >= 0 && $newFormation <= 2) {
        dbExecute($base, 'UPDATE constructions SET formation=? WHERE login=?', 'is', $newFormation, $_SESSION['login']);
        $information = "Formation défensive mise à jour.";
        header('Location: constructions.php?information=' . urlencode($information));
        exit();
    }
}

// FONCTIONS CONSTRUCTIONS

function mepConstructions($liste)
{
    global $nomsRes;
    global $placeDepot;
    global $ressources;
    global $revenuEnergie;
    global $revenu;
    global $base;

    // on doit calculer le niveau actuel (et dans le futur avec les constructions le précédant)
    $exNiveauActuel = dbQuery($base, 'SELECT niveau FROM actionsconstruction WHERE login=? AND batiment=? ORDER BY niveau DESC', 'ss', $_SESSION['login'], $liste['bdd']);
    $niveauActuel = mysqli_fetch_array($exNiveauActuel);
    $nb = mysqli_num_rows($exNiveauActuel);
    if ($nb == 0) {
        $niveauActuel['niveau'] = $liste['niveau'];
    }

    if (array_key_exists("progressBar", $liste) && $liste['progressBar']) {
        $media = '<img alt="za" src="' . $liste['image'] . '" style="width:80px;height:80px;margin-top:-54px;"/><div style="margin-left:-80px;margin-top:-10px;">' . progressBar($liste['vie'], $liste['vieMax'], "green") . '</div>';
    } else {
        $media = '<img alt="za" src="' . $liste['image'] . '" style="width:80px;height:80px;"/>';
    }

    $cout = "";
    if (array_key_exists("coutEnergie", $liste) && $liste['coutEnergie']) {
        $cout = $cout . coutEnergie($liste['coutEnergie']);
    } else {
        $liste['coutEnergie'] = 0;
    }

    foreach ($nomsRes as $num => $ressource) {
        if (array_key_exists('cout' . ucfirst($ressource), $liste) && $liste['cout' . ucfirst($ressource)]) {
            $cout = $cout . coutAtome($num, $liste['cout' . ucfirst($ressource)]);
        } else {
            $liste['cout' . ucfirst($ressource)] = 0;
        }
    }

    if (array_key_exists("coutAtomes", $liste) && $liste['coutAtomes']) {
        $cout = $cout . coutTout($liste['coutAtomes']);
        foreach ($nomsRes as $num => $ressource) {
            $liste['cout' . ucfirst($ressource)] = $liste['coutAtomes'];
        }
    }

    $bool = 1;

    foreach ($nomsRes as $num => $ressource) {
        if ($liste['cout' . ucfirst($ressource)] > $ressources[$ressource]) {
            $bool = 0;
        }
    }

    $nbResult = dbFetchOne($base, 'SELECT count(*) as nb FROM actionsconstruction WHERE login=?', 's', $_SESSION['login']);
    $nb = $nbResult;
    if ($nb['nb'] < 2) {
        if ($liste['coutEnergie'] >= $ressources['energie'] or $bool == 0) {
            $bool1 = 1;

            foreach ($nomsRes as $num => $ressource) {
                if ($liste['cout' . ucfirst($ressource)] > $placeDepot) {
                    $bool1 = 0;
                }
            }

            if ($placeDepot >= $liste['coutEnergie'] and $bool1 == 1) {
                $max = SECONDS_PER_HOUR * ($liste['coutEnergie'] - $ressources['energie']) / $revenu['energie'];
                foreach ($nomsRes as $num => $ressource) {
                    $max = max(SECONDS_PER_HOUR * ($liste['cout' . ucfirst($ressource)] - $ressources[$ressource]) / $revenu[$ressource], $max);
                }
                $augmenter =  'Assez de ressources le ' . date('d/m/Y', time() + $max) . ' à ' . date('H\hi', time() + $max);
            } else {
                $augmenter =  'L\'espace de stockage est trop petit pour autant de ressources.';
            }
        } else {
            $augmenter =  '<input type="hidden" value="Augmenter au niveau ' . ($niveauActuel['niveau'] + 1) . '" name="' . $liste['bdd'] . '" id="' . $liste['bdd'] . '"/>' . submit(['titre' => 'Niveau ' . ($niveauActuel['niveau'] + 1), 'form' => 'form' . $liste['bdd'], 'nom' => $liste['bdd'], 'image' => 'images/boutons/arrow.png']);
        }
    } else {
        $augmenter = 'Vous avez déjà <strong>deux constructions</strong> en cours.';
    }

    $drainage = '';
    if (array_key_exists("drainage", $liste) && $liste['drainage']) {
        $drainage = nombreEnergie('<span style="color:red">-' . $liste['drainage'] . '/h</span>');
    }

    item([
        'titre' => $liste['titre'],
        'media' => $media,
        'soustitre' => '<strong>Niveau ' . $liste['niveau'] . '</strong><br/>' . $liste['revenu'],
        'accordion' => debutContent(true, true) . $liste['description'] . finContent(true, true) .
            '<br/><br/>' . debutContent(false, true) . $liste['revenu'] . ' au <strong>niveau ' . $liste['niveau'] . '</strong><br/>
          ' . $liste['revenu1'] . ' au <strong> niveau ' . ($niveauActuel['niveau'] + 1) . '</strong>
          ' . $liste['effetSup'] . '<br/><br/>' . finContent(false, true) . '
          <form action="constructions.php" method="post" name="form' . $liste['bdd'] . '">' . csrfField() .
            important('Augmenter') . '
          ' . $cout . $drainage . nombreTemps(affichageTemps(round($liste['tempsConstruction'] * (1 - catalystEffect('construction_speed'))))) . nombrePoints('+' . $liste['points']) . '<br/><br/>
          ' . $augmenter . '</form><hr>'
    ]);
}

function traitementConstructions($liste)
{
    global $nomsRes;
    global $ressources;
    global $nbRes;
    global $base;
    global $constructions;
    global $ressources;
    global $autre;

    if (isset($_POST[$liste['bdd']])) {
        csrfCheck();
        $nbResult = dbFetchOne($base, 'SELECT count(*) as nb FROM actionsconstruction WHERE login=?', 's', $_SESSION['login']);
        $nb = $nbResult;

        if ($nb['nb'] < 2) {
            if (!array_key_exists("coutEnergie", $liste)) {
                $liste['coutEnergie'] = 0;
            }

            foreach ($nomsRes as $num => $ressource) {
                if (!array_key_exists('cout' . ucfirst($ressource), $liste)) {
                    $liste['cout' . ucfirst($ressource)] = 0;
                }
            }

            foreach ($nomsRes as $num => $ressource) {
                if (array_key_exists("coutAtomes", $liste) && $liste['coutAtomes']) {
                    $liste['cout' . ucfirst($ressource)] = $liste['coutAtomes'];
                }
            }


            $bool = 1;

            foreach ($nomsRes as $num => $ressource) {
                if ($ressources[$ressource] < $liste['cout' . ucfirst($ressource)]) {
                    $bool = 0;
                }
            }

            if ($ressources['energie'] >= $liste['coutEnergie'] and $bool == 1) {
                withTransaction($base, function() use ($base, $liste, $nomsRes, $nbRes, $constructions, $autre, &$information, &$erreur) {
                    // Lock resources with FOR UPDATE
                    $ressources = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
                    if (!$ressources) { $erreur = "Une erreur est survenue."; return; }

                    // Re-validate with locked values
                    foreach ($nomsRes as $num => $ressource) {
                        if ($ressources[$ressource] < $liste['cout' . ucfirst($ressource)]) {
                            $erreur = "Vous n'avez pas assez de ressources."; return;
                        }
                    }
                    if ($ressources['energie'] < $liste['coutEnergie']) {
                        $erreur = "Vous n'avez pas assez de ressources."; return;
                    }

                    // Build dynamic UPDATE for ressources - these are computed values, not user input
                    $chaine = "";
                    foreach ($nomsRes as $num => $ressource) {
                        $plus = "";
                        if ($num < $nbRes) {
                            $plus = ",";
                        }
                        $chaine = $chaine . $ressource . '=' . ($ressources[$ressource] - $liste['cout' . ucfirst($ressource)]) . $plus;
                    }

                    $newEnergie = $ressources['energie'] - $liste['coutEnergie'];
                    // Note: $chaine contains computed numeric values from server-side data, not user input
                    $sql2 = 'UPDATE ressources SET energie=?,' . $chaine . ' WHERE login=?';
                    $stmt = mysqli_prepare($base, $sql2);
                    if (!$stmt) {
                        error_log("SQL Prepare Error: " . mysqli_error($base));
                        $erreur = "Une erreur est survenue."; return;
                    }
                    mysqli_stmt_bind_param($stmt, 'ds', $newEnergie, $_SESSION['login']);
                    if (!mysqli_stmt_execute($stmt)) {
                        error_log("SQL Execute Error: " . mysqli_stmt_error($stmt));
                        $erreur = "Une erreur est survenue."; return;
                    }
                    mysqli_stmt_close($stmt);

                    $ex = dbQuery($base, 'SELECT * FROM actionsconstruction WHERE login=? ORDER BY fin DESC', 's', $_SESSION['login']);
                    $nb = mysqli_num_rows($ex);
                    if ($nb > 0) {
                        $actionsconstruction = mysqli_fetch_array($ex);
                        $tempsDebut = $actionsconstruction['fin'];
                    } else {
                        $tempsDebut = time();
                    }

                    $exNiveauActuel = dbQuery($base, 'SELECT niveau FROM actionsconstruction WHERE login=? AND batiment=? ORDER BY niveau DESC', 'ss', $_SESSION['login'], $liste['bdd']);
                    $niveauActuel = mysqli_fetch_array($exNiveauActuel);
                    $nb = mysqli_num_rows($exNiveauActuel);

                    if ($nb == 0) {
                        $niveauActuel['niveau'] = $constructions[$liste['bdd']];
                    }

                    $newNiveau = $niveauActuel['niveau'] + 1;
                    $adjustedConstructionTime = round($liste['tempsConstruction'] * (1 - catalystEffect('construction_speed')));
                    $finTemps = $tempsDebut + $adjustedConstructionTime;
                    dbExecute($base, 'INSERT INTO actionsconstruction VALUES(default,?,?,?,?,?,?,?)', 'siissii',
                        $_SESSION['login'], $tempsDebut, $finTemps, $liste['bdd'], $newNiveau, $liste['titre'], $liste['points']);

                    dbExecute($base, 'UPDATE autre SET energieDepensee = energieDepensee + ? WHERE login=?', 'ds', $liste['coutEnergie'], $_SESSION['login']);

                    $information = "La construction a bien été lancée.";
                });
            } else {
                $erreur = "Vous n'avez pas assez de ressources.";
            }
        } else {
            $erreur = "Vous avez déjà deux constructions en cours.";
        }
    }
}

foreach ($listeConstructions as $num => $b) {
    traitementConstructions($b);
}

include("includes/tout.php");

$ex = dbQuery($base, 'SELECT * FROM actionsconstruction WHERE login=?', 's', $_SESSION['login']);
$nb = mysqli_num_rows($ex);
if ($nb > 0) {
    debutCarte();
    scriptAffichageTemps();
    echo '<div class="table-responsive"><table><tr><th>Constructions</th><th>Temps restant</th><th>Fin</th></tr>';
    while ($actionsconstruction = mysqli_fetch_array($ex)) {
        echo '<tr><td>' . $actionsconstruction['affichage'] . ' <strong>niveau ' . $actionsconstruction['niveau'] . '</strong></td><td><span id="affichage' . $actionsconstruction['id'] . '">' . affichageTemps($actionsconstruction['fin'] - time()) . '</span></td><td>' . date('H\hi', $actionsconstruction['fin']) . '</td></tr>';
        echo '<script>
            var valeur' . $actionsconstruction['id'] . ' = ' . ($actionsconstruction['fin'] - time()) . ';
            function tempsDynamique' . $actionsconstruction['id'] . '(){
                if(valeur' . $actionsconstruction['id'] . ' > 0){
                    valeur' . $actionsconstruction['id'] . ' -= 1;
                    document.getElementById("affichage' . $actionsconstruction['id'] . '").innerHTML = affichageTemps(valeur' . $actionsconstruction['id'] . ');
                }
                else {
                    document.location.href="constructions.php";
                }
            }
            setInterval(tempsDynamique' . $actionsconstruction['id'] . ', 1000);
            </script>';
    }
    echo '</table></div>';
    finCarte();
}

include("includes/constantes.php"); // on actualise les constantes

// --- Defensive Formation Selector ---
$currentFormation = $constructions['formation'] ?? 0;
global $FORMATIONS;
debutCarte("Formation défensive");
echo '<form action="constructions.php" method="post">' . csrfField();
echo '<p style="margin:8px;">Choisissez votre formation défensive. Elle s\'applique automatiquement quand vous êtes attaqué.</p>';
foreach ($FORMATIONS as $id => $f) {
    $checked = ($currentFormation == $id) ? ' checked' : '';
    echo '<label style="display:block;padding:8px;margin:4px 8px;border:1px solid #ddd;border-radius:4px;cursor:pointer;">';
    echo '<input type="radio" name="formation" value="' . $id . '"' . $checked . ' style="margin-right:8px;"/>';
    echo '<strong>' . htmlspecialchars($f['name']) . '</strong> — ' . htmlspecialchars($f['desc']);
    echo '</label>';
}
echo '<div style="text-align:center;margin:12px 0;"><button type="submit" class="button button-fill">Changer de formation</button></div>';
echo '</form>';
finCarte();

debutCarte("Constructions");
debutListe();
foreach ($listeConstructions as $num => $b) {
    mepConstructions($b);
}
finListe();
finCarte();

include("includes/copyright.php");
