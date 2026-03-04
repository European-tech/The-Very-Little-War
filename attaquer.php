<?php

include("includes/basicprivatephp.php");
include("includes/redirectionVacance.php");

// Single query for nbattaques (was duplicated — optimization 7).
$donneesMedaille = dbFetchOne($base, 'SELECT nbattaques FROM autre WHERE login=?', 's', $_SESSION['login']);
$bonus = 0;
$reduction = 0;

foreach ($paliersTerreur as $num => $palier) {
    if ($donneesMedaille['nbattaques'] >= $palier) {
        $bonus = $bonusMedailles[$num];
        $reduction = $bonusMedailles[$num];
    }
}

$coutPourUnAtome = ATTACK_ENERGY_COST_FACTOR * (1 - $bonus / 100);

if (isset($_POST['joueurAEspionner']) && isset($_POST['nombreneutrinos'])) {
    csrfCheck();
    if (!empty($_POST['joueurAEspionner']) && !empty($_POST['nombreneutrinos'])) { // Vérification que la variable n'est pas vide
        $_POST['joueurAEspionner'] = trim($_POST['joueurAEspionner']);
        $_POST['nombreneutrinos'] = intval($_POST['nombreneutrinos']);
        if ($_POST['joueurAEspionner'] != $_SESSION['login']) {
            if (preg_match("#^[0-9]*$#", $_POST['nombreneutrinos']) and $_POST['nombreneutrinos'] >= 1 and $_POST['nombreneutrinos'] <= $autre['neutrinos']) {
                $membreJoueur = dbFetchOne($base, 'SELECT * FROM membre WHERE login=?', 's', $_POST['joueurAEspionner']);
                updateRessources($_POST['joueurAEspionner']);
                updateActions($_POST['joueurAEspionner']);


                $distance = pow(pow($membre['x'] - $membreJoueur['x'], 2) + pow($membre['y'] - $membreJoueur['y'], 2), 0.5);
                $tempsTrajet = round($distance / $vitesseEspionnage * SECONDS_PER_HOUR);

                $now = time();
                dbExecute($base, 'INSERT INTO actionsattaques VALUES(default,?,?,?,?,?,?,?,?)', 'ssiiiisi',
                    $_SESSION['login'], $_POST['joueurAEspionner'], $now, ($now + $tempsTrajet), ($now + 2 * $tempsTrajet), "Espionnage", 0, $_POST['nombreneutrinos']);
                $newNeutrinos = $autre['neutrinos'] - $_POST['nombreneutrinos'];
                dbExecute($base, 'UPDATE autre SET neutrinos=? WHERE login=?', 'is', $newNeutrinos, $_SESSION['login']);
                $autre['neutrinos'] -= $_POST['nombreneutrinos'];

                $information = 'Vous avez lancé l\'espionnage de ' . htmlspecialchars($_POST['joueurAEspionner'], ENT_QUOTES, 'UTF-8') . ' !';
            } else {
                $erreur = "Le nombre de neutrinos n'est pas valable.";
            }
        } else {
            $erreur = "Vous ne pouvez pas vous espionner.";
        }
    } else {
        $erreur = "T'y as cru ?";
    }
}
// Attaque
if (isset($_POST['joueurAAttaquer'])) {
    csrfCheck();
    if (!empty($_POST['joueurAAttaquer'])) { // Vérification que la variable n'est pas vide

        $_POST['joueurAAttaquer'] = trim($_POST['joueurAAttaquer']);
        if ($_POST['joueurAAttaquer'] != $_SESSION['login']) {

            $enVac = dbFetchOne($base, 'SELECT vacance,timestamp FROM membre WHERE login=?', 's', $_POST['joueurAAttaquer']);

            if ($enVac['vacance']) {
                $erreur = "Vous ne pouvez pas attaquer un joueur en vacances";
            } elseif (time() - $enVac['timestamp'] < BEGINNER_PROTECTION_SECONDS) {
                $erreur = "Le joueur est encore sous protection des débutants.";
            } elseif (time() - $membre['timestamp'] < BEGINNER_PROTECTION_SECONDS) {
                $erreur = "Votre protection de débutant est encore active (encore <strong>" . affichageTemps(BEGINNER_PROTECTION_SECONDS - time() + $membre['timestamp']) . " h</strong>) et vous ne pouvez donc pas attaquer.";
            } else {
                // Check attack cooldown (after failed attack on same target)
                $cooldown = dbFetchOne($base, 'SELECT expires FROM attack_cooldowns WHERE attacker=? AND defender=? AND expires > ?', 'ssi', $_SESSION['login'], $_POST['joueurAAttaquer'], time());
                if ($cooldown) {
                    $erreur = "Vous devez attendre encore <strong>" . affichageTemps($cooldown['expires'] - time()) . "</strong> avant de pouvoir attaquer ce joueur.";
                } else {

                $joueurDefenseur = dbFetchOne($base, 'SELECT * FROM autre WHERE login=?', 's', $_POST['joueurAAttaquer']);
                if ($joueurDefenseur) {
                    $nb = 1;
                } else {
                    $nb = 0;
                }

                $positions = dbFetchOne($base, 'SELECT x,y FROM membre WHERE login=?', 's', $_POST['joueurAAttaquer']);

                if ($nb > 0) {
                    $bool = 1;

                    $troupesPositives = true; // si sup a 0
                    for ($i = 1; $i <= $nbClasses; $i++) {
                        if (!isset($_POST['nbclasse' . $i])) {
                            $_POST['nbclasse' . $i] = 0;
                        }

                        if ($_POST['nbclasse' . $i] < 0) {
                            $troupesPositives = false;
                        }
                    }

                    // Check at least 1 molecule is sent (prevent zero-troop exploit)
                    $totalTroops = 0;
                    for ($c = 1; $c <= $nbClasses; $c++) {
                        $totalTroops += intval($_POST['nbclasse' . $c]);
                    }
                    if ($totalTroops < 1) {
                        $troupesPositives = false;
                    }

                    if ($troupesPositives) {

                        $moleculesAttaqueRows = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=?', 's', $_SESSION['login']);
                        $c = 1;
                        $tempsTrajet = 0;
                        $troupes = "";
                        $cout = 0;

                        foreach ($moleculesAttaqueRows as $moleculesAttaque) {
                            if (ceil($moleculesAttaque['nombre']) < $_POST['nbclasse' . $c]) {
                                $bool = 0;
                            }

                            if ($moleculesAttaque['nombre'] < $_POST['nbclasse' . $c]) { // si on envoie le tout alors, il faut prendre aussi la virgule
                                $_POST['nbclasse' . $c] =  $moleculesAttaque['nombre'];
                            }

                            if ($_POST['nbclasse' . $c] == "") {
                                $_POST['nbclasse' . $c] = 0;
                            }

                            if ($moleculesAttaque['formule'] != "Vide" && $_POST['nbclasse' . $c] > 0) {
                                $distance = pow(pow($membre['x'] - $positions['x'], 2) + pow($membre['y'] - $positions['y'], 2), 0.5);
                                $tempsTrajet = max($tempsTrajet, round($distance / vitesse($moleculesAttaque['chlore'], $moleculesAttaque['azote'], $niveauchlore) * SECONDS_PER_HOUR));
                            }
                            $troupes = $troupes . $_POST['nbclasse' . $c] . ';';

                            $nbAtomes = 0;
                            foreach ($nomsRes as $num => $res) {
                                $nbAtomes += $moleculesAttaque[$res];
                            }
                            $cout += $_POST['nbclasse' . $c] * $coutPourUnAtome * $nbAtomes;

                            $c++;
                        }

                        if ($cout <= $ressources['energie']) {
                            if ($bool) {
                                withTransaction($base, function() use ($base, $cout, $troupes, $tempsTrajet) {
                                    $moleculesAttaqueTxRows = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC FOR UPDATE', 's', $_SESSION['login']);
                                    $c = 1;
                                    foreach ($moleculesAttaqueTxRows as $moleculesAttaque) {
                                        $newNombre = $moleculesAttaque['nombre'] - $_POST['nbclasse' . $c];
                                        if ($newNombre < 0) {
                                            throw new Exception('Pas assez de molécules');
                                        }
                                        dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di', $newNombre, $moleculesAttaque['id']);
                                        $c++;
                                    }
                                    $now = time();
                                    dbExecute($base, 'INSERT INTO actionsattaques VALUES(default,?,?,?,?,?,?,0,default)', 'ssiiiss',
                                        $_SESSION['login'], $_POST['joueurAAttaquer'], $now, ($now + $tempsTrajet), ($now + 2 * $tempsTrajet), $troupes);
                                    ajouter('energie', 'ressources', -$cout, $_SESSION['login']);
                                    ajouter('energieDepensee', 'autre', $cout, $_SESSION['login']);
                                });
                                logInfo('ATTACK', 'Attack launched', ['attacker' => $_SESSION['login'], 'defender' => $_POST['joueurAAttaquer'], 'troops' => $troupes, 'energy_cost' => $cout]);
                                $information = "L'attaque a été lancée.";
                            } else {
                                $erreur = "Vous n\'avez pas assez de molécules.";
                            }
                        } else {
                            $erreur = "Vous n\'avez pas assez d\'énergie.";
                        }
                    } else {
                        $erreur = "Votre nombre de troupes doit être positif.";
                    }
                } else {
                    $erreur = "Ce joueur n\'existe pas.";
                }
                } // end cooldown else
            }
        } else {
            $erreur = "Vous ne pouvez pas vous attaquer.";
        }
    } else {
        $erreur = "T'y as cru ?";
    }
}

