<?php
/**
 * Player Management Module
 * Player status, registration, initialization, buildings, alliances, etc.
 */

function statut($joueur)
{
    global $base;
    $actifs = dbFetchOne($base, 'SELECT count(*) AS nb FROM membre WHERE derniereConnexion >= ? AND x!=-1000 AND login=?', 'is', (time() - ACTIVE_PLAYER_THRESHOLD), $joueur);

    if ($actifs['nb'] == 1) {
        return 1;
    } else {
        return 0;
    }
}

function compterActifs()
{
    global $base;
    $nb = dbFetchOne($base, 'SELECT count(*) AS nb FROM membre WHERE derniereConnexion >= ? AND x!=-1000', 'i', (time() - ACTIVE_PLAYER_THRESHOLD));

    return $nb['nb'];
}

/**
 * Register a new player.
 *
 * Returns true on success.
 * Returns 'login_taken'  when the login is already in use (TOCTOU-safe:
 *                         detected via DB UNIQUE constraint errno 1062).
 * Returns 'email_taken'  when the email is already in use (same guarantee).
 * Returns false          on any other DB error.
 */
function inscrire($pseudo, $mdp, $mail)
{
    global $base;
    global $REGISTRATION_ELEMENT_THRESHOLDS;
    $alea = mt_rand(1, REGISTRATION_RANDOM_MAX);
    foreach ($REGISTRATION_ELEMENT_THRESHOLDS as $index => $threshold) {
        if ($alea <= $threshold) {
            $alea = $index;
            break;
        }
    }

    $safePseudo = antihtml(trim($pseudo));
    $safeMail = antihtml(trim($mail));
    $hashedPassword = password_hash($mdp, PASSWORD_DEFAULT);
    $now = time();
    $timestamps = $now . ',' . $now . ',' . $now . ',' . $now;
    $vieGen = pointsDeVie(1);
    $vieCDF = vieChampDeForce(0);

    try {
        withTransaction($base, function() use ($base, $safePseudo, $hashedPassword, $now, $timestamps, $alea, $safeMail, $vieGen, $vieCDF) {
            // Explicit column lists guard against positional mismatches after schema migrations.
            // Only non-auto-increment, non-defaulted columns are listed; everything else uses DB defaults.

            // membre: id=auto_increment, session_token=NULL default, vacance/moderateur/codeur have defaults.
            // Use a raw prepared statement so we can read errno on UNIQUE constraint violation (1062)
            // before the connection state resets — gives TOCTOU-safe duplicate detection.
            $stmt = mysqli_prepare($base,
                'INSERT INTO membre (login, pass_md5, timestamp, ip, derniereConnexion, troll, email, x, y)
                 VALUES (?, ?, ?, ?, ?, ?, ?, -1000, -1000)'
            );
            if (!$stmt) {
                throw new \RuntimeException('prepare:membre:' . mysqli_error($base));
            }
            mysqli_stmt_bind_param($stmt, 'ssssiis',
                $safePseudo, $hashedPassword, $now, $_SERVER['REMOTE_ADDR'], $now, $alea, $safeMail
            );
            if (!mysqli_stmt_execute($stmt)) {
                $errno  = mysqli_stmt_errno($stmt);
                $errmsg = mysqli_stmt_error($stmt);
                mysqli_stmt_close($stmt);
                if ($errno === 1062) {
                    // MariaDB error text includes key name, e.g.:
                    // "Duplicate entry 'foo@bar.com' for key 'uq_membre_email'"
                    throw new \RuntimeException('duplicate:' . $errmsg);
                }
                throw new \RuntimeException('insert:membre:' . $errmsg);
            }
            mysqli_stmt_close($stmt);

            // autre: login is PK; tempsPrecedent must be set now; timeMolecule needs initial timestamps;
            // description and missions are NOT NULL with no DB default — supply explicit values.
            // all other columns (points, idalliance, nbattaques, etc.) use DB defaults (0 or NULL)
            dbExecute(
                $base,
                'INSERT INTO autre (login, tempsPrecedent, timeMolecule, description, missions)
                 VALUES (?, ?, ?, ?, ?)',
                'sisss',
                $safePseudo, $now, $timestamps, 'Pas de description', ''
            );

            // ressources: id=auto_increment; all resource/revenue columns default to their start values
            dbExecute(
                $base,
                'INSERT INTO ressources (login) VALUES (?)',
                's',
                $safePseudo
            );

            // Atomic increment: avoids TOCTOU race when two registrations happen concurrently.
            dbExecute($base, 'UPDATE statistiques SET inscrits = inscrits + 1');

            // molecules: 4 rows, one per numeroclasse; remaining columns (atoms, nombre, isotope) default to 0
            dbExecute(
                $base,
                'INSERT INTO molecules (numeroclasse, proprietaire)
                 VALUES (1, ?), (2, ?), (3, ?), (4, ?)',
                'ssss',
                $safePseudo, $safePseudo, $safePseudo, $safePseudo
            );

            // constructions: login is PK; vieGenerateur/vieChampdeforce/vieProducteur/vieDepot need
            // computed starting HP; building levels and other vie columns default to 0
            dbExecute(
                $base,
                'INSERT INTO constructions (login, vieGenerateur, vieChampdeforce, vieProducteur, vieDepot)
                 VALUES (?, ?, ?, ?, ?)',
                'sdddd',
                $safePseudo, $vieGen, $vieCDF, $vieGen, $vieGen
            );

            dbExecute($base, 'INSERT IGNORE INTO prestige (login) VALUES(?)', 's', $safePseudo);
        });
    } catch (\RuntimeException $e) {
        $msg = $e->getMessage();
        if (str_starts_with($msg, 'duplicate:')) {
            // Identify which unique key was violated from the MariaDB error text.
            if (stripos($msg, 'email') !== false) {
                return 'email_taken';
            }
            return 'login_taken';
        }
        error_log('inscrire() DB error: ' . $msg);
        return false;
    }

    return true;
}

function ajouterPoints($nb, $joueur, $type = 0)
{
    global $base;

    if ($type == 0) {
        // Construction points — update raw, then recompute sqrt total
        $result = dbExecute($base, 'UPDATE autre SET points = points + ? WHERE login = ? AND points + ? >= 0', 'dsd', $nb, $joueur, $nb);
        if (mysqli_affected_rows($base) > 0) {
            recalculerTotalPointsJoueur($base, $joueur);
            return $nb;
        }
        return 0;
    }

    $result = 0;
    withTransaction($base, function() use ($base, $joueur, $type, $nb, &$result) {
        $points = dbFetchOne($base, 'SELECT * FROM autre WHERE login=? FOR UPDATE', 's', $joueur);
        if (!$points) return;

        if ($type == 1) {
            $newPoints = max(0, $points['pointsAttaque'] + $nb);
            dbExecute($base, 'UPDATE autre SET pointsAttaque=? WHERE login=?', 'ds', $newPoints, $joueur);
            recalculerTotalPointsJoueur($base, $joueur);
            $result = -pointsAttaque($points['pointsAttaque']) + pointsAttaque($newPoints);
        }
        if ($type == 2) {
            $newPoints = max(0, $points['pointsDefense'] + $nb);
            dbExecute($base, 'UPDATE autre SET pointsDefense=? WHERE login=?', 'ds', $newPoints, $joueur);
            recalculerTotalPointsJoueur($base, $joueur);
            $result = -pointsDefense($points['pointsDefense']) + pointsDefense($newPoints);
        }
        if ($type == 3) {
            $newPillage = $points['ressourcesPillees'] + $nb;
            dbExecute($base, 'UPDATE autre SET ressourcesPillees=? WHERE login=?', 'ds', $newPillage, $joueur);
            recalculerTotalPointsJoueur($base, $joueur);
            $result = chiffrePetit($nb, 0);
        }
    });
    return $result;
}

