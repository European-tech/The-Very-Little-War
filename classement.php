<?php
require_once("includes/session_init.php");
if (isset($_SESSION['login']))
{
	include("includes/basicprivatephp.php");
}
else
{
	include("includes/basicpublicphp.php");
}


if(isset($_GET['clas'])) {
	$_GET['clas'] = (int)$_GET['clas'];
}

// Whitelist the order column for player ranking
if(isset($_GET['clas'])) {
    switch($_GET['clas']) {
        case 0:
            $order = 'batmax';
            break;
        case 1:
            $order = 'victoires';
            break;
        case 2:
            $order = 'pointsAttaque';
            break;
        case 3:
            $order = 'pointsDefense';
            break;
        case 4:
            $order = 'ressourcesPillees';
            break;
        case 5:
            $order = 'points';
            break;
        default :
            $order = 'totalPoints';
            break;
    }
}
else {
    $order = 'totalPoints';
}

if(isset($_POST['joueurRecherche']) AND !empty($_POST['joueurRecherche'])) {
	$recherche = dbFetchOne($base, 'SELECT count(*) AS joueurExiste FROM autre WHERE login=?', 's', $_POST['joueurRecherche']);

	if($recherche['joueurExiste'] == 1) {
		// Count players ranked above the searched player to find their rank (single query instead of full scan)
		$searchLogin = $_POST['joueurRecherche'];
		$playerScore = dbFetchOne($base, 'SELECT ' . $order . ' AS score FROM autre WHERE login=?', 's', $searchLogin);
		if ($playerScore) {
			$rankRow = dbFetchOne($base, 'SELECT COUNT(*) AS rank FROM autre WHERE ' . $order . ' > ?', 'd', $playerScore['score']);
			$place = ($rankRow['rank'] ?? 0) + 1;
			$pageParDefaut = ceil($place / 20);
		}
		$pasTrouve = 1;
	}
	else {
		$erreur = "Ce joueur n'existe pas.";
		$pasTrouve = 0;
	}
}

include("includes/tout.php");

