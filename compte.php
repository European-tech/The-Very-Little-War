<?php
include("includes/basicprivatephp.php");
include("includes/bbcode.php");

// CSRF check for all POST requests on this page
csrfCheck();

// Re-verify account is not banned before processing any mutation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accountStatus = dbFetchOne($base, 'SELECT estExclu FROM membre WHERE login = ?', 's', $_SESSION['login']);
    if (!$accountStatus || $accountStatus['estExclu'] == 1) {
        header('Location: index.php'); exit;
    }
}

if (isset($_POST['verification']) and isset($_POST['oui'])) {
    // AUTH-HIGH-001: Re-check 7-day cooldown on POST path — not just in view rendering.
    $memberTimestamp = dbFetchOne($base, 'SELECT timestamp FROM membre WHERE login = ?', 's', $_SESSION['login']);
    if (!$memberTimestamp || (time() - (int)$memberTimestamp['timestamp']) <= SECONDS_PER_WEEK) {
        $erreur = "Le compte ne peut être supprimé qu'au bout d'une semaine.";
    } else {
        supprimerJoueur($_SESSION['login']);
        // Destroy session so the deleted account cannot continue browsing
        session_unset();
        session_destroy();
        // Clear session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        header("Location: index.php"); exit;
    }
}

if (isset($_POST['dateFin'])) { // Conversion de la date au format anglais
    $parts = explode('/', $_POST['dateFin']);
    if (count($parts) !== 3 || !checkdate((int)$parts[1], (int)$parts[0], (int)$parts[2])) {
        $erreur = "Format de date invalide (attendu : JJ/MM/AAAA).";
    } else {
    list($jour, $mois, $annee) = $parts;
    $dateT = new DateTime();
    $dateT->setDate((int)$annee, (int)$mois, (int)$jour);
    if ($dateT->getTimestamp() >= time() + VACATION_MIN_ADVANCE_SECONDS) {
        $date = sprintf('%04d-%02d-%02d', (int)$annee, (int)$mois, (int)$jour);
        // MEDIUM-007: Check for active combat before activating vacation mode
        $login = $_SESSION['login'];
        // MEDIUM-001: Active-combat check runs INSIDE the transaction, after the FOR UPDATE
        // lock on membre, so there is no TOCTOU window between the check and the INSERT.
        $vacationBlocked = false;
        withTransaction($base, function() use ($base, $login, $date, &$vacationBlocked) {
            $membreRow = dbFetchOne($base, 'SELECT id, vacance FROM membre WHERE login = ? FOR UPDATE', 's', $login);
            if (!$membreRow || $membreRow['vacance']) return; // already on vacation
            $membreId = (int)$membreRow['id'];
            // Re-check for active combat under the DB lock (TOCTOU fix).
            $activeCombat = dbCount($base, 'SELECT COUNT(*) AS nb FROM actionsattaques WHERE (defenseur=? OR attaquant=?) AND attaqueFaite=0 AND tempsAttaque > ?', 'ssi', $login, $login, time());
            if ($activeCombat > 0) {
                $vacationBlocked = true;
                return; // rollback will happen via transaction, but no writes were done
            }
            dbExecute($base, 'DELETE FROM actionsformation WHERE login = ?', 's', $login);
            dbExecute($base, 'INSERT INTO vacances VALUES (default, ?, CURRENT_DATE, ?)', 'is', $membreId, $date);
            dbExecute($base, 'UPDATE membre SET vacance = 1 WHERE id = ?', 'i', $membreId);
        });
        if ($vacationBlocked) {
            $erreur = "Vous ne pouvez pas activer le mode vacances pendant un combat en cours.";
        } else {
        header("Location: compte.php"); exit;
        } // end active combat check else
    } else {
        $erreur = "Vous ne pouvez pas vous mettre en vacances moins de trois jours.";
    }
    } // end date validation else
}