function initPlayer($joueur)
{
    // Per-request cache stored in $GLOBALS so it can be invalidated externally
    // (e.g. after augmenterBatiment writes new DB data).
    // Avoids 60-80 redundant queries when initPlayer is called multiple times
    // per page load.
    if (!isset($GLOBALS['_initPlayerCache'])) {
        $GLOBALS['_initPlayerCache'] = [];
    }
    if (isset($GLOBALS['_initPlayerCache'][$joueur])) {
        foreach ($GLOBALS['_initPlayerCache'][$joueur] as $key => $value) {
            $GLOBALS[$key] = $value;
        }
        return;
    }

    global $nomsRes;
    global $base;
    global $paliersConstructeur;
    global $BUILDING_CONFIG;
    global $bonusMedailles;
    global $ressources;
    global $revenu;
    foreach ($nomsRes as $num => $ressource) {
        global ${'revenu' . $ressource};
    }
    global $constructions;
    global $autre;
    foreach ($nomsRes as $num => $ressource) {
        global ${'points' . $ressource};
        global ${'niveau' . $ressource};
    }
    global $membre;
    global $revenuEnergie;
    global $placeDepot;
    global $points;
    global $plusHaut;
    global $production;
    global $productionCondenseur;
    global $listeConstructions;


    $ressources = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $joueur);

    foreach ($nomsRes as $num => $ressource) {
        ${'revenu' . $ressource} = revenuAtome($num, $joueur);
        $revenu[$ressource] = revenuAtome($num, $joueur);
    }

    // AUTRES

    $constructions = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $joueur);

    $niveaux = explode(';', $constructions['pointsProducteur']);
    $niveauxAtomes = explode(';', $constructions['pointsCondenseur']);
    foreach ($nomsRes as $num => $ressource) {
        ${'points' . $ressource} = $niveaux[$num];
        ${'niveau' . $ressource} = $niveauxAtomes[$num];
    }

    $autre = dbFetchOne($base, 'SELECT * FROM autre WHERE login=?', 's', $joueur);

    $membre = dbFetchOne($base, 'SELECT * FROM membre WHERE login=?', 's', $joueur);

    $revenuEnergie = revenuEnergie($constructions['generateur'], $joueur);
    $revenu['energie'] = $revenuEnergie;
    $placeDepot = placeDepot($constructions['depot']);


    // CONSTRUCTIONS

    $specCondMod = getSpecModifier($joueur, 'condenseur_points');
    $points = ['condenseur' => max(1, $BUILDING_CONFIG['condenseur']['points_per_level'] + (int)$specCondMod), 'producteur' => sizeof($nomsRes)];

    $plusHaut = batMax($joueur);
    dbExecute($base, 'UPDATE autre SET batmax=? WHERE login=?', 'is', $plusHaut, $joueur);

    $bonus = 0;
    foreach ($paliersConstructeur as $num => $palier) {
        if ($plusHaut >= $palier) {
            $bonus = $bonusMedailles[$num];
        }
    }

    $max = 0;
    foreach ($nomsRes as $num => $ressource) {
        $max = max($max, SECONDS_PER_HOUR * ($placeDepot - $ressources[$ressource]) / max(1, $revenu[$ressource]));
    }

    $production = '<strong><span id="nbPointsRestants">' . $constructions['pointsProducteurRestants'] . '</span> points</strong> à placer<br/><form method="post" action="constructions.php" name="formPointsProducteur">';
    revenuAtomeJavascript($joueur); // ecrit la fonction en javscript qui donne la production pour un nombre de points
    foreach ($nomsRes as $num => $ressource) {
        $production = $production . nombreAtome($num, '<span style="color:green">+<span id="nbPointsAffichage' . $ressource . '">' . $revenu[$ressource] . '</span>/h</span> <input type="hidden" value="0" id="nbPoints' . $ressource . '" name="nbPoints' . $ressource . '"/><a href="#"><img class="imageAide" src="images/add.png" alt="add" style="margin-left:10px" id="add' . $ressource . '"/></a>');

        $production = $production . '
        ' . cspScriptTag() . '
            document.getElementById("add' . $ressource . '").addEventListener("click",function(){
                var pointsRestants = parseInt(document.getElementById("nbPointsRestants").textContent);
                if(pointsRestants > 0){
                    document.getElementById("nbPointsRestants").textContent = pointsRestants-1;
                    document.getElementById("nbPoints' . $ressource . '").value++;
                    document.getElementById("nbPointsAffichage' . $ressource . '").textContent = revenuAtomeJavascript(parseInt(document.getElementById("nbPoints' . $ressource . '").value)+parseInt(' . ${'points' . $ressource} . '));
                }
            });
        </script>';
    }
    $production = $production . '<br/><br/><center><input type="image" class="w32" style="margin-right:20px" src="images/yes.png" name="actioninvitation" value="Sauvegarder"/></form><a href="constructions.php"><img src="images/croix.png" class="w32" style="margin-left:20px"  alt="Ne pas sauvegarder"/></a></center>';

    $bonusDuplicateur = 1;
    if ($autre['idalliance'] > 0) {
        $duplicateur = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', 'i', $autre['idalliance']);
        $bonusDuplicateur = 1 + ($duplicateur['duplicateur'] * DUPLICATEUR_BONUS_PER_LEVEL);
    }

    $productionCondenseur = '<strong><span id="nbPointsCondenseurRestants">' . $constructions['pointsCondenseurRestants'] . '</span> points</strong> à placer<br/><form method="post" action="constructions.php" name="formPointsCondenseur">';
    foreach ($nomsRes as $num => $ressource) {
        $productionCondenseur = $productionCondenseur . nombreAtome($num, 'Niveau <span id="nbPointsCondenseurAffichage' . $ressource . '">' . ${'niveau' . $ressource} . '</span><input type="hidden" value="0" id="nbPointsCondenseur' . $ressource . '" name="nbPointsCondenseur' . $ressource . '"/><a href="#"><img class="imageAide" src="images/add.png" alt="add" style="margin-left:10px" id="addCondenseur' . $ressource . '"/></a>');

        $productionCondenseur = $productionCondenseur . '
        ' . cspScriptTag() . '
            document.getElementById("addCondenseur' . $ressource . '").addEventListener("click",function(){
                var pointsRestants = parseInt(document.getElementById("nbPointsCondenseurRestants").textContent);
                if(pointsRestants > 0){
                    document.getElementById("nbPointsCondenseurRestants").textContent = pointsRestants-1;
                    document.getElementById("nbPointsCondenseur' . $ressource . '").value++;
                    document.getElementById("nbPointsCondenseurAffichage' . $ressource . '").textContent=parseInt(document.getElementById("nbPointsCondenseur' . $ressource . '").value)+parseInt(' . ${'niveau' . $ressource} . ');
                }
            });
        </script>';
    }
    $productionCondenseur = $productionCondenseur . '<br/><br/><center><input type="image" class="w32" style="margin-right:20px" src="images/yes.png" name="actioninvitation" value="Sauvegarder"/></form><a href="constructions.php"><img src="images/croix.png" class="w32" style="margin-left:20px"  alt="Ne pas sauvegarder"/></a></center>';


    /////////////////////////////////////////
    // Optimization: single query for all building queues instead of 9 separate queries.
    // Build a lookup: batiment => highest queued niveau (the next level being built).
    $allQueues = dbFetchAll($base, 'SELECT batiment, MAX(niveau) AS niveau FROM actionsconstruction WHERE login=? GROUP BY batiment', 's', $joueur);
    $queuedNiveaux = [];
    foreach ($allQueues as $row) {
        $queuedNiveaux[$row['batiment']] = (int) $row['niveau'];
    }

    // generateur
    if (isset($queuedNiveaux['generateur'])) {
        $niveauActuel = ['niveau' => $queuedNiveaux['generateur']];
    } else {
        $niveauActuel = ['niveau' => $constructions['generateur']];
    }

    if ($niveauActuel['niveau'] == 0) {
        $tempsGenerateur = $BUILDING_CONFIG['generateur']['time_level1'];
    } else {
        $tempsGenerateur = round($BUILDING_CONFIG['generateur']['time_base'] * pow($BUILDING_CONFIG['generateur']['time_growth_base'], $niveauActuel['niveau']));
    }

    // producteur
    if (isset($queuedNiveaux['producteur'])) {
        $niveauActuel1 = ['niveau' => $queuedNiveaux['producteur']];
    } else {
        $niveauActuel1 = ['niveau' => $constructions['producteur']];
    }

    if ($niveauActuel1['niveau'] == 0) {
        $tempsProducteur = $BUILDING_CONFIG['producteur']['time_level1'];
    } else {
        $tempsProducteur = round($BUILDING_CONFIG['producteur']['time_base'] * pow($BUILDING_CONFIG['producteur']['time_growth_base'], $niveauActuel1['niveau']));
    }

    // depot
    if (isset($queuedNiveaux['depot'])) {
        $niveauActuelDepot = ['niveau' => $queuedNiveaux['depot']];
    } else {
        $niveauActuelDepot = ['niveau' => $constructions['depot']];
    }

    // champdeforce
    if (isset($queuedNiveaux['champdeforce'])) {
        $niveauActuelChampDeForce = ['niveau' => $queuedNiveaux['champdeforce']];
    } else {
        $niveauActuelChampDeForce = ['niveau' => $constructions['champdeforce']];
    }

    // ionisateur
    if (isset($queuedNiveaux['ionisateur'])) {
        $niveauActuelIonisateur = ['niveau' => $queuedNiveaux['ionisateur']];
    } else {
        $niveauActuelIonisateur = ['niveau' => $constructions['ionisateur']];
    }

    // condenseur
    if (isset($queuedNiveaux['condenseur'])) {
        $niveauActuelCondenseur = ['niveau' => $queuedNiveaux['condenseur']];
    } else {
        $niveauActuelCondenseur = ['niveau' => $constructions['condenseur']];
    }

    // lieur
    if (isset($queuedNiveaux['lieur'])) {
        $niveauActuelLieur = ['niveau' => $queuedNiveaux['lieur']];
    } else {
        $niveauActuelLieur = ['niveau' => $constructions['lieur']];
    }

    // stabilisateur
    if (isset($queuedNiveaux['stabilisateur'])) {
        $niveauActuelStabilisateur = ['niveau' => $queuedNiveaux['stabilisateur']];
    } else {
        $niveauActuelStabilisateur = ['niveau' => $constructions['stabilisateur']];
    }

    // coffrefort
    if (isset($queuedNiveaux['coffrefort'])) {
        $niveauActuelCoffrefort = ['niveau' => $queuedNiveaux['coffrefort']];
    } else {
        $niveauActuelCoffrefort = ['niveau' => $constructions['coffrefort'] ?? 0];
    }

    $listeConstructions = [
        'generateur' => [
            'titre' => 'Générateur',
            'bdd' => 'generateur',
            'image' => 'images/batiments/generateur.png',
            'progressBar' => true,
            'niveau' => $constructions['generateur'],
            'revenu' => '<span title="Revenu : ' . BASE_ENERGY_PER_LEVEL . ' × niveau × duplicateur × médaille × iode × prestige − drainage">' . nombreEnergie('<span style="color:green" >+' . chiffrePetit(revenuEnergie($constructions['generateur'], $joueur, 4)) . '/h</span>') . '</span>',
            'revenu1' => '<span title="Revenu : ' . BASE_ENERGY_PER_LEVEL . ' × niveau × duplicateur × médaille × iode × prestige − drainage">' . nombreEnergie('<span style="color:green" >+' . chiffrePetit(revenuEnergie($niveauActuel['niveau'] + 1, $joueur, 4)) . '/h</span>') . '</span>',
            'effetSup' => ($revenuEnergie > 0
                ? '<br/><br/><strong>Stockage plein : </strong>' . date('d/m/Y', time() + SECONDS_PER_HOUR * ($placeDepot - $ressources['energie']) / $revenuEnergie) . ' à ' . date('H\hi', time() + SECONDS_PER_HOUR * ($placeDepot - $ressources['energie']) / $revenuEnergie)
                : '<br/><br/><strong>Stockage plein : </strong>Jamais (production insuffisante)'),
            'description' => 'Le générateur <strong>produit de l\'énergie</strong>.',
            'coutEnergie' => round((1 - ($bonus / 100)) * $BUILDING_CONFIG['generateur']['cost_energy_base'] * pow($BUILDING_CONFIG['generateur']['cost_growth_base'], $niveauActuel['niveau'])),
            'coutAtomes' => round((1 - ($bonus / 100)) * $BUILDING_CONFIG['generateur']['cost_atoms_base'] * pow($BUILDING_CONFIG['generateur']['cost_growth_base'], $niveauActuel['niveau'])),
            'points' => $BUILDING_CONFIG['generateur']['points_base'] + floor($niveauActuel['niveau'] * $BUILDING_CONFIG['generateur']['points_level_factor']),
            'tempsConstruction' => $tempsGenerateur,
            'vie' => $constructions['vieGenerateur'],
            'vieMax' => pointsDeVie($constructions['generateur'])
        ],

        'producteur' => [
            'titre' => 'Producteur',
            'bdd' => 'producteur',
            'image' => 'images/batiments/producteur.png',
            'progressBar' => true,
            'niveau' => $constructions['producteur'],
            'revenu' => $constructions['pointsProducteurRestants'] . ' points restants',
            'revenu1' => '+' . $points['producteur'] . ' points à placer',
            'effetSup' => '<br/><br/><strong>Stockage plein : </strong>' . date('d/m/Y', time() + $max) . ' à ' . date('H\hi', time() + $max) . '<br/><br/>
                    ' . important("Production") . '
                    ' . $production,
            'description' => 'Le producteur <strong>produit des atomes</strong> à partir d\'énergie. A chaque niveau supplémentaire, vous obtenez un certain nombre de points qu\'il faut placer dans les atomes que vous voulez produire. Plus il y a de points dans un atome, plus sa production sera grande.',
            'coutEnergie' => round((1 - ($bonus / 100)) * $BUILDING_CONFIG['producteur']['cost_energy_base'] * pow($BUILDING_CONFIG['producteur']['cost_growth_base'], $niveauActuel1['niveau'])),
            'coutAtomes' => round((1 - ($bonus / 100)) * $BUILDING_CONFIG['producteur']['cost_atoms_base'] * pow($BUILDING_CONFIG['producteur']['cost_growth_base'], $niveauActuel1['niveau'])),
            'drainage' => drainageProducteur($niveauActuel1['niveau'] + 1),
            'points' => $BUILDING_CONFIG['producteur']['points_base'] + floor($niveauActuel1['niveau'] * $BUILDING_CONFIG['producteur']['points_level_factor']),
            'tempsConstruction' => $tempsProducteur,
            'vie' => $constructions['vieProducteur'],
            'vieMax' => pointsDeVie($constructions['producteur']),
        ],

        'depot' =>  [
            'titre' => 'Stockage',
            'bdd' => 'depot',
            'image' => 'images/batiments/depot.png',
            'progressBar' => true,
            'niveau' => $constructions['depot'],
            'revenu' => '<span title="Stockage : ' . BASE_STORAGE_INITIAL . ' × ' . ECO_GROWTH_BASE . '^niveau">' . chiffrePetit($placeDepot) . ' ressources max</span>',
            'revenu1' => '<span title="Stockage : ' . BASE_STORAGE_INITIAL . ' × ' . ECO_GROWTH_BASE . '^niveau">' . chiffrePetit(placeDepot($niveauActuelDepot['niveau'] + 1)) . ' ressources max</span>',
            'effetSup' => '',
            'description' => 'Le stockage est l\'endroit où sont stockés les ressources. Il détermine donc <strong>la quantité maximale de ressources</strong> que l\'on peut avoir.',
            'coutEnergie' => round((1 - ($bonus / 100)) * $BUILDING_CONFIG['depot']['cost_energy_base'] * pow($BUILDING_CONFIG['depot']['cost_growth_base'], $niveauActuelDepot['niveau'])),
            'points' => $BUILDING_CONFIG['depot']['points_base'] + floor($niveauActuelDepot['niveau'] * $BUILDING_CONFIG['depot']['points_level_factor']),
            'tempsConstruction' => round($BUILDING_CONFIG['depot']['time_base'] * pow($BUILDING_CONFIG['depot']['time_growth_base'], $niveauActuelDepot['niveau'])),
            'vie' => $constructions['vieDepot'],
            'vieMax' => pointsDeVie($constructions['depot'])
        ],


        'champdeforce' => [
            'titre' => 'Champ de force',
            'bdd' => 'champdeforce',
            'image' => 'images/batiments/champdeforce.png',
            'progressBar' => true,
            'niveau' => $constructions['champdeforce'],
            'revenu' => chip('+' . floor($bonusDuplicateur * $constructions['champdeforce'] * CHAMPDEFORCE_COMBAT_BONUS_PER_LEVEL) . '%', '<img src="images/batiments/shield.png" alt="shield" style="border-radius:0px;height:20px;width:20px" />', "white", "", true),
            'revenu1' => chip('+' . floor($bonusDuplicateur * ($niveauActuelChampDeForce['niveau'] + 1) * CHAMPDEFORCE_COMBAT_BONUS_PER_LEVEL) . '%', '<img src="images/batiments/shield.png" alt="shield" style="border-radius:0px;height:20px;width:20px" />', "white", "", true),
            'effetSup' => '',
            'description' => 'Le champ de force vous protège des attaques adverses en donnant un <strong>bonus de défense</strong> lorsque vous êtes attaqué. Il prend aussi <strong>les dégâts des attaques adverses</strong> en premier si son niveau est superieur aux autres bâtiments.',
            'coutCarbone' => round((1 - ($bonus / 100)) * $BUILDING_CONFIG['champdeforce']['cost_carbone_base'] * pow($BUILDING_CONFIG['champdeforce']['cost_growth_base'], $niveauActuelChampDeForce['niveau'])),
            'points' => $BUILDING_CONFIG['champdeforce']['points_base'] + floor($niveauActuelChampDeForce['niveau'] * $BUILDING_CONFIG['champdeforce']['points_level_factor']),
            'tempsConstruction' => round($BUILDING_CONFIG['champdeforce']['time_base'] * pow($BUILDING_CONFIG['champdeforce']['time_growth_base'], $niveauActuelChampDeForce['niveau'] + $BUILDING_CONFIG['champdeforce']['time_level_offset'])),
            'vie' => $constructions['vieChampdeforce'],
            'vieMax' => vieChampDeForce($constructions['champdeforce'])
        ],

        'ionisateur' => [
            'titre' => 'Ionisateur',
            'bdd' => 'ionisateur',
            'image' => 'images/batiments/ionisateur.png',
            'progressBar' => false,
            'niveau' => $constructions['ionisateur'],
            'revenu' => chip('+' . floor($bonusDuplicateur * $constructions['ionisateur'] * IONISATEUR_COMBAT_BONUS_PER_LEVEL) . '%', '<img src="images/batiments/sword.png" alt="shield" style="border-radius:0px;height:20px;width:20px" />', "white", "", true),
            'revenu1' => chip('+' . floor($bonusDuplicateur * ($niveauActuelIonisateur['niveau'] + 1) * IONISATEUR_COMBAT_BONUS_PER_LEVEL) . '%', '<img src="images/batiments/sword.png" alt="shield" style="border-radius:0px;height:20px;width:20px" />', "white", "", true),
            'effetSup' => '',
            'description' => 'L\'ionisateur améliore votre capacité offensive en donnant un <strong>bonus d\'attaque</strong> à vos molécules.',
            'coutOxygene' => round((1 - ($bonus / 100)) * $BUILDING_CONFIG['ionisateur']['cost_oxygene_base'] * pow($BUILDING_CONFIG['ionisateur']['cost_growth_base'], $niveauActuelIonisateur['niveau'])),
            'points' => $BUILDING_CONFIG['ionisateur']['points_base'] + floor($niveauActuelIonisateur['niveau'] * $BUILDING_CONFIG['ionisateur']['points_level_factor']),
            'tempsConstruction' => round($BUILDING_CONFIG['ionisateur']['time_base'] * pow($BUILDING_CONFIG['ionisateur']['time_growth_base'], $niveauActuelIonisateur['niveau'] + $BUILDING_CONFIG['ionisateur']['time_level_offset']))
        ],

        'condenseur' => [
            'titre' => 'Condenseur',
            'bdd' => 'condenseur',
            'image' => 'images/batiments/condenseur.png',
            'progressBar' => false,
            'niveau' => $constructions['condenseur'],
            'revenu' => $constructions['pointsCondenseurRestants'] . ' points restants',
            'revenu1' => '+' . $points['condenseur'] . ' points à placer',
            'effetSup' => '<br/><br/>
                    ' . important("Effets") . '
                    ' . $productionCondenseur,
            'description' => 'Le condenseur permet de condenser la matière. Ainsi, les <strong>atomes</strong> formant les molécules s\'en trouvent <strong>renforcés</strong>, plus puissants. Vous pouvez choisir d\'augmenter certains atomes (et donc leur effets respectifs) plus que d\'autres.',
            'coutEnergie' => round((1 - ($bonus / 100)) * $BUILDING_CONFIG['condenseur']['cost_energy_base'] * pow($BUILDING_CONFIG['condenseur']['cost_growth_base'], $niveauActuelCondenseur['niveau'])),
            'coutAtomes' => round((1 - ($bonus / 100)) * $BUILDING_CONFIG['condenseur']['cost_atoms_base'] * pow($BUILDING_CONFIG['condenseur']['cost_growth_base'], $niveauActuelCondenseur['niveau'])),
            'points' => $BUILDING_CONFIG['condenseur']['points_base'] + floor($niveauActuelCondenseur['niveau'] * $BUILDING_CONFIG['condenseur']['points_level_factor']),
            'tempsConstruction' => round($BUILDING_CONFIG['condenseur']['time_base'] * pow($BUILDING_CONFIG['condenseur']['time_growth_base'], $niveauActuelCondenseur['niveau'] + $BUILDING_CONFIG['condenseur']['time_level_offset']))
        ],

        'lieur' => [
            'titre' => 'Lieur',
            'bdd' => 'lieur',
            'image' => 'images/batiments/lieur.png',
            'progressBar' => false,
            'niveau' => $constructions['lieur'],
            'revenu' => chip('-' . round((1 - 1/bonusLieur($constructions['lieur'])) * 100) . '%', '<img src="images/batiments/tempsMolecule.png" alt="tpsMol" style="border-radius:0px;width:22px;height:22px"/>', "white", "", true),
            'revenu1' => chip('-' . round((1 - 1/bonusLieur($niveauActuelLieur['niveau'] + 1)) * 100) . '%', '<img src="images/batiments/tempsMolecule.png" alt="tpsMol" style="border-radius:0px;width:22px;height:22px"/>', "white", "", true),
            'effetSup' => '',
            'description' => 'Le lieur forme des liaisons entre atomes afin de créer des molécules. Il permet ainsi de <strong>réduire le temps de formation des molécules</strong> de votre armée.',
            'coutAzote' => round((1 - ($bonus / 100)) * $BUILDING_CONFIG['lieur']['cost_azote_base'] * pow($BUILDING_CONFIG['lieur']['cost_growth_base'], $niveauActuelLieur['niveau'])),
            'points' => $BUILDING_CONFIG['lieur']['points_base'] + floor($niveauActuelLieur['niveau'] * $BUILDING_CONFIG['lieur']['points_level_factor']),
            'tempsConstruction' => round($BUILDING_CONFIG['lieur']['time_base'] * pow($BUILDING_CONFIG['lieur']['time_growth_base'], $niveauActuelLieur['niveau'] + $BUILDING_CONFIG['lieur']['time_level_offset']))
        ],


        'stabilisateur' => [
            'titre' => 'Stabilisateur',
            'bdd' => 'stabilisateur',
            'image' => 'images/batiments/stabilisateur.png',
            'progressBar' => false,
            'niveau' => $constructions['stabilisateur'],
            'revenu' => '<span title="Réduction : (1 − ' . STABILISATEUR_ASYMPTOTE . '^niveau) × 100">' . round((1 - pow(STABILISATEUR_ASYMPTOTE, $constructions['stabilisateur'])) * 100, 1) . '% de réduction des chances de disparition des molécules</span>',
            'revenu1' => '<span title="Réduction : (1 − ' . STABILISATEUR_ASYMPTOTE . '^niveau) × 100">' . round((1 - pow(STABILISATEUR_ASYMPTOTE, $niveauActuelStabilisateur['niveau'] + 1)) * 100, 1) . '% de réduction des chances de disparition des molécules</span>',
            'effetSup' => '',
            'description' => 'Le stabilisateur permet à vos <strong>molécules d\'être plus stables</strong>, c\'est à dire qu\'elles disparaitront au bout d\'un temps plus long',
            'coutAtomes' => round((1 - ($bonus / 100)) * $BUILDING_CONFIG['stabilisateur']['cost_atoms_base'] * pow($BUILDING_CONFIG['stabilisateur']['cost_growth_base'], $niveauActuelStabilisateur['niveau'])),
            'points' => $BUILDING_CONFIG['stabilisateur']['points_base'] + floor($niveauActuelStabilisateur['niveau'] * $BUILDING_CONFIG['stabilisateur']['points_level_factor']),
            'tempsConstruction' => round($BUILDING_CONFIG['stabilisateur']['time_base'] * pow($BUILDING_CONFIG['stabilisateur']['time_growth_base'], $niveauActuelStabilisateur['niveau'] + $BUILDING_CONFIG['stabilisateur']['time_level_offset']))
        ],

        'coffrefort' => [
            'titre' => 'Coffre-fort',
            'bdd' => 'coffrefort',
            'image' => 'images/batiments/shield.png',
            'progressBar' => false,
            'niveau' => $constructions['coffrefort'] ?? 0,
            'revenu' => number_format(capaciteCoffreFort($constructions['coffrefort'] ?? 0, $constructions['depot'])) . ' atomes protégés (' . min(VAULT_MAX_PROTECTION_PCT * 100, ($constructions['coffrefort'] ?? 0) * VAULT_PCT_PER_LEVEL * 100) . '% du stockage)',
            'revenu1' => number_format(capaciteCoffreFort(($constructions['coffrefort'] ?? 0) + 1, $constructions['depot'])) . ' atomes protégés (' . min(VAULT_MAX_PROTECTION_PCT * 100, (($constructions['coffrefort'] ?? 0) + 1) * VAULT_PCT_PER_LEVEL * 100) . '% du stockage)',
            'effetSup' => '',
            'description' => 'Le coffre-fort protège une partie de vos <strong>ressources contre le pillage</strong>. Chaque niveau protège ' . (VAULT_PCT_PER_LEVEL * 100) . '% supplémentaires de votre stockage (max ' . (VAULT_MAX_PROTECTION_PCT * 100) . '%).',
            'coutEnergie' => round((1 - ($bonus / 100)) * $BUILDING_CONFIG['coffrefort']['cost_energy_base'] * pow($BUILDING_CONFIG['coffrefort']['cost_growth_base'], $niveauActuelCoffrefort['niveau'])),
            'coutAtomes' => 0,
            'points' => $BUILDING_CONFIG['coffrefort']['points_base'] + floor($niveauActuelCoffrefort['niveau'] * $BUILDING_CONFIG['coffrefort']['points_level_factor']),
            'tempsConstruction' => round($BUILDING_CONFIG['coffrefort']['time_base'] * pow($BUILDING_CONFIG['coffrefort']['time_growth_base'], $niveauActuelCoffrefort['niveau'] + $BUILDING_CONFIG['coffrefort']['time_level_offset']))
        ]
    ];

    // Save all computed globals into the per-request global cache so subsequent
    // calls within the same request (e.g. augmenterBatiment → initPlayer twice)
    // skip all DB queries entirely.
    $snapshot = ['ressources' => $ressources, 'revenu' => $revenu, 'constructions' => $constructions,
        'autre' => $autre, 'membre' => $membre, 'revenuEnergie' => $revenuEnergie,
        'placeDepot' => $placeDepot, 'points' => $points, 'plusHaut' => $plusHaut,
        'production' => $production, 'productionCondenseur' => $productionCondenseur,
        'listeConstructions' => $listeConstructions];
    foreach ($nomsRes as $num => $ressource) {
        $snapshot['revenu' . $ressource] = ${'revenu' . $ressource};
        $snapshot['points' . $ressource] = ${'points' . $ressource};
        $snapshot['niveau' . $ressource] = ${'niveau' . $ressource};
    }
    $GLOBALS['_initPlayerCache'][$joueur] = $snapshot;
}

