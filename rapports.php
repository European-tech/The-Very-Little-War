<?php
include("includes/basicprivatephp.php");
require_once("includes/csrf.php");

if(isset($_POST['supprimer']) AND preg_match("#\d#",$_POST['supprimer'])) {
	csrfCheck();
	if($_POST['supprimer'] == 1) {
		dbExecute($base, 'DELETE FROM rapports WHERE destinataire = ?', 's', $_SESSION['login']);
	}
	else {
		$supprimerId = (int)$_POST['supprimer'];
		dbExecute($base, 'DELETE FROM rapports WHERE id = ? AND destinataire = ?', 'is', $supprimerId, $_SESSION['login']);
	}
}

include("includes/layout.php");

if(isset($_GET['rapport'])) {
	$rapportId = (int)$_GET['rapport'];
	$rapports = dbFetchOne($base, 'SELECT * FROM rapports WHERE id = ? AND destinataire = ?', 'is', $rapportId, $_SESSION['login']);
	$nb_rapports = $rapports ? 1 : 0;
	if($nb_rapports > 0) {
		dbExecute($base, 'UPDATE rapports SET statut=1 WHERE id = ?', 'i', $rapportId);

        debutCarte($rapports['titre']);
		debutContent();
		// Report content is HTML generated server-side by combat/game_actions
		// Sanitize any residual user-controlled data (player names, alliance tags)
		$allowedTags = '<a><br><br/><strong><b><i><em><p><div><span><img><table><tr><td><th><ul><ol><li><hr>';
		$content = strip_tags($rapports['contenu'], $allowedTags);
		$content = preg_replace('/\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $content);
		echo $content;
        finContent();
        finCarte('<form method="post" action="rapports.php" style="display:inline">'.csrfField().'<input type="hidden" name="supprimer" value="'.$rapports['id'].'"><button type="submit" style="background:none;border:none;cursor:pointer;padding:0;"><img src="images/croix.png" alt="supprimer" class="imageSousMenu"> Supprimer</button></form>');
	}
	else {
		header('Location: rapports.php');
		exit();
	}
}
else {
	$nombreDeRapportsParPage = REPORTS_PER_PAGE;
	$totalDesRapports = dbCount($base, 'SELECT COUNT(*) AS nb_rapports FROM rapports WHERE destinataire = ?', 's', $_SESSION['login']);
	$nombreDePages  = ceil($totalDesRapports / $nombreDeRapportsParPage); // Calcul du nombre de pages créées
	// Puis on fait une boucle pour écrire les liens vers chacune des pages

	$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
	if ($page < 1 || $page > $nombreDePages) {
		$page = 1;
	}

	// On calcule le numéro du premier rapport qu'on prend pour le LIMIT de MySQL
	$premierRapportAafficher = ($page - 1) * $nombreDeRapportsParPage;

	$rapportsRows = dbFetchAll($base, 'SELECT * FROM rapports WHERE destinataire = ? ORDER BY timestamp DESC LIMIT ?, ?', 'sii', $_SESSION['login'], $premierRapportAafficher, $nombreDeRapportsParPage);
	$nb_rapports = count($rapportsRows);

    debutCarte("Rapports");
	if($nb_rapports > 0) {
		echo '
        <div class="table-responsive">
		<table class="table table-striped table-bordered">
        <tbody>';
		foreach($rapportsRows as $rapports) {
            echo '<td>'.htmlspecialchars($rapports['image'], ENT_QUOTES, 'UTF-8').'</td>';
			if($rapports['statut'] != 0){
				echo '<td><a href="rapports.php?rapport='.$rapports['id'].'">'.htmlspecialchars($rapports['titre'], ENT_QUOTES, 'UTF-8').'</a></td>';
			}
			else {
				echo '<td><strong><a href="rapports.php?rapport='.$rapports['id'].'">'.htmlspecialchars($rapports['titre'], ENT_QUOTES, 'UTF-8').'</a></strong></td>';
			}
			echo '<td><em>'.date('d/m/Y à H\hi', $rapports['timestamp']).'</em></td>';
			echo '<td><form method="post" action="rapports.php" style="display:inline">'.csrfField().'<input type="hidden" name="supprimer" value="'.$rapports['id'].'"><button type="submit" style="background:none;border:none;cursor:pointer;padding:0;"><img src="images/croix.png" alt="supprimer" class="w32"></button></form></td></tr>';
		}
		echo '</tbody></table></div>';
        $supprimer = '<form method="post" action="rapports.php" style="display:inline">'.csrfField().'<input type="hidden" name="supprimer" value="1"><button type="submit" style="background:none;border:none;cursor:pointer;text-decoration:underline;">Supprimer tous les rapports</button></form>';
		$adresse = "rapports.php?";
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
        $pages = 'Pages : '.$premier.' '.$pointsD.' '.$precedent.' <strong>'.$page.'</strong> '.$suivant.' '.$pointsF.' '.$dernier;
	}
	else {
        $pages ="";
        $supprimer = "";
        debutContent();
		echo "<br/>Vous n'avez aucun rapports.<br/>";
        finContent();
	}
	finCarte($supprimer.$pages);
}

include("includes/copyright.php"); ?>
