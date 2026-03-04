<?php
require_once(__DIR__ . '/session_init.php');

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

    // Regenerate session ID periodically (every 30 min) to limit session fixation window
    if (!isset($_SESSION['_last_regeneration']) || (time() - $_SESSION['_last_regeneration']) > SESSION_REGEN_INTERVAL) {
        session_regenerate_id(true);
        $_SESSION['_last_regeneration'] = time();
    }
} else {
    session_destroy();
    header('Location: index.php');
    exit();
}

// Idle timeout: 1 hour of inactivity
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {
    session_destroy();
    header('Location: index.php?erreur=' . urlencode('Session expirée. Veuillez vous reconnecter.'));
    exit();
}
$_SESSION['last_activity'] = time();

// si c'est la premiere connexion depuis la derniere partie, on le replace
$posAct = dbFetchOne($base, 'SELECT x, y FROM membre WHERE login = ?', 's', $_SESSION['login']);
if ($posAct && $posAct['x'] == -1000) {
    $position = coordonneesAleatoires();
    dbExecute($base, 'UPDATE membre SET x = ?, y = ? WHERE login = ?', 'iis', $position['x'], $position['y'], $_SESSION['login']);
}
require_once(__DIR__ . '/constantesBase.php');
initPlayer($_SESSION['login']);

/////////////////////////////////////////////////////


if (isset($_GET['information'])) {
    $information = antiXSS($_GET['information']);
}

if (isset($_GET['erreur'])) {
    $erreur = antiXSS($_GET['erreur']);
}
//////////////////////////////////////////////////////////// Gestion des connectés
// Throttle: only update once per 60 seconds to reduce DB writes
if (!isset($_SESSION['last_online_update']) || time() - $_SESSION['last_online_update'] > ONLINE_UPDATE_THROTTLE_SECONDS) {
    //Vérification si l'adresse IP est dans la table
    $donnees = dbFetchOne($base, 'SELECT COUNT(*) AS nbre_entrees FROM connectes WHERE ip = ?', 's', $_SERVER['REMOTE_ADDR']);

    if (!$donnees || $donnees['nbre_entrees'] == 0) //L'IP ne se trouve pas dans la table, on va l'ajouter.
    {
        $now = time();
        dbExecute($base, 'INSERT INTO connectes VALUES(?, ?)', 'si', $_SERVER['REMOTE_ADDR'], $now);
    } else //L'IP se trouve déjà dans la table, on met juste à jour le timestamp.
    {
        $now = time();
        dbExecute($base, 'UPDATE connectes SET timestamp = ? WHERE ip = ?', 'is', $now, $_SERVER['REMOTE_ADDR']);
    }

    // Toutes les entrées vieilles de plus de 5 minutes sont supprimées
    $timestamp_5min = time() - ONLINE_TIMEOUT_SECONDS;
    dbExecute($base, 'DELETE FROM connectes WHERE timestamp < ?', 'i', $timestamp_5min);

    $_SESSION['last_online_update'] = time();
}

// Ajout de Yojim
// On vérifie si le joueur connecté est en vacance
$joueurEnVac = dbFetchOne($base, 'SELECT vacance FROM membre WHERE login = ?', 's', $_SESSION['login']);


