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
        } else {
            $somme = $somme + $_POST['nbPoints' . $ressource];
        }
    }


    if ($bool) {
        try {
            withTransaction($base, function() use ($base, $nomsRes, $somme) {
                // Re-read with lock to prevent race condition (P5-GAP-011)
                $locked = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
                if ($somme > $locked['pointsProducteurRestants']) {
                    throw new \RuntimeException('NOT_ENOUGH_POINTS');
                }
                $existingPoints = explode(';', $locked['pointsProducteur']);
                $chaine = "";
                foreach ($nomsRes as $num => $ressource) {
                    $plus = ($num + 1 < count($nomsRes)) ? ";" : "";
                    $chaine = $chaine . ($_POST['nbPoints' . $ressource] + ($existingPoints[$num] ?? 0)) . $plus;
                }
                $newPoints = $locked['pointsProducteurRestants'] - $somme;
                dbExecute($base, 'UPDATE constructions SET pointsProducteurRestants=?, pointsProducteur=? WHERE login=?', 'iss', $newPoints, $chaine, $_SESSION['login']);
            });
            $information = "Les points du producteur ont été sauvegardés.";
            header('Location: constructions.php?information=' . urlencode($information));
            exit();
        } catch (\RuntimeException $e) {
            $erreur = "Le nombre de points n'est pas valide.";
        }
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
        } else {
            $somme = $somme + $_POST['nbPointsCondenseur' . $ressource];
        }
    }


    if ($bool) {
        try {
            withTransaction($base, function() use ($base, $nomsRes, $somme) {
                // Re-read with lock to prevent race condition (P5-GAP-011)
                $locked = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
                if ($somme > $locked['pointsCondenseurRestants']) {
                    throw new \RuntimeException('NOT_ENOUGH_POINTS');
                }
                $existingPoints = explode(';', $locked['pointsCondenseur']);
                $chaine = "";
                foreach ($nomsRes as $num => $ressource) {
                    $plus = ($num + 1 < count($nomsRes)) ? ";" : "";
                    $chaine = $chaine . ($_POST['nbPointsCondenseur' . $ressource] + ($existingPoints[$num] ?? 0)) . $plus;
                }
                $newPoints = $locked['pointsCondenseurRestants'] - $somme;
                dbExecute($base, 'UPDATE constructions SET pointsCondenseurRestants=?, pointsCondenseur=? WHERE login=?', 'iss', $newPoints, $chaine, $_SESSION['login']);
            });
            $information = "Les points du condenseur ont été sauvegardés.";
            header('Location: constructions.php?information=' . urlencode($information));
            exit();
        } catch (\RuntimeException $e) {
            $erreur = "Le nombre de points n'est pas valide.";
        }
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
    global $BUILDING_CONFIG;

    // on doit calculer le niveau actuel (et dans le futur avec les constructions le précédant)
    $niveauActuel = dbFetchOne($base, 'SELECT niveau FROM actionsconstruction WHERE login=? AND batiment=? ORDER BY niveau DESC', 'ss', $_SESSION['login'], $liste['bdd']);
    if (!$niveauActuel) {
        $niveauActuel['niveau'] = $liste['niveau'];
    }

    if (array_key_exists("progressBar", $liste) && $liste['progressBar']) {
        $media = '<img alt="za" src="' . $liste['image'] . '" style="width:80px;height:80px;margin-top:-54px;"/><div style="margin-left:-80px;margin-top:-10px;">' . progressBar($liste['vie'], $liste['vieMax'], "green") . '</div>';
    } else {
        $media = '<img alt="za" src="' . $liste['image'] . '" style="width:80px;height:80px;"/>';
    }

    // Build cost tooltip from building config
    $bddKey = $liste['bdd'];
    $costTooltip = '';
    if (isset($BUILDING_CONFIG[$bddKey])) {
        $bc = $BUILDING_CONFIG[$bddKey];
        $growthBase = $bc['cost_growth_base'];
        $costTooltip = 'Formule du coût : base × ' . htmlspecialchars($growthBase) . '^niveau (−bonus médaille)';
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

    // Wrap cost chips in a span with formula tooltip
    if ($costTooltip && $cout) {
        $cout = '<span title="' . $costTooltip . '">' . $cout . '</span>';
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
        if ($liste['coutEnergie'] > $ressources['energie'] or $bool == 0) {
            $bool1 = 1;

            foreach ($nomsRes as $num => $ressource) {
                if ($liste['cout' . ucfirst($ressource)] > $placeDepot) {
                    $bool1 = 0;
                }
            }

            if ($placeDepot >= $liste['coutEnergie'] and $bool1 == 1) {
                $max = ($revenu['energie'] > 0)
                    ? SECONDS_PER_HOUR * ($liste['coutEnergie'] - $ressources['energie']) / $revenu['energie']
                    : PHP_INT_MAX / 2;
                foreach ($nomsRes as $num => $ressource) {
                    $val = ($revenu[$ressource] > 0)
                        ? SECONDS_PER_HOUR * ($liste['cout' . ucfirst($ressource)] - $ressources[$ressource]) / $revenu[$ressource]
                        : PHP_INT_MAX / 2;
                    $max = max($val, $max);
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
    $drainageTooltip = '';
    if (array_key_exists("drainage", $liste) && $liste['drainage']) {
        $drainageTooltip = 'Drainage : ' . htmlspecialchars(PRODUCTEUR_DRAIN_PER_LEVEL) . ' × ' . htmlspecialchars(ECO_GROWTH_BASE) . '^niveau';
        $drainage = '<span title="' . $drainageTooltip . '">' . nombreEnergie('<span style="color:red">-' . $liste['drainage'] . '/h</span>') . '</span>';
    }

    // Build time tooltip
    $timeTooltip = '';
    if (isset($BUILDING_CONFIG[$bddKey])) {
        $bc = $BUILDING_CONFIG[$bddKey];
        $offset = isset($bc['time_level_offset']) ? ' + ' . $bc['time_level_offset'] : '';
        $timeTooltip = 'Temps : ' . htmlspecialchars($bc['time_base']) . 's × ' . htmlspecialchars($bc['time_growth_base']) . '^(niveau' . htmlspecialchars($offset) . ')';
    }
    $tempsDisplay = nombreTemps(affichageTemps(round($liste['tempsConstruction'] * (1 - catalystEffect('construction_speed')))));
    if ($timeTooltip) {
        $tempsDisplay = '<span title="' . $timeTooltip . '">' . $tempsDisplay . '</span>';
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
          ' . $cout . $drainage . $tempsDisplay . nombrePoints('+' . $liste['points']) . '<br/><br/>
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
    global $information;
    global $erreur;

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
                    // Re-check queue slots inside transaction to prevent TOCTOU race
                    $nbSlots = dbFetchOne($base, 'SELECT count(*) as nb FROM actionsconstruction WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
                    if ($nbSlots && $nbSlots['nb'] >= 2) {
                        $erreur = "Vous avez déjà deux constructions en cours."; return;
                    }
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

                    // Whitelist $liste['bdd'] before using as constructions array key
                    $validBuildingCols = ['generateur', 'producteur', 'depot', 'champdeforce', 'ionisateur', 'condenseur', 'lieur', 'stabilisateur', 'coffrefort'];
                    if (!in_array($liste['bdd'], $validBuildingCols, true)) {
                        $erreur = "Bâtiment invalide."; return;
                    }

                    // Re-fetch current building level inside transaction with FOR UPDATE to avoid stale snapshot
                    $currentBuilding = dbFetchOne($base, 'SELECT ' . $liste['bdd'] . ' AS niveau FROM constructions WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
                    $currentLevel = $currentBuilding ? (int)$currentBuilding['niveau'] : 0;

                    // Check max level BEFORE deducting resources
                    $niveauActuel = dbFetchOne($base, 'SELECT niveau FROM actionsconstruction WHERE login=? AND batiment=? ORDER BY niveau DESC', 'ss', $_SESSION['login'], $liste['bdd']);

                    if (!$niveauActuel) {
                        $niveauActuel['niveau'] = $currentLevel;
                    }

                    $newNiveau = $niveauActuel['niveau'] + 1;
                    if ($newNiveau > MAX_BUILDING_LEVEL) {
                        $erreur = "Niveau maximum atteint (" . MAX_BUILDING_LEVEL . ")."; return;
                    }

                    // Build dynamic UPDATE for ressources - these are computed values, not user input
                    $chaine = "";
                    $newEnergie = $ressources['energie'] - $liste['coutEnergie'];
                    $setClauses = ['energie=?'];
                    $paramTypes = 'd';
                    $paramValues = [$newEnergie];
                    foreach ($nomsRes as $num => $ressource) {
                        $setClauses[] = $ressource . '=?';
                        $paramTypes .= 'd';
                        $paramValues[] = $ressources[$ressource] - $liste['cout' . ucfirst($ressource)];
                    }
                    $paramTypes .= 's';
                    $paramValues[] = $_SESSION['login'];
                    dbExecute($base, 'UPDATE ressources SET ' . implode(', ', $setClauses) . ' WHERE login=?', $paramTypes, ...$paramValues);

                    $lastConstruction = dbFetchOne($base, 'SELECT * FROM actionsconstruction WHERE login=? ORDER BY fin DESC', 's', $_SESSION['login']);
                    if ($lastConstruction) {
                        $tempsDebut = $lastConstruction['fin'];
                    } else {
                        $tempsDebut = time();
                    }
                    $adjustedConstructionTime = round($liste['tempsConstruction'] * (1 - catalystEffect('construction_speed')));
                    $finTemps = $tempsDebut + $adjustedConstructionTime;
                    // Note: no FOR UPDATE needed here — the COUNT(*) FOR UPDATE above already
                    // serializes concurrent queue insertions for this player.
                    dbExecute($base, 'INSERT INTO actionsconstruction VALUES(default,?,?,?,?,?,?,?)', 'siisisi',
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

include("includes/layout.php");

$actionsconstructionRows = dbFetchAll($base, 'SELECT * FROM actionsconstruction WHERE login=?', 's', $_SESSION['login']);
$nb = count($actionsconstructionRows);
if ($nb > 0) {
    debutCarte();
    scriptAffichageTemps();
    echo '<div class="table-responsive"><table><tr><th>Constructions</th><th>Temps restant</th><th>Fin</th></tr>';
    foreach ($actionsconstructionRows as $actionsconstruction) {
        echo '<tr><td>' . $actionsconstruction['affichage'] . ' <strong>niveau ' . $actionsconstruction['niveau'] . '</strong></td><td><span id="affichage' . $actionsconstruction['id'] . '">' . affichageTemps(max(0, $actionsconstruction['fin'] - time())) . '</span></td><td>' . date('H\hi', $actionsconstruction['fin']) . '</td></tr>';
        echo cspScriptTag() . '
            var valeur' . $actionsconstruction['id'] . ' = ' . (int)($actionsconstruction['fin'] - time()) . ';
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

require_once("includes/constantesBase.php");
initPlayer($_SESSION['login']); // on actualise les constantes

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
