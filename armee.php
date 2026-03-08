<?php
include("includes/basicprivatephp.php");
include("includes/redirectionVacance.php");

if (isset($_POST['emplacementmoleculesupprimer']) and !empty($_POST['emplacementmoleculesupprimer']) and preg_match("#^[0-9]*$#", $_POST['emplacementmoleculesupprimer']) and $_POST['emplacementmoleculesupprimer'] <= MAX_MOLECULE_CLASSES and $_POST['emplacementmoleculesupprimer'] >= 1) { // FIX FINDING-GAME-022: was <= 5
    csrfCheck();
    $molecules = dbFetchOne($base, 'SELECT formule,id FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $_SESSION['login'], $_POST['emplacementmoleculesupprimer']);

    if ($molecules && $molecules['formule'] != "Vide") {
        $login = $_SESSION['login'];
        $emplacement = $_POST['emplacementmoleculesupprimer'];
        $moleculeId = $molecules['id'];

        try {
        withTransaction($base, function() use ($base, $login, $emplacement, $moleculeId, $nomsRes, $nbRes, $nbClasses) {
            $niveauclasse = dbFetchOne($base, 'SELECT niveauclasse FROM ressources WHERE login=? FOR UPDATE', 's', $login);
            $newNiveauClasse = $niveauclasse['niveauclasse'] - 1;
            dbExecute($base, 'UPDATE ressources SET niveauclasse=? WHERE login=?', 'is', $newNiveauClasse, $login);

            $chaine = ""; // on passe toutes les chaines sauf conditions pour les ressources en dynamique
            foreach ($nomsRes as $num => $ressource) {
                $plus = "";
                if ($num < $nbRes) {
                    $plus = ",";
                }
                $chaine = $chaine . '' . $ressource . '=default' . $plus;
            }

            // $chaine contains only server-side column names with 'default' keyword, not user input
            dbExecute($base, 'UPDATE molecules SET formule = default, ' . $chaine . ', nombre = default WHERE proprietaire=? AND numeroclasse=?', 'si', $login, $emplacement);

            dbExecute($base, 'DELETE FROM actionsformation WHERE login=? AND idclasse=?', 'si', $login, $moleculeId);
            $nvxDebut = time();
            $actuActionsRows = dbFetchAll($base, 'SELECT * FROM actionsformation WHERE login=? ORDER BY debut ASC', 's', $login);
            foreach ($actuActionsRows as $actionsformation) {
                if (time() < $actionsformation['debut']) {
                    $newFin = $nvxDebut + $actionsformation['nombreRestant'] * $actionsformation['tempsPourUn'];
                    dbExecute($base, 'UPDATE actionsformation SET debut=?, fin=? WHERE id=?', 'iii', $nvxDebut, $newFin, $actionsformation['id']);
                    $nvxDebut = $nvxDebut + $actionsformation['nombreRestant'] * $actionsformation['tempsPourUn'];
                } else {
                    $nvxDebut = $actionsformation['fin'];
                }
            }

            // on enleve ces types de molécules dans les attaques lancées
            $actionsattaquesRows = dbFetchAll($base, 'SELECT * FROM actionsattaques WHERE attaquant=?', 's', $login);
            foreach ($actionsattaquesRows as $actionsattaques) {
                $explosion = explode(";", $actionsattaques['troupes']);
                $chaine = "";
                for ($i = 1; $i <= $nbClasses; $i++) {
                    if ($i == $emplacement) {
                        $chaine .= "0;";
                    } else {
                        $chaine .= $explosion[$i - 1] . ";";
                    }
                }

                dbExecute($base, 'UPDATE actionsattaques SET troupes=? WHERE id=?', 'si', $chaine, $actionsattaques['id']);
            }
        });
        $information = "Vous avez supprimé la classe de molécules.";
        } catch (\Exception $e) {
            // ERR-P7-002: catch unexpected transaction failures to avoid blank page
            logError('PAGE_ERROR', $e->getMessage(), ['page' => 'armee.php', 'action' => 'supprimer_molecule']);
            $erreur = "Une erreur est survenue lors de la suppression. Veuillez réessayer.";
        }
    } else {
        $erreur = "Cet emplacement est déja vide.";
    }
}

// NEUTRINOS
if (isset($_POST['nombreneutrinos']) and !empty($_POST['nombreneutrinos'])) {
    csrfCheck();
    $_POST['nombreneutrinos'] = transformInt($_POST['nombreneutrinos']);
    if (preg_match("#^[0-9]*$#", $_POST['nombreneutrinos']) and $_POST['nombreneutrinos'] >= 1) {
        $_POST['nombreneutrinos'] = intval($_POST['nombreneutrinos']);
        $nombreNeutrinos = $_POST['nombreneutrinos'];
        $login = $_SESSION['login'];
        try {
            withTransaction($base, function() use ($base, $nombreNeutrinos, $coutNeutrino, $login, &$autre) {
                $res = dbFetchOne($base, 'SELECT energie FROM ressources WHERE login = ? FOR UPDATE', 's', $login);
                if (!$res || $res['energie'] < $nombreNeutrinos * $coutNeutrino) {
                    throw new \RuntimeException('Insufficient energy');
                }
                $autreRow = dbFetchOne($base, 'SELECT neutrinos, energieDepensee FROM autre WHERE login = ? FOR UPDATE', 's', $login);

                $newNeutrinos = $autreRow['neutrinos'] + $nombreNeutrinos;
                dbExecute($base, 'UPDATE autre SET neutrinos=? WHERE login=?', 'is', $newNeutrinos, $login);
                $autre['neutrinos'] = $newNeutrinos;

                $newEnergie = max(0, $res['energie'] - $nombreNeutrinos * $coutNeutrino);
                dbExecute($base, 'UPDATE ressources SET energie=? WHERE login=?', 'ds', $newEnergie, $login);
                $newEnergieDepensee = $autreRow['energieDepensee'] + $nombreNeutrinos * $coutNeutrino;
                dbExecute($base, 'UPDATE autre SET energieDepensee=? WHERE login=?', 'ds', $newEnergieDepensee, $login);
            });
            $information = 'Vous avez formé ' . $nombreNeutrinos . ' neutrinos.';
        } catch (\RuntimeException $e) {
            $erreur = "Vous n'avez pas assez d'énergie.";
        }
    } else {
        $erreur = "Seul des nombres positifs et entiers doivent être entrés.";
    }
}

if (isset($_POST['emplacementmoleculeformer']) and !empty($_POST['emplacementmoleculeformer']) and preg_match("#^[0-9]*$#", $_POST['emplacementmoleculeformer']) and $_POST['emplacementmoleculeformer'] <= MAX_MOLECULE_CLASSES and $_POST['emplacementmoleculeformer'] >= 1) { // FIX FINDING-GAME-022
    csrfCheck();
    $_POST['nombremolecules'] = transformInt($_POST['nombremolecules']);
    if (isset($_POST['nombremolecules']) and !empty($_POST['nombremolecules']) and preg_match("#^[0-9]*$#", $_POST['nombremolecules'])) {
        $_POST['nombremolecules'] = intval($_POST['nombremolecules']);
        $nombreMolecules = $_POST['nombremolecules'];
        if ($nombreMolecules > 1000000) {
            $erreur = "Le nombre de molécules demandé est trop élevé.";
        }
        if (empty($erreur)) {
        $emplacementFormer = $_POST['emplacementmoleculeformer'];
        $login = $_SESSION['login'];
        // V4: Fetch lieur level and azote condenseur level for tempsFormation
        $buildingsArmee = dbFetchOne($base, 'SELECT lieur, pointsCondenseur FROM constructions WHERE login=?', 's', $login);
        $nivLieurArmee = ($buildingsArmee && isset($buildingsArmee['lieur'])) ? $buildingsArmee['lieur'] : 0;
        $niveauazote = 0;
        if ($buildingsArmee && isset($buildingsArmee['pointsCondenseur'])) {
            $niveaux = explode(';', $buildingsArmee['pointsCondenseur']);
            $niveauazote = isset($niveaux[1]) ? (int)$niveaux[1] : 0; // azote is index 1 in $nomsRes
        }
        try {
            $formuleAffichage = withTransaction($base, function() use ($base, $nombreMolecules, $emplacementFormer, $login, $nomsRes, $nbRes, $niveauazote, $nivLieurArmee) {
                $donneesFormer = dbFetchOne($base, 'SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $login, $emplacementFormer);
                $ressources = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=? FOR UPDATE', 's', $login);

                foreach ($nomsRes as $num => $ressource) {
                    if (($donneesFormer[$ressource] * $nombreMolecules) > $ressources[$ressource]) {
                        throw new \RuntimeException('Insufficient atoms');
                    }
                }

                $total = 0;
                foreach ($nomsRes as $num => $ressource) {
                    $total = $total + $donneesFormer[$ressource];
                }

                $actionsformationRow = dbFetchOne($base, 'SELECT * FROM actionsformation WHERE login=? ORDER BY fin DESC FOR UPDATE', 's', $login);
                $nb = $actionsformationRow ? 1 : 0;
                if ($nb > 0) {
                    $actionsformation = $actionsformationRow;
                    $tempsDebut = $actionsformation['fin'];
                } else {
                    $tempsDebut = time();
                }

                $tempsForm = tempsFormation($total, $donneesFormer['azote'], $donneesFormer['iode'], $niveauazote, $nivLieurArmee, $login);
                $finTemps = $tempsDebut + $tempsForm * $nombreMolecules;
                dbExecute($base, 'INSERT INTO actionsformation VALUES(default,?,?,?,?,?,?,?,?)', 'issiiiss',
                    $donneesFormer['id'], $login, $tempsDebut, $finTemps, $nombreMolecules, $nombreMolecules, $donneesFormer['formule'], $tempsForm);

                // Build dynamic UPDATE for ressources with parameterized values
                $setClauses = [];
                $paramTypes = '';
                $paramValues = [];
                foreach ($nomsRes as $num => $ressource) {
                    $setClauses[] = $ressource . '=?';
                    $paramTypes .= 'd';
                    $paramValues[] = $ressources[$ressource] - ($nombreMolecules * $donneesFormer[$ressource]);
                }
                $paramTypes .= 's';
                $paramValues[] = $login;
                dbExecute($base, 'UPDATE ressources SET ' . implode(', ', $setClauses) . ' WHERE login=?', $paramTypes, ...$paramValues);

                return $donneesFormer['formule'];
            });
            $information = 'Vous avez lancé la formation de ' . $nombreMolecules . ' molécules de ' . couleurFormule($formuleAffichage) . '';
        } catch (\RuntimeException $e) {
            $erreur = "Vous n'avez pas assez d'atomes.";
        }
        } // end empty($erreur) check
    } else {
        $erreur = "Seul des nombres positifs et entiers doivent être entrés.";
    }
}

if (isset($_POST['emplacementmoleculecreer1']) and !empty($_POST['emplacementmoleculecreer1']) and preg_match("#^[0-9]*$#", $_POST['emplacementmoleculecreer1']) and $_POST['emplacementmoleculecreer1'] <= MAX_MOLECULE_CLASSES and $_POST['emplacementmoleculecreer1'] >= 1) { // FIX FINDING-GAME-022
    csrfCheck();
    $bool = 1;
    foreach ($nomsRes as $num => $ressource) {
        if (!(isset($_POST[$ressource]) and preg_match("#^[0-9]*$#", $_POST[$ressource]))) {
            $bool = 0;
        }
    }
    if ($bool == 1) { // on vérifie que c'est un chiffre positif
        $bool = 1;
        foreach ($nomsRes as $num => $ressource) { // si on est en dessous de 200 atomes de chaque
            if ($_POST[$ressource] > MAX_ATOMS_PER_ELEMENT) {
                $bool = 0;
            }
        }
        if ($bool == 1) {
            $bool = 1;
            foreach ($nomsRes as $num => $ressource) { // si on est en dessous de 200 atomes de chaque
                if (!empty($_POST[$ressource])) {
                    $bool = 0;
                }
            }
            if ($bool == 0) {
                // Collect validated POST atom values before transaction
                $atomValues = [];
                $formule = "";
                foreach ($nomsRes as $num => $ressource) {
                    if (!empty($_POST[$ressource])) {
                        $atomValues[$ressource] = intval($_POST[$ressource]);
                        if ($_POST[$ressource] > 1) {
                            $formule = '' . $formule . '' . $lettre[$num] . '<sub>' . $_POST[$ressource] . '</sub>';
                        } else {
                            $formule = $formule . '' . $lettre[$num];
                        }
                    } else {
                        $atomValues[$ressource] = 0;
                    }
                }
                // Isotope selection (validated integer 0-3)
                $isotope = isset($_POST['isotope']) ? intval($_POST['isotope']) : 0;
                if ($isotope < 0 || $isotope > 3) $isotope = 0;
                $numClasse = intval($_POST['emplacementmoleculecreer1']);
                $login = $_SESSION['login'];

                try {
                    withTransaction($base, function() use ($base, $login, $nomsRes, $nbRes, $atomValues, $formule, $isotope, $numClasse) {
                        // Verify slot is empty before overwriting (prevent clobbering existing molecule)
                        $existingSlot = dbFetchOne($base, 'SELECT formule FROM molecules WHERE proprietaire=? AND numeroclasse=? FOR UPDATE', 'si', $login, $numClasse);
                        if ($existingSlot && $existingSlot['formule'] !== 'Vide') {
                            throw new \RuntimeException('Slot not empty');
                        }
                        $cout = dbFetchOne($base, 'SELECT energie, niveauclasse FROM ressources WHERE login=? FOR UPDATE', 's', $login);
                        if (!$cout || $cout['energie'] < coutClasse($cout['niveauclasse'])) {
                            throw new \RuntimeException('Insufficient energy');
                        }

                        $newNiveauClasse = $cout['niveauclasse'] + 1;
                        dbExecute($base, 'UPDATE ressources SET niveauclasse=? WHERE login=?', 'is', $newNiveauClasse, $login);

                        // Build dynamic UPDATE for molecules with fully parameterized values
                        $setClauses = [];
                        $paramTypes = '';
                        $paramValues = [];
                        foreach ($nomsRes as $num => $ressource) {
                            $setClauses[] = $ressource . '=?';
                            $paramTypes .= 'i';
                            $paramValues[] = $atomValues[$ressource];
                        }
                        $setClauses[] = 'formule=?';
                        $paramTypes .= 's';
                        $paramValues[] = $formule;
                        $setClauses[] = 'isotope=?';
                        $paramTypes .= 'i';
                        $paramValues[] = $isotope;
                        $paramTypes .= 'si';
                        $paramValues[] = $login;
                        $paramValues[] = $numClasse;
                        dbExecute($base, 'UPDATE molecules SET ' . implode(', ', $setClauses) . ' WHERE proprietaire=? AND numeroclasse=?', $paramTypes, ...$paramValues);

                        $newEnergie = max(0, $cout['energie'] - coutClasse($cout['niveauclasse']));
                        dbExecute($base, 'UPDATE ressources SET energie=? WHERE login=?', 'ds', $newEnergie, $login);
                    });
                    $information = "Une nouvelle classe de molécule a été créée.";
                } catch (\RuntimeException $e) {
                    if ($e->getMessage() === 'Slot not empty') {
                        $erreur = "Cet emplacement contient déjà une molécule.";
                    } else {
                        $erreur = "Vous n'avez pas assez d'energie.";
                    }
                }
            } else {
                $erreur = "Votre molécule doit au moins être composée d'un atome.";
            }
        } else {
            $erreur = "Les molécules ne doivent pas excéder " . MAX_ATOMS_PER_ELEMENT . " atomes de chaque.";
        }
    } else {
        $erreur = "Seul des nombres positifs et entiers doivent être entrés.";
    }
}

include("includes/layout.php");

$actionsformationRows = dbFetchAll($base, 'SELECT * FROM actionsformation WHERE login=? ORDER BY debut ASC', 's', $_SESSION['login']);
$nb = count($actionsformationRows);
if ($nb > 0) {
    debutCarte();
    scriptAffichageTemps();
    echo '<div class="table-responsive"><table>';
    echo '<tr><th>Molécule</th><th>Prochaine</th><th>Total</th></tr>';

    $c = 0;
    foreach ($actionsformationRows as $actionsformation) {
        if (empty($actionsformation['tempsPourUn']) || $actionsformation['tempsPourUn'] <= 0) {
            continue;
        }
        $offset = max(0, $actionsformation['debut'] - time());

        $moleculeEnCours = dbFetchOne($base, 'SELECT * FROM molecules WHERE id=?', 'i', $actionsformation['idclasse']);

        // rajouter formation dynamique (et non pas recharger, rehcarger uniquement si tout fini et surtout ne pas oublier de former les molécules en traitement dans updateActions)
        $tempsVirgule = $actionsformation['tempsPourUn']; // on a l'int pour les % et le double pour afficahge dynamique
        $actionsformation['tempsPourUn'] = ceil($actionsformation['tempsPourUn']);
        if ((($actionsformation['fin'] - time() - $offset) % $actionsformation['tempsPourUn']) == 0) {
            $prochaine = affichageTemps($offset + $actionsformation['tempsPourUn']);
        } else {
            $prochaine = affichageTemps($offset + ($actionsformation['fin'] - time() - $offset) % $actionsformation['tempsPourUn']);
        }

        if ($actionsformation['idclasse'] != 'neutrino') {
            $affichageFormule = couleurFormule($moleculeEnCours['formule']);
        } else {
            $affichageFormule =  $actionsformation['formule'];
        }

        echo '<tr><td><strong id="nombreRestants' . $actionsformation['id'] . '">' . $actionsformation['nombreRestant'] . '</strong> ' . $affichageFormule . '</td><td id="affichageProchain' . $actionsformation['id'] . '">' . $prochaine . '</td><td id="affichage' . $actionsformation['id'] . '">' . affichageTemps($actionsformation['fin'] - time()) . '</td></tr>';

        echo '
        ' . cspScriptTag() . '
            var valeur' . $actionsformation['id'] . ' = ' . ($actionsformation['fin'] - time()) . ';


            if(' . ($actionsformation['fin'] - time() - $offset) % $actionsformation['tempsPourUn'] . ' == 0){
                var valeurProchain' . $actionsformation['id'] . ' = ' . ($offset + $actionsformation['tempsPourUn']) . ';
            }
            else {
                var valeurProchain' . $actionsformation['id'] . ' = ' . ($offset + ($actionsformation['fin'] - time() - $offset) % $actionsformation['tempsPourUn']) . ';
            }

            function tempsDynamique' . $actionsformation['id'] . '(){
                var nombreParSeconde = 1/' . $tempsVirgule . ';
                var vraiRestant =  parseInt(document.getElementById("nombreRestants' . $actionsformation['id'] . '").innerHTML);
                if(valeur' . $actionsformation['id'] . ' != 0){
                    valeur' . $actionsformation['id'] . ' -= 1;
                    document.getElementById("affichage' . $actionsformation['id'] . '").innerHTML = affichageTemps(valeur' . $actionsformation['id'] . ');

                    if(valeurProchain' . $actionsformation['id'] . ' == 0){
                        valeurProchain' . $actionsformation['id'] . ' = ' . ($offset + $actionsformation['tempsPourUn']) . ';
                        vraiRestant = vraiRestant-nombreParSeconde;
                        document.getElementById("nombreRestants' . $actionsformation['id'] . '").innerHTML = parseInt(vraiRestant);
                    }
                    valeurProchain' . $actionsformation['id'] . ' = valeurProchain' . $actionsformation['id'] . '-1;

                    document.getElementById("affichageProchain' . $actionsformation['id'] . '").innerHTML = affichageTemps(valeurProchain' . $actionsformation['id'] . ');
                }
                else {
                    document.location.href="armee.php";
                }
            }

            setInterval(tempsDynamique' . $actionsformation['id'] . ', 1000);
            </script>';
        $c++; // sert a savoir si c'est la formation en cours
    }
    echo '</table></div>';
    finCarte();
}

if (isset($_POST['emplacementmoleculecreer'])) {
    debutCarte("Composition de la classe" . aide("composition"));
    echo '<form action="armee.php" method="post" name="creernouvelleclasse1">';
    echo csrfField();
    debutListe();
    foreach ($nomsRes as $num => $ressource) {
        item(['titre' => nombreAtome($num, 'Nombre ' . pref($ressource) . '<strong>' . $nomsAccents[$num] . '</strong>') . aide($ressource, true), 'input' => '<input type="number" name="' . $ressource . '" id="' . $ressource . '" placeholder="' . $utilite[$num] . '" class="form-control"/>']);
    }  ?>
    <input type="hidden" name="emplacementmoleculecreer1" value="<?php echo htmlspecialchars($_POST['emplacementmoleculecreer'], ENT_QUOTES, 'UTF-8'); ?>" />
<?php
    // Isotope variant selector
    global $ISOTOPES;
    item(['titre' => '<strong>Isotope</strong>', 'input' => '']);
    echo '<div style="padding:4px 16px;">';
    foreach ($ISOTOPES as $id => $iso) {
        $checked = ($id === 0) ? ' checked' : '';
        echo '<label style="display:block;padding:4px 0;cursor:pointer;">';
        echo '<input type="radio" name="isotope" value="' . $id . '"' . $checked . ' style="margin-right:6px;"/>';
        echo '<strong>' . htmlspecialchars($iso['name']) . '</strong> — <small>' . htmlspecialchars($iso['desc']) . '</small>';
        echo '</label>';
    }
    echo '</div>';

    item(['input' => submit(['form' => 'creernouvelleclasse1', 'titre' => 'Créer'])]);
    finListe();
    echo '</form>';
    finCarte();
}

$currentSub = isset($_GET['sub']) ? (int)$_GET['sub'] : 0;
echo '<div style="text-align:center; margin:10px 0;">';
echo '<a href="armee.php" class="button ' . ($currentSub === 0 ? 'button-fill' : '') . '" style="margin:0 5px;">Formation</a>';
echo '<a href="armee.php?sub=1" class="button ' . ($currentSub === 1 ? 'button-fill' : '') . '" style="margin:0 5px;">Vue d\'ensemble</a>';
echo '</div>';

if ($currentSub == 0) {
    debutCarte('Molécule ' . aide('armee'));
    $moleculeRows = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse', 's', $_SESSION['login']);
    if (!is_array($moleculeRows)) {
        error_log("SQL error fetching molecules");
        echo "Une erreur est survenue.";
    } else {
    $nbclasse = count($moleculeRows);

    $ressources = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $_SESSION['login']);
    $compteur = 0;
    foreach ($moleculeRows as $molecule) {
        echo '<form action="armee.php" method="post">' . csrfField() . '<img src="images/' . $molecule['numeroclasse'] . '.png" alt="' . $molecule['numeroclasse'] . '" style="vertical-align: middle;height:40px;width:40px;"/>';
        echo '<a href="molecule.php?id=' . $molecule['id'] . '" style="margin-left: 20px;font-weight:bold;" class="lienFormule">' . couleurFormule($molecule['formule']) . '</a>  ';
        echo nombreMolecules(ceil($molecule['nombre']));
        if ($molecule['formule'] != "Vide") {
            $nbmoleculesMax = PHP_INT_MAX;
            foreach ($nomsRes as $num => $ressource) {
                if ($molecule[$ressource] > 0) {
                    $nbmoleculesMax = min($nbmoleculesMax, floor($ressources[$ressource] / $molecule[$ressource]));
                }
            }

            echo '
            <input type="hidden" name="emplacementmoleculesupprimer" value="' . $molecule['numeroclasse'] . '"/>
            <input src="images/croix.png" class="w32" alt="supprimer" type="image" value="Supprimer" style="vertical-align: middle;float:right;" name="supprimermolecule" title="Supprimer la classe de molécule">';

            echo '</form></br><br/>';
            debutListe();
            item(['titre' => 'Former', 'form' => ['armee.php', 'formermolecule' . $molecule['numeroclasse']], 'input' => '<input type="text" name="nombremolecules" id="nombremolecules" class="form-control" placeholder="Nombre de molécules"/><input type="hidden" name="emplacementmoleculeformer" value="' . $molecule['numeroclasse'] . '"/>' . csrfField(), 'after' => '<a name="generer" class="button button-raised button-fill max-fill-btn" data-target-index="' . $compteur . '" data-max-value="' . $nbmoleculesMax . '" style="margin-right:5px">Max : ' . chiffrePetit($nbmoleculesMax, 0) . '</a>']);
            item(['input' => submit(['form' => 'formermolecule' . $molecule['numeroclasse'], 'titre' => 'Former'])]);
            if ($compteur != 3) {
                echo '<hr class="corps"><br/>';
            }
            $compteur++;
            finListe();
        } else {
            $cout = dbFetchOne($base, 'SELECT niveauclasse FROM ressources WHERE login=?', 's', $_SESSION['login']);
            echo '<input src="images/plus.png" alt="creer" type="image" value="Créer" name="creernouvelleclasse" title="Créer une classe de molécule" style="vertical-align: middle;float:right;" class="w32">
            <input type="hidden" name="emplacementmoleculecreer" value="' . $molecule['numeroclasse'] . '"/> ' . coutEnergie(coutClasse($cout['niveauclasse'])) . '<hr/></form>';
        }
    }
    } // end else

    echo cspScriptTag() . '
        document.querySelectorAll(".max-fill-btn").forEach(function(btn) {
            btn.addEventListener("click", function() {
                var idx = parseInt(this.getAttribute("data-target-index"));
                var maxVal = this.getAttribute("data-max-value");
                document.getElementsByName("nombremolecules")[idx].value = maxVal;
            });
        });
    </script>';

    finCarte();

    debutCarte("Neutrinos" . aide('neutrinos'));
    echo nombreNeutrino($autre['neutrinos']);
    echo coutEnergie($coutNeutrino) . '<br/><br/>';
    debutListe();
    item(['titre' => 'Former', 'form' => ['armee.php', 'formerneutrino'], 'input' => '<input type="text" name="nombreneutrinos" id="nombreneutrinos" class="form-control" placeholder="Nombre de neutrinos"/>' . csrfField()]);
    item(['input' => submit(['form' => 'formerneutrino', 'titre' => 'Former'])]);
    finListe();
    finCarte();
} else {
    debutCarte("Armée" . aide("vueEnsemble"));
    debutContent();
    $moleculeOverviewRows = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? AND formule!=? ORDER BY numeroclasse', 'ss', $_SESSION['login'], "Vide");
    if (!is_array($moleculeOverviewRows)) {
        error_log("SQL error fetching army overview");
        echo "Une erreur est survenue.";
    } else {
    $nbclasse = count($moleculeOverviewRows);
    if ($nbclasse == 0 && $autre['neutrinos'] <= 0) {
        echo '<p style="text-align:center; color:#999; padding:20px;">Vous n\'avez pas encore de molécules. Rendez-vous dans Formation pour en créer !</p>';
    } else {
?>
    <div class="reponsive-table">
        <table class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th style="width:100px;"><?php echo imageLabel('<img alt="molecule" src="images/classement/molecule.png" title="Formule" class="imageSousMenu"/>', 'Formule'); ?></th>
                    <th style="width:100px;">Quantité</th>
                    <th style="width:80px;">Isotope</th>
                </tr>
            </thead>
            <tbody>
                <?php
                global $ISOTOPES;
                foreach ($moleculeOverviewRows as $molecule) {
                    $mx = $molecule['oxygene'];
                    foreach ($nomsRes as $num => $ressource) {
                        $mx = max($mx, $molecule[$ressource]);
                    }
                    foreach ($nomsRes as $num => $ressource) {
                        if ($mx == $molecule[$ressource]) {
                            $img = $ressource;
                        }
                    }

                    $isoType = intval($molecule['isotope'] ?? 0);
                    $isoName = isset($ISOTOPES[$isoType]) ? $ISOTOPES[$isoType]['name'] : 'Normal';

                    echo '<tr><td><img alt="' . $img . '" src="images/accueil/' . $img . '.png" class="imageAide2">
        <a href="molecule.php?id=' . $molecule['id'] . '" class="lienFormule">' . couleurFormule($molecule['formule']) . '</a></td>
        <td>' . number_format($molecule['nombre'], 0, ' ', ' ') . '</td>
        <td>' . htmlspecialchars($isoName) . '</td>
        </tr>';
                }

                if ($autre['neutrinos'] > 0) {
                    echo '<tr><td><img alt="neutrinos" src="images/neutrino.png" class="imageAide2">
        <span style="margin-left:8px">Neutrinos</span></td>
        <td>' . number_format($autre['neutrinos'], 0, ' ', ' ') . '</td>
        <td>—</td>
        </tr>';
                }
                ?>
            </tbody>
        </table>
    </div>
<?php
    }
    } // end else
    finContent();
    finCarte();
}
include("includes/copyright.php"); ?>