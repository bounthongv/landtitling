<?php
// API v1: auth — citizen phone-OTP (dev stub) and officer password login
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';
require_once __DIR__ . '/../../lib/otp.php';
require_method('POST');
$body = read_json_body();
$cfg = require __DIR__ . '/../../lib/config.php';

$action = $body['action'] ?? '';

if ($action === 'otp/request') {
    // Citizen OTP login — issue a signed code (stateless; see lib/otp.php)
    $phone = $body['phone'] ?? '';
    $err = collect_errors([
        'phone' => validate_required($phone, 'phone') ?: validate_phone($phone, 'phone'),
    ]);
    reject_on_errors($err);

    $pdo = db_connect();
    // Upsert a citizen user by phone
    $stmt = $pdo->prepare("SELECT id, role FROM users WHERE phone = :phone AND is_active = TRUE");
    $stmt->execute([':phone' => $phone]);
    $user = $stmt->fetch();
    if (!$user) {
        $stmt = $pdo->prepare("INSERT INTO users (role, phone, is_active) VALUES ('citizen', :phone, TRUE) RETURNING id, role");
        $stmt->execute([':phone' => $phone]);
        $user = $stmt->fetch();
    }

    $otp = otp_issue($phone, $cfg['jwt_secret']);
    // DEV STUB: real deployment delivers the SMS inside otp_send_sms().
    otp_send_sms($phone, 'Your land fee code is ' . $otp['code']);

    // Never echo the code back unless OTP_DEBUG=1 is explicitly set.
    $data = ['otp_token' => $otp['token'], 'expires_at' => $otp['expires_at']];
    if (!empty($cfg['otp_debug'])) {
        $data['debug_code'] = $otp['code'];
        json_ok($data, 200, ['hint' => 'SMS gateway not wired; code returned for development']);
    }
    json_ok($data);
}

if ($action === 'otp/verify') {
    $phone = $body['phone'] ?? '';
    $code  = $body['code'] ?? '';
    $token = $body['otp_token'] ?? '';
    $err = collect_errors([
        'phone' => validate_required($phone, 'phone') ?: validate_phone($phone, 'phone'),
        'code'  => validate_required($code, 'code'),
        'otp_token' => validate_required($token, 'otp token'),
    ]);
    reject_on_errors($err);

    $res = otp_verify($phone, $code, $token, $cfg['jwt_secret']);
    if (!$res['ok']) {
        json_error('Invalid or expired code', 401, 'invalid_otp');
    }

    $pdo = db_connect();
    $stmt = $pdo->prepare("SELECT id, role FROM users WHERE phone = :phone AND is_active = TRUE");
    $stmt->execute([':phone' => $phone]);
    $user = $stmt->fetch();
    if (!$user) {
        json_error('Phone not registered', 404, 'not_found');
    }
    json_ok(['token' => auth_issue_token($user), 'user' => ['id' => (int)$user['id'], 'role' => $user['role']]]);
}

if ($action === 'login') {
    // Officer login (username + password)
    $username = $body['username'] ?? '';
    $password = $body['password'] ?? '';
    $err = collect_errors([
        'username' => validate_required($username, 'username'),
        'password' => validate_required($password, 'password'),
    ]);
    reject_on_errors($err);

    $pdo = db_connect();
    $stmt = $pdo->prepare("SELECT id, role, password_hash FROM users WHERE username = :username AND is_active = TRUE");
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        json_error('Invalid credentials', 401, 'invalid_credentials');
    }
    $token = auth_issue_token($user);
    json_ok(['token' => $token, 'user' => ['id' => (int)$user['id'], 'role' => $user['role']]]);
}

json_error('Unknown auth action', 400, 'bad_action');