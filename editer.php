<?php
include("includes/basicprivatephp.php");
include("includes/bbcode.php");
require_once("includes/rate_limiter.php");

// Validate and sanitize ID early — prefer POST body (hidden fields) over GET to prevent
// action="" XSS routing bypass (MED-033). GET params are still accepted for link-based
// navigation to the edit form (type=1/2), but POST params take precedence.
$id = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
$type = isset($_POST['type']) ? (int)$_POST['type'] : (isset($_GET['type']) ? (int)$_GET['type'] : 0);

// On regarde si l'utilisateur est un modérateur
$moderateur = dbFetchOne($base, 'SELECT moderateur FROM membre WHERE login = ?', 's', $_SESSION['login']);
// MEDIUM-009: Banned moderator cannot edit posts — check active forum ban
$activeBan = dbFetchOne($base, 'SELECT id FROM sanctions WHERE joueur = ? AND dateFin >= CURDATE()', 's', $_SESSION['login']);
if ($activeBan) {
    // User is currently forum-banned; strip moderator privileges for this request
    $moderateur = ['moderateur' => '0'];
}

// On recherche le sujet que l'on souhaite éditer
$sujet = dbFetchOne($base, 'SELECT idsujet FROM reponses WHERE id = ?', 'i', $id);

/**
 * MEDIUM-017: Helper — check alliance-private forum access for a reply row.
 * Returns true if the current user may act on this reply, false otherwise.
 * Non-moderators are always allowed (they are gated by authorship separately).
 * Moderators are denied if the reply belongs to an alliance-private forum they
 * are not a member of — site-wide moderation does not grant cross-alliance access.
 */
function checkAllianceForumAccess($base, $replyId, $moderatorLogin) {
    $replyTopicRow = dbFetchOne($base, 'SELECT s.idforum FROM reponses r JOIN sujets s ON s.id = r.idsujet WHERE r.id = ?', 'i', $replyId);
    if (!$replyTopicRow) return true; // row not found — let caller handle
    try {
        $forumMeta = dbFetchOne($base, 'SELECT alliance_id FROM forums WHERE id = ?', 'i', $replyTopicRow['idforum']);
        if ($forumMeta && !empty($forumMeta['alliance_id'])) {
            $modAllianceRow = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login = ?', 's', $moderatorLogin);
            $modAllianceId = $modAllianceRow ? (int)$modAllianceRow['idalliance'] : 0;
            return $modAllianceId === (int)$forumMeta['alliance_id'];
        }
    } catch (\Exception $e) {
        // alliance_id column not yet present — all forums public
    }
    return true;
}

// Suppression - require POST with CSRF
if ($type == 3 AND $id > 0 AND $_SERVER['REQUEST_METHOD'] === 'POST') {
	csrfCheck();
	// Use pre-fetched $moderateur (ban-aware) instead of fresh DB query to prevent banned-mod bypass
	$isModo = ($moderateur && $moderateur['moderateur'] != '0') ? 1 : 0;
	// MEDIUM-017: Block moderators from deleting replies in alliance-private forums they don't belong to.
	if ($isModo && !checkAllianceForumAccess($base, $id, $_SESSION['login'])) {
		$erreur = "Vous n'avez pas accès à ce forum privé d'alliance.";
	} else {
		// M-013: Wrap fetch+delete in a transaction with FOR UPDATE to close the TOCTOU window
		// between reading the author and performing the DELETE.
		$deleteAllowed = false;
		$deletedAuthor = null;
		$deleteSubjectId = $sujet ? (int)$sujet['idsujet'] : 0;
		try {
			withTransaction($base, function() use ($base, $id, $isModo, &$deleteAllowed, &$deletedAuthor) {
				$auteur = dbFetchOne($base, 'SELECT auteur FROM reponses WHERE id = ? FOR UPDATE', 'i', $id);
				if (!$auteur) {
					throw new \RuntimeException('NOT_FOUND');
				}
				if ($auteur['auteur'] !== $_SESSION['login'] && $isModo < 1) {
					throw new \RuntimeException('NOT_AUTHORIZED');
				}
				$deletedAuthor = $auteur['auteur'];
				dbExecute($base, 'DELETE FROM reponses WHERE id = ?', 'i', $id);
				dbExecute($base, 'UPDATE autre SET nbMessages = nbMessages - 1 WHERE login = ? AND nbMessages > 0', 's', $auteur['auteur']);
				$deleteAllowed = true;
			});
		} catch (\RuntimeException $e) {
			if ($e->getMessage() === 'NOT_AUTHORIZED') {
				$erreur = "Vous ne pouvez pas supprimer une réponse dont vous n'êtes pas l'auteur.";
			} elseif ($e->getMessage() !== 'NOT_FOUND') {
				$erreur = "Une erreur est survenue lors de la suppression.";
			}
		}
		if ($deleteAllowed) {
			header("Location: sujet.php?id=" . $deleteSubjectId); exit;
		}
	}
}

