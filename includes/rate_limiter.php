<?php
/**
 * Simple file-based rate limiter
 * Stores rate limit data in /tmp/tvlw_rates/
 */

if (!defined('RATE_LIMIT_DIR')) {
    define('RATE_LIMIT_DIR', '/tmp/tvlw_rates');
}

function rateLimitCheck($identifier, $action, $maxAttempts, $windowSeconds) {
    $dir = RATE_LIMIT_DIR;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $file = $dir . '/' . md5($identifier . '_' . $action) . '.json';
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
    file_put_contents($file, json_encode(array_values($attempts)), LOCK_EX);
    return true; // Allowed
}

function rateLimitRemaining($identifier, $action, $maxAttempts, $windowSeconds) {
    $file = RATE_LIMIT_DIR . '/' . md5($identifier . '_' . $action) . '.json';
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
