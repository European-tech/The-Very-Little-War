<?php
require_once("includes/session_init.php");
if (isset($_SESSION['login'])) {
	include("includes/basicprivatephp.php");
} else {
	include("includes/basicpublicphp.php");
}
include("includes/bbcode.php");
require_once("includes/csrf.php");

if (isset($_POST['contenu']) and isset($_GET['id'])) {
	csrfCheck();
	$_GET['id'] = trim($_GET['id']);
	if (preg_match("#^[0-9]+$#", $_GET['id'])) {
		if (isset($_SESSION['login'])) {
			// Check if poster is banned from forum
			$banCheck = dbFetchOne($base, 'SELECT id, dateFin FROM sanctions WHERE joueur = ?', 's', $_SESSION['login']);
			if ($banCheck) {
				$diff = dbFetchOne($base, 'SELECT DATEDIFF(CURDATE(), ?) AS d', 's', $banCheck['dateFin']);
				if ($diff['d'] >= 0) {
					dbExecute($base, 'DELETE FROM sanctions WHERE id = ?', 'i', $banCheck['id']);
				} else {
					$erreur = "Vous êtes banni du forum jusqu'au " . htmlspecialchars($banCheck['dateFin'], ENT_QUOTES, 'UTF-8') . ".";
				}
			}
			if (empty($erreur) && !empty($_POST['contenu']) && mb_strlen($_POST['contenu']) <= 10000) {
				$getId = (int)$_GET['id'];
				// Check topic is not locked (P5-GAP-023)
				$topicStatus = dbFetchOne($base, 'SELECT statut FROM sujets WHERE id = ?', 'i', $getId);
				if (!$topicStatus) {
					$erreur = "Ce sujet n'existe pas.";
				} elseif ($topicStatus['statut'] == 1) {
					$erreur = "Ce sujet est verrouillé.";
				} else {
				$timestamp = time();
				dbExecute($base, 'INSERT INTO reponses VALUES(default, ?, "1", ?, ?, ?)', 'issi', $getId, $_POST['contenu'], $_SESSION['login'], $timestamp);
				//
				dbExecute($base, 'DELETE FROM statutforum WHERE idsujet = ?', 'i', $getId);
				$nbMessages = dbFetchOne($base, 'SELECT nbMessages FROM autre WHERE login = ?', 's', $_SESSION['login']);
				dbExecute($base, 'UPDATE autre SET nbMessages = ? WHERE login = ?', 'is', ($nbMessages['nbMessages'] + 1), $_SESSION['login']);
				header("Location: sujet.php?id=" . (int)$_GET['id']); exit;
				} // end locked topic check
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

include("includes/layout.php");

if (isset($_GET['id'])) {
	$_GET['id'] = trim($_GET['id']);
	$getId = (int)$_GET['id'];
	// Count replies based on visibility (mods see hidden posts, others don't)
	$isModerator = false;
	if (isset($_SESSION['login'])) {
		$modCheck = dbFetchOne($base, 'SELECT moderateur FROM membre WHERE login = ?', 's', $_SESSION['login']);
		$isModerator = !empty($modCheck['moderateur']);
	}
	if ($isModerator) {
		$countRow = dbFetchOne($base, 'SELECT COUNT(*) AS cnt FROM reponses WHERE idsujet = ?', 'i', $getId);
	} else {
		$countRow = dbFetchOne($base, 'SELECT COUNT(*) AS cnt FROM reponses WHERE idsujet = ? AND visibilite=1', 'i', $getId);
	}
	$nb_resultats = $countRow ? (int)$countRow['cnt'] : 0;
	$nombreDeSujetsParPage = FORUM_POSTS_PER_PAGE;
	$nombreDePages  = ceil($nb_resultats / $nombreDeSujetsParPage);
	$page = isset($_GET['page']) ? intval($_GET['page']) : 0;
	if ($page < 1 || $page > $nombreDePages) {
		$page = ($nombreDePages > 0) ? $nombreDePages : 1;
	}

	// On calcule le numéro du premier message qu'on prend pour le LIMIT de MySQL
	$premierSujetAafficher = ($page - 1) * $nombreDeSujetsParPage;

	// Si le joueur est modérateur, il a accès aux messages masqués
	if ($isModerator) {
		$reponseRows = dbFetchAll($base, 'SELECT * FROM reponses WHERE idsujet = ? ORDER BY timestamp ASC LIMIT ?, ?', 'iii', $getId, $premierSujetAafficher, $nombreDeSujetsParPage);
	}
	// Si le joueur n'est pas modérateur, il n'a pas accès au messages masqués
	else {
		$reponseRows = dbFetchAll($base, 'SELECT * FROM reponses WHERE idsujet = ? AND visibilite=1 ORDER BY timestamp ASC LIMIT ?, ?', 'iii', $getId, $premierSujetAafficher, $nombreDeSujetsParPage);
	}
	//

	$sujet = dbFetchOne($base, 'SELECT * FROM sujets WHERE id = ?', 'i', $getId);
	if (!$sujet) {
		header('Location: forum.php');
		exit();
	}

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
		$sanctionRow = dbFetchOne($base, 'SELECT * FROM sanctions WHERE joueur = ?', 's', $_SESSION['login']);
	}

	// Si il est banni (skip check if not logged in)
	// Clean up expired bans (same logic as forum.php)
	if (isset($sanctionRow) && $sanctionRow && strtotime($sanctionRow['dateFin']) < time()) {
		dbExecute($base, 'DELETE FROM sanctions WHERE joueur = ?', 's', $_SESSION['login']);
		$sanctionRow = null;
	}
	$isBanned = isset($sanctionRow) && $sanctionRow;
	if ($isBanned) {
		$sanction = $sanctionRow;
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
		echo '<a href="forum.php">Forum</a> > <a href="listesujets.php?id=' . (int)$sujet['idforum'] . '">' . htmlspecialchars($forum['titre'], ENT_QUOTES, 'UTF-8') . '</a> > ' . htmlspecialchars($sujet['titre'], ENT_QUOTES, 'UTF-8');
		finContent();
		finCarte();

		$editer = "";
		if (isset($_SESSION['login']) and $_SESSION['login'] == $sujet['auteur']) {
			$editer = '<a href="editer.php?id=' . $sujet['id'] . '&type=1">Editer</a>';
		}
		carteForum('<img alt="profil" src="images/profil/' . htmlspecialchars($image['image'], ENT_QUOTES, 'UTF-8') . '" style="max-width:70px;max-height:70px;border-radius:10px;"/>', '<a href="joueur.php?id=' . htmlspecialchars($sujet['auteur'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($sujet['auteur'], ENT_QUOTES, 'UTF-8') . '</a>', date('d/m/Y à H\hi', $sujet['timestamp']), htmlspecialchars($sujet['titre'], ENT_QUOTES, 'UTF-8'), BBcode($sujet['contenu']), $couleur, 'Page : ' . $pages . $editer);


		if ($nb_resultats > 0) {
			foreach ($reponseRows as $reponse) {

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
					$editer = '<a href="editer.php?id=' . $reponse['id'] . '&type=2">Editer</a> '
						. '<form method="post" action="editer.php?id=' . $reponse['id'] . '&type=3" style="display:inline">' . csrfField() . '<button type="submit" style="background:none;border:none;cursor:pointer;text-decoration:underline;color:inherit;padding:0;" onclick="return confirm(\'Supprimer cette réponse ?\')">Supprimer</button></form>';
				}
				// Si l'utilisateur est un modérateur
				elseif (isset($_SESSION['login']) and $donnees4['moderateur'] == 1) {
					$editLink = '<a href="editer.php?id=' . $reponse['id'] . '&type=2">Editer</a> ';
					$deleteForm = '<form method="post" action="editer.php?id=' . $reponse['id'] . '&type=3" style="display:inline">' . csrfField() . '<button type="submit" style="background:none;border:none;cursor:pointer;text-decoration:underline;color:inherit;padding:0;" onclick="return confirm(\'Supprimer cette réponse ?\')">Supprimer</button></form> ';
					// Si le message est masqué, on propose de l'afficher
					if (!$reponse['visibilite']) {
						$editer = $editLink . $deleteForm
							. '<form method="post" action="editer.php?id=' . $reponse['id'] . '&type=4" style="display:inline">' . csrfField() . '<button type="submit" style="background:none;border:none;cursor:pointer;text-decoration:underline;color:inherit;padding:0;">Afficher</button></form>';
					}
					// Sinon, on propose de le masquer
					else {
						$editer = $editLink . $deleteForm
							. '<form method="post" action="editer.php?id=' . $reponse['id'] . '&type=5" style="display:inline">' . csrfField() . '<button type="submit" style="background:none;border:none;cursor:pointer;text-decoration:underline;color:inherit;padding:0;">Masquer</button></form>';
					}
				}

				carteForum('<img alt="profil" src="images/profil/' . htmlspecialchars($image['image'], ENT_QUOTES, 'UTF-8') . '" style="max-width:70px;max-height:70px;border-radius:10px;"/>', '<a href="joueur.php?id=' . htmlspecialchars($reponse['auteur'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($reponse['auteur'], ENT_QUOTES, 'UTF-8') . '</a>', date('d/m/Y à H\hi', $reponse['timestamp']), htmlspecialchars($sujet['titre'], ENT_QUOTES, 'UTF-8'), BBcode($reponse['contenu'], $javascript), $couleur, $editer);
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
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/mathjax/2.7.9/MathJax.js?config=TeX-AMS-MML_HTMLorMML"></script>
<?php include("includes/copyright.php"); ?>
