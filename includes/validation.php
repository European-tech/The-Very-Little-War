<?php
function validateLogin($login) {
    return preg_match('/^[a-zA-Z0-9_]{' . LOGIN_MIN_LENGTH . ',' . LOGIN_MAX_LENGTH . '}$/', $login);
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        && mb_strlen($email) <= (defined('EMAIL_MAX_LENGTH') ? EMAIL_MAX_LENGTH : 254);
}

/**
 * MEDIUM-027 / LOW-005: Shared password validation.
 * Returns an array of error strings (empty array = valid).
 *
 * @param string $password      The proposed password.
 * @param string $confirm       Optional confirmation password to match against.
 * @return string[]             List of validation errors (empty = OK).
 */
function validatePassword($password, $confirm = null) {
    $errors = [];
    $maxLen = defined('PASSWORD_BCRYPT_MAX_LENGTH') ? PASSWORD_BCRYPT_MAX_LENGTH : 72;
    $minLen = defined('PASSWORD_MIN_LENGTH') ? PASSWORD_MIN_LENGTH : 8;

    // REG-M-005 / H-019: Use strlen() (byte count) not mb_strlen() (character count) to correctly
    // enforce bcrypt's 72-byte hard limit. A password of 36 × "é" (2 bytes each in UTF-8) is
    // 72 bytes but only 36 characters — mb_strlen() would accept it and bcrypt would silently
    // truncate any additional bytes, making longer passwords weaker than users expect.
    if (strlen($password) > $maxLen) {
        $errors[] = 'Le mot de passe est trop long (' . $maxLen . ' caractères max).';
    } elseif (strlen($password) < $minLen) {
        $errors[] = 'Le mot de passe doit contenir au moins ' . $minLen . ' caractères.';
    }

    if ($confirm !== null && $password !== $confirm) {
        $errors[] = 'Les deux mots de passe sont différents.';
    }

    return $errors;
}

function validatePositiveInt($value) {
    return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
}

function validateRange($value, $min, $max) {
    $val = filter_var($value, FILTER_VALIDATE_INT);
    return $val !== false && $val >= $min && $val <= $max;
}

function sanitizeOutput($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}
