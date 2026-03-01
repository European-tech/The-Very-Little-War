<?php
session_start();
include("../includes/connexion.php");
include("../includes/constantesBase.php");
if (isset($_POST['motdepasseadmin'])) {
	if (password_verify($_POST['motdepasseadmin'], ADMIN_PASSWORD_HASH)) {
		$_SESSION['motdepasseadmin'] = true;
	}
}
if (!isset($_SESSION['motdepasseadmin']) or $_SESSION['motdepasseadmin'] !== true) {
?>
	<form action="index.php" method="post">
		<label for="motdepasseadmin">Mot de passe : </label>
		<input type="password" name="motdepasseadmin" id="motdepasseadmin" />
		<input type="submit" name="valider" value="Valider" />
	</form> <?php
		} else {

			if (isset($_POST['deplacer']) and isset($_POST['deplacerSubmit']) and isset($_POST['idSujet'])) {
				$deplacer = (int)$_POST['deplacer'];
				$idSujet = (int)$_POST['idSujet'];
				dbExecute($base, 'UPDATE sujets SET idforum = ? WHERE id = ?', 'ii', $deplacer, $idSujet);
				$erreur = "Le sujet a été déplacé.";
			}
			if (isset($_POST['joueurBombe'])) {
				$nb = dbCount($base, 'SELECT count(login) AS nb FROM membre WHERE login = ?', 's', $_POST['joueurBombe']);

				if ($nb > 0) {
					$joueur = dbFetchOne($base, 'SELECT bombe FROM autre WHERE login = ?', 's', $_POST['joueurBombe']);
					dbExecute($base, 'UPDATE autre SET bombe = ? WHERE login = ?', 'is', ($joueur['bombe'] + 1), $_POST['joueurBombe']);
					$erreur = "Vous avez rajouté un point de bombe à " . htmlspecialchars($_POST['joueurBombe'], ENT_QUOTES, 'UTF-8') . ".";
				} else {
					$erreur = "Ce joueur n'existe pas.";
				}
			}

			if (isset($_GET['supprimersujet'])) {
				$supprimersujet = (int)$_GET['supprimersujet'];
				dbExecute($base, 'DELETE FROM sujets WHERE id = ?', 'i', $supprimersujet);
				dbExecute($base, 'DELETE FROM statutforum WHERE idsujet = ?', 'i', $supprimersujet);
			}
			if (isset($_GET['verouillersujet'])) {
				$verouillersujet = (int)$_GET['verouillersujet'];
				dbExecute($base, 'UPDATE sujets SET statut = 1 WHERE id = ?', 'i', $verouillersujet);
				dbExecute($base, 'DELETE FROM statutforum WHERE idsujet = ?', 'i', $verouillersujet);
			}
			if (isset($_GET['deverouillersujet'])) {
				$deverouillersujet = (int)$_GET['deverouillersujet'];
				dbExecute($base, 'UPDATE sujets SET statut = 0 WHERE id = ?', 'i', $deverouillersujet);
			}

			$bool = 1;
			foreach ($nomsRes as $num => $ressource) {
				if (!(isset($_POST[$ressource . 'Envoyee']))) {
					$bool = 0;
				}
			}
			if (isset($_POST['energieEnvoyee']) and $bool == 1 and isset($_POST['destinataire'])) {
				if (!empty($_POST['destinataire'])) {
					if (empty($_POST['energieEnvoyee'])) {
						$_POST['energieEnvoyee'] = 0;
					}
					foreach ($nomsRes as $num => $ressource) {
						if (empty($_POST[$ressource . 'Envoyee'])) {
							$_POST[$ressource . 'Envoyee'] = 0;
						}
					}

					$bool = 1;
					foreach ($nomsRes as $num => $ressource) {
						if (!(preg_match("#^[0-9]*$#", $_POST[$ressource . 'Envoyee']))) {
							$bool = 0;
						}
					}
					if (preg_match("#^[0-9]*$#", $_POST['energieEnvoyee']) and $bool == 1) {

						$verification = dbFetchOne($base, 'SELECT count(*) AS joueurOuPas FROM membre WHERE login = ?', 's', $_POST['destinataire']);
						if ($verification['joueurOuPas'] == 1) {
							$ressourcesDestinataire = dbFetchOne($base, 'SELECT * FROM ressources WHERE login = ?', 's', $_POST['destinataire']);

							$chaine = "";
							foreach ($nomsRes as $num => $ressource) {
								$plus = "";
								if ($num < $nbRes) {
									$plus = ",";
								}
								$chaine = $chaine . '' . $ressource . '=' . round($ressourcesDestinataire[$ressource] + $_POST[$ressource . 'Envoyee']) . '' . $plus;
							}
							mysqli_query($base, 'UPDATE ressources SET energie=\'' . round($ressourcesDestinataire['energie'] + $_POST['energieEnvoyee']) . '\', ' . $chaine . ' WHERE login=\'' . mysqli_real_escape_string($base, $_POST['destinataire']) . '\'');

							$chaine = "";
							foreach ($nomsRes as $num => $ressource) {
								$plus = "";
								if ($num < $nbRes) {
									$plus = ",";
								}
								$chaine = $chaine . '"' . (int)$_POST[$ressource . 'Envoyee'] . '"' . $plus;
							}
							$justification = mysqli_real_escape_string($base, stripslashes(htmlentities(trim($_POST['justification']))));
							mysqli_query($base, 'INSERT INTO moderation VALUES(default,"' . mysqli_real_escape_string($base, $_POST['destinataire']) . '", "' . (int)$_POST['energieEnvoyee'] . '", ' . $chaine . ', "' . $justification . '")');

							$chaine = "";
							foreach ($nomsRes as $num => $ressource) {
								$plus = "";
								if ($num < $nbRes) {
									$plus = ",";
								}
								$chaine = $chaine . '' . number_format($_POST[$ressource . 'Envoyee'], 0, ' ', ' ') . '<img src="../images/' . $ressource . '.png" alt="' . $ressource . '"/>' . $plus;
							}
							$erreur = "Vous avez donné " . number_format($_POST['energieEnvoyee'], 0, ' ', ' ') . "<img src=\"../images/energie.png\" alt=\"energie\"/>, " . $chaine . " à " . htmlspecialchars($_POST['destinataire'], ENT_QUOTES, 'UTF-8') . ".";
						} else {
							$erreur = "Le destinataire n'existe pas.";
						}
					} else {
						$erreur = "Seul des nombres entiers et positifs doivent être entrés.";
					}
				} else {
					$erreur = "Vous n'avez pas entré de destinataire.";
				}
			}
			?>
	<!DOCTYPE html>
	<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="fr">

	<head>
		<title>The Very Little War - Modération</title>
		<!--[if IE]><script src="http://html5shiv.googlecode.com/svn/trunk/html5.js"></script><![endif]-->
		<style type="text/css">
			label {
				display: block;
				width: 270px;
				float: left;
			}
		</style>
		<link rel="stylesheet" type="text/css" href="../style/css/templatemo-style.css">
	</head>

	<body>
		<div class="panel panel-default margin-10 text-center pattern-bg">
			<div class="panel-heading">
				<h4>Modération</h4>
			</div>
			<div class="panel-body">
				<p>
					<!-- <a href="index.php?sub=1">Donner des ressources</a><br /> -->
					<a href="index.php?sub=2">Multicompte</a><br />
					<a href="index.php">Accueil modération (bombe + sujets)</a><br />
				</p>
				<?php
				if (isset($erreur)) {
					echo $erreur;
				}
				if (isset($_GET['sub'])) {
					//if ($_GET['sub'] == 1) {
				?>
					<!--<p>
							<form action="index.php?sub=1" method="post">
								<label for="energieEnvoyee">Energie : </label><input type="number" name="energieEnvoyee" id="energieEnvoyee" /><br />
								<?php
								/*foreach ($nomsRes as $num => $ressource) {
									echo '<label for="' . $ressource . 'Envoyee">' . ucfirst($nomsAccents[$num]) . ' : </label><input type="number" name="' . $ressource . 'Envoyee" id="' . $ressource . 'Envoyee"/><br/>';
								}*/
								?>
								<br />
								<label for="destinataire">Destinataire : </label><input type="text" name="destinataire" id="destinataire" /><br /><br />
								<label for="justification">Justification : </label><textarea name="justification" id="justification" /></textarea><br /><br />
								<input type="submit" name="envoyer" value="Envoyer" />
							</form>
						</p>-->
					<?php
					//}
					if ($_GET['sub'] == 2) { ?>
						<h4>Liste des multi-comptes</h4>
						<p>Veuillez donner un avertissement aux joueurs concernés avant de supprimer les comptes.<br /><br />
						<table>
							<tr>
								<th>Ip multiple</th>
							</tr>
							<?php $retour = dbQuery($base, 'SELECT ip FROM membre GROUP BY ip HAVING (count(*)>1)');
							while ($donnees = mysqli_fetch_array($retour)) {
							?>
								<tr>
									<td><?php echo '<a href="ip.php?ip=' . htmlspecialchars($donnees['ip'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($donnees['ip'], ENT_QUOTES, 'UTF-8') . '</a>'; ?></td>
								</tr>
							<?php
							}
							?>
						</table>
						</p>
					<?php
					}
				} else {
					?>
					<p>

						<span class="important">Ajouter +1 à la bombe</span> :
					<form action="index.php" method="post"><input type="text" name="joueurBombe" /><input type="submit" name="bombe" value="ajouter" /></form>
					</p>
					<p>
					<table>
						<tr>
							<th>Vérouiller</th>
							<th>Dévérouiller</th>
							<th>Supprimer</th>
							<th>Titre</th>
							<th>Auteur</th>
							<th>Statut</th>
							<th>Déplacer</th>
							<th>Date</th>
						</tr>
						<?php
						$retour = dbQuery($base, 'SELECT * FROM sujets ORDER BY auteur DESC');
						while ($donnees = mysqli_fetch_array($retour)) {
						?>
							<tr>
								<td><?php echo '<a href="index.php?verouillersujet=' . (int)$donnees['id'] . '">'; ?>Vérouiller</a></td>
								<td><?php echo '<a href="index.php?deverouillersujet=' . (int)$donnees['id'] . '">'; ?>Dévérouiller</a></td>
								<td><?php echo '<a href="index.php?supprimersujet=' . (int)$donnees['id'] . '">'; ?>Supprimer</a></td>
								<td><?php echo htmlspecialchars(stripslashes($donnees['titre']), ENT_QUOTES, 'UTF-8'); ?></td>
								<td><?php echo htmlspecialchars(stripslashes($donnees['auteur']), ENT_QUOTES, 'UTF-8'); ?></td>
								<td><?php if ($donnees['statut'] == 0) {
										echo "Ouvert";
									} else {
										echo "Vérouillé";
									} ?></td>
								<td>
									<form action="index.php" method="post">
										<select name="deplacer">
											<?php
											$ex = dbQuery($base, 'SELECT id,titre FROM forums');
											while ($forum = mysqli_fetch_array($ex)) {
												$selected = "";
												if ($forum['id'] == $donnees['idforum']) {
													$selected = "selected";
												}
												echo '<option value="' . (int)$forum['id'] . '" ' . $selected . '>' . htmlspecialchars($forum['titre'], ENT_QUOTES, 'UTF-8') . '</option>';
											}
											?>
										</select>
										<input type="hidden" name="idSujet" value="<?php echo (int)$donnees['id']; ?>" />
										<input type="submit" value="Déplacer" name="deplacerSubmit" />
									</form>
								</td>
								<td><?php echo date('d/m/Y', $donnees['timestamp']); ?></td>
							</tr>
						<?php
						}
						?>
					</table>
					</p>
			<?php
				}
			}
			?>
			</div>
		</div>
	</body>

	</html>
