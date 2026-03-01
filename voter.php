<?php
session_start();
include("includes/connexion.php");
require_once("includes/database.php");

// Auth check: must be logged in
if (!isset($_SESSION['login'])) {
    exit(json_encode(["erreur" => true]));
}

if (isset($_GET['reponse']) && isset($_GET['login'])) {
    if (!empty($_GET['login']) && !empty($_GET['reponse'])) {
        $login = $_SESSION['login']; // Use session login, not user-supplied
        $reponse = $_GET['reponse'];

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
            if (!isset($_GET['pasDeVote'])) {
                dbExecute($base, 'UPDATE reponses SET reponse = ? WHERE login = ? AND sondage = ?', 'ssi', $reponse, $login, $sondageId);
            }
            exit(json_encode(["erreur" => false, "dejaRepondu" => true]));
        }
    } else {
        exit(json_encode(["erreur" => true]));
    }
}
