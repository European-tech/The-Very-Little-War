<?php
session_start();
$_SESSION['start'] = "start"; // Sert a savoir si il faut de nouveau ouvrir une nouvelle session ou non
if (isset($_SESSION['login'])) {
	include("includes/basicprivatephp.php");
} else {
	include("includes/basicpublicphp.php");
}
include("includes/bbcode.php");
require_once("includes/csrf.php");

if (isset($_POST['contenu']) and isset($_GET['id'])) {
	csrfCheck();
	$_GET['id'] = antiXSS($_GET['id']);
	if (preg_match("#^[0-9]*$#", $_GET['id'])) {
		if (isset($_SESSION['login'])) {
			if (!empty($_POST['contenu'])) {
				// Modifié par Yojim
				$getId = (int)$_GET['id'];
				$timestamp = time();
				dbExecute($base, 'INSERT INTO reponses VALUES(default, ?, "1", ?, ?, ?)', 'issi', $getId, $_POST['contenu'], $_SESSION['login'], $timestamp);
				//
				dbExecute($base, 'DELETE FROM statutforum WHERE idsujet = ?', 'i', $getId);
				$nbMessages = dbFetchOne($base, 'SELECT nbMessages FROM autre WHERE login = ?', 's', $_SESSION['login']);
				dbExecute($base, 'UPDATE autre SET nbMessages = ? WHERE login = ?', 'is', ($nbMessages['nbMessages'] + 1), $_SESSION['login']);
				$information = "Votre réponse a été créée.";
			} else {
				$erreur = "Tous les champs ne sont pas remplis.";
			}
		} else {
			$erreur = "T'as essayé de m'avoir ? Eh bah non !";
		}
	} else {
		$erreur = "Mais c'est que tu es trés marrant toi ?";
	}
}

include("includes/tout.php");

