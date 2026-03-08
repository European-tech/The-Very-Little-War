<?php
/**
 * Game Actions Module
 * Processing of pending game actions (construction, formation, attacks, etc.)
 */

function updateActions($joueur)
{
    static $updating = [];
    if (isset($updating[$joueur])) {
        return; // Prevent infinite recursion
    }
    $updating[$joueur] = true;
    try {

    global $autre;
    global $points;
    global $constructions;
    global $nomsRes;
    global $base;
    global $nbRes;
    global $nbClasses;
    global $FORMATIONS;

    initPlayer($joueur);

    // Constructions
    $rows = dbFetchAll($base, 'SELECT * FROM actionsconstruction WHERE login=? AND fin<?', 'si', $joueur, time());
    foreach ($rows as $actions) {
        // CAS guard: DELETE first, only process if we claimed it (prevents double-processing)
        try {
            withTransaction($base, function() use ($base, $actions, $joueur) {
                $affected = dbExecute($base, 'DELETE FROM actionsconstruction WHERE id=?', 'i', $actions['id']);
                if ($affected === 0 || $affected === false) {
                    throw new \RuntimeException('already_processed');
                }
                augmenterBatiment($actions['batiment'], $joueur);
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'already_processed') continue;
            throw $e;
        }
    }

    // Formation

    $rows = dbFetchAll($base, 'SELECT * FROM actionsformation WHERE login=? AND debut<?', 'si', $joueur, time()); // toutes les formations qui sont en cours

    foreach ($rows as $actions) {
        $actionId = $actions['id'];
        withTransaction($base, function() use ($base, $actionId, $joueur) {
            // CAS guard: lock and re-read action to prevent double-processing
            $actions = dbFetchOne($base, 'SELECT * FROM actionsformation WHERE id=? FOR UPDATE', 'i', $actionId);
            if (!$actions) return; // Already processed by concurrent request

            $molecule = dbFetchOne($base, 'SELECT * FROM molecules WHERE id=?', 's', $actions['idclasse']);
            if (!$molecule) {
                // Molecule was deleted — remove orphaned formation action
                dbExecute($base, 'DELETE FROM actionsformation WHERE id=?', 'i', $actions['id']);
                return;
            }

            if ($actions['tempsPourUn'] <= 0) {
                logError("Formation tempsPourUn <= 0 for action " . $actions['id']);
                dbExecute($base, 'DELETE FROM actionsformation WHERE id=?', 'i', $actions['id']);
                return;
            }

            if ($actions['fin'] >= time()) {
                $derniereFormation = ($actions['nombreDebut'] - $actions['nombreRestant']) * $actions['tempsPourUn'] + $actions['debut'];
                $formed = (int)floor((time() - $derniereFormation) / $actions['tempsPourUn']);
                if ($actions['idclasse'] != 'neutrino') {
                    dbExecute($base, 'UPDATE molecules SET nombre = nombre + ? WHERE id=?', 'is', $formed, $actions['idclasse']);
                } else {
                    dbExecute($base, 'UPDATE autre SET neutrinos = neutrinos + ? WHERE login=?', 'is', $formed, $joueur);
                }
                dbExecute($base, 'UPDATE actionsformation SET nombreRestant = nombreRestant - ? WHERE id=?', 'ii', $formed, $actions['id']);
            } else {
                dbExecute($base, 'DELETE FROM actionsformation WHERE id=?', 'i', $actions['id']);
                if ($actions['idclasse'] != 'neutrino') {
                    dbExecute($base, 'UPDATE molecules SET nombre = nombre + ? WHERE id=?', 'is', (int)$actions['nombreRestant'], $actions['idclasse']);
                } else {
                    dbExecute($base, 'UPDATE autre SET neutrinos = neutrinos + ? WHERE login=?', 'is', (int)$actions['nombreRestant'], $joueur);
                }
            }
        });
    }

    // Attaques

    $rows = dbFetchAll($base, 'SELECT * FROM actionsattaques WHERE attaquant=? OR defenseur=? ORDER BY tempsAttaque DESC', 'ss', $joueur, $joueur);

    foreach ($rows as $actions) {
        if ($actions['attaqueFaite'] == 0 && $actions['tempsAttaque'] < time()) { // on fait l'attaque
            if ($actions['troupes'] != 'Espionnage') {
                if ($actions['attaquant'] == $joueur) {
                    $enFace = $actions['defenseur'];
                    updateRessources($actions['defenseur']);
                    updateActions($actions['defenseur']);
                } else {
                    $enFace = $actions['attaquant'];
                    updateRessources($actions['attaquant']);
                    updateActions($actions['attaquant']);
                }

                $nbsecondes = $actions['tempsAttaque'] - $actions['tempsAller'];
                $molecules = explode(";", $actions['troupes']);

                // Variables populated inside withTransaction closure, used after for multi-account check
                $combatAttaquant = $actions['attaquant'];
                $combatDefenseur = $actions['defenseur'];
                $combatTemps = $actions['tempsAttaque'];

                try {
                    withTransaction($base, function() use (
                        $base, &$actions, $molecules, $nbsecondes, $nomsRes, $nbClasses, $FORMATIONS
                    ) {
                        // CAS guard INSIDE transaction: prevents data loss on rollback (P2-D7-002)
                        $casAffected = dbExecute($base, 'UPDATE actionsattaques SET attaqueFaite=1 WHERE id=? AND attaqueFaite=0', 'i', $actions['id']);
                        if ($casAffected === 0 || $casAffected === false) {
                            // Another concurrent request already resolved this combat — skip it
                            throw new \RuntimeException('cas_skip');
                        }

                        // Re-validate alliance membership at resolution time (FLOW-CMB-P10-001)
                        // Prevents friendly-fire if attacker/defender joined the same alliance after attack launch
                        $attAllianceId = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $actions['attaquant']);
                        $defAllianceId = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $actions['defenseur']);
                        if ($attAllianceId && $defAllianceId
                            && (int)$attAllianceId['idalliance'] > 0
                            && (int)$attAllianceId['idalliance'] === (int)$defAllianceId['idalliance']) {
                            // Both now in same alliance — abort attack, refund molecules, delete action
                            $troupesArr = explode(';', $actions['troupes'] ?? '');
                            $moleculesOwned = dbFetchAll($base, 'SELECT id, classe FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC', 's', $actions['attaquant']);
                            foreach ($moleculesOwned as $idx => $mol) {
                                $nb = isset($troupesArr[$idx]) && is_numeric($troupesArr[$idx]) ? (int)$troupesArr[$idx] : 0;
                                if ($nb > 0) {
                                    dbExecute($base, 'UPDATE molecules SET nombre = nombre + ? WHERE id=?', 'ii', $nb, $mol['id']);
                                }
                            }
                            dbExecute($base, 'DELETE FROM actionsattaques WHERE id=?', 'i', $actions['id']);
                            logInfo('SECURITY', 'Friendly fire aborted at resolution', ['attacker' => $actions['attaquant'], 'defender' => $actions['defenseur']]);
                            throw new \RuntimeException('cas_skip'); // reuse cas_skip to cleanly exit tx
                        }

                        // Decay loop now inside the transaction
                        $moleculesRows = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC', 's', $actions['attaquant']);

                        $compteur = 1;
                        $chaine = '';
                        $totalMoleculesPerdues = 0;
                        foreach ($moleculesRows as $moleculesProp) {
                            if (!isset($molecules[$compteur - 1])) {
                                logError("Malformed troupes string for action " . $actions['id']);
                                break;
                            }
                            // M-018: Guard against empty/non-numeric segments in the troupes string
                            $rawMolVal = $molecules[$compteur - 1];
                            if (!is_numeric($rawMolVal)) {
                                logError('GAME_ACTIONS', 'Non-numeric troupes segment in decay loop', ['val' => $rawMolVal, 'action_id' => $actions['id'], 'class' => $compteur]);
                                $rawMolVal = 0;
                            }
                            $moleculesRestantes = (pow(coefDisparition($actions['attaquant'], $compteur), $nbsecondes) * $rawMolVal);
                            $chaine = $chaine . $moleculesRestantes . ';';
                            $totalMoleculesPerdues += ($rawMolVal - $moleculesRestantes);
                            $compteur++;
                        }
                        // Batch update moleculesPerdues in one atomic statement
                        if ($totalMoleculesPerdues > 0) {
                            dbExecute($base, 'UPDATE autre SET moleculesPerdues = moleculesPerdues + ? WHERE login=?', 'is', (int) round($totalMoleculesPerdues), $actions['attaquant']);
                        }

                        $actions['troupes'] = $chaine;
                        include("includes/combat.php"); // Les pertes sont calculées, le gagnant est désigné et les troupes sont mises à jour dans la BD, les ressources sont pillées

                        // Map combat.php output variables to report template variables
                        $attaquePts = number_format($pointsAttaquant, 0, ' ', ' ');
                        $defensePts = number_format($pointsDefenseur, 0, ' ', ' ');
                        $pillagePts = number_format($totalPille, 0, ' ', ' ');
                        $pillagePts1 = $pillagePts;

                        if ($gagnant == 2) {
                            $titreRapportJoueur = "Vous gagnez contre " . htmlspecialchars($actions['defenseur'], ENT_QUOTES, 'UTF-8') . " !";
                            $titreRapportDefenseur = "Vous perdez contre " . htmlspecialchars($actions['attaquant'], ENT_QUOTES, 'UTF-8') . " !";
                        } elseif ($gagnant == 1) {
                            $titreRapportJoueur = "Vous perdez contre " . htmlspecialchars($actions['defenseur'], ENT_QUOTES, 'UTF-8') . " !";
                            $titreRapportDefenseur = "Vous gagnez contre " . htmlspecialchars($actions['attaquant'], ENT_QUOTES, 'UTF-8') . " !";
                        } else {
                            $titreRapportJoueur = "Egalité contre " . htmlspecialchars($actions['defenseur'], ENT_QUOTES, 'UTF-8') . " !";
                            $titreRapportDefenseur = "Egalité contre " . htmlspecialchars($actions['attaquant'], ENT_QUOTES, 'UTF-8') . " !";
                        }

                        $chaine = "Aucune";
                        foreach ($nomsRes as $num => $ressource) {
                            $pilleAmount = $ressourcePille[$ressource] ?? 0;
                            if ($pilleAmount > 0) {
                                if ($chaine == "Aucune") {
                                    $chaine = nombreAtome($num, $pilleAmount);
                                } else {
                                    $chaine = $chaine . nombreAtome($num, $pilleAmount);
                                }
                            }
                        }



                        // verifier si on a envoyé des molécules de cette classe

                        for ($i = 1; $i <= $nbClasses; $i++) {
                            if ($classeAttaquant[$i]['nombre'] == 0) {
                                $classeAttaquant[$i]['formuleAfficher'] = "?";
                            } else {
                                // H-004: escape raw DB formula before passing to couleurFormule()
                                $classeAttaquant[$i]['formuleAfficher'] = couleurFormule(htmlspecialchars($classeAttaquant[$i]['formule'], ENT_QUOTES, 'UTF-8'));
                            }
                        }

                        $information = "";
                        if ($attaquantsRestants == 0) {
                            $information = "<strong>Aucune mol&eacute;cule n'est revenue !</strong><br/><br/>";
                        }

                        $debutRapport = "<p>
                                    <div class=\"table-responsive\">
                                    " . important('Attaquant') . "<br/>
                                    " . chipInfo($attaquePts, 'images/molecule/sword.png') . chipInfo($pillagePts, 'images/molecule/bag.png') . "<br/><br/>
                                    <table class=\"table table-bordered\">
                                    <caption style=\"color:red;font-weight:bold;\"><img src=\"images/attaquer/gladius.png\" alt=\"epee\" class=\"imageAide\"/><a style=\"color:red\" href=\"joueur.php?id=" . htmlspecialchars($actions['attaquant'], ENT_QUOTES, 'UTF-8') . "\">" . htmlspecialchars($actions['attaquant'], ENT_QUOTES, 'UTF-8') . "</caption>
                                    <thead>
                                    <tr>
                                    <th></th>
                                    " . (function() use ($classeAttaquant, $nbClasses) {
                                        $h = '';
                                        for ($i = 1; $i <= $nbClasses; $i++) {
                                            $h .= "<th>" . $classeAttaquant[$i]['formuleAfficher'] . "</th>\n";
                                        }
                                        return $h;
                                    })() . "
                                    </tr>
                                    </thead>

                                    <tbody>
                                    <tr>
                                    <th>Troupes</th>
                                    " . (function() use ($classeAttaquant, $nbClasses) {
                                        $h = '';
                                        for ($i = 1; $i <= $nbClasses; $i++) {
                                            $h .= "<td>" . number_format($classeAttaquant[$i]['nombre'], 0, ' ', ' ') . "</td>\n";
                                        }
                                        return $h;
                                    })() . "
                                    </tr>

                                    <tr>
                                    <th>Pertes</th>
                                    " . (function() use ($attaquantMort, $nbClasses) {
                                        $h = '';
                                        for ($i = 1; $i <= $nbClasses; $i++) {
                                            $h .= "<td>" . number_format($attaquantMort[$i] ?? 0, 0, ' ', ' ') . "</td>\n";
                                        }
                                        return $h;
                                    })() . "
                                    </tr>
                                    </tbody>
                                    </table></div><br/><br/>

                                    $information
                                    <br/><br/>
                                    " . important('Défenseur') . "<br/>
                                    " . chipInfo($defensePts, 'images/molecule/shield.png') . chipInfo($pillagePts1, 'images/molecule/bag.png') . "<br/><br/>
                                    <div class=\"table-responsive\">
                                    <table class=\"table table-bordered\">
                                    <caption style=\"color:green;font-weight:bold;\"><img src=\"images/attaquer/shield.png\" alt=\"bouclier\" class=\"imageAide\"/><a style=\"color:green\" href=\"joueur.php?id=" . htmlspecialchars($actions['defenseur'], ENT_QUOTES, 'UTF-8') . "\">" . htmlspecialchars($actions['defenseur'], ENT_QUOTES, 'UTF-8') . "</a></caption>

                                    <thead>
                                    <tr>
                                    <th></th>";

                        for ($i = 1; $i <= $nbClasses; $i++) {
                            $classeDefenseur[$i]['nombre'] = separerZeros($classeDefenseur[$i]['nombre']);
                            $defenseurMort[$i] = separerZeros($defenseurMort[$i] ?? 0);
                        }

                        $milieuDefenseur = (function() use ($classeDefenseur, $defenseurMort, $nbClasses) {
                            $h = '';
                            for ($i = 1; $i <= $nbClasses; $i++) {
                                // H-004: escape raw DB formula before passing to couleurFormule()
                                $h .= "<th>" . couleurFormule(htmlspecialchars($classeDefenseur[$i]['formule'], ENT_QUOTES, 'UTF-8')) . "</th>\n";
                            }
                            $h .= "</tr></thead><tbody><tr><th>Troupes</th>\n";
                            for ($i = 1; $i <= $nbClasses; $i++) {
                                $h .= "<td>" . $classeDefenseur[$i]['nombre'] . "</td>\n";
                            }
                            $h .= "</tr><tr><th>Pertes</th>\n";
                            for ($i = 1; $i <= $nbClasses; $i++) {
                                $h .= "<td>" . $defenseurMort[$i] . "</td>\n";
                            }
                            $h .= "</tr>";
                            return $h;
                        })();

                        if ($attaquantsRestants == 0) { // si aucune molécule n'est revenue alors on a aucune information sur les troupes en face
                            for ($i = 1; $i <= $nbClasses; $i++) {
                                $classeDefenseur[$i]['formule'] = "?";
                                $classeDefenseur[$i]['nombre'] = "?";
                                $defenseurMort[$i] = "?";
                            }

                            dbExecute($base, 'DELETE FROM actionsattaques WHERE id=?', 'i', $actions['id']); // pas de retour si ils sont morts

                        }


                        $milieuAttaquant = (function() use ($classeDefenseur, $defenseurMort, $nbClasses) {
                            $h = '';
                            for ($i = 1; $i <= $nbClasses; $i++) {
                                // H-004: escape raw DB formula before passing to couleurFormule()
                                $h .= "<th>" . couleurFormule(htmlspecialchars($classeDefenseur[$i]['formule'], ENT_QUOTES, 'UTF-8')) . "</th>\n";
                            }
                            $h .= "</tr></thead><tbody><tr><th>Troupes</th>\n";
                            for ($i = 1; $i <= $nbClasses; $i++) {
                                $h .= "<td>" . $classeDefenseur[$i]['nombre'] . "</td>\n";
                            }
                            $h .= "</tr><tr><th>Pertes</th>\n";
                            for ($i = 1; $i <= $nbClasses; $i++) {
                                $h .= "<td>" . $defenseurMort[$i] . "</td>\n";
                            }
                            $h .= "</tr>";
                            return $h;
                        })();


                        // Reactions feature removed — placeholder for future implementation
                        $reactionsHtml = '';

                        $finRapport = "
                                    </tbody>
                                    </table></div><br/><br/>

                                    " . $reactionsHtml . "

                                    " . important('Ressources pillées') . "
                                    " . $chaine . "
                                    <br/><br/>

                                    " . important('Bâtiments endommagés') . "

                                    <strong>Générateur : </strong>" . number_format($degatsGenEnergie, 0, ' ', ' ') . " (" . $destructionGenEnergie . ")<br/>
                                    <strong>Champ de force : </strong>" . number_format($degatschampdeforce, 0, ' ', ' ') . " (" . $destructionchampdeforce . ")<br/>
                                    <strong>Producteur : </strong>" . number_format($degatsProducteur, 0, ' ', ' ') . " (" . $destructionProducteur . ")<br/>
                                    <strong>Stockage: </strong>" . number_format($degatsDepot, 0, ' ', ' ') . " (" . $destructionDepot . ")<br/>
                                    <strong>Ionisateur : </strong>" . number_format($degatsIonisateur, 0, ' ', ' ') . " (" . $destructionIonisateur . ")<br/>
                                    </p>
                                    ";

                        $contenuRapportAttaquant = $debutRapport . $milieuAttaquant . $finRapport;
                        $contenuRapportDefenseur = $debutRapport . $milieuDefenseur . $finRapport;

                        // Les rapports sont créés
                        $rapportImage = '<img alt="attack" src="images/rapports/sword.png"/ class="imageAide">';
                        dbExecute($base, 'INSERT INTO rapports VALUES(default, ?, ?, ?, ?, default, ?)', 'issss', $actions['tempsAttaque'], $titreRapportJoueur, $contenuRapportAttaquant, $actions['attaquant'], $rapportImage);

                        dbExecute($base, 'INSERT INTO rapports VALUES(default, ?, ?, ?, ?, default, ?)', 'issss', $actions['tempsAttaque'], $titreRapportDefenseur, $contenuRapportDefenseur, $actions['defenseur'], $rapportImage);
                        // withTransaction handles commit automatically
                    });

                // Multi-account: check for coordinated attacks from same-IP accounts (read-only, outside tx)
                require_once(__DIR__ . '/multiaccount.php');
                checkCoordinatedAttacks($base, $combatAttaquant, $combatDefenseur, $combatTemps);

                } catch (\RuntimeException $e) {
                    if ($e->getMessage() === 'cas_skip') {
                        continue; // valid — we are in the outer foreach, not a closure
                    }
                    error_log('Combat transaction rolled back for action ' . ($actions['id'] ?? 'unknown') . ': ' . $e->getMessage());
                } catch (\Throwable $combatException) {
                    error_log('Combat transaction rolled back for action ' . ($actions['id'] ?? 'unknown') . ': ' . $combatException->getMessage());
                }
            } else {
                // P9-MED-008: Outer transaction wraps the entire espionage resolution block.
                // The CAS guard (first statement) prevents double-processing in concurrent requests.
                $espActionId = $actions['id'];
                $espActions  = $actions; // capture by value for closure
                withTransaction($base, function() use (
                    $base, $espActionId, $espActions, $nomsRes, $nbClasses, $FORMATIONS
                ) {
                    // CAS guard: mark attaqueFaite=1 only if not already processed.
                    // dbExecute returns affected_rows: 0 means another request beat us.
                    $cas = dbExecute($base, 'UPDATE actionsattaques SET attaqueFaite=1 WHERE id=? AND attaqueFaite=0', 'i', $espActionId);
                    if ($cas === false || $cas === 0) {
                        // Already resolved by a concurrent request — skip silently.
                        return;
                    }

                    $nDef = dbFetchOne($base, 'SELECT neutrinos FROM autre WHERE login=?', 's', $espActions['defenseur']);
                    if (!$nDef) {
                        // Defender was deleted — cancel espionage, action already marked done.
                        dbExecute($base, 'DELETE FROM actionsattaques WHERE id=?', 'i', $espActionId);
                        return;
                    }
                    // Radar research reduces the neutrino threshold for successful espionage
                    $radarDiscount      = 1 - allianceResearchBonus($espActions['attaquant'], 'espionage_cost');
                    $espionageThreshold = ($nDef['neutrinos'] * ESPIONAGE_SUCCESS_RATIO) * $radarDiscount;

                    if ($espionageThreshold < $espActions['nombreneutrinos']) {
                        // COMB-001: Use a plain array instead of variable-variables ($classe1..$classeN)
                        // to avoid dynamic variable injection risk and improve readability.
                        $espClasses = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC', 's', $espActions['defenseur']);

                        $ressourcesJoueur    = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?',     's', $espActions['defenseur']);
                        $constructionsJoueur = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?',  's', $espActions['defenseur']);

                        // P9-MED-009: NULL-dereference guard — target may have been deleted
                        // between the CAS guard and these reads (e.g. account deletion race).
                        if (!$ressourcesJoueur || !$constructionsJoueur) {
                            $titreRapportJoueur   = "Espionnage — cible supprimée";
                            $contenuRapportJoueur = "<p>La cible a été supprimée avant que le rapport puisse être généré.</p>";
                        } else {
                            $titreRapportJoueur = "Vous espionnez " . htmlspecialchars($espActions['defenseur'], ENT_QUOTES, 'UTF-8');
                            $chaine1 = "";
                            foreach ($nomsRes as $num => $ressource) {
                                $chaine1 = $chaine1 . nombreAtome($num, number_format($ressourcesJoueur[$ressource], 0, ' ', ' '));
                            }

                            // Build army section dynamically from the $espClasses array
                            // SPY-P9-009: couleurFormule() does not call htmlspecialchars() internally,
                            // so we escape the formula string first to prevent latent XSS in spy reports.
                            $armeeHtml = '';
                            foreach ($espClasses as $espClass) {
                                $safeFormule = htmlspecialchars($espClass['formule'], ENT_QUOTES, 'UTF-8');
                                $armeeHtml .= "<strong>" . couleurFormule($safeFormule) . " : </strong>" . nombreMolecules(number_format($espClass['nombre'], 0, ' ', ' ')) . "<br/>\n";
                            }

                            $contenuRapportJoueur = "
                    <p>" . important('Armée') . "
                    " . $armeeHtml . "<br/>
                    <br/><br/>
                    " . important('Ressources') . "
                    " . nombreEnergie(number_format($ressourcesJoueur['energie'], 0, ' ', ' ')) . "
                    " . $chaine1 . "
                    <br/><br/>
                    " . important('Bâtiments') . "
                    <div class=\"table-responsive\">
                    <table class=\"table table-striped table-bordered\">
                    <thead>
                    <tr>
                    <th>Batiment</th>
                    <th>Niveau</th>
                    <th>Vie</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                    <td>Générateur</td>
                    <td>" . $constructionsJoueur['generateur'] . "</td>
                    <td>
                    " . $constructionsJoueur['vieGenerateur'] . "/" . pointsDeVie($constructionsJoueur['generateur']) . "</div>
                    </div></td>
                    </tr>
                    <tr>
                    <td>Producteur</td>
                    <td>" . $constructionsJoueur['producteur'] . "</td>
                    <td>
                    " . $constructionsJoueur['vieProducteur'] . "/" . pointsDeVie($constructionsJoueur['producteur']) . "</td>
                    </tr>
                    <tr>
                    <td>Stockage</td>
                    <td>" . $constructionsJoueur['depot'] . "</td>
                    <td>
                    " . $constructionsJoueur['vieDepot'] . "/" . pointsDeVie($constructionsJoueur['depot']) . "</td>
                    </tr>
                    <tr>
                    <td>Champ de force</td>
                    <td>" . $constructionsJoueur['champdeforce'] . "</td>
                    <td>
                    " . $constructionsJoueur['vieChampdeforce'] . "/" . vieChampDeForce($constructionsJoueur['champdeforce']) . "</td>
                    </tr>
                    <tr>
                    <td>Ionisateur</td>
                    <td>" . $constructionsJoueur['ionisateur'] . "</td>
                    <td>
                    " . $constructionsJoueur['vieIonisateur'] . "/" . vieIonisateur($constructionsJoueur['ionisateur']) . "</td>
                    </tr>
                    <tr>
                    <td>Condenseur</td>
                    <td>" . $constructionsJoueur['condenseur'] . "</td>
                    <td>Pas de vie</td>
                    </tr>
                    <tr>
                    <td>Lieur</td>
                    <td>" . $constructionsJoueur['lieur'] . "</td>
                    <td>Pas de vie</td>
                    </tr>
                    <tr>
                    <td>Stabilisateur</td>
                    <td>" . $constructionsJoueur['stabilisateur'] . "</td>
                    <td>Pas de vie</td>
                    </tr>
                    <tr>
                    <td>Coffre-fort</td>
                    <td>" . ($constructionsJoueur['coffrefort'] ?? 0) . "</td>
                    <td>Pas de vie</td>
                    </tr>
                    </tbody>
                    </table>
                    </div>
                    <br/>" . important('Formation défensive') . "
                    <strong>" . htmlspecialchars($FORMATIONS[$constructionsJoueur['formation'] ?? 0]['name'] ?? 'Dispersée') . "</strong>
                    </p>";
                        } // end null-check else
                    } else {
                        $titreRapportJoueur   = "Espionnage raté";
                        $contenuRapportJoueur = "<p>Votre espionnage a raté, vous avez envoyé moins de la moitié des neutrinos de votre adversaire.</p>";
                    }

                    // Inner transaction for atomic report write + action deletion (MED-068).
                    // Still useful: the outer transaction covers the CAS + reads, the inner
                    // groups the two INSERT/DELETE writes into a single atomic unit within it.
                    withTransaction($base, function() use ($base, $espActionId, $espActions, $titreRapportJoueur, $contenuRapportJoueur, $espionageThreshold) {
                        dbExecute($base, 'INSERT INTO rapports (timestamp, titre, contenu, destinataire, type) VALUES(?, ?, ?, ?, ?)', 'issss', $espActions['tempsAttaque'], $titreRapportJoueur, $contenuRapportJoueur, $espActions['attaquant'], 'espionage');

                        // ESPIONAGE-HIGH-001: Notify defender unconditionally on espionage success.
                        // Previous condition was inverted (>= instead of <), making this dead code.
                        // We are already inside the success branch (threshold < nombreneutrinos),
                        // so the defender is always notified when espionage succeeds.
                        $titreRapportEspionDef   = 'Tentative d\'espionnage détectée';
                        $contenuRapportEspionDef = '<p>Un agent inconnu a espionné votre base. Vos défenses, ressources et compositions moléculaires ont été observées.</p>';
                        dbExecute($base, 'INSERT INTO rapports (timestamp, titre, contenu, destinataire, type) VALUES(?, ?, ?, ?, ?)', 'issss', $espActions['tempsAttaque'], $titreRapportEspionDef, $contenuRapportEspionDef, $espActions['defenseur'], 'defense');

                        dbExecute($base, 'DELETE FROM actionsattaques WHERE id=?', 'i', $espActionId);
                    });
                }); // end outer espionage transaction
            }
        }

        if ($actions['tempsRetour'] < time() && $actions['attaqueFaite'] == 1 && $joueur == $actions['attaquant'] && $actions['troupes'] != 'Espionnage') { // dans ce cas là on remet à jour les troupes

            $nbsecondes = $actions['tempsRetour'] - $actions['tempsAttaque'];
            $molecules = explode(";", $actions['troupes']);

            // Validate troupes array length
            if (count($molecules) < $nbClasses) {
                logError("Return trip: malformed troupes string for action " . $actions['id'] . ": " . $actions['troupes']);
                dbExecute($base, 'DELETE FROM actionsattaques WHERE id=?', 'i', $actions['id']);
            } else {
                $actionId = $actions['id'];
                withTransaction($base, function() use ($base, $actionId, $joueur, $nbsecondes, $molecules) {
                    // CAS guard: lock action row, verify it still exists
                    $actionRow = dbFetchOne($base, 'SELECT id FROM actionsattaques WHERE id=? FOR UPDATE', 'i', $actionId);
                    if (!$actionRow) return; // Already processed by concurrent request

                    $moleculesRows = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC FOR UPDATE', 's', $joueur);

                    $compteur = 1;
                    $totalMoleculesPerdues = 0;

                    foreach ($moleculesRows as $moleculesProp) {
                        if (!isset($molecules[$compteur - 1])) break;
                        $moleculesRestantes = (pow(coefDisparition($joueur, $compteur), $nbsecondes) * $molecules[$compteur - 1]);

                        dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di', ($moleculesProp['nombre'] + $moleculesRestantes), $moleculesProp['id']);

                        $totalMoleculesPerdues += ($molecules[$compteur - 1] - $moleculesRestantes);
                        $compteur++;
                    }

                    // Batch update moleculesPerdues atomically
                    if ($totalMoleculesPerdues > 0) {
                        dbExecute($base, 'UPDATE autre SET moleculesPerdues = moleculesPerdues + ? WHERE login=?', 'is', (int) round($totalMoleculesPerdues), $joueur);
                    }

                    dbExecute($base, 'DELETE FROM actionsattaques WHERE id=?', 'i', $actionId);
                });
            }
        }
    }

    // MED-049: UNION ALL avoids OR-condition that prevents index use on receveur/envoyeur.
    // Both columns have separate indexes; UNION ALL lets each branch hit its index.
    // Deduplicate by id in case a row ever matches both (receveur==envoyeur, edge case).
    $now = time();
    $rowsA = dbFetchAll($base, 'SELECT * FROM actionsenvoi WHERE receveur=? AND tempsArrivee<?', 'si', $joueur, $now);
    $rowsB = dbFetchAll($base, 'SELECT * FROM actionsenvoi WHERE envoyeur=? AND tempsArrivee<?', 'si', $joueur, $now);
    $seenIds = [];
    $rows = [];
    foreach (array_merge($rowsA, $rowsB) as $r) {
        if (!isset($seenIds[$r['id']])) {
            $seenIds[$r['id']] = true;
            $rows[] = $r;
        }
    }

    foreach ($rows as $actions) {
        $actionId = $actions['id'];
        $envoyees = explode(";", $actions['ressourcesEnvoyees']);
        $recues = explode(";", $actions['ressourcesRecues']);

        $chaine1 = "";
        foreach ($nomsRes as $num => $ressource) {
            if ($envoyees[$num] > 0) {
                $chaine1 = $chaine1 . nombreAtome($num, number_format($envoyees[$num], 0, ' ', ' '));
            }
        }

        $chaine2 = "";
        foreach ($nomsRes as $num => $ressource) {
            if ($recues[$num] > 0) {
                $chaine2 = $chaine2 . nombreAtome($num, number_format($recues[$num], 0, ' ', ' '));
            }
        }

        $energieEnvoyee = "";
        if ($envoyees[count($nomsRes)] > 0) {
            $energieEnvoyee = nombreEnergie(number_format($envoyees[count($nomsRes)], 0, ' ', ' '));
        }

        $energieRecue = "";
        if ($recues[count($nomsRes)] > 0) {
            $energieRecue = nombreEnergie(number_format($recues[count($nomsRes)], 0, ' ', ' '));
        }

        $titreRapport = "Rapport d'apport de ressources par " . htmlspecialchars($actions['envoyeur'], ENT_QUOTES, 'UTF-8');
        $contenuRapport = "<a href=\"joueur.php?id=" . htmlspecialchars($actions['envoyeur'], ENT_QUOTES, 'UTF-8') . "\">" . htmlspecialchars($actions['envoyeur'], ENT_QUOTES, 'UTF-8') . "</a> vous envoie les ressources suivantes : <br/><br/>
        " . important('Ressources envoyées') . "
        " . $energieEnvoyee . $chaine1 . "<br/><br/>
        " . important('Ressources reçues') . "
        " . $energieRecue . $chaine2;

        withTransaction($base, function() use ($base, $actionId, $actions, $nomsRes, $nbRes, $recues, $titreRapport, $contenuRapport) {
            // HIGH-006: Lock the actionsenvoi row first to prevent double-delivery race condition
            $transfer = dbFetchOne($base, 'SELECT id FROM actionsenvoi WHERE id=? FOR UPDATE', 'i', $actionId);
            if (!$transfer) {
                // Already delivered by a concurrent request — abort cleanly
                return;
            }

            $ressourcesDestinataire = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=? FOR UPDATE', 's', $actions['receveur']);
            if (!$ressourcesDestinataire) {
                // Recipient was deleted — just remove the transfer
                dbExecute($base, 'DELETE FROM actionsenvoi WHERE id=?', 'i', $actionId);
                return;
            }
            // FIX FINDING-GAME-009: Cap received resources at storage limit
            $depotReceveur = dbFetchOne($base, 'SELECT depot FROM constructions WHERE login=?', 's', $actions['receveur']);
            $maxStorageRecv = placeDepot($depotReceveur['depot']);

            $recues[count($nomsRes)] = max(0, $recues[count($nomsRes)]);
            // Build parameterized update for envoi resources
            $envoiSetClauses = ['energie=?'];
            $envoiTypes = 'd';
            $envoiParams = [min($maxStorageRecv, round($ressourcesDestinataire['energie'] + $recues[count($nomsRes)]))];
            foreach ($nomsRes as $num => $ressource) {
                $envoiSetClauses[] = "$ressource=?";
                $envoiTypes .= 'd';
                $recues[$num] = max(0, $recues[$num]);
                $envoiParams[] = min($maxStorageRecv, round($ressourcesDestinataire[$ressource] + $recues[$num]));
            }
            $envoiParams[] = $actions['receveur'];
            $envoiTypes .= 's';
            dbExecute($base, 'UPDATE ressources SET ' . implode(',', $envoiSetClauses) . ' WHERE login=?', $envoiTypes, ...$envoiParams);

            dbExecute($base, 'INSERT INTO rapports VALUES(default, ?, ?, ?, ?, default, ?)', 'issss', time(), $titreRapport, $contenuRapport, $actions['receveur'], '<img alt="fleche" src="images/rapports/retour.png" class="imageAide">');

            dbExecute($base, 'DELETE FROM actionsenvoi WHERE id=?', 'i', $actionId);
        });

        // P9-LOW-019: Check for suspicious transfer patterns (outside tx — read-only detection)
        if (function_exists('checkTransferPatterns')) {
            checkTransferPatterns($base, $actions['envoyeur'], $actions['receveur'], time());
        }
    }

    initPlayer($joueur);

    } finally {
        unset($updating[$joueur]);
    }
}
