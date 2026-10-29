<?php
/**
 * Minimal validation helpers. Each returns an error string or null.
 */

function validate_required($value, $label) {
    return ($value === null || $value === '' || $value === []) ? "$label is required" : null;
}

function validate_int($value, $label) {
    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
        return "$label must be an integer";
    }
    return null;
}

function validate_positive($value, $label) {
    if (!is_numeric($value) || $value <= 0) {
        return "$label must be a positive number";
    }
    return null;
}

function validate_enum($value, array $allowed, $label) {
    if (!in_array($value, $allowed, true)) {
        return "$label must be one of: " . implode(', ', $allowed);
    }
    return null;
}

function validate_phone($value, $label) {
    if (!preg_match('/^\+?[0-9]{8,15}$/', $value)) {
        return "$label is not a valid phone number";
    }
    return null;
}

/** Escape LIKE/ILIKE metacharacters so user input is matched literally. */
function like_escape($value) {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string)$value);
}

/** Last 8 digits of a phone number, or '' when the input has fewer than 8.
 *  Lao mobile numbers are written 020x-xxx-xxx, 20x-xxx-xxx or bare, and the
 *  +856 country code may be prefixed — all share the same trailing 8 digits. */
function phone_tail8($value) {
    $digits = preg_replace('/\D+/', '', (string)$value);
    return strlen($digits) >= 8 ? substr($digits, -8) : '';
}

function validate_string($value, $label, $max = 255) {
    if (is_string($value) && strlen($value) > $max) {
        return "$label must be at most $max characters";
    }
    return null;
}

/**
 * Run an array of [field, error] pairs against $errors collector.
 * @param array $rules  list of [field, validator_result] where result is error string or null
 * @return array        assoc field => error for failures
 */
function collect_errors(array $rules) {
    $errors = [];
    foreach ($rules as $field => $err) {
        if ($err !== null) {
            $errors[$field] = $err;
        }
    }
    return $errors;
}

/**
 * If $errors is non-empty, emit a 422 with field errors and exit.
 */
function reject_on_errors(array $errors) {
    if ($errors) {
        json_error('Validation failed', 422, 'validation_failed', $errors);
    }
}