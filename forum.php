<?php
require_once("includes/session_init.php");
include("includes/bbcode.php"); // Ajout de Yojim
if (isset($_SESSION['login']))
{
	include("includes/basicprivatephp.php");
}
else
{
	include("includes/basicpublicphp.php");
}

include("includes/layout.php");
debutCarte("Forum"); ?>
<div class="table-responsive">
<?php
	// Vérification du ban forum : requête unique sur dateFin >= CURDATE() (standardisé)
	$isBanned = false;
	if(isset($_SESSION['login'])) {
		// Clean up any expired bans first, then check for an active one
		// MED-036: Probabilistic GC — only clean up expired bans 1% of the time to avoid hot-path writes
		if (mt_rand(1, 100) === 1) {
			dbExecute($base, 'DELETE FROM sanctions WHERE joueur = ? AND dateFin < CURDATE()', 's', $_SESSION['login']);
		}
		$sanction = dbFetchOne($base, 'SELECT * FROM sanctions WHERE joueur = ? AND dateFin >= CURDATE()', 's', $_SESSION['login']);
		if ($sanction) {
			$isBanned = true;
		}
	}
	// Si il est banni
	if ($isBanned) {
		list($annee,$mois,$jour) = explode('-',$sanction['dateFin']);
		$sanction['dateFin'] = $jour.'/'.$mois.'/'.$annee;
		echo "Vous ne pouvez plus accéder au forum car vous avez été banni par <a href=\"ecriremessage.php?destinataire=".htmlspecialchars($sanction['moderateur'], ENT_QUOTES, 'UTF-8')."\" >".htmlspecialchars($sanction['moderateur'], ENT_QUOTES, 'UTF-8')."</a> jusqu'au <strong>".htmlspecialchars($sanction['dateFin'], ENT_QUOTES, 'UTF-8')."</strong>.<br/>";
		echo "Motif de la sanction : ".BBcode($sanction['motif']);
	}
	else {


?>
<table class="table table-striped table-bordered" >
<thead>
<tr>
<?php
if(isset($_SESSION['login'])) {
	echo '<th>Statut</th>';
}
?>
<th >Forum</th>
<th>Sujets</th>
<th>Messages</th>
</tr>
</thead>
<tbody>
<?php
$forumRows = dbFetchAll($base, 'SELECT * FROM forums');

// FM-NEW-001: Hoist viewer's alliance lookup above the loop (was N+1 per alliance forum)
$viewerAllianceId = 0;
if (isset($_SESSION['login'])) {
    try {
        $viewerRow = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);
        $viewerAllianceId = $viewerRow ? (int)$viewerRow['idalliance'] : 0;
    } catch (\Exception $e) {
        // autre table not accessible — viewer has no alliance
    }
}

foreach($forumRows as $forum) {
    // MED-028: Hide alliance-private forums from non-members
    try {
        if (!empty($forum['alliance_id'])) {
            if ($viewerAllianceId !== (int)$forum['alliance_id']) {
                continue; // Skip this forum — viewer is not a member
            }
        }
    } catch (\Exception $e) {
        // alliance_id column not yet present — all forums public, skip silently
    }
    $nbSujets = dbFetchOne($base, 'SELECT count(*) AS nbSujets FROM sujets WHERE idforum = ?', 'i', $forum['id']);
	echo '<tr>';
    if(isset($_SESSION['login'])) {
		$statutForum = dbFetchOne($base, 'SELECT count(*) AS nbLus FROM statutforum WHERE login = ? AND idforum = ?', 'si', $_SESSION['login'], $forum['id']);

		if($statutForum['nbLus'] >= $nbSujets['nbSujets']) {
			echo '<td><img src="images/forum/pasDeNouveauMessage.png" alt="pasDeNouveauMessage" class="w32"/> </td>';
		}
		else {
			echo '<td><img src="images/forum/nouveauMessage.png" alt="nouveauMessage" class="w32"/></td>';
		}
	}
    echo '<td><a href="listesujets.php?id='.(int)$forum['id'].'">'.htmlspecialchars($forum['titre'], ENT_QUOTES, 'UTF-8').'</a></td>';

	echo '<td>'.$nbSujets['nbSujets'].'</td>';
	$nbMessages = dbFetchOne($base, 'SELECT count(*) AS cnt FROM sujets s, reponses r WHERE idforum = ? AND s.id = r.idsujet AND r.visibilite = 1', 'i', $forum['id']);
	echo '<td>'.($nbMessages['cnt']+$nbSujets['nbSujets']).'</td>';

	echo '</tr>';
}
?>
</tbody>
</table>
</div>
<?php
if(isset($_SESSION['login'])) { ?>
<br/>
<p class="legende">
<?php echo important("Légende"); ?>
<img src="images/forum/nouveauMessage.png" alt="nouveauMessage" style="vertical-align:middle" class="w32"/> : Un ou plusieurs nouveaux messages<br/><br/>
<img src="images/forum/pasDeNouveauMessage.png" alt="pasDeNouveauMessage" style="vertical-align:middle" class="w32"/> : Pas de nouveaux messages
</p>
<?php
	}
 }// Ajout par Yojim
finCarte();
include("includes/copyright.php"); ?>