if (isset($_POST['changermdpactuel']) && isset($_POST['changermdp']) && isset($_POST['changermdp1'])) {
    // INFRA-SEC16-002: Rate limit password change to prevent brute-forcing current password.
    if (!rateLimitCheck($_SESSION['login'], 'password_change', 5, 300)) {
        $erreur = "Trop de tentatives. Réessayez dans quelques minutes.";
    } elseif (!empty($_POST['changermdpactuel']) && !empty($_POST['changermdp']) && !empty($_POST['changermdp1'])) {
        $currentPassword = $_POST['changermdpactuel'];
        $newPassword = $_POST['changermdp'];
        $newPasswordConfirm = $_POST['changermdp1'];

        // Fetch current hash from database
        $currentUser = dbFetchOne($base, 'SELECT pass_md5 FROM membre WHERE login = ?', 's', $_SESSION['login']);
        if (!$currentUser) {
            $erreur = "Erreur : utilisateur introuvable.";
        } else {
            $storedHash = $currentUser['pass_md5'];
            $verified = false;

            // Verify current password: try bcrypt first, then MD5 fallback
            if (password_verify($currentPassword, $storedHash)) {
                $verified = true;
            } elseif (hash_equals(md5($currentPassword), $storedHash)) {
                $verified = true;
            }

            if (!$verified) {
                $erreur = "Le mot de passe actuel est incorrect.";
            } else {
                // MEDIUM-027: Use shared validatePassword() from validation.php
                $passErrors = validatePassword($newPassword, $newPasswordConfirm);
                if (!empty($passErrors)) {
                    $erreur = htmlspecialchars($passErrors[0], ENT_QUOTES, 'UTF-8');
                }
            }
            if (empty($erreur) && $verified) {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                // SESSION-H-001: Invalidate ALL existing sessions for this user first,
                // then set a new token — this logs out any other active sessions/devices.
                dbExecute($base, 'UPDATE membre SET session_token = NULL WHERE login = ?', 's', $_SESSION['login']);
                dbExecute($base, 'UPDATE membre SET pass_md5 = ? WHERE login = ?', 'ss', $newHash, $_SESSION['login']);
                // Regenerate session ID to prevent fixation after credential change
                session_regenerate_id(true);
                // Regenerate session token so session validation keeps working
                $newToken = bin2hex(random_bytes(32));
                $_SESSION['session_token'] = $newToken;
                $_SESSION['session_created'] = time(); // Reset absolute lifetime on password change
                dbExecute($base, 'UPDATE membre SET session_token = ? WHERE login = ?', 'ss', $newToken, $_SESSION['login']);

                $information = "Votre mot de passe a &eacute;t&eacute; chang&eacute;.";
            }
        }
    } else {
        $erreur = "Tous les champs ne sont pas remplis.";
    }
}

if (isset($_POST['changermail'])) {
    // PLAYER11-002: Rate-limit email change to prevent rapid lockout attacks via stolen credentials
    if (!rateLimitCheck($_SESSION['login'], 'email_change', 3, 3600)) {
        $erreur = "Trop de tentatives de changement d'email. Réessayez dans une heure.";
    } elseif (!empty($_POST['changermail']) && !empty($_POST['mot_de_passe_actuel'])) {
        // Require current password before changing email (PASS1-MEDIUM-004)
        $membreData = dbFetchOne($base, 'SELECT pass_md5 FROM membre WHERE login = ?', 's', $_SESSION['login']);
        $emailPasswordOk = false;
        if ($membreData) {
            $submittedPassword = $_POST['mot_de_passe_actuel'];
            $storedHash = $membreData['pass_md5'];
            if (password_verify($submittedPassword, $storedHash)) {
                $emailPasswordOk = true;
            } elseif (hash_equals(md5($submittedPassword), $storedHash)) {
                // MEDIUM-028: MD5 fallback — migrate to bcrypt on successful verify
                $newHash = password_hash($submittedPassword, PASSWORD_BCRYPT);
                dbExecute($base, 'UPDATE membre SET pass_md5 = ? WHERE login = ?', 'ss', $newHash, $_SESSION['login']);
                $emailPasswordOk = true;
            }
        }
        if (!$emailPasswordOk) {
            $erreur = "Mot de passe incorrect.";
        } elseif (mb_strlen($_POST['changermail']) > EMAIL_MAX_LENGTH) {
            $erreur = "Email trop long.";
        } elseif (!validateEmail($_POST['changermail'])) {
            // Use validateEmail() (FILTER_VALIDATE_EMAIL) for consistency with inscription.php (PASS1-MEDIUM-005)
            $erreur = "Votre email n'est pas correct.";
        } else {
            $newEmail = strtolower(trim($_POST['changermail']));
            $existingCount = dbCount($base, 'SELECT COUNT(*) AS nb FROM membre WHERE email = ? AND login != ?', 'ss', $newEmail, $_SESSION['login']);
            if ($existingCount > 0) {
                $erreur = "Cette adresse e-mail est déjà utilisée.";
            } else {
                dbExecute($base, 'UPDATE membre SET email = ? WHERE login = ?', 'ss', $newEmail, $_SESSION['login']);
                $information = "Votre adresse e-mail a été changée.";
            }
        }
    } else {
        $erreur = "Tous les champs ne sont pas remplis.";
    }
}