/**
 * Invalidate the per-request initPlayer() cache for a given player.
 * Must be called before re-invoking initPlayer() after any DB write that
 * changes constructions, ressources, autre, or membre data.
 */
function invalidatePlayerCache($joueur)
{
    if (isset($GLOBALS['_initPlayerCache'][$joueur])) {
        unset($GLOBALS['_initPlayerCache'][$joueur]);
    }
}

function augmenterBatiment($nom, $joueur)
{ // BUG listeconstructions
    global $base;
    global $listeConstructions;
    global $points;
    invalidatePlayerCache($joueur);
    initPlayer($joueur);

    // MED-004: Wrap all DB reads and writes in a transaction so the SELECT
    // is not stale by the time we write (reads were previously outside the transaction).
    withTransaction($base, function() use ($base, $nom, $joueur, $listeConstructions, $points) {
        // Lock the row with FOR UPDATE so concurrent requests cannot read stale data.
        $batiments = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=? FOR UPDATE', 's', $joueur);

        // Hard cap on building levels (P4-ADV-004)
        if (($batiments[$nom] ?? 0) >= MAX_BUILDING_LEVEL) {
            return;
        }

        if ($nom == 'producteur') {
            dbExecute($base, 'UPDATE constructions SET pointsProducteurRestants=? WHERE login=?', 'is', ($batiments['pointsProducteurRestants'] + $points['producteur']), $joueur);
        }
        if ($nom == 'condenseur') {
            dbExecute($base, 'UPDATE constructions SET pointsCondenseurRestants=? WHERE login=?', 'is', ($batiments['pointsCondenseurRestants'] + $points['condenseur']), $joueur);
        }

        if ($nom == "champdeforce" || $nom == "generateur" || $nom == "producteur" || $nom == "depot" || $nom == "ionisateur") {
            $vieCol = 'vie' . ucfirst($nom);
            if ($nom == "champdeforce") {
                $vieVal = vieChampDeForce($batiments[$nom] + 1, $joueur);
            } elseif ($nom == "ionisateur") {
                $vieVal = vieIonisateur($batiments[$nom] + 1, $joueur);
            } else {
                $vieVal = pointsDeVie($batiments[$nom] + 1, $joueur);
            }
            dbExecute($base, "UPDATE constructions SET $nom=?, $vieCol=? WHERE login=?", 'ids', ($batiments[$nom] + 1), $vieVal, $joueur);
        } else {
            dbExecute($base, "UPDATE constructions SET $nom=? WHERE login=?", 'is', ($batiments[$nom] + 1), $joueur);
        }
        ajouterPoints($listeConstructions[$nom]['points'], $joueur);
    });

    // FIX H-007: invalidate target player's cache, not necessarily current session
    invalidatePlayerCache($joueur);
    initPlayer($joueur);
}

