<?php
include("includes/basicprivatephp.php");
include("includes/redirectionVacance.php");
//tableau d'échange de ressources

$actifs = dbFetchOne($base, 'SELECT count(*) AS nbActifs FROM membre WHERE derniereConnexion >=?', 'i', (time() - ACTIVE_PLAYER_THRESHOLD));
$volatilite = MARKET_VOLATILITY_FACTOR / max(1, $actifs['nbActifs']);


$val = dbFetchOne($base, 'SELECT * FROM cours ORDER BY timestamp DESC LIMIT 1');
if (!$val) {
    // No market data yet — initialize with default prices (1.0 per resource)
    $tabCours = array_fill(0, $nbRes + 1, 1.0);
} else {
    $tabCours = explode(",", $val['tableauCours']);
}


$bool = 1;
foreach ($nomsRes as $num => $ressource) {
    if (!(isset($_POST[$ressource . 'Envoyee']))) {
        $bool = 0;
    }
}
if (isset($_POST['energieEnvoyee']) and $bool == 1 and isset($_POST['destinataire'])) {
    csrfCheck();
    if (!empty($_POST['destinataire'])) {
        $_POST['destinataire'] = trim($_POST['destinataire']);

        $verification = dbFetchOne($base, 'SELECT count(*) AS joueurOuPas FROM membre WHERE login=?', 's', $_POST['destinataire']);
        if (!$verification || $verification['joueurOuPas'] != 1) {
            $erreur = "Le destinataire n'existe pas.";
        } else {
        $ipdd = dbFetchOne($base, 'SELECT ip FROM membre WHERE login=?', 's', $_POST['destinataire']);
        $ipmm = dbFetchOne($base, 'SELECT ip FROM membre WHERE login=?', 's', $_SESSION['login']);

        // Block transfers between flagged multi-account pairs
        require_once('includes/multiaccount.php');
        if (areFlaggedAccounts($base, $_SESSION['login'], $_POST['destinataire'])) {
            $erreur = "Transfert bloqué : les comptes sont sous surveillance pour suspicion de multi-compte.";
        } elseif ($ipmm['ip'] != $ipdd['ip']) {
            if (empty($_POST['energieEnvoyee'])) {
                $_POST['energieEnvoyee'] = 0;
            }
            $_POST['energieEnvoyee'] = transformInt($_POST['energieEnvoyee']);
            $_POST['energieEnvoyee'] = intval($_POST['energieEnvoyee']);

            foreach ($nomsRes as $num => $ressource) {
                if (empty($_POST[$ressource . 'Envoyee'])) {
                    $_POST[$ressource . 'Envoyee'] = 0;
                }
                $_POST[$ressource . 'Envoyee'] = transformInt($_POST[$ressource . 'Envoyee']);
                $_POST[$ressource . 'Envoyee'] = intval($_POST[$ressource . 'Envoyee']);
            }
            $bool = 1;
            foreach ($nomsRes as $num => $ressource) {
                if (!(preg_match("#^[0-9]*$#", $_POST[$ressource . 'Envoyee']))) {
                    $bool = 0;
                }
            }
            if (preg_match("#^[0-9]*$#", $_POST['energieEnvoyee']) and $bool == 1) {
                $verification = dbFetchOne($base, 'SELECT count(*) AS joueurOuPas FROM membre WHERE login=?', 's', $_POST['destinataire']);
                if ($verification['joueurOuPas'] == 1) {
                    // Self-transfer check (P4-ADV-003)
                    if ($_POST['destinataire'] === $_SESSION['login']) {
                        $erreur = "Vous ne pouvez pas vous envoyer des ressources.";
                    } elseif ($_POST['energieEnvoyee'] == 0 && array_sum(array_map(function($r) { return (int)$_POST[$r . 'Envoyee']; }, $nomsRes)) == 0) {
                        $erreur = "Vous devez envoyer au moins une ressource.";
                    } else {
                    try {
                        $transferInfo = '';
                        withTransaction($base, function() use ($base, $nomsRes, $nbRes, $membre, &$transferInfo, &$revenuEnergie, &$revenu, &$vitesseMarchands) {
                            // Lock sender resources to prevent race condition (P5-GAP-002)
                            $ressources = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=? FOR UPDATE', 's', $_SESSION['login']);

                            $bool = 1;
                            foreach ($nomsRes as $num => $ressource) {
                                if ($ressources[$ressource] < $_POST[$ressource . 'Envoyee']) {
                                    $bool = 0;
                                }
                            }
                            if (!($ressources['energie'] >= $_POST['energieEnvoyee'] and $bool == 1)) {
                                throw new \RuntimeException('NOT_ENOUGH_RESOURCES');
                            }

                            $constructionsJoueur = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $_POST['destinataire']);

                            // PASS1-MEDIUM-010: Check recipient storage capacity before accepting the transfer.
                            // Reject if the recipient has no room in any of the resources/energy being sent.
                            $maxStorageReceveur = placeDepot($constructionsJoueur['depot']);
                            $ressourcesReceveur = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $_POST['destinataire']);
                            if ($ressourcesReceveur) {
                                $noRoomCount = 0;
                                $sentCount = 0;
                                if ($_POST['energieEnvoyee'] > 0) {
                                    $sentCount++;
                                    if ($ressourcesReceveur['energie'] >= $maxStorageReceveur) {
                                        $noRoomCount++;
                                    }
                                }
                                foreach ($nomsRes as $num => $ressource) {
                                    if ($_POST[$ressource . 'Envoyee'] > 0) {
                                        $sentCount++;
                                        if ($ressourcesReceveur[$ressource] >= $maxStorageReceveur) {
                                            $noRoomCount++;
                                        }
                                    }
                                }
                                if ($sentCount > 0 && $noRoomCount === $sentCount) {
                                    throw new \RuntimeException('RECIPIENT_STORAGE_FULL');
                                }
                            }

                            // V4: Invert ratio — penalize alt→main feeding, allow big→small charity
                            $receiverEnergyRev = revenuEnergie($constructionsJoueur['generateur'], $_POST['destinataire']);
                            if ($receiverEnergyRev > $revenuEnergie) {
                                $rapportEnergie = min(1.0, $revenuEnergie / max(1, $receiverEnergyRev));
                            } else {
                                $rapportEnergie = 1;
                            }

                            foreach ($nomsRes as $num => $ressource) {
                                $receiverAtomRev = revenuAtome($num, $_POST['destinataire']);
                                if ($receiverAtomRev > $revenu[$ressource]) {
                                    ${'rapport' . $ressource} = min(1.0, $revenu[$ressource] / max(1, $receiverAtomRev));
                                } else {
                                    ${'rapport' . $ressource} = 1;
                                }
                            }

                            $ressourcesEnvoyees = '';
                            $ressourcesRecues = '';

                            foreach ($nomsRes as $num => $ressource) {
                                $ressourcesEnvoyees = $ressourcesEnvoyees . $_POST[$ressource . 'Envoyee'] . ";";
                                $ressourcesRecues = $ressourcesRecues . (${'rapport' . $ressource} * $_POST[$ressource . 'Envoyee']) . ";";
                            }

                            $joueur = dbFetchOne($base, 'SELECT x,y FROM membre WHERE login=?', 's', $_POST['destinataire']);

                            $distance = pow(pow($membre['x'] - $joueur['x'], 2) + pow($membre['y'] - $joueur['y'], 2), 0.5);

                            $ressourcesEnvoyees = $ressourcesEnvoyees . $_POST['energieEnvoyee'];
                            $ressourcesRecues = $ressourcesRecues . $rapportEnergie * $_POST['energieEnvoyee'];

                            $tempsArrivee = time() + round(SECONDS_PER_HOUR * $distance / $vitesseMarchands);
                            dbExecute($base, 'INSERT INTO actionsenvoi VALUES(default,?,?,?,?,?)', 'ssssi',
                                $_SESSION['login'], $_POST['destinataire'], $ressourcesEnvoyees, $ressourcesRecues, $tempsArrivee);

                            // Build parameterized UPDATE for ressources
                            $newEnergie = $ressources['energie'] - $_POST['energieEnvoyee'];
                            $setClauses = ['energie=?'];
                            $paramTypes = 'd';
                            $paramValues = [$newEnergie];
                            foreach ($nomsRes as $num => $ressource) {
                                $setClauses[] = $ressource . '=?';
                                $paramTypes .= 'd';
                                $paramValues[] = $ressources[$ressource] - $_POST[$ressource . 'Envoyee'];
                            }
                            $paramTypes .= 's';
                            $paramValues[] = $_SESSION['login'];
                            dbExecute($base, 'UPDATE ressources SET ' . implode(', ', $setClauses) . ' WHERE login=?', $paramTypes, ...$paramValues);

                            // Build info string for display
                            $infoChaine = "";
                            foreach ($nomsRes as $num => $ressource) {
                                if ($_POST[$ressource . 'Envoyee'] > 0) {
                                    $infoChaine = $infoChaine . ' ' . number_format($_POST[$ressource . 'Envoyee'], 0, ' ', ' ') . ' <img class="imageAide" src="images/' . $ressource . '.png" alt="' . $ressource . '"/>';
                                }
                            }
                            if ($_POST['energieEnvoyee'] > 0) {
                                $transferInfo = "Vous avez envoyé " . number_format($_POST['energieEnvoyee'], 0, ' ', ' ') . "<img class=\"imageAide\" src=\"images/energie.png\" alt=\"energie\"/> " . $infoChaine . ' à ' . $_POST['destinataire'];
                            } else {
                                $transferInfo = "Vous avez envoyé " . $infoChaine . " à " . $_POST['destinataire'];
                            }
                        });

                        // Multi-account: check for suspicious transfer patterns (after commit)
                        require_once('includes/multiaccount.php');
                        checkTransferPatterns($base, $_SESSION['login'], $_POST['destinataire'], time());

                        $information = $transferInfo;
                    } catch (\RuntimeException $e) {
                        if ($e->getMessage() === 'NOT_ENOUGH_RESOURCES') {
                            $erreur = "Vous n'avez pas assez de ressources.";
                        } elseif ($e->getMessage() === 'RECIPIENT_STORAGE_FULL') {
                            $erreur = "Le destinataire n'a pas de place dans son stockage pour ces ressources.";
                        } else {
                            $erreur = "Erreur lors du transfert.";
                            error_log('Transfer failed: ' . $e->getMessage());
                        }
                    }
                    } // end self-transfer check
                } else {
                    $erreur = "Le destinataire n'existe pas.";
                }
            } else {
                $erreur = "Seul des nombres entiers et positifs doivent être entrés.";
            }
        } else {
            $erreur = "Impossible d'envoyer des ressources à ce joueur. Même adresse IP.";
        }
        } // end recipient exists check
    } else {
        $erreur = "Vous n'avez pas entré de destinataire.";
    }
}

