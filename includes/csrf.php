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
            if (function_exists('logWarn')) {
                logWarn('SECURITY', 'CSRF token validation failed', [
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
                ]);
            }
            http_response_code(403);
            // For AJAX requests, return JSON error
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Jeton CSRF invalide']);
                exit();
            }
            // For regular requests, redirect to previous page or index
            $referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
            header('Location: ' . $referer . (strpos($referer, '?') !== false ? '&' : '?') . 'erreur=' . urlencode('Erreur de securite : jeton CSRF invalide. Veuillez rafraichir la page.'));
            exit();
        }
    }
}
