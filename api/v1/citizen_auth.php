<?php
// API v1: citizen_auth.php — citizen sign-in by phone number.
//
// The phone number is the identifier: nearly everyone has a mobile phone and
// most do not use email. Nothing is sent anywhere and no code is typed — the
// first time a number is used the account is created, so signing up and signing
// in are the same call. See docs/9-citizen-app-api-contract.md §1.
//
// Accepted consequence of phone-only sign-in: anyone who knows a citizen's
// number can sign in as them. Every attempt is therefore written to the audit
// log, so a guessed-number sweep is visible after the fact.
//
// This file is deliberately separate from api/v1/auth.php (officer password
// login + email OTP); that file has another owner.
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';
require_once __DIR__ . '/../../lib/fee.php';
require_once __DIR__ . '/../../lib/phone.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405, 'method_not_allowed');
}

$body   = read_json_body();
$action = (string)($body['action'] ?? '');
if ($action !== 'phone/signin') {
    json_error('Unknown auth action', 400, 'bad_action');
}

$pdo = db_connect();

$phoneIn = trim((string)($body['phone'] ?? ''));
$phone   = citizen_normalise_phone($phoneIn);
if ($phone === null) {
    json_error('That is not a usable Lao mobile number', 400, 'invalid_phone');
}

$stmt = $pdo->prepare("SELECT id, role, level, district_id, is_super, is_active
    FROM users WHERE phone = :p");
$stmt->execute([':p' => $phone]);
$user = $stmt->fetch();

$created = false;
if (!$user) {
    $ins = $pdo->prepare("INSERT INTO users (role, phone, email, is_active)
        VALUES ('citizen', :p, NULL, TRUE) RETURNING id");
    $ins->execute([':p' => $phone]);
    $user = [
        'id' => (int)$ins->fetch()['id'], 'role' => 'citizen',
        'level' => 'district', 'district_id' => null, 'is_super' => false,
    ];
    $created = true;
} elseif (empty($user['is_active'])) {
    fee_audit($pdo, null, 'citizen_signin_refused', 'user', (int)$user['id'],
        ['phone' => $phone, 'reason' => 'inactive']);
    json_error('This account is deactivated', 403, 'inactive');
}

$token = auth_issue_token($user);

fee_audit(
    $pdo,
    (int)$user['id'],
    $created ? 'citizen_signup' : 'citizen_signin',
    'user',
    (int)$user['id'],
    ['phone' => $phone, 'created' => $created]
);

json_ok([
    'token'   => $token,
    'user'    => ['id' => (int)$user['id'], 'role' => $user['role']],
    // Echo the number as the citizen typed it — it is what the app shows back.
    'phone'   => $phoneIn !== '' ? $phoneIn : $phone,
    'created' => $created,
]);