if (isset($_POST['typeRessourceAAcheter']) and isset($_POST['nombreRessourceAAcheter'])) {
    csrfCheck();
    $_POST['nombreRessourceAAcheter'] = intval(transformInt($_POST['nombreRessourceAAcheter']));
    $_POST['typeRessourceAAcheter'] = trim($_POST['typeRessourceAAcheter']);
    if (!empty($_POST['nombreRessourceAAcheter']) and preg_match("#^[0-9]*$#", $_POST['nombreRessourceAAcheter'])) {
        $bool = 1;
        $numRes = -1;
        foreach ($nomsRes as $num => $ressource) {
            if ($_POST['typeRessourceAAcheter'] == $ressource) {
                $bool = 0;
                $numRes = $num;
            }
        }
        if ($bool == 0) { // verification que c'est une ressource qui existe
            // PASS1-MEDIUM-009: Ensure cost is at least 1 to prevent free-atom exploit when price*qty rounds to 0
            $coutAchat = max(1, (int)round($tabCours[$numRes] * $_POST['nombreRessourceAAcheter']));
            // Advisory pre-check (may use stale data); authoritative check is inside the transaction with FOR UPDATE
            $diffEnergieAchat = $ressources['energie'] - $coutAchat;
            if ($diffEnergieAchat >= 0) {
                $newResVal = $ressources[$nomsRes[$numRes]] + $_POST['nombreRessourceAAcheter'];
                if ($newResVal > $placeDepot) {
                    $erreur = "Vous n'avez pas assez de place dans votre stockage.";
                } else {
                    try {
                        withTransaction($base, function() use ($base, &$diffEnergieAchat, &$newResVal, $nomsRes, $numRes, $tabCours, $volatilite, $placeDepot, $coutAchat) {
                            // PASS1-MEDIUM-020: Re-read depot level inside transaction to get authoritative storage cap
                            $depotRow = dbFetchOne($base, 'SELECT depot FROM constructions WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
                            $placeDepotTx = placeDepot($depotRow ? (int)$depotRow['depot'] : 1);

                            // Re-read resources with lock to prevent race condition
                            $locked = dbFetchOne($base, 'SELECT energie, ' . $nomsRes[$numRes] . ' AS res FROM ressources WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
                            $diffEnergieAchat = $locked['energie'] - $coutAchat;
                            if ($diffEnergieAchat < 0) {
                                throw new Exception('NOT_ENOUGH_ENERGY');
                            }
                            $newResVal = $locked['res'] + $_POST['nombreRessourceAAcheter'];
                            if ($newResVal > $placeDepotTx) {
                                throw new Exception('NOT_ENOUGH_STORAGE');
                            }
                            dbExecute($base, 'UPDATE ressources SET energie=?, ' . $nomsRes[$numRes] . '=? WHERE login=?', 'dds', $diffEnergieAchat, $newResVal, $_SESSION['login']);

                            // PASS1-MEDIUM-018: Re-read current market price with lock to prevent stale-price race
                            $coursRow = dbFetchOne($base, 'SELECT tableauCours FROM cours ORDER BY timestamp DESC LIMIT 1 FOR UPDATE');
                            $txTabCours = $coursRow ? explode(',', $coursRow['tableauCours']) : $tabCours;

                            $chaine = '';
                            foreach ($txTabCours as $num => $cours) {
                                if ($num < sizeof($txTabCours) - 1) {
                                    $fin = ",";
                                } else {
                                    $fin = "";
                                }

                                if ($numRes == $num) {
                                    $ajout = $txTabCours[$num] + $volatilite * $_POST['nombreRessourceAAcheter'] / MARKET_GLOBAL_ECONOMY_DIVISOR;
                                    // Mean-reversion: pull price toward baseline of 1.0 (catalyst Équilibre boosts convergence)
                                    $meanReversion = MARKET_MEAN_REVERSION * (1 + catalystEffect('market_convergence'));
                                    $ajout = $ajout * (1 - $meanReversion) + 1.0 * $meanReversion;
                                    // Clamp to floor/ceiling
                                    $ajout = max(MARKET_PRICE_FLOOR, min(MARKET_PRICE_CEILING, $ajout));
                                    $chaine = $chaine . $ajout . $fin;
                                } else {
                                    $chaine = $chaine . $txTabCours[$num] . $fin;
                                }
                            }

                            $now = time();
                            dbExecute($base, 'INSERT INTO cours VALUES (default,?,?)', 'si', $chaine, $now);

                            // Award trade points based on energy spent (not atom volume, to prevent buy-sell exploits)
                            $reseauBonus = 1 + allianceResearchBonus($_SESSION['login'], 'trade_points');
                            // PASS1-MEDIUM-019: Atomic tradeVolume increment to prevent read-then-write race
                            $tradeVolumeDelta = round($coutAchat * $reseauBonus);
                            dbExecute($base, 'UPDATE autre SET tradeVolume = tradeVolume + ? WHERE login=?', 'ds', $tradeVolumeDelta, $_SESSION['login']);
                            recalculerTotalPointsJoueur($base, $_SESSION['login']);
                        });
                        logInfo('MARKET', 'Market buy', ['resource' => $_POST['typeRessourceAAcheter'], 'amount' => $_POST['nombreRessourceAAcheter'], 'energy_cost' => $coutAchat]);
                        $safeResName = $nomsRes[$numRes]; // server-side validated resource name
                        $information = "Vous avez acheté " . number_format($_POST['nombreRessourceAAcheter'], 0, ' ', ' ') . " <img class=\"imageAide\" src=\"images/" . htmlspecialchars($safeResName, ENT_QUOTES, 'UTF-8') . ".png\" alt=\"" . htmlspecialchars($safeResName, ENT_QUOTES, 'UTF-8') . "\"/> pour " . number_format(round($tabCours[$numRes] * $_POST['nombreRessourceAAcheter']), 0, ' ', ' ') . " <img class=\"imageAide\" src=\"images/energie.png\" alt=\"energie\"/>";

                        $val = dbFetchOne($base, 'SELECT * FROM cours ORDER BY timestamp DESC LIMIT 1');
                        $tabCours = explode(",", $val['tableauCours']);
                    } catch (Exception $e) {
                        if ($e->getMessage() === 'NOT_ENOUGH_ENERGY') {
                            $erreur = "Vous n'avez pas assez d'énergie.";
                        } elseif ($e->getMessage() === 'NOT_ENOUGH_STORAGE') {
                            $erreur = "Vous n'avez pas assez de place dans votre stockage.";
                        } else {
                            $erreur = "Une erreur est survenue lors de l'achat. Veuillez réessayer.";
                            error_log('Market buy transaction failed: ' . $e->getMessage());
                        }
                    }
                } // end else (storage check)
            } else {
                $erreur = "Vous n'avez pas assez d'énergie.";
            }
        } else {
            $erreur = "Cette ressource n'existe pas.";
        }
    } else {
        $erreur = "Vous ne devez entrer que des nombre entiers et positifs.";
    }
}

if (isset($_POST['typeRessourceAVendre']) and isset($_POST['nombreRessourceAVendre'])) {
    csrfCheck();
    $_POST['nombreRessourceAVendre'] = intval(transformInt($_POST['nombreRessourceAVendre']));
    $_POST['typeRessourceAVendre'] = trim($_POST['typeRessourceAVendre']);
    if (!empty($_POST['nombreRessourceAVendre']) and preg_match("#^[0-9]*$#", $_POST['nombreRessourceAVendre'])) {
        $bool = 1;
        $numRes = -1;
        foreach ($nomsRes as $num => $ressource) {
            if ($_POST['typeRessourceAVendre'] == $ressource) {
                $bool = 0;
                $numRes = $num;
            }
        }
        if ($bool == 0) { // verification que c'est une ressource qui existe
            // Advisory pre-check (may use stale data); authoritative check is inside the transaction with FOR UPDATE
            if ($ressources[$nomsRes[$numRes]] >= $_POST['nombreRessourceAVendre']) {
                // FIX FINDING-GAME-005: 5% sell tax to prevent buy-sell arbitrage for trade points
                $sellTaxRate = MARKET_SELL_TAX_RATE;
                $newEnergie = $ressources['energie'] + round($tabCours[$numRes] * $_POST['nombreRessourceAVendre'] * $sellTaxRate);
                if ($newEnergie > $placeDepot) {
                    $newEnergie = $placeDepot; // Cap energy at storage limit
                }
                $newResVal = $ressources[$nomsRes[$numRes]] - $_POST['nombreRessourceAVendre'];
                $energyGained = round($tabCours[$numRes] * $_POST['nombreRessourceAVendre'] * $sellTaxRate);
                $actualSold = 0; // will be set inside the transaction
                try {
                    withTransaction($base, function() use ($base, &$newEnergie, &$newResVal, &$energyGained, &$actualSold, $nomsRes, $numRes, $tabCours, $volatilite, $placeDepot, $sellTaxRate) {
                        // PASS1-MEDIUM-020: Re-read depot level inside transaction to get authoritative storage cap
                        $depotRow = dbFetchOne($base, 'SELECT depot FROM constructions WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
                        $placeDepotTx = placeDepot($depotRow ? (int)$depotRow['depot'] : 1);

                        // Re-read resources with lock to prevent race condition
                        $locked = dbFetchOne($base, 'SELECT energie, ' . $nomsRes[$numRes] . ' AS res FROM ressources WHERE login=? FOR UPDATE', 's', $_SESSION['login']);
                        if ($locked['res'] < $_POST['nombreRessourceAVendre']) {
                            throw new Exception('NOT_ENOUGH_ATOMS');
                        }

                        // V4: Overflow fix — only sell atoms that fit in remaining energy space
                        // PASS1-MEDIUM-018: Use freshly locked price for sell calculations
                        $coursRow = dbFetchOne($base, 'SELECT tableauCours FROM cours ORDER BY timestamp DESC LIMIT 1 FOR UPDATE');
                        $txTabCours = $coursRow ? explode(',', $coursRow['tableauCours']) : $tabCours;
                        $energySpace = $placeDepotTx - $locked['energie'];
                        if ($energySpace <= 0) {
                            throw new Exception('ENERGY_FULL');
                        }
                        $pricePerAtom = $txTabCours[$numRes] * $sellTaxRate;
                        if ($pricePerAtom <= 0) {
                            throw new Exception('INVALID_PRICE');
                        }
                        $maxSellable = (int)min(PHP_INT_MAX, floor($energySpace / $pricePerAtom));
                        $actualSold = min($_POST['nombreRessourceAVendre'], $maxSellable, $locked['res']);
                        if ($actualSold <= 0) {
                            throw new Exception('ENERGY_FULL');
                        }

                        $newResVal = $locked['res'] - $actualSold;
                        $energyGained = round($txTabCours[$numRes] * $actualSold * $sellTaxRate);
                        $newEnergie = $locked['energie'] + $energyGained;
                        if ($newEnergie > $placeDepotTx) {
                            $newEnergie = $placeDepotTx;
                        }
                        dbExecute($base, 'UPDATE ressources SET energie=?, ' . $nomsRes[$numRes] . '=? WHERE login=?', 'dds', $newEnergie, $newResVal, $_SESSION['login']);

                        $chaine = '';
                        foreach ($txTabCours as $num => $cours) {
                            if ($num < sizeof($txTabCours) - 1) {
                                $fin = ",";
                            } else {
                                $fin = "";
                            }

                            if ($numRes == $num) {
                                // Guard against division by zero if price is 0
                                $currentPrice = max(MARKET_PRICE_FLOOR, $txTabCours[$num]);
                                $ajout = 1 / (1 / $currentPrice + $volatilite * $actualSold / MARKET_GLOBAL_ECONOMY_DIVISOR);
                                // Mean-reversion: pull price toward baseline of 1.0 (catalyst Équilibre boosts convergence)
                                $meanReversion = MARKET_MEAN_REVERSION * (1 + catalystEffect('market_convergence'));
                                $ajout = $ajout * (1 - $meanReversion) + 1.0 * $meanReversion;
                                // Clamp to floor/ceiling
                                $ajout = max(MARKET_PRICE_FLOOR, min(MARKET_PRICE_CEILING, $ajout));
                                $chaine = $chaine . $ajout . $fin;
                            } else {
                                $chaine = $chaine . $txTabCours[$num] . $fin;
                            }
                        }

                        $now = time();
                        dbExecute($base, 'INSERT INTO cours VALUES (default,?,?)', 'si', $chaine, $now);

                        // Award trade points on sell (mirror buy logic)
                        $reseauBonus = 1 + allianceResearchBonus($_SESSION['login'], 'trade_points');
                        // PASS1-MEDIUM-019: Atomic tradeVolume increment to prevent read-then-write race
                        $tradeVolumeDelta = round($energyGained * $reseauBonus);
                        dbExecute($base, 'UPDATE autre SET tradeVolume = tradeVolume + ? WHERE login=?', 'ds', $tradeVolumeDelta, $_SESSION['login']);
                        recalculerTotalPointsJoueur($base, $_SESSION['login']);
                    });
                    logInfo('MARKET', 'Market sell', ['resource' => $_POST['typeRessourceAVendre'], 'amount' => $actualSold, 'energy_gained' => $energyGained]);
                    $safeResName = $nomsRes[$numRes]; // server-side validated resource name
                    $information = "Vous avez vendu " . number_format($actualSold, 0, ' ', ' ') . " <img class=\"imageAide\" src=\"images/" . htmlspecialchars($safeResName, ENT_QUOTES, 'UTF-8') . ".png\" alt=\"" . htmlspecialchars($safeResName, ENT_QUOTES, 'UTF-8') . "\"/> pour " . number_format($energyGained, 0, ' ', ' ') . " <img class=\"imageAide\" src=\"images/energie.png\" alt=\"energie\"/> (5% de frais)";

                    $val = dbFetchOne($base, 'SELECT * FROM cours ORDER BY timestamp DESC LIMIT 1');
                    $tabCours = explode(",", $val['tableauCours']);
                } catch (Exception $e) {
                    if ($e->getMessage() === 'NOT_ENOUGH_ATOMS') {
                        $erreur = "Vous n'avez pas assez d'atomes.";
                    } elseif ($e->getMessage() === 'ENERGY_FULL') {
                        $erreur = "Votre stockage d'énergie est plein.";
                    } else {
                        $erreur = "Une erreur est survenue lors de la vente. Veuillez réessayer.";
                        error_log('Market sell transaction failed: ' . $e->getMessage());
                    }
                }
            } else {
                $erreur = "Vous n'avez pas assez d'atomes.";
            }
        } else {
            $erreur = "Cette ressource n'existe pas.";
        }
    } else {
        $erreur = "Vous ne devez entrer que des nombre entiers et positifs.";
    }
}

include("includes/layout.php");

if (!isset($_GET['sub'])) {
    $_GET['sub'] = 0;
}


$actionsenvois = dbFetchAll($base, 'SELECT * FROM actionsenvoi WHERE envoyeur=? OR receveur=? ORDER BY tempsArrivee ASC', 'ss', $_SESSION['login'], $_SESSION['login']);
$nb = count($actionsenvois); // pour ne pas voir l'espionnage

if ($nb > 0) {
    debutCarte();
    scriptAffichageTemps();
    echo '<div class="table-responsive"><table>';
    echo '<tr><th>Type</th><th>Joueur</th><th>Temps</th></tr>';

    foreach ($actionsenvois as $actionsenvoi) {

        $safeReceveur = htmlspecialchars($actionsenvoi['receveur'], ENT_QUOTES, 'UTF-8');
        $safeEnvoyeur = htmlspecialchars($actionsenvoi['envoyeur'], ENT_QUOTES, 'UTF-8');
        if ($_SESSION['login'] == $actionsenvoi['envoyeur']) { // faire si retour ou non
            echo '<tr><td><img src="images/rapports/envoi.png" class="imageChip" alt="fleche"/></td><td><a href="joueur.php?id=' . $safeReceveur . '">' . $safeReceveur . '</a></td><td id="affichage' . $actionsenvoi['id'] . '">' . affichageTemps($actionsenvoi['tempsArrivee'] - time()) . '</td></tr>';
        } else {
            echo '<tr><td><img src="images/rapports/retour.png" class="imageChip" alt="fleche"/></td><td><a href="joueur.php?id=' . $safeEnvoyeur . '">' . $safeEnvoyeur . '</a></td><td id="affichage' . $actionsenvoi['id'] . '">' . affichageTemps($actionsenvoi['tempsArrivee'] - time()) . '</td></tr>';
        }
        echo '
                ' . cspScriptTag() . '
                    var valeur' . $actionsenvoi['id'] . ' = ' . ($actionsenvoi['tempsArrivee'] - time()) . ';

                    function tempsDynamique' . $actionsenvoi['id'] . '(){
                        if(valeur' . $actionsenvoi['id'] . ' > 0){
                            valeur' . $actionsenvoi['id'] . ' -= 1;
                            document.getElementById("affichage' . $actionsenvoi['id'] . '").innerHTML = affichageTemps(valeur' . $actionsenvoi['id'] . ');
                        }
                        else {
                            document.location.href="marche.php";
                        }
                    }

                    setInterval(tempsDynamique' . $actionsenvoi['id'] . ', 1000);
                    </script>';
    }
    echo '</table></div>';
    finCarte();
}

if ($_GET['sub'] == 1) {
    debutCarte("Envoyer des ressources" . aide("envoyerRessources"));
    echo 'Cours d\'envoi : <span class="important">1</span> ' . popover("popover-envoiRes", "images/question.png");
    debutListe();
?>
    <form action="marche.php?sub=1" method="post" name="formEnvoyer">
        <?php echo csrfField(); ?>

        <?php
        item(['floating' => true, 'input' => '<input type="text" name="energieEnvoyee" id="energieEnvoyee" class="form-control"/>', 'titre' => 'Energie', 'after' => nombreEnergie(number_format($ressources['energie'], 0, ' ', ' '))]);

        foreach ($nomsRes as $num => $ressource) {
            item(['floating' => true, 'input' => '<input type="text" name="' . $ressource . 'Envoyee" id="' . $ressource . 'Envoyee" class="form-control"/>', 'titre' => ucfirst($nomsAccents[$num]), 'after' => nombreAtome($num, number_format($ressources[$ressource], 0, ' ', ' '))]);
        }

        item(['floating' => true, 'input' => '<input type="text" name="destinataire" id="destinataire" class="form-control"/>', 'titre' => 'Destinataire']);
        item(['input' => submit(['form' => 'formEnvoyer', 'titre' => 'Envoyer'])]);
        finListe();
        finCarte();

        ?>
        <div class="popover popover-envoiRes">
            <div class="popover-angle"></div>
            <div class="popover-inner">
                <div class="content-block">
                    <p>Cela signifie que les ressources reçues seront égales au rapport entre les revenus des ressources de l'envoyeur et du receveur multiplié par ce coefficient de cours d'envoi.</p>
                </div>
            </div>
        </div>


    <?php
}

if ($_GET['sub'] == 0) {
    $joueurConstr = dbFetchOne($base, 'SELECT generateur FROM constructions WHERE login = ?', 's', $_SESSION['login']);
    if ($joueurConstr && (int)$joueurConstr['generateur'] < 10) {
        echo '<div class="card" style="background:#e8f5e9;border-left:4px solid #4caf50;margin-bottom:12px;">';
        echo '<div class="card-content card-content-padding">';
        echo '<p><strong>Conseil marché :</strong> Les prix fluctuent ! Achetez quand le cours est bas ';
        echo '(près de ' . MARKET_PRICE_FLOOR . '), vendez quand il monte. Regardez le graphique ';
        echo 'pour repérer les tendances.</p>';
        echo '</div></div>';
    }
    debutCarte("Cours");
    echo '<div class="table-responsive" style="overflow-y:hidden"><div id="curve_chart" style="width: 100%; height: 400px"></div></div>';
    finCarte();
    debutCarte("Acheter");
    ?>
        <form action="marche.php?sub=0" method="post" name="formAcheter">
            <?php echo csrfField(); ?>
            <?php
            debutListe();
            $options = "";
            foreach ($nomsRes as $num => $ressource) {
                $options = $options . '<option value="' . $ressource . '" data-option-color="' . $couleursSimples[$num] . '" data-option-image="images/petitesImages/' . $ressource . '.png">' . ucfirst($ressource) . '';
            }

            item(['floating' => false, 'select' => ["typeRessourceAAcheter", $options, "javascript" => '', "hauteur" => 450], 'titre' => 'Atome']);
            item(['floating' => false, 'input' => '<input type="text" name="nombreRessourceAAcheter" id="nombreRessourceAAcheter" class="form-control" placeholder="Quantité d\'atomes"/>', 'titre' => 'Nombre']);
            item(['floating' => false, 'input' => '<input type="text" name="coutEnergieAchat" id="coutEnergieAchat" class="form-control"/>', 'titre' => 'Coût en énergie (' . chiffrePetit($ressources['energie']) . ')']);
            item(['input' => submit(['form' => 'formAcheter', 'titre' => 'Acheter', 'image' => 'images/marche/achat.png'])]);
            ?>
        </form>
        <?php
        finListe();
        finCarte();

        debutCarte("Vendre");
        ?>
        <form action="marche.php?sub=0" method="post" name="formVendre">
            <?php echo csrfField(); ?>
            <?php
            debutListe();
            $options = "";
            foreach ($nomsRes as $num => $ressource) {
                $options = $options . '<option value="' . $ressource . '" data-option-color="' . $couleursSimples[$num] . '" data-option-image="images/petitesImages/' . $ressource . '.png">' . ucfirst($ressource) . ' (' . chiffrePetit($ressources[$ressource]) . ')</option>';
            }

            item(['floating' => false, 'select' => ["typeRessourceAVendre", $options, "javascript" => '', "hauteur" => 450], 'titre' => 'Atome']);
            item(['floating' => false, 'input' => '<input type="text" name="nombreRessourceAVendre" id="nombreRessourceAVendre" class="form-control"/>', 'titre' => 'Nombre']);
            item(['floating' => false, 'input' => '<input type="text" name="apportEnergieVente" id="apportEnergieVente" class="form-control"/>', 'titre' => 'Apport en énergie']);
            item(['input' => submit(['form' => 'formVendre', 'titre' => 'Vendre', 'image' => 'images/marche/vente.png'])]);

            ?>
        </form>
        <?php
        finListe();
        finCarte();
        ?>
        <script nonce="<?php echo htmlspecialchars(cspNonce(), ENT_QUOTES, 'UTF-8'); ?>">
            // script de calcul des ressources en temps reel (pour l'échange)
            function majAchat(param) {
                var typeRessourceAAcheter = document.getElementById('typeRessourceAAcheter').value;
                var nombreRessourceAAcheter = symboleEnNombre(document.getElementById('nombreRessourceAAcheter').value);
                var coutEnergie = symboleEnNombre(document.getElementById('coutEnergieAchat').value);
                var echange = <?php echo json_encode($tabCours);

                                foreach ($nomsRes as $num => $ressource) { // on récupére le numéro dans le tableau de ressources des ressources que l'on échange
                                    echo '
		if("' . $ressource . '" == typeRessourceAAcheter){
			var numAchat = ' . $num . ';
		}
        ';
                                } ?>

                if (nombreRessourceAAcheter == "") {
                    nombreRessourceAAcheter = 0;
                }
                if (coutEnergie == "") {
                    coutEnergie = 0;
                }

                if (param) {
                    coutEnergie = Math.round(nombreRessourceAAcheter * echange[numAchat]);
                    document.getElementById("coutEnergieAchat").value = coutEnergie;
                } else {
                    nombreRessourceAAcheter = Math.round(coutEnergie / echange[numAchat]);
                    document.getElementById("nombreRessourceAAcheter").value = nombreRessourceAAcheter;
                }
            }

            function majVente(param) {
                var typeRessourceAVendre = document.getElementById('typeRessourceAVendre').value;
                var nombreRessourceAVendre = symboleEnNombre(document.getElementById('nombreRessourceAVendre').value);
                var apportEnergie = symboleEnNombre(document.getElementById('apportEnergieVente').value);
                var sellTaxRate = <?php echo MARKET_SELL_TAX_RATE; ?>;
                var echange = <?php echo json_encode($tabCours);

                                foreach ($nomsRes as $num => $ressource) { // on récupére le numéro dans le tableau de ressources des ressources que l'on échange
                                    echo '
		if("' . $ressource . '" == typeRessourceAVendre){
			var numVente = ' . $num . ';
		}
        ';
                                } ?>

                if (nombreRessourceAVendre == "") {
                    nombreRessourceAVendre = 0;
                }
                if (apportEnergie == "") {
                    apportEnergie = 0;
                }

                if (param) {
                    apportEnergie = Math.round(nombreRessourceAVendre * echange[numVente] * sellTaxRate);
                    document.getElementById("apportEnergieVente").value = apportEnergie;
                } else {
                    nombreRessourceAVendre = Math.round(apportEnergie / (echange[numVente] * sellTaxRate));
                    document.getElementById("nombreRessourceAVendre").value = nombreRessourceAVendre;
                }
            }

            // Event listeners for buy form (replaces inline onChange)
            document.getElementById('typeRessourceAAcheter').addEventListener('change', function(){ majAchat(true); });
            document.getElementById('nombreRessourceAAcheter').addEventListener('change', function(){ majAchat(true); });
            document.getElementById('coutEnergieAchat').addEventListener('change', function(){ majAchat(false); });

            // Event listeners for sell form (replaces inline onChange)
            document.getElementById('typeRessourceAVendre').addEventListener('change', function(){ majVente(true); });
            document.getElementById('nombreRessourceAVendre').addEventListener('change', function(){ majVente(true); });
            document.getElementById('apportEnergieVente').addEventListener('change', function(){ majVente(false); });
        </script>

    <?php
}
    ?>

    <!-- SRI hash may break if Google updates loader.js; remove integrity attr if chart stops loading -->
    <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"
            integrity="sha384-Q4nTc23a1YNtnl17XDjJkYn/j5Ksb7rsGG1NTcIxbz6sTGfGXZJ8WdvzALeeuafr"
            crossorigin="anonymous"
            nonce="<?php echo htmlspecialchars(cspNonce(), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script nonce="<?php echo htmlspecialchars(cspNonce(), ENT_QUOTES, 'UTF-8'); ?>">
        // affichage des cours
        google.charts.load('current', {
            'packages': ['corechart']
        });
        google.charts.setOnLoadCallback(drawChart);

        function drawChart() {
            var data = google.visualization.arrayToDataTable([
                ['Temps',
                    <?php
                    foreach ($nomsRes as $num => $res) {
                        echo '"' . ucfirst($res) . '",';
                    } ?>
                ],
                <?php
                $tot = '';
                $coursRows = dbFetchAll($base, "SELECT * FROM cours ORDER BY timestamp DESC LIMIT " . MARKET_HISTORY_LIMIT);
                $c = 1;
                $nb = count($coursRows);
                foreach ($coursRows as $cours) {
                    if ($c != 1) {
                        $fin = ",";
                    } else {
                        $fin = "";
                    }
                    $tot =  '["' . date('d/m H\hi', $cours['timestamp']) . '",' . $cours['tableauCours'] . ']' . $fin . $tot;
                    $c++;
                }

                echo $tot;
                ?>
            ]);

            var options = {
                title: 'Evolution du coût en énergie des ressoures',
                backgroundColor: '#FAFAFA',
                curveType: 'function',
                colors: [<?php foreach ($couleurs as $num => $couleur) {
                                echo '"' . $couleur . '",';
                            } ?>],
                legend: {
                    position: 'bottom'
                }
            };

            var chart = new google.visualization.LineChart(document.getElementById('curve_chart'));

            chart.draw(data, options);
        }
    </script>


    <?php
    include("includes/copyright.php"); ?>