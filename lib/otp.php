<?php
/**
 * Stateless one-time-passcode tokens.
 *
 * The previous implementation stored the code in the API's PHP session, which could
 * never work: the citizen pages call the API server-side (no cookie round-trip), so
 * otp/request and otp/verify always landed in different sessions and verification
 * failed every time. These tokens are HMAC-signed instead, so they travel with the
 * request and need no shared storage.
 *
 * token = base64url(phone|expires_at|code) . '.' . hmac_sha256(payload)
 *
 * The token is now keyed on the email address rather than a phone number, since
 * SMS was dropped for lack of a gateway.
 */

function otp_issue(string $phone, string $secret, int $ttl = 300): array
{
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $exp  = time() + $ttl;
    $payload = $phone . '|' . $exp . '|' . $code;
    $sig = hash_hmac('sha256', $payload, $secret);
    return [
        'code'        => $code,
        'expires_at'  => $exp,
        'token'       => rtrim(strtr(base64_encode($payload), '+/', '-_'), '=') . '.' . $sig,
    ];
}

/**
 * Deliver an OTP by email (the SMS channel was dropped: no gateway available).
 * Sending goes through mail_send(), which needs a system MTA and MAIL_FROM set.
 */
function otp_send_email(string $email, string $code): bool
{
    require_once __DIR__ . '/mail.php';
    $body = '<p>' . e_mail('Your land fee sign-in code') . '</p>'
          . '<p style="font-size:26px;font-weight:bold;letter-spacing:2px;">' . e_mail($code) . '</p>'
          . '<p>' . e_mail('This code expires in 5 minutes. If you did not request it, ignore this message.') . '</p>';
    return mail_send($email, 'Land Fee sign-in code', $body);
}

/** Local HTML escaper (keeps this lib free of the admin pages' e() helper). */
function e_mail($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/**
 * Verify a code against a token. Returns ['ok'=>bool, 'reason'=>string].
 * The signature is checked before anything else, so a tampered payload is rejected
 * without leaking which part was wrong.
 */
function otp_verify(string $phone, string $code, string $token, string $secret): array
{
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return ['ok' => false, 'reason' => 'bad_token'];
    }
    $raw = base64_decode(strtr($parts[0], '-_', '+/'), true);
    if ($raw === false) {
        return ['ok' => false, 'reason' => 'bad_token'];
    }
    $bits = explode('|', $raw);
    if (count($bits) !== 3) {
        return ['ok' => false, 'reason' => 'bad_token'];
    }
    [$tokPhone, $exp, $tokCode] = $bits;

    $expected = hash_hmac('sha256', $tokPhone . '|' . $exp . '|' . $tokCode, $secret);
    if (!hash_equals($expected, $parts[1])) {
        return ['ok' => false, 'reason' => 'bad_signature'];
    }
    if ((int)$exp < time()) {
        return ['ok' => false, 'reason' => 'expired'];
    }
    if ($tokPhone !== $phone) {
        return ['ok' => false, 'reason' => 'bad_phone'];
    }
    if (!hash_equals((string)$tokCode, (string)$code)) {
        return ['ok' => false, 'reason' => 'bad_code'];
    }
    return ['ok' => true, 'reason' => ''];
}