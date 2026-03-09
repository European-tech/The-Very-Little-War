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
    if ($newFormation >= 0 && $newFormation <= MAX_FORMATION_ID) { // FIX: use config constant instead of hardcoded 2
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
    // BLDG-P10-003: Block upgrade button when building is already at max level.
    if ($niveauActuel['niveau'] >= MAX_BUILDING_LEVEL) {
        $augmenter = '<strong>Niveau maximum atteint (' . MAX_BUILDING_LEVEL . ')</strong>';
    } elseif ($nb['nb'] < 2) {
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
    global $BUILDING_CONFIG;
    global $bonus;

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
                try {
                withTransaction($base, function() use ($base, $liste, $nomsRes, $nbRes, $constructions, $autre, $BUILDING_CONFIG, $bonus, &$information, &$erreur) {
                    // Re-check queue slots inside transaction to prevent TOCTOU race
                    $nbSlots = dbFetchOne($base, 'SELECT count(*) as nb FROM actionsconstruction WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
                    if ($nbSlots && $nbSlots['nb'] >= 2) {
                        $erreur = "Vous avez déjà deux constructions en cours."; return;
                    }
                    // Lock resources with FOR UPDATE
                    $ressources = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
                    if (!$ressources) { $erreur = "Une erreur est survenue."; return; }

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

                    // H-007: Re-compute building cost from the freshly locked level so that a race
                    // where another request completed a build between the outer check and this
                    // transaction cannot cause us to charge the wrong (stale) cost.
                    $bddKey = $liste['bdd'];
                    $freshCosts = [];
                    if (isset($BUILDING_CONFIG[$bddKey])) {
                        $bc = $BUILDING_CONFIG[$bddKey];
                        $growthBase = $bc['cost_growth_base'];
                        $costFactor = (1 - ($bonus / 100));
                        foreach (['energy', 'atoms', 'carbone', 'azote', 'oxygene'] as $costKey) {
                            $configKey = 'cost_' . $costKey . '_base';
                            if (isset($bc[$configKey])) {
                                $freshCosts['cout' . ucfirst($costKey === 'energy' ? 'Energie' : ucfirst($costKey))] =
                                    round($costFactor * $bc[$configKey] * pow($growthBase, $niveauActuel['niveau']));
                            }
                        }
                    }
                    // Map config keys to $liste keys (energy→coutEnergie, atoms→coutAtomes, etc.)
                    $costKeyMap = ['energy' => 'Energie', 'atoms' => 'Atomes', 'carbone' => 'Carbone', 'azote' => 'Azote', 'oxygene' => 'Oxygene'];
                    $freshEnergieCost = 0;
                    $freshAtomCosts = [];
                    if (isset($BUILDING_CONFIG[$bddKey])) {
                        $bc = $BUILDING_CONFIG[$bddKey];
                        $costFactor = (1 - ($bonus / 100));
                        foreach ($costKeyMap as $cfgSuffix => $listeSuffix) {
                            $cfgKey = 'cost_' . $cfgSuffix . '_base';
                            if (isset($bc[$cfgKey])) {
                                $freshVal = round($costFactor * $bc[$cfgKey] * pow($bc['cost_growth_base'], $niveauActuel['niveau']));
                                if ($listeSuffix === 'Energie') {
                                    $freshEnergieCost = $freshVal;
                                } elseif ($listeSuffix === 'Atomes') {
                                    // coutAtomes applies to all resource types equally
                                    foreach ($nomsRes as $num => $ressource) {
                                        $freshAtomCosts[$ressource] = $freshVal;
                                    }
                                } else {
                                    $freshAtomCosts[strtolower($listeSuffix)] = $freshVal;
                                }
                            }
                        }
                    } else {
                        // Fallback to pre-computed costs if config key missing
                        $freshEnergieCost = $liste['coutEnergie'];
                        foreach ($nomsRes as $num => $ressource) {
                            $freshAtomCosts[$ressource] = $liste['cout' . ucfirst($ressource)] ?? 0;
                        }
                    }

                    // Re-validate with locked resources against fresh costs
                    foreach ($nomsRes as $num => $ressource) {
                        $freshCost = $freshAtomCosts[$ressource] ?? 0;
                        if ($ressources[$ressource] < $freshCost) {
                            $erreur = "Vous n'avez pas assez de ressources."; return;
                        }
                    }
                    if ($ressources['energie'] < $freshEnergieCost) {
                        $erreur = "Vous n'avez pas assez de ressources."; return;
                    }

                    // BLDG15-001: Sanity check — building costs must be positive.
                    if ($freshEnergieCost < 0) {
                        logError('CONSTRUCTION', 'Negative coutEnergie detected', ['batiment' => $liste['bdd'], 'cost' => $freshEnergieCost, 'login' => $_SESSION['login']]);
                        throw new \RuntimeException('INVALID_COST');
                    }
                    foreach ($nomsRes as $num => $ressource) {
                        $atomCost = $freshAtomCosts[$ressource] ?? 0;
                        if ($atomCost < 0) {
                            logError('CONSTRUCTION', 'Negative atom cost for ' . $ressource, ['login' => $_SESSION['login']]);
                            throw new \RuntimeException('INVALID_COST');
                        }
                    }

                    // BUILDINGS-P20-003: Duplicate-building check BEFORE resource deduction so that
                    // a spurious duplicate does not commit with resources already subtracted.
                    // (Inside withTransaction; throw rolls back, return would commit.)
                    $existingBuild = dbFetchOne($base, 'SELECT id FROM actionsconstruction WHERE login = ? AND batiment = ?', 'ss', $_SESSION['login'], $liste['bdd']);
                    if ($existingBuild) {
                        throw new \RuntimeException('DUPLICATE_BUILD');
                    }

                    // Build dynamic UPDATE for ressources using fresh (locked-level) costs
                    $chaine = "";
                    $newEnergie = $ressources['energie'] - $freshEnergieCost;
                    $setClauses = ['energie=?'];
                    $paramTypes = 'd';
                    $paramValues = [$newEnergie];
                    foreach ($nomsRes as $num => $ressource) {
                        $setClauses[] = $ressource . '=?';
                        $paramTypes .= 'd';
                        $paramValues[] = $ressources[$ressource] - ($freshAtomCosts[$ressource] ?? 0);
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
                    // Re-compute construction time from locked level (BUILDINGS-H1: prevent race with stale pre-tx time)
                    if (isset($BUILDING_CONFIG[$bddKey])) {
                        $bc = $BUILDING_CONFIG[$bddKey];
                        $levelForTime = $niveauActuel['niveau'];
                        $offset = $bc['time_level_offset'] ?? 0;
                        $freshTime = (int)round($bc['time_base'] * pow($bc['time_growth_base'], $levelForTime + $offset));
                    } else {
                        $freshTime = $liste['tempsConstruction'];
                    }
                    $adjustedConstructionTime = (int)round($freshTime * (1 - catalystEffect('construction_speed')));
                    $finTemps = $tempsDebut + $adjustedConstructionTime;
                    // Note: no FOR UPDATE needed here — the COUNT(*) FOR UPDATE above already
                    // serializes concurrent queue insertions for this player.
                    dbExecute($base, 'INSERT INTO actionsconstruction (login, debut, fin, batiment, niveau, affichage, points) VALUES (?,?,?,?,?,?,?)', 'siisisi',
                        $_SESSION['login'], $tempsDebut, $finTemps, $liste['bdd'], $newNiveau, $liste['titre'], $liste['points']);

                    dbExecute($base, 'UPDATE autre SET energieDepensee = energieDepensee + ? WHERE login=?', 'ds', $freshEnergieCost, $_SESSION['login']);

                    $information = "La construction a bien été lancée.";
                });
                } catch (\RuntimeException $e) {
                    if ($e->getMessage() === 'DUPLICATE_BUILD') {
                        $erreur = "Ce bâtiment est déjà en cours de construction.";
                    } else {
                        $erreur = "Une erreur est survenue lors de la construction.";
                    }
                }
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
        echo '<tr><td>' . htmlspecialchars($actionsconstruction['affichage'], ENT_QUOTES, 'UTF-8') . ' <strong>niveau ' . (int)$actionsconstruction['niveau'] . '</strong></td><td><span id="affichage' . $actionsconstruction['id'] . '">' . affichageTemps(max(0, $actionsconstruction['fin'] - time())) . '</span></td><td>' . date('H\hi', $actionsconstruction['fin']) . '</td></tr>';
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
