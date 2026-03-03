<?php
/**
 * Centralized session initialization with security hardening.
 * ALL pages must use this instead of bare session_start().
 */
require_once(__DIR__ . '/config.php');

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', !empty($_SERVER['HTTPS']) ? 1 : 0);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', SESSION_IDLE_TIMEOUT);
    session_start();
}
