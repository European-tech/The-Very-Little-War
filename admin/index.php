<?php
include("../includes/connexion.php");

session_start();
include("../includes/constantesBase.php");
include("../includes/fonctions.php");
require_once(__DIR__ . '/../includes/logger.php');
require_once(__DIR__ . '/../includes/csrf.php');

if (isset($_POST['motdepasseadmin'])) {
	if (password_verify($_POST['motdepasseadmin'], ADMIN_PASSWORD_HASH)) {
		$_SESSION['motdepasseadmin'] = true;
		logInfo('ADMIN', 'Admin login successful');
	} else {
		logWarn('ADMIN', 'Admin login failed');
	}
}
if (isset($_SESSION['motdepasseadmin']) and $_SESSION['motdepasseadmin'] === true) {
	// CSRF check for all POST actions
	csrfCheck();

	if (isset($_POST['supprimercompte'])) {
		$ip = $_POST['supprimercompte'];
		$rows = dbFetchAll($base, 'SELECT login FROM membre WHERE ip = ?', 's', $ip);
		foreach ($rows as $login) {
			supprimerJoueur($login['login']);
		}
	}

	if (isset($_POST['maintenance'])) {
		dbExecute($base, 'UPDATE statistiques SET maintenance = ?', 'i', 1);
	}

	if (isset($_POST['plusmaintenance'])) {
		dbExecute($base, 'UPDATE statistiques SET maintenance = ?', 'i', 0);
	}

	if (isset($_POST['miseazero'])) {
		//Virage des joueurs inactifs
		remiseAZero();
	}
?>

	<!DOCTYPE html>
	<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="fr">

	<head>
		<meta http-equiv="content-type" content="text/html; charset=utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title>The Very Little War - Menu d'administration</title>
		<!--[if IE]><script src="http://html5shiv.googlecode.com/svn/trunk/html5.js"></script><![endif]-->
		<style type="text/css">
			h3,
			th,
			td {
				text-align: center;
			}

			table {
				border-collapse: collapse;
				border: 2px solid black;
				margin: auto;
			}

			th,
			td {
				border: 1px solid black;
			}
		</style>
	</head>

	<body>
		<h4>Menu d'aministration</h4>
		<p>
		<ul>
			<li><a href="listenews.php">Liste des news</a></li>
			<li><a href="supprimercompte.php">Supprimer un compte</a></li>
			<li><a href="listesujets.php">Verouiller ou supprimer un sujet</a></li>
			<li><a href="supprimerreponse.php">Supprimer une reponse</a></li>
		</ul>
		</p>

		<h4>Liste des multi-comptes</h4>
		<p>
		<table>
			<tr>
				<th>Ip multiple</th>
				<th>Supprimer les comptes</th>
			</tr>
			<?php $retour = dbQuery($base, 'SELECT ip FROM membre GROUP BY ip HAVING (count(*)>1)');
			while ($donnees = mysqli_fetch_array($retour)) {
				$ex1 = dbQuery($base, 'SELECT login FROM membre WHERE ip = ?', 's', $donnees['ip']);
				$a = 0;
				while ($d1 = mysqli_fetch_array($ex1)) {
					if (statut($d1['login'])) {
						$a = 1;
					}
				}
				if ($a) {
			?>
					<tr>
						<td><?php echo '<a href="ip.php?ip=' . htmlspecialchars($donnees['ip'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($donnees['ip'], ENT_QUOTES, 'UTF-8') . '</a>'; ?></td>
						<td>
							<form action="index.php" method="post" style="display:inline">
								<?php echo csrfField(); ?>
								<input type="hidden" name="supprimercompte" value="<?php echo htmlspecialchars($donnees['ip'], ENT_QUOTES, 'UTF-8'); ?>" />
								<input type="submit" value="Supprimer" />
							</form>
						</td>
					</tr>
			<?php
				}
			}
			?>
		</table>
		</p>
		<h4>Mise en maintenance (plus personne ne pourra aller sur le site sauf ici)</h4>
		<p>
		<form action="index.php" method="post">
			<?php echo csrfField(); ?>
			<?php
			$maintenance = dbFetchOne($base, 'SELECT maintenance FROM statistiques');
			if ($maintenance['maintenance'] != 0) {
			?>
				<input type="submit" name="plusmaintenance" value="Enlever la mise en maintenance" />
			<?php
			} else {
			?>
				<input type="submit" name="maintenance" value="Mise en maintenance" />
			<?php
			}
			?>
		</form>
		</p>
		<h4>Ressources donnees</h4>
		<p>
		<table>
			<tr>
				<th>Energie</th>
				<?php foreach ($nomsRes as $num => $ressource) {
					echo '<th>' . ucfirst($nomsAccents[$num]) . '</th>';
				} ?>
				<th>Justification</th>
				<th>Destinataire</th>
			</tr>
			<?php $retour = dbQuery($base, 'SELECT * FROM moderation');
			while ($donnees = mysqli_fetch_array($retour)) {
			?>
				<tr>
					<td><?php echo $donnees['energie']; ?></td>
					<?php foreach ($nomsRes as $num => $ressource) {
						echo '<td>' . $donnees[$ressource] . '</td>';
					} ?>
					<td><?php echo htmlspecialchars($donnees['justification'], ENT_QUOTES, 'UTF-8'); ?></td>
					<td><?php echo htmlspecialchars($donnees['destinataire'], ENT_QUOTES, 'UTF-8'); ?></td>
				</tr>
			<?php
			}
			?>
		</table>
		</p>
		<h4>Remise a zero et virage des joueurs inactifs</h4>
		<p>
		<form action="index.php" method="post">
			<?php echo csrfField(); ?>
			<input type="submit" name="miseazero" value="Remise à zero" />
		</form>
		</p>
	</body>

	</html>
<?php
} else { ?>
	<form action="index.php" method="post">
		<label for="motdepasseadmin">Mot de passe : </label>
		<input type="password" name="motdepasseadmin" id="motdepasseadmin" />
		<input type="submit" name="valider" value="Valider" />
	</form>
<?php
}
?>
