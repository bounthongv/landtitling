<?php
// lib/phone.php — Lao mobile number normalisation.
//
// Shared by citizen sign-in (account identity) and the parcel-claim endpoint
// (an owner's recorded phone vs. the signed-in citizen's phone), so the same
// number written three different ways always compares equal.

/**
 * Normalise a Lao mobile number to digits + country code with no trunk zero:
 *   "020 555 1234"      -> 856205551234
 *   "+856 20 555 1234"  -> 856205551234
 *   "0205551234"        -> 856205551234
 * Returns null when the number is not a usable Lao mobile number.
 *
 * The trunk zero is the part that is easy to get wrong: the same citizen
 * writing their number three different ways must land on one account.
 */
function citizen_normalise_phone($raw): ?string
{
    $digits = preg_replace('/\D+/', '', (string)$raw);
    if ($digits === '' || $digits === null) {
        return null;
    }
    // Country code already present: keep the national significant number.
    if (strpos($digits, '856') === 0 && strlen($digits) > 3) {
        $digits = substr($digits, 3);
    }
    // Drop the national trunk zero.
    if (strlen($digits) > 1 && $digits[0] === '0') {
        $digits = substr($digits, 1);
    }
    // Lao mobile: operator prefix 20/30/80 + 7 digits.
    if (!preg_match('/^[238]0\d{7}$/', $digits)) {
        return null;
    }
    return '856' . $digits;
}
