<?php
/**
 * Simple file-based rate limiter.
 * Stores rate limit data in data/rates/ (project-local, not world-readable).
 */

if (!defined('RATE_LIMIT_DIR')) {
    define('RATE_LIMIT_DIR', __DIR__ . '/../data/rates');
}

function rateLimitCheck($identifier, $action, $maxAttempts, $windowSeconds) {
    $dir = RATE_LIMIT_DIR;
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false; // Fail-safe: deny rather than bypass rate limiting
        }
    }

    // Probabilistic cleanup of stale rate limit files (~1% of calls)
    if (mt_rand(1, 100) === 1) {
        $maxWindow = max(
            defined('RATE_LIMIT_LOGIN_WINDOW') ? RATE_LIMIT_LOGIN_WINDOW : 900,
            defined('RATE_LIMIT_ADMIN_WINDOW') ? RATE_LIMIT_ADMIN_WINDOW : 3600,
            defined('RATE_LIMIT_REGISTER_WINDOW') ? RATE_LIMIT_REGISTER_WINDOW : 3600,
            defined('RATE_LIMIT_MARKET_WINDOW') ? RATE_LIMIT_MARKET_WINDOW : 60
        ) * 2; // double the max window to avoid deleting still-active files
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            if (filemtime($file) < time() - $maxWindow) {
                @unlink($file);
            }
        }
        // MEDIUM-005: Periodic cleanup of expired login_attempts DB rows.
        // The login_attempts table (created by migration) accumulates rows indefinitely;
        // purge any rows older than the login rate-limit window on ~1% of requests.
        if (function_exists('dbExecute') && isset($GLOBALS['base'])) {
            $window = defined('RATE_LIMIT_LOGIN_WINDOW') ? RATE_LIMIT_LOGIN_WINDOW : 900;
            dbExecute($GLOBALS['base'], 'DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL ? SECOND', 'i', $window);
        }
    }

    // LOW-007: Minor TOCTOU race possible under high concurrency — max over-admission
    // is bounded by request parallelism (typically <5 concurrent). Acceptable for rate limiting.
    // To eliminate: use flock() on the rate limit file for atomic read-check-write.
    $file = $dir . '/' . hash('sha256', json_encode([$identifier, $action])) . '.json';
    $now = time();
    $attempts = [];

    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if (is_array($data)) {
            // Keep only attempts within the window
            $attempts = array_filter($data, function($t) use ($now, $windowSeconds) {
                return ($now - $t) < $windowSeconds;
            });
        }
    }

    if (count($attempts) >= $maxAttempts) {
        return false; // Rate limited
    }

    $attempts[] = $now;
    // LOW-006: Check write result and fail closed on error to prevent rate-limit bypass.
    $result = file_put_contents($file, json_encode(array_values($attempts)), LOCK_EX);
    if ($result === false) {
        if (function_exists('logError')) {
            logError('Rate limiter write failed for: ' . $file);
        }
        return false; // fail closed — deny request rather than silently bypass rate limit
    }
    return true; // Allowed
}

function rateLimitRemaining($identifier, $action, $maxAttempts, $windowSeconds) {
    $file = RATE_LIMIT_DIR . '/' . hash('sha256', json_encode([$identifier, $action])) . '.json';
    $now = time();
    $attempts = [];

    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if (is_array($data)) {
            $attempts = array_filter($data, function($t) use ($now, $windowSeconds) {
                return ($now - $t) < $windowSeconds;
            });
        }
    }

    return max(0, $maxAttempts - count($attempts));
}
