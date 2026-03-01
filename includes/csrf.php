<?php
/**
 * CSRF protection: generate and verify tokens.
 */

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

function csrfVerify() {
    if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

function csrfCheck() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrfVerify()) {
            die('Erreur de securite : jeton CSRF invalide. Veuillez rafraichir la page.');
        }
    }
}
