<?php
// lib/phone.php — Lao mobile number normalisation.
//
// Shared by citizen sign-in (account identity) and the parcel-claim endpoint
// (an owner's recorded phone vs. the signed-in citizen's phone), so the same
// number written three different ways always compares equal.

/**
 * Normalise a Lao mobile number to digits + country code with no trunk zero:
 *   "020 5555 1234"     -> 8562055551234
 *   "+856 20 5555 1234" -> 8562055551234
 *   "02055551234"       -> 8562055551234
 *   "55551234"          -> 8562055551234 (bare subscriber part)
 * Returns null when the number is not a usable Lao mobile number.
 *
 * The trunk zero is the part that is easy to get wrong: the same citizen
 * writing their number three different ways must land on one account.
 *
 * Lengths follow the plan in force since the 2011 migration to 10-digit
 * mobile numbers: 020 + 8 digits. Numbers issued before it (020 + 7) are
 * still accepted, as are 030/080 wireless numbers (7 digits) — rejecting
 * those would lock out working SIMs.
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
    // Drop the national trunk zero (tolerates "+856 0…" and "00 20…").
    $digits = ltrim($digits, '0');
    if ($digits === '') {
        return null;
    }
    // 020 mobile: current plan 8 digits, legacy 7. 030/080 wireless: 7.
    if (preg_match('/^20\d{7,8}$/', $digits) || preg_match('/^[38]0\d{7}$/', $digits)) {
        return '856' . $digits;
    }
    // A bare subscriber part ("55551234"): only 020 numbers have 8 digits, so
    // read it as one. Citizens routinely write their number without the prefix.
    if (preg_match('/^[1-9]\d{7}$/', $digits)) {
        return '85620' . $digits;
    }
    return null;
}