if (isset($_POST['changerdescription'])) {
    $newDescription = trim($_POST['changerdescription']);
    if (mb_strlen($newDescription) > DESCRIPTION_MAX_LENGTH) {
        $erreur = "Description trop longue (max " . DESCRIPTION_MAX_LENGTH . " caractères).";
    } else {
        dbExecute($base, 'UPDATE autre SET description = ? WHERE login = ?', 'ss', $newDescription, $_SESSION['login']);
        $autre['description'] = $newDescription;
        $information = "Votre description a été changée.";
    }
}

if (isset($_FILES['photo']['name']) and !empty($_FILES['photo']['name'])) {
    $dossier = 'images/profil/';
    $taille_maxi = PROFILE_IMAGE_MAX_SIZE_BYTES;
    $taille = filesize($_FILES['photo']['tmp_name']);

    // Validate extension whitelist
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
    $filenameParts = explode('.', $_FILES['photo']['name']);
    $extension = strtolower(end($filenameParts));

    // Validate MIME type with finfo
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $_FILES['photo']['tmp_name']);
    finfo_close($finfo);
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif'];

    $img_size = getimagesize($_FILES['photo']['tmp_name']);

    //Début des vérifications de sécurité...
    if (!in_array($extension, $allowedExtensions)) //Si l'extension n'est pas autorisée
    {
        $erreur = 'Seuls les fichiers de type png, gif, jpg, jpeg sont autorisés.';
    } elseif (!in_array($mimeType, $allowedMimes)) {
        $erreur = 'Le type MIME du fichier n\'est pas autorisé.';
    } elseif ($img_size === false) {
        $erreur = 'Le fichier n\'est pas une image valide.';
    } elseif ($img_size[0] > PROFILE_IMAGE_MAX_DIMENSION_PX or $img_size[1] > PROFILE_IMAGE_MAX_DIMENSION_PX) {
        $erreur = "Erreur : image trop grande ! (les dimensions requises sont au maximum " . PROFILE_IMAGE_MAX_DIMENSION_PX . "*" . PROFILE_IMAGE_MAX_DIMENSION_PX . ")";
    } elseif ($taille > $taille_maxi) {
        $erreur = 'L\'image est trop grosse ! (maximum 2 Mo)';
    } else //S'il n'y a pas d'erreur, on upload
    {
        // Generate random filename to prevent path traversal and overwrite attacks
        $fichier = 'avatar_' . bin2hex(random_bytes(16)) . '.' . $extension;
        move_uploaded_file($_FILES['photo']['tmp_name'], $dossier . $fichier);
        dbExecute($base, 'UPDATE autre SET image = ? WHERE login = ?', 'ss', $fichier, $_SESSION['login']);
        $information = "Votre image a bien été enregistrée.";
    }
}

include("includes/layout.php");