// Si le joueur n'est pas en vacance on fait la mise a jour des ressources ...
if (!$joueurEnVac['vacance']) {
    updateRessources($_SESSION['login']); // mise a jour
    $now = time();
    dbExecute($base, 'UPDATE membre SET derniereConnexion = ? WHERE login = ?', 'is', $now, $_SESSION['login']);

    $donnees = dbFetchOne($base, 'SELECT tempsPrecedent FROM autre WHERE login = ?', 's', $_SESSION['login']);
    updateActions($_SESSION['login']);
    // Refresh player data after resource/action updates (invalidate cache first)
    unset($GLOBALS['_initPlayerCache'][$_SESSION['login']]);
    initPlayer($_SESSION['login']);
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

if ($maintenance['maintenance'] == 1 && (time() - $debut["debut"]) >= SEASON_MAINTENANCE_PAUSE_SECONDS) {
    // Phase 2: 24h have passed since maintenance was set, proceed with full reset
    // Advisory lock prevents concurrent resets when multiple players connect simultaneously
    $lockResult = dbFetchOne($base, "SELECT GET_LOCK('tvlw_season_reset', 0) as locked", '');
    if (!$lockResult || $lockResult['locked'] != 1) {
        // Another request is already performing the reset — skip and show maintenance message
        $erreur = "Une nouvelle partie recommencera dans 24 heures.";
    } else {

    // Full season-end flow: archive, VP, prestige, reset, news
    $vainqueurManche = performSeasonEnd();

    // Reset complete, disable maintenance mode BEFORE emails
    // so game stays accessible even if email loop hangs or times out
    dbExecute($base, 'UPDATE statistiques SET maintenance = 0');

    // Release the advisory lock now that the reset is fully committed
    dbExecute($base, "SELECT RELEASE_LOCK('tvlw_season_reset')", '');

    //envoi des mails (always send — even without winner, notify of reset)
    // Runs AFTER maintenance cleared — email failures won't lock the game
    $mailRows = dbFetchAll($base, 'SELECT email, login FROM membre', '');
    foreach ($mailRows as $donnees) {
        $mail = $donnees['email']; // Déclaration de l'adresse de destination.
        if (!preg_match("#^[a-z0-9._-]+@(hotmail|live|msn).[a-z]{2,4}$#", $mail)) // On filtre les serveurs qui rencontrent des bogues.
        {
            $passage_ligne = "\r\n";
        } else {
            $passage_ligne = "\n";
        }
        //=====Déclaration des messages au format texte et au format HTML.
        $winnerName = $vainqueurManche ?? 'Personne';
        $message_txt = "Bonjour " . $donnees['login'] . " ! " . $winnerName . " vient de remporter la partie en cours le " . date('d/m/Y à H\hi', time()) . ". Les points de tous les joueurs vont être remis à zéro et
            vous pourrez commencer à rejouer la nouvelle partie à partir du " . date('d/m/Y à H\hi', time()) . " ! Ne manquez pas cette occasion de prendre la tête du classement. Je vous souhaite donc bonne chance pour la suite
            et à bientôt sur The Very Little War !
            Si vous ne souhaitez plus recevoir ce genre de mail il suffit de changer votre adresse e-mail sur www.theverylittlewar.com dans la partie \"Mon compte\".";
        $message_html = "<html><head></head><body>Bonjour " . $donnees['login'] . " ! <b>" . $winnerName . "</b> vient de remporter la partie en cours le " . date('d/m/Y à H\hi', time()) . ". Les points de tous les joueurs vont être remis à zéro et
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
        $mailResult = mail($mail, $sujet, $message, $header);
        if (!$mailResult) {
            logWarn('SEASON', 'Season reset email failed', ['player' => $donnees['login']]);
        }
        //==========
    }

    } // end advisory lock else block

} elseif (date('n', time()) != date('n', $debut["debut"]) && $maintenance['maintenance'] == 0) {
    // Phase 1: New month detected, enable maintenance and start 24h countdown
    $erreur = "Une nouvelle partie recommencera dans 24 heures.";
    dbExecute($base, 'UPDATE statistiques SET maintenance = 1');
    $now = time();
    dbExecute($base, 'UPDATE statistiques SET debut = ?', 'i', $now);
    // Block POST actions during maintenance
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Le jeu est en maintenance']);
        exit;
    }
} elseif ($maintenance['maintenance'] == 1 && (time() - $debut["debut"]) < SEASON_MAINTENANCE_PAUSE_SECONDS) {
    // Still in maintenance period, 24h have not yet passed
    $erreur = "Une nouvelle partie recommencera dans 24 heures.";
    // Block POST actions during maintenance
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Le jeu est en maintenance']);
        exit;
    }
}
