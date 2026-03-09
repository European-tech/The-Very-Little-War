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
require_once(__DIR__ . '/../includes/multiaccount.php');
$nonce = cspNonce();
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self';");

$rateLimitError = '';
if (isset($_POST['motdepasseadmin'])) {
	csrfCheck();
	if (!rateLimitCheck($_SERVER['REMOTE_ADDR'] ?? '', 'admin_login', RATE_LIMIT_ADMIN_MAX, RATE_LIMIT_ADMIN_WINDOW)) {
		logWarn('ADMIN', 'Admin login rate limited', ['ip_hash' => substr(hash_hmac('sha256', ($_SERVER['REMOTE_ADDR'] ?? ''), (defined('SECRET_SALT') ? SECRET_SALT : 'tvlw_salt')), 0, 12)]);
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
// ADMIN14-008: Admin sessions also enforce absolute lifetime (same as player sessions).
// Without this, an admin keeping the session alive with periodic activity has an
// indefinitely-lived session, increasing hijacking risk on shared machines.
if (isset($_SESSION['motdepasseadmin']) && isset($_SESSION['session_created'])
    && (time() - $_SESSION['session_created']) > SESSION_ABSOLUTE_TIMEOUT) {
	unset($_SESSION['motdepasseadmin']);
	unset($_SESSION['admin_last_activity']);
}
if (isset($_SESSION['motdepasseadmin']) and $_SESSION['motdepasseadmin'] === true) {
	$_SESSION['admin_last_activity'] = time();
	// Stamp session_created on first admin login so absolute timeout can be enforced
	if (!isset($_SESSION['session_created'])) {
	    $_SESSION['session_created'] = time();
	}
	// IP binding: reject if session IP doesn't match current IP (SESS-P7-003)
	if (isset($_SESSION['admin_ip']) && !hash_equals((string)$_SESSION['admin_ip'], (string)($_SERVER['REMOTE_ADDR'] ?? ''))) {
		session_unset();
		session_destroy();
		header('Location: index.php');
		exit();
	}
	// CSRF check for admin actions (not the login form itself)
	if (isset($_POST['supprimercompte']) || isset($_POST['maintenance']) || isset($_POST['plusmaintenance']) || isset($_POST['miseazero'])) {
		csrfCheck();
	}

	if (isset($_POST['supprimercompte'])) {
		$ip = $_POST['supprimercompte'] ?? '';
		// ADMIN16-001: The dashboard form sends the already-hashed IP (HMAC-SHA256, 64 hex chars).
		// FILTER_VALIDATE_IP always fails on a hex hash, breaking batch deletion entirely.
		// Accept the hash directly; validate it is a 64-char lowercase hex string.
		$isValidHash = (bool) preg_match('/^[0-9a-f]{64}$/', $ip);
		if (!$isValidHash) {
			$erreur = "Valeur d'IP invalide (hash attendu).";
		} else {
			// Hash is already the correct format — query directly (no second hashIpAddress() call).
			$hashedIp = $ip;
			$rows = dbFetchAll($base, 'SELECT login FROM membre WHERE ip = ?', 's', $hashedIp);
			if (count($rows) > 5) {
				$erreur = "Trop de comptes correspondants (" . count($rows) . "). Opération refusée.";
			} else {
				$logins_deleted = array_column($rows, 'login');
				// ADMIN15-002: Wrap batch deletion in try-catch for actionable admin feedback on failure.
				// withTransaction() rolls back correctly on exception, but without a catch the admin
				// sees a crash page with no information about what failed.
				try {
					withTransaction($base, function() use ($base, $rows) {
						foreach ($rows as $loginRow) {
							supprimerJoueur($loginRow['login']);
						}
					});
					// MEDIUM-040: Consolidated audit log entry for batch IP deletion.
					logInfo('ADMIN_BATCH_DELETE', 'Batch IP deletion completed', ['ip' => $ip, 'count' => count($logins_deleted)]);
					$succes = count($rows) . " compte(s) supprimé(s) pour IP " . htmlspecialchars($ip);
				} catch (\Exception $e) {
					logError('ADMIN_BATCH_DELETE', 'Batch IP deletion failed', ['ip' => $ip, 'error' => $e->getMessage()]);
					$erreur = "Erreur lors de la suppression des comptes. Consultez les logs.";
				}
			}
		}
	}

	if (isset($_POST['maintenance'])) {
		// FIX2-ADMIN: Set maintenance=1 and record the trigger time in maintenance_started_at.
		// Do NOT update debut — debut is the real season start and must remain unchanged so that
		// MIN_SEASON_DAYS guards and season-duration calculations stay accurate.
		dbExecute($base, 'UPDATE statistiques SET maintenance = 1, maintenance_started_at = UNIX_TIMESTAMP()');
	}

	if (isset($_POST['plusmaintenance'])) {
		dbExecute($base, 'UPDATE statistiques SET maintenance = ?', 'i', 0);
	}

	if (isset($_POST['miseazero'])) {
		// AUTH-C-001: performSeasonEnd() manages its own advisory lock internally.
		// Wrapping it in a second GET_LOCK on the same connection would be re-entrant
		// (MariaDB advisory locks are per-connection, not nestable) and would cause
		// the inner RELEASE_LOCK to release the lock prematurely.
		// P27-026: Guard admin manual reset with MIN_SEASON_DAYS (bypass requires explicit confirmation)
		$debutRow = dbFetchOne($base, 'SELECT debut FROM statistiques', '', '');
		$seasonAge = $debutRow ? (time() - (int)$debutRow['debut']) : PHP_INT_MAX;
		$forcereset = isset($_POST['forcereset']) && $_POST['forcereset'] === '1';
		if ($seasonAge < MIN_SEASON_DAYS * SECONDS_PER_DAY && !$forcereset) {
			$erreur = "La saison a commencé il y a moins de " . MIN_SEASON_DAYS . " jours. Cochez la case de confirmation pour forcer la remise à zéro.";
		} else {
			try {
				performSeasonEnd();
			} catch (\Exception $e) {
				logError('ADMIN', 'Manual season reset failed: ' . $e->getMessage());
				echo '<p style="color:red">Erreur lors de la remise à zéro : ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
			}
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
		<?php // P27-018: Display batch deletion feedback to admin
		if (isset($erreur)) echo '<p style="color:red">' . htmlspecialchars($erreur, ENT_QUOTES, 'UTF-8') . '</p>';
		if (isset($succes)) echo '<p style="color:green">' . htmlspecialchars($succes, ENT_QUOTES, 'UTF-8') . '</p>';
		?>
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
						<td>
						<form method="post" action="ip.php" style="display:inline">
							<?php echo csrfField(); ?>
							<input type="hidden" name="ip" value="<?php echo htmlspecialchars($donnees['ip'], ENT_QUOTES, 'UTF-8'); ?>" />
							<button type="submit"><?php echo htmlspecialchars($donnees['ip'], ENT_QUOTES, 'UTF-8'); ?></button>
						</form>
					</td>
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
			<label><input type="checkbox" name="forcereset" value="1"> Forcer (ignorer la garde MIN_SEASON_DAYS)</label><br>
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