if (!isset($_POST['supprimercompte'])) {
    debutCarte("Gestion du compte");
    echo important("Changer le mot de passe");
    $mailForDisplay = dbFetchOne($base, 'SELECT email FROM membre WHERE login = ?', 's', $_SESSION['login']);
    if ($mailForDisplay && !empty($mailForDisplay['email'])) {
        echo '<p style="color:gray;font-size:0.9em;">Email actuel : <strong>' . htmlspecialchars($mailForDisplay['email'], ENT_QUOTES, 'UTF-8') . '</strong></p>';
    }
    debutListe();
    echo '<form action="compte.php" method="post" name="formChangerMdp">';
    echo csrfField();
    item(['media' => '<img alt="login" src="images/accueil/door-key.png" class="w32"/>', 'floating' => true, 'titre' => 'Mot de passe actuel', 'input' => '<input type="password" name="changermdpactuel" id="changermdpactuel" class="form-control" maxlength="' . PASSWORD_BCRYPT_MAX_LENGTH . '" autocomplete="current-password"/>']);
    item(['media' => '<img alt="login" src="images/accueil/door-key.png" class="w32"/>', 'floating' => true, 'titre' => 'Nouveau mot de passe', 'input' => '<input type="password" name="changermdp" id="changermdp" class="form-control" maxlength="' . PASSWORD_BCRYPT_MAX_LENGTH . '" autocomplete="new-password"/>']);
    item(['media' => '<img alt="login" src="images/accueil/door-key.png" class="w32"/>', 'floating' => true, 'titre' => 'Confirmation', 'input' => '<input type="password" name="changermdp1" id="changermdp1" class="form-control" maxlength="' . PASSWORD_BCRYPT_MAX_LENGTH . '" autocomplete="new-password"/>']);
    item(['input' => submit(['titre' => 'Changer', 'form' => 'formChangerMdp'])]);
    echo '</form><br/>';
    finListe();

    echo important("Changer le mail");

    $mail = dbFetchOne($base, 'SELECT email FROM membre WHERE login = ?', 's', $_SESSION['login']);
    $mailValue = ($mail && isset($mail['email'])) ? $mail['email'] : '';

    debutListe();
    echo '<form action="compte.php" method="post" name="formChangerMail">';
    echo csrfField();
    item(['media' => '<img alt="login" src="images/accueil/email.png" class="w32"/>', 'floating' => true, 'titre' => 'Mail', 'input' => '<input type="text" name="changermail" id="changermail" class="form-control" value="' . htmlspecialchars($mailValue, ENT_QUOTES, 'UTF-8') . '"/>']);
    item(['media' => '<img alt="login" src="images/accueil/door-key.png" class="w32"/>', 'floating' => true, 'titre' => 'Mot de passe actuel', 'input' => '<input type="password" name="mot_de_passe_actuel" id="mot_de_passe_actuel_mail" class="form-control" autocomplete="current-password"/>']);
    item(['input' => submit(['titre' => 'Changer', 'form' => 'formChangerMail'])]);
    echo '</form><br/>';
    finListe();

    $joueur = dbFetchOne($base, 'SELECT id FROM membre WHERE login = ?', 's', $_SESSION['login']);
    $estEnVac = dbFetchOne($base, 'SELECT vacance FROM membre WHERE id = ?', 'i', $joueur['id']);

    // Si le joueur est déjà en vacances
    if ($estEnVac['vacance']) {
        $vacance = dbFetchOne($base, 'SELECT dateDebut, dateFin FROM vacances WHERE idJoueur = ?', 'i', $joueur['id']);
        // Convertion des dates
        list($annee, $mois, $jour) = explode('-', $vacance['dateDebut']);
        $vacance['dateDebut'] = $jour . '/' . $mois . '/' . $annee;
        list($annee, $mois, $jour) = explode('-', $vacance['dateFin']);
        $vacance['dateFin'] = $jour . '/' . $mois . '/' . $annee;
        $debut =  $vacance['dateDebut'];
        $fin =  "<input type=\"text\" name=\"dateFin\" id=\"dateFin\" class=\"form-control\" value=\"" . htmlspecialchars($vacance['dateFin'], ENT_QUOTES, 'UTF-8') . "\"/>";
        $activation = "";
        $disabled = "disabled";
    }
    // Si il n'est pas en vacances
    else {
        $debut = date("d/m/Y  H:i:s");
        $fin = '<input type="text" name="dateFin" placeholder="Sélectionnez" readonly id="calVacs">';
        $activation = submit(['titre' => 'Activer', 'form' => 'formVacances']);
        $disabled = false;
    }

    echo important('Partir en vacances');
    debutListe();
    echo '<form action="compte.php" method="post" name="formVacances">';
    echo csrfField();
    echo '<br/><br/><div class="content-block">
        <strong>Avantages du mode vacances :</strong><br/>
        - Vous ne pouvez plus être attaqué<br/>
        - Vos molécules ne se dégradent pas<br/>
        - Votre production continue normalement<br/>
        <strong>Inconvénients :</strong><br/>
        - Vous ne pouvez pas attaquer, former des molécules, ou commercer<br/>
        - La production de molécules en cours sera annulée<br/>
        - Durée minimum : 3 jours
    </div><br/>';
    item(['floating' => false, 'titre' => 'Date de début', 'input' => '<input type="text" name="dateDebut" id="dateDebut" class="form-control" value="' . htmlspecialchars($debut, ENT_QUOTES, 'UTF-8') . '"/>', 'disabled' => true]);
    item(['floating' => false, 'titre' => 'Date de fin', 'input' => $fin, 'disabled' => $disabled]);
    item(['input' => $activation]);
    echo '</form>';
    finListe();

    echo important("Supprimer le compte");
    debutListe();
    $donnees = dbFetchOne($base, 'SELECT timestamp FROM membre WHERE login = ?', 's', $_SESSION['login']);
    if ((time() - $donnees['timestamp']) > SECONDS_PER_WEEK) {
        item(['form' => ["compte.php", "formSupprimer"], 'input' => '<input type="hidden" name="supprimercompte"/>' . csrfField() . submit(['titre' => 'Supprimer le compte', 'style' => 'background-color:red', 'form' => 'formSupprimer', 'confirm' => 'Êtes-vous sûr de vouloir supprimer votre compte ? Cette action est irréversible.'])]);
    } else {
        debutContent();
        echo '<br/>Le compte ne peut être supprimé qu\'au bout d\'une semaine.';
        finContent();
    }
    finListe();
    finCarte();

    debutCarte("Gestion du profil");

    echo important("Modifier la description");
    debutListe();

    $description = dbFetchOne($base, 'SELECT description FROM autre WHERE login = ?', 's', $_SESSION['login']);
    echo '<br/>';
    creerBBcode("changerdescription", $description['description']);

    item(['form' => ["compte.php", "formChangerDescription"], 'floating' => false, 'titre' => "Description", 'input' => '<textarea name="changerdescription" id="changerdescription" rows="10" cols="50" maxlength="' . DESCRIPTION_MAX_LENGTH . '">' . htmlspecialchars($description['description'], ENT_QUOTES, 'UTF-8') . '</textarea>' . csrfField()]);
    item(['input' => submit(['titre' => 'Modifier', 'form' => 'formChangerDescription'])]);

    finListe();
    echo '<br/>';

    echo important("Photo de profil (150x150)") . '<br/>';
    debutListe();
    item(['form' => ["compte.php", "formChangerPhoto", 'sup' => 'enctype="multipart/form-data"'], 'floating' => false, 'input' => '<input type="file" name="photo" id="photo" class="filestyle" data-buttonName="btn-primary" data-buttonBefore="true" data-icon="false"/><input type="hidden" name="MAX_FILE_SIZE" value="2000000"/>' . csrfField()]);

    item(['input' => submit(['titre' => 'Modifier', 'form' => 'formChangerPhoto'])]);
    finListe();
    finCarte();
} else {
    debutCarte("Suppression du compte");
    important("Supprimer votre compte ?");
    debutListe();
    item(['input' => '
                 <center>
                    <input type="submit" style="vertical-align:middle;margin-right:80px;background:url(images/yes.png) no-repeat;width:48px;height:48px;border:none;cursor:pointer;" name="oui" value=""/><input style="vertical-align:middle;background:url(images/croix.png) no-repeat;width:48px;height:48px;border:none;cursor:pointer;" type="submit" name="non" value=""/>
	               <input type="hidden" name="verification"/>
                   ' . csrfField() . '
                </center>', 'form' => ["compte.php", "supprimerLeCompte"]]);
    finListe();
    finCarte();
}
?>
<?php include("includes/copyright.php"); ?>
