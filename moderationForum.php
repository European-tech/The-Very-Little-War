<?php
include("includes/basicprivatephp.php");
include("includes/bbcode.php");

// Supression de sanction
if (isset($_GET['supprimer'])) {
	$supprimerId = (int)$_GET['supprimer'];
	dbExecute($base, 'DELETE FROM sanctions WHERE idSanction = ?', 'i', $supprimerId);
}


if (isset($_POST['pseudo'], $_POST['dateFin'], $_POST['motif'])) {
	if (!empty($_POST['pseudo']) && !empty($_POST['dateFin']) && !empty($_POST['motif'])) {
		$nb = dbCount($base, 'SELECT count(*) FROM membre WHERE login = ?', 's', $_POST['pseudo']);
		// On vérifie que le joueur existe
		if ($nb > 0) {
			// Convertion de la date au format anglais
			list($jour, $mois, $annee) = explode('/', $_POST['dateFin']);
			$date = $annee . '-' . $mois . '-' . $jour;
			dbExecute($base, 'INSERT INTO sanctions VALUES (default, ?, CURRENT_DATE, ?, ?, ?)', 'ssss', $_POST['pseudo'], $date, $_POST['motif'], $_SESSION['login']);
		} else {
			$erreur = "<strong>Erreur</strong> : Ce joueur n'existe pas.";
		}
	} else {
		$erreur = "<strong>Erreur</strong> :Tous les champs doivent être remplis.";
	}
}

include("includes/tout.php");
$joueur = dbFetchOne($base, 'SELECT moderateur FROM membre WHERE login = ?', 's', $_SESSION['login']);
if ($joueur['moderateur']) {

	debutCarte("Modération du forum");

	echo important("Bannir un membre");
?>
	<form method="post" action="moderationForum.php" name="formModeration">
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
	<link rel="stylesheet" href="http://code.jquery.com/ui/1.10.3/themes/smoothness/jquery-ui.css" />
	<script src="http://code.jquery.com/jquery-1.9.1.js"></script>
	<script src="http://code.jquery.com/ui/1.10.3/jquery-ui.js"></script>
	<link rel="stylesheet" href="/resources/demos/style.css" />
	<script>
		$(function() {
			// Construction et paramétrage du selecteur de date
			$("#dateFin").datepicker({
				minDate: 1,
				dayNamesMin: ["Di", "Lu", "Ma", "Me", "Je", "Ve", "Sa"],
				monthNames: ["Janvier", "Février", "Mars", "Avril", "Mai", "Juin", "Juillet", "Août", "Septembre", "Octobre", "Novembre", "Decembre"],
				hideIfNoPrevNext: true,
				constrainInput: true
			});
			$("#dateFin").datepicker("option", "dateFormat", "dd/mm/yy");
		});
	</script><br />
	<?php
	finCarte();
	debutCarte("Sanctions en cours");
	$ex3 = dbQuery($base, 'SELECT * FROM sanctions');
	if (!mysqli_num_rows($ex3)) {
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
					while ($sanction = mysqli_fetch_array($ex3)) {
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
								<td><a href=\"moderationForum.php?supprimer=" . (int)$sanction['idSanction'] . "\"><img  src=\"images/croix.png\" alt=\"supprimer\"></a></td>
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
}
// Si l'utilisateur n'est pas un modérateur, on affiche un message d'erreur
else {
	debutCarte();
	debutContent();
	echo
	'
							<span class="important">Erreur : Accès interdit</span>
							Seul les modérateurs on accès à cette page.
					';
	finContent();
	finCarte();
}
include("includes/copyright.php");
?>