include("includes/layout.php");

if (time() - $membre['timestamp'] < BEGINNER_PROTECTION_SECONDS) {
    debutCarte();
    echo '<div class="table-responsive"><table>';
    echo '<tr><td><img src="images/attaquer/baby.png" class="imageChip" alt="bebe"/><td><td>Fin de la protection des débutants le ' . date('d/m/y \à H\hi', $membre['timestamp'] + BEGINNER_PROTECTION_SECONDS);
    echo '</table></div>';
    finCarte();
}

$nbResult = dbFetchOne($base, 'SELECT count(*) AS nb FROM actionsattaques WHERE attaquant=? OR (defenseur=? AND troupes!=?)', 'sss', $_SESSION['login'], $_SESSION['login'], 'Espionnage');
$nb = $nbResult;

$actionsattaquesRows = dbFetchAll($base, 'SELECT * FROM actionsattaques WHERE attaquant=? OR defenseur=? ORDER BY tempsAttaque ASC', 'ss', $_SESSION['login'], $_SESSION['login']);
if ($nb['nb'] > 0) {
    debutCarte();
    scriptAffichageTemps();
    echo '<div class="table-responsive"><table>';
    echo '<tr><th>Type</th><th>Joueur</th><th>Temps</th></tr>';

    foreach ($actionsattaquesRows as $actionsattaques) {

        if ($_SESSION['login'] == $actionsattaques['attaquant']) { // faire si retour ou non
            if (time() < $actionsattaques['tempsAttaque']) {
                if ($actionsattaques['troupes'] != 'Espionnage') {
                    echo '<tr><td><a href="attaque.php?id=' . $actionsattaques['id'] . '"><img src="images/rapports/sword.png" class="imageChip" alt="epee"/></a></td><td><a href="joueur.php?id=' . $actionsattaques['defenseur'] . '">' . $actionsattaques['defenseur'] . '</a></td><td id="affichage' . $actionsattaques['id'] . '">' . affichageTemps($actionsattaques['tempsAttaque'] - time()) . '</td></tr>';
                } else {
                    echo '<tr><td><img src="images/rapports/binoculars.png" class="imageChip" alt="espion"/></td><td><a href="joueur.php?id=' . $actionsattaques['defenseur'] . '">' . $actionsattaques['defenseur'] . '</a></td><td id="affichage' . $actionsattaques['id'] . '">' . affichageTemps($actionsattaques['tempsAttaque'] - time()) . '</td></tr>';
                }

                echo '
                ' . cspScriptTag() . '
                    var valeur' . $actionsattaques['id'] . ' = ' . ($actionsattaques['tempsAttaque'] - time()) . ';

                    function tempsDynamique' . $actionsattaques['id'] . '(){
                        if(valeur' . $actionsattaques['id'] . ' > 0){
                            valeur' . $actionsattaques['id'] . ' -= 1;
                            document.getElementById("affichage' . $actionsattaques['id'] . '").innerHTML = affichageTemps(valeur' . $actionsattaques['id'] . ');
                        }
                        else {
                            document.location.href="attaquer.php";
                        }
                    }

                    setInterval(tempsDynamique' . $actionsattaques['id'] . ', 1000);
                    </script>';
            } else {
                echo '<tr><td><a href="attaque.php?id=' . $actionsattaques['id'] . '"><img src="images/attaquer/retour.png" class="imageChip" alt="epee"/></a></td><td>Retour</td><td id="affichage' . $actionsattaques['id'] . '">' . affichageTemps($actionsattaques['tempsRetour'] - time()) . '</td></tr>';
                echo cspScriptTag() . '
                    var valeur' . $actionsattaques['id'] . ' = ' . ($actionsattaques['tempsRetour'] - time()) . ';

                    function tempsDynamique' . $actionsattaques['id'] . '(){
                        if(valeur' . $actionsattaques['id'] . ' > 0){
                            valeur' . $actionsattaques['id'] . ' -= 1;
                            document.getElementById("affichage' . $actionsattaques['id'] . '").innerHTML = affichageTemps(valeur' . $actionsattaques['id'] . ');
                        }
                        else {
                            document.location.href="attaquer.php";
                        }
                    }

                    setInterval(tempsDynamique' . $actionsattaques['id'] . ', 1000);
                    </script>';
            }
        } else {
            if ($actionsattaques['troupes'] != 'Espionnage' && $actionsattaques['attaqueFaite'] == 0) {
                echo '<tr><td><img src="images/batiments/shield.png" class="imageChip" alt="bouclier"/></td><td><a href="joueur.php?id=' . $actionsattaques['attaquant'] . '">' . $actionsattaques['attaquant'] . '</a></td><td>?</td>';
            }
        }
    }
    echo '</table></div>';
    finCarte();
}


