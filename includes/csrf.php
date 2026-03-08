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
    if (hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        // Token is NOT rotated on success: rotating breaks multi-form pages (e.g. compte.php)
        // where the first submission would invalidate the token for subsequent forms.
        // The token still changes on session creation (csrfToken()), which is sufficient.
        return true;
    }
    return false;
}

function csrfCheck() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // MEDIUM-026: Secondary defense — validate Origin header if present.
        // This catches browsers that send Origin (all modern browsers on cross-origin POSTs).
        if (isset($_SERVER['HTTP_ORIGIN'])) {
            $expectedOrigin = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
            if (strpos($_SERVER['HTTP_ORIGIN'], $expectedOrigin) !== 0) {
                if (function_exists('logWarn')) {
                    logWarn('SECURITY', 'CSRF origin mismatch', [
                        'origin' => $_SERVER['HTTP_ORIGIN'],
                        'expected' => $expectedOrigin,
                        'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
                    ]);
                }
                http_response_code(403);
                exit('CSRF origin mismatch');
            }
        }
        if (!csrfVerify()) {
            if (function_exists('logWarn')) {
                logWarn('SECURITY', 'CSRF token validation failed', [
                    'ip_hash' => substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . (defined('SECRET_SALT') ? SECRET_SALT : 'tvlw_salt')), 0, 12),
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
            // For regular requests, redirect to previous page or index (same-origin only, P2-D1-001)
            $referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
            $parsed = parse_url($referer);
            if (!empty($parsed['host']) && $parsed['host'] !== ($_SERVER['HTTP_HOST'] ?? '')) {
                $redirectTo = 'index.php';
            } else {
                // Only use the path component, sanitize to a known PHP page
                $path = basename($parsed['path'] ?? 'index.php');
                $redirectTo = preg_match('/^[a-zA-Z0-9_-]+\.php$/', $path) ? $path : 'index.php';
            }
            header('Location: ' . $redirectTo . '?erreur=' . urlencode('Erreur de securite : jeton CSRF invalide. Veuillez rafraichir la page.'));
            exit();
        }
    }
}
