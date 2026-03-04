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
	// Ajout de Yojim
	// On vérifie si l'utilisateur n'est pas banni du forum
	$isBanned = false;
	if(isset($_SESSION['login'])) {
		$sanction = dbFetchOne($base, 'SELECT * FROM sanctions WHERE joueur = ?', 's', $_SESSION['login']);
		if ($sanction) {
			$isBanned = true;
		}
	}
	// Si il est banni
	if ($isBanned) {
		// On calcul la différence entre la date de fin et la date actuelle
		$diff = dbFetchOne($base, 'SELECT DATEDIFF(CURDATE(), ?) AS d', 's', $sanction['dateFin']);
		// Si la date de fin de la sanction est passée, on supprime la sanction
		if ($diff['d'] >= 0){
			dbExecute($base, 'DELETE FROM sanctions WHERE joueur = ?', 's', $_SESSION['login']);
			// Rafraichissement de la page
			echo "<script>window.location.replace(\"forum.php\")</script>";
		}
		else {
			list($annee,$mois,$jour) = explode('-',$sanction['dateFin']);
			$sanction['dateFin'] = $jour.'/'.$mois.'/'.$annee;
			echo "Vous ne pouvez plus accéder au forum car vous avez été banni par <a href=\"ecriremessage.php?destinataire=".htmlspecialchars($sanction['moderateur'], ENT_QUOTES, 'UTF-8')."\" >".htmlspecialchars($sanction['moderateur'], ENT_QUOTES, 'UTF-8')."</a> jusqu'au <strong>".htmlspecialchars($sanction['dateFin'], ENT_QUOTES, 'UTF-8')."</strong>.<br/>";
			echo "Motif de la sanction : ".BBcode($sanction['motif']);
		}
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

foreach($forumRows as $forum) {
    $nbSujets = dbFetchOne($base, 'SELECT count(*) AS nbSujets FROM sujets WHERE idforum = ? AND statut = 0', 'i', $forum['id']);
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
	$nbMessages = dbFetchOne($base, 'SELECT count(*) AS cnt FROM sujets s, reponses r WHERE idforum = ? AND s.id = r.idsujet', 'i', $forum['id']);
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