if (!isset($_GET['type'])) {
    $_GET['type'] = 0;
}

if ($_GET['type'] == 0) {
    debutCarte("Carte" . aide("carte"), "", false, 'conteneurCarte');
    $tailleTile = MAP_TILE_SIZE_PX;
    $centre = ['x' => $membre['x'], 'y' => $membre['y']];

    $tailleCarte = dbFetchOne($base, 'SELECT tailleCarte FROM statistiques');

    $carte = [];
    for ($i = 0; $i < $tailleCarte['tailleCarte']; $i++) {
        $temp = [];
        for ($j = 0; $j < $tailleCarte['tailleCarte']; $j++) {
            $temp[] = 0;
        }
        $carte[] = $temp;
    }

    if (isset($_GET['x'])) {
        $x = intval($_GET['x']);
    } else {
        $x = $centre['x'];
    }

    if (isset($_GET['y'])) {
        $y = intval($_GET['y']);
    } else {
        $y = $centre['y'];
    }

    // Optimization 5: single JOIN to get all players with their alliance info,
    // then pre-load all active wars and pacts — eliminates ~2 queries per player.
    $myAllianceId = (int) $autre['idalliance'];

    $allPlayers = dbFetchAll($base, 'SELECT m.id, m.login, m.x, m.y, a.points, a.idalliance FROM membre m JOIN autre a ON m.login = a.login', '');

    // Pre-load active wars and pacts involving my alliance (only meaningful when in an alliance).
    $warAllianceIds = [];
    $pactAllianceIds = [];
    if ($myAllianceId > 0) {
        $wars = dbFetchAll($base, 'SELECT alliance1, alliance2 FROM declarations WHERE type=0 AND (alliance1=? OR alliance2=?) AND fin=0', 'ii', $myAllianceId, $myAllianceId);
        foreach ($wars as $w) {
            $otherId = ($w['alliance1'] == $myAllianceId) ? (int)$w['alliance2'] : (int)$w['alliance1'];
            $warAllianceIds[$otherId] = true;
        }
        $pacts = dbFetchAll($base, 'SELECT alliance1, alliance2 FROM declarations WHERE type=1 AND (alliance1=? OR alliance2=?) AND valide!=0', 'ii', $myAllianceId, $myAllianceId);
        foreach ($pacts as $p) {
            $otherId = ($p['alliance1'] == $myAllianceId) ? (int)$p['alliance2'] : (int)$p['alliance1'];
            $pactAllianceIds[$otherId] = true;
        }
    }

    foreach ($allPlayers as $tableau) {
        $playerAllianceId = (int) $tableau['idalliance'];

        if ($tableau['login'] == $_SESSION['login']) {
            $type = 'soi';
        } elseif ($myAllianceId > 0 && $playerAllianceId > 0 && isset($warAllianceIds[$playerAllianceId])) {
            $type = 'guerre';
        } elseif ($myAllianceId > 0 && $playerAllianceId > 0 && isset($pactAllianceIds[$playerAllianceId])) {
            $type = 'pacte';
        } elseif ($playerAllianceId == $myAllianceId && $myAllianceId != 0) {
            $type = 'alliance';
        } else {
            $type = 'rien';
        }
        $carte[$tableau['x']][$tableau['y']] = [$tableau['id'], $tableau['login'], $tableau['points'], $type];
    }

?>
    <div style="width:600px;height:300px;" id="carte">
        <?php
        for ($i = 0; $i < $tailleCarte['tailleCarte']; $i++) {
            for ($j = 0; $j < $tailleCarte['tailleCarte']; $j++) {
                if ($carte[$i][$j] != 0) {
                    $mapImages = ["petit.png", "moyen.png", "grand.png", "tgrand.png", "geant.png"];
                    $image = $mapImages[count($MAP_ICON_DIVISORS)]; // default to largest
                    foreach ($MAP_ICON_DIVISORS as $idx => $divisor) {
                        if ($carte[$i][$j][2] <= floor($nbPointsVictoire / $divisor)) {
                            $image = $mapImages[$idx];
                            break;
                        }
                    }

                    if ($carte[$i][$j][3] == 'soi') {
                        $border = 'orange 2px';
                    } elseif ($carte[$i][$j][3] == 'guerre') {
                        $border = 'red 2px';
                    } elseif ($carte[$i][$j][3] == 'alliance') {
                        $border = 'blue 2px';
                    } elseif ($carte[$i][$j][3] == 'pacte') {
                        $border = 'green 2px';
                    } else {
                        $border = 'lightgray 1px';
                    }

                    $safeMapLogin = htmlspecialchars($carte[$i][$j][1], ENT_QUOTES, 'UTF-8');
                    echo '<a href="joueur.php?id=' . $safeMapLogin . '"><img src="images/carte/' . $image . '" style="position:absolute;display:block;top:' . ($i * $tailleTile) . 'px;left:' . ($j * $tailleTile) . 'px;outline:' . $border . ' solid;width:' . $tailleTile . 'px;height:' . $tailleTile . 'px;" /></a><span style="text-align:center;position:absolute;display:block;top:' . ($i * $tailleTile) . 'px;left:' . ($j * $tailleTile) . 'px;width:' . $tailleTile . 'px;opacity:0.7;background-color:black;color:white;">' . $safeMapLogin . '</span>';
                } else {
                    echo '<img src="images/carte/rien.png" style="position:absolute;display:block;top:' . ($i * $tailleTile) . 'px;left:' . ($j * $tailleTile) . 'px;outline:lightgray 1px solid" />';
                }
            }
        }
        ?>
    </div>
    <script nonce="<?php echo htmlspecialchars(cspNonce(), ENT_QUOTES, 'UTF-8'); ?>">
        document.getElementById('conteneurCarte').scrollTop = Math.max(0, parseInt(<?php echo $tailleTile * ($x + 0.5); ?> - document.getElementById('conteneurCarte').offsetHeight / 2));
        document.getElementById('conteneurCarte').scrollLeft = Math.max(0, parseInt(<?php echo $tailleTile * ($y + 0.5); ?> - document.getElementById('conteneurCarte').offsetWidth / 2));
    </script>
<?php
    finCarte();
}

