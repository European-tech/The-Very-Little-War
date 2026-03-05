<?php
include("includes/basicprivatephp.php");
include("includes/redirectionVacance.php");

include("includes/layout.php");

if(isset($_GET['id'])){
    $_GET['id'] = (int)$_GET['id'];
    $attaque = dbFetchOne($base, 'SELECT * FROM actionsattaques WHERE id=? AND attaquant=? AND troupes!=?', 'iss', $_GET['id'], $_SESSION['login'], 'Espionnage');
    $nb = $attaque ? 1 : 0;

    if($nb > 0){
        debutCarte("Attaque");
            scriptAffichageTemps();

            $joueur = dbFetchOne($base, 'SELECT * FROM membre WHERE login=?', 's', $attaque['defenseur']);
            echo important("Cible");

            echo chip(joueur($attaque['defenseur']),'<img alt="coupe" src="images/classement/joueur.png" class="imageChip" style="width:25px;border-radius:0px;"/>',"white",false,true);
            echo chip('<a href="attaquer.php?x='.$joueur['x'].'&y='.$joueur['y'].'">'.$joueur['x'].';'.$joueur['y'].' - '.(round(10*(pow(pow($membre['x']-$joueur['x'],2)+pow($membre['y']-$joueur['y'],2),0.5)))/10).' cases</a>','<img alt="coupe" src="images/attaquer/map.png" class="imageChip" style="width:25px;border-radius:0px;"/>',"white",false,true);
            if(time() > $attaque['tempsAttaque']){
                echo nombreTemps('<strong>Retour</strong> : <span id="affichage'.$attaque['id'].'">'.affichageTemps($attaque['tempsRetour']-time()).'</span>');
                echo '
                ' . cspScriptTag() . '
                    var valeur'.$attaque['id'].' = '.($attaque['tempsRetour']-time()).';

                    function tempsDynamique'.$attaque['id'].'(){
                        if(valeur'.$attaque['id'].' > 0){
                            valeur'.$attaque['id'].' -= 1;
                            document.getElementById("affichage'.$attaque['id'].'").innerHTML = affichageTemps(valeur'.$attaque['id'].');
                        }
                        else {
                            document.location.href="attaque.php?id='.$attaque['id'].'";
                        }
                    }

                    setInterval(tempsDynamique'.$attaque['id'].', 1000);
                    </script>';
            }
            else {
                echo nombreTemps('<strong>Attaque</strong> : <span id="affichage'.$attaque['id'].'">'.affichageTemps($attaque['tempsAttaque']-time()).'</span>');
                echo '
                ' . cspScriptTag() . '
                    var valeur'.$attaque['id'].' = '.($attaque['tempsAttaque']-time()).';

                    function tempsDynamique'.$attaque['id'].'(){
                        if(valeur'.$attaque['id'].' > 0){
                            valeur'.$attaque['id'].' -= 1;
                            document.getElementById("affichage'.$attaque['id'].'").innerHTML = affichageTemps(valeur'.$attaque['id'].');
                        }
                        else {
                            document.location.href="attaquer.php";
                        }
                    }

                    setInterval(tempsDynamique'.$attaque['id'].', 1000);
                    </script>';
            }

            echo '<br/><br/>';
            echo important("Troupes attaquantes");
            echo '<div class="table-responsive"><table>';
            $troupes = explode(";",$attaque['troupes']);
            $moleculesRows = dbFetchAll($base, 'SELECT * FROM molecules WHERE proprietaire=? ORDER BY numeroclasse ASC', 's', $_SESSION['login']);

            $c = 0;
            foreach($moleculesRows as $molecules){
                if($troupes[$c] > 0){
                    echo '<tr><td>'.couleurFormule($molecules['formule']).'</td><td>'.$troupes[$c].'</td></tr>';
                }
                $c++;
            }

            echo'</table></div>';

        finCarte();
    }
    else {
        debutCarte("Oups !");
        debutContent();
            echo "C'est dommage, cette attaque n'existe pas (ou plus à la rigueur).";
        finContent();
        finCarte();
    }
}
else {
    debutCarte("Oups !");
    debutContent();
        echo "Là, il n'y a rien dans ma barre !";
    finContent();
    finCarte();
}

include("includes/copyright.php");

?>