function diminuerBatiment($nom, $joueur)
{ // pour résoudre les bugs, construire un objet PLAYER qui initialise toutes ses constantes
    global $nomsRes;
    global $points;
    global $constructions;
    global $listeConstructions;
    foreach ($nomsRes as $num => $ressource) {
        global ${'points' . $ressource};
        global ${'niveau' . $ressource};
    }

    invalidatePlayerCache($joueur);
    initPlayer($joueur);

    global $base;

    // Wrap entire function body in a transaction to prevent race conditions
    // between the SELECT and the subsequent UPDATE statements.
    withTransaction($base, function() use ($base, $nom, $joueur, $nomsRes, $points, $constructions, $listeConstructions) {
        // FOR UPDATE locks the row so concurrent requests wait rather than reading stale data.
        $batiments = dbFetchOne($base, "SELECT $nom, pointsProducteurRestants, pointsProducteur, pointsCondenseurRestants, pointsCondenseur FROM constructions WHERE login=? FOR UPDATE", 's', $joueur);

        if ($batiments[$nom] > 1) {
            if ($nom == 'producteur') {
                if ($batiments['pointsProducteurRestants'] >= $points['producteur']) {
                    dbExecute($base, 'UPDATE constructions SET pointsProducteurRestants=? WHERE login=?', 'is', ($batiments['pointsProducteurRestants'] - $points['producteur']), $joueur);
                } else {
                    $pointsAEnlever = $points['producteur'] - $batiments['pointsProducteurRestants'];
                    dbExecute($base, 'UPDATE constructions SET pointsProducteurRestants=0 WHERE login=?', 's', $joueur);

                    // FIX FINDING-GAME-025: minimum is 0 (not 1) to avoid granting free production
                    $chaine = "";
                    foreach ($nomsRes as $num => $ressource) {
                        if ($pointsAEnlever <= ${'points' . $ressource}) {
                            $chaine = $chaine . (${'points' . $ressource} - $pointsAEnlever) . ";";
                            $pointsAEnlever = 0;
                        } else {
                            $chaine = $chaine . "0;";
                            $pointsAEnlever = $pointsAEnlever - ${'points' . $ressource};
                        }
                    }

                    dbExecute($base, 'UPDATE constructions SET pointsProducteur=? WHERE login=?', 'ss', $chaine, $joueur);
                }
            }
            if ($nom == 'condenseur') {
                if ($batiments['pointsCondenseurRestants'] >= $points['condenseur']) {
                    dbExecute($base, 'UPDATE constructions SET pointsCondenseurRestants=? WHERE login=?', 'is', ($batiments['pointsCondenseurRestants'] - $points['condenseur']), $joueur);
                } else {
                    dbExecute($base, 'UPDATE constructions SET pointsCondenseurRestants=0 WHERE login=?', 's', $joueur);
                    $pointsAEnlever = $points['condenseur'] - $batiments['pointsCondenseurRestants'];

                    $chaine = "";
                    foreach ($nomsRes as $num => $ressource) {
                        $currentLevel = ${'niveau' . $ressource};
                        if ($pointsAEnlever > 0 && $currentLevel > 0) {
                            $canRemove = min($pointsAEnlever, $currentLevel);
                            $chaine = $chaine . ($currentLevel - $canRemove) . ";";
                            $pointsAEnlever -= $canRemove;
                        } else {
                            $chaine = $chaine . $currentLevel . ";";
                        }
                    }

                    dbExecute($base, 'UPDATE constructions SET pointsCondenseur=? WHERE login=?', 'ss', $chaine, $joueur);
                }
            }

            if ($nom == "champdeforce" || $nom == "generateur" || $nom == "producteur" || $nom == "depot" || $nom == "ionisateur") {
                $vieCol = 'vie' . ucfirst($nom);
                if ($nom == "champdeforce") {
                    $vieVal = vieChampDeForce($batiments[$nom] - 1);
                } elseif ($nom == "ionisateur") {
                    $vieVal = vieIonisateur($batiments[$nom] - 1);
                } else {
                    $vieVal = pointsDeVie($batiments[$nom] - 1);
                }
                dbExecute($base, "UPDATE constructions SET $nom=?, $vieCol=? WHERE login=?", 'ids', ($batiments[$nom] - 1), $vieVal, $joueur);
            } else {
                dbExecute($base, "UPDATE constructions SET $nom=? WHERE login=?", 'is', ($batiments[$nom] - 1), $joueur);
            }
            ajouterPoints(-$listeConstructions[$nom]['points'], $joueur);
        }
    });

    // FIX H-007: invalidate target player's cache, not necessarily current session
    invalidatePlayerCache($joueur);
    initPlayer($joueur);
}