if (isset($_GET['id'])) {
    $_GET['id'] = trim($_GET['id']);

    $joueur = dbFetchOne($base, 'SELECT * FROM membre WHERE login=?', 's', $_GET['id']);
    $nb = $joueur ? 1 : 0;

    if ($nb > 0) {
        if ($_GET['type'] == 1) {
            debutCarte("Attaquer");
            echo '<form method="post" action="attaquer.php" name="formAttaquer">';
            echo csrfField();
            $moleculesFormRows = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse', 's', $_SESSION['login']);
            if (!is_array($moleculesFormRows)) {
                error_log("SQL error fetching molecules for attacker view");
                echo "Une erreur est survenue.";
            } else {

            $distance = pow(pow($membre['x'] - $joueur['x'], 2) + pow($membre['y'] - $joueur['y'], 2), 0.5);
            scriptAffichageTemps();

            $res = array();
            echo important("Cible");

            echo chip(joueur($joueur['login']), '<img alt="coupe" src="images/classement/joueur.png" class="imageChip" style="width:25px;border-radius:0px;"/>', "white", false, true);
            echo chip('<a href="attaquer.php?x=' . $joueur['x'] . '&y=' . $joueur['y'] . '">' . $joueur['x'] . ';' . $joueur['y'] . ' - ' . (round(10 * $distance) / 10) . ' cases</a>', '<img alt="coupe" src="images/attaquer/map.png" class="imageChip" style="width:25px;border-radius:0px;"/>', "white", false, true);

            echo '<br/><br/>' . important("Coûts");
            echo chipInfo('0:00:00', 'images/molecule/temps.png', 'tempsAttaque');
            echo nombreEnergie(0, 'coutEnergie');
            echo '<br/><br/>';
            echo important("Troupes attaquantes");
            debutListe();

            $moleculesNonVideRows = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? AND formule!=?', 'ss', $_SESSION['login'], "Vide");

            $nombreClasses = count($moleculesNonVideRows);
            if ($nombreClasses == 0) {
                echo 'Vous n\'avez aucune molécule et vous ne pouvez donc pas attaquer.';
            } else {
                foreach ($moleculesFormRows as $molecules) {
                    if ($molecules['formule'] != "Vide") {
                        item(['titre' => '<a href="molecule.php?id=' . $molecules['id'] . '" class="lienFormule">' . couleurFormule($molecules['formule']) . '</a>', 'floating' => false, 'input' => '<input type="number" name="nbclasse' . $molecules['numeroclasse'] . '" id="nbclasse' . $molecules['numeroclasse'] . '" placeholder="Nombre" />', 'after' => nombreMolecules('<a href="#" class="lienVisible max-fill-attack" data-target-id="nbclasse' . $molecules['numeroclasse'] . '" data-max-value="' . ceil($molecules['nombre']) . '">' . ceil($molecules['nombre']) . '</a>')]);
                    }
                }
                echo '<input type="hidden" name="joueurAAttaquer" value="' . htmlspecialchars($joueur['login'], ENT_QUOTES, 'UTF-8') . '"/><br/>';
                echo submit(['titre' => 'Attaquer', 'image' => 'images/attaquer/attaquer.png', 'form' => 'formAttaquer']);
                finListe();
            }
            echo '</form>';

            $moleculesJsRows = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? AND formule!=? ORDER BY numeroclasse', 'ss', $_SESSION['login'], 'Vide');
            if (!is_array($moleculesJsRows)) {
                error_log("SQL error fetching molecules for attack JS");
            } else {
            $nbClasses = count($moleculesJsRows);
            // affichage du temps pour attaquer
            echo '
            ' . cspScriptTag() . '
            var tempsEnCours = 0;
            var tempsAttaque = [];

            function actualiseTemps(){
                tempsEnCours = 0;
                ';

            for ($i = 1; $i <= $nbClasses; $i++) {
                echo '
                    if(document.getElementById("nbclasse' . $i . '").value > 0){
                        tempsEnCours = Math.max(tempsEnCours,tempsAttaque[' . ($i - 1) . ']);
                    }
                    ';
            }
            echo '
                document.getElementById("tempsAttaque").innerHTML = affichageTemps(tempsEnCours);
                return tempsEnCours;
            }

            function actualiseCout(){
                var cout = 0;
                ';
            for ($i = 1; $i <= $nbClasses; $i++) {
                $molecules1 = dbFetchOne($base, 'SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $_SESSION['login'], $i);
                $totAtomes = 0;
                foreach ($nomsRes as $num => $res) {
                    $totAtomes += $molecules1[$res];
                }
                echo '
                cout += document.getElementById("nbclasse' . $i . '").value*' . ($totAtomes * $coutPourUnAtome) . ';';
            }
            echo '
                document.getElementById("coutEnergie").innerHTML = nFormatter(cout);
            }

            ';


            $c = 1;
            foreach ($moleculesJsRows as $molecules) {
                echo 'tempsAttaque[' . ($c - 1) . '] = ' . round($distance / vitesse($molecules['chlore'], $molecules['azote'], $niveauchlore) * SECONDS_PER_HOUR) . ';';
                echo 'document.getElementById("nbclasse' . $molecules['numeroclasse'] . '").addEventListener("input",function(){
                        var nbUnites = document.getElementById("nbclasse' . $molecules['numeroclasse'] . '").value;
                        if(nbUnites > 0){
                            document.getElementById("tempsAttaque").innerHTML = affichageTemps(Math.max(tempsAttaque[' . ($c - 1) . '],tempsEnCours));
                            tempsEnCours = Math.max(tempsAttaque[' . ($c - 1) . '],tempsEnCours);
                        }
                        else {
                            tempsEnCours = actualiseTemps();
                        }

                        actualiseCout();
                    });';
                $c++;
            }
            echo '
            // Max fill buttons for attack troops (replaces inline javascript: URIs)
            document.querySelectorAll(".max-fill-attack").forEach(function(el) {
                el.addEventListener("click", function(e) {
                    e.preventDefault();
                    var targetId = this.getAttribute("data-target-id");
                    var maxVal = this.getAttribute("data-max-value");
                    document.getElementById(targetId).value = maxVal;
                    actualiseTemps();
                    actualiseCout();
                });
            });
            </script>
            ';
            } // end else for ex

            finCarte();
            } // end else for first ex
        } elseif ($_GET['type'] == 2) {
            debutCarte("Espionner");
            echo '<form method="post" action="attaquer.php" name="formEspionner">';
            echo csrfField();
            echo important("Cible");

            echo chip(htmlspecialchars($joueur['login'], ENT_QUOTES, 'UTF-8'), '<img alt="coupe" src="images/classement/joueur.png" class="imageChip" style="width:25px;border-radius:0px;"/>', "white", false, true);
            echo chip('<a href="attaquer.php?x=' . $joueur['x'] . '&y=' . $joueur['y'] . '">' . $joueur['x'] . ';' . $joueur['y'] . ' - ' . (round(10 * (pow(pow($membre['x'] - $joueur['x'], 2) + pow($membre['y'] - $joueur['y'], 2), 0.5))) / 10) . ' cases</a>', '<img alt="coupe" src="images/attaquer/map.png" class="imageChip" style="width:25px;border-radius:0px;"/>', "white", false, true);
            echo '<br/><br/>';

            echo important("Neutrinos");
            debutListe();
            item(['input' => '<input type="number" min="0" max="' . $autre['neutrinos'] . '" name="nombreneutrinos" id="nombreneutrinos" class="form-control" placeholder="Nombre de neutrinos"/>', 'after' => nombreNeutrino($autre['neutrinos'])]);
            finListe();
            echo '<br/><br/>';

            echo important("Coût");
            echo nombreTemps(affichageTemps(SECONDS_PER_HOUR * pow(pow($membre['x'] - $joueur['x'], 2) + pow($membre['y'] - $joueur['y'], 2), 0.5) / $vitesseEspionnage));

            echo '<input type="hidden" name="joueurAEspionner" value="' . htmlspecialchars($joueur['login'], ENT_QUOTES, 'UTF-8') . '"/><br/><br/>';
            echo submit(['titre' => 'Espionner', 'image' => 'images/attaquer/espionner.png', 'form' => 'formEspionner']);

            finCarte();
        }
    } else {
        debutCarte("Dommage");
        debutContent();
        echo 'Ce joueur n\'existe pas.';
        finContent();
        finCarte();
    }
}

// Affichage avant attaque et/ou espionnage

include("includes/copyright.php"); ?>