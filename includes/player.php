<?php
/**
 * Player Management Module
 * Player status, registration, initialization, buildings, alliances, etc.
 */

function statut($joueur)
{
    global $base;
    $actifs = dbFetchOne($base, 'SELECT count(*) AS nb FROM membre WHERE derniereConnexion >= ? AND x!=-1000 AND login=?', 'is', (time() - 2678400), $joueur);

    if ($actifs['nb'] == 1) {
        return 1;
    } else {
        return 0;
    }
}

function compterActifs()
{
    global $base;
    $nb = dbFetchOne($base, 'SELECT count(*) AS nb FROM membre WHERE derniereConnexion >= ? AND x!=-1000', 'i', (time() - 2678400));

    return $nb['nb'];
}

function inscrire($pseudo, $mdp, $mail)
{
    global $base;
    $data1 = dbFetchOne($base, 'SELECT inscrits FROM statistiques');
    $nbinscrits = $data1['inscrits'] + 1;

    $alea = mt_rand(1, 200);
    if ($alea <= 100) {
        $alea = 0;
    } elseif ($alea > 100 && $alea <= 150) {
        $alea = 1;
    } elseif ($alea > 150 && $alea <= 175) {
        $alea = 2;
    } elseif ($alea > 175 && $alea <= 187) {
        $alea = 3;
    } elseif ($alea > 187 && $alea <= 193) {
        $alea = 4;
    } elseif ($alea > 193 && $alea <= 197) {
        $alea = 5;
    } elseif ($alea > 197 && $alea <= 199) {
        $alea = 6;
    } else {
        $alea = 7;
    }

    $safePseudo = antihtml(trim($pseudo));
    $safeMail = antihtml(trim($mail));
    $hashedPassword = password_hash($mdp, PASSWORD_DEFAULT);
    $now = time();
    $timestamps = $now . ',' . $now . ',' . $now . ',' . $now;
    $vieGen = pointsDeVie(1);
    $vieCDF = vieChampDeForce(0);

    dbExecute($base, 'INSERT INTO membre VALUES(default, ?, ?, ?, ?, ?, 0, ?, 0, 0, ?,-1000,-1000)', 'sssisis', $safePseudo, $hashedPassword, $now, $_SERVER['REMOTE_ADDR'], $now, $alea, $safeMail);
    dbExecute($base, 'INSERT INTO autre VALUES(?, default, default, "Pas de description", ?, default, default, default, default, default, default, default, default,default,default,?,default,default,default,default,"",default)', 'sis', $safePseudo, $now, $timestamps);
    dbExecute($base, 'INSERT INTO ressources VALUES(default,?, default, default, default, default, default, default, default, default, default, default, default, default, default, default, default, default, default, default, default, default)', 's', $safePseudo);
    dbExecute($base, 'UPDATE statistiques SET inscrits=?', 'i', $nbinscrits);
    dbExecute($base, 'INSERT INTO molecules VALUES(default, default, default, default, default, default,default, default, default, default, 1, ?, default),
	(default, default, default, default, default, default,default, default, default, default, 2, ?, default),
	(default, default, default, default, default, default,default, default, default, default, 3, ?, default),
	(default , default, default, default, default, default,default, default, default, default, 4, ?, default)', 'ssss', $safePseudo, $safePseudo, $safePseudo, $safePseudo);
    dbExecute($base, 'INSERT INTO constructions VALUES(?, default, default, default, default, default, default, default, default, ?, ?, ?,?,default,default,default,default)', 'sdddd', $safePseudo, $vieGen, $vieCDF, $vieGen, $vieGen);
}

function ajouterPoints($nb, $joueur, $type = 0)
{
    global $base;
    $points = dbFetchOne($base, 'SELECT * FROM autre WHERE login=?', 's', $joueur);

    if ($type == 0) {
        // points de constructions
        if ($points['points'] + $nb >= 0) {
            dbExecute($base, 'UPDATE autre SET points=?, totalPoints=? WHERE login=?', 'dds', ($points['points'] + $nb), ($points['totalPoints'] + $nb), $joueur);
            return $nb;
        }
    }
    if ($type == 1) {
        // points d'attaque
        dbExecute($base, 'UPDATE autre SET pointsAttaque=?, totalPoints=? WHERE login=?', 'dds', ($points['pointsAttaque'] + $nb), ($points['totalPoints'] - pointsAttaque($points['pointsAttaque']) + pointsAttaque($points['pointsAttaque'] + $nb)), $joueur);
        return -pointsAttaque($points['pointsAttaque']) + pointsAttaque($points['pointsAttaque'] + $nb);
    }
    if ($type == 2) {
        // points de defense
        dbExecute($base, 'UPDATE autre SET pointsDefense=?, totalPoints=? WHERE login=?', 'dds', ($points['pointsDefense'] + $nb), ($points['totalPoints'] - pointsDefense($points['pointsDefense']) + pointsDefense($points['pointsDefense'] + $nb)), $joueur);
        return -pointsDefense($points['pointsDefense']) + pointsDefense($points['pointsDefense'] + $nb);
    }
    if ($type == 3) {
        // points de pillage
        dbExecute($base, 'UPDATE autre SET ressourcesPillees=? WHERE login=?', 'ds', ($points['ressourcesPillees'] + $nb), $joueur);
        return chiffrePetit($nb, 0);
    }
}

function initPlayer($joueur)
{
    global $nomsRes;
    global $base;
    global $paliersConstructeur;
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

    $points = ['condenseur' => 3, 'producteur' => sizeof($nomsRes)];

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
        $max = max($max, 3600 * ($placeDepot - $ressources[$ressource]) / $revenu[$ressource]);
    }

    $production = '<strong><span id="nbPointsRestants">' . $constructions['pointsProducteurRestants'] . '</span> points</strong> à placer<br/><form method="post" action="constructions.php" name="formPointsProducteur">';
    revenuAtomeJavascript($joueur); // ecrit la fonction en javscript qui donne la production pour un nombre de points
    foreach ($nomsRes as $num => $ressource) {
        $production = $production . nombreAtome($num, '<span style="color:green">+<span id="nbPointsAffichage' . $ressource . '">' . $revenu[$ressource] . '</span>/h</span> <input type="hidden" value="0" id="nbPoints' . $ressource . '" name="nbPoints' . $ressource . '"/><a href="#"><img class="imageAide" src="images/add.png" alt="add" style="margin-left:10px" id="add' . $ressource . '"/></a>');

        $production = $production . '
        <script>
            document.getElementById("add' . $ressource . '").addEventListener("click",function(){
                var pointsRestants = parseInt(document.getElementById("nbPointsRestants").innerHTML);
                if(pointsRestants > 0){
                    document.getElementById("nbPointsRestants").innerHTML = pointsRestants-1;
                    document.getElementById("nbPoints' . $ressource . '").value++;
                    document.getElementById("nbPointsAffichage' . $ressource . '").innerHTML = revenuAtomeJavascript(parseInt(document.getElementById("nbPoints' . $ressource . '").value)+parseInt(' . ${'points' . $ressource} . '));
                }
            });
        </script>';
    }
    $production = $production . '<br/><br/><center><input type="image" class="w32" style="margin-right:20px" src="images/yes.png" name="actioninvitation" value="Sauvegarder"/></form><a href="constructions.php"><img src="images/croix.png" class="w32" style="margin-left:20px"  alt="Ne pas sauvegarder"/></a></center>';

    $bonusDuplicateur = 1;
    if ($autre['idalliance'] > 0) {
        $duplicateur = dbFetchOne($base, 'SELECT duplicateur FROM alliances WHERE id=?', 'i', $autre['idalliance']);
        $bonusDuplicateur = 1 + ($duplicateur['duplicateur'] / 100);
    }

    $productionCondenseur = '<strong><span id="nbPointsCondenseurRestants">' . $constructions['pointsCondenseurRestants'] . '</span> points</strong> à placer<br/><form method="post" action="constructions.php" name="formPointsCondenseur">';
    foreach ($nomsRes as $num => $ressource) {
        $productionCondenseur = $productionCondenseur . nombreAtome($num, 'Niveau <span id="nbPointsCondenseurAffichage' . $ressource . '">' . ${'niveau' . $ressource} . '</span><input type="hidden" value="0" id="nbPointsCondenseur' . $ressource . '" name="nbPointsCondenseur' . $ressource . '"/><a href="#"><img class="imageAide" src="images/add.png" alt="add" style="margin-left:10px" id="addCondenseur' . $ressource . '"/></a>');

        $productionCondenseur = $productionCondenseur . '
        <script>
            document.getElementById("addCondenseur' . $ressource . '").addEventListener("click",function(){
                var pointsRestants = parseInt(document.getElementById("nbPointsCondenseurRestants").innerHTML);
                if(pointsRestants > 0){
                    document.getElementById("nbPointsCondenseurRestants").innerHTML = pointsRestants-1;
                    document.getElementById("nbPointsCondenseur' . $ressource . '").value++;
                    document.getElementById("nbPointsCondenseurAffichage' . $ressource . '").innerHTML=parseInt(document.getElementById("nbPointsCondenseur' . $ressource . '").value)+parseInt(' . ${'niveau' . $ressource} . ');
                }
            });
        </script>';
    }
    $productionCondenseur = $productionCondenseur . '<br/><br/><center><input type="image" class="w32" style="margin-right:20px" src="images/yes.png" name="actioninvitation" value="Sauvegarder"/></form><a href="constructions.php"><img src="images/croix.png" class="w32" style="margin-left:20px"  alt="Ne pas sauvegarder"/></a></center>';


    /////////////////////////////////////////

    $exNiveauActuel = dbQuery($base, 'SELECT niveau FROM actionsconstruction WHERE login=? AND batiment=? ORDER BY niveau DESC', 'ss', $joueur, 'generateur');
    $niveauActuel = mysqli_fetch_array($exNiveauActuel);
    $nb = mysqli_num_rows($exNiveauActuel);
    if ($nb == 0) {
        $niveauActuel['niveau'] = $constructions['generateur'];
    }

    if ($niveauActuel['niveau'] == 1) {
        $tempsGenerateur = 10;
    } else {
        $tempsGenerateur = round(60 * pow($niveauActuel['niveau'], 1.5));
    }


    $exNiveauActuel1 = dbQuery($base, 'SELECT niveau FROM actionsconstruction WHERE login=? AND batiment=? ORDER BY niveau DESC', 'ss', $joueur, 'producteur');
    $niveauActuel1 = mysqli_fetch_array($exNiveauActuel1);
    $nb1 = mysqli_num_rows($exNiveauActuel1);
    if ($nb1 == 0) {
        $niveauActuel1['niveau'] = $constructions['producteur'];
    }

    if ($niveauActuel1['niveau'] == 1) {
        $tempsProducteur = 10;
    } else {
        $tempsProducteur = round(40 * pow($niveauActuel1['niveau'], 1.5));
    }

    $exNiveauActuel1 = dbQuery($base, 'SELECT niveau FROM actionsconstruction WHERE login=? AND batiment=? ORDER BY niveau DESC', 'ss', $joueur, 'depot');
    $niveauActuelDepot = mysqli_fetch_array($exNiveauActuel1);
    $nb = mysqli_num_rows($exNiveauActuel1);
    if ($nb == 0) {
        $niveauActuelDepot['niveau'] = $constructions['depot'];
    }
    $exNiveauActuel1 = dbQuery($base, 'SELECT niveau FROM actionsconstruction WHERE login=? AND batiment=? ORDER BY niveau DESC', 'ss', $joueur, 'champdeforce');
    $niveauActuelChampDeForce = mysqli_fetch_array($exNiveauActuel1);
    $nb = mysqli_num_rows($exNiveauActuel1);
    if ($nb == 0) {
        $niveauActuelChampDeForce['niveau'] = $constructions['champdeforce'];
    }
    $exNiveauActuel1 = dbQuery($base, 'SELECT niveau FROM actionsconstruction WHERE login=? AND batiment=? ORDER BY niveau DESC', 'ss', $joueur, 'ionisateur');
    $niveauActuelIonisateur = mysqli_fetch_array($exNiveauActuel1);
    $nb = mysqli_num_rows($exNiveauActuel1);
    if ($nb == 0) {
        $niveauActuelIonisateur['niveau'] = $constructions['ionisateur'];
    }
    $exNiveauActuel1 = dbQuery($base, 'SELECT niveau FROM actionsconstruction WHERE login=? AND batiment=? ORDER BY niveau DESC', 'ss', $joueur, 'condenseur');
    $niveauActuelCondenseur = mysqli_fetch_array($exNiveauActuel1);
    $nb = mysqli_num_rows($exNiveauActuel1);
    if ($nb == 0) {
        $niveauActuelCondenseur['niveau'] = $constructions['condenseur'];
    }
    $exNiveauActuel1 = dbQuery($base, 'SELECT niveau FROM actionsconstruction WHERE login=? AND batiment=? ORDER BY niveau DESC', 'ss', $joueur, 'lieur');
    $niveauActuelLieur = mysqli_fetch_array($exNiveauActuel1);
    $nb = mysqli_num_rows($exNiveauActuel1);
    if ($nb == 0) {
        $niveauActuelLieur['niveau'] = $constructions['lieur'];
    }
    $exNiveauActuel1 = dbQuery($base, 'SELECT niveau FROM actionsconstruction WHERE login=? AND batiment=? ORDER BY niveau DESC', 'ss', $joueur, 'stabilisateur');
    $niveauActuelStabilisateur = mysqli_fetch_array($exNiveauActuel1);
    $nb = mysqli_num_rows($exNiveauActuel1);
    if ($nb == 0) {
        $niveauActuelStabilisateur['niveau'] = $constructions['stabilisateur'];
    }

    $listeConstructions = [
        'generateur' => [
            'titre' => 'Générateur',
            'bdd' => 'generateur',
            'image' => 'images/batiments/generateur.png',
            'progressBar' => true,
            'niveau' => $constructions['generateur'],
            'revenu' => nombreEnergie('<span style="color:green" >+' . chiffrePetit(revenuEnergie($constructions['generateur'], $joueur, 4)) . '/h</span>'),
            'revenu1' => nombreEnergie('<span style="color:green" >+' . chiffrePetit(revenuEnergie($niveauActuel['niveau'] + 1, $joueur, 4)) . '/h</span>'),
            'effetSup' => '<br/><br/><strong>Stockage plein : </strong>' . date('d/m/Y', time() + 3600 * ($placeDepot - $ressources['energie']) / $revenu['energie']) . ' à ' . date('H\hi', time() + 3600 * ($placeDepot - $ressources['energie']) / $revenuEnergie),
            'description' => 'Le générateur <strong>produit de l\'énergie</strong>.',
            'coutEnergie' => round((1 - ($bonus / 100)) * 50 * pow($niveauActuel['niveau'], 0.4)),
            'coutAtomes' => round((1 - ($bonus / 100)) * 75 * pow($niveauActuel['niveau'], 0.4)),
            'points' => 1 + floor($niveauActuel['niveau'] * 0.1),
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
            'coutEnergie' => round((1 - ($bonus / 100)) * 75 * pow($niveauActuel1['niveau'], 0.4)),
            'coutAtomes' => round((1 - ($bonus / 100)) * 50 * pow($niveauActuel1['niveau'], 0.4)),
            'drainage' => drainageProducteur($niveauActuel1['niveau'] + 1),
            'points' => 1 + floor($niveauActuel1['niveau'] * 0.1),
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
            'revenu' => chiffrePetit($placeDepot) . ' ressources max',
            'revenu1' => chiffrePetit(placeDepot($niveauActuelDepot['niveau'] + 1)) . ' ressources max',
            'effetSup' => '',
            'description' => 'Le stockage est l\'endroit où sont stockés les ressources. Il détermine donc <strong>la quantité maximale de ressources</strong> que l\'on peut avoir.',
            'coutEnergie' => round((1 - ($bonus / 100)) * 100 * pow($niveauActuelDepot['niveau'], 0.4)),
            'points' => 1 + floor($niveauActuelDepot['niveau'] * 0.1),
            'tempsConstruction' => round(80 * pow($niveauActuelDepot['niveau'], 1.5)),
            'vie' => $constructions['vieDepot'],
            'vieMax' => pointsDeVie($constructions['depot'])
        ],


        'champdeforce' => [
            'titre' => 'Champ de force',
            'bdd' => 'champdeforce',
            'image' => 'images/batiments/champdeforce.png',
            'progressBar' => true,
            'niveau' => $constructions['champdeforce'],
            'revenu' => chip('+' . floor($bonusDuplicateur * $constructions['champdeforce'] * 2) . '%', '<img src="images/batiments/shield.png" alt="shield" style="border-radius:0px;height:20px;width:20px" />', "white", "", true),
            'revenu1' => chip('+' . floor($bonusDuplicateur * ($niveauActuelChampDeForce['niveau'] + 1) * 2) . '%', '<img src="images/batiments/shield.png" alt="shield" style="border-radius:0px;height:20px;width:20px" />', "white", "", true),
            'effetSup' => '',
            'description' => 'Le champ de force vous protège des attaques adverses en donnant un <strong>bonus de défense</strong> lorsque vous êtes attaqué. Il prend aussi <strong>les dégâts des attaques adverses</strong> en premier si son niveau est superieur aux autres bâtiments.',
            'coutCarbone' => round((1 - ($bonus / 100)) * 100 * pow($niveauActuelChampDeForce['niveau'], 0.4)),
            'points' => 1 + floor($niveauActuelChampDeForce['niveau'] * 0.075),
            'tempsConstruction' => round(20 * pow($niveauActuelChampDeForce['niveau'] + 2, 1.7)),
            'vie' => $constructions['vieChampdeforce'],
            'vieMax' => vieChampDeForce($constructions['champdeforce'])
        ],

        'ionisateur' => [
            'titre' => 'Ionisateur',
            'bdd' => 'ionisateur',
            'image' => 'images/batiments/ionisateur.png',
            'progressBar' => false,
            'niveau' => $constructions['ionisateur'],
            'revenu' => chip('+' . floor($bonusDuplicateur * $constructions['ionisateur'] * 2) . '%', '<img src="images/batiments/sword.png" alt="shield" style="border-radius:0px;height:20px;width:20px" />', "white", "", true),
            'revenu1' => chip('+' . floor($bonusDuplicateur * ($niveauActuelIonisateur['niveau'] + 1) * 2) . '%', '<img src="images/batiments/sword.png" alt="shield" style="border-radius:0px;height:20px;width:20px" />', "white", "", true),
            'effetSup' => '',
            'description' => 'L\'ionisateur améliore votre capacité offensive en donnant un <strong>bonus d\'attaque</strong> à vos molécules.',
            'coutOxygene' => round((1 - ($bonus / 100)) * 100 * pow($niveauActuelIonisateur['niveau'], 0.4)),
            'points' => 1 + floor($niveauActuelIonisateur['niveau'] * 0.075),
            'tempsConstruction' => round(20 * pow($niveauActuelIonisateur['niveau'] + 2, 1.7))
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
            'coutEnergie' => round((1 - ($bonus / 100)) * 25 * pow($niveauActuelCondenseur['niveau'], 0.6)),
            'coutAtomes' => round((1 - ($bonus / 100)) * 100 * pow($niveauActuelCondenseur['niveau'], 0.6)),
            'points' => 2 + floor($niveauActuelCondenseur['niveau'] * 0.1),
            'tempsConstruction' => round(120 * pow($niveauActuelCondenseur['niveau'] + 1, 1.6))
        ],

        'lieur' => [
            'titre' => 'Lieur',
            'bdd' => 'lieur',
            'image' => 'images/batiments/lieur.png',
            'progressBar' => false,
            'niveau' => $constructions['lieur'],
            'revenu' => chip('-' . floor((bonusLieur($constructions['lieur']) - 1) * 100) . '%', '<img src="images/batiments/tempsMolecule.png" alt="tpsMol" style="border-radius:0px;width:22px;height:22px"/>', "white", "", true),
            'revenu1' => chip('-' . floor((bonusLieur($niveauActuelLieur['niveau'] + 1) - 1) * 100) . '%', '<img src="images/batiments/tempsMolecule.png" alt="tpsMol" style="border-radius:0px;width:22px;height:22px"/>', "white", "", true),
            'effetSup' => '',
            'description' => 'Le lieur forme des liaisons entre atomes afin de créer des molécules. Il permet ainsi de <strong>réduire le temps de formation des molécules</strong> de votre armée.',
            'coutAzote' => round((1 - ($bonus / 100)) * 100 * pow($niveauActuelLieur['niveau'], 0.6)),
            'points' => 2 + floor($niveauActuelLieur['niveau'] * 0.1),
            'tempsConstruction' => round(100 * pow($niveauActuelLieur['niveau'] + 1, 1.5))
        ],


        'stabilisateur' => [
            'titre' => 'Stabilisateur',
            'bdd' => 'stabilisateur',
            'image' => 'images/batiments/stabilisateur.png',
            'progressBar' => false,
            'niveau' => $constructions['stabilisateur'],
            'revenu' => ($constructions['stabilisateur'] * 0.5) . '% de réduction des chances de disparition des molécules',
            'revenu1' => (($niveauActuelStabilisateur['niveau'] + 1) * 0.5) . '% de réduction des chances de disparition des molécules',
            'effetSup' => '',
            'description' => 'Le stabilisateur permet à vos <strong>molécules d\'être plus stables</strong>, c\'est à dire qu\'elles disparaitront au bout d\'un temps plus long',
            'coutAtomes' => round((1 - ($bonus / 100)) * 75 * pow($niveauActuelStabilisateur['niveau'], 0.8)),
            'points' => 3 + floor($niveauActuelStabilisateur['niveau'] * 0.1),
            'tempsConstruction' => round(120 * pow($niveauActuelStabilisateur['niveau'] + 1, 1.5))
        ]
    ];
}