// Si on souhaite masquer un message - moderator only (P5-GAP-014)
if ($type == 5 AND $id > 0 AND $_SERVER['REQUEST_METHOD'] === 'POST') {
	csrfCheck();
	// MEDIUM-017: Alliance-private forum access check for hide action.
	if ($moderateur && $moderateur['moderateur'] != '0') {
		if (!checkAllianceForumAccess($base, $id, $_SESSION['login'])) {
			$erreur = "Vous n'avez pas accès à ce forum privé d'alliance.";
		} else {
			dbExecute($base, 'UPDATE reponses SET visibilite = 0 WHERE id = ?', 'i', $id);
			$sujetId = $sujet ? (int)$sujet['idsujet'] : 0;
			header("Location: sujet.php?id=" . (int)$sujetId); exit;
		}
	}
}
// Si on souhaite afficher un message - moderator only (P5-GAP-014)
if ($type == 4 AND $id > 0 AND $_SERVER['REQUEST_METHOD'] === 'POST') {
	csrfCheck();
	// MEDIUM-017: Alliance-private forum access check for show action.
	if ($moderateur && $moderateur['moderateur'] != '0') {
		if (!checkAllianceForumAccess($base, $id, $_SESSION['login'])) {
			$erreur = "Vous n'avez pas accès à ce forum privé d'alliance.";
		} else {
			dbExecute($base, 'UPDATE reponses SET visibilite = 1 WHERE id = ?', 'i', $id);
			$sujetId = $sujet ? (int)$sujet['idsujet'] : 0;
			header("Location: sujet.php?id=" . (int)$sujetId); exit;
		}
	}
}

