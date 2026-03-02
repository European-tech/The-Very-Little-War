<?php
session_start();
include("includes/connexion.php");
require_once("includes/database.php");
require_once("includes/csrf.php");

// Auth check: must be logged in
if (!isset($_SESSION['login'])) {
    exit(json_encode(["erreur" => true]));
}

// Accept both GET (legacy) and POST, require CSRF on POST
$reponse = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $reponse = $_POST['reponse'] ?? null;
} elseif (isset($_GET['reponse'])) {
    // Legacy GET support — will be removed in future
    $reponse = $_GET['reponse'];
}

if (!empty($reponse)) {
    $login = $_SESSION['login'];

    $data = dbFetchOne($base, 'SELECT id FROM sondages ORDER BY date DESC LIMIT 1');

    if (!$data) {
        exit(json_encode(["erreur" => true]));
    }

    $sondageId = $data['id'];

    $existing = dbFetchOne($base, 'SELECT count(*) AS nb FROM reponses WHERE login = ? AND sondage = ?', 'si', $login, $sondageId);

    if ($existing['nb'] == 0) {
        dbExecute($base, 'INSERT INTO reponses VALUES(default, ?, ?, ?)', 'sis', $login, $sondageId, $reponse);
        exit(json_encode(["erreur" => false, "dejaRepondu" => false]));
    } else {
        $pasDeVote = $_POST['pasDeVote'] ?? $_GET['pasDeVote'] ?? null;
        if (!$pasDeVote) {
            dbExecute($base, 'UPDATE reponses SET reponse = ? WHERE login = ? AND sondage = ?', 'ssi', $reponse, $login, $sondageId);
        }
        exit(json_encode(["erreur" => false, "dejaRepondu" => true]));
    }
} else {
    exit(json_encode(["erreur" => true]));
}