function augmenterBatiment($nom, $joueur)
{ // BUG listeconstructions
    global $base;
    global $listeConstructions;
    global $points;
    initPlayer($joueur);

    $batiments = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $joueur);

    if ($nom == 'producteur') {
        dbExecute($base, 'UPDATE constructions SET pointsProducteurRestants=? WHERE login=?', 'is', ($batiments['pointsProducteurRestants'] + $points['producteur']), $joueur);
    }
    if ($nom == 'condenseur') {
        dbExecute($base, 'UPDATE constructions SET pointsCondenseurRestants=? WHERE login=?', 'is', ($batiments['pointsCondenseurRestants'] + $points['condenseur']), $joueur);
    }

    if ($nom == "champdeforce" || $nom == "generateur" || $nom == "producteur" || $nom == "depot") {
        $vieCol = 'vie' . ucfirst($nom);
        if ($nom == "champdeforce") {
            $vieVal = vieChampDeForce($batiments[$nom] + 1);
        } else {
            $vieVal = pointsDeVie($batiments[$nom] + 1);
        }
        dbExecute($base, "UPDATE constructions SET $nom=?, $vieCol=? WHERE login=?", 'ids', ($batiments[$nom] + 1), $vieVal, $joueur);
    } else {
        dbExecute($base, "UPDATE constructions SET $nom=? WHERE login=?", 'is', ($batiments[$nom] + 1), $joueur);
    }
    ajouterPoints($listeConstructions[$nom]['points'], $joueur);

    initPlayer($_SESSION['login']);
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

    initPlayer($joueur);

    global $base;
    $batiments = dbFetchOne($base, "SELECT $nom FROM constructions WHERE login=?", 's', $joueur);

    if ($batiments[$nom] > 1) {
        if ($nom == 'producteur') {
            if ($constructions['pointsProducteurRestants'] >= $points['producteur']) {
                dbExecute($base, 'UPDATE constructions SET pointsProducteurRestants=? WHERE login=?', 'is', ($constructions['pointsProducteurRestants'] - $points['producteur']), $joueur);
            } else {
                $pointsAEnlever = $points['producteur'] - $constructions['pointsProducteurRestants'];
                dbExecute($base, 'UPDATE constructions SET pointsProducteurRestants=0 WHERE login=?', 's', $joueur);

                $chaine = "";
                foreach ($nomsRes as $num => $ressource) {
                    if ($pointsAEnlever <= ${'points' . $ressource} - 1) {
                        $chaine = $chaine . (${'points' . $ressource} - $pointsAEnlever) . ";";
                        $pointsAEnlever = 0;
                    } else {
                        $chaine = $chaine . "1;";
                        $pointsAEnlever = $pointsAEnlever - (${'points' . $ressource} - 1);
                    }
                }

                dbExecute($base, 'UPDATE constructions SET pointsProducteur=? WHERE login=?', 'ss', $chaine, $joueur);
            }
        }
        if ($nom == 'condenseur') {
            if ($constructions['pointsCondenseurRestants'] >= $points['condenseur']) {
                dbExecute($base, 'UPDATE constructions SET pointsCondenseurRestants=? WHERE login=?', 'is', ($constructions['pointsCondenseurRestants'] - $points['condenseur']), $joueur);
            } else {
                dbExecute($base, 'UPDATE constructions SET pointsCondenseurRestants=0 WHERE login=?', 's', $joueur);
                $pointsAEnlever = $points['condenseur'] - $constructions['pointsCondenseurRestants'];

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

        if ($nom == "champdeforce" || $nom == "generateur" || $nom == "producteur" || $nom == "depot") {
            $vieCol = 'vie' . ucfirst($nom);
            if ($nom == "champdeforce") {
                $vieVal = vieChampDeForce($batiments[$nom] - 1);
            } else {
                $vieVal = pointsDeVie($batiments[$nom] - 1);
            }
            dbExecute($base, "UPDATE constructions SET $nom=?, $vieCol=? WHERE login=?", 'ids', ($batiments[$nom] - 1), $vieVal, $joueur);
        } else {
            dbExecute($base, "UPDATE constructions SET $nom=? WHERE login=?", 'is', ($batiments[$nom] - 1), $joueur);
        }
        ajouterPoints(-$listeConstructions[$nom]['points'], $joueur);
    }

    initPlayer($_SESSION['login']);
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

    $ex = dbQuery($base, 'SELECT x,y FROM membre');
    while ($joueurs = mysqli_fetch_array($ex)) {
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

    $liste = ['generateur', 'producteur', 'champdeforce', 'ionisateur', 'depot', 'stabilisateur', 'condenseur', 'lieur'];
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
    $act = statut($joueur);
    if ($act == 0) {
        return '<a href="joueur.php?id=' . $joueur . '" class="lienVisible"><span style="color:darkgray">' . $joueur . '</span></a>';
    } else {
        return '<a href="joueur.php?id=' . $joueur . '" class="lienVisible">' . $joueur . '</a>';
    }
}

function recalculerStatsAlliances()
{
    global $base;

    $ex = dbQuery($base, 'SELECT id FROM alliances');
    while ($donnees = mysqli_fetch_array($ex)) {
        $ex1 = dbQuery($base, 'SELECT * FROM autre WHERE idalliance=?', 'i', $donnees['id']);
        $pointstotaux = 0;
        $cTotal = 0;
        $aTotal = 0;
        $dTotal = 0;
        $pTotal = 0;
        while ($donnees1 = mysqli_fetch_array($ex1)) {
            $pointstotaux = $donnees1['totalPoints'] + $pointstotaux;
            $cTotal += $donnees1['points'];
            $aTotal += pointsAttaque($donnees1['pointsAttaque']);
            $dTotal += pointsDefense($donnees1['pointsDefense']);
            $pTotal += $donnees1['ressourcesPillees'];
        }
        dbExecute($base, 'UPDATE alliances SET pointstotaux=?, totalConstructions=?, totalAttaque=?, totalDefense=?, totalPillage=? WHERE id=?', 'ddddi', $pointstotaux, $cTotal, $aTotal, $dTotal, $pTotal, $donnees['id']);
    }
}

function supprimerAlliance($alliance)
{
    global $base;
    dbExecute($base, 'UPDATE autre SET energieDonnee=0 WHERE idalliance=?', 'i', $alliance);
    dbExecute($base, 'DELETE FROM alliances WHERE id=?', 'i', $alliance);
    dbExecute($base, 'UPDATE autre SET idalliance=0 WHERE idalliance=?', 'i', $alliance);
    dbExecute($base, 'DELETE FROM invitations WHERE idalliance=?', 'i', $alliance);
    dbExecute($base, 'DELETE FROM declarations WHERE (alliance1=? OR alliance2=?)', 'ii', $alliance, $alliance);
    dbExecute($base, 'DELETE FROM grades WHERE idalliance=?', 'i', $alliance);
}

function supprimerJoueur($joueur)
{
    global $base;
    if (function_exists('logInfo')) {
        logInfo('ACCOUNT', 'Account deleted', ['deleted_player' => $joueur]);
    }
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

    $donnees = dbFetchOne($base, 'SELECT inscrits FROM statistiques');
    $nbinscrits = $donnees['inscrits'] - 1;
    dbExecute($base, 'UPDATE statistiques SET inscrits=?', 'i', $nbinscrits);
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

function remiseAZero()
{
    global $base;
    global $nomsRes;
    global $nbRes;

    dbExecute($base, 'UPDATE autre SET points=0, niveaututo=1, nbattaques=0, neutrinos=default,moleculesPerdues=0, energieDepensee=0, energieDonnee=0, bombe=0, batMax=1, totalPoints=0, pointsAttaque=0, pointsDefense=0, ressourcesPillees = 0, missions=\'\'');
    dbExecute($base, 'UPDATE constructions SET generateur=default, producteur=default,pointsProducteur=default,pointsProducteurRestants=default, pointsCondenseur=default, pointsCondenseurRestants=default,champdeforce=default, lieur=default,ionisateur=default, depot=1, stabilisateur=default, condenseur=0,vieGenerateur=?, vieChampdeforce=?, vieProducteur=?, vieDepot=?', 'dddd', pointsDeVie(1), vieChampDeForce(0), pointsDeVie(1), pointsDeVie(1));
    dbExecute($base, 'UPDATE alliances SET energieAlliance=0,duplicateur=0');
    dbExecute($base, 'UPDATE molecules SET formule="Vide", nombre=0');
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

    dbExecute($base, 'UPDATE statistiques SET nbDerniere=0, tailleCarte=1');
    dbExecute($base, 'UPDATE membre SET x=-1000, y=-1000'); // on les enleve de la carte, ils sont replacés quand ils se reconnectent
}
