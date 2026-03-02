<?php
include("includes/basicprivatephp.php");
include("includes/redirectionVacance.php");

if (isset($_POST['emplacementmoleculesupprimer']) and !empty($_POST['emplacementmoleculesupprimer']) and preg_match("#^[0-9]*$#", $_POST['emplacementmoleculesupprimer']) and $_POST['emplacementmoleculesupprimer'] <= MAX_MOLECULE_CLASSES and $_POST['emplacementmoleculesupprimer'] >= 1) { // FIX FINDING-GAME-022: was <= 5
    csrfCheck();
    $molecules = dbFetchOne($base, 'SELECT formule,id FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $_SESSION['login'], $_POST['emplacementmoleculesupprimer']);

    if ($molecules['formule'] != "Vide") {
        $niveauclasse = dbFetchOne($base, 'SELECT niveauclasse FROM ressources WHERE login=?', 's', $_SESSION['login']);
        $newNiveauClasse = $niveauclasse['niveauclasse'] - 1;
        dbExecute($base, 'UPDATE ressources SET niveauclasse=? WHERE login=?', 'is', $newNiveauClasse, $_SESSION['login']);

        $chaine = ""; // on passe toutes les chaines sauf conditions pour les ressources en dynamique
        foreach ($nomsRes as $num => $ressource) {
            $plus = "";
            if ($num < $nbRes) {
                $plus = ",";
            }
            $chaine = $chaine . '' . $ressource . '=default' . $plus;
        }

        // $chaine contains only server-side column names with 'default' keyword, not user input
        dbExecute($base, 'UPDATE molecules SET formule = default, ' . $chaine . ', nombre = default WHERE proprietaire=? AND numeroclasse=?', 'si', $_SESSION['login'], $_POST['emplacementmoleculesupprimer']);

        dbExecute($base, 'DELETE FROM actionsformation WHERE login=? AND idclasse=?', 'si', $_SESSION['login'], $molecules['id']);
        $nvxDebut = time();
        $exActuActions = dbQuery($base, 'SELECT * FROM actionsformation WHERE login=?', 's', $_SESSION['login']);
        while ($actionsformation = mysqli_fetch_array($exActuActions)) {
            if (time() < $actionsformation['debut']) {
                $newFin = $nvxDebut + $actionsformation['nombreRestant'] * $actionsformation['tempsPourUn'];
                dbExecute($base, 'UPDATE actionsformation SET debut=?, fin=? WHERE id=?', 'iii', $nvxDebut, $newFin, $actionsformation['id']);
                $nvxDebut = $nvxDebut + $actionsformation['nombreRestant'] * $actionsformation['tempsPourUn'];
            } else {
                $nvxDebut = $actionsformation['fin'];
            }
        }

        // on enleve ces types de molécules dans les attaques lancées
        $ex = dbQuery($base, 'SELECT * FROM actionsattaques WHERE attaquant=?', 's', $_SESSION['login']);
        while ($actionsattaques = mysqli_fetch_array($ex)) {
            $explosion = explode(";", $actionsattaques['troupes']);
            $chaine = "";
            for ($i = 1; $i <= $nbClasses; $i++) {
                if ($i == $_POST['emplacementmoleculesupprimer']) {
                    $chaine .= "0;";
                } else {
                    $chaine .= $explosion[$i - 1] . ";";
                }
            }

            dbExecute($base, 'UPDATE actionsattaques SET troupes=? WHERE id=?', 'si', $chaine, $actionsattaques['id']);
        }

        $information = "Vous avez supprimé la classe de molécules.";
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
        if ($_POST['nombreneutrinos'] * $coutNeutrino <= $ressources['energie']) {

            $newNeutrinos = $autre['neutrinos'] + $_POST['nombreneutrinos'];
            dbExecute($base, 'UPDATE autre SET neutrinos=? WHERE login=?', 'is', $newNeutrinos, $_SESSION['login']);
            $autre['neutrinos'] += $_POST['nombreneutrinos'];

            $newEnergie = $ressources['energie'] - $_POST['nombreneutrinos'] * $coutNeutrino;
            dbExecute($base, 'UPDATE ressources SET energie=? WHERE login=?', 'ds', $newEnergie, $_SESSION['login']);
            $newEnergieDepensee = $autre['energieDepensee'] + $_POST['nombreneutrinos'] * $coutNeutrino;
            dbExecute($base, 'UPDATE autre SET energieDepensee=? WHERE login=?', 'ds', $newEnergieDepensee, $_SESSION['login']);

            $information = 'Vous avez formé ' . $_POST['nombreneutrinos'] . ' neutrinos.';
        } else {
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
        $donneesFormer = dbFetchOne($base, 'SELECT * FROM molecules WHERE proprietaire=? AND numeroclasse=?', 'si', $_SESSION['login'], $_POST['emplacementmoleculeformer']);
        $ressources = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $_SESSION['login']);

        $bool = 1;
        foreach ($nomsRes as $num => $ressource) {
            if (($donneesFormer[$ressource] * $_POST['nombremolecules']) > $ressources[$ressource]) {
                $bool = 0;
            }
        }
        if ($bool == 1) {
            $total = 0;
            foreach ($nomsRes as $num => $ressource) {
                $total = $total + $donneesFormer[$ressource];
            }

            $ex = dbQuery($base, 'SELECT * FROM actionsformation WHERE login=? ORDER BY fin DESC', 's', $_SESSION['login']);
            $nb = mysqli_num_rows($ex);
            if ($nb > 0) { // s'il y a deja quelque chose en cours, on le met derriere
                $actionsformation = mysqli_fetch_array($ex);
                $tempsDebut = $actionsformation['fin'];
            } else {
                $tempsDebut = time();
            }

            $tempsForm = tempsFormation($donneesFormer['azote'], $niveauazote, $total, $_SESSION['login']);
            $finTemps = $tempsDebut + $tempsForm * $_POST['nombremolecules'];
            dbExecute($base, 'INSERT INTO actionsformation VALUES(default,?,?,?,?,?,?,?,?)', 'issiiisis',
                $donneesFormer['id'], $_SESSION['login'], $tempsDebut, $finTemps, $_POST['nombremolecules'], $_POST['nombremolecules'], $donneesFormer['formule'], $tempsForm);

            // Build dynamic UPDATE for ressources - computed from server data
            $chaine = "";
            foreach ($nomsRes as $num => $ressource) {
                $plus = "";
                if ($num < $nbRes) {
                    $plus = ",";
                }
                $chaine = $chaine . '' . $ressource . '=' . ($ressources[$ressource] - ($_POST['nombremolecules'] * $donneesFormer[$ressource])) . '' . $plus;
            }
            // $chaine is built from server-side computed numeric values
            $stmt = mysqli_prepare($base, 'UPDATE ressources SET ' . $chaine . ' WHERE login=?');
            if (!$stmt) {
                error_log("SQL Prepare Error: " . mysqli_error($base));
            } else {
                mysqli_stmt_bind_param($stmt, 's', $_SESSION['login']);
                if (!mysqli_stmt_execute($stmt)) {
                    error_log("SQL Execute Error: " . mysqli_stmt_error($stmt));
                }
                mysqli_stmt_close($stmt);
            }

            $information = 'Vous avez lancé la formation de ' . $_POST['nombremolecules'] . ' molécules de ' . couleurFormule($donneesFormer['formule']) . '';
        } else {
            $erreur = "Vous n'avez pas assez d'atomes.";
        }
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
            if ($_POST[$ressource] > 200) {
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
                $cout = dbFetchOne($base, 'SELECT energie, niveauclasse FROM ressources WHERE login=?', 's', $_SESSION['login']);
                if ($cout['energie'] >= (coutClasse($cout['niveauclasse']))) {
                    $formule = "";
                    foreach ($nomsRes as $num => $ressource) {
                        if (!empty($_POST[$ressource])) {
                            $$ressource = $_POST[$ressource];
                            if ($_POST[$ressource] > 1) {
                                $formule = '' . $formule . '' . $lettre[$num] . '<sub>' . $_POST[$ressource] . '</sub>';
                            } else {
                                $formule = $formule . '' . $lettre[$num];
                            }
                        } else {
                            $$ressource = 0;
                        }
                    }

                    $newNiveauClasse = $cout['niveauclasse'] + 1;
                    dbExecute($base, 'UPDATE ressources SET niveauclasse=? WHERE login=?', 'is', $newNiveauClasse, $_SESSION['login']);

                    // Isotope selection (validated integer 0-3)
                    $isotope = isset($_POST['isotope']) ? intval($_POST['isotope']) : 0;
                    if ($isotope < 0 || $isotope > 3) $isotope = 0;

                    // Build dynamic UPDATE for molecules - computed from validated integer POST values
                    $chaine = "";
                    foreach ($nomsRes as $num => $ressource) {
                        $plus = "";
                        if ($num < $nbRes) {
                            $plus = ",";
                        }
                        $chaine = $chaine . '' . $ressource . '=' . intval($$ressource) . '' . $plus;
                    }
                    // $chaine has validated integers, formule and login are parameterized
                    $stmt = mysqli_prepare($base, 'UPDATE molecules SET ' . $chaine . ', formule=?, isotope=' . $isotope . ' WHERE proprietaire=? AND numeroclasse=?');
                    if (!$stmt) {
                        error_log("SQL Prepare Error: " . mysqli_error($base));
                    } else {
                        $numClasse = intval($_POST['emplacementmoleculecreer1']);
                        mysqli_stmt_bind_param($stmt, 'ssi', $formule, $_SESSION['login'], $numClasse);
                        if (!mysqli_stmt_execute($stmt)) {
                            error_log("SQL Execute Error: " . mysqli_stmt_error($stmt));
                        }
                        mysqli_stmt_close($stmt);
                    }

                    $newEnergie = $cout['energie'] - coutClasse($cout['niveauclasse']);
                    dbExecute($base, 'UPDATE ressources SET energie=? WHERE login=?', 'ds', $newEnergie, $_SESSION['login']);

                    $information = "Une nouvelle classe de molécule a été créée.";
                } else {
                    $erreur = "Vous n'avez pas assez d'energie.";
                }
            } else {
                $erreur = "Votre molécule doit au moins être composée d'un atome.";
            }
        } else {
            $erreur = "Les molécules ne doivent pas excéder 200 atomes de chaque.";
        }
    } else {
        $erreur = "Seul des nombres positifs et entiers doivent être entrés.";
    }
}

