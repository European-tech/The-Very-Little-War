<?php
/**
 * CSP nonce generation.
 * Include early (before any output) to generate a per-request nonce.
 */

$GLOBALS['csp_nonce'] = base64_encode(random_bytes(16));

function cspNonce() {
    return $GLOBALS['csp_nonce'];
}

function cspScriptTag() {
    return '<script nonce="' . htmlspecialchars(cspNonce(), ENT_QUOTES, 'UTF-8') . '">';
}
