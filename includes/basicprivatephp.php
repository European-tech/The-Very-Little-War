<?php
// Session security hardening (must be set before session_start)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', !empty($_SERVER['HTTPS']) ? 1 : 0);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

include("includes/connexion.php");
include("includes/fonctions.php");
require_once(__DIR__ . '/csrf.php');
require_once(__DIR__ . '/validation.php');
require_once(__DIR__ . '/logger.php');

if (isset($_SESSION['login']) && isset($_SESSION['session_token'])) {
    $row = dbFetchOne($base, 'SELECT session_token FROM membre WHERE login = ?', 's', $_SESSION['login']);
    if (!$row || !isset($_SESSION['session_token']) || !$row['session_token'] || !hash_equals($row['session_token'], $_SESSION['session_token'])) {
        session_destroy();
        header('Location: index.php');
        exit();
    }

    // Regenerate session ID to prevent session fixation
    if (!isset($_SESSION['_regenerated'])) {
        session_regenerate_id(true);
        $_SESSION['_regenerated'] = true;
    }
} elseif (isset($_SESSION['login']) && isset($_SESSION['mdp'])) {
    // Legacy fallback: password-hash-based sessions still active after upgrade
    // Validate using old method, then migrate to session token
    $row = dbFetchOne($base, 'SELECT pass_md5 FROM membre WHERE login = ?', 's', $_SESSION['login']);
    if (!$row || $row['pass_md5'] !== $_SESSION['mdp']) {
        session_destroy();
        header('Location: index.php');
        exit();
    }
    // Migrate to session token
    $sessionToken = bin2hex(random_bytes(32));
    $_SESSION['session_token'] = $sessionToken;
    unset($_SESSION['mdp']);
    dbExecute($base, 'UPDATE membre SET session_token=? WHERE login=?', 'ss', $sessionToken, $_SESSION['login']);

    if (!isset($_SESSION['_regenerated'])) {
        session_regenerate_id(true);
        $_SESSION['_regenerated'] = true;
    }
} else {
    session_destroy();
    header('Location: index.php');
    exit();
}


// si c'est la premiere connexion depuis la derniere partie, on le replace
$posAct = dbFetchOne($base, 'SELECT x, y FROM membre WHERE login = ?', 's', $_SESSION['login']);
if ($posAct && $posAct['x'] == -1000) {
    $position = coordonneesAleatoires();
    dbExecute($base, 'UPDATE membre SET x = ?, y = ? WHERE login = ?', 'iis', $position['x'], $position['y'], $_SESSION['login']);
}
include("includes/constantes.php");

/////////////////////////////////////////////////////


if (isset($_GET['information'])) {
    $information = antiXSS($_GET['information']);
}

if (isset($_GET['erreur'])) {
    $erreur = antiXSS($_GET['erreur']);
}
//////////////////////////////////////////////////////////// Gestion des connectés

//Vérification si l'adresse IP est dans la table
$donnees = dbFetchOne($base, 'SELECT COUNT(*) AS nbre_entrees FROM connectes WHERE ip = ?', 's', $_SERVER['REMOTE_ADDR']);

if ($donnees['nbre_entrees'] == 0) //L'IP ne se trouve pas dans la table, on va l'ajouter.
{
    $now = time();
    dbExecute($base, 'INSERT INTO connectes VALUES(?, ?)', 'si', $_SERVER['REMOTE_ADDR'], $now);
} else //L'IP se trouve déjà dans la table, on met juste à jour le timestamp.
{
    $now = time();
    dbExecute($base, 'UPDATE connectes SET timestamp = ? WHERE ip = ?', 'is', $now, $_SERVER['REMOTE_ADDR']);
}

// Toutes les entrées vieilles de plus de 5 minutes sont supprimées
$timestamp_5min = time() - (60 * 5); // 60 * 5 = nombre de secondes écoulées en 5 minutes
dbExecute($base, 'DELETE FROM connectes WHERE timestamp < ?', 'i', $timestamp_5min);

// Ajout de Yojim
// On vérifie si le joueur connecté est en vacance
$joueurEnVac = dbFetchOne($base, 'SELECT vacance FROM membre WHERE login = ?', 's', $_SESSION['login']);


