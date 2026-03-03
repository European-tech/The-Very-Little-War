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

    global $autre;
    global $points;
    global $constructions;
    global $nomsRes;
    global $base;
    global $nbRes;
    global $nbClasses;

    initPlayer($joueur);

    // Constructions
    $ex = dbQuery($base, 'SELECT * FROM actionsconstruction WHERE login=? AND fin<?', 'si', $joueur, time());
    while ($actions = mysqli_fetch_array($ex)) {
        augmenterBatiment($actions['batiment'], $joueur);

        dbExecute($base, 'DELETE FROM actionsconstruction WHERE id=?', 'i', $actions['id']);
    }

    // Formation

    $ex = dbQuery($base, 'SELECT * FROM actionsformation WHERE login=? AND debut<?', 'si', $joueur, time()); // toutes les formations qui sont en cours

    //neutrinos
    $neutrinos = dbFetchOne($base, 'SELECT neutrinos FROM autre WHERE login=?', 's', $joueur);

    while ($actions = mysqli_fetch_array($ex)) {
        $molecule = dbFetchOne($base, 'SELECT * FROM molecules WHERE id=?', 's', $actions['idclasse']);

        if ($actions['tempsPourUn'] <= 0) {
            logError("Formation tempsPourUn <= 0 for action " . $actions['id']);
            dbExecute($base, 'DELETE FROM actionsformation WHERE id=?', 'i', $actions['id']);
            continue;
        }

        if ($actions['fin'] >= time()) {
            $derniereFormation = ($actions['nombreDebut'] - $actions['nombreRestant']) * $actions['tempsPourUn'] + $actions['debut'];
            $formed = floor((time() - $derniereFormation) / $actions['tempsPourUn']);
            if ($actions['idclasse'] != 'neutrino') {
                dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'ds', ($molecule['nombre'] + $formed), $actions['idclasse']);
            } else {
                dbExecute($base, 'UPDATE autre SET neutrinos=? WHERE login=?', 'ds', ($neutrinos['neutrinos'] + $formed), $joueur);
            }
            dbExecute($base, 'UPDATE actionsformation SET nombreRestant=? WHERE id=?', 'di', ($actions['nombreRestant'] - $formed), $actions['id']);
        } else {
            dbExecute($base, 'DELETE FROM actionsformation WHERE id=?', 'i', $actions['id']);
            if ($actions['idclasse'] != 'neutrino') {
                dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'ds', ($molecule['nombre'] + $actions['nombreRestant']), $actions['idclasse']);
            } else {
                dbExecute($base, 'UPDATE autre SET neutrinos=? WHERE login=?', 'ds', ($neutrinos['neutrinos'] + $actions['nombreRestant']), $joueur);
                //$autre['neutrinos'] = ($neutrinos['neutrinos'] + $actions['nombreRestant']);
            }
        }
    }

    // Attaques

    $ex = dbQuery($base, 'SELECT * FROM actionsattaques WHERE attaquant=? OR defenseur=? ORDER BY tempsAttaque DESC', 'ss', $joueur, $joueur);

    while ($actions = mysqli_fetch_array($ex)) {
        if ($actions['attaqueFaite'] == 0 && $actions['tempsAttaque'] < time()) { // on fait l'attaque
            if ($actions['troupes'] != 'Espionnage') {
                // CAS guard: only proceed if this request is the first to claim the action
                $casAffected = dbExecute($base, 'UPDATE actionsattaques SET attaqueFaite=1 WHERE id=? AND attaqueFaite=0', 'i', $actions['id']);
                if ($casAffected === 0 || $casAffected === false) {
                    // Another concurrent request already resolved this combat — skip it
                    continue;
                }

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

                mysqli_begin_transaction($base);
                try {

                // Decay loop now inside the transaction
                $ex3 = dbQuery($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC', 's', $actions['attaquant']);

                $compteur = 1;
                $chaine = '';
                $totalMoleculesPerdues = 0;
                while ($moleculesProp = mysqli_fetch_array($ex3)) {
                    if (!isset($molecules[$compteur - 1])) {
                        logError("Malformed troupes string for action " . $actions['id']);
                        break;
                    }
                    $moleculesRestantes = (pow(coefDisparition($actions['attaquant'], $compteur), $nbsecondes) * $molecules[$compteur - 1]);
                    $chaine = $chaine . $moleculesRestantes . ';';
                    $totalMoleculesPerdues += ($molecules[$compteur - 1] - $moleculesRestantes);
                    $compteur++;
                }
                // Batch update moleculesPerdues in one atomic statement
                if ($totalMoleculesPerdues > 0) {
                    dbExecute($base, 'UPDATE autre SET moleculesPerdues = moleculesPerdues + ? WHERE login=?', 'ds', $totalMoleculesPerdues, $actions['attaquant']);
                }

                $actions['troupes'] = $chaine;
                include("includes/combat.php"); // Les pertes sont calculées, le gagnant est désigné et les troupes sont mises à jour dans la BD, les ressources sont pillées

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
                    if (${$ressource . 'Pille'} > 0) {
                        if ($chaine == "Aucune") {
                            $chaine = nombreAtome($num, ${$ressource . 'Pille'});
                        } else {
                            $chaine = $chaine . nombreAtome($num, ${$ressource . 'Pille'});
                        }
                    }
                }



                // verifier si on a envoyé des molécules de cette classe

                for ($i = 1; $i <= $nbClasses; $i++) {
                    if (${'classeAttaquant' . $i}['nombre'] == 0) {
                        ${'classeAttaquant' . $i}['formuleAfficher'] = "?";
                    } else {
                        ${'classeAttaquant' . $i}['formuleAfficher'] = couleurFormule(${'classeAttaquant' . $i}['formule']);
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
                            <th>" . $classeAttaquant1['formuleAfficher'] . "</th>
                            <th>" . $classeAttaquant2['formuleAfficher'] . "</th>
                            <th>" . $classeAttaquant3['formuleAfficher'] . "</th>
                            <th>" . $classeAttaquant4['formuleAfficher'] . "</th>
                            </tr>
                            </thead>

                            <tbody>
                            <tr>
                            <th>Troupes</th>
                            <td>" . number_format($classeAttaquant1['nombre'], 0, ' ', ' ') . "</td>
                            <td>" . number_format($classeAttaquant2['nombre'], 0, ' ', ' ') . "</td>
                            <td>" . number_format($classeAttaquant3['nombre'], 0, ' ', ' ') . "</td>
                            <td>" . number_format($classeAttaquant4['nombre'], 0, ' ', ' ') . "</td>
                            </tr>

                            <tr>
                            <th>Pertes</th>
                            <td>" . number_format($classe1AttaquantMort, 0, ' ', ' ') . "</td>
                            <td>" . number_format($classe2AttaquantMort, 0, ' ', ' ') . "</td>
                            <td>" . number_format($classe3AttaquantMort, 0, ' ', ' ') . "</td>
                            <td>" . number_format($classe4AttaquantMort, 0, ' ', ' ') . "</td>
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

                $classeDefenseur1['nombre'] = separerZeros($classeDefenseur1['nombre']);
                $classeDefenseur2['nombre'] = separerZeros($classeDefenseur2['nombre']);
                $classeDefenseur3['nombre'] = separerZeros($classeDefenseur3['nombre']);
                $classeDefenseur4['nombre'] = separerZeros($classeDefenseur4['nombre']);

                $classe1DefenseurMort = separerZeros($classe1DefenseurMort);
                $classe2DefenseurMort = separerZeros($classe2DefenseurMort);
                $classe3DefenseurMort = separerZeros($classe3DefenseurMort);
                $classe4DefenseurMort = separerZeros($classe4DefenseurMort);

                $milieuDefenseur = "
                            <th>" . couleurFormule($classeDefenseur1['formule']) . "</th>
                            <th>" . couleurFormule($classeDefenseur2['formule']) . "</th>
                            <th>" . couleurFormule($classeDefenseur3['formule']) . "</th>
                            <th>" . couleurFormule($classeDefenseur4['formule']) . "</th>
                            </tr>
                            </thead>

                            <tbody>
                            <tr>
                            <th>Troupes</th>
                            <td>" . $classeDefenseur1['nombre'] . "</td>
                            <td>" . $classeDefenseur2['nombre'] . "</td>
                            <td>" . $classeDefenseur3['nombre'] . "</td>
                            <td>" . $classeDefenseur4['nombre'] . "</td>
                            </tr>

                            <tr>
                            <th>Pertes</th>
                            <td>" . $classe1DefenseurMort . "</td>
                            <td>" . $classe2DefenseurMort . "</td>
                            <td>" . $classe3DefenseurMort . "</td>
                            <td>" . $classe4DefenseurMort . "</td>
                            </tr>";

                if ($attaquantsRestants == 0) { // si aucune molécule n'est revenue alors on a aucune information sur les troupes en face
                    $classeDefenseur1['formule'] = "?";
                    $classeDefenseur2['formule'] = "?";
                    $classeDefenseur3['formule'] = "?";
                    $classeDefenseur4['formule'] = "?";

                    $classeDefenseur1['nombre'] = "?";
                    $classeDefenseur2['nombre'] = "?";
                    $classeDefenseur3['nombre'] = "?";
                    $classeDefenseur4['nombre'] = "?";

                    $classe1DefenseurMort = "?";
                    $classe2DefenseurMort = "?";
                    $classe3DefenseurMort = "?";
                    $classe4DefenseurMort = "?";

                    dbExecute($base, 'DELETE FROM actionsattaques WHERE id=?', 'i', $actions['id']); // pas de retour si ils sont morts

                }


                $milieuAttaquant = "
                            <th>" . couleurFormule($classeDefenseur1['formule']) . "</th>
                            <th>" . couleurFormule($classeDefenseur2['formule']) . "</th>
                            <th>" . couleurFormule($classeDefenseur3['formule']) . "</th>
                            <th>" . couleurFormule($classeDefenseur4['formule']) . "</th>
                            </tr>
                            </thead>

                            <tbody>
                            <tr>
                            <th>Troupes</th>
                            <td>" . $classeDefenseur1['nombre'] . "</td>
                            <td>" . $classeDefenseur2['nombre'] . "</td>
                            <td>" . $classeDefenseur3['nombre'] . "</td>
                            <td>" . $classeDefenseur4['nombre'] . "</td>
                            </tr>

                            <tr>
                            <th>Pertes</th>
                            <td>" . $classe1DefenseurMort . "</td>
                            <td>" . $classe2DefenseurMort . "</td>
                            <td>" . $classe3DefenseurMort . "</td>
                            <td>" . $classe4DefenseurMort . "</td>
                            </tr>";


                // Build reactions display for combat report
                $reactionsHtml = '';
                if (!empty($activeReactionsAtt) || !empty($activeReactionsDef)) {
                    $reactionsHtml = important('Réactions chimiques') . '<br/>';
                    foreach ($activeReactionsAtt as $name => $bonuses) {
                        $reactionsHtml .= '<span style="color:#D07D00">&#9883; ' . htmlspecialchars($name) . ' (attaquant)</span><br/>';
                    }
                    foreach ($activeReactionsDef as $name => $bonuses) {
                        $reactionsHtml .= '<span style="color:green">&#9883; ' . htmlspecialchars($name) . ' (défenseur)</span><br/>';
                    }
                    $reactionsHtml .= '<br/>';
                }

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
                            </p>
                            ";

                $contenuRapportAttaquant = $debutRapport . $milieuAttaquant . $finRapport;
                $contenuRapportDefenseur = $debutRapport . $milieuDefenseur . $finRapport;

                // Les rapports sont créés
                $rapportImage = '<img alt="attack" src="images/rapports/sword.png"/ class="imageAide">';
                dbExecute($base, 'INSERT INTO rapports VALUES(default, ?, ?, ?, ?, default, ?)', 'issss', $actions['tempsAttaque'], $titreRapportJoueur, $contenuRapportAttaquant, $actions['attaquant'], $rapportImage);

                dbExecute($base, 'INSERT INTO rapports VALUES(default, ?, ?, ?, ?, default, ?)', 'issss', $actions['tempsAttaque'], $titreRapportDefenseur, $contenuRapportDefenseur, $actions['defenseur'], $rapportImage);
                mysqli_commit($base);
                } catch (Exception $combatException) {
                    mysqli_rollback($base);
                    error_log('Combat transaction rolled back for action ' . $actions['id'] . ': ' . $combatException->getMessage());
                }
            } else {
                $nDef = dbFetchOne($base, 'SELECT neutrinos FROM autre WHERE login=?', 's', $actions['defenseur']);
                // Radar research reduces the neutrino threshold for successful espionage
                $radarDiscount = 1 - allianceResearchBonus($actions['attaquant'], 'espionage_cost');
                $espionageThreshold = ($nDef['neutrinos'] * ESPIONAGE_SUCCESS_RATIO) * $radarDiscount;

                if ($espionageThreshold < $actions['nombreneutrinos']) {
                    $exEspionnage = dbQuery($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC', 's', $actions['defenseur']);
                    $i = 1;
                    while ($donneesEspionnage = mysqli_fetch_array($exEspionnage)) {
                        ${'classe' . $i} = $donneesEspionnage;
                        $i++;
                    }


                    $ressourcesJoueur = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $actions['defenseur']);

                    $constructionsJoueur = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $actions['defenseur']);

                    $titreRapportJoueur = "Vous espionnez " . htmlspecialchars($actions['defenseur'], ENT_QUOTES, 'UTF-8');
                    $chaine1 = "";
                    foreach ($nomsRes as $num => $ressource) {
                        $chaine1 = $chaine1 . nombreAtome($num, number_format($ressourcesJoueur[$ressource], 0, ' ', ' '));
                    }

                    $contenuRapportJoueur = "
                    <p>" . important('Armée') . "
                    <strong>" . couleurFormule($classe1['formule']) . " : </strong>" . nombreMolecules(number_format($classe1['nombre'], 0, ' ', ' ')) . "<br/>
                    <strong>" . couleurFormule($classe2['formule']) . " : </strong>" . nombreMolecules(number_format($classe2['nombre'], 0, ' ', ' ')) . "<br/>
                    <strong>" . couleurFormule($classe3['formule']) . " : </strong>" . nombreMolecules(number_format($classe3['nombre'], 0, ' ', ' ')) . "<br/>
                    <strong>" . couleurFormule($classe4['formule']) . " : </strong>" . nombreMolecules(number_format($classe4['nombre'], 0, ' ', ' ')) . "<br/>
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
                    <td>Pas de vie</td>
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
                } else {
                    $titreRapportJoueur = "Espionnage raté";
                    $contenuRapportJoueur = "<p>Votre espionnage a raté, vous avez envoyé moins de la moitié des neutrinos de votre adversaire.</p>";
                }


                dbExecute($base, 'INSERT INTO rapports VALUES(default, ?, ?, ?, ?, default, ?)', 'issss', $actions['tempsAttaque'], $titreRapportJoueur, $contenuRapportJoueur, $actions['attaquant'], '<img alt="attaque" src="images/rapports/binoculars.png"/ class="imageAide">');

                // Notify defender they were spied on (anonymous — doesn't reveal spy identity)
                if ($espionageThreshold < $actions['nombreneutrinos']) {
                    $titreRapportEspionDef = 'Tentative d\'espionnage détectée';
                    $contenuRapportEspionDef = '<p>Un agent inconnu a espionné votre base. Vos défenses, ressources et compositions moléculaires ont été observées.</p>';
                    dbExecute($base, 'INSERT INTO rapports VALUES(default, ?, ?, ?, ?, default, ?)', 'issss', $actions['tempsAttaque'], $titreRapportEspionDef, $contenuRapportEspionDef, $actions['defenseur'], '<img alt="spy" src="images/rapports/binoculars.png" class="imageAide"/>');
                }

                dbExecute($base, 'DELETE FROM actionsattaques WHERE id=?', 'i', $actions['id']);
            }
        }

        if ($actions['tempsRetour'] < time() && $joueur == $actions['attaquant'] && $actions['troupes'] != 'Espionnage') { // dans ce cas là on remet à jour les troupes

            $nbsecondes = $actions['tempsRetour'] - $actions['tempsAttaque'];
            $molecules = explode(";", $actions['troupes']);

            // Validate troupes array length
            if (count($molecules) < $nbClasses) {
                logError("Return trip: malformed troupes string for action " . $actions['id'] . ": " . $actions['troupes']);
                dbExecute($base, 'DELETE FROM actionsattaques WHERE id=?', 'i', $actions['id']);
            } else {
                $ex3 = dbQuery($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC', 's', $joueur);

                $compteur = 1;
                $totalMoleculesPerdues = 0;

                while ($moleculesProp = mysqli_fetch_array($ex3)) {
                    if (!isset($molecules[$compteur - 1])) break;
                    $moleculesRestantes = (pow(coefDisparition($joueur, $compteur), $nbsecondes) * $molecules[$compteur - 1]);

                    dbExecute($base, 'UPDATE molecules SET nombre=? WHERE id=?', 'di', ($moleculesProp['nombre'] + $moleculesRestantes), $moleculesProp['id']);

                    $totalMoleculesPerdues += ($molecules[$compteur - 1] - $moleculesRestantes);
                    $compteur++;
                }

                // Batch update moleculesPerdues atomically
                if ($totalMoleculesPerdues > 0) {
                    dbExecute($base, 'UPDATE autre SET moleculesPerdues = moleculesPerdues + ? WHERE login=?', 'ds', $totalMoleculesPerdues, $joueur);
                }

                dbExecute($base, 'DELETE FROM actionsattaques WHERE id=?', 'i', $actions['id']);
            }
        }
    }

    $ex = dbQuery($base, 'SELECT * FROM actionsenvoi WHERE (receveur=? OR envoyeur=?) AND tempsArrivee<?', 'ssi', $joueur, $joueur, time());

    while ($actions = mysqli_fetch_array($ex)) {
        dbExecute($base, 'DELETE FROM actionsenvoi WHERE id=?', 'i', $actions['id']);

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
        if ($envoyees[sizeof($nomsRes)] > 0) {
            $energieEnvoyee = nombreEnergie(number_format($envoyees[sizeof($nomsRes)], 0, ' ', ' '));
        }

        $energieRecue = "";
        if ($recues[sizeof($nomsRes)] > 0) {
            $energieRecue = nombreEnergie(number_format($recues[sizeof($nomsRes)], 0, ' ', ' '));
        }

        $titreRapport = "Rapport d\'apport de ressources par " . htmlspecialchars($actions['envoyeur'], ENT_QUOTES, 'UTF-8');
        $contenuRapport = "<a href=\"joueur.php?id=" . htmlspecialchars($actions['envoyeur'], ENT_QUOTES, 'UTF-8') . "\">" . htmlspecialchars($actions['envoyeur'], ENT_QUOTES, 'UTF-8') . "</a> vous envoie les ressources suivantes : <br/><br/>
        " . important('Ressources envoyées') . "
        " . $energieEnvoyee . $chaine1 . "<br/><br/>
        " . important('Ressources reçues') . "
        " . $energieRecue . $chaine2;

        dbExecute($base, 'INSERT INTO rapports VALUES(default, ?, ?, ?, ?, default, ?)', 'issss', time(), $titreRapport, $contenuRapport, $actions['receveur'], '<img alt="fleche" src="images/rapports/retour.png" class="imageAide">');

        $ressourcesDestinataire = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $actions['receveur']);
        // FIX FINDING-GAME-009: Cap received resources at storage limit
        $depotReceveur = dbFetchOne($base, 'SELECT depot FROM constructions WHERE login=?', 's', $actions['receveur']);
        $maxStorageRecv = placeDepot($depotReceveur['depot']);
        $chaine = "";
        foreach ($nomsRes as $num => $ressource) {
            $plus = "";
            $recues[$num] = max(0, $recues[$num]);
            if ($num < $nbRes) {
                $plus = ",";
            }
            $chaine = $chaine . '' . $ressource . '=' . min($maxStorageRecv, round($ressourcesDestinataire[$ressource] + $recues[$num])) . '' . $plus;
        }

        $recues[sizeof($nomsRes)] = max(0, $recues[sizeof($nomsRes)]);
        // Build parameterized update for envoi resources
        $envoiSetClauses = ['energie=?'];
        $envoiTypes = 'd';
        $envoiParams = [min($maxStorageRecv, round($ressourcesDestinataire['energie'] + $recues[sizeof($nomsRes)]))];
        foreach ($nomsRes as $num => $ressource) {
            $envoiSetClauses[] = "$ressource=?";
            $envoiTypes .= 'd';
            $envoiParams[] = min($maxStorageRecv, round($ressourcesDestinataire[$ressource] + $recues[$num]));
        }
        $envoiParams[] = $actions['receveur'];
        $envoiTypes .= 's';
        dbExecute($base, 'UPDATE ressources SET ' . implode(',', $envoiSetClauses) . ' WHERE login=?', $envoiTypes, ...$envoiParams);
    }

    initPlayer($joueur);
    unset($updating[$joueur]);
}
