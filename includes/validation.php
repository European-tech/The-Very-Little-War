<?php
function validateLogin($login) {
    return preg_match('/^[a-zA-Z0-9_]{' . LOGIN_MIN_LENGTH . ',' . LOGIN_MAX_LENGTH . '}$/', $login);
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
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