$_GET['sub'] = isset($_GET['sub']) ? (int)$_GET['sub'] : 0;
debutCarte("Classement"); ?>
<div class="table-responsive">
<?php
if(isset($_GET['sub']) AND $_GET['sub'] == 0) {

	$nombreDeJoueursParPage = 20;

		if(isset($_SESSION['login'])) {
			if(isset($pasTrouve) AND $pasTrouve==1) {
			}
			else {
				// Find logged-in player's rank with a count query instead of full table scan
				$myScore = dbFetchOne($base, 'SELECT ' . $order . ' AS score FROM autre WHERE login=?', 's', $_SESSION['login']);
				if ($myScore) {
					$myRank = dbFetchOne($base, 'SELECT COUNT(*) AS rank FROM autre WHERE ' . $order . ' > ?', 'd', $myScore['score']);
					$place = ($myRank['rank'] ?? 0) + 1;
					$pageParDefaut = ceil($place / $nombreDeJoueursParPage);
				} else {
					$pageParDefaut = 1;
				}
			}
		}
		else {
			$pageParDefaut = 1;
		}



	$retour = dbFetchOne($base, 'SELECT COUNT(*) AS nb_joueurs FROM autre');
	$donnees = $retour;
	$totalDesJoueurs = $donnees['nb_joueurs'];
	$nombreDePages  = ceil($totalDesJoueurs / $nombreDeJoueursParPage); // Calcul du nombre de pages créées

	$page = isset($_GET['page']) ? intval($_GET['page']) : $pageParDefaut;
	if ($page < 1 || $page > $nombreDePages) {
		$page = $pageParDefaut;
	}

	$premierJoueurAafficher = ($page - 1) * $nombreDeJoueursParPage;

	// $order is whitelisted, $premierJoueurAafficher and $nombreDeJoueursParPage are integers
	$classement = dbQuery($base, 'SELECT * FROM autre ORDER BY ' . $order . ' DESC LIMIT ?, ?', 'ii', $premierJoueurAafficher, $nombreDeJoueursParPage);
	$compteur = $nombreDeJoueursParPage*($page-1)+1;

	if(isset($_SESSION['login'])) {
		$idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);

		$allianceJoueur = dbFetchOne($base, 'SELECT * FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
	}

	// Optimization 4: Pre-load alliances, prestige, and declarations to eliminate
	// the per-row queries (~7 queries * 20 rows = up to 140 queries saved per page).
	$allianceCache = [];
	$allianceRows = dbFetchAll($base, 'SELECT id, tag FROM alliances', '');
	foreach ($allianceRows as $ar) {
		$allianceCache[(int)$ar['id']] = $ar['tag'];
	}

	$prestigeCache = [];
	$prestigeRows = dbFetchAll($base, 'SELECT login, total_pp FROM prestige', '');
	foreach ($prestigeRows as $pr) {
		$prestigeCache[$pr['login']] = (int)$pr['total_pp'];
	}

	// Pre-load war and pact sets for the logged-in player's alliance.
	$warAllianceIdsClassement = [];
	$pactAllianceIdsClassement = [];
	$myAllianceIdClassement = isset($idalliance['idalliance']) ? (int)$idalliance['idalliance'] : 0;
	if (isset($_SESSION['login']) && $myAllianceIdClassement > 0) {
		$warsC = dbFetchAll($base, 'SELECT alliance1, alliance2 FROM declarations WHERE type=0 AND (alliance1=? OR alliance2=?) AND fin=0', 'ii', $myAllianceIdClassement, $myAllianceIdClassement);
		foreach ($warsC as $wc) {
			$oid = ($wc['alliance1'] == $myAllianceIdClassement) ? (int)$wc['alliance2'] : (int)$wc['alliance1'];
			$warAllianceIdsClassement[$oid] = true;
		}
		$pactsC = dbFetchAll($base, 'SELECT alliance1, alliance2 FROM declarations WHERE type=1 AND (alliance1=? OR alliance2=?) AND valide!=0', 'ii', $myAllianceIdClassement, $myAllianceIdClassement);
		foreach ($pactsC as $pc) {
			$oid = ($pc['alliance1'] == $myAllianceIdClassement) ? (int)$pc['alliance2'] : (int)$pc['alliance1'];
			$pactAllianceIdsClassement[$oid] = true;
		}
	}
	?>
	<table class="table table-striped table-bordered">
	<thead>
	<tr>
	<th><img src="images/classement/up.png" alt="up" class="imageSousMenu"/><br/><span class="labelClassement">Rang</span></th>
    <th><img src="images/classement/joueur.png" alt="joueur" title="Joueur" class="imageSousMenu"/><br/><span class="labelClassement">Joueur</span></th>
	<th><a href="classement.php?sub=0"><img src="images/classement/points.png" alt="points" title="Points" class="imageSousMenu"/><br/><span class="labelClassement">Points</span></a></th>
	<th><img src="images/classement/alliance.png" alt="alliance" title="Equipe" class="imageSousMenu"/><br/><span class="labelClassement">Equipe</span></th>
	<th><a href="classement.php?sub=0&clas=5"><img src="images/classement/museum.png" alt="pointCs" title="Points de construction" class="imageSousMenu"/><br/><span class="labelClassement">Constructions</span></a></th>
	<th><a href="classement.php?sub=0&clas=2"><img src="images/classement/sword.png" alt="att" title="Attaque" class="imageSousMenu"/><br/><span class="labelClassement">Attaque</span></a></th>
	<th><a href="classement.php?sub=0&clas=3"><img src="images/classement/shield.png" alt="def" title="Défense" class="imageSousMenu"/><br/><span class="labelClassement">Défense</span></a></th>
	<th><a href="classement.php?sub=0&clas=4"><img src="images/classement/bag.png" alt="bag" title="Pillage" class="imageSousMenu"/><br/><span class="labelClassement">Pillage</span></a></th>
	<th><a href="classement.php?sub=0&clas=1"><img src="images/classement/victoires.png" alt="victoires" title="Points de victoire" class="imageSousMenu"/><br/><span class="labelClassement">Victoire</span></a></th>
	<th><img src="images/classement/shield.png" alt="prestige" title="Prestige" class="imageSousMenu"/><br/><span class="labelClassement">PP</span></th>
	</tr>
	</thead>
	<tbody>
	<?php
	while($donnees = mysqli_fetch_array($classement)){
		$rowAllianceId = (int) $donnees['idalliance'];
		$enGuerre = "";

		if(isset($_SESSION['login'])) {
			if($_SESSION['login'] == $donnees['login'] || (isset($_POST['joueurRecherche']) && $_POST['joueurRecherche']==$donnees['login'])) {
				$enGuerre = "160,160,160";
			}
		}

		if(isset($_SESSION['login']) AND $rowAllianceId > 0) {
			if(isset($warAllianceIdsClassement[$rowAllianceId]) AND $rowAllianceId != $myAllianceIdClassement) {
				$enGuerre = "254,130,130";
			}
			if((isset($pactAllianceIdsClassement[$rowAllianceId]) OR $rowAllianceId == $myAllianceIdClassement) AND $donnees['login'] != $_SESSION['login']) {
				$enGuerre = "156,255,136";
			}
		}
		// Retrieve alliance tag from pre-loaded cache (no DB query per row).
		$allianceTag = ($rowAllianceId > 0 && isset($allianceCache[$rowAllianceId])) ? $allianceCache[$rowAllianceId] : '';
		?>
		<tr style="background-color: rgba(<?php if(isset($enGuerre)) { echo $enGuerre.",0.6)"; }?>;">
		<td ><?php echo imageClassement($compteur) ; ?></td>
		<td><?php echo joueur($donnees['login']); ?></td>
		<td><?php echo number_format($donnees['totalPoints'], 0 , ' ', ' '); ?></td>
		<td><?php if($rowAllianceId > 0 && $allianceTag !== '') { echo alliance($allianceTag); } ?></td>
		<td><?php echo chiffrePetit($donnees['points']); ?></td>
		<td><?php echo chiffrePetit(pointsAttaque($donnees['pointsAttaque'])); ?></td>
		<td><?php echo chiffrePetit(pointsDefense($donnees['pointsDefense'])); ?></td>
		<td><?php echo chiffrePetit($donnees['ressourcesPillees']); ?></td>
		<td><?php echo $donnees['victoires'].' <span style="font-style:italic;font-size:10px">+'.pointsVictoireJoueur($compteur).'</span>'; ?></td>
		<td><?php echo isset($prestigeCache[$donnees['login']]) ? $prestigeCache[$donnees['login']] : 0; ?></td>
		</tr>
		<?php $compteur++;
	}
	?>
	</tbody>
	</table>

	<?php
	$adresse = "classement.php?sub=0&";
        $premier = '';
        if($page > 2){
            $premier = '<a href="'.$adresse.'page=1">1</a>';
        }
        $pointsD = '';
        if($page > 3){
            $pointsD = '...';
        }
        $precedent = '';
        if($page > 1){
            $precedent = '<a href="'.$adresse.'page='.($page-1).'">'.($page-1).'</a>';
        }
        $suivant = '';
        if($page+1 <= $nombreDePages){
            $suivant = '<a href="'.$adresse.'page='.($page+1).'">'.($page+1).'</a>';
        }
        $pointsF = '';
        if($page+3 <= $nombreDePages){
            $pointsF = '...';
        }
        $dernier = '';
        if($page+2 <= $nombreDePages){
            $dernier = '<a href="'.$adresse.'page='.$nombreDePages.'">'.$nombreDePages.'</a>';
        }
        $pages = $premier.' '.$pointsD.' '.$precedent.' <strong>'.$page.'</strong> '.$suivant.' '.$pointsF.' '.$dernier;
	?></p>
	<?php
	if(isset($_SESSION['login'])) {
	$plus = '';
	if(isset($_GET['clas'])) {
		$plus = '&clas='.$_GET['clas'];
	}
    debutListe();
        echo important("Rechercher");
        item(['floating' => true, 'form' => ['classement.php?sub=0'.$plus, "rechercher"], 'titre' => 'Nom du joueur', 'input' => '<input type="text" name="joueurRecherche" id="joueurRecherche" class="form-control"/>']);
        echo '<br/>'.submit(['form' => 'rechercher', 'titre' => 'Rechercher', 'image' => 'images/boutons/rechercher.png']);
	finListe();
	}
}
elseif (isset($_GET['sub']) AND $_GET['sub'] == 1){
	recalculerStatsAlliances();



	//////////////////////////////////////////////////////////////////////////////////////////////////////////
	if(isset($_SESSION['login'])) {
		$idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);

		$allianceJoueur = dbFetchOne($base, 'SELECT * FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);
	}
	else {
		$allianceJoueur['id'] = -1; // pour ne pas avoir d'erreurs et ne pas mettre de if partout
	}

	$nombreDeAlliancesParPage = 20;
	$retour = dbFetchOne($base, 'SELECT COUNT(*) AS nb_alliances FROM alliances');
	$totalDesAlliances = $retour['nb_alliances'];
	$nombreDePages  = ceil($totalDesAlliances / $nombreDeAlliancesParPage);

	$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
	if ($page < 1 || $page > $nombreDePages) {
		$page = 1;
	}

	$premiereAllianceAafficher = ($page - 1) * $nombreDeAlliancesParPage;

    // Whitelist the order column for alliance ranking
    if(isset($_GET['clas'])) {
		switch($_GET['clas']) {
			case 1:
				$order = 'totalConstructions';
				break;
            case 2:
				$order = 'totalAttaque';
				break;
            case 3:
				$order = 'totalDefense';
				break;
            case 4:
				$order = 'totalPillage';
				break;
            case 5 :
                $order = 'pointsVictoire';
                break;
			default :
				$order = 'pointstotaux';
				break;
		}
	}
	else {
		$order = 'pointstotaux';
	}

	// $order is whitelisted
	$classement = dbQuery($base, 'SELECT * FROM alliances ORDER BY ' . $order . ' DESC LIMIT ?, ?', 'ii', $premiereAllianceAafficher, $nombreDeAlliancesParPage);
	$compteur = $nombreDeAlliancesParPage*($page-1)+1;

	// Pre-load member counts per alliance to avoid N+1 queries
	$memberCountCache = [];
	$memberCountRows = dbFetchAll($base, 'SELECT idalliance, COUNT(*) AS nb FROM autre WHERE idalliance > 0 GROUP BY idalliance', '');
	foreach ($memberCountRows as $mcr) {
		$memberCountCache[(int)$mcr['idalliance']] = (int)$mcr['nb'];
	}

	// Pre-load war and pact sets for the logged-in player's alliance (alliance ranking tab)
	$warAllianceIdsAllianceTab = [];
	$pactAllianceIdsAllianceTab = [];
	$myAllianceIdAllianceTab = isset($allianceJoueur['id']) ? (int)$allianceJoueur['id'] : 0;
	if (isset($_SESSION['login']) && $myAllianceIdAllianceTab > 0) {
		$warsAT = dbFetchAll($base, 'SELECT alliance1, alliance2 FROM declarations WHERE type=0 AND (alliance1=? OR alliance2=?) AND fin=0', 'ii', $myAllianceIdAllianceTab, $myAllianceIdAllianceTab);
		foreach ($warsAT as $wat) {
			$oid = ($wat['alliance1'] == $myAllianceIdAllianceTab) ? (int)$wat['alliance2'] : (int)$wat['alliance1'];
			$warAllianceIdsAllianceTab[$oid] = true;
		}
		$pactsAT = dbFetchAll($base, 'SELECT alliance1, alliance2 FROM declarations WHERE type=1 AND (alliance1=? OR alliance2=?) AND valide!=0', 'ii', $myAllianceIdAllianceTab, $myAllianceIdAllianceTab);
		foreach ($pactsAT as $pat) {
			$oid = ($pat['alliance1'] == $myAllianceIdAllianceTab) ? (int)$pat['alliance2'] : (int)$pat['alliance1'];
			$pactAllianceIdsAllianceTab[$oid] = true;
		}
	}

	?>
	<table class="table table-striped table-bordered">
	<thead>
	<tr>
	<th><img src="images/classement/up.png" alt="up" title="Classement" class="imageSousMenu"/><br/><span class="labelClassement">Rang</span></th>
	<th><img src="images/classement/post-it.png" alt="post" class="imageSousMenu"/><br/><span class="labelClassement">TAG</span></th>
	<th><img src="images/classement/alliance.png" alt="alliance" title="Nombre de joueurs" class="imageSousMenu"/><br/><span class="labelClassement">Membres</span></th>
    <th><a href="classement.php?sub=1"><img src="images/classement/points.png" alt="points" title="Points totaux" class="imageSousMenu"/><br/><span class="labelClassement">Points</span></a></th>
	<th><img src="images/classement/sum-sign.png" alt="post" class="imageSousMenu"/><br/><span class="labelClassement">Moyenne</span></th>
	<th><a href="classement.php?sub=1&clas=1"><img src="images/classement/museum.png" alt="pointCs" title="Points de construction" class="imageSousMenu"/><br/><span class="labelClassement">Constructions</span></a></th>
	<th><a href="classement.php?sub=1&clas=2"><img src="images/classement/sword.png" alt="att" title="Attaque" class="imageSousMenu"/><br/><span class="labelClassement">Attaque</span></a></th>
	<th><a href="classement.php?sub=1&clas=3"><img src="images/classement/shield.png" alt="def" title="Défense" class="imageSousMenu"/><br/><span class="labelClassement">Défense</span></a></th>
	<th><a href="classement.php?sub=1&clas=4"><img src="images/classement/bag.png" alt="bag" title="Pillage" class="imageSousMenu"/><br/><span class="labelClassement">Pillage</span></a></th>
	<th><a href="classement.php?sub=1&clas=5"><img src="images/classement/victoires.png" alt="bag" title="Points de victoire" class="imageSousMenu"/><br/><span class="labelClassement">Victoire</span></a></th>
	</tr>
	</thead>
	<tbody>
	<?php
	while($donnees = mysqli_fetch_array($classement)){
		$rowAllianceIdAT = (int)$donnees['id'];
		$enGuerre = "";

		if (isset($_SESSION['login']) && $myAllianceIdAllianceTab > 0) {
			if (isset($warAllianceIdsAllianceTab[$rowAllianceIdAT]) && $rowAllianceIdAT != $myAllianceIdAllianceTab) {
				$enGuerre = "254,130,130";
			}
			if (isset($pactAllianceIdsAllianceTab[$rowAllianceIdAT]) && $rowAllianceIdAT != $myAllianceIdAllianceTab) {
				$enGuerre = "156,255,136";
			}
			if ($rowAllianceIdAT == $myAllianceIdAllianceTab) {
				$enGuerre = "160,160,160";
			}
		}

		$nbjoueurs = isset($memberCountCache[$rowAllianceIdAT]) ? $memberCountCache[$rowAllianceIdAT] : 0;
		if ($nbjoueurs != 0) { // Pour éviter la division par zéro
			?>
			<tr style="background-color: rgba(<?php if(isset($enGuerre)) { echo $enGuerre.",0.6)"; }?>">
			<td><?php echo imageClassement($compteur) ; ?></td>
			<td><?php echo alliance($donnees['tag']); ?></td>
			<td><?php echo $nbjoueurs; ?></td>
			<td><?php echo number_format($donnees['pointstotaux'], 0 , ' ', ' ');?></td>
			<td><?php echo number_format(round($donnees['pointstotaux']/$nbjoueurs), 0 , ' ', ' '); ?></td>
			<td><?php echo number_format($donnees['totalConstructions'], 0 , ' ', ' ');?></td>
			<td><?php echo number_format($donnees['totalAttaque'], 0 , ' ', ' ');?></td>
			<td><?php echo number_format($donnees['totalDefense'], 0 , ' ', ' ');?></td>
			<td><?php echo number_format($donnees['totalPillage'], 0 , ' ', ' ');?></td>
            <td><?php echo $donnees['pointsVictoire'].' <span style="font-style:italic;font-size:10px">+'.pointsVictoireAlliance($compteur).'</span></td>'; ?></td>
			</tr>
			<?php $compteur++;
		}
		else {
			// Skip empty alliances during display - do NOT delete during render loop
			continue;
		}
	}
	?>
	</tbody>
	</table>
	<?php
	$adresse = "classement.php?sub=1&";
        $premier = '';
        if($page > 2){
            $premier = '<a href="'.$adresse.'page=1">1</a>';
        }
        $pointsD = '';
        if($page > 3){
            $pointsD = '...';
        }
        $precedent = '';
        if($page > 1){
            $precedent = '<a href="'.$adresse.'page='.($page-1).'">'.($page-1).'</a>';
        }
        $suivant = '';
        if($page+1 <= $nombreDePages){
            $suivant = '<a href="'.$adresse.'page='.($page+1).'">'.($page+1).'</a>';
        }
        $pointsF = '';
        if($page+3 <= $nombreDePages){
            $pointsF = '...';
        }
        $dernier = '';
        if($page+2 <= $nombreDePages){
            $dernier = '<a href="'.$adresse.'page='.$nombreDePages.'">'.$nombreDePages.'</a>';
        }
        $pages = $premier.' '.$pointsD.' '.$precedent.' <strong>'.$page.'</strong> '.$suivant.' '.$pointsF.' '.$dernier;
}
elseif(isset($_GET['sub']) AND $_GET['sub'] == 2) {
	$nombreDeGuerresParPage = 20;
	$retour = dbFetchOne($base, 'SELECT COUNT(*) AS nb_guerres FROM declarations WHERE (pertes1 + pertes2) != 0 AND type=0 AND fin!= 0');
	$totalDesGuerres = $retour['nb_guerres'];
	$nombreDePages  = ceil($totalDesGuerres / $nombreDeGuerresParPage);

	$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
	if ($page < 1 || $page > $nombreDePages) {
		$page = 1;
	}

	$premiereGuerreAafficher = ($page - 1) * $nombreDeGuerresParPage;
	$compteur = $nombreDeGuerresParPage*($page-1)+1;
	?>
	<table class="table table-striped table-bordered">
	<thead>
	<tr>
	<th><img src="images/classement/up.png" alt="up" title="Classement" class="imageSousMenu"/><br/><span class="labelClassement">Rang</span></th>
	<th><img src="images/classement/adversaires.png" alt="adversaires" title="Adversaires" class="imageSousMenu"/><br/><span class="labelClassement">Adversaires</span></th>
	<th><img src="images/classement/morts.png" alt="morts" title="Nombre de molécules perdues" class="imageSousMenu"/><br/><span class="labelClassement">Pertes</span></th>
	<th><img src="images/classement/calendrier.png" alt="calendrier" title="Durée (jours)" class="imageSousMenu"/><br/><span class="labelClassement">Durée</span></th>
	<th><img src="images/classement/copy.png" alt="copy" class="imageSousMenu"/><br/><span class="labelClassement">Détails</span></th>
	</tr>
	</thead>
	<tbody>
	<?php
	// Pre-load all alliance tags to avoid per-row lookups
	$allianceTagCache = [];
	$allianceTagRows = dbFetchAll($base, 'SELECT id, tag FROM alliances', '');
	foreach ($allianceTagRows as $atr) {
		$allianceTagCache[(int)$atr['id']] = $atr['tag'];
	}

	$ex = dbQuery($base, 'SELECT * FROM declarations WHERE type=0 AND fin!= 0 ORDER BY (pertes1 + pertes2) DESC LIMIT ?, ?', 'ii', $premiereGuerreAafficher, $nombreDeGuerresParPage);
	while ($donnees = mysqli_fetch_array($ex)) {
		$alliance1 = ['tag' => isset($allianceTagCache[(int)$donnees['alliance1']]) ? $allianceTagCache[(int)$donnees['alliance1']] : '???'];
		$alliance2 = ['tag' => isset($allianceTagCache[(int)$donnees['alliance2']]) ? $allianceTagCache[(int)$donnees['alliance2']] : '???'];
		?>
		<tr>
		<td><?php echo imageClassement($compteur) ; ?></td>
		<td><?php echo alliance($alliance1['tag']); ?> contre <?php echo alliance($alliance2['tag']); ?></td>
		<td><?php echo number_format($donnees['pertes1'] + $donnees['pertes2'], 0 , ' ', ' '); ?></td>
		<td><?php echo round(($donnees['fin'] - $donnees['timestamp'])/86400);?></td>
		<td><a href="guerre.php?id=<?php echo $donnees['id']; ?>" class="lienVisible"><img src="images/classement/details.png" alt="details" title="Détails"/></a></td>
		</tr>
		<?php
		$compteur++;
	}
	?>
	</tbody>
	</table>
	<?php $adresse = "classement.php?sub=2&";
        $premier = '';
        if($page > 2){
            $premier = '<a href="'.$adresse.'page=1">1</a>';
        }
        $pointsD = '';
        if($page > 3){
            $pointsD = '...';
        }
        $precedent = '';
        if($page > 1){
            $precedent = '<a href="'.$adresse.'page='.($page-1).'">'.($page-1).'</a>';
        }
        $suivant = '';
        if($page+1 <= $nombreDePages){
            $suivant = '<a href="'.$adresse.'page='.($page+1).'">'.($page+1).'</a>';
        }
        $pointsF = '';
        if($page+3 <= $nombreDePages){
            $pointsF = '...';
        }
        $dernier = '';
        if($page+2 <= $nombreDePages){
            $dernier = '<a href="'.$adresse.'page='.$nombreDePages.'">'.$nombreDePages.'</a>';
        }
        $pages = $premier.' '.$pointsD.' '.$precedent.' <strong>'.$page.'</strong> '.$suivant.' '.$pointsF.' '.$dernier;

}
else {
	$nombreDeForumParPage = 20;
	$nbMembres = dbFetchOne($base, 'SELECT count(*) AS nbMembres FROM membre');
	$totalDesMembres = $nbMembres['nbMembres'];
	$nombreDePages  = ceil($totalDesMembres / $nombreDeForumParPage);

	$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
	if ($page < 1 || $page > $nombreDePages) {
		$page = 1;
	}

	$premierForumAafficher = ($page - 1) * $nombreDeForumParPage;
	$compteur = $nombreDeForumParPage*($page-1)+1;
	?>
	<table class="table table-striped table-bordered">
	<thead>
	<tr>
	<th><img src="images/classement/up.png" alt="up" title="Classement" class="imageSousMenu"/><br/><span class="labelClassement">Rang</span></th>
	<th><img src="images/classement/joueur.png" alt="joueur" title="Joueur" class="imageSousMenu"/><br/><span class="labelClassement">Joueur</span></th>
	<th><a href="classement.php?sub=3"><img src="images/classement/reponses.png" alt="reponses" title="Nombre de réponses" class="imageSousMenu"/><br/><span class="labelClassement">Réponses</span></a></th>
	<th><img src="images/classement/sujets.png" alt="reponses" title="Nombre de sujets" class="imageSousMenu"/><br/><span class="labelClassement">Sujets</span></th>
	<th><a href="classement.php?sub=3&clas=0"><img src="images/classement/bombe.png" alt="bombe" title="Nombre de bombes gagnées" class="imageSousMenu"/><br/><span class="labelClassement">Bombe</span></a></th>
	<th><a href="classement.php?sub=3&clas=1"><img src="images/classement/alea.png" alt="alea" title="Médaille aléatoire" class="imageSousMenu"/><br/><span class="labelClassement">Aléatoire</span></a></th>
	</tr>
	</thead>
	<tbody>
	<?php
	$plus = '';
	if(isset($_GET['clas'])) {
		$plus = '&clas='.$_GET['clas'];
	}

	// Whitelist $order and $table for forum ranking
	if(isset($_GET['clas'])) {
		switch(intval($_GET['clas'])) {
			case 0:
				$order = 'bombe';
				$table = 'autre';
				break;
			case 1:
				$order = 'troll';
				$table = 'membre';
				break;
			default :
				$order = 'nbMessages';
				$table = 'autre';
				break;
		}
	}
	else {
		$order = 'nbMessages';
		$table = 'autre';
	}

	// Pre-load forum data to avoid N+1 queries (3 queries per row eliminated)
	$autreForumCache = [];
	$autreForumRows = dbFetchAll($base, 'SELECT login, nbMessages, bombe FROM autre', '');
	foreach ($autreForumRows as $afr) {
		$autreForumCache[$afr['login']] = $afr;
	}

	$membreForumCache = [];
	$membreForumRows = dbFetchAll($base, 'SELECT login, troll FROM membre', '');
	foreach ($membreForumRows as $mfr) {
		$membreForumCache[$mfr['login']] = (int)$mfr['troll'];
	}

	$sujetsCountCache = [];
	$sujetsCountRows = dbFetchAll($base, 'SELECT auteur, COUNT(*) AS nbSujets FROM sujets GROUP BY auteur', '');
	foreach ($sujetsCountRows as $scr) {
		$sujetsCountCache[$scr['auteur']] = (int)$scr['nbSujets'];
	}

	// Medal name mappings
	$trollMedals = [0 => 'medaillebronze', 1 => 'medailleargent', 2 => 'medailleor', 3 => 'emeraude', 4 => 'saphir', 5 => 'rubis', 6 => 'diamant'];
	$bombeMedals = [0 => 'Rien', 1 => 'Bronze', 2 => 'Argent', 3 => 'Or', 4 => 'Platine'];

	// $order and $table are whitelisted
	$ex = dbQuery($base, 'SELECT login FROM ' . $table . ' ORDER BY ' . $order . ' DESC LIMIT ?, ?', 'ii', $premierForumAafficher, $nombreDeForumParPage);
	while ($donnees = mysqli_fetch_array($ex)) {
		$donnees1 = isset($autreForumCache[$donnees['login']]) ? $autreForumCache[$donnees['login']] : ['nbMessages' => 0, 'bombe' => 0];
		$trollLevel = isset($membreForumCache[$donnees['login']]) ? $membreForumCache[$donnees['login']] : 0;
		$trollImage = isset($trollMedals[$trollLevel]) ? $trollMedals[$trollLevel] : 'diamantrouge';
		$bombeLevel = (int)$donnees1['bombe'];
		$bombeImage = isset($bombeMedals[$bombeLevel]) ? $bombeMedals[$bombeLevel] : 'Diamant';
		$nbSujetsCount = isset($sujetsCountCache[$donnees['login']]) ? $sujetsCountCache[$donnees['login']] : 0;

		$enGuerre = "";
		if(isset($_SESSION['login'])) {
			if($_SESSION['login'] == $donnees['login']) {
					$enGuerre = "160,160,160";
			}
		}

		?>
		<tr style="background-color:rgba(<?php echo $enGuerre; ?>,0.6)">
		<td><?php echo imageClassement($compteur) ; ?></td>
		<td><?php echo joueur($donnees['login']); ?></td>
		<td><?php echo $donnees1['nbMessages']; ?></td>
		<td><?php echo $nbSujetsCount; ?></td>
		<td><img alt="bombe" style="width:40px;height:40px" src="images/medailles/bombe<?php echo $bombeImage;?>.png"/></td>
		<td><img alt="troll" style="width:40px;height:40px" src="images/classement/<?php echo $trollImage;?>.png"/></td>
		</tr>
		<?php
		$compteur++;
	}
	?>
	</tbody>
	</table>
	<?php $adresse = "classement.php?sub=3&";
        $premier = '';
        if($page > 2){
            $premier = '<a href="'.$adresse.'page=1">1</a>';
        }
        $pointsD = '';
        if($page > 3){
            $pointsD = '...';
        }
        $precedent = '';
        if($page > 1){
            $precedent = '<a href="'.$adresse.'page='.($page-1).'">'.($page-1).'</a>';
        }
        $suivant = '';
        if($page+1 <= $nombreDePages){
            $suivant = '<a href="'.$adresse.'page='.($page+1).'">'.($page+1).'</a>';
        }
        $pointsF = '';
        if($page+3 <= $nombreDePages){
            $pointsF = '...';
        }
        $dernier = '';
        if($page+2 <= $nombreDePages){
            $dernier = '<a href="'.$adresse.'page='.$nombreDePages.'">'.$nombreDePages.'</a>';
        }
        $pages = $premier.' '.$pointsD.' '.$precedent.' <strong>'.$page.'</strong> '.$suivant.' '.$pointsF.' '.$dernier;

}
echo '</div>';
finCarte($pages);

include("includes/copyright.php"); ?>