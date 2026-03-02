<?php
include("includes/basicprivatephp.php");
include("includes/bbcode.php");


$idalliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_SESSION['login']);

$chef = dbFetchOne($base, 'SELECT * FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);

$ex = dbQuery($base, 'SELECT * FROM grades WHERE login=? AND idalliance=?', 'si', $_SESSION['login'], $chef['id']);
$grade = mysqli_fetch_array($ex);
$existeGrade = mysqli_num_rows($ex);

$ex = dbQuery($base, 'SELECT login FROM autre WHERE idalliance=?', 'i', $idalliance['idalliance']);
$nombreJoueurs = mysqli_num_rows($ex);

if ($chef['chef'] != $_SESSION['login'] and $existeGrade < 1) {
?>
	<script LANGUAGE="JavaScript">
		window.location = "allianceprive.php";
	</script>
	<?php
	exit();
}

if ($_SESSION['login'] != $chef['chef']) {
	list($inviter, $guerre, $pacte, $bannir, $description) = explode('.', $grade['grade']);
	if ($inviter == 1) $inviter = true;
	if ($guerre == 1) $guerre = true;
	if ($bannir == 1) $bannir = true;
	if ($pacte == 1) $pacte = true;
	if ($description == 1) $description = true;
	$gradeChef = false;
} else {
	$inviter = true;
	$guerre = true;
	$bannir = true;
	$pacte = true;
	$description = true;
	$gradeChef = true;
}

if ($gradeChef) {
	if (isset($_POST['supprimeralliance1'])) {
		csrfCheck();
		logInfo('ALLIANCE', 'Alliance deleted', ['alliance_id' => $idalliance['idalliance'], 'deleted_by' => $_SESSION['login']]);
		supprimerAlliance($idalliance['idalliance']);
	?>
		<script LANGUAGE="JavaScript">
			window.location = "allianceprive.php";
		</script>
		<?php
		exit();
	}

	if (isset($_POST['changernom'])) {
		csrfCheck();
		if (!empty($_POST['changernom'])) {
			$_POST['changernom'] = mysqli_real_escape_string($base, stripslashes(antihtml($_POST['changernom'])));
			$nballiance = dbCount($base, 'SELECT count(*) as nb FROM alliances WHERE nom=?', 's', $_POST['changernom']);

			if ($nballiance == 0) {
				dbExecute($base, 'UPDATE alliances SET nom=? WHERE id=?', 'si', $_POST['changernom'], $idalliance['idalliance']);

				$information = 'Le nom de l\'équipe a bien été changé et est devenu ' . $_POST['changernom'] . '.';
			} else {
				$erreur = "Une équipe avec ce nom existe déjà.";
			}
		} else {
			$erreur = "Le nom de votre équipe doit au moins comporter un caractère.";
		}
	}

	if (isset($_POST['nomgrade']) and isset($_POST['personnegrade'])) {
		csrfCheck();
		$_POST['nomgrade'] = mysqli_real_escape_string($base, stripslashes(antihtml($_POST['nomgrade'])));
		$_POST['personnegrade'] = ucfirst(mysqli_real_escape_string($base, stripslashes(antihtml($_POST['personnegrade']))));
		if (!empty($_POST['nomgrade']) and !empty($_POST['personnegrade'])) {
			$gradee = dbCount($base, 'SELECT count(*) as nb FROM grades WHERE login=? AND idalliance=?', 'si', $_POST['personnegrade'], $chef['id']);
			if ($_POST['personnegrade'] != $chef['chef'] and $gradee < 1) {
				$existe = dbCount($base, 'SELECT count(*) as nb FROM membre WHERE login=?', 's', $_POST['personnegrade']);
				if ($existe >= 1) {
					if (isset($_POST['inviterDroit']) and $_POST['inviterDroit']) $droit_inviter = 1;
					else $droit_inviter = 0;
					if (isset($_POST['guerreDroit']) and $_POST['guerreDroit']) $droit_guerre = 1;
					else $droit_guerre = 0;
					if (isset($_POST['pacteDroit']) and $_POST['pacteDroit']) $droit_pacte = 1;
					else $droit_pacte = 0;
					if (isset($_POST['bannirDroit']) and $_POST['bannirDroit']) $droit_bannir = 1;
					else $droit_bannir = 0;
					if (isset($_POST['descriptionDroit']) and $_POST['descriptionDroit']) $droit_description = 1;
					else $droit_description = 0;

					$gradeStr = $droit_inviter . '.' . $droit_guerre . '.' . $droit_pacte . '.' . $droit_bannir . '.' . $droit_description;
					dbExecute($base, 'INSERT INTO grades VALUES(?,?,?,?)', 'ssss', $_POST['personnegrade'], $gradeStr, $chef['id'], $_POST['nomgrade']);
					$information = "" . $_POST['personnegrade'] . " a été gradé " . $_POST['nomgrade'] . ".";
				} else {
					$erreur = "Cette personne n'existe pas";
				}
			} else {
				$erreur = "Cette personne est déjà gradée.";
			}
		} else {
			$erreur = "Tout les champs ne sont pas remplis";
		}
	}

	if (isset($_POST['joueurGrade']) and !empty($_POST['joueurGrade'])) {
		csrfCheck();
		$_POST['joueurGrade'] = mysqli_real_escape_string($base, stripslashes(antihtml($_POST['joueurGrade'])));
		$gradeExiste = dbCount($base, 'SELECT count(*) AS gradeExiste FROM grades WHERE login=? AND idalliance=?', 'si', $_POST['joueurGrade'], $chef['id']);

		if ($gradeExiste > 0) {
			dbExecute($base, 'DELETE FROM grades WHERE login=? AND idalliance=?', 'si', $_POST['joueurGrade'], $chef['id']);
			$information = "Vous avez supprimé le grade de " . $_POST['joueurGrade'] . ".";
		} else {
			$erreur = "Cette guerre n'existe pas.";
		}
	}

	if (isset($_POST['changertag'])) {
		csrfCheck();
		if (!empty($_POST['changertag'])) {
			$_POST['changertag'] = mysqli_real_escape_string($base, stripslashes(antihtml($_POST['changertag'])));
			$nballiance = dbCount($base, 'SELECT count(*) as nb FROM alliances WHERE tag=?', 's', $_POST['changertag']);

			if ($nballiance == 0) {
				dbExecute($base, 'UPDATE alliances SET tag=? WHERE id=?', 'si', $_POST['changertag'], $idalliance['idalliance']);

				$information = 'Le tag de l\'équipe a bien été changé et est devenu ' . $_POST['changertag'] . '.';
			} else {
				$erreur = "Une équipe avec ce tag existe déjà.";
			}
		} else {
			$erreur = "Le tag de votre équipe doit au moins comporter un caractère.";
		}
	}

	if (isset($_POST['changerchef'])) {
		csrfCheck();
		if (!empty($_POST['changerchef'])) {
			$_POST['changerchef'] = mysqli_real_escape_string($base, stripslashes(antihtml($_POST['changerchef'])));
			$dansLAlliance = dbCount($base, 'SELECT count(*) as nb FROM autre WHERE idalliance=? AND login=?', 'is', $idalliance['idalliance'], $_POST['changerchef']);
			if ($dansLAlliance > 0) {
				dbExecute($base, 'UPDATE alliances SET chef=? WHERE id=?', 'si', $_POST['changerchef'], $idalliance['idalliance']);

		?>
				<script LANGUAGE="JavaScript">
					window.location = "allianceprive.php";
				</script>
	<?php
			} else {
				$erreur = "Le joueur que vous essayez de mettre en chef n'existe pas ou n'est pas dans votre équipe.";
			}
		} else {
			$erreur = "Aucun chef n'a été séléctionné";
		}
	}
}



if ($description) {
	if (isset($_POST['changerdescription'])) {
		csrfCheck();
		if (!empty($_POST['changerdescription'])) {
			$_POST['changerdescription'] = mysqli_real_escape_string($base, stripslashes(antihtml($_POST['changerdescription'])));
			dbExecute($base, 'UPDATE alliances SET description=? WHERE id=?', 'si', $_POST['changerdescription'], $idalliance['idalliance']);
			$information = 'La description de l\'équipe a bien été changée.';
		} else {
			$erreur = "La description de votre équipe doit au moins comporter un caractère.";
		}
	}
}

if ($bannir) {
	if (isset($_POST['bannirpersonne'])) {
		csrfCheck();
		if (!empty($_POST['bannirpersonne'])) {
			$_POST['bannirpersonne'] = ucfirst(mysqli_real_escape_string($base, stripslashes(antihtml($_POST['bannirpersonne']))));
			$dansLAlliance = dbCount($base, 'SELECT count(*) as nb FROM autre WHERE idalliance=? AND login=?', 'is', $idalliance['idalliance'], $_POST['bannirpersonne']);
			if ($dansLAlliance > 0) {
				dbExecute($base, 'UPDATE autre SET idalliance=0 WHERE login=?', 's', $_POST['bannirpersonne']);
				dbExecute($base, 'DELETE FROM grades WHERE idalliance=? AND login=?', 'is', $idalliance['idalliance'], $_POST['bannirpersonne']);
				$information = 'Vous avez banni ' . $_POST['bannirpersonne'] . '.';
			} else {
				$erreur = "Le joueur que vous essayez de bannir n'existe pas ou n'est pas dans votre équipe.";
			}
		} else {
			$erreur = "Aucune personne n'a été séléctionné";
		}
	}
}

if ($pacte) {
	if (isset($_POST['pacte'])) {
		csrfCheck();
		$_POST['pacte'] = mysqli_real_escape_string($base, stripslashes(antihtml($_POST['pacte'])));
		$ex = dbQuery($base, 'SELECT id FROM alliances WHERE tag=? AND id!=?', 'si', $_POST['pacte'], $idalliance['idalliance']);
		$existeAlliance = mysqli_num_rows($ex);
		if ($existeAlliance > 0) {
			$allianceAllie = dbFetchOne($base, 'SELECT * FROM alliances WHERE tag=?', 's', $_POST['pacte']);

			$nbDeclarations = dbFetchOne($base, 'SELECT count(*) AS nbDeclarations FROM declarations WHERE alliance1=? AND alliance2=? AND fin=0', 'ii', $allianceAllie['id'], $chef['id']);

			$nbDeclarations1 = dbFetchOne($base, 'SELECT count(*) AS nbDeclarations FROM declarations WHERE alliance2=? AND alliance1=? AND fin=0', 'ii', $allianceAllie['id'], $chef['id']);

			if ($nbDeclarations['nbDeclarations'] == 0 and $nbDeclarations1['nbDeclarations'] == 0) {
				$now = time();
				dbExecute($base, 'INSERT INTO declarations VALUES(default, 1, ?, ?, ?, default, default, default, default, default)', 'iii', $chef['id'], $allianceAllie['id'], $now);
				$idDeclaration = dbFetchOne($base, 'SELECT id FROM declarations WHERE type=1 AND valide=0 AND alliance1=? AND alliance2=?', 'ii', $chef['id'], $allianceAllie['id']);

				$rapportTitre = 'L\'alliance ' . $chef['tag'] . ' vous propose un pacte.';
				$rapportContenu = 'L\'alliance <a href="alliance.php?id=' . $chef['tag'] . '">' . $chef['tag'] . '</a> vous propose un pacte.
				<form action="validerpacte.php" method="post">
				<input type="submit" value="Accepter" name="accepter"/>
				<input type="submit" value="Refuser" name="refuser"/>
				<input type="hidden" value="' . $idDeclaration['id'] . '" name="idDeclaration"/>
				</form>';
				dbExecute($base, 'INSERT INTO rapports VALUES(default, ?, ?, ?, ?, default)', 'isss', $now, $rapportTitre, $rapportContenu, $allianceAllie['chef']);
				$information = "Vous avez proposé un pacte à l'alliance " . $_POST['pacte'] . ".";
			} else {
				$erreur = "Soit vous êtes déjà allié avec cette équipe, soit vous êtes en guerre avec elle.";
			}
		} else {
			$erreur = "Cette équipe n'existe pas.";
		}
	}

	if (isset($_POST['allie']) and !empty($_POST['allie'])) {
		csrfCheck();
		$_POST['allie'] = intval($_POST['allie']);
		$pacteExiste = dbCount($base, 'SELECT count(*) AS pacteExiste FROM declarations WHERE (alliance1=? OR alliance2=?) AND type=1', 'ii', $_POST['allie'], $_POST['allie']);

		if ($pacteExiste > 0) {
			$allianceAdverse = dbFetchOne($base, 'SELECT * FROM alliances WHERE id=?', 'i', $_POST['allie']);
			dbExecute($base, 'DELETE FROM declarations WHERE ((alliance1=? AND alliance2=?) OR (alliance2=? AND alliance1=?)) AND type=1', 'iiii', $chef['id'], $allianceAdverse['id'], $chef['id'], $allianceAdverse['id']);
			$now = time();
			$rapportTitre = 'L\'alliance ' . $chef['tag'] . ' met fin au pacte qui vous alliait.';
			$rapportContenu = 'L\'alliance <a href="alliance.php?id=' . $chef['tag'] . '">' . $chef['tag'] . '</a> met fin au pacte qui vous alliait.';
			dbExecute($base, 'INSERT INTO rapports VALUES(default, ?, ?, ?, ?, default)', 'isss', $now, $rapportTitre, $rapportContenu, $allianceAdverse['chef']);
			$information = "Le pacte avec " . $allianceAdverse['tag'] . " est bien rompu.";
		} else {
			$erreur = "Ce pacte n'existe pas.";
		}
	}
}

if ($guerre) {
	if (isset($_POST['guerre'])) {
		csrfCheck();
		$_POST['guerre'] = mysqli_real_escape_string($base, stripslashes(antihtml($_POST['guerre'])));
		$ex = dbQuery($base, 'SELECT id FROM alliances WHERE tag=? AND id!=?', 'si', $_POST['guerre'], $idalliance['idalliance']);
		$existeAlliance = mysqli_num_rows($ex);
		if ($existeAlliance > 0) {
			$allianceAdverse = dbFetchOne($base, 'SELECT * FROM alliances WHERE tag=?', 's', $_POST['guerre']);
			$nbDeclarations = dbFetchOne($base, 'SELECT count(*) AS nbDeclarations FROM declarations WHERE alliance1=? AND alliance2=? AND ((fin=0 AND type=0) OR (type=1 AND valide!=0))', 'ii', $allianceAdverse['id'], $chef['id']);
			echo $nbDeclarations['nbDeclarations'];

			$nbDeclarations1 = dbFetchOne($base, 'SELECT count(*) AS nbDeclarations FROM declarations WHERE alliance2=? AND alliance1=? AND ((fin=0 AND type=0) OR (type=1 AND valide!=0))', 'ii', $allianceAdverse['id'], $chef['id']);

			if ($nbDeclarations['nbDeclarations'] == 0 and $nbDeclarations1['nbDeclarations'] == 0) {
				dbExecute($base, 'DELETE FROM declarations WHERE alliance1=? AND alliance2=? AND fin=0 AND valide=0', 'ii', $allianceAdverse['id'], $chef['id']);
				dbExecute($base, 'DELETE FROM declarations WHERE alliance2=? AND alliance1=? AND fin=0 AND valide=0', 'ii', $allianceAdverse['id'], $chef['id']);
				$now = time();
				dbExecute($base, 'INSERT INTO declarations VALUES(default, 0, ?, ?, ?, default, default, default, default, default)', 'iii', $chef['id'], $allianceAdverse['id'], $now);
				$rapportTitre = 'L\'alliance ' . $chef['tag'] . ' vous déclare la guerre.';
				$rapportContenu = 'L\'alliance <a href="alliance.php?id=' . $chef['tag'] . '">' . $chef['tag'] . '</a> vous déclare la guerre.';
				dbExecute($base, 'INSERT INTO rapports VALUES(default, ?, ?, ?, ?, default)', 'isss', $now, $rapportTitre, $rapportContenu, $allianceAdverse['chef']);
				$information = "Vous avez déclaré la guerre à l'équipe " . $_POST['guerre'] . ".";
			} else {
				$erreur = "Soit une guerre est déjà déclarée contre cette équipe, soit vous êtes alliés avec elle.";
			}
		} else {
			$erreur = "Cette équipe n'existe pas.";
		}
	}

	if (isset($_POST['adversaire']) and !empty($_POST['adversaire'])) {
		csrfCheck();
		$_POST['adversaire'] = intval($_POST['adversaire']);
		$guerreExiste = dbCount($base, 'SELECT count(*) AS guerreExiste FROM declarations WHERE ((alliance1=? AND alliance2=?) OR (alliance2=? AND alliance1=?)) AND type=0 AND fin=0', 'iiii', $chef['id'], $_POST['adversaire'], $chef['id'], $_POST['adversaire']);

		if ($guerreExiste > 0) {
			$allianceAdverse = dbFetchOne($base, 'SELECT * FROM alliances WHERE id=?', 'i', $_POST['adversaire']);

			$now = time();
			dbExecute($base, 'UPDATE declarations SET fin=? WHERE ((alliance1=? AND alliance2=?) OR (alliance2=? AND alliance1=?)) AND fin=0 AND type=0', 'iiiii', $now, $chef['id'], $allianceAdverse['id'], $chef['id'], $allianceAdverse['id']);
			$rapportTitre = 'L\'alliance ' . $chef['tag'] . ' met fin à la guerre qui vous opposait.';
			$rapportContenu = 'L\'alliance <a href="alliance.php?id=' . $chef['tag'] . '">' . $chef['tag'] . '</a> met fin à la guerre qui vous opposait.';
			dbExecute($base, 'INSERT INTO rapports VALUES(default, ?, ?, ?, ?, default)', 'isss', $now, $rapportTitre, $rapportContenu, $allianceAdverse['chef']);
			$information = "La guerre contre " . $allianceAdverse['tag'] . " a pris fin.";
		} else {
			$erreur = "Cette guerre n'existe pas.";
		}
	}
}

if ($inviter) {
	if (isset($_POST['inviterpersonne'])) {
		csrfCheck();
		if (!empty($_POST['inviterpersonne'])) {
			if ($nombreJoueurs < $joueursEquipe) {
				$_POST['inviterpersonne'] = ucfirst(mysqli_real_escape_string($base, stripslashes(antihtml($_POST['inviterpersonne']))));
				$joueurExiste = dbCount($base, 'SELECT count(*) as nb FROM autre WHERE login=?', 's', $_POST['inviterpersonne']);

				$invitationDejaEnvoye = dbCount($base, 'SELECT count(*) as nb FROM invitations WHERE invite=? AND idalliance=?', 'si', $_POST['inviterpersonne'], $idalliance['idalliance']);
				if ($invitationDejaEnvoye == 0) {
					if ($joueurExiste > 0) {
						dbExecute($base, 'INSERT INTO invitations VALUES (default, ?, ?, ?)', 'iss', $idalliance['idalliance'], $chef['tag'], $_POST['inviterpersonne']);

						$information = 'Vous avez invité ' . $_POST['inviterpersonne'] . '';
					} else {
						$erreur = "Ce joueur n'existe pas.";
					}
				} else {
					$erreur = "Vous avez déja envoyé une invitation à ce joueur.";
				}
			} else {
				$erreur = "Le nombre maximal de joueurs est atteint dans l'équipe";
			}
		} else {
			$erreur = "Je crois qu'une personne sans nom, ça n'existe pas.";
		}
	}
}

// On actualise les informations qui ont pu être changées
$chef = dbFetchOne($base, 'SELECT * FROM alliances WHERE id=?', 'i', $idalliance['idalliance']);

include("includes/tout.php");
debutCarte('Paramètres de l\'équipe');
debutListe();
if ($chef) {
	item(['form' => ["allianceadmin.php", "changerNom"], 'floating' => true, 'titre' => "Nom de l'alliance", 'input' => '<input type="text" name="changernom" id="changernom" value="' . htmlspecialchars(stripslashes($chef['nom']), ENT_QUOTES, 'UTF-8') . '" class="form-control"/>' . csrfField(), 'after' => submit(['titre' => 'Changer', 'form' => 'changerNom'])]);

	item(['form' => ["allianceadmin.php", "changerTAG"], 'floating' => true, 'titre' => "TAG", 'input' => '<input maxlength=10 type="text" name="changertag" id="changertag" value="' . htmlspecialchars(stripslashes($chef['tag']), ENT_QUOTES, 'UTF-8') . '" class="form-control"/>' . csrfField(), 'after' => submit(['titre' => 'Changer', 'form' => 'changerTAG'])]);
}
if ($description) {
	creerBBcode("changerdescription", $chef['description']);
	item(['form' => ["allianceadmin.php", "description"], 'floating' => false, 'titre' => "Description", 'input' => '<textarea name="changerdescription" id="changerdescription" rows="10" cols="50">' . htmlspecialchars($chef['description'], ENT_QUOTES, 'UTF-8') . '</textarea>' . csrfField(), 'after' => submit(['titre' => 'Changer', 'form' => 'description'])]);
}
if ($chef) {
	item(['form' => ["allianceadmin.php", "supprimerAlliance"], 'floating' => false, 'input' => '<input type="hidden" name="supprimeralliance1"/>' . csrfField() . submit(['titre' => 'Supprimer l\'équipe', 'form' => 'supprimerAlliance', 'style' => 'background-color:red'])]);
}
finListe();
finCarte();

debutCarte('Gestion des membres');
debutContent();
debutListe();
if ($gradeChef) {
	$options = '';
	$ex2 = dbQuery($base, 'SELECT login FROM autre WHERE idalliance=?', 'i', $idalliance['idalliance']);
	while ($chef1 = mysqli_fetch_array($ex2)) {
		$options = $options . '<option value=' . $chef1['login'] . '>' . $chef1['login'] . '</option>';
	}
	item(['form' => ["allianceadmin.php", "formChangerChef"], 'select' => ['changerchef', $options], 'titre' => 'Chef', 'input' => csrfField()]);
	item(['input' => submit(['titre' => 'Changer', 'form' => 'formChangerChef'])]);
	echo '<hr/>';
}

if ($bannir) {
	$options = '';
	$ex2 = dbQuery($base, 'SELECT login FROM autre WHERE idalliance=?', 'i', $idalliance['idalliance']);
	while ($chef1 = mysqli_fetch_array($ex2)) {
		$options = $options . '<option value=' . $chef1['login'] . '>' . $chef1['login'] . '</option>';
	}
	item(['form' => ["allianceadmin.php", "bannir"], 'select' => ['bannirpersonne', $options], 'titre' => 'Bannir un membre', 'input' => csrfField()]);
	item(['input' => submit(['titre' => 'Bannir', 'form' => 'bannir'])]);
	echo '<hr/>';
}

if ($inviter) {
	if ($nombreJoueurs < $joueursEquipe) {
		item(['form' => ["allianceadmin.php", "inviterPersonne"], 'titre' => "Inviter", 'ajax' => true, 'autocomplete' => 'labelInviter', 'input' => ' <input type="hidden" name="inviterpersonne" id="inviterpersonne" class="form-control"/>' . csrfField(), 'after' => 'Nom du joueur']);
		item(['input' => submit(['titre' => 'Inviter', 'form' => 'inviterPersonne'])]);
	} else {
		echo 'Le nombre de joueurs maximal est déjà atteint dans l\'équipe.';
	}
}
finListe();
finContent();
finCarte();


if ($gradeChef) {
	debutCarte('Grades');
	echo important('Créer un grade');
	debutListe();
	?>
	<form method="post" action="allianceadmin.php" name="creerGrade">
		<?php echo csrfField(); ?>
		<?php
		item(['floating' => true, 'titre' => "Nom du grade", 'input' => '<input type="text" name="nomgrade" id="nomgrade" class="form-control"/>']);

		$options = '';
		$ex2 = dbQuery($base, 'SELECT login FROM autre WHERE idalliance=?', 'i', $idalliance['idalliance']);
		while ($chef1 = mysqli_fetch_array($ex2)) {
			$options = $options . '<option value=' . $chef1['login'] . '>' . $chef1['login'] . '</option>';
		}
		item(['select' => ['personnegrade', $options], 'titre' => 'Login du gradé']);
		finListe();
		echo checkbox([['name' => 'inviterDroit', 'titre' => 'Inviter des joueurs', 'noList' => true], ['name' => 'guerreDroit', 'titre' => 'Déclarer/finir la guerre', 'noList' => true], ['name' => 'pacteDroit', 'titre' => 'Demander/finir un pacte', 'noList' => true], ['name' => 'bannirDroit', 'titre' => 'Bannir un joueur', 'noList' => true], ['name' => 'descriptionDroit', 'titre' => 'Changer la description', 'noList' => true]]);
		echo '<br/>';
		item(['input' => submit(['titre' => 'Créer', 'form' => 'creerGrade']), 'noList' => true]);
		?> </form>
	<br />
	<?php echo important('Liste des grades'); ?>
	<form method="post" action="allianceadmin.php" name="supprimerGrade">
		<?php echo csrfField(); ?>
		<?php
		$ex = dbQuery($base, 'SELECT * FROM grades WHERE idalliance=?', 'i', $chef['id']);
		?>
		<div class="table-responsive">
			<table class="table table-striped table-bordered">
				<thead>
					<tr>
						<th>Login</th>
						<th>Nom du grade</th>
						<th>Supprimer</th>
					</tr>
				</thead>
				<tbody>
					<?php
					while ($listeGrades = mysqli_fetch_array($ex)) {
						echo '<tr>
                            <td><a href="joueur.php?id=' . $listeGrades['login'] . '">' . $listeGrades['login'] . '</a></td>
                            <td>' . $listeGrades['nom'] . '</td>
                            <td>
                            <input type="hidden" name="joueurGrade" value="' . $listeGrades['login'] . '"/>
                            <input src="images/croix.png" alt="suppr" type="image" name="Supprimer"></td>
                            </tr>';
					}
					?>
				</tbody>
			</table>
		</div>
	</form>
<?php
	finCarte();
} ?>

<?php if ($pacte) {
	debutCarte('Pactes');
	debutListe();
	item(['form' => ["allianceadmin.php", "declarerPacte"], 'floating' => false, 'titre' => "Demander un pacte", 'input' => '<input type="text" name="pacte" id="pacte" placeholder="TAG de l\'alliance" class="form-control"/>' . csrfField(), 'after' => submit(['titre' => 'Demander', 'form' => 'declarerPacte'])]);
	echo '<li>';
	$ex = dbQuery($base, 'SELECT * FROM declarations WHERE alliance1=? AND type=1 AND valide!=0', 'i', $chef['id']);
	echo '
                        <div class="table-responsive">
                        <table class="table table-striped table-bordered">
                        <thead>
                        <tr>
                        <th>Allié</th>
                        <th>Début</th>
                        <th>Fin</th>
                        </tr></thead><tbody>';
	while ($pacte = mysqli_fetch_array($ex)) {
		$tagAlliance = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id=?', 'i', $pacte['alliance2']);

		echo '<tr>
                            <td><a href="alliance.php?id=' . $tagAlliance['tag'] . '">' . $tagAlliance['tag'] . '</a></td>
                            <td>' . date('d/m/Y à H\hi', $pacte['timestamp']) . '</td>
                            <td><form action="allianceadmin.php" method="post">' . csrfField() . '
                            <input type="hidden" name="allie" value="' . $pacte['alliance2'] . '"/>
                            <input src="images/croix.png" alt="stop" type="image" name="stoppacte"></form></td>
                            </tr>';
	}
	$ex = dbQuery($base, 'SELECT * FROM declarations WHERE alliance2=? AND type=1 AND valide!=0', 'i', $chef['id']);
	while ($pacte = mysqli_fetch_array($ex)) {
		$tagAlliance = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id=?', 'i', $pacte['alliance1']);

		echo '<tr>
                            <td><a href="alliance.php?id=' . $tagAlliance['tag'] . '">' . $tagAlliance['tag'] . '</a></td>
                            <td>' . date('d/m/Y à H\hi', $pacte['timestamp']) . '</td>
                            <td><form action="allianceadmin.php" method="post">' . csrfField() . '
                            <input type="hidden" name="allie" value="' . $pacte['alliance1'] . '"/>
                            <input src="images/croix.png" alt="stop" type="image" name="stoppacte"></form></td>
                            </tr>';
	}
?>
	</tbody>
	</table>
	</div>
	</li>
<?php
	finListe();
	finCarte();
}
if ($guerre) {
	debutCarte('Guerres');
	debutListe();
	item(['form' => ["allianceadmin.php", "declarerGuerre"], 'floating' => false, 'titre' => "Déclarer une guerre", 'input' => '<input type="text" name="guerre" id="guerre" placeholder="TAG de l\'alliance" class="form-control"/>' . csrfField(), 'after' => submit(['titre' => 'Déclarer', 'form' => 'declarerGuerre'])]);
	echo '<li>';
	$ex = dbQuery($base, 'SELECT * FROM declarations WHERE alliance1=? AND type=0 AND fin=0', 'i', $chef['id']);
	echo '
                        <div class="table-responsive">
                        <table class="table table-striped table-bordered">
                        <thead>
                        <tr>
                        <th>Adversaire</th>
                        <th>Début</th>
                        <th>Pertes</th>
                        <th>Fin</th>
                        </tr></thead><tbody>';
	while ($guerre = mysqli_fetch_array($ex)) {
		$tagAlliance = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id=?', 'i', $guerre['alliance2']);

		echo '<tr>
                            <td><a href="alliance.php?id=' . $tagAlliance['tag'] . '">' . $tagAlliance['tag'] . '</a></td>
                            <td>' . date('d/m/Y à H\hi', $guerre['timestamp']) . '</td>
                            <td>' . $guerre['pertes1'] . '</td>
                            <td><form action="allianceadmin.php" method="post">' . csrfField() . '
                            <input type="hidden" name="adversaire" value="' . $guerre['alliance2'] . '"/>
                            <input src="images/croix.png" alt="stop" type="image" name="stopguerre"></form></td>
                            </tr>';
	}
	$ex = dbQuery($base, 'SELECT * FROM declarations WHERE alliance2=? AND type=0 AND fin=0', 'i', $chef['id']);
	while ($guerre = mysqli_fetch_array($ex)) {
		$tagAlliance = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id=?', 'i', $guerre['alliance1']);

		echo '<tr>
                            <td><a href="alliance.php?id=' . $tagAlliance['tag'] . '">' . $tagAlliance['tag'] . '</a></td>
                            <td>' . date('d/m/Y à H\hi', $guerre['timestamp']) . '</td>
                            <td>' . $guerre['pertes1'] . '</td>
                            <td>Déclarée par ' . $tagAlliance['tag'] . '</td>
                            </tr>';
	}
?>
	</tbody>
	</table>
	</div>
	</li>
<?php
	finListe();
	finCarte();
}

include("includes/copyright.php"); ?>