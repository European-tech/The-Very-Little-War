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
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
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

    // M-001: Use fopen + LOCK_EX for atomic read-check-write to eliminate TOCTOU race.
    // Previously, file_get_contents (no lock) + file_put_contents (LOCK_EX) left a window
    // where two concurrent requests could both read "not-yet-limited" state and both proceed.
    $file = $dir . '/' . hash('sha256', json_encode([$identifier, $action])) . '.json';
    $now = time();
    $allowed = false;

    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        if (function_exists('logError')) {
            logError('RATE_LIMITER', 'fopen failed', ['file' => $file]);
        }
        return false; // fail closed
    }

    if (flock($fp, LOCK_EX)) {
        $raw = stream_get_contents($fp);
        $data = json_decode($raw, true);
        $attempts = [];
        if (is_array($data)) {
            // Keep only attempts within the window
            $attempts = array_filter($data, function($t) use ($now, $windowSeconds) {
                return ($now - $t) < $windowSeconds;
            });
        }

        if (count($attempts) < $maxAttempts) {
            $attempts[] = $now;
            rewind($fp);
            ftruncate($fp, 0);
            $written = fwrite($fp, json_encode(array_values($attempts)));
            if ($written === false) {
                if (function_exists('logError')) {
                    // INFRA-SEC-M2: Correct error label — this is an fwrite failure, not an flock failure.
                    logError('RATE_LIMITER', 'fwrite failed', ['file' => $file]);
                }
                flock($fp, LOCK_UN);
                fclose($fp);
                return false; // fail closed
            }
            $allowed = true;
        }
        // else: rate limited — do not write, $allowed stays false

        flock($fp, LOCK_UN);
    } else {
        // Could not acquire lock — fail closed
        if (function_exists('logError')) {
            logError('RATE_LIMITER', 'flock failed', ['file' => $file]);
        }
        fclose($fp);
        return false;
    }

    fclose($fp);
    return $allowed;
}

function rateLimitRemaining($identifier, $action, $maxAttempts, $windowSeconds) {
    $file = RATE_LIMIT_DIR . '/' . hash('sha256', json_encode([$identifier, $action])) . '.json';
    $now = time();
    $attempts = [];

    // INFRA-SEC-MEDIUM-003: Use fopen + LOCK_SH so reads cannot observe partial/corrupt data
    // written by a concurrent rateLimitCheck() call that holds LOCK_EX.
    if (file_exists($file)) {
        $fp = @fopen($file, 'r');
        if ($fp !== false) {
            if (flock($fp, LOCK_SH)) {
                $raw = stream_get_contents($fp);
                flock($fp, LOCK_UN);
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    $attempts = array_filter($data, function($t) use ($now, $windowSeconds) {
                        return ($now - $t) < $windowSeconds;
                    });
                }
            }
            fclose($fp);
        }
    }

    return max(0, $maxAttempts - count($attempts));
}