updateRessources($_SESSION['login']); // mise a jour
// Si le joueur n'est pas en vacance on fait la mise a jour des ressources ...
if (!$joueurEnVac['vacance']) {
    $now = time();
    dbExecute($base, 'UPDATE membre SET derniereConnexion = ? WHERE login = ?', 'is', $now, $_SESSION['login']);

    $donnees = dbFetchOne($base, 'SELECT tempsPrecedent FROM autre WHERE login = ?', 's', $_SESSION['login']);
    updateActions($_SESSION['login']);
    include("includes/constantes.php");
}
// Ajout par Yojim
// Si le joueur est encore en mode vacances
else {
    // On récupère la date de fin du mode vacances
    $vac = dbFetchOne($base, 'SELECT dateFin FROM vacances WHERE idJoueur IN (SELECT id FROM membre WHERE login = ?)', 's', $_SESSION['login']);
    // On calcul la différence entre la date de fin et la date actuelle
    $diff = dbFetchOne($base, 'SELECT DATEDIFF(CURDATE(), ?) AS d', 's', $vac['dateFin']);
    $now = time();
    dbExecute($base, 'UPDATE membre SET derniereConnexion = ? WHERE login = ?', 'is', $now, $_SESSION['login']);
    // Si la date de fin du mode vacances est passee, on enleve le mode vacances
    if ($diff['d'] >= 0) {
        // Mise à jour du champ vacances
        dbExecute($base, 'UPDATE membre SET vacance = 0 WHERE login = ?', 's', $_SESSION['login']);
        // Supression du tuple de vacances
        dbExecute($base, 'DELETE FROM vacances WHERE idJoueur IN (SELECT id FROM membre WHERE login = ?)', 's', $_SESSION['login']);
        $now = time();
        dbExecute($base, 'UPDATE autre SET tempsPrecedent = ? WHERE login = ?', 'is', $now, $_SESSION['login']);
    }
}

//////////////////////////////////////////////////////////// Gestion des ressources
//Vérification si nouveau mois le lendemain
$debutRow = dbFetchOne($base, 'SELECT debut FROM statistiques');
$debut = $debutRow;

$maintenanceRow = dbFetchOne($base, 'SELECT maintenance FROM statistiques');
$maintenance = $maintenanceRow;

