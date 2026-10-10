<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * Minimal HS256 JWT implementation (no external deps).
 * Token carries: sub (user id), role, iat, exp.
 */

function jwt_encode(array $payload, $secret) {
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $segments = [
        jwt_b64url(json_encode($header)),
        jwt_b64url(json_encode($payload)),
    ];
    $signing = implode('.', $segments);
    $signature = hash_hmac('sha256', $signing, $secret, true);
    $segments[] = jwt_b64url($signature);
    return implode('.', $segments);
}

function jwt_decode($token, $secret) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    list($h, $p, $s) = $parts;
    $signing = "$h.$p";
    $expected = jwt_b64url(hash_hmac('sha256', $signing, $secret, true));
    if (!hash_equals($expected, $s)) {
        return null;
    }
    $payload = json_decode(jwt_b64url_decode($p), true);
    if (!is_array($payload)) {
        return null;
    }
    // expiry check
    if (isset($payload['exp']) && time() > $payload['exp']) {
        return null;
    }
    return $payload;
}

function jwt_b64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function jwt_b64url_decode($data) {
    $pad = strlen($data) % 4;
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Issue a token for a user row.
 * @param array $user  users table row
 * @return string JWT
 */
function auth_issue_token(array $user) {
    $cfg = require __DIR__ . '/config.php';
    $now = time();
    $payload = [
        'sub'  => (int)$user['id'],
        'role' => $user['role'],
        'iat'  => $now,
        'exp'  => $now + $cfg['jwt_ttl'],
    ];
    return jwt_encode($payload, $cfg['jwt_secret']);
}

/**
 * Resolve Bearer token -> payload (user id + role) or null.
 */
function auth_current() {
    $cfg = require __DIR__ . '/config.php';
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
        return jwt_decode(trim($m[1]), $cfg['jwt_secret']);
    }
    return null;
}

/**
 * Require an authenticated user with an allowed role (or any if roles null).
 */
function require_auth(array $roles = null) {
    $payload = auth_current();
    if (!$payload) {
        json_error('Unauthorized', 401, 'unauthorized');
    }
    if ($roles !== null && !in_array($payload['role'], $roles, true)) {
        json_error('Forbidden', 403, 'forbidden');
    }
    return $payload;
}