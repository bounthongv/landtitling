<?php
// API v1: auth — citizen phone-OTP (dev stub) and officer password login
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';
require_method('POST');
$body = read_json_body();

$action = $body['action'] ?? '';

if ($action === 'otp/request') {
    // Citizen OTP login — request a code
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

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT); // dev stub
    // Store the code keyed to the user in session for verification
    session_start();
    $_SESSION['otp'][$user['id']] = ['code' => $code, 'exp' => time() + 300];

    // DEV STUB: in real deployment, send $code via SMS gateway here.
    json_ok(['debug_code' => $code], 200, ['hint' => 'SMS gateway not wired; code returned for development']);
}

if ($action === 'otp/verify') {
    $phone = $body['phone'] ?? '';
    $code = $body['code'] ?? '';
    $err = collect_errors([
        'phone' => validate_required($phone, 'phone') ?: validate_phone($phone, 'phone'),
        'code'  => validate_required($code, 'code'),
    ]);
    reject_on_errors($err);

    $pdo = db_connect();
    $stmt = $pdo->prepare("SELECT id, role FROM users WHERE phone = :phone AND is_active = TRUE");
    $stmt->execute([':phone' => $phone]);
    $user = $stmt->fetch();
    if (!$user) {
        json_error('Phone not registered', 404, 'not_found');
    }
    session_start();
    $stored = $_SESSION['otp'][$user['id']] ?? null;
    if (!$stored || $stored['exp'] < time() || !hash_equals((string)$stored['code'], (string)$code)) {
        json_error('Invalid or expired code', 401, 'invalid_otp');
    }
    unset($_SESSION['otp'][$user['id']]);
    $token = auth_issue_token($user);
    json_ok(['token' => $token, 'user' => ['id' => (int)$user['id'], 'role' => $user['role']]]);
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