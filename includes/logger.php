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

function gameLog($level, $category, $message, $context = []) {
    if ($level < MIN_LOG_LEVEL) return;

    if (!is_dir(LOG_DIR)) {
        mkdir(LOG_DIR, 0755, true);
    }

    $levelNames = ['DEBUG', 'INFO', 'WARN', 'ERROR'];
    $levelName = $levelNames[$level] ?? 'UNKNOWN';
    $timestamp = date('Y-m-d H:i:s');
    $login = $_SESSION['login'] ?? 'anonymous';
    $rawIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $salt = defined('SECRET_SALT') ? SECRET_SALT : 'tvlw_salt';
    $hashedIp = ($rawIp !== 'unknown') ? substr(hash('sha256', $rawIp . $salt), 0, 12) : 'unknown';

    $contextStr = !empty($context) ? ' | ' . json_encode($context) : '';
    $safeCategory = str_replace(["\r", "\n"], ' ', $category);
    $safeMessage = str_replace(["\r", "\n"], ' ', $message);
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
