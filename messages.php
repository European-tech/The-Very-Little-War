<?php
include("includes/basicprivatephp.php");
include("includes/bbcode.php");
require_once("includes/csrf.php");

if(isset($_POST['supprimer_tout'])) {
	csrfCheck();
	// LOW-033: soft delete — mark all as deleted by recipient, then purge fully-deleted rows
	dbExecute($base, 'UPDATE messages SET deleted_by_recipient=1 WHERE destinataire = ?', 's', $_SESSION['login']);
	dbExecute($base, 'DELETE FROM messages WHERE deleted_by_sender=1 AND deleted_by_recipient=1');
} elseif(isset($_POST['supprimer']) AND preg_match("#^\d+$#",$_POST['supprimer'])) {
	csrfCheck();
	$supprimerId = (int)$_POST['supprimer'];
	// LOW-033: soft delete by recipient
	dbExecute($base, 'UPDATE messages SET deleted_by_recipient=1 WHERE id = ? AND destinataire = ?', 'is', $supprimerId, $_SESSION['login']);
	dbExecute($base, 'DELETE FROM messages WHERE id = ? AND deleted_by_sender=1 AND deleted_by_recipient=1', 'i', $supprimerId);
}

include("includes/layout.php");

// LOW-030: display flash message from PM send redirect
if (isset($_SESSION['flash_message'])) {
    echo '<div class="toast-text">' . htmlspecialchars($_SESSION['flash_message'], ENT_QUOTES, 'UTF-8') . '</div>';
    unset($_SESSION['flash_message']);
}

if(isset($_GET['message'])) {
	$messageId = (int)$_GET['message'];
	// LOW-033: exclude soft-deleted messages from view
	$messages = dbFetchOne($base, 'SELECT * FROM messages WHERE ( (destinataire = ? AND deleted_by_recipient=0) OR (expeditaire = ? AND deleted_by_sender=0) ) AND id = ?', 'ssi', $_SESSION['login'], $_SESSION['login'], $messageId);
	$nb_messages = $messages ? 1 : 0;
	if($nb_messages > 0) {
		if($_SESSION['login'] == $messages['destinataire']) {
			// AUTH-P7-002: marking a message as read via GET is acceptable — the change is idempotent,
			// ownership is verified above (destinataire check), and there is no financial impact.
			// Only update when status is genuinely unread to avoid spurious writes.
			dbExecute($base, 'UPDATE messages SET statut=1 WHERE id = ? AND statut=0', 'i', $messageId);
		}
		debutCarte(htmlspecialchars($messages['titre'], ENT_QUOTES, 'UTF-8'));
		debutContent();
		echo BBcode($messages['contenu']);
        finContent();
        finCarte(imageLabel('<img src="images/message_ferme.png" alt="up" class="imageSousMenu"/>','Répondre','ecriremessage.php?reponse=true&destinataire='.htmlspecialchars($messages['expeditaire'], ENT_QUOTES, 'UTF-8')).'<form method="post" action="messages.php" style="display:inline">'.csrfField().'<input type="hidden" name="supprimer" value="'.$messages['id'].'"><button type="submit" style="background:none;border:none;cursor:pointer;padding:0;"><img src="images/croix.png" alt="supprimer" class="imageSousMenu"> Supprimer</button></form>');
	}
	else {
		header('Location: messages.php');
		exit();
	}
}
else {
	$nombreDeMessagesParPage = MESSAGES_PER_PAGE;
	$totalDesMessages = dbCount($base, 'SELECT COUNT(*) AS nb_messages FROM messages WHERE destinataire = ? AND deleted_by_recipient=0', 's', $_SESSION['login']);
	$nombreDePages  = ceil($totalDesMessages / $nombreDeMessagesParPage); // Calcul du nombre de pages créées
	// Puis on fait une boucle pour écrire les liens vers chacune des pages

	$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
	if ($page < 1 || $page > $nombreDePages) {
		$page = 1;
	}

	// On calcule le numéro du premier message qu'on prend pour le LIMIT de MySQL
	$premierMessageAafficher = ($page - 1) * $nombreDeMessagesParPage;

	// LOW-033: exclude soft-deleted messages
	$messagesRows = dbFetchAll($base, 'SELECT * FROM messages WHERE destinataire = ? AND deleted_by_recipient=0 ORDER BY timestamp DESC LIMIT ?, ?', 'sii', $_SESSION['login'], $premierMessageAafficher, $nombreDeMessagesParPage);
	$nb_messages = count($messagesRows);
	debutCarte("Messages");
	if($nb_messages > 0) {
		echo '<div class="table-responsive"><table class="table table-striped table-bordered">
		<thead>
		<tr>
		<th>Statut</th>
		<th>Titre</th>
		<th>Auteur</th>
		<th>Date</th>
		<th>Action</th>
		</tr></thead><tbody>';
		foreach($messagesRows as $messages) {
			if($messages['statut'] != 0){
				echo '<tr><td><a href="messages.php?message='.$messages['id'].'"><img src="images/message_ouvert.png" alt="ouvert" title="Lu" class="w32"/></a></td>';
			}
			else {
				echo '<tr><td><a href="messages.php?message='.$messages['id'].'"><img src="images/message_ferme.png" alt="ferme" title="Non lu" class="w32"/></a></td>';
			}
			echo '<td><a href="messages.php?message='.$messages['id'].'">'.htmlspecialchars($messages['titre'], ENT_QUOTES, 'UTF-8').'</a></td>';
			echo '<td><a href="joueur.php?id='.htmlspecialchars($messages['expeditaire'], ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($messages['expeditaire'], ENT_QUOTES, 'UTF-8').'</a></td>';
			echo '<td><em>'.date('d/m/Y à H\hi', $messages['timestamp']).'</em></td>';
			echo '<td><form method="post" action="messages.php" style="display:inline">'.csrfField().'<input type="hidden" name="supprimer" value="'.$messages['id'].'"><button type="submit" style="background:none;border:none;cursor:pointer;padding:0;"><img src="images/croix.png" alt="supprimer" class="w32"></button></form></td></tr>';
		}
		echo '</tbody></table></div>';
        // LOW-032: require JS confirmation before deleting all messages
        $supprimer = '<form method="post" action="messages.php" style="display:inline">'.csrfField().'<input type="hidden" name="supprimer_tout" value="1"><button type="submit" data-confirm="Supprimer tous les messages ? Cette action est irréversible." style="background:none;border:none;cursor:pointer;text-decoration:underline;">Supprimer tous les messages</button></form>';
		$adresse = "messages.php?";
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
		echo '<p style="text-align:center; color:#999; padding:20px;">Votre boîte de réception est vide.</p>';
        finContent();
	}
    finCarte('<a href="ecriremessage.php">Ecrire</a><a href="messagesenvoyes.php">Envoyés</a>'.$pages.' '.$supprimer);
}

include("includes/copyright.php"); ?>