function coordonneesAleatoires()
{
    global $base;
    $inscrits = dbFetchOne($base, 'SELECT tailleCarte,nbDerniere FROM statistiques');

    if ($inscrits['nbDerniere'] > $inscrits['tailleCarte'] - 2) {
        $inscrits['nbDerniere'] = 0;
        $inscrits['tailleCarte'] += 1;
    }

    $carte = [];
    for ($i = 0; $i < $inscrits['tailleCarte']; $i++) {
        $temp = [];
        for ($j = 0; $j < $inscrits['tailleCarte']; $j++) {
            $temp[] = 0;
        }
        $carte[] = $temp;
    }

    $joueursRows = dbFetchAll($base, 'SELECT x,y FROM membre WHERE x >= 0 AND y >= 0', '');
    foreach ($joueursRows as $joueurs) {
        $carte[$joueurs['x']][$joueurs['y']] = 1;
    }

    $alea = mt_rand(0, 1);
    if ($alea == 0) { // horizontale
        $y = $inscrits['tailleCarte'] - 1;
        $x = mt_rand(0, $inscrits['tailleCarte'] - 1);
        $maxAttempts = $inscrits['tailleCarte'] * 2;
        $attempts = 0;
        while ($carte[$x][$y] != 0 && $attempts < $maxAttempts) {
            $x = mt_rand(0, $inscrits['tailleCarte'] - 1);
            $attempts++;
        }
        if ($attempts >= $maxAttempts) {
            // Map edge is full, force expand
            $inscrits['tailleCarte'] += 1;
            $x = $inscrits['tailleCarte'] - 1;
            $y = 0;
        }
    } else {
        $x = $inscrits['tailleCarte'] - 1;
        $y = mt_rand(0, $inscrits['tailleCarte'] - 1);
        $maxAttempts = $inscrits['tailleCarte'] * 2;
        $attempts = 0;
        while ($carte[$x][$y] != 0 && $attempts < $maxAttempts) {
            $y = mt_rand(0, $inscrits['tailleCarte'] - 1);
            $attempts++;
        }
        if ($attempts >= $maxAttempts) {
            $inscrits['tailleCarte'] += 1;
            $x = 0;
            $y = $inscrits['tailleCarte'] - 1;
        }
    }

    dbExecute($base, 'UPDATE statistiques SET tailleCarte=?, nbDerniere=?', 'ii', $inscrits['tailleCarte'], ($inscrits['nbDerniere'] + 1));

    return ['x' => $x, 'y' => $y];
}


function batMax($pseudo)
{
    global $nomsRes;
    global $nbRes;
    global $base;

    $liste = ['generateur', 'producteur', 'champdeforce', 'ionisateur', 'depot', 'stabilisateur', 'condenseur', 'lieur', 'coffrefort'];
    // on ne peut pas faire un foreach car il y a un probleme de priorités
    $tableau = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $pseudo);
    $plusHaut = $tableau['generateur'];

    foreach ($liste as $num => $batiment) {
        if ($tableau[$batiment] > $plusHaut) {
            $plusHaut = $tableau[$batiment];
        }
    }

    return $plusHaut;
}

function joueur($joueur)
{
    $safe = htmlspecialchars($joueur, ENT_QUOTES, 'UTF-8');
    $act = statut($joueur);
    if ($act == 0) {
        return '<a href="joueur.php?id=' . $safe . '" class="lienVisible"><span style="color:darkgray">' . $safe . '</span></a>';
    } else {
        return '<a href="joueur.php?id=' . $safe . '" class="lienVisible">' . $safe . '</a>';
    }
}

function recalculerStatsAlliances()
{
    global $base;

    $alliancesRows = dbFetchAll($base, 'SELECT id FROM alliances', '');
    foreach ($alliancesRows as $donnees) {
        $membresRows = dbFetchAll($base, 'SELECT login, totalPoints, points, pointsAttaque, pointsDefense, ressourcesPillees FROM autre WHERE idalliance=?', 'i', $donnees['id']);
        $pointstotaux = 0;
        $cTotal = 0;
        $aTotal = 0;
        $dTotal = 0;
        $pTotal = 0;
        foreach ($membresRows as $donnees1) {
            $pointstotaux = $donnees1['totalPoints'] + $pointstotaux;
            $cTotal += $donnees1['points'];
            $aTotal += pointsAttaque($donnees1['pointsAttaque']);
            $dTotal += pointsDefense($donnees1['pointsDefense']);
            $pTotal += $donnees1['ressourcesPillees'];
        }
        dbExecute($base, 'UPDATE alliances SET pointstotaux=?, totalConstructions=?, totalAttaque=?, totalDefense=?, totalPillage=? WHERE id=?', 'dddddi', $pointstotaux, $cTotal, $aTotal, $dTotal, $pTotal, $donnees['id']);
    }
}

function supprimerAlliance($alliance)
{
    global $base;
    withTransaction($base, function() use ($base, $alliance) {
        // Clean up attack cooldowns for all alliance members before dissolution
        $members = dbFetchAll($base, 'SELECT login FROM autre WHERE idalliance = ?', 'i', $alliance);
        foreach ($members as $member) {
            dbExecute($base, 'DELETE FROM attack_cooldowns WHERE attacker = ? OR defender = ?', 'ss', $member['login'], $member['login']);
        }
        dbExecute($base, 'UPDATE autre SET energieDonnee=0 WHERE idalliance=?', 'i', $alliance);
        dbExecute($base, 'DELETE FROM alliances WHERE id=?', 'i', $alliance);
        dbExecute($base, 'UPDATE autre SET idalliance=0 WHERE idalliance=?', 'i', $alliance);
        dbExecute($base, 'DELETE FROM invitations WHERE idalliance=?', 'i', $alliance);
        dbExecute($base, 'DELETE FROM declarations WHERE (alliance1=? OR alliance2=?)', 'ii', $alliance, $alliance);
        dbExecute($base, 'DELETE FROM grades WHERE idalliance=?', 'i', $alliance);
    });
}