if ($maintenance['maintenance'] == 1 && (time() - $debut["debut"]) >= 86400) {
    // Phase 2: 24h have passed since maintenance was set, proceed with full reset
    // Advisory lock prevents concurrent resets when multiple players connect simultaneously
    $lockResult = dbFetchOne($base, "SELECT GET_LOCK('tvlw_season_reset', 0) as locked", '');
    if (!$lockResult || $lockResult['locked'] != 1) {
        // Another request is already performing the reset — skip and show maintenance message
        $erreur = "Une nouvelle partie recommencera dans 24 heures.";
    } else {

    //archivage de la partie (20 meilleurs)
    $chaine = '';
    $classement = dbQuery($base, 'SELECT * FROM autre ORDER BY totalPoints DESC LIMIT 0, 20');
    $compteur = 0;
    while ($data = mysqli_fetch_array($classement)) {
        $sql4Result = dbQuery($base, 'SELECT nombre FROM molecules WHERE proprietaire = ? AND nombre != 0', 's', $data['login']);
        if ($data['idalliance'] > 0) {
            $alliance = dbFetchOne($base, 'SELECT tag, id FROM alliances WHERE id = ?', 'i', $data['idalliance']);
        } else {
            $alliance['tag'] = '';
        }
        $nb_molecules = 0;
        while ($donnees4 = mysqli_fetch_array($sql4Result)) {
            $nb_molecules = $nb_molecules + $donnees4['nombre'];
        }
        $chaine = $chaine . '[' . $data['login'] . ',' . $data['totalPoints'] . ',' . $alliance['tag'] . ',' . $data['points'] . ',' . pointsAttaque($data['pointsAttaque']) . ',' . pointsDefense($data['pointsDefense']) . ',' . $data['ressourcesPillees'] . ',' . $data['victoires'] . '';

        if ($compteur == 0) {
            $vainqueurManche = $data['login'];
        }

        $compteur++;
    }

    //archivage des alliances
    $classement = dbQuery($base, 'SELECT * FROM alliances ORDER BY pointstotaux DESC LIMIT 0, 20');
    $chaine1 = '';
    while ($data = mysqli_fetch_array($classement)) {
        $req1 = dbQuery($base, 'SELECT login FROM autre WHERE idalliance = ?', 'i', $data['id']);
        $nbjoueurs = mysqli_num_rows($req1);
        if ($nbjoueurs != 0) {
            $chaine1 = $chaine1 . '[' . $data['tag'] . ',' . $nbjoueurs . ',' . $data['pointstotaux'] . ',' . $data['pointstotaux'] / $nbjoueurs . ',' . $data['totalConstructions'] . ',' . pointsAttaque($data['totalAttaque']) . ',' . pointsDefense($data['totalDefense']) . ',' . $data['totalPillage'] . ',' . $data['pointsVictoire'] . '';
        }
    }

    //archivage guerres
    $classement = dbQuery($base, 'SELECT * FROM declarations WHERE pertesTotales != 0 AND type = 0 AND fin != 0 ORDER BY pertesTotales DESC LIMIT 0, 20');
    $chaine2 = '';
    while ($data = mysqli_fetch_array($classement)) {
        $alliance1 = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id = ?', 'i', $data['alliance1']);
        $alliance2 = dbFetchOne($base, 'SELECT tag FROM alliances WHERE id = ?', 'i', $data['alliance2']);
        $req1 = dbQuery($base, 'SELECT login FROM autre WHERE idalliance = ?', 'i', $data['id']);
        $nbjoueurs = mysqli_num_rows($req1);
        if ($nbjoueurs != 0) {
            $chaine2 = $chaine2 . '[' . $alliance1['tag'] . ' contre ' . $alliance2['tag'] . ',' . $data['pertesTotales'] . ',' . (($data['fin'] - $data['timestamp']) / 86400) . ',' . $data['id'] . '';
        }
    }

    // ajout des points pour les alliances et joueurs
    $classement = dbQuery($base, 'SELECT * FROM autre ORDER BY totalPoints DESC');
    $c = 1;
    while ($pointsVictoire = mysqli_fetch_array($classement)) {
        ajouter('victoires', 'autre', pointsVictoireJoueur($c), $pointsVictoire['login']);
        $c++;
    }

    $classement = dbQuery($base, 'SELECT * FROM alliances ORDER BY pointstotaux DESC');
    $c = 1;
    while ($pointsVictoire = mysqli_fetch_array($classement)) {
        $newPtsVictoire = $pointsVictoire['pointsVictoire'] + pointsVictoireAlliance($c);
        dbExecute($base, 'UPDATE alliances SET pointsVictoire = ? WHERE id = ?', 'ii', $newPtsVictoire, $pointsVictoire['id']);
        $victoiresJoueurs = dbQuery($base, 'SELECT * FROM autre WHERE idalliance = ?', 'i', $pointsVictoire['id']);
        while ($pointsVictoireJoueurs = mysqli_fetch_array($victoiresJoueurs)) {
            ajouter('victoires', 'autre', pointsVictoireAlliance($c), $pointsVictoireJoueurs['login']);
        }
        $c++;
    }

    //remise à zéro et news

    // Award prestige points BEFORE reset (cross-season progression)
    require_once(__DIR__ . '/prestige.php');
    awardPrestigePoints();

    $debutRow2 = dbFetchOne($base, 'SELECT debut FROM statistiques');
    $debut = $debutRow2;
    $now = time();
    dbExecute($base, 'INSERT INTO parties VALUES(default, ?, ?, ?, ?)', 'isss', $now, $chaine, $chaine1, $chaine2);
    remiseAZero();

    $now = time();
    dbExecute($base, 'UPDATE statistiques SET debut = ?', 'i', $now);

    $titre = "Vainqueur de la dernière manche";
    $contenu = 'Le vainqueur de la dernière manche est <a href="joueur.php?id=' . htmlspecialchars($vainqueurManche, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($vainqueurManche, ENT_QUOTES, 'UTF-8') . '</a><br/><br/>Reprise <strong>le ' . date('d/m/Y à H\hi', time()) . '</strong>';

    //mise à jour du nombre de victoires et des news
    $now = time();
    dbExecute($base, 'INSERT INTO news VALUES(default, ?, ?, ?)', 'ssi', $titre, $contenu, $now);

    //envoi des mails
    $exMails = dbQuery($base, 'SELECT email, login FROM membre');
    while ($donnees = mysqli_fetch_array($exMails)) {
        $mail = $donnees['email']; // Déclaration de l'adresse de destination.
        if (!preg_match("#^[a-z0-9._-]+@(hotmail|live|msn).[a-z]{2,4}$#", $mail)) // On filtre les serveurs qui rencontrent des bogues.
        {
            $passage_ligne = "\r\n";
        } else {
            $passage_ligne = "\n";
        }
        //=====Déclaration des messages au format texte et au format HTML.
        $message_txt = "Bonjour " . $donnees['login'] . " ! " . $vainqueurManche . " vient de remporter la partie en cours le " . date('d/m/Y à H\hi', time()) . ". Les points de tous les joueurs vont être remis à zéro et
            vous pourrez commencer à rejouer la nouvelle partie à partir du " . date('d/m/Y à H\hi', time()) . " ! Ne manquez pas cette occasion de prendre la tête du classement. Je vous souhaite donc bonne chance pour la suite
            et à bientôt sur The Very Little War !
            Si vous ne souhaitez plus recevoir ce genre de mail il suffit de changer votre adresse e-mail sur www.theverylittlewar.com dans la partie \"Mon compte\".";
        $message_html = "<html><head></head><body>Bonjour " . $donnees['login'] . " ! <b>" . $vainqueurManche . "</b> vient de remporter la partie en cours le " . date('d/m/Y à H\hi', time()) . ". Les points de tous les joueurs vont être remis à zéro et
            vous pourrez commencer à rejouer la nouvelle partie à partir du <b>" . date('d/m/Y à H\hi', time()) . "</b> ! Ne manquez pas cette occasion de prendre la tête du classement. Je vous souhaite donc bonne chance pour la suite
            et à bientôt sur <a href=\"www.theverylittlewar.com\">The Very Little War</a> !<br/><br/><br/><br/>
            <i>Si vous ne souhaitez plus recevoir ce genre de mail il suffit de changer votre adresse e-mail sur <a href=\"www.theverylittlewar.com\">www.theverylittlewar.com</a> dans la partie \"Mon compte\".</i></body></html>";
        //==========

        //=====Création de la boundary
        $boundary = "-----=" . md5(rand());
        //==========

        //=====Définition du sujet.
        $sujet = "Début d'une nouvelle partie";
        //=========

        //=====Création du header de l'e-mail.
        $header = "From: \"The Very Little War\"<noreply@theverylittewar.com>" . $passage_ligne;
        $header .= "Reply-to: \"The Very Little War\" <theverylittewar@gmail.com>" . $passage_ligne;
        $header .= "MIME-Version: 1.0" . $passage_ligne;
        $header .= "Content-Type: multipart/alternative;" . $passage_ligne . " boundary=\"$boundary\"" . $passage_ligne;
        //==========

        //=====Création du message.
        $message = $passage_ligne . "--" . $boundary . $passage_ligne;
        //=====Ajout du message au format texte.
        $message .= "Content-Type: text/plain; charset=\"UTF-8\"" . $passage_ligne;
        $message .= "Content-Transfer-Encoding: 8bit" . $passage_ligne;
        $message .= $passage_ligne . $message_txt . $passage_ligne;
        //==========
        $message .= $passage_ligne . "--" . $boundary . $passage_ligne;
        //=====Ajout du message au format HTML
        $message .= "Content-Type: text/html; charset=\"UTF-8\"" . $passage_ligne;
        $message .= "Content-Transfer-Encoding: 8bit" . $passage_ligne;
        $message .= $passage_ligne . $message_html . $passage_ligne;
        //==========
        $message .= $passage_ligne . "--" . $boundary . "--" . $passage_ligne;
        $message .= $passage_ligne . "--" . $boundary . "--" . $passage_ligne;
        //==========

        //=====Envoi de l'e-mail.
        mail($mail, $sujet, $message, $header);
        //==========
    }

    // Reset complete, disable maintenance mode
    dbExecute($base, 'UPDATE statistiques SET maintenance = 0');

    // Release the advisory lock now that the reset is fully committed
    dbExecute($base, "SELECT RELEASE_LOCK('tvlw_season_reset')", '');

    } // end advisory lock else block

} elseif (date('n', time()) != date('n', $debut["debut"]) && $maintenance['maintenance'] == 0) {
    // Phase 1: New month detected, enable maintenance and start 24h countdown
    $erreur = "Une nouvelle partie recommencera dans 24 heures.";
    dbExecute($base, 'UPDATE statistiques SET maintenance = 1');
    $now = time();
    dbExecute($base, 'UPDATE statistiques SET debut = ?', 'i', $now);
} elseif ($maintenance['maintenance'] == 1 && (time() - $debut["debut"]) < 86400) {
    // Still in maintenance period, 24h have not yet passed
    $erreur = "Une nouvelle partie recommencera dans 24 heures.";
}
