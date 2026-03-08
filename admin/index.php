<?php
include("../includes/connexion.php");

session_name('TVLW_ADMIN');
require_once(__DIR__ . '/../includes/session_init.php');
include("../includes/constantesBase.php");
include("../includes/fonctions.php");
require_once(__DIR__ . '/../includes/logger.php');
require_once(__DIR__ . '/../includes/csrf.php');
require_once(__DIR__ . '/../includes/rate_limiter.php');
require_once(__DIR__ . '/../includes/csp.php');
$nonce = cspNonce();
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self';");

$rateLimitError = '';
if (isset($_POST['motdepasseadmin'])) {
	csrfCheck();
	if (!rateLimitCheck($_SERVER['REMOTE_ADDR'], 'admin_login', RATE_LIMIT_ADMIN_MAX, RATE_LIMIT_ADMIN_WINDOW)) {
		logWarn('ADMIN', 'Admin login rate limited', ['ip_hash' => substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . (defined('SECRET_SALT') ? SECRET_SALT : 'tvlw')), 0, 12)]);
		$rateLimitError = 'Trop de tentatives de connexion. Réessayez dans quelques minutes.';
	} elseif (password_verify($_POST['motdepasseadmin'], ADMIN_PASSWORD_HASH)) {
		session_regenerate_id(true);
		$_SESSION['motdepasseadmin'] = true;
		$_SESSION['admin_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
		logInfo('ADMIN', 'Admin login successful');
	} else {
		logWarn('ADMIN', 'Admin login failed');
	}
}
// Admin idle timeout — expire session after inactivity
if (isset($_SESSION['motdepasseadmin']) && isset($_SESSION['admin_last_activity'])
    && (time() - $_SESSION['admin_last_activity']) > SESSION_IDLE_TIMEOUT) {
	unset($_SESSION['motdepasseadmin']);
	unset($_SESSION['admin_last_activity']);
}
if (isset($_SESSION['motdepasseadmin']) and $_SESSION['motdepasseadmin'] === true) {
	$_SESSION['admin_last_activity'] = time();
	// CSRF check for admin actions (not the login form itself)
	if (isset($_POST['supprimercompte']) || isset($_POST['maintenance']) || isset($_POST['plusmaintenance']) || isset($_POST['miseazero'])) {
		csrfCheck();
	}

	if (isset($_POST['supprimercompte'])) {
		$ip = $_POST['supprimercompte'] ?? '';
		if (!filter_var($ip, FILTER_VALIDATE_IP)) {
			$erreur = "Format d'IP invalide.";
		} else {
			$rows = dbFetchAll($base, 'SELECT login FROM membre WHERE ip = ?', 's', $ip);
			if (count($rows) > 5) {
				$erreur = "Trop de comptes correspondants (" . count($rows) . "). Opération refusée.";
			} else {
				foreach ($rows as $loginRow) {
					supprimerJoueur($loginRow['login']);
				}
				$succes = count($rows) . " compte(s) supprimé(s) pour IP " . htmlspecialchars($ip);
			}
		}
	}

	if (isset($_POST['maintenance'])) {
		$now = time();
		// MED-015: Also update debut timestamp so the 24h maintenance window starts now.
		// Without this, the automatic reset check compares against stale debut and may
		// immediately skip to Phase 2 (season reset) or never trigger the countdown.
		dbExecute($base, 'UPDATE statistiques SET maintenance = ?, debut = ?', 'ii', 1, $now);
	}

	if (isset($_POST['plusmaintenance'])) {
		dbExecute($base, 'UPDATE statistiques SET maintenance = ?', 'i', 0);
	}

	if (isset($_POST['miseazero'])) {
		// AUTH-C-001: performSeasonEnd() manages its own advisory lock internally.
		// Wrapping it in a second GET_LOCK on the same connection would be re-entrant
		// (MariaDB advisory locks are per-connection, not nestable) and would cause
		// the inner RELEASE_LOCK to release the lock prematurely.
		try {
			performSeasonEnd();
		} catch (\Exception $e) {
			logError('ADMIN', 'Manual season reset failed: ' . $e->getMessage());
			echo '<p style="color:red">Erreur lors de la remise à zéro : ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
		}
	}
?>

	<!DOCTYPE html>
	<html lang="fr">

	<head>
		<meta http-equiv="content-type" content="text/html; charset=utf-8" />
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title>The Very Little War - Menu d'administration</title>
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
			<li><a href="multiaccount.php">Anti multi-comptes</a></li>
		</ul>
		</p>

		<h4>Liste des multi-comptes</h4>
		<p>
		<table>
			<tr>
				<th>Ip multiple</th>
				<th>Supprimer les comptes</th>
			</tr>
			<?php $ipRows = dbFetchAll($base, 'SELECT ip FROM membre GROUP BY ip HAVING (count(*)>1)');
			foreach ($ipRows as $donnees) {
				$loginRows = dbFetchAll($base, 'SELECT login FROM membre WHERE ip = ?', 's', $donnees['ip']);
				$a = 0;
				foreach ($loginRows as $d1) {
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
			<?php $moderationRows = dbFetchAll($base, 'SELECT * FROM moderation');
			foreach ($moderationRows as $donnees) {
			?>
				<tr>
					<td><?php echo (int)$donnees['energie']; ?></td>
					<?php foreach ($nomsRes as $num => $ressource) {
						echo '<td>' . (int)$donnees[$ressource] . '</td>';
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
	<?php if (!empty($rateLimitError)): ?>
		<p style="color:red"><?php echo htmlspecialchars($rateLimitError, ENT_QUOTES, 'UTF-8'); ?></p>
	<?php endif; ?>
	<form action="index.php" method="post">
		<?php echo csrfField(); ?>
		<label for="motdepasseadmin">Mot de passe : </label>
		<input type="password" name="motdepasseadmin" id="motdepasseadmin" />
		<input type="submit" name="valider" value="Valider" />
	</form>
<?php
}
?>
