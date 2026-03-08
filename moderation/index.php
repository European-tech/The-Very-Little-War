<?php
session_name('TVLW_MOD');
require_once(__DIR__ . '/../includes/session_init.php');
include("../includes/connexion.php");
include("../includes/constantesBase.php");
require_once("../includes/database.php");
require_once("../includes/csrf.php");
require_once(__DIR__ . '/../includes/rate_limiter.php');
require_once(__DIR__ . '/../includes/logger.php');
require_once(__DIR__ . '/../includes/csp.php');
$nonce = cspNonce();
// ADMIN-HIGH-002: Complete CSP — removed data: from img-src, added frame-ancestors and form-action.
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline'; img-src 'self'; frame-ancestors 'none'; form-action 'self';");

$rateLimitError = '';
if (isset($_POST['motdepasseadmin'])) {
	csrfCheck();
	if (!rateLimitCheck($_SERVER['REMOTE_ADDR'], 'moderation_login', RATE_LIMIT_ADMIN_MAX, RATE_LIMIT_ADMIN_WINDOW)) {
		logWarn('MODERATION', 'Moderation login rate limited', ['ip_hash' => substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . (defined('SECRET_SALT') ? SECRET_SALT : 'tvlw_salt')), 0, 12)]);
		$rateLimitError = 'Trop de tentatives de connexion. Réessayez dans quelques minutes.';
	} elseif (password_verify($_POST['motdepasseadmin'], ADMIN_PASSWORD_HASH)) {
		session_regenerate_id(true);
		$_SESSION['motdepasseadmin'] = true;
		$_SESSION['mod_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
		logInfo('MODERATION', 'Moderation login successful');
	} else {
		logWarn('MODERATION', 'Moderation login failed');
	}
}
// Moderation idle timeout
if (isset($_SESSION['motdepasseadmin']) && isset($_SESSION['mod_last_activity'])
    && (time() - $_SESSION['mod_last_activity']) > SESSION_IDLE_TIMEOUT) {
	unset($_SESSION['motdepasseadmin']);
	unset($_SESSION['mod_last_activity']);
}
if (!isset($_SESSION['motdepasseadmin']) or $_SESSION['motdepasseadmin'] !== true) {
?>
	<?php if (!empty($rateLimitError)): ?>
		<p style="color:red"><?php echo htmlspecialchars($rateLimitError, ENT_QUOTES, 'UTF-8'); ?></p>
	<?php endif; ?>
	<form action="index.php" method="post">
		<?php echo csrfField(); ?>
		<label for="motdepasseadmin">Mot de passe : </label>
		<input type="password" name="motdepasseadmin" id="motdepasseadmin" />
		<input type="submit" name="valider" value="Valider" />
	</form> <?php
		} else {
			$_SESSION['mod_last_activity'] = time();
			// IP binding: reject if session IP doesn't match current IP (SEC-P6-001/SESS-P7-003)
			if (isset($_SESSION['mod_ip']) && !hash_equals((string)$_SESSION['mod_ip'], (string)($_SERVER['REMOTE_ADDR'] ?? ''))) {
				session_unset();
				session_destroy();
				header('Location: index.php');
				exit();
			}

			if ($_SERVER['REQUEST_METHOD'] === 'POST') {
				csrfCheck();
			}

			if (isset($_POST['deplacer']) and isset($_POST['deplacerSubmit']) and isset($_POST['idSujet'])) {
				$deplacer = (int)$_POST['deplacer'];
				$idSujet = (int)$_POST['idSujet'];
				dbExecute($base, 'UPDATE sujets SET idforum = ? WHERE id = ?', 'ii', $deplacer, $idSujet);
				$erreur = "Le sujet a été déplacé.";
			}
			if (isset($_POST['joueurBombe'])) {
				if (!preg_match('/^[a-zA-Z0-9_-]{1,20}$/', $_POST['joueurBombe'])) {
					$erreur = "Nom de joueur invalide.";
				} else {
				$nb = dbCount($base, 'SELECT count(login) AS nb FROM membre WHERE login = ?', 's', $_POST['joueurBombe']);

				if ($nb > 0) {
					$joueur = dbFetchOne($base, 'SELECT bombe FROM autre WHERE login = ?', 's', $_POST['joueurBombe']);
					dbExecute($base, 'UPDATE autre SET bombe = ? WHERE login = ?', 'is', ($joueur['bombe'] + 1), $_POST['joueurBombe']);
					$erreur = "Vous avez rajouté un point de bombe à " . htmlspecialchars($_POST['joueurBombe'], ENT_QUOTES, 'UTF-8') . ".";
				} else {
					$erreur = "Ce joueur n'existe pas.";
				}
				} // end else valid format
			}

			if (isset($_POST['supprimersujet'])) {
				$supprimersujet = (int)$_POST['supprimersujet'];
				withTransaction($base, function() use ($base, $supprimersujet) {
					dbExecute($base, 'DELETE FROM reponses WHERE idsujet = ?', 'i', $supprimersujet);
					dbExecute($base, 'DELETE FROM sujets WHERE id = ?', 'i', $supprimersujet);
					dbExecute($base, 'DELETE FROM statutforum WHERE idsujet = ?', 'i', $supprimersujet);
				});
			}
			if (isset($_POST['verouillersujet'])) {
				$verouillersujet = (int)$_POST['verouillersujet'];
				dbExecute($base, 'UPDATE sujets SET statut = 1 WHERE id = ?', 'i', $verouillersujet);
				dbExecute($base, 'DELETE FROM statutforum WHERE idsujet = ?', 'i', $verouillersujet);
			}
			if (isset($_POST['deverouillersujet'])) {
				$deverouillersujet = (int)$_POST['deverouillersujet'];
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
					// Cap resource grants to prevent abuse (P5-GAP-007)
					$maxGrant = 1000000;
					$maxAtomGrant = 50000; // per-action atom cap for moderators
					if ((int)$_POST['energieEnvoyee'] > $maxGrant) {
						$erreur = "Le montant d'énergie dépasse le maximum autorisé ($maxGrant).";
					} elseif (preg_match("#^[0-9]*$#", $_POST['energieEnvoyee']) and $bool == 1) {

						$verification = dbFetchOne($base, 'SELECT count(*) AS joueurOuPas FROM membre WHERE login = ?', 's', $_POST['destinataire']);
						if ($verification['joueurOuPas'] == 1) {
							// Wrap resource grant + audit log in transaction (P5-GAP-027)
							withTransaction($base, function() use ($base, $nomsRes, $maxAtomGrant) {
								$ressourcesDestinataire = dbFetchOne($base, 'SELECT * FROM ressources WHERE login = ? FOR UPDATE', 's', $_POST['destinataire']);

								// Build dynamic UPDATE for ressources using prepared statements
								$setParts = ['energie = ?'];
								$types = 'd';
								$params = [min($ressourcesDestinataire['energie'] + (int)$_POST['energieEnvoyee'], 100000)];

								foreach ($nomsRes as $num => $ressource) {
									$setParts[] = $ressource . ' = ?';
									$types .= 'd';
									$atomAmount = min((int)$_POST[$ressource . 'Envoyee'], $maxAtomGrant);
									$params[] = min($ressourcesDestinataire[$ressource] + $atomAmount, 100000);
								}

								$types .= 's';
								$params[] = $_POST['destinataire'];

								dbExecute($base, 'UPDATE ressources SET ' . implode(', ', $setParts) . ' WHERE login = ?', $types, ...$params);

								// Build INSERT for moderation log using prepared statements
								$insertCols = 'default, ?, ?';
								$insertTypes = 'si';
								$insertParams = [$_POST['destinataire'], (int)$_POST['energieEnvoyee']];

								foreach ($nomsRes as $num => $ressource) {
									$insertCols .= ', ?';
									$insertTypes .= 'i';
									$insertParams[] = (int)$_POST[$ressource . 'Envoyee'];
								}

								$insertCols .= ', ?';
								$insertTypes .= 's';
								$justification = htmlentities(trim($_POST['justification'] ?? ''), ENT_QUOTES, 'UTF-8');
								$insertParams[] = $justification;

								dbExecute($base, 'INSERT INTO moderation VALUES(' . $insertCols . ')', $insertTypes, ...$insertParams);
							}); // end withTransaction

							$chaine = "";
							foreach ($nomsRes as $num => $ressource) {
								$plus = "";
								if ($num < $nbRes) {
									$plus = ",";
								}
								$chaine = $chaine . '' . number_format((int)$_POST[$ressource . 'Envoyee'], 0, ' ', ' ') . '<img src="../images/' . htmlspecialchars($ressource, ENT_QUOTES, 'UTF-8') . '.png" alt="' . htmlspecialchars($ressource, ENT_QUOTES, 'UTF-8') . '"/>' . $plus;
							}
							$erreur = "Vous avez donné " . number_format((int)$_POST['energieEnvoyee'], 0, ' ', ' ') . "<img src=\"../images/energie.png\" alt=\"energie\"/>, " . $chaine . " à " . htmlspecialchars($_POST['destinataire'], ENT_QUOTES, 'UTF-8') . ".";
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
	<html lang="fr">

	<head>
		<meta charset="UTF-8">
		<title>The Very Little War - Modération</title>
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
				// LOW-017: $erreur is constructed only from hardcoded strings or values already escaped
				// via htmlspecialchars() (e.g. player names, resource amounts). Any HTML (img tags etc.)
				// is built from safe, server-controlled values. Raw echo is intentional here.
				if (isset($erreur)) {
					echo $erreur;
				}
				if (isset($_GET['sub'])) {
					if ($_GET['sub'] == 2) { ?>
						<h4>Liste des multi-comptes</h4>
						<p>Veuillez donner un avertissement aux joueurs concernés avant de supprimer les comptes.<br /><br />
						<table>
							<tr>
								<th>Ip multiple</th>
							</tr>
							<?php $ipRows = dbFetchAll($base, 'SELECT ip FROM membre GROUP BY ip HAVING (count(*)>1)');
							foreach ($ipRows as $donnees) {
							?>
								<tr>
									<td>
							<form method="post" action="ip.php" style="display:inline">
								<?php echo csrfField(); ?>
								<input type="hidden" name="ip" value="<?php echo htmlspecialchars($donnees['ip'], ENT_QUOTES, 'UTF-8'); ?>" />
								<button type="submit"><?php echo htmlspecialchars($donnees['ip'], ENT_QUOTES, 'UTF-8'); ?></button>
							</form>
						</td>
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
					<form action="index.php" method="post"><?php echo csrfField(); ?><input type="text" name="joueurBombe" /><input type="submit" name="bombe" value="ajouter" /></form>
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
						$sujetRows = dbFetchAll($base, 'SELECT * FROM sujets ORDER BY auteur DESC');
						foreach ($sujetRows as $donnees) {
						?>
							<tr>
								<td><form method="post" action="index.php" style="display:inline"><?php echo csrfField(); ?><input type="hidden" name="verouillersujet" value="<?php echo (int)$donnees['id']; ?>" /><button type="submit">Vérouiller</button></form></td>
								<td><form method="post" action="index.php" style="display:inline"><?php echo csrfField(); ?><input type="hidden" name="deverouillersujet" value="<?php echo (int)$donnees['id']; ?>" /><button type="submit">Dévérouiller</button></form></td>
								<td><form method="post" action="index.php" style="display:inline"><?php echo csrfField(); ?><input type="hidden" name="supprimersujet" value="<?php echo (int)$donnees['id']; ?>" /><button type="submit">Supprimer</button></form></td>
								<td><?php echo htmlspecialchars($donnees['titre'], ENT_QUOTES, 'UTF-8'); ?></td>
								<td><?php echo htmlspecialchars($donnees['auteur'], ENT_QUOTES, 'UTF-8'); ?></td>
								<td><?php if ($donnees['statut'] == 0) {
										echo "Ouvert";
									} else {
										echo "Vérouillé";
									} ?></td>
								<td>
									<form action="index.php" method="post">
										<?php echo csrfField(); ?>
										<select name="deplacer">
											<?php
											$forumRows = dbFetchAll($base, 'SELECT id,titre FROM forums');
											foreach ($forumRows as $forum) {
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
