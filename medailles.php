<?php
include("includes/basicprivatephp.php");
include("includes/layout.php");

// MISC11-001: Fix swapped rateLimitCheck args — identifier=IP, action=string (not reversed)
rateLimitCheck($_SERVER['REMOTE_ADDR'], 'medals_lookup', 30, 60);

/**
 * Calculate medal progress toward the next tier (P1-D8-053)
 */
function medalProgress($currentValue, $thresholds) {
    for ($i = 0; $i < count($thresholds); $i++) {
        if ($currentValue < $thresholds[$i]) {
            $prevThreshold = $i > 0 ? $thresholds[$i - 1] : 0;
            $range = $thresholds[$i] - $prevThreshold;
            $pct = $range > 0 ? round(($currentValue - $prevThreshold) / $range * 100) : 0;
            return ['next_tier' => $i, 'pct' => min(100, max(0, $pct)), 'remaining' => $thresholds[$i] - $currentValue];
        }
    }
    return ['next_tier' => -1, 'pct' => 100, 'remaining' => 0];
}

if((isset($_GET['login']) AND !empty($_GET['login'])) OR isset($_SESSION['login']))  {
	if(isset($_GET['login']) AND $_GET['login'] != $_SESSION['login']) {
		$joueur = trim($_GET['login']);
	}
	else {
		$joueur = $_SESSION['login'];
	}
	
	$donnees = dbFetchOne($base, 'SELECT count(*) AS ok FROM membre WHERE login=?', 's', $joueur);
	
	if($donnees['ok'] == 1) {
        
        // M-022 DESIGN: Uses all-time reponses count (not season-reset autre.nbMessages) —
        // forum posts persist cross-season intentionally. Players accumulate Pipelette medal
        // progress across the lifetime of their account, reflecting long-term community
        // contribution rather than single-season activity.
        $donnees1 = dbFetchOne($base, 'SELECT count(*) AS nbmessages FROM reponses WHERE auteur=?', 's', $joueur);

        // Single query fetches all needed columns from autre (nbattaques, energieDepensee, bombe, etc.)
        $donnees2 = dbFetchOne($base, 'SELECT * FROM autre WHERE login=?', 's', $joueur);

        $donnees4 = dbFetchOne($base, 'SELECT * FROM constructions WHERE login=?', 's', $joueur);
        $plusHaut = batMax($donnees4['login']);

        $troll = dbFetchOne($base, 'SELECT troll FROM membre WHERE login=?', 's', $joueur);
            
        // Display player name from DB (not from $_GET) to prevent XSS via URL parameter
        $joueurDisplay = isset($donnees2['login'])
            ? htmlspecialchars($donnees2['login'], ENT_QUOTES, 'UTF-8')
            : htmlspecialchars($joueur, ENT_QUOTES, 'UTF-8');
	    debutCarte("Médailles de " . $joueurDisplay);
        debutListe();
            $listeMedailles = [['Terreur',$donnees2['nbattaques'],$paliersTerreur,'Attaques','% de diminution du coût d\'attaque',$bonusMedailles],
                               ['Attaquant',floor($donnees2['pointsAttaque']),$paliersAttaque,'Points d\'attaque','% d\'attaque supplémentaire',$bonusMedailles],
                               ['Defenseur',floor($donnees2['pointsDefense']),$paliersDefense,'Points de défense','% de défense supplémentaire',$bonusMedailles],
                               ['Pilleur',floor($donnees2['ressourcesPillees']),$paliersPillage,'Ressources pillées','% de pillage supplémentaire',$bonusMedailles],
                               ['Pertes',floor($donnees2['moleculesPerdues']),$paliersPertes,'Pertes','% de stabilisation des molécules',$bonusMedailles],
                               ['Energievore',$donnees2['energieDepensee'],$paliersEnergievore,'Energie dépensée','% de production d\'énergie',$bonusMedailles],
                               ['Constructeur',$plusHaut,$paliersConstructeur,'Niveau de bâtiment','% de réduction du coût des bâtiments',$bonusMedailles],
                               ['Pipelette',$donnees1['nbmessages'],$paliersPipelette,'Messages sur le forum','',$bonusForum],
                               ['Explosif',$donnees2['bombe'],$paliersBombe,'Jeu de la bombe','',$bonusTroll],
                               ['Aléatoire',$troll['troll'],$paliersTroll,'Aléatoire','',$bonusTroll]];
            foreach($listeMedailles as $nbMedaille => $infos){
                
                $nomMedaille = $infos[0];
                $bonus = $infos[5];
                $medaille = false;
                // H-005: cast to string and htmlspecialchars on any DB value going into HTML
                $statValue = htmlspecialchars((string)$infos[1], ENT_QUOTES, 'UTF-8');
                $firstThreshold = htmlspecialchars((string)$infos[2][0], ENT_QUOTES, 'UTF-8');
                $progression = $statValue.'/'.$firstThreshold; // pas de médaille
                $imageMedaille = '<img alt="vide" src="images/classement/vide.png" />';
                $bonusActuel = "Aucun bonus";
                $bonusSuivant = '<img alt="medaille" class="imageAide" src="images/classement/'.$imagesMedailles[0].'"/> <strong>'.$paliersMedailles[0].'</strong> : '.$bonus[0].$infos[4];
                $objetProgression = '<span class="important">'.$infos[3].'</span>';

                foreach($paliersMedailles as $num => $palier){
                    if($infos[1] >= $infos[2][$num]){
                        $medaille = $num;

                        $bonusActuel = '<img alt="medaille" class="imageAide" src="images/classement/'.$imagesMedailles[$num].'"/> <strong>'.$paliersMedailles[$num].'</strong> : '.$bonus[$num].$infos[4];

                        if($num+1 < count($infos[2])){
                            $nextThreshold = htmlspecialchars((string)$infos[2][$num+1], ENT_QUOTES, 'UTF-8');
                            $progression = $statValue.'/'.$nextThreshold;
                            $bonusSuivant = '<img alt="medaille" class="imageAide" src="images/classement/'.$imagesMedailles[$num+1].'"/> <strong>'.$paliersMedailles[$num+1].'</strong> : '.$bonus[$num+1].$infos[4];
                        }
                        else { // gestion du diamant rouge
                            $progression = $statValue.' - Niveau maximal';
                            $bonusSuivant = '<strong>Niveau maximal</strong>';
                        }
                        
                        $imageMedaille = '<img alt="medaille" style="vertical-align:middle;width:40px;height:40px;" src="images/classement/'.$imagesMedailles[$num].'" />';
                    }
                }
                
                // Progress bar toward next tier (P1-D8-053)
                $prog = medalProgress($infos[1], $infos[2]);
                $progressBarHtml = '';
                if ($prog['next_tier'] >= 0) {
                    $progressBarHtml = '<br/><br/>' . important('Progression vers le prochain palier')
                        . '<div style="background:#e0e0e0;border-radius:8px;height:10px;overflow:hidden;margin:6px 0 4px 0;">'
                        . '<div style="background:linear-gradient(90deg,#42a5f5,#1e88e5);height:100%;width:' . $prog['pct'] . '%;border-radius:8px;transition:width 0.5s;"></div>'
                        . '</div>'
                        . '<small style="color:#757575;">Encore ' . number_format($prog['remaining'], 0, ',', ' ') . ' pour ' . htmlspecialchars($paliersMedailles[$prog['next_tier']]) . '</small>';
                }

                item(['accordion' => important('Bonus actuel').$bonusActuel.'<br/><br/>'.important('Bonus au prochain niveau').$bonusSuivant.$progressBarHtml, 'titre' => $objetProgression, 'media' => $imageMedaille,'soustitre' => $progression]);
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
