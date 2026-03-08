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
    // MED-044: Rate limit espionage to prevent spam/flooding
    if (!rateLimitCheck('espionage_' . $_SESSION['login'], 'espionage', ESPIONAGE_RATE_LIMIT, ESPIONAGE_RATE_WINDOW)) {
        $erreur = "Trop d'espionnages récents. Attendez avant d'en lancer un autre.";
    } elseif (!empty($_POST['joueurAEspionner']) && !empty($_POST['nombreneutrinos'])) { // Vérification que la variable n'est pas vide
        // H-014: Guard against array injection before trim()
        if (!is_string($_POST['joueurAEspionner'])) {
            $erreur = 'Joueur invalide.';
        } elseif (mb_strlen($_POST['joueurAEspionner']) > LOGIN_MAX_LENGTH) {
            $erreur = 'Nom de joueur invalide.';
        }
        if (empty($erreur)) {
        $_POST['joueurAEspionner'] = trim($_POST['joueurAEspionner']);
        $_POST['nombreneutrinos'] = intval($_POST['nombreneutrinos']);
        if ($_POST['joueurAEspionner'] != $_SESSION['login']) {
            // Check vacation mode + beginner protection for espionage target
            $espTarget = dbFetchOne($base, 'SELECT vacance,timestamp FROM membre WHERE login=?', 's', $_POST['joueurAEspionner']);
            if (!$espTarget) {
                $erreur = "Ce joueur n'existe pas.";
            } elseif ($espTarget['vacance']) {
                $erreur = "Vous ne pouvez pas espionner un joueur en vacances.";
            } elseif (time() - $espTarget['timestamp'] < BEGINNER_PROTECTION_SECONDS + (hasPrestigeUnlock($_POST['joueurAEspionner'], 'veteran') ? SECONDS_PER_DAY : 0)) {
                // MED-042: Apply same prestige veteran extension as combat beginner protection.
                $erreur = "Le joueur est encore sous protection des débutants.";
            } elseif (hasActiveShield($base, $_POST['joueurAEspionner'])) {
                // Comeback shield blocks espionage as well as direct attacks (P2-HIGH-013)
                $erreur = "Ce joueur est sous protection de retour. Revenez plus tard.";
            } elseif ($autre['idalliance'] > 0 && (function() use ($base, $autre) {
                $targetAlliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_POST['joueurAEspionner']);
                return $targetAlliance && (int)$targetAlliance['idalliance'] === (int)$autre['idalliance'];
            })()) {
                // ESP-002: Alliance members cannot spy on each other
                $erreur = "Vous ne pouvez pas espionner un membre de votre alliance.";
            } elseif ((function() use ($base) {
                // ESP-001: Check attacker's own beginner protection (cannot spy while under protection)
                $attackerInfo = dbFetchOne($base, 'SELECT timestamp FROM membre WHERE login=?', 's', $_SESSION['login']);
                $veteranBonus = hasPrestigeUnlock($_SESSION['login'], 'veteran') ? SECONDS_PER_DAY : 0;
                return $attackerInfo && (time() - (int)$attackerInfo['timestamp']) < (BEGINNER_PROTECTION_SECONDS + $veteranBonus);
            })()) {
                $erreur = "Vous ne pouvez pas espionner pendant votre période de protection des débutants.";
            } elseif (preg_match("#^[0-9]*$#", (string)$_POST['nombreneutrinos']) and $_POST['nombreneutrinos'] >= 1 and $_POST['nombreneutrinos'] <= $autre['neutrinos']) {
                // MED-043: Cast to string before preg_match to avoid PHP 8.2 deprecation on integer subject.
                $membreJoueur = dbFetchOne($base, 'SELECT * FROM membre WHERE login=?', 's', $_POST['joueurAEspionner']);
                updateRessources($_POST['joueurAEspionner']);
                updateActions($_POST['joueurAEspionner']);


                $distance = pow(pow($membre['x'] - $membreJoueur['x'], 2) + pow($membre['y'] - $membreJoueur['y'], 2), 0.5);
                $tempsTrajet = round($distance / $vitesseEspionnage * SECONDS_PER_HOUR);

                $now = time();
                // GAMECORE-P10-001: Capture pre-validated values before the closure to prevent
                // double-spend race — the closure must not read $_POST directly, as $_SESSION
                // and superglobals are shared state and not re-validated under the DB lock.
                $capturedNeutrinos = (int)$_POST['nombreneutrinos'];
                $capturedCible = $_POST['joueurAEspionner'];
                $capturedLogin = $_SESSION['login'];
                // Wrap espionage in transaction with FOR UPDATE (P5-GAP-004)
                try {
                    withTransaction($base, function() use ($base, $now, $tempsTrajet, $capturedNeutrinos, $capturedCible, $capturedLogin) {
                        $autreRow = dbFetchOne($base, 'SELECT neutrinos FROM autre WHERE login=? FOR UPDATE', 's', $capturedLogin);
                        // Re-validate against the locked DB value (not the pre-closure check)
                        if ($autreRow['neutrinos'] < $capturedNeutrinos) {
                            throw new \RuntimeException('NOT_ENOUGH_NEUTRINOS');
                        }
                        dbExecute($base, 'INSERT INTO actionsattaques VALUES(default,?,?,?,?,?,?,?,?)', 'ssiiisii',
                            $capturedLogin, $capturedCible, $now, ($now + $tempsTrajet), ($now + 2 * $tempsTrajet), "Espionnage", 0, $capturedNeutrinos);
                        $newNeutrinos = $autreRow['neutrinos'] - $capturedNeutrinos;
                        dbExecute($base, 'UPDATE autre SET neutrinos=? WHERE login=?', 'is', $newNeutrinos, $capturedLogin);
                    });
                    $autre['neutrinos'] -= $capturedNeutrinos;
                    $information = 'Vous avez lancé l\'espionnage de ' . htmlspecialchars($_POST['joueurAEspionner'], ENT_QUOTES, 'UTF-8') . ' !';
                } catch (\RuntimeException $e) {
                    if ($e->getMessage() === 'NOT_ENOUGH_NEUTRINOS') {
                        $erreur = "Vous n'avez pas assez de neutrinos.";
                    } else {
                        $erreur = "Une erreur est survenue.";
                        error_log('Espionage failed: ' . $e->getMessage());
                    }
                }
            } else {
                $erreur = "Le nombre de neutrinos n'est pas valable.";
            }
        } else {
            $erreur = "Vous ne pouvez pas vous espionner.";
        }
        } // end if (empty($erreur)) — H-014 is_string guard
    } else {
        $erreur = "T'y as cru ?";
    }
}
// Attaque
if (isset($_POST['joueurAAttaquer'])) {
    csrfCheck();
    // MED-025: Re-check attacker vacation in POST handler (TOCTOU guard).
    // redirectionVacance.php covers most cases, but an explicit error here
    // ensures a proper $erreur message rather than a silent redirect.
    $attackerVacCheck = dbFetchOne($base, 'SELECT vacance FROM membre WHERE login=?', 's', $_SESSION['login']);
    if ($attackerVacCheck && $attackerVacCheck['vacance'] == 1) {
        $erreur = "Vous ne pouvez pas attaquer en mode vacances.";
    } elseif (!empty($_POST['joueurAAttaquer'])) { // Vérification que la variable n'est pas vide
        // H-014/M-007: Guard against array injection and enforce max length before trim()
        if (!is_string($_POST['joueurAAttaquer'])) {
            $erreur = 'Joueur invalide.';
        } elseif (mb_strlen($_POST['joueurAAttaquer']) > LOGIN_MAX_LENGTH) {
            $erreur = 'Nom de joueur invalide.';
        }
        if (!empty($erreur)) {
            // fall through to display error
        } else {
        $_POST['joueurAAttaquer'] = trim($_POST['joueurAAttaquer']);
        if ($_POST['joueurAAttaquer'] != $_SESSION['login']) {

            $enVac = dbFetchOne($base, 'SELECT vacance,timestamp FROM membre WHERE login=?', 's', $_POST['joueurAAttaquer']);
            if (!$enVac) {
                $erreur = "Ce joueur n'existe pas.";
            } else {
            // Prevent attacking alliance members
            $attackerAlliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);
            $defenderAlliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_POST['joueurAAttaquer']);
            // PASS1-MEDIUM-007: Fresh fetch of attacker registration timestamp to avoid stale $membre data
            $attackerFresh = dbFetchOne($base, 'SELECT timestamp FROM membre WHERE login=?', 's', $_SESSION['login']);
            $attackerTimestamp = $attackerFresh ? (int)$attackerFresh['timestamp'] : (int)$membre['timestamp'];
            if ($attackerAlliance && $defenderAlliance
                && $attackerAlliance['idalliance'] > 0
                && $attackerAlliance['idalliance'] == $defenderAlliance['idalliance']) {
                $erreur = "Vous ne pouvez pas attaquer un membre de votre alliance.";
            } elseif ($enVac['vacance']) {
                $erreur = "Vous ne pouvez pas attaquer un joueur en vacances";
            } elseif (time() - $enVac['timestamp'] < BEGINNER_PROTECTION_SECONDS + (hasPrestigeUnlock($_POST['joueurAAttaquer'], 'veteran') ? SECONDS_PER_DAY : 0)) {
                $erreur = "Le joueur est encore sous protection des débutants.";
            } elseif (time() - $attackerTimestamp < BEGINNER_PROTECTION_SECONDS + (hasPrestigeUnlock($_SESSION['login'], 'veteran') ? SECONDS_PER_DAY : 0)) {
                $attackerProtectionLeft = $attackerTimestamp + BEGINNER_PROTECTION_SECONDS + (hasPrestigeUnlock($_SESSION['login'], 'veteran') ? SECONDS_PER_DAY : 0) - time();
                $erreur = "Votre protection de débutant est encore active (encore <strong>" . affichageTemps($attackerProtectionLeft) . " h</strong>) et vous ne pouvez donc pas attaquer.";
            } elseif (hasActiveShield($base, $_POST['joueurAAttaquer'])) {
                // Comeback shield protection (P1-D8-044)
                $erreur = "Ce joueur est sous protection de retour. Revenez plus tard.";
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
                        $_POST['nbclasse' . $i] = intval($_POST['nbclasse' . $i]);

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

                        $moleculesAttaqueRows = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC', 's', $_SESSION['login']);
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
                        // LOW-010: Round energy cost to 2 decimal places to avoid float precision issues
                        $cout = round($cout, 2);

                        // Apply compound speed boost to travel time and snapshot all active bonuses.
                        // HIGH-024: Bonuses must be snapshotted NOW (before the transaction) so that
                        // combat.php uses the values that were active at launch, not at resolution.
                        require_once('includes/compounds.php');
                        $speedBoost = getCompoundBonus($base, $_SESSION['login'], 'speed_boost');
                        $atkBoostSnapshot = getCompoundBonus($base, $_SESSION['login'], 'attack_boost');
                        $defBoostSnapshot = getCompoundBonus($base, $_POST['joueurAAttaquer'], 'defense_boost');
                        $pillageBoostSnapshot = getCompoundBonus($base, $_SESSION['login'], 'pillage_boost');
                        if ($speedBoost > 0) {
                            $tempsTrajet = max(1, round($tempsTrajet / (1 + $speedBoost)));
                        }

                        if ($cout <= $ressources['energie']) {
                            if ($bool) {
                                try {
                                    withTransaction($base, function() use ($base, $cout, $troupes, $tempsTrajet, $atkBoostSnapshot, $defBoostSnapshot, $speedBoost, $pillageBoostSnapshot) {
                                        // PASS1-MEDIUM-006: Re-validate energy under FOR UPDATE lock to prevent TOCTOU
                                        $attaquant = $_SESSION['login'];
                                        $energieFraiche = dbFetchOne($base, 'SELECT energie FROM ressources WHERE login=? FOR UPDATE', 's', $attaquant);
                                        if ($energieFraiche['energie'] < $cout) {
                                            throw new RuntimeException('Énergie insuffisante');
                                        }
                                        $moleculesAttaqueTxRows = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC FOR UPDATE', 's', $attaquant);
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
                                        // Store snapshotted compound bonuses so combat.php uses launch-time values
                                        dbExecute($base,
                                            'INSERT INTO actionsattaques (attaquant, defenseur, tempsAller, tempsAttaque, tempsRetour, troupes, attaqueFaite, nombreneutrinos, compound_atk_bonus, compound_spd_bonus, compound_def_bonus, compound_pillage_bonus) VALUES (?,?,?,?,?,?,0,0,?,?,?,?)',
                                            'ssiiisdddd',
                                            $attaquant, $_POST['joueurAAttaquer'], $now, ($now + $tempsTrajet), ($now + 2 * $tempsTrajet), $troupes,
                                            $atkBoostSnapshot, $speedBoost, $defBoostSnapshot, $pillageBoostSnapshot);
                                        ajouter('energie', 'ressources', -$cout, $attaquant);
                                        ajouter('energieDepensee', 'autre', $cout, $attaquant);
                                    });
                                    logInfo('ATTACK', 'Attack launched', ['attacker' => $_SESSION['login'], 'defender' => $_POST['joueurAAttaquer'], 'troops' => $troupes, 'energy_cost' => $cout]);
                                    $information = "L'attaque a été lancée.";
                                } catch (\RuntimeException $e) {
                                    if ($e->getMessage() === 'Énergie insuffisante') {
                                        $erreur = "Vous n'avez pas assez d'énergie (solde modifié entre la vérification et l'envoi).";
                                    } else {
                                        $erreur = "Une erreur est survenue lors du lancement de l'attaque.";
                                        error_log('Attack transaction failed: ' . $e->getMessage());
                                    }
                                } catch (\Exception $e) {
                                    $erreur = "Vous n'avez pas assez de molécules.";
                                }
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
            } // end $enVac null check
        } else {
            $erreur = "Vous ne pouvez pas vous attaquer.";
        }
        } // end else — H-014/M-007 is_string guard
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
                $safeDefenseur = htmlspecialchars($actionsattaques['defenseur'], ENT_QUOTES, 'UTF-8');
                if ($actionsattaques['troupes'] != 'Espionnage') {
                    echo '<tr><td><a href="attaque.php?id=' . $actionsattaques['id'] . '"><img src="images/rapports/sword.png" class="imageChip" alt="epee"/></a></td><td><a href="joueur.php?id=' . $safeDefenseur . '">' . $safeDefenseur . '</a></td><td id="affichage' . $actionsattaques['id'] . '">' . affichageTemps(max(0, $actionsattaques['tempsAttaque'] - time())) . '</td></tr>';
                } else {
                    echo '<tr><td><img src="images/rapports/binoculars.png" class="imageChip" alt="espion"/></td><td><a href="joueur.php?id=' . $safeDefenseur . '">' . $safeDefenseur . '</a></td><td id="affichage' . $actionsattaques['id'] . '">' . affichageTemps(max(0, $actionsattaques['tempsAttaque'] - time())) . '</td></tr>';
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
                echo '<tr><td><a href="attaque.php?id=' . $actionsattaques['id'] . '"><img src="images/attaquer/retour.png" class="imageChip" alt="epee"/></a></td><td>Retour</td><td id="affichage' . $actionsattaques['id'] . '">' . affichageTemps(max(0, $actionsattaques['tempsRetour'] - time())) . '</td></tr>';
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
                $etaSeconds = max(0, $actionsattaques['tempsAttaque'] - time());
                echo '<tr><td><img src="images/batiments/shield.png" class="imageChip" alt="bouclier"/></td><td><a href="joueur.php?id=' . htmlspecialchars($actionsattaques['attaquant'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($actionsattaques['attaquant'], ENT_QUOTES, 'UTF-8') . '</a></td><td id="eta' . $actionsattaques['id'] . '">' . affichageTemps($etaSeconds) . '</td></tr>';
                echo cspScriptTag() . '
                    var eta' . $actionsattaques['id'] . ' = ' . $etaSeconds . ';
                    function etaDynamique' . $actionsattaques['id'] . '(){
                        if(eta' . $actionsattaques['id'] . ' > 0){
                            eta' . $actionsattaques['id'] . ' -= 1;
                            document.getElementById("eta' . $actionsattaques['id'] . '").innerHTML = affichageTemps(eta' . $actionsattaques['id'] . ');
                        } else {
                            document.location.href="attaquer.php";
                        }
                    }
                    setInterval(etaDynamique' . $actionsattaques['id'] . ', 1000);
                </script>';
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

    $mapSize = (int)$tailleCarte['tailleCarte'];
    $scrollX = isset($_GET['x']) ? max(0, min((int)$_GET['x'], $mapSize - 1)) : $centre['x'];
    $scrollY = isset($_GET['y']) ? max(0, min((int)$_GET['y'], $mapSize - 1)) : $centre['y'];
    $x = $scrollX;
    $y = $scrollY;

    // Optimization 5: single JOIN to get all players with their alliance info,
    // then pre-load all active wars and pacts — eliminates ~2 queries per player.
    $myAllianceId = (int) $autre['idalliance'];

    // MEDIUM-033: Use > 0 instead of >= 0 — sentinel for inactive players is x=-1000,y=-1000.
    // Coordinate (0,0) is not a valid player position (map starts at 1,1), so > 0 excludes all negative coords and the origin.
    $allPlayers = dbFetchAll($base, 'SELECT m.id, m.login, m.x, m.y, a.points, a.idalliance FROM membre m JOIN autre a ON m.login = a.login WHERE m.x > 0 AND m.y > 0', '');

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
        $px = (int)$tableau['x'];
        $py = (int)$tableau['y'];
        if ($px >= 0 && $px < $mapSize && $py >= 0 && $py < $mapSize) {
            $carte[$px][$py] = [$tableau['id'], $tableau['login'], $tableau['points'], $type];
        }
    }

?>
    <?php $mapPx = $tailleCarte['tailleCarte'] * $tailleTile; ?>
    <div style="width:<?php echo $mapPx; ?>px;height:<?php echo $mapPx; ?>px;position:relative;background-size:<?php echo $tailleTile; ?>px <?php echo $tailleTile; ?>px;background-image:linear-gradient(to right, lightgray 1px, transparent 1px),linear-gradient(to bottom, lightgray 1px, transparent 1px);" id="carte">
        <?php
        // Resource node color mapping
        $nodeColors = [
            'carbone' => '#333',    'azote' => '#4488ff',  'hydrogene' => '#ff4444',
            'oxygene' => '#44cc44', 'chlore' => '#aadd00',  'soufre' => '#ddcc00',
            'brome' => '#cc6600',   'iode' => '#9944cc',   'energie' => '#ffaa00'
        ];

        // Load resource nodes for the map
        require_once('includes/resource_nodes.php');
        $mapNodes = getActiveResourceNodes($base);

        // Render map tiles — only occupied cells (empty tiles shown via CSS grid background)
        for ($i = 0; $i < $tailleCarte['tailleCarte']; $i++) {
            for ($j = 0; $j < $tailleCarte['tailleCarte']; $j++) {
                if ($carte[$i][$j] !== 0) {
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
                }
            }
        }

        // Render resource nodes as diamond markers on top of tiles
        // MAPS-HIGH-002: At season start tailleCarte is reset to 1, but nodes are generated
        // using MAP_INITIAL_SIZE as the boundary. Use the larger of the two so nodes are
        // visible on the map from the very first login of a new season.
        $effectiveMapBound = max((int)$tailleCarte['tailleCarte'], MAP_INITIAL_SIZE);
        foreach ($mapNodes as $node) {
            // MEDIUM-032: Also reject negative coordinates (sentinel values or corrupt data)
            if ($node['x'] < 0 || $node['y'] < 0 || $node['x'] >= $effectiveMapBound || $node['y'] >= $effectiveMapBound) continue;
            $color = $nodeColors[$node['resource_type']] ?? '#fff';
            $safeType = htmlspecialchars(ucfirst($node['resource_type']), ENT_QUOTES, 'UTF-8');
            $nodeSize = 16;
            $offset = ($tailleTile - $nodeSize) / 2;
            echo '<span title="' . $safeType . ' +' . (int)$node['bonus_pct'] . '% (rayon ' . (int)$node['radius'] . ')" style="position:absolute;display:block;top:' . ($node['x'] * $tailleTile + $offset) . 'px;left:' . ($node['y'] * $tailleTile + $offset) . 'px;width:' . $nodeSize . 'px;height:' . $nodeSize . 'px;background:' . $color . ';transform:rotate(45deg);opacity:0.85;border:1px solid #fff;pointer-events:auto;z-index:2;"></span>';
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
                if ($molecules1) {
                    foreach ($nomsRes as $num => $res) {
                        $totAtomes += $molecules1[$res];
                    }
                }
                echo '
                cout += document.getElementById("nbclasse' . $i . '").value*' . ($totAtomes * $coutPourUnAtome) . ';';
            }
            echo '
                document.getElementById("coutEnergie").textContent = nFormatter(cout);
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