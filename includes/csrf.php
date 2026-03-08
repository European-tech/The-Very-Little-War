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
            // INFRA-P10-001: Use SERVER_NAME (not HTTP_HOST) — HTTP_HOST is client-controlled
            // and can be spoofed to bypass origin checks; SERVER_NAME is set by Apache config.
            // AUTH12-001: Include port for non-standard ports (browsers omit default 80/443).
            $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            $scheme = $isHttps ? 'https' : 'http';
            $port = (int)($_SERVER['SERVER_PORT'] ?? ($isHttps ? 443 : 80));
            $defaultPort = $isHttps ? 443 : 80;
            $portSuffix = ($port !== $defaultPort) ? ':' . $port : '';
            $expectedOrigin = $scheme . '://' . $_SERVER['SERVER_NAME'] . $portSuffix;
            if ($_SERVER['HTTP_ORIGIN'] !== $expectedOrigin) {
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
                    'ip_hash' => substr(hash_hmac('sha256', ($_SERVER['REMOTE_ADDR'] ?? ''), (defined('SECRET_SALT') ? SECRET_SALT : 'tvlw_salt')), 0, 12),
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
            // INFRA13-001: Use SERVER_NAME (not HTTP_HOST) — HTTP_HOST is client-controlled.
            // Consistent with the Origin check above (line ~37).
            if (!empty($parsed['host']) && $parsed['host'] !== ($_SERVER['SERVER_NAME'] ?? '')) {
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
