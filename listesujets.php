<?php
require_once("includes/session_init.php");
if (isset($_SESSION['login'])) {
	include("includes/basicprivatephp.php");
} else {
	include("includes/basicpublicphp.php");
}
require_once("includes/csrf.php");

if(!isset($_GET['id'])
	or intval(trim($_GET['id'])) == 0
	or $_GET['id'] < 1
	or $_GET['id'] > 8
	or !preg_match("#^[0-9]*$#", $_GET['id'])
	) {
	header('Location: forum.php');
	exit();
}

include("includes/bbcode.php");

$_GET['id'] = trim($_GET['id']);
$getId = (int)$_GET['id'];

if (isset($_POST['titre']) and isset($_POST['contenu'])) {
	csrfCheck();
	if (isset($_SESSION['login'])) {
		if (!empty($_POST['titre']) and !empty($_POST['contenu']) and mb_strlen($_POST['contenu']) <= 10000 and mb_strlen($_POST['titre']) <= 200) {
			$timestamp = time();
			dbExecute($base, 'INSERT INTO sujets VALUES(default, ?, ?, ?, ?, default, ?)', 'isssi', $getId, $_POST['titre'], $_POST['contenu'], $_SESSION['login'], $timestamp);

			$sujet = dbFetchOne($base, 'SELECT id FROM sujets WHERE contenu = ?', 's', $_POST['contenu']);

			dbExecute($base, 'INSERT INTO statutforum VALUES(?, ?, ?)', 'sii', $_SESSION['login'], $sujet['id'], $getId);
			$information = "Votre sujet a été créé.";
		} else {
			$erreur = "Tous les champs ne sont pas remplis.";
		}
	} else {
		$erreur = "T'as essayé de m'avoir ? Eh bah non !";
	}

}


include("includes/tout.php");

if (isset($_SESSION['login'])) {
	include("includes/basicprivatehtml.php");
} else {
	include("includes/basicpublichtml.php");
}

$idforum = dbFetchOne($base, 'SELECT titre, id FROM forums WHERE id = ?', 'i', $getId);
?>