if (isset($_POST['contenu']) AND !empty(trim($_POST['contenu'])) AND $id > 0 AND $type > 0) {
	csrfCheck();
	// FORUM-MED-001: Correct arg order (identifier=login first, action second) + check return value.
	if (!rateLimitCheck($_SESSION['login'], 'forum_edit', 10, 300)) {
		$erreur = "Trop de modifications. Veuillez patienter avant de modifier à nouveau.";
	}
	$contenu = $_POST['contenu'];
	if (isset($_POST['titre']) AND !empty(trim($_POST['titre']))) { // alors c'est un sujet
		$titre = $_POST['titre'];
		if ($type == 1) {
			if (mb_strlen($contenu) > FORUM_POST_MAX_LENGTH) {
				$erreur = "Le contenu est trop long (" . FORUM_POST_MAX_LENGTH . " caractères max).";
			} elseif (mb_strlen($titre) > FORUM_TITLE_MAX_LENGTH) {
				$erreur = "Le titre est trop long (" . FORUM_TITLE_MAX_LENGTH . " caractères max).";
			}
		}
		$auteur = dbFetchOne($base, 'SELECT auteur FROM sujets WHERE id = ?', 'i', $id);
		if ($type == 1) {
			if (!empty($erreur)) {
				// fall through to display form with error
			} elseif ($auteur && $auteur['auteur'] == $_SESSION['login']) {
				dbExecute($base, 'UPDATE sujets SET contenu = ?, titre = ? WHERE id = ?', 'ssi', $contenu, $titre, $id);
				$information = "Le sujet a bien été modifié";
				dbExecute($base, 'DELETE FROM statutforum WHERE idsujet = ?', 'i', $id);
				header("Location: sujet.php?id=" . (int)$id); exit;
			} elseif ($moderateur && $moderateur['moderateur'] != '0') {
				// MEDIUM-016: Moderator path for type=1 (topic title/content edit).
				// Mirrors type=2 moderator logic: alliance-private access check, audit log, then update.
				$topicForumRow = dbFetchOne($base, 'SELECT idforum FROM sujets WHERE id = ?', 'i', $id);
				if ($topicForumRow) {
					try {
						$forumMeta = dbFetchOne($base, 'SELECT alliance_id FROM forums WHERE id = ?', 'i', $topicForumRow['idforum']);
						if ($forumMeta && !empty($forumMeta['alliance_id'])) {
							$modAllianceRow = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login = ?', 's', $_SESSION['login']);
							$modAllianceId = $modAllianceRow ? (int)$modAllianceRow['idalliance'] : 0;
							if ($modAllianceId !== (int)$forumMeta['alliance_id']) {
								$erreur = "Vous n'avez pas accès à ce forum privé d'alliance.";
							}
						}
					} catch (\Exception $e) {
						// alliance_id column not yet present — all forums public
					}
				}
				if (empty($erreur)) {
					$originalRow = dbFetchOne($base, 'SELECT titre, contenu FROM sujets WHERE id = ?', 'i', $id);
					$originalContent = $originalRow ? $originalRow['titre'] . "\n" . $originalRow['contenu'] : '';
					dbExecute($base,
						'INSERT INTO moderation_log (moderator_login, target_post_id, post_type, original_content, new_content, action_at) VALUES (?, ?, ?, ?, ?, ?)',
						'sisssi', $_SESSION['login'], $id, 'sujet', $originalContent, $titre . "\n" . $contenu, time()
					);
					dbExecute($base, 'UPDATE sujets SET contenu = ?, titre = ? WHERE id = ?', 'ssi', $contenu, $titre, $id);
					$information = "Le sujet a bien été modifié";
					dbExecute($base, 'DELETE FROM statutforum WHERE idsujet = ?', 'i', $id);
					header("Location: sujet.php?id=" . (int)$id); exit;
				}
			} else {
				$erreur = "Vous ne pouvez modifier un sujet dont vous n'êtes pas l'auteur";
			}
		}
	}
	if ($type == 2) {
		if (mb_strlen($contenu) > FORUM_POST_MAX_LENGTH) {
			$erreur = "Le contenu est trop long (" . FORUM_POST_MAX_LENGTH . " caractères max).";
		}
		// Rajout de Yojim
		if (empty($erreur) && $moderateur['moderateur'] == '0') {
			$auteur = dbFetchOne($base, 'SELECT auteur FROM reponses WHERE id = ?', 'i', $id);
			if ($auteur && $auteur['auteur'] == $_SESSION['login']) {
				dbExecute($base, 'UPDATE reponses SET contenu = ? WHERE auteur = ? AND id = ?', 'ssi', $contenu, $_SESSION['login'], $id);
				$information = "La réponse a bien été modifiée";
				$reponse = dbFetchOne($base, 'SELECT * FROM reponses WHERE id = ?', 'i', $id);
				if ($reponse) {
					dbExecute($base, 'DELETE FROM statutforum WHERE idsujet = ?', 'i', $reponse['idsujet']);
				}

                $sujetRow = dbFetchOne($base, 'SELECT idsujet FROM reponses WHERE id = ?', 'i', $id);
                if ($sujetRow) {
                    header("Location: sujet.php?id=" . (int)$sujetRow['idsujet']); exit;
                }
			} else {
				$erreur = "Vous ne pouvez pas modifier une réponse donc vous n'êtes pas l'auteur";
			}
		} elseif (empty($erreur)) {
			// P9-MED-007: Check alliance-private forum access for moderator edits.
			// Fetch the forum for this reply's topic and verify the moderator is a member of the
			// required alliance (if any). Site-wide moderation does not grant access to
			// alliance-private forums that the moderator is not a member of.
			$replyTopicRow = dbFetchOne($base, 'SELECT s.idforum FROM reponses r JOIN sujets s ON s.id = r.idsujet WHERE r.id = ?', 'i', $id);
			if ($replyTopicRow) {
				try {
					$forumMeta = dbFetchOne($base, 'SELECT alliance_id FROM forums WHERE id = ?', 'i', $replyTopicRow['idforum']);
					if ($forumMeta && !empty($forumMeta['alliance_id'])) {
						// Fetch moderator's own alliance from autre (not from $moderateur which only has the moderateur key)
						$modAllianceRow = dbFetchOne($base, 'SELECT idalliance FROM autre WHERE login = ?', 's', $_SESSION['login']);
						$modAllianceId = $modAllianceRow ? (int)$modAllianceRow['idalliance'] : 0;
						if ($modAllianceId !== (int)$forumMeta['alliance_id']) {
							$erreur = "Vous n'avez pas accès à ce forum privé d'alliance.";
						}
					}
				} catch (\Exception $e) {
					// alliance_id column not yet present — all forums public, skip silently
				}
			}
			if (empty($erreur)) {
				// Moderator edit: log the change before applying it (MED-034)
				$originalRow = dbFetchOne($base, 'SELECT contenu FROM reponses WHERE id = ?', 'i', $id);
				$originalContent = $originalRow ? $originalRow['contenu'] : '';
				dbExecute($base,
					'INSERT INTO moderation_log (moderator_login, target_post_id, post_type, original_content, new_content, action_at) VALUES (?, ?, ?, ?, ?, ?)',
					'sisssi', $_SESSION['login'], $id, 'reponse', $originalContent, $contenu, time()
				);
				dbExecute($base, 'UPDATE reponses SET contenu = ? WHERE id = ?', 'si', $contenu, $id);
				$information = "La réponse a bien été modifiée";
				$reponse = dbFetchOne($base, 'SELECT * FROM reponses WHERE id = ?', 'i', $id);
				if ($reponse) {
					dbExecute($base, 'DELETE FROM statutforum WHERE idsujet = ?', 'i', $reponse['idsujet']);
					header("Location: sujet.php?id=" . (int)$reponse['idsujet']); exit;
				}
			}
		}
	}
}

