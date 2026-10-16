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
 * Deliver an OTP by SMS. This is the seam where the real gateway goes (BCEL/LaoTel/etc).
 * Until one is configured it records nothing and silently no-ops — which means
 * otp/request will not actually reach a handset. That is intentional and loud in the
 * docs: a real SMS provider is required before production use.
 */
function otp_send_sms(string $phone, string $message): void
{
    // TODO: plug in the provider SDK here. Never log OTPs to the application log.
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