<div class="table-responsive">
	<?php

	debutCarte($idforum['titre']);
	$nb_resultats = dbCount($base, 'SELECT count(*) FROM sujets WHERE idforum = ?', 'i', $getId);
	$nombreDeSujetsParPage = FORUM_POSTS_PER_PAGE;
	$nombreDePages  = ceil($nb_resultats / $nombreDeSujetsParPage);
	$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
	if ($page < 1 || $page > $nombreDePages) {
		$page = 1;
	}

	// On calcule le numéro du premier message qu'on prend pour le LIMIT de MySQL
	$premierSujetAafficher = ($page - 1) * $nombreDeSujetsParPage;
	$ex1 = dbQuery($base, 'SELECT * FROM sujets WHERE idforum = ? ORDER BY statut, timestamp DESC LIMIT ?, ?', 'iii', $getId, $premierSujetAafficher, $nombreDeSujetsParPage);
	if ($nb_resultats > 0) {
		echo '
		<div class="table-responsive">
		<table class="table table-striped table-bordered">
		<thead>
		<tr>
		<th>Statut</th>
		<th>Sujet</th>
		<th>Auteur</th>
		<th>Date</th>
		</tr>
		</thead>
		<tbody>';
		while ($sujet = mysqli_fetch_array($ex1)) {
			echo '<tr>';
			if ($sujet['statut'] == 0) {
				if (isset($_SESSION['login'])) {
					$statutForum = dbFetchOne($base, 'SELECT count(*) AS luOuPas FROM statutforum WHERE idsujet = ? AND login = ?', 'is', $sujet['id'], $_SESSION['login']);

					if ($statutForum['luOuPas'] == 0) {
						echo '<td><img src="images/forum/nouveauMessage.png" alt="nouveauMessage" class="w32"/></td>';
					} else {
						echo '<td><img src="images/forum/pasDeNouveauMessage.png" alt="pasDeNouveauMessage" class="w32"/></td>';
					}
				} else {
					echo '<td><img src="images/forum/pasDeNouveauMessage.png" alt="pasDeNouveauMessage" class="w32"/></td>';
				}
			} else {
				echo '<td><img src="images/forum/sujetVerouille.png" alt="sujetVerouille" class="w32"/></td>';
			}
			echo '<td><a href="sujet.php?id=' . $sujet['id'] . '">' . htmlspecialchars($sujet['titre'], ENT_QUOTES, 'UTF-8') . '</a>';
			if (isset($_SESSION['login']) and $_SESSION['login'] == $sujet['auteur']) {
				echo '<br/><a href="editer.php?id=' . $sujet['id'] . '&type=1"><em>Editer</em></a>';
			}
			echo '</td>';
			echo '
		<td>' . joueur($sujet['auteur']) . '</td>
		<td><em>' . date('d/m/Y à H\hi', $sujet['timestamp']) . '</em></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div><br/>';
		echo '<p>Page : ';
		$adresse = "listesujets.php?";
		$premier = '';
		if ($page > 2) {
			$premier = '<a href="' . $adresse . 'page=1&id=' . $getId . '">1</a>';
		}
		$pointsD = '';
		if ($page > 3) {
			$pointsD = '...';
		}
		$precedent = '';
		if ($page > 1) {
			$precedent = '<a href="' . $adresse . 'page=' . ($page - 1) . '&id=' . $getId . '">' . ($page - 1) . '</a>';
		}
		$suivant = '';
		if ($page + 1 <= $nombreDePages) {
			$suivant = '<a href="' . $adresse . 'page=' . ($page + 1) . '&id=' . $getId . '">' . ($page + 1) . '</a>';
		}
		$pointsF = '';
		if ($page + 3 <= $nombreDePages) {
			$pointsF = '...';
		}
		$dernier = '';
		if ($page + 2 <= $nombreDePages) {
			$dernier = '<a href="' . $adresse . 'page=' . $nombreDePages . '&id=' . $getId . '">' . $nombreDePages . '</a>';
		}
		echo $premier . ' ' . $pointsD . ' ' . $precedent . ' <strong>' . $page . '</strong> ' . $suivant . ' ' . $pointsF . ' ' . $dernier;
		?></p>
		<p class="legende">
			<?php
			if (isset($_SESSION['login'])) {
				echo important('Légende'); ?>
				<img src="images/forum/nouveauMessage.png" alt="nouveauMessage" style="vertical-align:middle" class="w32" /> : Un ou plusieurs nouveaux messages<br /><br />
				<img src="images/forum/pasDeNouveauMessage.png" alt="pasDeNouveauMessage" style="vertical-align:middle" class="w32" /> : Pas de nouveaux messages<br /><br />
				<img src="images/forum/sujetVerouille.png" alt="sujetVerouille" style="vertical-align:middle" class="w32" /> : Sujet verrouillé<br />
			<?php
			} else {
				echo important('Légende'); ?>
				<img src="images/forum/pasDeNouveauMessage.png" alt="pasDeNouveauMessage" style="vertical-align:middle" /> : Sujet ouvert<br />
				<img src="images/forum/sujetVerouille.png" alt="sujetVerouille" style="vertical-align:middle" /> : Sujet verrouillé<br />
			<?php } ?>
		</p><?php
	} else {
		echo '<p>Cette partie du forum ne contient aucun sujets ou ce forum n\'existe pas.</p>';
	}
	finCarte();

	if (isset($_SESSION['login'])) {
		debutCarte("Créer un sujet");

		?><form action="listesujets.php?id=<?php if (isset($_GET['id'])) {
									echo (int)$_GET['id'];
								} ?>" method="post" name="formCreerSujet"><?php echo csrfField();
																																debutListe();
																																item(['titre' => 'Titre', 'input' => '<input type="text" name="titre" id="titre" class="form-control"/>', 'floating' => true]);
																																creerBBcode("contenu");
																																item(['floating' => true, 'titre' => "Contenu", 'input' => '<textarea name="contenu" id="contenu" rows="10" cols="50"></textarea>']);
																																item(['input' => submit(['titre' => 'Créer', 'form' => 'formCreerSujet'])]);
																																finListe();
																																?></form><?php
		finCarte();
	}

	include("includes/copyright.php"); ?>
