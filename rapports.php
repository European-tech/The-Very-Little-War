<?php
include("includes/basicprivatephp.php");
require_once("includes/csrf.php");

if(isset($_POST['supprimer']) AND preg_match("#^\d+$#",$_POST['supprimer'])) {
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
		// AUTH-P7-001: marking a report as read via GET is acceptable — the change is idempotent,
		// ownership is verified above (destinataire = $_SESSION['login']), and there is no financial
		// or game-state impact. A cross-site trigger cannot cause damage beyond toggling read status.
		dbExecute($base, 'UPDATE rapports SET statut=1 WHERE id = ? AND statut=0', 'i', $rapportId);

        debutCarte(htmlspecialchars($rapports['titre'], ENT_QUOTES, 'UTF-8'));
		debutContent();
		// Report content is HTML generated server-side by combat/game_actions
		// Sanitize any residual user-controlled data (player names, alliance tags)
		$allowedTags = '<a><br><br/><strong><b><i><em><p><div><span><img><table><tr><td><th><ul><ol><li><hr>';
		$content = strip_tags($rapports['contenu'], $allowedTags);
		// Strip event handlers, style attributes, and dangerous href values (P5-GAP-010)
		$content = preg_replace('/\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $content);
		$content = preg_replace('/\s+style\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $content);
		$content = preg_replace('/href\s*=\s*["\']?\s*javascript\s*:/i', 'href="', $content);

		// SOC-P6-002: Pact accept/refuse form — injected fresh with the viewer's own CSRF token.
		// The stored report contains [PACT_ID:N] instead of an embedded form (see allianceadmin.php SOC-P6-001 fix).
		if (preg_match('/\[PACT_ID:(\d+)\]/', $content, $pactMatch)) {
			$declId = (int)$pactMatch[1];
			// Check the declaration exists, is a pending pact (type=1, valide=0)
			$pactDecl = dbFetchOne($base, 'SELECT d.id, d.valide, d.alliance2, a1.tag AS tag1 FROM declarations d JOIN alliances a1 ON a1.id=d.alliance1 WHERE d.id=? AND d.type=1 AND d.valide=0', 'i', $declId);
			// Get current viewer's alliance
			$myAllianceRow = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);
			$myAllianceId = $myAllianceRow ? (int)$myAllianceRow['idalliance'] : 0;
			// Only render the form if the viewer belongs to alliance2 (the target)
			$pactForm = '';
			if ($pactDecl && $myAllianceId > 0 && (int)$pactDecl['alliance2'] === $myAllianceId) {
				$pactForm = '<form action="validerpacte.php" method="post">'
					. csrfField()
					. '<input type="hidden" name="idDeclaration" value="' . $declId . '"/>'
					. '<button type="submit" name="accepter" class="button button-small button-fill color-green">Accepter</button> '
					. '<button type="submit" name="refuser" class="button button-small button-fill color-red">Refuser</button>'
					. '</form>';
			}
			// Remove the marker from displayed text and append the fresh form
			$content = str_replace('[PACT_ID:' . $declId . ']', '', $content) . $pactForm;
		}

		echo $content;
        finContent();
        finCarte('<form method="post" action="rapports.php" style="display:inline">'.csrfField().'<input type="hidden" name="supprimer" value="'.$rapports['id'].'"><button type="submit" style="background:none;border:none;cursor:pointer;padding:0;"><img src="images/croix.png" alt="supprimer" class="imageSousMenu"> Supprimer</button></form>');

		// Post-defeat recovery card (P1-D8-045)
		if (strpos($rapports['titre'], 'perdez') !== false) {
?>
<div class="card" style="background:#fff3e0;border-left:4px solid #ff9800;">
    <div class="card-content card-content-padding">
        <p><strong>Ne baissez pas les bras !</strong></p>
        <p>Votre armee peut etre reformee rapidement.</p>
        <ul>
            <li><a href="armee.php">Reformer vos molecules</a></li>
            <li><a href="regles.php#formations">Apprendre les formations defensives</a></li>
            <li>Pensez a espionner avant d'attaquer : <a href="attaquer.php">Espionnage</a></li>
        </ul>
    </div>
</div>
<?php
		}
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
            $imageCell = strip_tags($rapports['image'], '<img>');
            // Strip event handlers from any allowed <img> tags
            $imageCell = preg_replace('/\s+on\w+\s*=\s*"[^"]*"/i', '', $imageCell);
            $imageCell = preg_replace('/\s+on\w+\s*=\s*\'[^\']*\'/i', '', $imageCell);
            $imageCell = preg_replace('/\s+on\w+\s*=[^\s>]*/i', '', $imageCell); // unquoted handlers
            echo '<tr><td>' . $imageCell . '</td>';
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
		echo '<p style="text-align:center; color:#999; padding:20px;">Aucun rapport pour le moment.</p>';
        finContent();
	}
	finCarte($supprimer.$pages);
}

include("includes/copyright.php"); ?>
