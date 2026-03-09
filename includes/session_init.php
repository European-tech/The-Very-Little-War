<?php
/**
 * Centralized session initialization with security hardening.
 * ALL pages must use this instead of bare session_start().
 */
require_once(__DIR__ . '/config.php');

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_path', '/'); // INFRA-TEMPLATES-M2: explicit path prevents path-scope cookie leakage
    // AUTH-MEDIUM: Detect HTTPS via direct connection OR trusted TLS-terminating proxy.
    // TRUSTED_PROXY_IPS is defined in config.php (empty array = no proxy assumed).
    $trustedProxyIps = defined('TRUSTED_PROXY_IPS') ? TRUSTED_PROXY_IPS : [];
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    $behindTrustedProxy = !empty($trustedProxyIps) && in_array($remoteAddr, $trustedProxyIps, true);
    $isHttps = !empty($_SERVER['HTTPS'])
        || ($behindTrustedProxy && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    ini_set('session.cookie_secure', $isHttps ? 1 : 0);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Strict'); // MEDIUM-029: Strict prevents cross-site request forgery via cookies
    ini_set('session.gc_maxlifetime', SESSION_IDLE_TIMEOUT);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_trans_sid', 0);
    // INFRA-SEC-HIGH-001: Only set session name if not already customized by calling page
    // (e.g. moderation pages pre-set 'TVLW_MOD' before including session_init.php).
    if (session_name() === 'PHPSESSID') {
        session_name('TVLW_SESSION');
    }
    session_start();
}
