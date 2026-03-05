<?php
require_once("includes/session_init.php");
include("includes/connexion.php");
require_once("includes/database.php");
require_once("includes/csrf.php");

// Auth check: must be logged in
if (!isset($_SESSION['login'])) {
    exit(json_encode(["erreur" => true]));
}

// Validate session token against DB
$row = dbFetchOne($base, 'SELECT session_token FROM membre WHERE login = ?', 's', $_SESSION['login']);
if (!$row || !isset($_SESSION['session_token']) || !$row['session_token'] || !hash_equals($row['session_token'], $_SESSION['session_token'])) {
    session_destroy();
    exit(json_encode(["erreur" => true]));
}

// POST only with CSRF — GET vote support removed (P5-GAP-009)
$reponse = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $reponse = intval($_POST['reponse'] ?? 0);
} else {
    exit(json_encode(["erreur" => true]));
}

if (!empty($reponse)) {
    $login = $_SESSION['login'];

    $data = dbFetchOne($base, 'SELECT id, reponses FROM sondages ORDER BY date DESC LIMIT 1');

    if (!$data) {
        exit(json_encode(["erreur" => true]));
    }

    // Validate option index against actual number of options
    $options = explode(',', $data['reponses']);
    if ($reponse < 1 || $reponse > count($options)) {
        exit(json_encode(["erreur" => true]));
    }

    $sondageId = $data['id'];

    $existing = dbFetchOne($base, 'SELECT count(*) AS nb FROM reponses_sondage WHERE login = ? AND sondage = ?', 'si', $login, $sondageId);

    if ($existing['nb'] == 0) {
        dbExecute($base, 'INSERT INTO reponses_sondage VALUES(default, ?, ?, ?)', 'sis', $login, $sondageId, $reponse);
        exit(json_encode(["erreur" => false, "dejaRepondu" => false]));
    } else {
        $pasDeVote = $_POST['pasDeVote'] ?? null;
        if (!$pasDeVote) {
            dbExecute($base, 'UPDATE reponses_sondage SET reponse = ? WHERE login = ? AND sondage = ?', 'isi', $reponse, $login, $sondageId);
        }
        exit(json_encode(["erreur" => false, "dejaRepondu" => true]));
    }
} else {
    exit(json_encode(["erreur" => true]));
}
