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
	$bits = explode('.', $grade['grade'] ?? '');
	if (count($bits) !== 5) {
		[$inviter, $guerre, $pacte, $bannir, $description] = [false, false, false, false, false];
	} else {
		[$inviter, $guerre, $pacte, $bannir, $description] = array_map(fn($b) => $b === '1', $bits);
	}
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
		// M-006: Block alliance dissolution during an active war
		$activeWarDissolve = dbFetchOne($base,
			'SELECT COUNT(*) as cnt FROM declarations WHERE (alliance1=? OR alliance2=?) AND type=0 AND fin=0',
			'ii', $currentAlliance['idalliance'], $currentAlliance['idalliance']
		);
		if ($activeWarDissolve && $activeWarDissolve['cnt'] > 0) {
			$erreur = "Votre alliance est en guerre. Vous devez d'abord terminer la guerre avant de dissoudre l'alliance.";
		} else {
			// H-012: Re-verify chef status INSIDE a transaction to close the TOCTOU window
			// between the outer $gradeChef check and the actual dissolution.
			$dissolveActor  = $_SESSION['login'];
			$dissolveAlliId = $currentAlliance['idalliance'];
			try {
				withTransaction($base, function() use ($base, $dissolveActor, $dissolveAlliId) {
					$allianceLocked = dbFetchOne($base, 'SELECT chef FROM alliances WHERE id=? FOR UPDATE', 'i', $dissolveAlliId);
					if (!$allianceLocked || $allianceLocked['chef'] !== $dissolveActor) {
						throw new \RuntimeException('NOT_CHEF');
					}
					// Re-check war inside transaction (race: war declared between outer check and here)
					$warCheck = dbFetchOne($base,
						'SELECT COUNT(*) as cnt FROM declarations WHERE (alliance1=? OR alliance2=?) AND type=0 AND fin=0',
						'ii', $dissolveAlliId, $dissolveAlliId);
					if ($warCheck && $warCheck['cnt'] > 0) {
						throw new \RuntimeException('WAR_ACTIVE');
					}
					// Authorization confirmed — dissolve inside the tx for atomicity
					supprimerAlliance($dissolveAlliId);
				});
				logInfo('ALLIANCE', 'Alliance deleted', ['alliance_id' => $dissolveAlliId, 'deleted_by' => $dissolveActor]);
				header("Location: alliance.php"); exit;
			} catch (\RuntimeException $e) {
				if ($e->getMessage() === 'NOT_CHEF') {
					$erreur = "Vous n'êtes plus le chef de l'alliance.";
				} elseif ($e->getMessage() === 'WAR_ACTIVE') {
					$erreur = "Votre alliance est en guerre. Vous devez d'abord terminer la guerre avant de dissoudre l'alliance.";
				} else {
					$erreur = "Erreur lors de la dissolution de l'alliance.";
				}
			}
		}
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
				// ALLIANCE-MED-002: Wrap grade creation in a transaction and re-check uniqueness inside
				// to prevent TOCTOU race where two concurrent requests both pass the pre-check and both INSERT.
				$nomgrade = $_POST['nomgrade'];
				$personnegrade = $_POST['personnegrade'];
				$chefId = $chef['id'];
				$chefChef = $chef['chef'];
				$droit_inviter    = (isset($_POST['inviterDroit'])    && $_POST['inviterDroit'])    ? 1 : 0;
				$droit_guerre     = (isset($_POST['guerreDroit'])     && $_POST['guerreDroit'])     ? 1 : 0;
				$droit_pacte      = (isset($_POST['pacteDroit'])      && $_POST['pacteDroit'])      ? 1 : 0;
				$droit_bannir     = (isset($_POST['bannirDroit'])     && $_POST['bannirDroit'])     ? 1 : 0;
				$droit_description= (isset($_POST['descriptionDroit'])&& $_POST['descriptionDroit'])? 1 : 0;
				$gradeStr = $droit_inviter . '.' . $droit_guerre . '.' . $droit_pacte . '.' . $droit_bannir . '.' . $droit_description;

				try {
					withTransaction($base, function() use ($base, $chefId, $chefChef, $nomgrade, $personnegrade, $gradeStr, &$erreur, &$information) {
						// SOC-P6-005: Cap grades per alliance to prevent unbounded growth
						$gradeCount = dbCount($base, 'SELECT COUNT(*) AS cnt FROM grades WHERE idalliance=?', 'i', $chefId);
						if ($gradeCount >= MAX_GRADES_PER_ALLIANCE) {
							$erreur = "Nombre maximum de grades atteint pour cette alliance.";
							return;
						}

						// Re-check uniqueness inside transaction (ALLIANCE-MED-002 TOCTOU guard)
						$dup = dbCount($base, 'SELECT COUNT(*) FROM grades WHERE idalliance=? AND nom=?', 'is', $chefId, $nomgrade);
						if ($dup > 0) {
							$erreur = "Un grade avec ce nom existe déjà dans votre alliance.";
							return;
						}

						$gradee = dbCount($base, 'SELECT count(*) as nb FROM grades WHERE login=? AND idalliance=?', 'si', $personnegrade, $chefId);
						if ($personnegrade != $chefChef and $gradee < 1) {
							$existe = dbCount($base, 'SELECT count(*) as nb FROM membre WHERE login=?', 's', $personnegrade);
							$inAlliance = dbCount($base, 'SELECT login FROM autre WHERE login=? AND idalliance=? FOR UPDATE', 'si', $personnegrade, $chefId);
							if ($existe >= 1 && $inAlliance >= 1) {
								$gradeInsert = dbExecute($base, 'INSERT INTO grades (login, grade, idalliance, nom) VALUES (?, ?, ?, ?)', 'ssis', $personnegrade, $gradeStr, $chefId, $nomgrade);
								if ($gradeInsert !== false) {
									$information = "" . htmlspecialchars($personnegrade, ENT_QUOTES, 'UTF-8') . " a été gradé " . htmlspecialchars($nomgrade, ENT_QUOTES, 'UTF-8') . ".";
								} else {
									$erreur = "Ce joueur est déjà gradé dans cette alliance.";
								}
							} else {
								$erreur = "Cette personne n'existe pas ou n'est pas dans votre alliance.";
							}
						} else {
							$erreur = "Cette personne est déjà gradée.";
						}
					});
				} catch (\Throwable $e) {
					$erreur = "Erreur lors de la création du grade.";
				}
			} // end MED-026 validation
		} else {
			$erreur = "Tout les champs ne sont pas remplis";
		}
	}

	if (isset($_POST['joueurGrade']) and !empty($_POST['joueurGrade'])) {
		csrfCheck();
		$_POST['joueurGrade'] = trim($_POST['joueurGrade']);
		if (mb_strlen($_POST['joueurGrade']) > LOGIN_MAX_LENGTH) {
			$erreur = "Nom de joueur invalide.";
		} else {
			// ALLIANCE-P18-002: Atomic DELETE — skip pre-check SELECT, use affected_rows inside transaction.
			try {
				$joueurGradeTarget = $_POST['joueurGrade'];
				$chefId = $chef['id'];
				withTransaction($base, function() use ($base, $joueurGradeTarget, $chefId) {
					dbExecute($base, 'DELETE FROM grades WHERE login=? AND idalliance=?', 'si', $joueurGradeTarget, $chefId);
					if ($base->affected_rows === 0) {
						throw new \RuntimeException('GRADE_NOT_FOUND');
					}
				});
				$information = "Vous avez supprimé le grade de " . htmlspecialchars($_POST['joueurGrade'], ENT_QUOTES, 'UTF-8') . ".";
			} catch (\RuntimeException $e) {
				if ($e->getMessage() === 'GRADE_NOT_FOUND') {
					$erreur = "Ce grade n'existe pas.";
				} else {
					$erreur = "Erreur lors de la suppression du grade.";
				}
			}
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
			// ALL11-003: Prevent self-transfer (current chef cannot transfer to themselves)
			if ($newChef === $_SESSION['login']) {
				$erreur = "Vous êtes déjà chef de l'alliance.";
			} else
			try {
				withTransaction($base, function() use ($base, $currentAlliance, $newChef) {
					// Lock alliances row first (same order as dissolution) to avoid deadlock
					// H-012: Re-verify the actor is still the chef INSIDE the transaction
					$oldChef = dbFetchOne($base, 'SELECT chef FROM alliances WHERE id=? FOR UPDATE', 'i', $currentAlliance['idalliance']);
					if (!$oldChef || $oldChef['chef'] !== $_SESSION['login']) {
						throw new \RuntimeException('NOT_CHEF');
					}
					// ALLIANCE-M1: Check membership AND estExclu inside the transaction with FOR UPDATE
					$member = dbFetchOne($base, 'SELECT login, estExclu FROM membre m JOIN autre a ON a.login = m.login WHERE m.login = ? AND a.idalliance = ? FOR UPDATE', 'si', $newChef, $currentAlliance['idalliance']);
					if (!$member || (int)$member['estExclu'] === 1) {
						throw new \RuntimeException('NOT_IN_ALLIANCE');
					}
					dbExecute($base, 'UPDATE alliances SET chef=? WHERE id=?', 'si', $newChef, $currentAlliance['idalliance']);
					// SOC-P6-003: Remove outgoing chef's grade so they don't retain officer permissions
					dbExecute($base, 'DELETE FROM grades WHERE login=? AND idalliance=?', 'si', $oldChef['chef'], $currentAlliance['idalliance']);
				});
				header("Location: alliance.php"); exit;
			} catch (\RuntimeException $e) {
				if ($e->getMessage() === 'NOT_CHEF') {
					$erreur = "Vous n'êtes plus le chef de l'alliance.";
				} else {
					$erreur = "Le joueur que vous essayez de mettre en chef n'existe pas ou n'est pas dans votre équipe.";
				}
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
			// MEDIUM-015: Enforce max length on alliance description
			if (mb_strlen($_POST['changerdescription'], 'UTF-8') > ALLIANCE_DESC_MAX_LENGTH) {
				$erreur = "La description est trop longue (" . ALLIANCE_DESC_MAX_LENGTH . " caractères maximum).";
			} else {
				dbExecute($base, 'UPDATE alliances SET description=? WHERE id=?', 'si', $_POST['changerdescription'], $currentAlliance['idalliance']);
				$information = 'La description de l\'équipe a bien été changée.';
			}
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
				// H-020: Officers cannot ban other officers — only the chef can
				$targetGrade = dbFetchOne($base, 'SELECT id FROM grades WHERE login=? AND idalliance=?', 'si', $_POST['bannirpersonne'], $currentAlliance['idalliance']);
				if ($_POST['bannirpersonne'] === $_SESSION['login']) {
					$erreur = "Vous ne pouvez pas vous bannir vous-même.";
				} elseif ($allianceData && $allianceData['chef'] === $_POST['bannirpersonne']) {
					$erreur = "Vous ne pouvez pas bannir le chef de l'alliance.";
				} elseif ($targetGrade && !$gradeChef) {
					$erreur = "Vous ne pouvez pas bannir un autre officier. Seul le chef peut le faire.";
				} else {
				$kickedLogin  = $_POST['bannirpersonne'];
				$actorLogin   = $_SESSION['login'];
				$allianceIdForKick = $currentAlliance['idalliance'];
				// AA-002 / H-012: Wrap all ban operations in a transaction for atomicity.
				// Re-verify actor authorization and target membership INSIDE the transaction
				// after FOR UPDATE locks to close the TOCTOU window between the outer
				// grade-check and the actual kick.
				try {
				withTransaction($base, function() use ($base, $kickedLogin, $currentAlliance, $actorLogin, $allianceIdForKick) {
					// Re-read alliance row under lock to get the authoritative chef
					$allianceLocked = dbFetchOne($base, 'SELECT chef FROM alliances WHERE id=? FOR UPDATE', 'i', $allianceIdForKick);
					if (!$allianceLocked) {
						throw new \RuntimeException('ALLIANCE_GONE');
					}
					$isActorChef = ($allianceLocked['chef'] === $actorLogin);

					// Re-read actor's grade under lock
					$actorGradeRow = dbFetchOne($base, 'SELECT grade FROM grades WHERE login=? AND idalliance=? FOR UPDATE', 'si', $actorLogin, $allianceIdForKick);
					$actorHasBannirRight = false;
					if ($actorGradeRow) {
						$bits = explode('.', $actorGradeRow['grade'] ?? '');
						$actorHasBannirRight = (count($bits) === 5 && $bits[3] === '1');
					}
					if (!$isActorChef && !$actorHasBannirRight) {
						throw new \RuntimeException('PERMISSION_DENIED');
					}

					// Re-verify target's grade: only the chef can kick another officer
					$targetGradeRow = dbFetchOne($base, 'SELECT id FROM grades WHERE login=? AND idalliance=? FOR UPDATE', 'si', $kickedLogin, $allianceIdForKick);
					if ($targetGradeRow && !$isActorChef) {
						throw new \RuntimeException('CANNOT_KICK_OFFICER');
					}

					// Re-verify target is still in the alliance and is not the chef
					$targetMembership = dbFetchOne($base, 'SELECT login FROM autre WHERE login=? AND idalliance=? FOR UPDATE', 'si', $kickedLogin, $allianceIdForKick);
					if (!$targetMembership) {
						throw new \RuntimeException('TARGET_NOT_IN_ALLIANCE');
					}
					if ($kickedLogin === $allianceLocked['chef']) {
						throw new \RuntimeException('CANNOT_KICK_CHEF');
					}

					dbExecute($base, 'UPDATE autre SET idalliance=0 WHERE login=?', 's', $kickedLogin);
					dbExecute($base, 'DELETE FROM grades WHERE idalliance=? AND login=?', 'is', $currentAlliance['idalliance'], $kickedLogin);
					// MED-027: Clean up pending invitations for the kicked player
					dbExecute($base, 'DELETE FROM invitations WHERE invite=?', 's', $kickedLogin);
					// Record kick timestamp for rejoin cooldown (column added by migration 0030)
					try {
						dbExecute($base, 'UPDATE autre SET alliance_left_at=UNIX_TIMESTAMP() WHERE login=?', 's', $kickedLogin);
					} catch (\Exception $e) {
						// L-004: Log the error — non-fatal (ban still succeeded) but worth tracking
						logError('ALLIANCE', 'Failed to set alliance_left_at on ban', ['error' => $e->getMessage(), 'login' => $kickedLogin]);
					}
				});
				$information = 'Vous avez banni ' . htmlspecialchars($kickedLogin, ENT_QUOTES, 'UTF-8') . '.';
				} catch (\RuntimeException $e) {
					switch ($e->getMessage()) {
						case 'CANNOT_KICK_CHEF':
							$erreur = "Vous ne pouvez pas bannir le chef de l'alliance.";
							break;
						case 'CANNOT_KICK_OFFICER':
							$erreur = "Vous ne pouvez pas bannir un autre officier. Seul le chef peut le faire.";
							break;
						case 'TARGET_NOT_IN_ALLIANCE':
							$erreur = "Ce joueur n'est plus dans votre alliance.";
							break;
						case 'PERMISSION_DENIED':
							$erreur = "Vous n'avez pas la permission de bannir des membres.";
							break;
						default:
							$erreur = "Erreur lors du bannissement.";
					}
				}
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
		// ALL11-002: Prevent self-pact (defense-in-depth explicit check)
		if ($_POST['pacte'] === $chef['tag']) {
			$erreur = "Vous ne pouvez pas proposer un pacte à votre propre alliance.";
		} else {
		$existeAllianceRows = dbFetchAll($base, 'SELECT id FROM alliances WHERE tag=? AND id!=?', 'si', $_POST['pacte'], $currentAlliance['idalliance']);
		$existeAlliance = count($existeAllianceRows);
		if ($existeAlliance > 0) {
			$allianceAllie = dbFetchOne($base, 'SELECT * FROM alliances WHERE tag=?', 's', $_POST['pacte']);

			// M-017: Dead pre-check queries removed — authoritative duplicate check happens inside the
			// withTransaction block below using FOR UPDATE locks.

			// PASS1-MEDIUM-013: Atomic duplicate check + insert via transaction + FOR UPDATE lock
			try {
				$pactResult = withTransaction($base, function() use ($base, $chef, $allianceAllie) {
					// ALLIANCE-TX2: Re-verify actor's pacte grade permission inside transaction (TOCTOU guard)
					$allianceLocked = dbFetchOne($base, 'SELECT chef FROM alliances WHERE id=? FOR UPDATE', 'i', $chef['id']);
					if (!$allianceLocked) { throw new \RuntimeException('ALLIANCE_NOT_FOUND'); }
					$isChef = ($allianceLocked['chef'] === $_SESSION['login']);
					if (!$isChef) {
						$actorGradeRow = dbFetchOne($base, 'SELECT grade FROM grades WHERE login=? AND idalliance=? FOR UPDATE', 'si', $_SESSION['login'], $chef['id']);
						$bits = explode('.', $actorGradeRow['grade'] ?? '');
						if (count($bits) !== 5 || $bits[2] !== '1') {
							throw new \RuntimeException('PERMISSION_DENIED');
						}
					}
					// ALLIANCE-M2: Single atomic duplicate check (both directions) with FOR UPDATE
					$dupCount = dbCount($base,
						'SELECT COUNT(*) FROM declarations WHERE fin=0 AND ((alliance1=? AND alliance2=?) OR (alliance2=? AND alliance1=?)) FOR UPDATE',
						'iiii', $allianceAllie['id'], $chef['id'], $allianceAllie['id'], $chef['id']);
					if ($dupCount > 0) {
						throw new \RuntimeException('DUPLICATE');
					}
					$now = time();
					dbExecute($base, 'INSERT INTO declarations (type, alliance1, alliance2, timestamp) VALUES (1, ?, ?, ?)', 'iii', $chef['id'], $allianceAllie['id'], $now);
					$idDeclaration = dbFetchOne($base, 'SELECT id FROM declarations WHERE type=1 AND valide=0 AND alliance1=? AND alliance2=?', 'ii', $chef['id'], $allianceAllie['id']);
					$safeTag = htmlspecialchars($chef['tag'], ENT_QUOTES, 'UTF-8');
					$rapportTitre = 'L\'alliance ' . $safeTag . ' vous propose un pacte.';
					$rapportContenu = 'L\'alliance <a href="alliance.php?id=' . urlencode($chef['tag']) . '">' . $safeTag . '</a> vous propose un pacte. [PACT_ID:' . $idDeclaration['id'] . ']';
					dbExecute($base, 'INSERT INTO rapports (timestamp, titre, contenu, destinataire, statut, image, type) VALUES (?, ?, ?, ?, 0, \'\', \'alliance\')', 'isss', $now, $rapportTitre, $rapportContenu, $allianceAllie['chef']);
					return true;
				});
				$information = "Vous avez proposé un pacte à l'alliance " . htmlspecialchars($_POST['pacte'], ENT_QUOTES, 'UTF-8') . ".";
			} catch (\RuntimeException $e) {
				if ($e->getMessage() === 'PERMISSION_DENIED') {
					$erreur = "Vous n'avez pas la permission d'effectuer cette action.";
				} else {
					$erreur = "Soit vous êtes déjà allié avec cette équipe, soit vous êtes en guerre avec elle.";
				}
			}
		} else {
			$erreur = "Cette équipe n'existe pas.";
		}
		} // end self-pact guard
	}

	if (isset($_POST['allie']) and !empty($_POST['allie'])) {
		csrfCheck();
		$_POST['allie'] = intval($_POST['allie']);

		// NEW-TX-001: Wrap pact-break DELETE+INSERT-report in a transaction for atomicity
		$pacteRompu = false;
		try {
			withTransaction($base, function() use ($base, $chef, &$pacteRompu, &$information, &$erreur) {
				// ALLIANCE-TX2: Re-verify actor's pacte grade permission inside transaction (TOCTOU guard)
				$allianceLocked = dbFetchOne($base, 'SELECT chef FROM alliances WHERE id=? FOR UPDATE', 'i', $chef['id']);
				if (!$allianceLocked) { throw new \RuntimeException('ALLIANCE_NOT_FOUND'); }
				$isChef = ($allianceLocked['chef'] === $_SESSION['login']);
				if (!$isChef) {
					$actorGradeRow = dbFetchOne($base, 'SELECT grade FROM grades WHERE login=? AND idalliance=? FOR UPDATE', 'si', $_SESSION['login'], $chef['id']);
					$bits = explode('.', $actorGradeRow['grade'] ?? '');
					if (count($bits) !== 5 || $bits[2] !== '1') {
						throw new \RuntimeException('PERMISSION_DENIED');
					}
				}
				$allieId = intval($_POST['allie']);
				// Lock rows to prevent concurrent double-break
				$pacteExiste = dbCount($base, 'SELECT count(*) AS pacteExiste FROM declarations WHERE ((alliance1=? AND alliance2=?) OR (alliance2=? AND alliance1=?)) AND type=1 AND valide!=0 FOR UPDATE', 'iiii', $chef['id'], $allieId, $chef['id'], $allieId);
				if ($pacteExiste > 0) {
					$allianceAdverse = dbFetchOne($base, 'SELECT * FROM alliances WHERE id=?', 'i', $allieId);
					dbExecute($base, 'DELETE FROM declarations WHERE ((alliance1=? AND alliance2=?) OR (alliance2=? AND alliance1=?)) AND type=1', 'iiii', $chef['id'], $allianceAdverse['id'], $chef['id'], $allianceAdverse['id']);
					$now = time();
					$safePacteTag = htmlspecialchars($chef['tag'], ENT_QUOTES, 'UTF-8');
					$rapportTitre = 'L\'alliance ' . $safePacteTag . ' met fin au pacte qui vous alliait.';
					$rapportContenu = 'L\'alliance <a href="alliance.php?id=' . urlencode($chef['tag']) . '">' . $safePacteTag . '</a> met fin au pacte qui vous alliait.';
					dbExecute($base, 'INSERT INTO rapports (timestamp, titre, contenu, destinataire, statut, image, type) VALUES (?, ?, ?, ?, 0, \'\', \'alliance\')', 'isss', $now, $rapportTitre, $rapportContenu, $allianceAdverse['chef']);
					$information = "Le pacte avec " . htmlspecialchars($allianceAdverse['tag'], ENT_QUOTES, 'UTF-8') . " est bien rompu.";
					$pacteRompu = true;
				} else {
					throw new \RuntimeException('NOT_FOUND');
				}
			});
		} catch (\RuntimeException $e) {
			if ($e->getMessage() === 'PERMISSION_DENIED') {
				$erreur = "Vous n'avez pas la permission d'effectuer cette action.";
			} elseif ($e->getMessage() === 'NOT_FOUND') {
				$erreur = "Ce pacte n'existe pas.";
			}
		}
	}
}

if ($guerre) {
	if (isset($_POST['guerre'])) {
		csrfCheck();
		$_POST['guerre'] = trim($_POST['guerre']);
		// ALL11-001: Prevent self-war (defense-in-depth explicit check)
		if ($_POST['guerre'] === $chef['tag']) {
			$erreur = "Vous ne pouvez pas déclarer la guerre à votre propre alliance.";
		} else {
		$existeAllianceRows = dbFetchAll($base, 'SELECT id FROM alliances WHERE tag=? AND id!=?', 'si', $_POST['guerre'], $currentAlliance['idalliance']);
		$existeAlliance = count($existeAllianceRows);
		if ($existeAlliance > 0) {
			$allianceAdverse = dbFetchOne($base, 'SELECT * FROM alliances WHERE tag=?', 's', $_POST['guerre']);
			$allianceAdverseId = $allianceAdverse['id'];
			$allianceAdverseChef = $allianceAdverse['chef'];

			// Ghost-alliance guard: ensure the target alliance's chef exists and is not banned.
			$chefAdverse = dbFetchOne($base, 'SELECT id FROM membre WHERE login=? AND estExclu=0', 's', $allianceAdverseChef);
			if (!$chefAdverse) {
				$erreur = "Cette alliance n'est plus active (chef absent ou banni).";
			} else {

			$chefId = $chef['id'];
			$chefTag = $chef['tag'];

			// PASS1-LOW-022: Duplicate check + INSERT in a single transaction with FOR UPDATE to prevent race conditions
			try {
				withTransaction($base, function() use ($base, $allianceAdverseId, $allianceAdverseChef, $chefId, $chefTag, $chef) {
					// ALLIANCE-TX1: Re-verify actor's guerre grade permission inside transaction (TOCTOU guard)
					$allianceLocked = dbFetchOne($base, 'SELECT chef FROM alliances WHERE id=? FOR UPDATE', 'i', $chef['id']);
					if (!$allianceLocked) { throw new \RuntimeException('ALLIANCE_NOT_FOUND'); }
					$isChef = ($allianceLocked['chef'] === $_SESSION['login']);
					if (!$isChef) {
						$actorGradeRow = dbFetchOne($base, 'SELECT grade FROM grades WHERE login=? AND idalliance=? FOR UPDATE', 'si', $_SESSION['login'], $chef['id']);
						$bits = explode('.', $actorGradeRow['grade'] ?? '');
						if (count($bits) !== 5 || $bits[1] !== '1') {
							throw new \RuntimeException('PERMISSION_DENIED');
						}
					}
					// MIN-MEMBERS: Require at least 2 members in the declaring alliance to prevent solo-player wars.
					$memberCount = dbCount($base, 'SELECT COUNT(*) FROM autre WHERE idalliance = ?', 'i', $chef['id']);
					if ($memberCount < 2) {
						throw new \RuntimeException('NOT_ENOUGH_MEMBERS');
					}
					// ALLIANCE-M2: Single atomic duplicate check (both directions) with FOR UPDATE
					$dupCount = dbCount($base,
						'SELECT COUNT(*) FROM declarations WHERE ((fin=0 AND type=0) OR (type=1 AND valide!=0)) AND ((alliance1=? AND alliance2=?) OR (alliance2=? AND alliance1=?)) FOR UPDATE',
						'iiii', $allianceAdverseId, $chefId, $allianceAdverseId, $chefId);
					if ($dupCount > 0) {
						throw new \RuntimeException('DUPLICATE');
					}
					// FLOW-ALLIANCE MEDIUM-001: Before deleting pending pact proposals, notify
					// the proposing alliance chef that their pact was cancelled by a war declaration.
					$now = time();
					$safeWarNotifTag = htmlspecialchars($chefTag, ENT_QUOTES, 'UTF-8');
					// Check for a pending pact proposed BY the adverse alliance TO us (adverse is alliance1)
					$pendingPact1 = dbFetchOne($base, 'SELECT d.id, a.chef AS proposerChef FROM declarations d JOIN alliances a ON a.id = d.alliance1 WHERE d.alliance1=? AND d.alliance2=? AND d.fin=0 AND d.valide=0 AND d.type=1', 'ii', $allianceAdverseId, $chefId);
					if ($pendingPact1) {
						dbExecute($base, 'INSERT INTO rapports (timestamp, titre, contenu, destinataire, statut, image, type) VALUES (?, ?, ?, ?, 0, \'\', \'alliance\')',
							'isss', $now,
							'Votre proposition de pacte a été annulée.',
							'Votre proposition de pacte à l\'alliance <a href="alliance.php?id=' . urlencode($chefTag) . '">' . $safeWarNotifTag . '</a> a été annulée suite à une déclaration de guerre.',
							$pendingPact1['proposerChef']);
					}
					// Check for a pending pact proposed BY us TO the adverse alliance (we are alliance1)
					$pendingPact2 = dbFetchOne($base, 'SELECT d.id, a.chef AS proposerChef FROM declarations d JOIN alliances a ON a.id = d.alliance1 WHERE d.alliance1=? AND d.alliance2=? AND d.fin=0 AND d.valide=0 AND d.type=1', 'ii', $chefId, $allianceAdverseId);
					if ($pendingPact2) {
						dbExecute($base, 'INSERT INTO rapports (timestamp, titre, contenu, destinataire, statut, image, type) VALUES (?, ?, ?, ?, 0, \'\', \'alliance\')',
							'isss', $now,
							'Votre proposition de pacte a été annulée.',
							'Votre proposition de pacte à l\'alliance <a href="alliance.php?id=' . urlencode($chefTag) . '">' . $safeWarNotifTag . '</a> a été annulée suite à une déclaration de guerre.',
							$pendingPact2['proposerChef']);
					}
					dbExecute($base, 'DELETE FROM declarations WHERE alliance1=? AND alliance2=? AND fin=0 AND valide=0', 'ii', $allianceAdverseId, $chefId);
					dbExecute($base, 'DELETE FROM declarations WHERE alliance2=? AND alliance1=? AND fin=0 AND valide=0', 'ii', $allianceAdverseId, $chefId);
					dbExecute($base, 'INSERT INTO declarations (type, alliance1, alliance2, timestamp) VALUES (0, ?, ?, ?)', 'iii', $chefId, $allianceAdverseId, $now);
					$safeChefTag = htmlspecialchars($chefTag, ENT_QUOTES, 'UTF-8');
					$rapportTitre = 'L\'alliance ' . $safeChefTag . ' vous déclare la guerre.';
					$rapportContenu = 'L\'alliance <a href="alliance.php?id=' . urlencode($chefTag) . '">' . $safeChefTag . '</a> vous déclare la guerre.';
					dbExecute($base, 'INSERT INTO rapports (timestamp, titre, contenu, destinataire, statut, image, type) VALUES (?, ?, ?, ?, 0, \'\', \'alliance\')', 'isss', $now, $rapportTitre, $rapportContenu, $allianceAdverseChef);
				});
				$information = "Vous avez déclaré la guerre à l'équipe " . htmlspecialchars($_POST['guerre'], ENT_QUOTES, 'UTF-8') . ".";
			} catch (\RuntimeException $e) {
				if ($e->getMessage() === 'PERMISSION_DENIED') {
					$erreur = "Vous n'avez pas la permission d'effectuer cette action.";
				} elseif ($e->getMessage() === 'NOT_ENOUGH_MEMBERS') {
					$erreur = "Votre alliance doit avoir au moins 2 membres pour déclarer la guerre.";
				} else {
					$erreur = "Soit une guerre est déjà déclarée contre cette équipe, soit vous êtes alliés avec elle.";
				}
			}
			} // end ghost-alliance guard
		} else {
			$erreur = "Cette équipe n'existe pas.";
		}
		} // end self-war guard
	}

	if (isset($_POST['adversaire']) and !empty($_POST['adversaire'])) {
		csrfCheck();
		$_POST['adversaire'] = intval($_POST['adversaire']);

		// H-017/L-008: Move existence check INSIDE the transaction with FOR UPDATE to prevent
		// TOCTOU race between the check and the subsequent war-end UPDATE.
		// ALLIANCE_MGMT MEDIUM-001: Either the attacking OR defending alliance can end the war.
		try {
			withTransaction($base, function() use ($base, $chef, &$information) {
				// ALLIANCE-TX1: Re-verify actor's guerre grade permission inside transaction (TOCTOU guard)
				$allianceLocked = dbFetchOne($base, 'SELECT chef FROM alliances WHERE id=? FOR UPDATE', 'i', $chef['id']);
				if (!$allianceLocked) { throw new \RuntimeException('ALLIANCE_NOT_FOUND'); }
				$isChef = ($allianceLocked['chef'] === $_SESSION['login']);
				if (!$isChef) {
					$actorGradeRow = dbFetchOne($base, 'SELECT grade FROM grades WHERE login=? AND idalliance=? FOR UPDATE', 'si', $_SESSION['login'], $chef['id']);
					$bits = explode('.', $actorGradeRow['grade'] ?? '');
					if (count($bits) !== 5 || $bits[1] !== '1') {
						throw new \RuntimeException('PERMISSION_DENIED');
					}
				}
				$adversaireId = intval($_POST['adversaire']);
				// Lock the war row — current alliance may be alliance1 (attacker) OR alliance2 (defender)
				$guerreExisteTx = dbFetchOne($base,
					'SELECT id, alliance1, alliance2, pertes1, pertes2 FROM declarations
					 WHERE ((alliance1=? AND alliance2=?) OR (alliance1=? AND alliance2=?))
					 AND type=0 AND fin=0 FOR UPDATE',
					'iiii', $chef['id'], $adversaireId, $adversaireId, $chef['id']);
				if (!$guerreExisteTx) {
					throw new \RuntimeException('WAR_NOT_FOUND');
				}
				// Determine which side is which regardless of who declared
				$alliance1Id = (int)$guerreExisteTx['alliance1'];
				$alliance2Id = (int)$guerreExisteTx['alliance2'];
				$pertes1     = (int)$guerreExisteTx['pertes1'];
				$pertes2     = (int)$guerreExisteTx['pertes2'];

				$allianceAdverse = dbFetchOne($base, 'SELECT * FROM alliances WHERE id=? FOR UPDATE', 'i', $adversaireId);
				$now = time();
				// ALL-P7-002: Compute winner from molecule losses; award pointsVictoire
				$winner = 0; // draw by default
				if ($pertes1 < $pertes2) {
					$winner = 1; // alliance1 wins (fewer losses)
					dbExecute($base, 'UPDATE alliances SET pointsVictoire = pointsVictoire + 1 WHERE id=?', 'i', $alliance1Id);
				} elseif ($pertes1 > $pertes2) {
					$winner = 2; // alliance2 wins
					dbExecute($base, 'UPDATE alliances SET pointsVictoire = pointsVictoire + 1 WHERE id=?', 'i', $alliance2Id);
				}
				dbExecute($base, 'UPDATE declarations SET fin=?, winner=? WHERE id=? AND fin=0 AND type=0', 'iii', $now, $winner, (int)$guerreExisteTx['id']);
				$safeWarTag = htmlspecialchars($chef['tag'], ENT_QUOTES, 'UTF-8');
				$rapportTitre = 'L\'alliance ' . $safeWarTag . ' met fin à la guerre qui vous opposait.';
				$rapportContenu = 'L\'alliance <a href="alliance.php?id=' . urlencode($chef['tag']) . '">' . $safeWarTag . '</a> met fin à la guerre qui vous opposait.';
				dbExecute($base, 'INSERT INTO rapports (timestamp, titre, contenu, destinataire, statut, image, type) VALUES (?, ?, ?, ?, 0, \'\', \'alliance\')', 'isss', $now, $rapportTitre, $rapportContenu, $allianceAdverse['chef']);
				$information = "La guerre contre " . htmlspecialchars($allianceAdverse['tag'], ENT_QUOTES, 'UTF-8') . " a pris fin.";
			});
		} catch (\RuntimeException $e) {
			if ($e->getMessage() === 'PERMISSION_DENIED') {
				$erreur = "Vous n'avez pas la permission d'effectuer cette action.";
			} else {
				$erreur = "Cette guerre n'existe pas ou vous n'avez pas le droit de la terminer.";
			}
		}
	}
}

if ($inviter) {
	if (isset($_POST['inviterpersonne'])) {
		csrfCheck();
		if (!empty($_POST['inviterpersonne'])) {
			$_POST['inviterpersonne'] = ucfirst(trim($_POST['inviterpersonne']));
			$personneAInviter = $_POST['inviterpersonne'];
			$allianceId = $currentAlliance['idalliance'];
			$chefTag = $chef['tag'];

			// ALLIANCE-P10-001/002: Wrap all invite checks + INSERT in a transaction
			// to prevent duplicate invites and TOCTOU on member count / target alliance status.
			// INSERT IGNORE relies on the UNIQUE KEY uk_invite_alliance (migration 0098).
			try {
				$inviteResult = withTransaction($base, function() use ($base, $personneAInviter, $allianceId, $chefTag, $joueursEquipe) {
					// Re-check member count under lock
					$memberCount = dbCount($base, 'SELECT COUNT(*) FROM autre WHERE idalliance=? FOR UPDATE', 'i', $allianceId);
					if ($memberCount >= $joueursEquipe) {
						throw new \RuntimeException('alliance_full');
					}

					// Verify target player exists and is not game-banned (ALLIANCE13-002)
					$joueurExiste = dbFetchOne($base, 'SELECT m.estExclu FROM membre m JOIN autre a ON a.login=m.login WHERE a.login=?', 's', $personneAInviter);
					if (!$joueurExiste) {
						throw new \RuntimeException('player_not_found');
					}
					if ((int)$joueurExiste['estExclu'] === 1) {
						throw new \RuntimeException('player_banned');
					}

					// Re-check: target must not already be in an alliance (FOR UPDATE locks the row)
					$targetCheck = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login=? FOR UPDATE', 's', $personneAInviter);
					if (!$targetCheck || (int)$targetCheck['idalliance'] !== 0) {
						throw new \RuntimeException('already_in_alliance');
					}

					// Re-check: no pending invite already exists (FOR UPDATE locks the rows)
					$invitationDejaEnvoye = dbCount($base, 'SELECT COUNT(*) FROM invitations WHERE invite=? AND idalliance=? FOR UPDATE', 'si', $personneAInviter, $allianceId);
					if ($invitationDejaEnvoye > 0) {
						throw new \RuntimeException('invite_already_sent');
					}

					// INSERT IGNORE: UNIQUE constraint (uk_invite_alliance) prevents duplicates even under concurrent load
					dbExecute($base, 'INSERT IGNORE INTO invitations VALUES (default, ?, ?, ?)', 'iss', $allianceId, $chefTag, $personneAInviter);
					return true;
				});
				$information = 'Vous avez invité ' . htmlspecialchars($personneAInviter, ENT_QUOTES, 'UTF-8') . '';
			} catch (\RuntimeException $e) {
				switch ($e->getMessage()) {
					case 'alliance_full':
						$erreur = "Le nombre maximal de joueurs est atteint dans l'équipe";
						break;
					case 'player_not_found':
						$erreur = "Ce joueur n'existe pas.";
						break;
					case 'player_banned':
						$erreur = "Ce joueur n'est pas disponible.";
						break;
					case 'already_in_alliance':
						$erreur = "Ce joueur est déjà dans une alliance.";
						break;
					case 'invite_already_sent':
						$erreur = "Vous avez déjà envoyé une invitation à ce joueur.";
						break;
					default:
						$erreur = "Une erreur est survenue lors de l'invitation.";
				}
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
	// MEDIUM-015: maxlength attribute enforces ALLIANCE_DESC_MAX_LENGTH client-side
	item(['form' => ["allianceadmin.php", "description"], 'floating' => false, 'titre' => "Description", 'input' => '<textarea name="changerdescription" id="changerdescription" rows="10" cols="50" maxlength="' . ALLIANCE_DESC_MAX_LENGTH . '">' . htmlspecialchars($chef['description'], ENT_QUOTES, 'UTF-8') . '</textarea>' . csrfField(), 'after' => submit(['titre' => 'Changer', 'form' => 'description'])]);
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
		// ALLIANCE_MGMT MEDIUM-001: Defending alliance can also end the war
		echo '<tr>
                            <td><a href="alliance.php?id=' . urlencode($tagAlliance['tag']) . '">' . $safeWarTag . '</a> <em style="color:#999;font-size:0.85em">(déclarée par eux)</em></td>
                            <td>' . date('d/m/Y à H\hi', $guerre['timestamp']) . '</td>
                            <td>' . $guerre['pertes2'] . '</td>
                            <td><form action="allianceadmin.php" method="post">' . csrfField() . '
                            <input type="hidden" name="adversaire" value="' . $guerre['alliance1'] . '"/>
                            <input src="images/croix.png" alt="stop" type="image" name="stopguerre" title="Mettre fin à la guerre"></form></td>
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