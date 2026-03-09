<?php
/**
 * Simple logging system for TVLW.
 * Logs to files in the logs/ directory.
 */

define('LOG_DIR', __DIR__ . '/../logs');
define('LOG_LEVEL_DEBUG', 0);
define('LOG_LEVEL_INFO', 1);
define('LOG_LEVEL_WARN', 2);
define('LOG_LEVEL_ERROR', 3);

// Minimum log level to write (INFO for production)
define('MIN_LOG_LEVEL', LOG_LEVEL_INFO);

/**
 * INFRA-SEC-MEDIUM-001: Sanitize a user-controlled value before writing to a log line.
 * Replaces literal \r and \n characters with their escaped representations so that
 * a crafted login name or context value cannot inject fake log entries.
 */
function sanitizeLogValue($v) {
    return str_replace(["\r", "\n"], ['\\r', '\\n'], (string)$v);
}

function gameLog($level, $category, $message, $context = []) {
    if ($level < MIN_LOG_LEVEL) return;

    if (!is_dir(LOG_DIR)) {
        mkdir(LOG_DIR, 0755, true);
    }

    $levelNames = ['DEBUG', 'INFO', 'WARN', 'ERROR'];
    $levelName = $levelNames[$level] ?? 'UNKNOWN';
    $timestamp = date('Y-m-d H:i:s');
    // INFRA-SEC-MEDIUM-001: sanitize $login — it comes from $_SESSION which is user-controlled.
    $login = sanitizeLogValue($_SESSION['login'] ?? 'anonymous');
    $rawIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $salt = defined('SECRET_SALT') ? SECRET_SALT : 'tvlw_salt';
    $hashedIp = ($rawIp !== 'unknown') ? substr(hash_hmac('sha256', $rawIp, $salt), 0, 12) : 'unknown';

    // INFRA-SEC-MEDIUM-001: Strip newlines from all user-controlled fields to prevent log injection.
    // sanitizeLogValue() is applied to each context value individually so structured data is preserved.
    $safeContext = [];
    foreach ($context as $k => $v) {
        $safeContext[sanitizeLogValue($k)] = sanitizeLogValue($v);
    }
    $contextStr = !empty($safeContext) ? ' | ' . json_encode($safeContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
    $safeCategory = sanitizeLogValue($category);
    $safeMessage = sanitizeLogValue($message);
    $line = "[$timestamp] [$levelName] [$safeCategory] [$login@$hashedIp] $safeMessage$contextStr\n";

    $filename = LOG_DIR . '/' . date('Y-m-d') . '.log';
    file_put_contents($filename, $line, FILE_APPEND | LOCK_EX);
}

function logInfo($category, $message, $context = []) {
    gameLog(LOG_LEVEL_INFO, $category, $message, $context);
}

function logWarn($category, $message, $context = []) {
    gameLog(LOG_LEVEL_WARN, $category, $message, $context);
}

function logError($category, $message, $context = []) {
    gameLog(LOG_LEVEL_ERROR, $category, $message, $context);
}

function logDebug($category, $message, $context = []) {
    gameLog(LOG_LEVEL_DEBUG, $category, $message, $context);
}
