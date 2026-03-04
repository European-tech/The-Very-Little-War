<?php
include("includes/basicprivatephp.php");

include("includes/layout.php");

debutCarte("Messages envoyés");
?>
<div class="table-responsive">
<?php
$messageRows = dbFetchAll($base, 'SELECT * FROM messages WHERE expeditaire = ? ORDER BY timestamp DESC', 's', $_SESSION['login']);
$nb_messages = count($messageRows);
if($nb_messages > 0) {
	echo '<table class="table table-striped table-bordered">
	<thead>
	<tr>
	<th>Titre</th>
	<th>Destinataire</th>
	<th>Date</th>
	</tr></thead><tbody>';
	foreach($messageRows as $message) {
		echo '<tr><td><a href="messages.php?message='.(int)$message['id'].'">'.htmlspecialchars($message['titre'], ENT_QUOTES, 'UTF-8').'</a></td>';
		echo '<td><a href="joueur.php?id='.htmlspecialchars($message['destinataire'], ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($message['destinataire'], ENT_QUOTES, 'UTF-8').'</a></td>';
		echo '<td><em>'.date('d/m/Y à H\hi', $message['timestamp']).'</em></td></tr>';
	}
	echo '</tbody></table>';
}
else {
	echo "Vous n'avez envoyé aucun messages.";
}
?>
</div>

<?php
finCarte();
include("includes/copyright.php"); ?>
