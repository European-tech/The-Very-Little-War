<?php
function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;
        $name = trim($parts[0]);
        $value = trim($parts[1] ?? '');
        // INFRA-SEC-M1: Strip inline comments (e.g., "value # comment" → "value")
        if (($commentPos = strpos($value, ' #')) !== false) {
            $value = trim(substr($value, 0, $commentPos));
        }
        // Strip surrounding quotes after comment removal
        $value = trim($value, "\"'");
        if (getenv($name) === false) putenv("$name=$value");
    }
}