include("includes/tout.php");

$ex = dbQuery($base, 'SELECT * FROM actionsformation WHERE login=? ORDER BY debut ASC', 's', $_SESSION['login']);
$nb = mysqli_num_rows($ex);
if ($nb > 0) {
    debutCarte();
    scriptAffichageTemps();
    echo '<div class="table-responsive"><table>';
    echo '<tr><th>Molécule</th><th>Prochaine</th><th>Total</th></tr>';

    $c = 0;
    while ($actionsformation = mysqli_fetch_array($ex)) {
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
        <script>
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
        item(['titre' => nombreAtome($num, 'Nombre ' . pref($ressource) . '<strong>' . $nomsAccents[$num] . '</strong>') . aide($ressource, true), 'input' => '<input type="number" name="' . $ressource . '" id="' . $ressource . '" placeholder="' . $utilite[$num] . '" class="form-control" oninput="javascript:actualiserStats()"/>']);
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

if (!isset($_GET['sub']) || $_GET['sub'] == 0) {
    debutCarte('Molécule ' . aide('armee'));
    $ex = dbQuery($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse', 's', $_SESSION['login']);
    if (!$ex) {
        error_log("SQL error fetching molecules");
        echo "Une erreur est survenue.";
    } else {
    $nbclasse = mysqli_num_rows($ex);

    $ressources = dbFetchOne($base, 'SELECT * FROM ressources WHERE login=?', 's', $_SESSION['login']);
    $compteur = 0;
    while ($molecule = mysqli_fetch_array($ex)) {
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
            item(['titre' => 'Former', 'form' => ['armee.php', 'formermolecule' . $molecule['numeroclasse']], 'input' => '<input type="text" name="nombremolecules" id="nombremolecules" class="form-control" placeholder="Nombre de molécules"/><input type="hidden" name="emplacementmoleculeformer" value="' . $molecule['numeroclasse'] . '"/>' . csrfField(), 'after' => '<a name="generer" id="generer" onclick="javascript:document.getElementsByName(\'nombremolecules\')[' . $compteur . '].value = ' . $nbmoleculesMax . ';" value="Générer" class="button button-raised button-fill" style="margin-right:5px">Max : ' . chiffrePetit($nbmoleculesMax, 0) . '</a>']);
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
    $ex = dbQuery($base, 'SELECT * FROM molecules WHERE proprietaire=? AND formule!=? ORDER BY numeroclasse', 'ss', $_SESSION['login'], "Vide");
    if (!$ex) {
        error_log("SQL error fetching army overview");
        echo "Une erreur est survenue.";
    } else {
    $nbclasse = mysqli_num_rows($ex);
?>
    <div class="reponsive-table">
        <table class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th style="width:100px;"><?php echo imageLabel('<img alt="molecule" src="images/classement/molecule.png" title="Formule" class="imageSousMenu"/>', 'Formule'); ?></th>
                    <th style="width:100px;">Quantité</th>
                </tr>
            </thead>
            <tbody>
                <?php
                while ($molecule = mysqli_fetch_array($ex)) {
                    $mx = $molecule['oxygene'];
                    foreach ($nomsRes as $num => $ressource) {
                        $mx = max($mx, $molecule[$ressource]);
                    }
                    foreach ($nomsRes as $num => $ressource) {
                        if ($mx == $molecule[$ressource]) {
                            $img = $ressource;
                        }
                    }

                    echo '<tr><td><img alt="' . $img . '" src="images/accueil/' . $img . '.png" class="imageAide2">
        <a href="molecule.php?id=' . $molecule['id'] . '" class="lienFormule">' . couleurFormule($molecule['formule']) . '</a></td>
        <td>' . number_format($molecule['nombre'], 0, ' ', ' ') . '</td>
        </tr>';
                }

                if ($autre['neutrinos'] > 0) {
                    echo '<tr><td><img alt="neutrinos" src="images/neutrino.png" class="imageAide2">
        <span style="margin-left:8px">Neutrinos</span></td>
        <td>' . number_format($autre['neutrinos'], 0, ' ', ' ') . '</td>
        </tr>';
                }
                ?>
            </tbody>
        </table>
    </div>
<?php
    } // end else
    finContent();
    finCarte();
}
include("includes/copyright.php"); ?>