function supprimerJoueur($joueur)
{
    global $base;
    if (function_exists('logInfo')) {
        logInfo('ACCOUNT', 'Account deleted', ['deleted_player' => $joueur]);
    }
    withTransaction($base, function() use ($base, $joueur) {
        dbExecute($base, 'DELETE FROM connectes WHERE ip IN (SELECT ip FROM membre WHERE login=?)', 's', $joueur);
        dbExecute($base, 'DELETE FROM vacances WHERE idJoueur IN (SELECT id FROM membre WHERE login=?)', 's', $joueur);
        dbExecute($base, 'DELETE FROM autre WHERE login=?', 's', $joueur);
        dbExecute($base, 'DELETE FROM membre WHERE login=?', 's', $joueur);
        dbExecute($base, 'DELETE FROM ressources WHERE login=?', 's', $joueur);
        dbExecute($base, 'DELETE FROM molecules WHERE proprietaire=?', 's', $joueur);
        dbExecute($base, 'DELETE FROM constructions WHERE login=?', 's', $joueur);
        dbExecute($base, 'DELETE FROM invitations WHERE invite=?', 's', $joueur);
        dbExecute($base, 'DELETE FROM messages WHERE destinataire=? OR expeditaire=?', 'ss', $joueur, $joueur);
        dbExecute($base, 'DELETE FROM rapports WHERE destinataire=?', 's', $joueur);
        dbExecute($base, 'DELETE FROM grades WHERE login=?', 's', $joueur);
        dbExecute($base, 'DELETE FROM actionsattaques WHERE attaquant=? OR defenseur=?', 'ss', $joueur, $joueur);
        dbExecute($base, 'DELETE FROM actionsformation WHERE login=?', 's', $joueur);
        dbExecute($base, 'DELETE FROM actionsenvoi WHERE envoyeur=? OR receveur=?', 'ss', $joueur, $joueur);
        dbExecute($base, 'DELETE FROM statutforum WHERE login=?', 's', $joueur);
        dbExecute($base, 'DELETE FROM prestige WHERE login=?', 's', $joueur);
        dbExecute($base, 'DELETE FROM attack_cooldowns WHERE attacker=? OR defender=?', 'ss', $joueur, $joueur);
        dbExecute($base, 'DELETE FROM sanctions WHERE joueur=?', 's', $joueur);
        dbExecute($base, 'DELETE FROM actionsconstruction WHERE login=?', 's', $joueur);
        dbExecute($base, 'DELETE FROM player_compounds WHERE login=?', 's', $joueur);
        dbExecute($base, 'DELETE FROM season_recap WHERE login=?', 's', $joueur);

        // Atomic decrement: avoids TOCTOU race; floor at 0 to prevent negative counts.
        dbExecute($base, 'UPDATE statistiques SET inscrits = GREATEST(0, inscrits - 1)');
    });
}

function miseAJour()
{
    global $base;
    global $nomsRes;

    $constructions = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $_SESSION['login']);

    $niveaux = explode(';', $constructions['pointsProducteur']);
    foreach ($nomsRes as $num => $ressource) {
        ${'points' . $ressource} = $niveaux[$num];
    }
}

/**
 * Archive all player stats into season_recap before season reset (P1-D8-052).
 * Returns the season number that was archived.
 */
function archiveSeasonData($base)
{
    // Determine season number from existing archives
    $lastSeason = dbFetchOne($base, 'SELECT MAX(season_number) AS max_s FROM season_recap', '', '');
    $nextSeason = ($lastSeason && $lastSeason['max_s']) ? (int)$lastSeason['max_s'] + 1 : 1;

    $allPlayers = dbFetchAll($base,
        'SELECT a.login, a.totalPoints, a.pointsAttaque, a.pointsDefense, a.tradeVolume,
                a.ressourcesPillees, a.nbattaques, a.victoires, a.moleculesPerdues,
                a.streak_days, al.nom AS alliance_name
         FROM autre a
         LEFT JOIN alliances al ON al.id = a.idalliance AND a.idalliance > 0
         JOIN membre m ON m.login = a.login AND m.x != -1000
         ORDER BY a.totalPoints DESC', '', '');

    $rank = 0;
    withTransaction($base, function() use ($base, $allPlayers, &$rank, $nextSeason) {
        foreach ($allPlayers as $p) {
            $rank++;
            dbExecute($base,
                'INSERT INTO season_recap (season_number, login, final_rank, total_points, points_attaque,
                 points_defense, trade_volume, ressources_pillees, nb_attaques, victoires,
                 molecules_perdues, alliance_name, streak_max) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                'isiiiidiiidsi',
                $nextSeason, $p['login'], $rank, (int)$p['totalPoints'],
                (int)$p['pointsAttaque'], (int)$p['pointsDefense'],
                (float)$p['tradeVolume'], (int)$p['ressourcesPillees'],
                (int)$p['nbattaques'], (int)$p['victoires'],
                (float)$p['moleculesPerdues'], $p['alliance_name'] ?? '',
                (int)($p['streak_days'] ?? 0)
            );
        }
    });

    logInfo('SEASON', 'Season data archived', ['season' => $nextSeason, 'players' => $rank]);
    return $nextSeason;
}

/**
 * Perform the full season-end flow: archive rankings, award VP and prestige,
 * insert season record, reset game state, update debut, post winner news.
 * Returns the winner's login name (or null if no players).
 *
 * Both automatic season end (basicprivatephp.php) and admin reset call this.
 */
