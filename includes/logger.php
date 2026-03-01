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
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $contextStr = !empty($context) ? ' | ' . json_encode($context) : '';
    $line = "[$timestamp] [$levelName] [$category] [$login@$ip] $message$contextStr\n";

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
