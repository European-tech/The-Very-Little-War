<?php
require_once("includes/session_init.php");
if (isset($_SESSION['login']))
{
	include("includes/basicprivatephp.php");
	require_once("includes/rate_limiter.php");
}
else
{
	include("includes/basicpublicphp.php");
}

// Rate limit to prevent player enumeration
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    rateLimitCheck($_ip, 'profile_view', 60, 60);
}

include("includes/bbcode.php");

include("includes/layout.php");

if (isset($_GET['id'])) {
	$_GET['id'] = trim($_GET['id']);
	$membre = dbFetchOne($base, 'SELECT login, derniereConnexion, x, y, estExclu FROM membre WHERE login=?', 's', $_GET['id']);
	$nb = $membre ? 1 : 0;

	if($nb > 0) {
		// FLOW-SOCIAL MEDIUM-003: Hide banned players — show same "not found" response
		if ((int)$membre['estExclu'] === 1) {
			$nb = 0;
		}
	}

	if($nb > 0) {
		$donnees1 = dbFetchOne($base, 'SELECT * FROM autre WHERE login=?', 's', $membre['login']);

		$donnees3 = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $membre['login']);

		$donnees2 = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id=?', 'i', $donnees3['idalliance']);

		$moleculesCountRows = dbFetchAll($base, 'SELECT nombre FROM molecules WHERE proprietaire=? AND nombre!=0', 's', $membre['login']);
		$nb_molecules = 0;
		foreach($moleculesCountRows as $donnees4) {
			$nb_molecules = $nb_molecules + $donnees4['nombre'];
		}

        // Fetch prestige unlocks for legend badge (MEDIUM-003)
        $playerPrestige = dbFetchOne($base, 'SELECT unlocks FROM prestige WHERE login=?', 's', $membre['login']);
        $isLegendPlayer = false;
        if ($playerPrestige && !empty($playerPrestige['unlocks'])) {
            $isLegendPlayer = in_array('legende', array_filter(explode(',', $playerPrestige['unlocks'])));
        }

        debutCarte(htmlspecialchars($membre['login'], ENT_QUOTES, 'UTF-8'));
        $titre = 'Joueur';
        if ($isLegendPlayer) {
            $titre = $titre . ' <span class="prestige-legend" title="Joueur L&eacute;gendaire &mdash; 1000 PP d&eacute;pens&eacute;s">&#9733; L&eacute;gendaire</span>';
        }
        if(statut($membre['login']) == 0){
            $titre = $titre." <span style=\"color:darkgray\">Inactif</span>";
        }
        echo important($titre);
		?>
		<br/>
		<img style="margin-right: 20px; float: right; border-radius: 10px;width:80px;" alt="profil" src="images/profil/<?php echo htmlspecialchars($donnees1['image'], ENT_QUOTES, 'UTF-8'); ?>"/>
       <?php if($donnees3['idalliance'] > 0 && $donnees2) { $alliance = alliance($donnees2['tag']); } else { $alliance = "Pas d'alliance";}
        
        $playerPoints = dbFetchOne($base, 'SELECT totalPoints FROM autre WHERE login=?', 's', $membre['login']);
        $totalPoints = (int) ($playerPoints['totalPoints'] ?? 0); // LOW-027: cast to int
        $rangData = dbFetchOne($base, 'SELECT COUNT(*) + 1 AS rang FROM autre WHERE totalPoints > ?', 'd', $totalPoints);
        $rang = $rangData['rang'];
        echo chipInfo('<span class="important">Rang : </span>'.imageClassement($rang),'images/alliance/up.png').'<br/>';
        echo chip('<span class="important">Nom : </span>'.htmlspecialchars($membre['login'], ENT_QUOTES, 'UTF-8'),'<img alt="coupe" src="images/classement/joueur.png" class="imageChip" style="width:25px;border-radius:0px;"/>',"white",false,true).'<br/>';

        // Last seen indicator
        $lastConn = isset($membre['derniereConnexion']) ? (int)$membre['derniereConnexion'] : 0;
        $diff = time() - $lastConn;
        if ($diff < 300) {
            $lastSeenText = 'En ligne';
            $lastSeenColor = '#4caf50';
        } elseif ($diff < SECONDS_PER_HOUR) {
            $lastSeenText = 'Il y a ' . floor($diff / 60) . ' min';
            $lastSeenColor = '#8bc34a';
        } elseif ($diff < SECONDS_PER_DAY) {
            $lastSeenText = 'Il y a ' . floor($diff / SECONDS_PER_HOUR) . 'h';
            $lastSeenColor = '#ff9800';
        } else {
            $lastSeenText = 'Il y a ' . floor($diff / SECONDS_PER_DAY) . ' jour' . (floor($diff / SECONDS_PER_DAY) > 1 ? 's' : '');
            $lastSeenColor = '#9e9e9e';
        }
        echo '<p><span style="color:' . htmlspecialchars($lastSeenColor, ENT_QUOTES, 'UTF-8') . ';">&#9679;</span> ' . htmlspecialchars($lastSeenText) . '</p>';

        echo chip('<span class="important">Equipe : </span>'.$alliance,'<img alt="coupe" src="images/classement/alliance.png" class="imageChip" style="width:25px;border-radius:0px;"/>',"white",false,true).'<br/>';
        // MEDIUM-028: Use safe integer/formatted output for totalPoints, victoires, x, y
        $safePoints = htmlspecialchars(number_format((int)$donnees1['totalPoints'], 0, '.', ' '), ENT_QUOTES, 'UTF-8');
        echo nombrePoints('<span class="important">Points : </span>' . $safePoints).'<br/>';
		echo chip('<span class="important">Victoires : </span>'.(int)$donnees1['victoires'],'<img alt="coupe" src="images/classement/victoires.png" class="imageChip" style="width:25px;border-radius:0px;"/>',"white",false,true).'<br/>';
        $safeX = (int)$membre['x'];
        $safeY = (int)$membre['y'];
        if($safeX != -1000){
            // LOW-028: only show exact coordinates to authenticated users
            if (isset($_SESSION['login'])) {
                echo chip('<span class="important">Position : </span>'.'<a href="attaquer.php?x='.$safeX.'&amp;y='.$safeY.'">'.$safeX.';'.$safeY.'</a>','<img alt="coupe" src="images/attaquer/map.png" class="imageChip" style="width:25px;border-radius:0px;"/>',"white",false,true);
            } else {
                echo chip('<span class="important">Position : </span><img src="images/attaquer/map.png" alt="carte" style="width:16px;vertical-align:middle;"/> <em>Connectez-vous pour voir</em>','<img alt="coupe" src="images/attaquer/map.png" class="imageChip" style="width:25px;border-radius:0px;"/>',"white",false,true);
            }
        }

        $fin = false;
		if(isset($_SESSION['login'])) {
            if($safeX != -1000 && $_SESSION['login'] != $membre['login']){
			     $fin = '<a href="attaquer.php?id='.htmlspecialchars($membre['login'], ENT_QUOTES, 'UTF-8').'&type=1" class="lienSousMenu"><img src="images/classement/adversaires.png" class="imageSousMenu" alt="attaquer" title="Attaquer"/><br/><span class="labelSousMenu"  style="color:black">Attaquer</span></a>
                <a href="attaquer.php?id='.htmlspecialchars($membre['login'], ENT_QUOTES, 'UTF-8').'&type=2" class="lienSousMenu"><img src="images/rapports/binoculars.png" class="imageSousMenu" alt="attaquer" title="Espionner"/><br/><span class="labelSousMenu"  style="color:black">Espionner</span></a>';
            }

            $fin = $fin.'<a href="ecriremessage.php?destinataire='.htmlspecialchars($membre['login'], ENT_QUOTES, 'UTF-8').'" class="lienSousMenu"><img src="images/message_ferme.png" class="imageSousMenu" alt="attaquer" title="Ecrire un message"/><br/><span class="labelSousMenu"  style="color:black">Message</span></a>
            <a href="medailles.php?login='.htmlspecialchars($membre['login'], ENT_QUOTES, 'UTF-8').'" class="lienSousMenu"><img src="images/medailles.png" class="imageSousMenu" alt="attaquer" title="Médailles"/><br/><span class="labelSousMenu"  style="color:black">Médailles</span></a>';
            echo '<br/><br/>'.important("Actions");
		}
        
		finCarte($fin);
        
        if(isset($_SESSION['login'])) {
            debutCarte("Description");
                echo '<div class="table-responsive">';
		      echo BBcode($donnees1['description']);
                echo '</div>';
            finCarte();
        }
	} else {
        $membre['login'] = "Joueur inexistant";
        debutCarte("Erreur");
        debutContent();
		echo  "Ce joueur n'existe pas !";
        finContent();
        finCarte();
	}
} else {
    // AUTH15-001: Redirect to own profile if no ID given, but only if logged in.
    // Without the isset check, unauthenticated visits produced a PHP warning and
    // a redirect to joueur.php?id= (empty), leaking partial page content.
    if (isset($_SESSION['login'])) {
        header('Location: joueur.php?id=' . urlencode($_SESSION['login']));
    } else {
        header('Location: index.php');
    }
    exit();
}
include("includes/copyright.php"); ?>