function performSeasonEnd()
{
    global $base;

    // MED-012: Advisory lock prevents double-reset when called concurrently.
    // GET_LOCK(..., 0) returns 1 if acquired immediately, 0 if already held, NULL on error.
    // Lock name matches basicprivatephp.php ('tvlw_season_reset') so the two locks are unified.
    $lockResult = dbFetchOne($base, "SELECT GET_LOCK('tvlw_season_reset', 0) AS locked");
    if (!$lockResult || !$lockResult['locked']) {
        throw new \RuntimeException('Season reset lock not acquired — another reset in progress');
    }

    try {

    require_once(__DIR__ . '/prestige.php');

    // Phase 0: Archive per-player stats into season_recap (P1-D8-052)
    archiveSeasonData($base);

    // Phase 1a: Archive rankings and compute VP awards (read-only inside one transaction).
    // Snapshot rankings and archive strings atomically so the data is consistent.
    $vainqueurManche = null;
    $playerRankingsForVP = [];
    $allianceRankingsForVP = [];
    withTransaction($base, function() use ($base, &$vainqueurManche, &$playerRankingsForVP, &$allianceRankingsForVP) {
        // Archive top players
        $chaine = '';
        $classementRows = dbFetchAll($base, 'SELECT * FROM autre ORDER BY totalPoints DESC LIMIT 0, ' . SEASON_ARCHIVE_TOP_N, '');
        $compteur = 0;
        foreach ($classementRows as $data) {
            $molRows = dbFetchAll($base, 'SELECT nombre FROM molecules WHERE proprietaire = ? AND nombre != 0', 's', $data['login']);
            if ($data['idalliance'] > 0) {
                $alliance = dbFetchOne($base, 'SELECT tag, id FROM alliances WHERE id = ?', 'i', $data['idalliance']);
                if (!$alliance) $alliance = ['tag' => ''];
            } else {
                $alliance = ['tag' => ''];
            }
            $nb_molecules = 0;
            foreach ($molRows as $donnees4) {
                $nb_molecules = $nb_molecules + $donnees4['nombre'];
            }
            $chaine = $chaine . '[' . $data['login'] . ',' . $data['totalPoints'] . ',' . $alliance['tag'] . ',' . $data['points'] . ',' . pointsAttaque($data['pointsAttaque']) . ',' . pointsDefense($data['pointsDefense']) . ',' . $data['ressourcesPillees'] . ',' . $data['victoires'] . '';

            if ($compteur == 0) {
                $vainqueurManche = $data['login'];
            }

            $compteur++;
        }

        // Archive alliances
        $allianceClassement = dbFetchAll($base, 'SELECT * FROM alliances ORDER BY pointstotaux DESC LIMIT 0, ' . SEASON_ARCHIVE_TOP_N, '');
        $chaine1 = '';
        foreach ($allianceClassement as $data) {
            $membresAlliance = dbFetchAll($base, 'SELECT login FROM autre WHERE idalliance = ?', 'i', $data['id']);
            $nbjoueurs = count($membresAlliance);
            if ($nbjoueurs != 0) {
                $chaine1 = $chaine1 . '[' . $data['tag'] . ',' . $nbjoueurs . ',' . $data['pointstotaux'] . ',' . $data['pointstotaux'] / $nbjoueurs . ',' . $data['totalConstructions'] . ',' . pointsAttaque($data['totalAttaque']) . ',' . pointsDefense($data['totalDefense']) . ',' . $data['totalPillage'] . ',' . $data['pointsVictoire'] . '';
            }
        }

        // Archive wars
        $guerreClassement = dbFetchAll($base, 'SELECT * FROM declarations WHERE pertesTotales != 0 AND type = 0 AND fin != 0 ORDER BY pertesTotales DESC LIMIT 0, ' . SEASON_ARCHIVE_TOP_N, '');
        $chaine2 = '';
        foreach ($guerreClassement as $data) {
            $alliance1 = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id = ?', 'i', $data['alliance1']);
            $alliance2 = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id = ?', 'i', $data['alliance2']);
            $membresGuerre = dbFetchAll($base, 'SELECT login FROM autre WHERE idalliance = ?', 'i', $data['id']);
            $nbjoueurs = count($membresGuerre);
            if ($nbjoueurs != 0) {
                $chaine2 = $chaine2 . '[' . $alliance1['tag'] . ' contre ' . $alliance2['tag'] . ',' . $data['pertesTotales'] . ',' . (($data['fin'] - $data['timestamp']) / SECONDS_PER_DAY) . ',' . $data['id'] . '';
            }
        }

        // Freeze rankings into arrays (ranking read done inside this transaction to ensure consistency)
        $playerRankingsForVP = dbFetchAll($base, 'SELECT login, totalPoints FROM autre ORDER BY totalPoints DESC');
        $allianceRankingsForVP = dbFetchAll($base, 'SELECT id, pointsVictoire, pointstotaux FROM alliances ORDER BY pointstotaux DESC');

        // Insert season archive record
        $now = time();
        dbExecute($base, 'INSERT INTO parties VALUES(default, ?, ?, ?, ?)', 'isss', $now, $chaine, $chaine1, $chaine2);
    });

    // Phase 1b: Award VP to players in chunks of 100 to avoid long-running transactions.
    // LOW-022: Split large VP award loop into smaller transactions so the DB is not locked
    // for the entire season-reset duration. Rankings were snapshotted in Phase 1a.
    // HIGH-018: Compute true DENSE_RANK (no gaps) so tied players receive the same VP bonus.
    $denseRank = 1;
    $prevScore = null;
    foreach ($playerRankingsForVP as &$player) {
        if ($prevScore !== null && $player['totalPoints'] !== $prevScore) {
            $denseRank++;
        }
        $player['dense_rank'] = $denseRank;
        $prevScore = $player['totalPoints'];
    }
    unset($player);

    $playerChunks = array_chunk($playerRankingsForVP, 100);
    foreach ($playerChunks as $chunk) {
        withTransaction($base, function() use ($base, $chunk) {
            foreach ($chunk as $pointsVictoire) {
                ajouter('victoires', 'autre', pointsVictoireJoueur($pointsVictoire['dense_rank']), $pointsVictoire['login']);
            }
        });
    }

    // Phase 1c: Award VP to alliances and their members (each alliance in one transaction).
    // HIGH-PR-003: Compute DENSE_RANK over alliance scores so tied alliances share the same VP.
    $allianceDenseRank = 1;
    $alliancePrevScore = null;
    foreach ($allianceRankingsForVP as &$allianceData) {
        if ($alliancePrevScore !== null && $allianceData['pointstotaux'] !== $alliancePrevScore) {
            $allianceDenseRank++;
        }
        $allianceData['dense_rank'] = $allianceDenseRank;
        $alliancePrevScore = $allianceData['pointstotaux'];
    }
    unset($allianceData);

    foreach ($allianceRankingsForVP as $allianceData) {
        $localC = $allianceData['dense_rank'];
        withTransaction($base, function() use ($base, $allianceData, $localC) {
            $newPtsVictoire = $allianceData['pointsVictoire'] + pointsVictoireAlliance($localC);
            dbExecute($base, 'UPDATE alliances SET pointsVictoire = ? WHERE id = ?', 'ii', $newPtsVictoire, $allianceData['id']);
            $allianceMembers = dbFetchAll($base, 'SELECT login FROM autre WHERE idalliance = ?', 'i', $allianceData['id']);
            foreach ($allianceMembers as $pointsVictoireJoueurs) {
                ajouter('victoires', 'autre', pointsVictoireAlliance($localC), $pointsVictoireJoueurs['login']);
            }
        });
    }

    // Phase 1d: Award prestige points BEFORE reset (cross-season progression).
    awardPrestigePoints();

    // Phase 2: Reset all game state (has its own internal transaction)
    remiseAZero();

    // MED-014: Invalidate all existing session tokens so every player must re-login
    // after the season reset. This prevents stale sessions carrying old game state
    // into the new season.
    dbExecute($base, 'UPDATE membre SET session_token = NULL WHERE 1', '');
    logInfo('SEASON', 'All session tokens invalidated after season reset');

    // Phase 3: Generate resource nodes for the new season
    // remiseAZero() resets tailleCarte to 1 (players reconnect and expand it).
    // Use MAP_INITIAL_SIZE as the generation boundary so nodes span the full starting
    // map; actual tailleCarte at this point is 1 and would produce invalid coordinates.
    require_once(__DIR__ . '/resource_nodes.php');
    $nodeMapSize = MAP_INITIAL_SIZE;
    generateResourceNodes($base, $nodeMapSize);

    // Update season start time
    $now = time();
    dbExecute($base, 'UPDATE statistiques SET debut = ?', 'i', $now);

    // MED-016: Clear maintenance flag on successful season reset completion.
    // This is only reached if all prior steps succeeded (no exception thrown).
    dbExecute($base, 'UPDATE statistiques SET maintenance = 0', '');
    logInfo('SEASON', 'Season reset complete — maintenance flag cleared');

    // Post winner news
    if ($vainqueurManche !== null) {
        $titre = "Vainqueur de la dernière manche";
        $contenu = 'Le vainqueur de la dernière manche est <a href="joueur.php?id=' . htmlspecialchars($vainqueurManche, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($vainqueurManche, ENT_QUOTES, 'UTF-8') . '</a><br/><br/>Reprise <strong>le ' . date('d/m/Y à H\hi', time()) . '</strong>';
        $now = time();
        dbExecute($base, 'INSERT INTO news VALUES(default, ?, ?, ?)', 'ssi', $titre, $contenu, $now);
    }

    return $vainqueurManche;

    } finally {
        dbFetchOne($base, "SELECT RELEASE_LOCK('tvlw_season_reset')");
    }
}

/**
 * HIGH-017: Async email queue processor.
 *
 * Fetches up to $limit unsent emails from email_queue (rows where sent_at IS NULL),
 * sends each via mail(), and marks successful sends with a sent_at timestamp.
 * Failed sends are left in the queue and retried on the next invocation.
 *
 * This function is called probabilistically (1% of page requests) from
 * basicprivatephp.php so that emails drain without blocking any single user request.
 * The email_queue table is created by migrations/0038_create_email_queue.sql.
 *
 * @param \mysqli $base   Active database connection
 * @param int     $limit  Maximum emails to send per invocation (default: 20)
 */
function processEmailQueue(\mysqli $base, int $limit = 20): void
{
    $rows = dbFetchAll($base,
        'SELECT id, recipient_email, subject, body_html FROM email_queue WHERE sent_at IS NULL ORDER BY id ASC LIMIT ?',
        'i', $limit
    );

    if (empty($rows)) {
        return;
    }

    foreach ($rows as $row) {
        $recipient = $row['recipient_email'];
        $subject   = $row['subject'];
        $bodyHtml  = $row['body_html'];
        $id        = (int)$row['id'];

        // Determine line-ending style based on recipient domain (hotmail/live/msn quirk)
        if (preg_match("#^[a-z0-9._-]+@(hotmail|live|msn)\.[a-z]{2,4}$#", $recipient)) {
            $eol = "\n";
        } else {
            $eol = "\r\n";
        }

        $boundary = "-----=" . md5((string)$id . (string)time());

        // Plain-text fallback: strip HTML tags for the text part
        $bodyTxt = strip_tags(str_replace(['<br/>', '<br>', '<br />'], "\n", $bodyHtml));

        $header  = "From: \"The Very Little War\"<noreply@theverylittlewar.com>" . $eol;
        $header .= "Reply-to: \"The Very Little War\" <theverylittlewar@gmail.com>" . $eol;
        $header .= "MIME-Version: 1.0" . $eol;
        $header .= "Content-Type: multipart/alternative;" . $eol . " boundary=\"$boundary\"" . $eol;

        $message  = $eol . "--" . $boundary . $eol;
        $message .= "Content-Type: text/plain; charset=\"UTF-8\"" . $eol;
        $message .= "Content-Transfer-Encoding: 8bit" . $eol;
        $message .= $eol . $bodyTxt . $eol;
        $message .= $eol . "--" . $boundary . $eol;
        $message .= "Content-Type: text/html; charset=\"UTF-8\"" . $eol;
        $message .= "Content-Transfer-Encoding: 8bit" . $eol;
        $message .= $eol . $bodyHtml . $eol;
        $message .= $eol . "--" . $boundary . "--" . $eol;

        $sent = @mail($recipient, $subject, $message, $header);
        if ($sent) {
            dbExecute($base, 'UPDATE email_queue SET sent_at = ? WHERE id = ?', 'ii', time(), $id);
        } else {
            logWarn('EMAIL_QUEUE', 'mail() failed for queued email', ['id' => $id, 'recipient' => $recipient]);
        }
    }
}

function remiseAZero()
{
    global $base;
    global $nomsRes;
    global $nbRes;

    withTransaction($base, function() use ($nomsRes, $nbRes) {
        global $base;

        // LOW-023: victoires=0 reset is intentional — historical medals are archived to season_recap
        // (via archiveSeasonData() in Phase 0 of performSeasonEnd()) before this point.
        // Alliance pointsVictoire is preserved across seasons (not reset here).
        dbExecute($base, 'UPDATE autre SET points=0, niveaututo=1, nbattaques=0, neutrinos=default,moleculesPerdues=0, energieDepensee=0, energieDonnee=0, bombe=0, batMax=1, totalPoints=0, pointsAttaque=0, pointsDefense=0, ressourcesPillees=0, tradeVolume=0, victoires=0, missions=\'\', streak_days=0, streak_last_date=NULL, last_catch_up=0, comeback_shield_until=0, nbMessages=0, description=\'\', image=NULL, timeMolecule=0');
        dbExecute($base, 'UPDATE constructions SET generateur=default, producteur=default,pointsProducteur=default,pointsProducteurRestants=default, pointsCondenseur=default, pointsCondenseurRestants=default,champdeforce=default, lieur=default,ionisateur=default, depot=1, stabilisateur=default, condenseur=0, coffrefort=0, formation=0, spec_combat=0, spec_economy=0, spec_research=0, vieGenerateur=?, vieChampdeforce=?, vieProducteur=?, vieDepot=?, vieIonisateur=?', 'ddddd', pointsDeVie(1), vieChampDeForce(0), pointsDeVie(1), pointsDeVie(1), vieIonisateur(0));
        dbExecute($base, 'UPDATE alliances SET energieAlliance=0,duplicateur=0,catalyseur=0,fortification=0,reseau=0,radar=0,bouclier=0,pointstotaux=0,totalConstructions=0,totalAttaque=0,totalDefense=0,totalPillage=0');
        dbExecute($base, 'UPDATE molecules SET formule="Vide", nombre=0, isotope=0');
        dbExecute($base, 'UPDATE membre SET timestamp=?', 'i', time());

        $chaine = "";
        foreach ($nomsRes as $num => $ressource) {
            $plus = "";
            if ($num < $nbRes) {
                $plus = ",";
            }
            $chaine = $chaine . '' . $ressource . '=default' . $plus;
        }
        $sql = 'UPDATE ressources SET energie=default, terrain=default, revenuenergie=default, niveauclasse=1, ' . $chaine . '';
        dbExecute($base, $sql);
        dbExecute($base, 'DELETE FROM declarations');
        dbExecute($base, 'DELETE FROM invitations');
        dbExecute($base, 'DELETE FROM messages');
        dbExecute($base, 'DELETE FROM rapports');
        dbExecute($base, 'DELETE FROM actionsconstruction');
        dbExecute($base, 'DELETE FROM actionsformation');
        dbExecute($base, 'DELETE FROM actionsenvoi');
        dbExecute($base, 'DELETE FROM actionsattaques');
        dbExecute($base, 'DELETE FROM cours');
        dbExecute($base, 'DELETE FROM connectes');
        dbExecute($base, 'DELETE FROM vacances');
        dbExecute($base, 'UPDATE membre SET vacance = 0');
        dbExecute($base, 'DELETE FROM grades');

        dbExecute($base, 'UPDATE statistiques SET nbDerniere=0, tailleCarte=1');
        dbExecute($base, 'UPDATE membre SET x=-1000, y=-1000');

        $prestigeRows = dbFetchAll($base, 'SELECT login, unlocks FROM prestige WHERE unlocks LIKE ?', 's', '%debutant_rapide%');
        foreach ($prestigeRows as $pp) {
            dbExecute($base, 'UPDATE constructions SET generateur=2, vieGenerateur=? WHERE login=?', 'ds', pointsDeVie(2), $pp['login']);
        }

        dbExecute($base, 'DELETE FROM attack_cooldowns');
        dbExecute($base, 'DELETE FROM player_compounds');
        dbExecute($base, 'DELETE FROM resource_nodes');

        // MED-071: Remove temporary and expired sanctions on season reset.
        // Permanent bans (type != 'temp' and fin = 0 or future) are preserved.
        dbExecute($base, "DELETE FROM sanctions WHERE type='temp' OR (fin > 0 AND fin < NOW())", []);

        // MED-072: Clear season-specific news on season reset.
        dbExecute($base, 'DELETE FROM news WHERE 1', []);
    });

    // Forum data (sujets, reponses, statutforum) intentionally persists across seasons for community continuity
}

/**
 * Update daily login streak. Call once per page load (guarded by date check).
 * Returns array ['streak' => int, 'pp_earned' => int, 'milestone' => bool]
 */
function updateLoginStreak($base, $login) {
    global $STREAK_MILESTONES;
    // Streak date uses Europe/Paris timezone (see config.php — date_default_timezone_set)
    $tz = new DateTimeZone('Europe/Paris');
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    $result = ['streak' => 0, 'pp_earned' => 0, 'milestone' => false];

    withTransaction($base, function() use ($base, $login, $today, $tz, $STREAK_MILESTONES, &$result) {
        $row = dbFetchOne($base, 'SELECT streak_days, streak_last_date FROM autre WHERE login = ? FOR UPDATE', 's', $login);
        if (!$row) return;

        $lastDate = $row['streak_last_date'];
        $currentStreak = (int)$row['streak_days'];

        // Already logged in today
        if ($lastDate === $today) {
            $result = ['streak' => $currentStreak, 'pp_earned' => 0, 'milestone' => false];
            return;
        }

        $yesterday = (new DateTime('yesterday', $tz))->format('Y-m-d');

        if ($lastDate === $yesterday) {
            $currentStreak++;
        } else {
            $currentStreak = 1;
        }

        dbExecute($base, 'UPDATE autre SET streak_days = ?, streak_last_date = ? WHERE login = ?',
            'iss', $currentStreak, $today, $login);

        // Calculate PP earned
        $ppEarned = 0;
        $isMilestone = false;
        if (isset($STREAK_MILESTONES[$currentStreak])) {
            $ppEarned = $STREAK_MILESTONES[$currentStreak];
            $isMilestone = true;
        } elseif ($currentStreak >= 1) {
            // Non-milestone daily PP is intentional — small reward for consistency
            $ppEarned = STREAK_REWARD_DAY_1;
        }

        if ($ppEarned > 0) {
            dbExecute($base, 'INSERT INTO prestige (login, total_pp) VALUES (?, ?) ON DUPLICATE KEY UPDATE total_pp = total_pp + ?', 'sii', $login, $ppEarned, $ppEarned);
        }

        $result = ['streak' => $currentStreak, 'pp_earned' => $ppEarned, 'milestone' => $isMilestone];
    });

    return $result;
}

/**
 * Check and apply welcome-back bonus for returning players (P1-D8-044/047).
 * Call once per login, after streak update.
 * Returns ['applied' => bool, 'energy' => int, 'atoms' => int, 'shield_hours' => int]
 */
function checkComebackBonus($base, $login, $prevConnexion = null) {
    $now = time();
    $result = ['applied' => false];

    // Fetch registration date and last login in one query
    $membre = dbFetchOne($base, 'SELECT timestamp, derniereConnexion FROM membre WHERE login = ?', 's', $login);
    if (!$membre) return $result;

    // New players (account younger than COMEBACK_ABSENCE_DAYS) cannot trigger comeback bonus
    $minAge = COMEBACK_ABSENCE_DAYS * SECONDS_PER_DAY;
    if (($now - (int)$membre['timestamp']) < $minAge) {
        return $result;
    }

    if ($prevConnexion !== null) {
        $lastLogin = (int)$prevConnexion;
    } else {
        $lastLogin = (int)$membre['derniereConnexion'];
    }

    withTransaction($base, function() use ($base, $login, $now, $lastLogin, &$result) {
        // Lock row to prevent double-award from concurrent requests
        $autre = dbFetchOne($base, 'SELECT last_catch_up FROM autre WHERE login = ? FOR UPDATE', 's', $login);
        if (!$autre) return;

        $lastCatchUp = (int)$autre['last_catch_up'];
        $absentDays = ($now - $lastLogin) / SECONDS_PER_DAY;
        $cooldownOk = ($now - $lastCatchUp) > (COMEBACK_COOLDOWN_DAYS * SECONDS_PER_DAY);

        if ($absentDays < COMEBACK_ABSENCE_DAYS || !$cooldownOk) {
            return;
        }

        // Cap bonuses at storage limit to prevent exceeding depot capacity
        $depotData = dbFetchOne($base, 'SELECT depot FROM constructions WHERE login = ? FOR UPDATE', 's', $login);
        $storageMax = placeDepot($depotData ? $depotData['depot'] : 1);
        dbExecute($base, 'UPDATE ressources SET
            energie = LEAST(energie + ?, ?),
            carbone = LEAST(carbone + ?, ?), azote = LEAST(azote + ?, ?),
            hydrogene = LEAST(hydrogene + ?, ?), oxygene = LEAST(oxygene + ?, ?),
            chlore = LEAST(chlore + ?, ?), soufre = LEAST(soufre + ?, ?),
            brome = LEAST(brome + ?, ?), iode = LEAST(iode + ?, ?)
            WHERE login = ?',
            'ddddddddddddddddddds',
            (float)COMEBACK_ENERGY_BONUS, (float)$storageMax,
            (float)COMEBACK_ATOMS_BONUS, (float)$storageMax,
            (float)COMEBACK_ATOMS_BONUS, (float)$storageMax,
            (float)COMEBACK_ATOMS_BONUS, (float)$storageMax,
            (float)COMEBACK_ATOMS_BONUS, (float)$storageMax,
            (float)COMEBACK_ATOMS_BONUS, (float)$storageMax,
            (float)COMEBACK_ATOMS_BONUS, (float)$storageMax,
            (float)COMEBACK_ATOMS_BONUS, (float)$storageMax,
            (float)COMEBACK_ATOMS_BONUS, (float)$storageMax,
            $login
        );

        $shieldUntil = $now + (COMEBACK_SHIELD_HOURS * SECONDS_PER_HOUR);
        dbExecute($base, 'UPDATE autre SET last_catch_up = ?, comeback_shield_until = ? WHERE login = ?',
            'iis', $now, $shieldUntil, $login);

        $result = ['applied' => true, 'energy' => COMEBACK_ENERGY_BONUS, 'atoms' => COMEBACK_ATOMS_BONUS, 'shield_hours' => COMEBACK_SHIELD_HOURS];
    });

    if ($result['applied']) {
        $absentDays = ($now - $lastLogin) / SECONDS_PER_DAY;
        logInfo('COMEBACK', 'Welcome-back bonus applied', ['login' => $login, 'absent_days' => round($absentDays, 1)]);
    }

    return $result;
}

/**
 * Check if player is under comeback shield protection.
 */
function hasActiveShield($base, $login) {
    $row = dbFetchOne($base, 'SELECT comeback_shield_until FROM autre WHERE login = ?', 's', $login);
    return $row && (int)$row['comeback_shield_until'] > time();
}