include("includes/layout.php");

debutCarte("Editer");

if ($id > 0 AND $type > 0) {
	// Modification du sujet ou d'un message
	$reponse = dbFetchOne($base, $type == 1 ? 'SELECT * FROM sujets WHERE id = ?' : 'SELECT * FROM reponses WHERE id = ?', 'i', $id);
	$nbReponses = $reponse ? 1 : 0;
	if ($type == 2) { // si c'est un message (reply) il n'y a pas de titre
		$reponse['titre'] = "";
	}

	// Authorization: only author or moderator can view the edit form
	if ($nbReponses == 1) {
		$auteurField = $type == 1 ? $reponse['auteur'] : $reponse['auteur'];
		if ($auteurField !== $_SESSION['login'] && (!$moderateur || $moderateur['moderateur'] == '0')) {
			echo 'Vous ne pouvez pas modifier un contenu dont vous n\'êtes pas l\'auteur.';
			finCarte();
			include("includes/copyright.php");
			exit;
		}
	}

	if ($nbReponses == 1) {
		debutListe();
        // FORUM-P9-004: use hardcoded action instead of REQUEST_URI
        echo '<form method="post" action="editer.php" name="formEditer">';
        echo csrfField();
        echo '<input type="hidden" name="id" value="' . (int)$id . '"/>';
        echo '<input type="hidden" name="type" value="' . (int)$type . '"/>';
		if ($type == 1) {
            item(['titre' => 'Titre', "floating" => true, 'input'=> '<input type="text" name="titre" id="titre" value="'.htmlspecialchars($reponse['titre'], ENT_QUOTES, 'UTF-8').'"/>']);
		}

        creerBBcode("contenu", $reponse['contenu']);
        item(['floating' => false, 'titre' => "Réponse", 'input' => '<textarea name="contenu" id="contenu" rows="10" cols="50">'.htmlspecialchars($reponse['contenu'], ENT_QUOTES, 'UTF-8').'</textarea>']);
        item(['input' => submit(['titre' => 'Editer', 'form'=>'formEditer'])]);
        echo '</form>';
		finListe();
	} else {
		if ($id != 3) {
			echo 'Ce sujet ou cette réponse n\'existe pas !';
		}
	}
} else {
	echo 'Stop jouer avec la barre URL espèce de troll !';
}

finCarte();
include("includes/copyright.php");
