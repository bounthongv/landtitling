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
        'sub'         => (int)$user['id'],
        'role'        => $user['role'],
        'level'       => $user['level'] ?? 'district',
        'district_id' => isset($user['district_id']) && $user['district_id'] !== null ? (int)$user['district_id'] : null,
        'is_super'    => !empty($user['is_super']),
        'iat'         => $now,
        'exp'         => $now + $cfg['jwt_ttl'],
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

/**
 * Staff access scope for a decoded JWT payload.
 *
 * Returns ['district_id' => int|null, 'can_write' => bool]:
 *  - district-level staff: district_id is set; only district admin/officer
 *    may write records (managers are read-only).
 *  - province-level staff: district_id null (whole province); province users
 *    are read-only for records — data entry happens at district level.
 */
function staff_scope(array $payload) {
    $level = $payload['level'] ?? 'district';
    $district_id = isset($payload['district_id']) ? (int)$payload['district_id'] : 0;
    // Super admin: no district scoping, full write everywhere.
    $is_super = !empty($payload['is_super']);
    $can_write = $is_super
        || ($level === 'district' && in_array($payload['role'] ?? '', ['admin', 'officer'], true));
    return [
        'district_id' => !$is_super && $level === 'district' && $district_id > 0 ? $district_id : null,
        'can_write'   => $can_write,
    ];
}

/**
 * Require that the caller is staff who may write records (district admin/officer).
 * Sends 403 otherwise. Returns the staff_scope() array.
 */
function require_staff_write(array $payload) {
    $scope = staff_scope($payload);
    if (!$scope['can_write']) {
        json_error('You do not have permission to modify records', 403, 'read_only');
    }
    return $scope;
}

/**
 * District filter clause + params for a query on the given table alias.
 * Province-level staff (district_id null) get an empty clause (no filter).
 */
function district_filter_clause(array $payload, string $alias = 'p') {
    $scope = staff_scope($payload);
    if ($scope['district_id'] === null) {
        return ['clause' => '', 'params' => []];
    }
    return [
        'clause' => $alias . ".district_id = :" . $alias . "_district",
        'params' => [$alias . '_district' => $scope['district_id']],
    ];
}