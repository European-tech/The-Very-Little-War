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
        // INFRA-SEC-M1: Strip surrounding quotes OR inline comments, but not both.
        // Quoted values may legitimately contain " #" (e.g., passwords); strip their
        // quotes and preserve the content intact. Unquoted values get comment-stripped.
        $firstChar = strlen($value) > 0 ? $value[0] : '';
        if (($firstChar === '"' || $firstChar === "'") && strlen($value) >= 2 && substr($value, -1) === $firstChar) {
            $value = substr($value, 1, -1);
        } else {
            if (($commentPos = strpos($value, ' #')) !== false) {
                $value = trim(substr($value, 0, $commentPos));
            }
        }
        if (getenv($name) === false) putenv("$name=$value");
    }
}
