<?php 
include("includes/basicprivatephp.php");
include("includes/tout.php");

if((isset($_GET['login']) AND !empty($_GET['login'])) OR isset($_SESSION['login']))  {
	if(isset($_GET['login']) AND $_GET['login'] != $_SESSION['login']) {
		$joueur = antiXSS($_GET['login']);
	}
	else {
		$joueur = $_SESSION['login'];
	}
	
	$donnees = dbFetchOne($base, 'SELECT count(*) AS ok FROM membre WHERE login=?', 's', $joueur);
	
	if($donnees['ok'] == 1) {
        
        $donnees = dbFetchOne($base, 'SELECT nbattaques FROM autre WHERE login=?', 's', $joueur);
        
        $donnees1 = dbFetchOne($base, 'SELECT count(*) AS nbmessages FROM reponses WHERE auteur=?', 's', $joueur);
        
        $donnees2 = dbFetchOne($base, 'SELECT * FROM autre WHERE login=?', 's', $joueur);
        
        $donnees3 = dbFetchOne($base, 'SELECT energieDepensee FROM autre WHERE login=?', 's', $joueur);
        
        $donnees4 = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $joueur);
        $plusHaut = batMax($donnees4['login'],$nomsRes,$nbRes);
        
        $troll = dbFetchOne($base, 'SELECT troll FROM membre WHERE login=?', 's', $joueur);
        
        $bombe = dbFetchOne($base, 'SELECT bombe FROM autre WHERE login=?', 's', $joueur);
            
	    debutCarte("Médailles");
        debutListe();
            $listeMedailles = [['Terreur',$donnees['nbattaques'],$paliersTerreur,'Attaques','% de diminution du coût d\'attaque',$bonusMedailles],
                               ['Attaquant',floor($donnees2['pointsAttaque']),$paliersAttaque,'Points d\'attaque','% d\'attaque supplémentaire',$bonusMedailles],
                               ['Defenseur',floor($donnees2['pointsDefense']),$paliersDefense,'Points de défense','% de défense supplémentaire',$bonusMedailles],
                               ['Pilleur',floor($donnees2['ressourcesPillees']),$paliersPillage,'Ressources pillées','% de pillage supplémentaire',$bonusMedailles],
                               ['Pertes',floor($donnees2['moleculesPerdues']),$paliersPertes,'Pertes','% de stabilisation des molécules',$bonusMedailles],
                               ['Energievore',$donnees3['energieDepensee'],$paliersEnergievore,'Energie dépensée','% de production d\'énergie',$bonusMedailles],
                               ['Constructeur',$plusHaut,$paliersConstructeur,'Niveau de bâtiment','% de réduction du coût des bâtiments',$bonusMedailles],
                               ['Pipelette',$donnees1['nbmessages'],$paliersPipelette,'Messages sur le forum','',$bonusForum],
                               ['Explosif',$bombe['bombe'],$paliersBombe,'Jeu de la bombe','',$bonusTroll],
                               ['Aléatoire',$troll['troll'],$paliersTroll,'Aléatoire','',$bonusTroll]];
            foreach($listeMedailles as $nbMedaille => $infos){
                
                $nomMedaille = $infos[0];
                $bonus = $infos[5];
                $medaille = false;
                $progression = $infos[1].'/'.$infos[2][0]; // pas de médaille
                $imageMedaille = '<img alt="vide" src="images/classement/vide.png" />';
                $bonusActuel = "Aucun bonus";
                $bonusSuivant = '<img alt="medaille" class="imageAide" src="images/classement/'.$imagesMedailles[0].'"/> <strong>'.$paliersMedailles[0].'</strong> : '.$bonus[0].$infos[4];
                $objetProgression = '<span class="important">'.$infos[3].'</span>';
            
                foreach($paliersMedailles as $num => $palier){
                    if($infos[1] >= $infos[2][$num]){
                        $medaille = $num;
                        
                        $bonusActuel = '<img alt="medaille" class="imageAide" src="images/classement/'.$imagesMedailles[$num].'"/> <strong>'.$paliersMedailles[$num].'</strong> : '.$bonus[$num].$infos[4];
                        
                        if($num+1 < sizeof($infos[2])){
                            $progression = $infos[1].'/'.$infos[2][$num+1];
                            $bonusSuivant = '<img alt="medaille" class="imageAide" src="images/classement/'.$imagesMedailles[$num+1].'"/> <strong>'.$paliersMedailles[$num+1].'</strong> : '.$bonus[$num+1].$infos[4];
                        }
                        else { // gestion du diamant rouge
                            $progression = $infos[1].' - Niveau maximal';
                            $bonusSuivant = '<strong>Niveau maximal</strong>';
                        }
                        
                        $imageMedaille = '<img alt="medaille" style="vertical-align:middle;width:40px;height:40px;" src="images/classement/'.$imagesMedailles[$num].'" />';
                    }
                }
                
                item(['accordion' => important('Bonus actuel').$bonusActuel.'<br/><br/>'.important('Bonus au prochain niveau').$bonusSuivant, 'titre' => $objetProgression, 'media' => $imageMedaille,'soustitre' => $progression]);
            }
        finListe();
        finCarte();
	}
	else {
        debutCarte("Amusant");
        debutContent();
		echo 'A un moment faut s\'arréter de jouer avec la barre URL.';
        finContent();
        finCarte("Mais pas suffisant.");
	}
	
}

include("includes/copyright.php"); ?>
