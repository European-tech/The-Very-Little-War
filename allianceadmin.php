<?php
include("includes/basicprivatephp.php");
include("includes/bbcode.php");


// H-027: Verify alliance membership before allowing admin access
$currentAlliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login = ?', 's', $_SESSION['login']);
if (!$currentAlliance || (int)$currentAlliance['idalliance'] === 0) {
    header('Location: alliance.php');
    exit();
}

$chef = dbFetchOne($base, 'SELECT * FROM alliances WHERE id=?', 'i', $currentAlliance['idalliance']);
if (!$chef) {
    header('Location: alliance.php');
    exit();
}

$grade = dbFetchOne($base, 'SELECT * FROM grades WHERE login=? AND idalliance=?', 'si', $_SESSION['login'], $chef['id']);
$existeGrade = $grade ? 1 : 0;

$joueurRows = dbFetchAll($base, 'SELECT login FROM autre WHERE idalliance=?', 'i', $currentAlliance['idalliance']);
$nombreJoueurs = count($joueurRows);

if ($chef['chef'] != $_SESSION['login'] and $existeGrade < 1) {
	header("Location: alliance.php"); exit;
}

if ($_SESSION['login'] != $chef['chef']) {
	list($inviter, $guerre, $pacte, $bannir, $description) = explode('.', $grade['grade']);
	$inviter     = ($inviter === '1');
	$guerre      = ($guerre === '1');
	$bannir      = ($bannir === '1');
	$pacte       = ($pacte === '1');
	$description = ($description === '1');
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
		logInfo('ALLIANCE', 'Alliance deleted', ['alliance_id' => $currentAlliance['idalliance'], 'deleted_by' => $_SESSION['login']]);
		supprimerAlliance($currentAlliance['idalliance']);
		header("Location: alliance.php"); exit;
	}

	if (isset($_POST['changernom'])) {
		csrfCheck();
		if (!empty($_POST['changernom'])) {
			$_POST['changernom'] = trim($_POST['changernom']);
			if (!preg_match('/^[\w\s\-\.\']{3,50}$/u', $_POST['changernom'])) {
				$erreur = "Le nom d'alliance ne peut contenir que des lettres, chiffres, espaces, tirets et apostrophes (3-50 caractères).";
			} elseif (mb_strlen($_POST['changernom']) > 50) {
				$erreur = "Le nom de l'alliance ne peut pas dépasser 50 caractères.";
			} else {
				// PASS1-MEDIUM-017: Wrap uniqueness check + UPDATE in a transaction to prevent race conditions
				try {
					$newNom = $_POST['changernom'];
					$allianceId = $currentAlliance['idalliance'];
					withTransaction($base, function() use ($base, $newNom, $allianceId) {
						$exists = dbCount($base, 'SELECT COUNT(*) FROM alliances WHERE nom=? AND id!=? FOR UPDATE', 'si', $newNom, $allianceId);
						if ($exists > 0) {
							throw new \RuntimeException('DUPLICATE');
						}
						dbExecute($base, 'UPDATE alliances SET nom=? WHERE id=?', 'si', $newNom, $allianceId);
					});
					$information = 'Le nom de l\'équipe a bien été changé et est devenu ' . htmlspecialchars($_POST['changernom'], ENT_QUOTES, 'UTF-8') . '.';
				} catch (\RuntimeException $e) {
					$erreur = "Une équipe avec ce nom existe déjà.";
				}
			}
		} else {
			$erreur = "Le nom de votre équipe doit au moins comporter un caractère.";
		}
	}

	if (isset($_POST['nomgrade']) and isset($_POST['personnegrade'])) {
		csrfCheck();
		$_POST['nomgrade'] = trim($_POST['nomgrade']);
		$_POST['personnegrade'] = ucfirst(trim($_POST['personnegrade']));
		if (!empty($_POST['nomgrade']) and !empty($_POST['personnegrade'])) {
			// MED-026: Validate grade name — length, charset, uniqueness
			if (mb_strlen($_POST['nomgrade']) > ALLIANCE_GRADE_MAX_LENGTH) {
				$erreur = "Le nom du grade ne peut pas dépasser " . ALLIANCE_GRADE_MAX_LENGTH . " caractères.";
			} elseif (!preg_match('/^[a-zA-Z0-9 _\-]+$/', $_POST['nomgrade'])) {
				$erreur = "Le nom du grade ne peut contenir que des lettres, chiffres, espaces, tirets et underscores.";
			} elseif (dbCount($base, 'SELECT COUNT(*) FROM grades WHERE idalliance=? AND nom=?', 'is', $chef['id'], $_POST['nomgrade']) > 0) {
				$erreur = "Un grade avec ce nom existe déjà dans votre alliance.";
			} else {
				$gradee = dbCount($base, 'SELECT count(*) as nb FROM grades WHERE login=? AND idalliance=?', 'si', $_POST['personnegrade'], $chef['id']);
				if ($_POST['personnegrade'] != $chef['chef'] and $gradee < 1) {
					$existe = dbCount($base, 'SELECT count(*) as nb FROM membre WHERE login=?', 's', $_POST['personnegrade']);
					$inAlliance = dbCount($base, 'SELECT count(*) as nb FROM autre WHERE login=? AND idalliance=?', 'si', $_POST['personnegrade'], $chef['id']);
					if ($existe >= 1 && $inAlliance >= 1) {
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
						$information = "" . htmlspecialchars($_POST['personnegrade'], ENT_QUOTES, 'UTF-8') . " a été gradé " . htmlspecialchars($_POST['nomgrade'], ENT_QUOTES, 'UTF-8') . ".";
					} else {
						$erreur = "Cette personne n'existe pas ou n'est pas dans votre alliance.";
					}
				} else {
					$erreur = "Cette personne est déjà gradée.";
				}
			} // end MED-026 validation
		} else {
			$erreur = "Tout les champs ne sont pas remplis";
		}
	}

	if (isset($_POST['joueurGrade']) and !empty($_POST['joueurGrade'])) {
		csrfCheck();
		$_POST['joueurGrade'] = trim($_POST['joueurGrade']);
		$gradeExiste = dbCount($base, 'SELECT count(*) AS gradeExiste FROM grades WHERE login=? AND idalliance=?', 'si', $_POST['joueurGrade'], $chef['id']);

		if ($gradeExiste > 0) {
			dbExecute($base, 'DELETE FROM grades WHERE login=? AND idalliance=?', 'si', $_POST['joueurGrade'], $chef['id']);
			$information = "Vous avez supprimé le grade de " . htmlspecialchars($_POST['joueurGrade'], ENT_QUOTES, 'UTF-8') . ".";
		} else {
			$erreur = "Cette guerre n'existe pas.";
		}
	}

	if (isset($_POST['changertag'])) {
		csrfCheck();
		if (!empty($_POST['changertag'])) {
			$_POST['changertag'] = trim($_POST['changertag']);
			if (!preg_match('#^[a-zA-Z0-9_]{' . ALLIANCE_TAG_MIN_LENGTH . ',' . ALLIANCE_TAG_MAX_LENGTH . '}$#', $_POST['changertag'])) {
				$erreur = "Le tag ne peut contenir que des lettres, chiffres et underscores (" . ALLIANCE_TAG_MIN_LENGTH . " min, " . ALLIANCE_TAG_MAX_LENGTH . " max).";
			} else {
				// PASS1-MEDIUM-017: Wrap uniqueness check + UPDATE in a transaction to prevent race conditions
				try {
					$newTag = $_POST['changertag'];
					$allianceId = $currentAlliance['idalliance'];
					withTransaction($base, function() use ($base, $newTag, $allianceId) {
						$exists = dbCount($base, 'SELECT COUNT(*) FROM alliances WHERE tag=? AND id!=? FOR UPDATE', 'si', $newTag, $allianceId);
						if ($exists > 0) {
							throw new \RuntimeException('DUPLICATE');
						}
						dbExecute($base, 'UPDATE alliances SET tag=? WHERE id=?', 'si', $newTag, $allianceId);
					dbExecute($base, 'UPDATE invitations SET tag=? WHERE idalliance=?', 'si', $newTag, $allianceId);
					});
					$information = 'Le tag de l\'équipe a bien été changé et est devenu ' . htmlspecialchars($_POST['changertag'], ENT_QUOTES, 'UTF-8') . '.';
				} catch (\RuntimeException $e) {
					$erreur = "Une équipe avec ce tag existe déjà.";
				}
			}
		} else {
			$erreur = "Le tag de votre équipe doit au moins comporter un caractère.";
		}
	}

	if (isset($_POST['changerchef'])) {
		csrfCheck();
		if (!empty($_POST['changerchef'])) {
			$_POST['changerchef'] = trim($_POST['changerchef']);
			$newChef = $_POST['changerchef'];
			try {
				withTransaction($base, function() use ($base, $currentAlliance, $newChef) {
					$member = dbFetchOne($base, 'SELECT login FROM autre WHERE idalliance=? AND login=? FOR UPDATE', 'is', $currentAlliance['idalliance'], $newChef);
					if (!$member) {
						throw new \RuntimeException('NOT_IN_ALLIANCE');
					}
					dbExecute($base, 'UPDATE alliances SET chef=? WHERE id=?', 'si', $newChef, $currentAlliance['idalliance']);
				});
				header("Location: alliance.php"); exit;
			} catch (\RuntimeException $e) {
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
			$_POST['changerdescription'] = trim($_POST['changerdescription']);
			dbExecute($base, 'UPDATE alliances SET description=? WHERE id=?', 'si', $_POST['changerdescription'], $currentAlliance['idalliance']);
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
			$_POST['bannirpersonne'] = ucfirst(trim($_POST['bannirpersonne']));
			$dansLAlliance = dbCount($base, 'SELECT count(*) as nb FROM autre WHERE idalliance=? AND login=?', 'is', $currentAlliance['idalliance'], $_POST['bannirpersonne']);
			if ($dansLAlliance > 0) {
				// Cannot ban the alliance chef
				$allianceData = dbFetchOne($base, 'SELECT chef FROM alliances WHERE id=?', 'i', $currentAlliance['idalliance']);
				if ($_POST['bannirpersonne'] === $_SESSION['login']) {
					$erreur = "Vous ne pouvez pas vous bannir vous-même.";
				} elseif ($allianceData && $allianceData['chef'] === $_POST['bannirpersonne']) {
					$erreur = "Vous ne pouvez pas bannir le chef de l'alliance.";
				} else {
				$kickedLogin = $_POST['bannirpersonne'];
				// AA-002: Wrap all ban operations in a transaction for atomicity
				withTransaction($base, function() use ($base, $kickedLogin, $currentAlliance) {
					dbExecute($base, 'UPDATE autre SET idalliance=0 WHERE login=?', 's', $kickedLogin);
					dbExecute($base, 'DELETE FROM grades WHERE idalliance=? AND login=?', 'is', $currentAlliance['idalliance'], $kickedLogin);
					// MED-027: Clean up pending invitations for the kicked player
					dbExecute($base, 'DELETE FROM invitations WHERE invite=?', 's', $kickedLogin);
					// Record kick timestamp for rejoin cooldown (column added by migration 0030)
					try {
						dbExecute($base, 'UPDATE autre SET alliance_left_at=UNIX_TIMESTAMP() WHERE login=?', 's', $kickedLogin);
					} catch (\Exception $e) {
						// Column not yet present — migration pending, skip silently
					}
				});
				$information = 'Vous avez banni ' . htmlspecialchars($kickedLogin, ENT_QUOTES, 'UTF-8') . '.';
				}
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
		$_POST['pacte'] = trim($_POST['pacte']);
		$existeAllianceRows = dbFetchAll($base, 'SELECT id FROM alliances WHERE tag=? AND id!=?', 'si', $_POST['pacte'], $currentAlliance['idalliance']);
		$existeAlliance = count($existeAllianceRows);
		if ($existeAlliance > 0) {
			$allianceAllie = dbFetchOne($base, 'SELECT * FROM alliances WHERE tag=?', 's', $_POST['pacte']);

			$nbDeclarations = dbFetchOne($base, 'SELECT count(*) AS nbDeclarations FROM declarations WHERE alliance1=? AND alliance2=? AND (fin=0 OR type=1)', 'ii', $allianceAllie['id'], $chef['id']);

			$nbDeclarations1 = dbFetchOne($base, 'SELECT count(*) AS nbDeclarations FROM declarations WHERE alliance2=? AND alliance1=? AND (fin=0 OR type=1)', 'ii', $allianceAllie['id'], $chef['id']);

			// PASS1-MEDIUM-013: Atomic duplicate check + insert via transaction + FOR UPDATE lock
			try {
				$pactResult = withTransaction($base, function() use ($base, $chef, $allianceAllie) {
					// Lock both rows to prevent concurrent duplicate pact inserts
					$dup1 = dbFetchOne($base, 'SELECT count(*) AS nb FROM declarations WHERE alliance1=? AND alliance2=? AND (fin=0 OR type=1) FOR UPDATE', 'ii', $allianceAllie['id'], $chef['id']);
					$dup2 = dbFetchOne($base, 'SELECT count(*) AS nb FROM declarations WHERE alliance2=? AND alliance1=? AND (fin=0 OR type=1) FOR UPDATE', 'ii', $allianceAllie['id'], $chef['id']);
					if ($dup1['nb'] > 0 || $dup2['nb'] > 0) {
						throw new \RuntimeException('DUPLICATE');
					}
					$now = time();
					dbExecute($base, 'INSERT INTO declarations VALUES(default, 1, ?, ?, ?, default, default, default, default, default)', 'iii', $chef['id'], $allianceAllie['id'], $now);
					$idDeclaration = dbFetchOne($base, 'SELECT id FROM declarations WHERE type=1 AND valide=0 AND alliance1=? AND alliance2=?', 'ii', $chef['id'], $allianceAllie['id']);
					$safeTag = htmlspecialchars($chef['tag'], ENT_QUOTES, 'UTF-8');
					$rapportTitre = 'L\'alliance ' . $safeTag . ' vous propose un pacte.';
					$rapportContenu = 'L\'alliance <a href="alliance.php?id=' . urlencode($chef['tag']) . '">' . $safeTag . '</a> vous propose un pacte.
					<form action="validerpacte.php" method="post">
					' . csrfField() . '
					<input type="submit" value="Accepter" name="accepter"/>
					<input type="submit" value="Refuser" name="refuser"/>
					<input type="hidden" value="' . $idDeclaration['id'] . '" name="idDeclaration"/>
					</form>';
					dbExecute($base, 'INSERT INTO rapports VALUES(default, ?, ?, ?, ?, default)', 'isss', $now, $rapportTitre, $rapportContenu, $allianceAllie['chef']);
					return true;
				});
				$information = "Vous avez proposé un pacte à l'alliance " . htmlspecialchars($_POST['pacte'], ENT_QUOTES, 'UTF-8') . ".";
			} catch (\RuntimeException $e) {
				$erreur = "Soit vous êtes déjà allié avec cette équipe, soit vous êtes en guerre avec elle.";
			}
		} else {
			$erreur = "Cette équipe n'existe pas.";
		}
	}

	if (isset($_POST['allie']) and !empty($_POST['allie'])) {
		csrfCheck();
		$_POST['allie'] = intval($_POST['allie']);
		$pacteExiste = dbCount($base, 'SELECT count(*) AS pacteExiste FROM declarations WHERE ((alliance1=? AND alliance2=?) OR (alliance2=? AND alliance1=?)) AND type=1 AND valide!=0', 'iiii', $chef['id'], $_POST['allie'], $chef['id'], $_POST['allie']);

		if ($pacteExiste > 0) {
			$allianceAdverse = dbFetchOne($base, 'SELECT * FROM alliances WHERE id=?', 'i', $_POST['allie']);
			dbExecute($base, 'DELETE FROM declarations WHERE ((alliance1=? AND alliance2=?) OR (alliance2=? AND alliance1=?)) AND type=1', 'iiii', $chef['id'], $allianceAdverse['id'], $chef['id'], $allianceAdverse['id']);
			$now = time();
			$safePacteTag = htmlspecialchars($chef['tag'], ENT_QUOTES, 'UTF-8');
			$rapportTitre = 'L\'alliance ' . $safePacteTag . ' met fin au pacte qui vous alliait.';
			$rapportContenu = 'L\'alliance <a href="alliance.php?id=' . urlencode($chef['tag']) . '">' . $safePacteTag . '</a> met fin au pacte qui vous alliait.';
			dbExecute($base, 'INSERT INTO rapports VALUES(default, ?, ?, ?, ?, default)', 'isss', $now, $rapportTitre, $rapportContenu, $allianceAdverse['chef']);
			$information = "Le pacte avec " . htmlspecialchars($allianceAdverse['tag'], ENT_QUOTES, 'UTF-8') . " est bien rompu.";
		} else {
			$erreur = "Ce pacte n'existe pas.";
		}
	}
}

if ($guerre) {
	if (isset($_POST['guerre'])) {
		csrfCheck();
		$_POST['guerre'] = trim($_POST['guerre']);
		$existeAllianceRows = dbFetchAll($base, 'SELECT id FROM alliances WHERE tag=? AND id!=?', 'si', $_POST['guerre'], $currentAlliance['idalliance']);
		$existeAlliance = count($existeAllianceRows);
		if ($existeAlliance > 0) {
			$allianceAdverse = dbFetchOne($base, 'SELECT * FROM alliances WHERE tag=?', 's', $_POST['guerre']);
			$allianceAdverseId = $allianceAdverse['id'];
			$allianceAdverseChef = $allianceAdverse['chef'];
			$chefId = $chef['id'];
			$chefTag = $chef['tag'];

			// PASS1-LOW-022: Duplicate check + INSERT in a single transaction with FOR UPDATE to prevent race conditions
			try {
				withTransaction($base, function() use ($base, $allianceAdverseId, $allianceAdverseChef, $chefId, $chefTag) {
					// Authoritative duplicate check inside transaction with row lock
					$dup1 = dbCount($base, 'SELECT COUNT(*) FROM declarations WHERE alliance1=? AND alliance2=? AND ((fin=0 AND type=0) OR (type=1 AND valide!=0)) FOR UPDATE', 'ii', $allianceAdverseId, $chefId);
					$dup2 = dbCount($base, 'SELECT COUNT(*) FROM declarations WHERE alliance2=? AND alliance1=? AND ((fin=0 AND type=0) OR (type=1 AND valide!=0)) FOR UPDATE', 'ii', $allianceAdverseId, $chefId);
					if ($dup1 > 0 || $dup2 > 0) {
						throw new \RuntimeException('DUPLICATE');
					}
					dbExecute($base, 'DELETE FROM declarations WHERE alliance1=? AND alliance2=? AND fin=0 AND valide=0', 'ii', $allianceAdverseId, $chefId);
					dbExecute($base, 'DELETE FROM declarations WHERE alliance2=? AND alliance1=? AND fin=0 AND valide=0', 'ii', $allianceAdverseId, $chefId);
					$now = time();
					dbExecute($base, 'INSERT INTO declarations VALUES(default, 0, ?, ?, ?, default, default, default, default, default)', 'iii', $chefId, $allianceAdverseId, $now);
					$safeChefTag = htmlspecialchars($chefTag, ENT_QUOTES, 'UTF-8');
					$rapportTitre = 'L\'alliance ' . $safeChefTag . ' vous déclare la guerre.';
					$rapportContenu = 'L\'alliance <a href="alliance.php?id=' . urlencode($chefTag) . '">' . $safeChefTag . '</a> vous déclare la guerre.';
					dbExecute($base, 'INSERT INTO rapports VALUES(default, ?, ?, ?, ?, default)', 'isss', $now, $rapportTitre, $rapportContenu, $allianceAdverseChef);
				});
				$information = "Vous avez déclaré la guerre à l'équipe " . htmlspecialchars($_POST['guerre'], ENT_QUOTES, 'UTF-8') . ".";
			} catch (\RuntimeException $e) {
				$erreur = "Soit une guerre est déjà déclarée contre cette équipe, soit vous êtes alliés avec elle.";
			}
		} else {
			$erreur = "Cette équipe n'existe pas.";
		}
	}

	if (isset($_POST['adversaire']) and !empty($_POST['adversaire'])) {
		csrfCheck();
		$_POST['adversaire'] = intval($_POST['adversaire']);

		// HIGH-005: Only the declaring alliance (alliance1) may end the war.
		// This prevents the attacked party from unilaterally cancelling a war they did not start.
		$guerreExiste = dbCount($base, 'SELECT count(*) AS guerreExiste FROM declarations WHERE alliance1=? AND alliance2=? AND type=0 AND fin=0', 'ii', $chef['id'], $_POST['adversaire']);

		if ($guerreExiste > 0) {
			$allianceAdverse = dbFetchOne($base, 'SELECT * FROM alliances WHERE id=?', 'i', $_POST['adversaire']);

			$now = time();
			dbExecute($base, 'UPDATE declarations SET fin=? WHERE alliance1=? AND alliance2=? AND fin=0 AND type=0', 'iii', $now, $chef['id'], $allianceAdverse['id']);
			$safeWarTag = htmlspecialchars($chef['tag'], ENT_QUOTES, 'UTF-8');
			$rapportTitre = 'L\'alliance ' . $safeWarTag . ' met fin à la guerre qui vous opposait.';
			$rapportContenu = 'L\'alliance <a href="alliance.php?id=' . urlencode($chef['tag']) . '">' . $safeWarTag . '</a> met fin à la guerre qui vous opposait.';
			dbExecute($base, 'INSERT INTO rapports VALUES(default, ?, ?, ?, ?, default)', 'isss', $now, $rapportTitre, $rapportContenu, $allianceAdverse['chef']);
			$information = "La guerre contre " . htmlspecialchars($allianceAdverse['tag'], ENT_QUOTES, 'UTF-8') . " a pris fin.";
		} else {
			$erreur = "Cette guerre n'existe pas ou vous n'êtes pas à l'origine de cette déclaration de guerre.";
		}
	}
}

if ($inviter) {
	if (isset($_POST['inviterpersonne'])) {
		csrfCheck();
		if (!empty($_POST['inviterpersonne'])) {
			if ($nombreJoueurs < $joueursEquipe) {
				$_POST['inviterpersonne'] = ucfirst(trim($_POST['inviterpersonne']));
				$joueurExiste = dbCount($base, 'SELECT count(*) as nb FROM autre WHERE login=?', 's', $_POST['inviterpersonne']);

				$invitationDejaEnvoye = dbCount($base, 'SELECT count(*) as nb FROM invitations WHERE invite=? AND idalliance=?', 'si', $_POST['inviterpersonne'], $currentAlliance['idalliance']);
				if ($invitationDejaEnvoye == 0) {
					if ($joueurExiste > 0) {
						// HIGH-036: Block invite if target player is already in an alliance
						$targetAlliance = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=?', 's', $_POST['inviterpersonne']);
						if ($targetAlliance && (int)$targetAlliance['idalliance'] !== 0) {
							$erreur = "Ce joueur est déjà dans une alliance.";
						} else {
							dbExecute($base, 'INSERT INTO invitations VALUES (default, ?, ?, ?)', 'iss', $currentAlliance['idalliance'], $chef['tag'], $_POST['inviterpersonne']);
							$information = 'Vous avez invité ' . htmlspecialchars($_POST['inviterpersonne'], ENT_QUOTES, 'UTF-8') . '';
						}
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
$chef = dbFetchOne($base, 'SELECT * FROM alliances WHERE id=?', 'i', $currentAlliance['idalliance']);

$pageTitle = 'Administration Alliance — The Very Little War';
include("includes/layout.php");
debutCarte('Paramètres de l\'équipe');
debutListe();
if ($chef) {
	item(['form' => ["allianceadmin.php", "changerNom"], 'floating' => true, 'titre' => "Nom de l'alliance", 'input' => '<input type="text" name="changernom" id="changernom" value="' . htmlspecialchars($chef['nom'], ENT_QUOTES, 'UTF-8') . '" class="form-control"/>' . csrfField(), 'after' => submit(['titre' => 'Changer', 'form' => 'changerNom'])]);

	item(['form' => ["allianceadmin.php", "changerTAG"], 'floating' => true, 'titre' => "TAG", 'input' => '<input maxlength="' . ALLIANCE_TAG_MAX_LENGTH . '" type="text" name="changertag" id="changertag" value="' . htmlspecialchars($chef['tag'], ENT_QUOTES, 'UTF-8') . '" class="form-control"/>' . csrfField(), 'after' => submit(['titre' => 'Changer', 'form' => 'changerTAG'])]);
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
	$chefRows = dbFetchAll($base, 'SELECT login FROM autre WHERE idalliance=?', 'i', $currentAlliance['idalliance']);
	foreach ($chefRows as $chef1) {
		$safe = htmlspecialchars($chef1['login'], ENT_QUOTES, 'UTF-8'); $options = $options . '<option value="' . $safe . '">' . $safe . '</option>';
	}
	item(['form' => ["allianceadmin.php", "formChangerChef"], 'select' => ['changerchef', $options], 'titre' => 'Chef', 'input' => csrfField()]);
	item(['input' => submit(['titre' => 'Changer', 'form' => 'formChangerChef'])]);
	echo '<hr/>';
}

if ($bannir) {
	$options = '';
	$bannirRows = dbFetchAll($base, 'SELECT login FROM autre WHERE idalliance=?', 'i', $currentAlliance['idalliance']);
	foreach ($bannirRows as $chef1) {
		$safe = htmlspecialchars($chef1['login'], ENT_QUOTES, 'UTF-8'); $options = $options . '<option value="' . $safe . '">' . $safe . '</option>';
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
		item(['floating' => true, 'titre' => "Nom du grade", 'input' => '<input type="text" name="nomgrade" id="nomgrade" maxlength="' . ALLIANCE_GRADE_MAX_LENGTH . '" class="form-control"/>']);

		$options = '';
		$gradeMembreRows = dbFetchAll($base, 'SELECT login FROM autre WHERE idalliance=?', 'i', $currentAlliance['idalliance']);
		foreach ($gradeMembreRows as $chef1) {
			$safe = htmlspecialchars($chef1['login'], ENT_QUOTES, 'UTF-8'); $options = $options . '<option value="' . $safe . '">' . $safe . '</option>';
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
		$listeGradesRows = dbFetchAll($base, 'SELECT * FROM grades WHERE idalliance=?', 'i', $chef['id']);
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
					foreach ($listeGradesRows as $listeGrades) {
						$safeLogin = htmlspecialchars($listeGrades['login'], ENT_QUOTES, 'UTF-8');
						$safeNom = htmlspecialchars($listeGrades['nom'], ENT_QUOTES, 'UTF-8');
						echo '<tr>
                            <td><a href="joueur.php?id=' . $safeLogin . '">' . $safeLogin . '</a></td>
                            <td>' . $safeNom . '</td>
                            <td>
                            <form method="post" action="allianceadmin.php" style="display:inline">' . csrfField() . '
                            <input type="hidden" name="joueurGrade" value="' . $safeLogin . '"/>
                            <input src="images/croix.png" alt="suppr" type="image" name="Supprimer"/>
                            </form></td>
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
	$pacteRows1 = dbFetchAll($base, 'SELECT * FROM declarations WHERE alliance1=? AND type=1 AND valide!=0', 'i', $chef['id']);
	echo '
                        <div class="table-responsive">
                        <table class="table table-striped table-bordered">
                        <thead>
                        <tr>
                        <th>Allié</th>
                        <th>Début</th>
                        <th>Fin</th>
                        </tr></thead><tbody>';
	foreach ($pacteRows1 as $pacte) {
		$tagAlliance = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id=?', 'i', $pacte['alliance2']);
		$safeAllyTag = htmlspecialchars($tagAlliance['tag'], ENT_QUOTES, 'UTF-8');
		echo '<tr>
                            <td><a href="alliance.php?id=' . urlencode($tagAlliance['tag']) . '">' . $safeAllyTag . '</a></td>
                            <td>' . date('d/m/Y à H\hi', $pacte['timestamp']) . '</td>
                            <td><form action="allianceadmin.php" method="post">' . csrfField() . '
                            <input type="hidden" name="allie" value="' . $pacte['alliance2'] . '"/>
                            <input src="images/croix.png" alt="stop" type="image" name="stoppacte"></form></td>
                            </tr>';
	}
	$pacteRows2 = dbFetchAll($base, 'SELECT * FROM declarations WHERE alliance2=? AND type=1 AND valide!=0', 'i', $chef['id']);
	foreach ($pacteRows2 as $pacte) {
		$tagAlliance = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id=?', 'i', $pacte['alliance1']);
		$safeAllyTag = htmlspecialchars($tagAlliance['tag'], ENT_QUOTES, 'UTF-8');
		echo '<tr>
                            <td><a href="alliance.php?id=' . urlencode($tagAlliance['tag']) . '">' . $safeAllyTag . '</a></td>
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
	$guerreRows1 = dbFetchAll($base, 'SELECT * FROM declarations WHERE alliance1=? AND type=0 AND fin=0', 'i', $chef['id']);
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
	foreach ($guerreRows1 as $guerre) {
		$tagAlliance = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id=?', 'i', $guerre['alliance2']);
		$safeWarTag = htmlspecialchars($tagAlliance['tag'], ENT_QUOTES, 'UTF-8');
		echo '<tr>
                            <td><a href="alliance.php?id=' . urlencode($tagAlliance['tag']) . '">' . $safeWarTag . '</a></td>
                            <td>' . date('d/m/Y à H\hi', $guerre['timestamp']) . '</td>
                            <td>' . $guerre['pertes1'] . '</td>
                            <td><form action="allianceadmin.php" method="post">' . csrfField() . '
                            <input type="hidden" name="adversaire" value="' . $guerre['alliance2'] . '"/>
                            <input src="images/croix.png" alt="stop" type="image" name="stopguerre"></form></td>
                            </tr>';
	}
	$guerreRows2 = dbFetchAll($base, 'SELECT * FROM declarations WHERE alliance2=? AND type=0 AND fin=0', 'i', $chef['id']);
	foreach ($guerreRows2 as $guerre) {
		$tagAlliance = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id=?', 'i', $guerre['alliance1']);
		$safeWarTag = htmlspecialchars($tagAlliance['tag'], ENT_QUOTES, 'UTF-8');
		echo '<tr>
                            <td><a href="alliance.php?id=' . urlencode($tagAlliance['tag']) . '">' . $safeWarTag . '</a></td>
                            <td>' . date('d/m/Y à H\hi', $guerre['timestamp']) . '</td>
                            <td>' . $guerre['pertes2'] . '</td>
                            <td>Déclarée par ' . $safeWarTag . '</td>
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