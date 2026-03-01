<?php
include("includes/basicprivatephp.php");
include("includes/bbcode.php");
require_once("includes/csrf.php");

if(isset($_POST['supprimer']) AND preg_match("#\d#",$_POST['supprimer'])) {
	csrfCheck();
	if($_POST['supprimer'] == 1) {
		dbExecute($base, 'DELETE FROM messages WHERE destinataire = ?', 's', $_SESSION['login']);
	}
	else {
		$supprimerId = (int)$_POST['supprimer'];
		dbExecute($base, 'DELETE FROM messages WHERE id = ? AND destinataire = ?', 'is', $supprimerId, $_SESSION['login']);
	}
}

include("includes/tout.php");

if(isset($_GET['message'])) {
	$messageId = (int)$_GET['message'];
	$ex = dbQuery($base, 'SELECT * FROM messages WHERE ( destinataire = ? OR expeditaire = ? ) AND id = ?', 'ssi', $_SESSION['login'], $_SESSION['login'], $messageId);
	$messages = mysqli_fetch_array($ex);
	$nb_messages = mysqli_num_rows($ex);
	if($nb_messages > 0) {
		if($_SESSION['login'] == $messages['destinataire']) {
			dbExecute($base, 'UPDATE messages SET statut=1 WHERE id = ?', 'i', $messageId);
		}
		debutCarte($messages['titre']);
		debutContent();
		echo BBcode($messages['contenu']);
        finContent();
        finCarte(imageLabel('<img src="images/message_ferme.png" alt="up" class="imageSousMenu"/>','Répondre','ecriremessage.php?reponse=true&destinataire='.$messages['expeditaire']).'<form method="post" action="messages.php" style="display:inline">'.csrfField().'<input type="hidden" name="supprimer" value="'.$messages['id'].'"><button type="submit" style="background:none;border:none;cursor:pointer;padding:0;"><img src="images/croix.png" alt="supprimer" class="imageSousMenu"> Supprimer</button></form>');
	}
	else {
		header('Location: messages.php');
		exit();
	}
}
else {
	$nombreDeMessagesParPage = 15;
	$totalDesMessages = dbCount($base, 'SELECT COUNT(*) AS nb_messages FROM messages WHERE destinataire = ?', 's', $_SESSION['login']);
	$nombreDePages  = ceil($totalDesMessages / $nombreDeMessagesParPage); // Calcul du nombre de pages créées
	// Puis on fait une boucle pour écrire les liens vers chacune des pages

	if (isset($_GET['page']) AND $_GET['page'] <= $nombreDePages AND $_GET['page'] > 0 AND preg_match("#\d#",$_GET['page']))// Quelques vérifications comme si la variable ne contient qu'une suite de chiffres
	{
        $page = $_GET['page']; // Récuperation du numéro de la page
	}
	else // La variable n'existe pas, c'est la première fois qu'on charge la page
	{
        $page = 1; // On se met sur la page 1 (par défaut)
	}

	// On calcule le numéro du premier message qu'on prend pour le LIMIT de MySQL
	$premierMessageAafficher = ($page - 1) * $nombreDeMessagesParPage;

	$ex = dbQuery($base, 'SELECT * FROM messages WHERE destinataire = ? ORDER BY timestamp DESC LIMIT ?, ?', 'sii', $_SESSION['login'], $premierMessageAafficher, $nombreDeMessagesParPage);
	$nb_messages = mysqli_num_rows($ex);
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
		while($messages = mysqli_fetch_array($ex)) {
			if($messages['statut'] != 0){
				echo '<tr><td><a href="messages.php?message='.$messages['id'].'"><img src="images/message_ouvert.png" alt="ouvert" title="Lu" class="w32"/></td></a>';
			}
			else {
				echo '<tr><td><a href="messages.php?message='.$messages['id'].'"><img src="images/message_ferme.png" alt="ferme" title="Non lu" class="w32"/></td></a>';
			}
			echo '<td><a href="messages.php?message='.$messages['id'].'">'.htmlspecialchars($messages['titre'], ENT_QUOTES, 'UTF-8').'</a></td>';
			echo '<td><a href="joueur.php?id='.htmlspecialchars($messages['expeditaire'], ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($messages['expeditaire'], ENT_QUOTES, 'UTF-8').'</a></td>';
			echo '<td><em>'.date('d/m/Y à H\hi', $messages['timestamp']).'</em></td>';
			echo '<td><form method="post" action="messages.php" style="display:inline">'.csrfField().'<input type="hidden" name="supprimer" value="'.$messages['id'].'"><button type="submit" style="background:none;border:none;cursor:pointer;padding:0;"><img src="images/croix.png" alt="supprimer" class="w32"></button></form></td></tr>';
		}
		echo '</tbody></table></div>';
        $supprimer = '<form method="post" action="messages.php" style="display:inline">'.csrfField().'<input type="hidden" name="supprimer" value="1"><button type="submit" style="background:none;border:none;cursor:pointer;text-decoration:underline;">Supprimer tous les messages</button></form>';
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
		echo "Vous n'avez aucun messages ou cette page n'existe pas.<br/>";
        finContent();
	}
    finCarte('<a href="ecriremessage.php">Ecrire</a><a href="messagesenvoyes.php">Envoyés</a>'.$pages);
}

include("includes/copyright.php"); ?>
