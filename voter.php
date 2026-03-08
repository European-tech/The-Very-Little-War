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
$row = dbFetchOne($base, 'SELECT session_token FROM membre WHERE login = ? AND estExclu = 0', 's', $_SESSION['login']);
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

    // VOTE-P9-001: fixed column name (options not reponses), VOTE-P9-002: filter active=1
    $data = dbFetchOne($base, 'SELECT id, options FROM sondages WHERE active = 1 ORDER BY date DESC LIMIT 1');

    if (!$data) {
        exit(json_encode(["erreur" => true]));
    }

    // Validate option index against actual number of options
    $options = array_filter(array_map('trim', explode(',', $data['options'])));
    if ($reponse < 1 || $reponse > count($options)) {
        exit(json_encode(["erreur" => true]));
    }

    $sondageId = $data['id'];

    $dejaRepondu = false;
    // VOTE-P9-003: removed client-controlled $pasDeVote — once a vote is cast it cannot be changed
    // VOTE-P9-004: INSERT IGNORE handles concurrent first-vote race gracefully
    withTransaction($base, function() use ($base, $login, $sondageId, $reponse, &$dejaRepondu) {
        $existing = dbFetchOne($base, 'SELECT id FROM reponses_sondage WHERE login = ? AND sondage = ? FOR UPDATE', 'si', $login, $sondageId);
        if (!$existing) {
            dbExecute($base, 'INSERT IGNORE INTO reponses_sondage (login, sondage, reponse) VALUES (?, ?, ?)', 'sis', $login, $sondageId, $reponse);
            $dejaRepondu = false;
        } else {
            $dejaRepondu = true;
        }
    });
    exit(json_encode(["erreur" => false, "dejaRepondu" => $dejaRepondu]));
} else {
    // TODO: P9-CRIT-001 — no poll results endpoint exists; results can only be viewed via DB query
    exit(json_encode(["erreur" => true]));
}
