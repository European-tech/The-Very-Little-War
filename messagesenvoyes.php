<?php
include("includes/basicprivatephp.php");

include("includes/layout.php");

debutCarte("Messages envoyés");
?>
<div class="table-responsive">
<?php
// LOW-033: exclude soft-deleted sent messages
$totalMessages = dbCount($base, 'SELECT COUNT(*) AS nb FROM messages WHERE expeditaire = ? AND deleted_by_sender=0', 's', $_SESSION['login']);
$nombreDeMessagesParPage = MESSAGES_PER_PAGE;
$nombreDePages = max(1, (int)ceil($totalMessages / $nombreDeMessagesParPage));
$page = max(1, min($nombreDePages, intval($_GET['page'] ?? 1)));
$offset = ($page - 1) * $nombreDeMessagesParPage;

$messageRows = dbFetchAll($base, 'SELECT * FROM messages WHERE expeditaire = ? AND deleted_by_sender=0 ORDER BY timestamp DESC LIMIT ?, ?', 'sii', $_SESSION['login'], $offset, $nombreDeMessagesParPage);
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

	$adresse = "messagesenvoyes.php?";
	$premier = ($page > 2) ? '<a href="' . $adresse . 'page=1">1</a>' : '';
	$pointsD = ($page > 3) ? '...' : '';
	$precedent = ($page > 1) ? '<a href="' . $adresse . 'page=' . ($page - 1) . '">' . ($page - 1) . '</a>' : '';
	$suivant = ($page + 1 <= $nombreDePages) ? '<a href="' . $adresse . 'page=' . ($page + 1) . '">' . ($page + 1) . '</a>' : '';
	$pointsF = ($page + 3 <= $nombreDePages) ? '...' : '';
	$dernier = ($page + 2 <= $nombreDePages) ? '<a href="' . $adresse . 'page=' . $nombreDePages . '">' . $nombreDePages . '</a>' : '';
	$pages = 'Pages : ' . $premier . ' ' . $pointsD . ' ' . $precedent . ' <strong>' . $page . '</strong> ' . $suivant . ' ' . $pointsF . ' ' . $dernier;
}
else {
	$pages = '';
	echo '<p style="text-align:center; color:#999; padding:20px;">Aucun message envoyé.</p>';
}
?>
</div>

<?php
finCarte('<a href="messages.php">Reçus</a>' . (isset($pages) ? ' ' . $pages : ''));
include("includes/copyright.php"); ?>