if (isset($_GET['id'])) {
	$_GET['id'] = antiXSS($_GET['id']);
	$getId = (int)$_GET['id'];
	$ex = dbQuery($base, 'SELECT * FROM reponses WHERE idsujet = ?', 'i', $getId);
	$nb_resultats = mysqli_num_rows($ex);
	$nombreDeSujetsParPage = 10;
	$nombreDePages  = ceil($nb_resultats / $nombreDeSujetsParPage);
	if (isset($_GET['page']) and $_GET['page'] <= $nombreDePages and $_GET['page'] > 0 and preg_match("#\d#", $_GET['page'])) // Quelques vérifications comme si la variable ne contient qu'une suite de chiffres
	{
		$page = $_GET['page']; // Récuperation du numéro de la page
	} else // La variable n'existe pas, c'est la première fois qu'on charge la page
	{
		if ($nombreDePages > 0) {
			$page = $nombreDePages;
		} else {
			$page = 1;
		}
	}

	// On calcule le numéro du premier message qu'on prend pour le LIMIT de MySQL
	$premierSujetAafficher = ($page - 1) * $nombreDeSujetsParPage;

	// Modifié par Yojim
	if (isset($_SESSION['login'])) {
		$joueur = dbFetchOne($base, 'SELECT moderateur FROM membre WHERE login = ?', 's', $_SESSION['login']);
		// Si le joueur est modérateur, il a accès aux messages masqués
		if ($joueur['moderateur']) {
			$ex1 = dbQuery($base, 'SELECT * FROM reponses WHERE idsujet = ? ORDER BY timestamp ASC LIMIT ?, ?', 'iii', $getId, $premierSujetAafficher, $nombreDeSujetsParPage);
		}
		// Si le joueur n'est pas modérateur, il n'a pas accès au messages masqués
		else {
			$ex1 = dbQuery($base, 'SELECT * FROM reponses WHERE idsujet = ? AND visibilite=1 ORDER BY timestamp ASC LIMIT ?, ?', 'iii', $getId, $premierSujetAafficher, $nombreDeSujetsParPage);
		}
	} else {
		$ex1 = dbQuery($base, 'SELECT * FROM reponses WHERE idsujet = ? AND visibilite=1 ORDER BY timestamp ASC LIMIT ?, ?', 'iii', $getId, $premierSujetAafficher, $nombreDeSujetsParPage);
	}
	//

	$sujet = dbFetchOne($base, 'SELECT * FROM sujets WHERE id = ?', 'i', $getId);

	$javascript = false;
	if ($sujet['idforum'] == 8) {
		$javascript = true;
	}

	if (isset($_SESSION['login'])) {
		$existeDeja = dbFetchOne($base, 'SELECT count(*) AS existeDeja FROM statutforum WHERE login = ? AND idsujet = ?', 'si', $_SESSION['login'], $getId);

		if ($existeDeja['existeDeja'] == 0 and $sujet['statut'] != 1) {
			dbExecute($base, 'INSERT INTO statutforum VALUES(?, ?, ?)', 'sis', $_SESSION['login'], $getId, $sujet['idforum']);
		}
	}
	$forum = dbFetchOne($base, 'SELECT titre FROM forums WHERE id = ?', 'i', $sujet['idforum']);

	// Ajout de Yojim
	// On vérifie si l'utilisateur n'est pas banni du forum
	if (isset($_SESSION['login'])) {
		$ex4 = dbQuery($base, 'SELECT * FROM sanctions WHERE joueur = ?', 's', $_SESSION['login']);
	} else {
		$ex4 = dbQuery($base, 'SELECT * FROM sanctions WHERE joueur = ?', 's', 'lakzknsdjnsqjdnjibqsdhubqsdjqushd');
	}

	// Si il est banni
	if (mysqli_num_rows($ex4)) {
		$sanction = mysqli_fetch_array($ex4);
		list($annee, $mois, $jour) = explode('-', $sanction['dateFin']);
		$sanction['dateFin'] = $jour . '/' . $mois . '/' . $annee;
		echo "Vous ne pouvez plus accéder au forum car vous avez été banni par <a href=\"ecriremessage.php?destinataire=" . htmlspecialchars($sanction['moderateur'], ENT_QUOTES, 'UTF-8') . "\" class=\"lienVisible\">" . htmlspecialchars($sanction['moderateur'], ENT_QUOTES, 'UTF-8') . "</a> jusqu'au <strong>" . htmlspecialchars($sanction['dateFin'], ENT_QUOTES, 'UTF-8') . "</strong>.<br/>";
		echo "Motif de la sanction : " . BBcode($sanction['motif']);
	} else {

		$image = dbFetchOne($base, 'SELECT image, count(image) as nb FROM autre WHERE login = ?', 's', $sujet['auteur']);
		$couleur = rangForum($sujet['auteur']);
		if ($image['nb'] == 0) { // s'il le joueur n'existe plus, on prends l'image par défaut
			$image['image'] = "defaut.png";
		}

		$adresse = "sujet.php?";
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
		$pages = $premier . ' ' . $pointsD . ' ' . $precedent . ' <strong>' . $page . '</strong> ' . $suivant . ' ' . $pointsF . ' ' . $dernier;

		debutCarte();
		debutContent();
		$forum = dbFetchOne($base, 'SELECT titre FROM forums WHERE id = ?', 'i', $sujet['idforum']);
		echo '<a href="forum.php">Forum</a> > <a href="listesujets.php?id=' . $sujet['idforum'] . '">' . $forum['titre'] . '</a> > ' . $sujet['titre'];
		finContent();
		finCarte();

		$editer = "";
		if (isset($_SESSION['login']) and $_SESSION['login'] == $sujet['auteur']) {
			$editer = '<a href="editer.php?id=' . $sujet['id'] . '&type=1">Editer</a>';
		}
		carteForum('<img alt="profil" src="images/profil/' . htmlspecialchars($image['image'], ENT_QUOTES, 'UTF-8') . '" style="max-width:70px;max-height:70px;border-radius:10px;"/>', '<a href="joueur.php?id=' . $sujet['auteur'] . '">' . $sujet['auteur'] . '</a>', date('d/m/Y à H\hi', $sujet['timestamp']), $sujet['titre'], BBcode($sujet['contenu']), $couleur, 'Page : ' . $pages . $editer);


		if ($nb_resultats > 0) {
			while ($reponse = mysqli_fetch_array($ex1)) {

				$couleur = rangForum($reponse['auteur']);

				// Ajout de Yojim
				// Si le message est masqué, on change sa couleur
				if (!$reponse['visibilite']) {
					$couleur = '#7A7B7A; opacity:0.35';
				}
				// Sinon on laisse la couleur normale
				//
				$image = dbFetchOne($base, 'SELECT image, count(image) as nb FROM autre WHERE login = ?', 's', $reponse['auteur']);
				if ($image['nb'] == 0) { // s'il le joueur n'existe plus, on prends l'image par défaut
					$image['image'] = "defaut.png";
				}

				// On regarde si l'utilisateur connecté est un modérateur
				$editer = false;
				if (isset($_SESSION['login'])) {
					$donnees4 = dbFetchOne($base, 'SELECT moderateur FROM membre WHERE login = ?', 's', $_SESSION['login']);
				}
				if (isset($_SESSION['login']) and $_SESSION['login'] == $reponse['auteur'] and $donnees4['moderateur'] == 0) {
					$editer = '<a href="editer.php?id=' . $reponse['id'] . '&type=2">Editer</a> <a href="editer.php?id=' . $reponse['id'] . '&type=3">Supprimer</a>';
				}
				// Si l'utilisateur est un modérateur
				elseif (isset($_SESSION['login']) and $donnees4['moderateur'] == 1) {
					// Si le message est masqué, on propose de l'afficher
					if (!$reponse['visibilite']) {
						$editer = '<a href="editer.php?id=' . $reponse['id'] . '&type=2">Editer</a> <a href="editer.php?id=' . $reponse['id'] . '&type=3">Supprimer</a> <a href="editer.php?id=' . $reponse['id'] . '&type=4">Afficher</a>';
					}
					// Sinon, on propose de le masquer
					else {
						$editer = '<a href="editer.php?id=' . $reponse['id'] . '&type=2">Editer</a> <a href="editer.php?id=' . $reponse['id'] . '&type=3">Supprimer</a> <a href="editer.php?id=' . $reponse['id'] . '&type=5">Masquer</a>';
					}
				}

				carteForum('<img alt="profil" src="images/profil/' . htmlspecialchars($image['image'], ENT_QUOTES, 'UTF-8') . '" style="max-width:70px;max-height:70px;border-radius:10px;"/>', '<a href="joueur.php?id=' . $reponse['auteur'] . '">' . $reponse['auteur'] . '</a>', date('d/m/Y à H\hi', $reponse['timestamp']), $sujet['titre'], BBcode($reponse['contenu'], $javascript), $couleur, $editer);
			}
		} else {
			debutCarte();
			debutContent();
			echo '<p>Ce sujet ne contient aucune réponse.</p>';
			finContent();
			finCarte();
		}

		debutCarte();
		debutContent();
		echo 'Page : ' . $pages;
		finContent();
		finCarte();

		if (isset($_SESSION['login'])) {
			debutCarte("Créer une réponse");
			if ($sujet['statut'] == 0) {
				debutListe();
				creerBBcode("contenu");
				item(['form' => ['sujet.php?id=' . $getId, "reponseForm"], 'floating' => false, 'titre' => "Réponse", 'input' => csrfField() . '<textarea name="contenu" id="contenu" rows="10" cols="50"></textarea>']);
				item(['input' => submit(['titre' => 'Répondre', 'form' => 'reponseForm'])]);
				finListe();
			} else {
				echo "Ce sujet est verrouillé.";
			}
			finCarte();
		}
	}
} else {
	echo "<p>Bravo, t'es un vrai hackeur maintenant que tu sais modifier la barre URL !</p>";
}


include("includes/copyright.php"); ?>
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/mathjax/2.7.9/MathJax.js?config=TeX-AMS-MML_HTMLorMML"></script>
