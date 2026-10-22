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
    // Citizen OTP login — issue a signed code by EMAIL (the SMS channel was dropped:
    // no gateway available). Stateless: see lib/otp.php for why sessions can't work here.
    $email = trim((string)($body['email'] ?? ''));
    if ($email === '') {
        $email = trim((string)($body['phone'] ?? ''));   // tolerate the old field name
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('Enter a valid email address', 400, 'invalid_email');
    }

    $pdo = db_connect();
    // Upsert a citizen user by email. is_active is respected: a deactivated account
    // must not be able to log in by requesting a new code.
    $stmt = $pdo->prepare("SELECT id, role, is_active FROM users WHERE lower(email) = lower(:email)");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();
    if ($user && !$user['is_active']) {
        json_error('This account has been deactivated. Contact the land office.', 403, 'inactive');
    }
    if (!$user) {
        // users.phone is NOT NULL, so self-registered citizens get a placeholder phone.
        $placeholder = '+8569' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        try {
            $stmt = $pdo->prepare("INSERT INTO users (role, phone, email, is_active)
                VALUES ('citizen', :phone, :email, TRUE) RETURNING id, role");
            $stmt->execute([':phone' => $placeholder, ':email' => $email]);
            $user = $stmt->fetch();
        } catch (PDOException $e) {
            // Unique violation on email => a concurrent request created it first.
            if ($e->getCode() === '23505') {
                $stmt = $pdo->prepare("SELECT id, role, is_active FROM users WHERE lower(email) = lower(:email)");
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch();
            } else {
                json_error('Could not start sign-in: ' . $e->getMessage(), 500, 'signup_failed');
            }
        }
    }

    $otp = otp_issue($email, $cfg['jwt_secret']);
    $sent = otp_send_email($email, $otp['code']);

    // Never echo the code back unless OTP_DEBUG=1 is explicitly set.
    $data = ['otp_token' => $otp['token'], 'expires_at' => $otp['expires_at'], 'sent' => $sent];
    if (!empty($cfg['otp_debug'])) {
        $data['debug_code'] = $otp['code'];
        json_ok($data, 200, ['hint' => 'OTP_DEBUG on; code returned for development']);
    }
    json_ok($data);
}

if ($action === 'otp/verify') {
    $email = trim((string)($body['email'] ?? ''));
    if ($email === '') {
        $email = trim((string)($body['phone'] ?? ''));
    }
    $code  = $body['code'] ?? '';
    $token = $body['otp_token'] ?? '';
    $err = collect_errors([
        'email'     => filter_var($email, FILTER_VALIDATE_EMAIL) ? null : 'valid email',
        'code'      => validate_required($code, 'code'),
        'otp_token' => validate_required($token, 'otp token'),
    ]);
    reject_on_errors($err);

    $res = otp_verify($email, $code, $token, $cfg['jwt_secret']);
    if (!$res['ok']) {
        json_error('Invalid or expired code', 401, 'invalid_otp');
    }

    $pdo = db_connect();
    $stmt = $pdo->prepare("SELECT id, role, is_active FROM users WHERE lower(email) = lower(:email)");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();
    if (!$user) {
        json_error('Email not registered', 404, 'not_found');
    }
    if (!$user['is_active']) {
        json_error('This account has been deactivated. Contact the land office.', 403, 'inactive');
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
    $stmt = $pdo->prepare("SELECT id, username, role, level, district_id, password_hash FROM users WHERE username = :username AND is_active = TRUE");
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        json_error('Invalid credentials', 401, 'invalid_credentials');
    }
    // Resolve the district/province names for the UI (sidebar footer, etc.)
    $names = ['district_name' => null, 'province_name' => null];
    if (($user['level'] ?? 'district') === 'district' && $user['district_id']) {
        $dst = $pdo->prepare("SELECT d.name_en AS district_name, p.name_en AS province_name
                              FROM districts d LEFT JOIN provinces p ON p.id = d.province_id
                              WHERE d.id = :id");
        $dst->execute([':id' => $user['district_id']]);
        if ($dn = $dst->fetch()) {
            $names['district_name'] = $dn['district_name'];
            $names['province_name'] = $dn['province_name'];
        }
    } else {
        $pst = $pdo->prepare("SELECT name_en FROM provinces WHERE code = '13' LIMIT 1");
        $pst->execute();
        if ($pn = $pst->fetch()) {
            $names['province_name'] = $pn['name_en'];
        }
    }
    $user['level'] = $user['level'] ?? 'district';
    $token = auth_issue_token($user);
    json_ok(['token' => $token, 'user' => [
        'id' => (int)$user['id'], 'role' => $user['role'], 'level' => $user['level'],
        'district_id' => isset($user['district_id']) ? (int)$user['district_id'] : null,
        'username' => $user['username'],
        'district_name' => $names['district_name'], 'province_name' => $names['province_name'],
    ]]);
}

json_error('Unknown auth action', 400, 'bad_action');