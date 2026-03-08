<?php
include("includes/basicprivatephp.php");
include("includes/bbcode.php");
require_once("includes/csrf.php");
require_once("includes/rate_limiter.php");

// Check moderator status BEFORE processing any actions
$joueur = dbFetchOne($base, 'SELECT moderateur FROM membre WHERE login = ?', 's', $_SESSION['login']);
if (!$joueur['moderateur']) {
	include("includes/layout.php");
	debutCarte();
	debutContent();
	echo '<span class="important">Erreur : Accès interdit</span>
		Seul les modérateurs on accès à cette page.';
	finContent();
	finCarte();
	include("includes/copyright.php");
	exit();
}

// Supression de sanction (POST-based with CSRF)
if (isset($_POST['supprimer'])) {
	csrfCheck();
	$supprimerId = (int)$_POST['supprimer'];
	dbExecute($base, 'DELETE FROM sanctions WHERE idSanction = ?', 'i', $supprimerId);
}


// Rate limit: max 20 sanctions per hour per moderator to prevent abuse
if (isset($_POST['pseudo'], $_POST['dateFin'], $_POST['motif']) && !isset($_POST['supprimer'])) {
	rateLimitCheck($_SESSION['login'], 'sanction_create', 20, 3600);
}

if (isset($_POST['pseudo'], $_POST['dateFin'], $_POST['motif']) && !isset($_POST['supprimer'])) {
	csrfCheck();
	$_POST['motif'] = mb_substr(trim($_POST['motif']), 0, 2000);
	if (!empty($_POST['pseudo']) && !empty($_POST['dateFin']) && !empty($_POST['motif'])) {
		$nb = dbCount($base, 'SELECT count(*) FROM membre WHERE login = ?', 's', $_POST['pseudo']);
		// On vérifie que le joueur existe
		if ($nb > 0) {
			// Convertion de la date au format anglais
			$parts = explode('/', $_POST['dateFin']);
			if (count($parts) !== 3 || !checkdate((int)$parts[1], (int)$parts[0], (int)$parts[2])) {
				$erreur = "<strong>Erreur</strong> : Date invalide.";
			} else {
				list($jour, $mois, $annee) = $parts;
				$date = $annee . '-' . $mois . '-' . $jour;
				dbExecute($base, 'INSERT INTO sanctions VALUES (default, ?, CURRENT_DATE, ?, ?, ?)', 'ssss', $_POST['pseudo'], $date, $_POST['motif'], $_SESSION['login']);
			}
		} else {
			$erreur = "<strong>Erreur</strong> : Ce joueur n'existe pas.";
		}
	} else {
		$erreur = "<strong>Erreur</strong> :Tous les champs doivent être remplis.";
	}
}

include("includes/layout.php");

debutCarte("Modération du forum");

	echo important("Bannir un membre");
?>
	<form method="post" action="moderationForum.php" name="formModeration">
		<?php echo csrfField(); ?>
		<?php
		debutListe();
		item(['input' => '<input type="text" name="pseudo" id="pseudo" class="form-control"/>', 'floating' => true, 'titre' => 'Pseudo']);
		item(['floating' => false, 'titre' => 'Date de début', 'input' => '<input type="text" id="dateDebut" name="dateDebut" readonly class="form-control" value="' . date("d/m/Y  H:i:s") . '"/>', 'disabled' => true]);
		item(['floating' => false, 'titre' => 'Date de fin', 'input' => '<input type="text" placeholder="Sélectionnez" readonly id="calVacs"  name="dateFin">']);
		creerBBcode("motif");
		item(['floating' => false, 'titre' => "Motif", 'input' => '<textarea name="motif" id="motif" rows="10" cols="50"></textarea>']);
		item(['input' => submit(['form' => 'formModeration', 'titre' => 'Valider'])]);
		finListe();
		?>
	</form>
	<!-- Script JQuery pour la selection des dates -->
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.13.3/themes/smoothness/jquery-ui.min.css" integrity="sha512-EPEm2NPSRmFKzSAFm4xFSVpZMC3cKgBSxMxIfiUVGJGwSCuikYmGmFiuxmGxTQsLMOuQOBVEfCm8bnYJnQMnQ==" crossorigin="anonymous" referrerpolicy="no-referrer" />
	<script src="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.13.3/jquery-ui.min.js" integrity="sha512-Ww1y9OuQ2kehgVWSD/3nhgfrb424O3SgrGCDBOd1cMWcz1ZEAIjq7bZQTDsRP3VdJHTbJcNGFKYBKwF7OoWFbA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
	<script nonce="<?php echo htmlspecialchars(cspNonce(), ENT_QUOTES, 'UTF-8'); ?>">
		$(function() {
			// Construction et paramétrage du selecteur de date
			$("#calVacs").datepicker({
				minDate: 1,
				dayNamesMin: ["Di", "Lu", "Ma", "Me", "Je", "Ve", "Sa"],
				monthNames: ["Janvier", "Février", "Mars", "Avril", "Mai", "Juin", "Juillet", "Août", "Septembre", "Octobre", "Novembre", "Decembre"],
				hideIfNoPrevNext: true,
				constrainInput: true
			});
			$("#calVacs").datepicker("option", "dateFormat", "dd/mm/yy");
		});
	</script><br />
	<?php
	finCarte();
	debutCarte("Sanctions en cours");
	$sanctions = dbFetchAll($base, 'SELECT * FROM sanctions WHERE dateFin >= CURDATE() ORDER BY dateDebut DESC LIMIT 200');
	if (!count($sanctions)) {
		debutContent();
		echo "Aucune sanction en cours.";
		finContent();
	} else {

	?>
		<div class="table-responsive">
			<table class="table table-striped table-bordered">
				<thead>
					<tr>
						<th>Joueur</th>
						<th>Modérateur</th>
						<th>Date de début</th>
						<th>Date de fin</th>
						<th>Motif</th>
						<th>Annuler</th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ($sanctions as $sanction) {
						// Convertion des dates
						list($annee, $mois, $jour) = explode('-', $sanction['dateDebut']);
						$sanction['dateDebut'] = $jour . '/' . $mois . '/' . $annee;
						list($annee, $mois, $jour) = explode('-', $sanction['dateFin']);
						$sanction['dateFin'] = $jour . '/' . $mois . '/' . $annee;
						echo "
							<tr>
								<td>" . htmlspecialchars($sanction['joueur'], ENT_QUOTES, 'UTF-8') . "</td>
								<td>" . htmlspecialchars($sanction['moderateur'], ENT_QUOTES, 'UTF-8') . "</td>
								<td>" . htmlspecialchars($sanction['dateDebut'], ENT_QUOTES, 'UTF-8') . "</td>
								<td>" . htmlspecialchars($sanction['dateFin'], ENT_QUOTES, 'UTF-8') . "</td>
								<td>" . BBcode($sanction['motif']) . "</td>
								<td><form method=\"post\" action=\"moderationForum.php\" style=\"display:inline\">" . csrfField() . "<input type=\"hidden\" name=\"supprimer\" value=\"" . (int)$sanction['idSanction'] . "\"/><input type=\"image\" src=\"images/croix.png\" alt=\"supprimer\"></form></td>
							</tr>
							";
					}
					?>
				</tbody>
			</table>
		</div>
	<?php

	}
	finCarte(); ?>

<?php
include("includes/copyright.php");
?>
