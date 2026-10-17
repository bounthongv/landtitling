<?php
// API v1: users.php — admin user management.
// Staff (admin/officer): full CRUD + password reset + activate/deactivate.
// Citizens: read-only, plus linking a citizen login to an owner record — that link
// is what makes a parcel visible in the citizen app (owners.citizen_user_id).
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';
require_once __DIR__ . '/../../lib/fee.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];
$me = require_auth(['admin']);

// ---- GET: owner candidates (for linking), staff, or citizens ----
if ($method === 'GET') {
    // Candidate owners to link to a citizen login. Officers usually know the parcel
    // number rather than the owner's name, so both are searchable.
    if ($_GET['candidates'] ?? '') {
        $q = trim((string)($_GET['q'] ?? ''));
        $sql = "SELECT o.id, o.full_name, o.id_number, o.phone, o.citizen_user_id,
                       (SELECT COUNT(*) FROM parcel_owners po WHERE po.owner_id = o.id) AS parcel_count,
                       string_agg(COALESCE(p.parcel_no, ''), ', ' ORDER BY p.parcel_no) AS parcels
                FROM owners o
                LEFT JOIN parcel_owners po ON po.owner_id = o.id
                LEFT JOIN parcels p ON p.id = po.parcel_id";
        $params = [];
        if ($q !== '') {
            $sql .= " WHERE (o.full_name ILIKE :q OR o.id_number ILIKE :q OR o.phone ILIKE :q OR p.parcel_no ILIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }
        $sql .= ' GROUP BY o.id ORDER BY o.full_name LIMIT 100';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        json_ok($st->fetchAll());
    }

    $kind = $_GET['kind'] ?? 'staff';
    $q = trim((string)($_GET['q'] ?? ''));

    if ($kind === 'citizens') {
        $where = ["u.role = 'citizen'"];
        $params = [];
        if ($q !== '') {
            $where[] = '(u.email ILIKE :q OR u.phone ILIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }
        $w = 'WHERE ' . implode(' AND ', $where);
        $st = $pdo->prepare("SELECT u.id, u.email, u.phone, u.is_active, u.created_at,
                (SELECT COUNT(*) FROM owners o WHERE o.citizen_user_id = u.id) AS linked_owners,
                (SELECT COUNT(*) FROM parcel_owners po JOIN owners o ON o.id = po.owner_id
                  WHERE o.citizen_user_id = u.id) AS linked_parcels
            FROM users u $w ORDER BY u.created_at DESC LIMIT 500");
        $st->execute($params);
        json_ok($st->fetchAll());
    }

    $where = ["u.role <> 'citizen'"];
    $params = [];
    if ($q !== '') {
        $where[] = '(u.username ILIKE :q OR u.phone ILIKE :q OR u.email ILIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }
    $w = 'WHERE ' . implode(' AND ', $where);
    $st = $pdo->prepare("SELECT u.id, u.username, u.role, u.phone, u.email, u.is_active, u.created_at,
            (SELECT COUNT(*) FROM title_scans s WHERE s.verified_by = u.id) AS scans_verified
        FROM users u $w ORDER BY u.role, u.username NULLS LAST");
    $st->execute($params);
    json_ok($st->fetchAll());
}

$body = read_json_body();
$action = $body['action'] ?? ($_GET['action'] ?? '');

// ---- Create a staff account ----
if ($method === 'POST' && $action === 'create') {
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    $role     = trim((string)($body['role'] ?? 'officer'));
    $phone    = trim((string)($body['phone'] ?? ''));
    $email    = trim((string)($body['email'] ?? ''));

    if (!in_array($role, ['admin', 'officer'], true)) {
        json_error('Staff role must be admin or officer', 400, 'invalid_role');
    }
    $err = collect_errors([
        'username' => validate_required($username, 'username'),
        'password' => mb_strlen($password) < 8 ? 'password must be at least 8 characters' : null,
    ]);
    reject_on_errors($err);

    // users.phone is NOT NULL, so staff need one even if they never log in by phone.
    if ($phone === '') {
        $phone = '+8569' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }

    try {
        $st = $pdo->prepare("INSERT INTO users (role, username, phone, email, password_hash, is_active)
            VALUES (:role, :un, :ph, :em, :hash, TRUE)
            RETURNING id, username, role, phone, email, is_active");
        $st->execute([
            ':role' => $role, ':un' => $username, ':ph' => $phone,
            ':em' => $email ?: null, ':hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        $row = $st->fetch();
        fee_audit($pdo, $me['sub'] ?? null, 'user_create', 'user', (int)$row['id'], ['username' => $username, 'role' => $role]);
        json_ok($row, 201);
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') {
            json_error('That username, phone or email is already in use', 409, 'duplicate');
        }
        json_error('Create failed: ' . $e->getMessage(), 500, 'create_failed');
    }
}

// ---- Update a staff account (including password reset) ----
if ($method === 'POST' && $action === 'update') {
    $id = (int)($body['id'] ?? 0);
    if (!$id) {
        json_error('id is required', 400, 'missing_id');
    }
    $st = $pdo->prepare("SELECT id, username, role, is_active FROM users WHERE id = :id");
    $st->execute([':id' => $id]);
    $user = $st->fetch();
    if (!$user) {
        json_error('User not found', 404, 'not_found');
    }

    $sets = [];
    $params = [':id' => $id];
    foreach (['username', 'phone', 'email'] as $f) {
        if (array_key_exists($f, $body)) {
            $sets[] = "$f = :$f";
            $params[':' . $f] = trim((string)$body[$f]) ?: null;
        }
    }
    if (!empty($body['password'])) {
        if (mb_strlen((string)$body['password']) < 8) {
            json_error('Password must be at least 8 characters', 400, 'weak_password');
        }
        $sets[] = 'password_hash = :hash';
        $params[':hash'] = password_hash((string)$body['password'], PASSWORD_DEFAULT);
    }
    if (array_key_exists('role', $body) && in_array($body['role'], ['admin', 'officer', 'citizen'], true)) {
        if ($user['role'] === 'citizen') {
            json_error('Citizen accounts cannot be edited here', 400, 'citizen_readonly');
        }
        $sets[] = 'role = :role';
        $params[':role'] = $body['role'];
    }
    if (array_key_exists('is_active', $body)) {
        $active = !empty($body['is_active']);
        // Never allow an admin to lock themselves out, or to demote the last admin.
        if (!$active && (int)$user['id'] === (int)$me['sub']) {
            json_error('You cannot deactivate your own account', 409, 'self_lockout');
        }
        $sets[] = 'is_active = :active';
        $params[':active'] = $active;
    }
    if (!$sets) {
        json_error('Nothing to update', 400, 'nothing_to_update');
    }

    // Guard: the system must keep at least one active admin.
    $demotesAdmin = $user['role'] === 'admin'
        && array_key_exists(':role', $params) && $params[':role'] !== 'admin';
    $deactivatesAdmin = $user['role'] === 'admin'
        && array_key_exists(':active', $params) && $params[':active'] === false;
    if ($demotesAdmin || $deactivatesAdmin) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = TRUE AND id <> :id");
        $st->execute([':id' => $id]);
        if ((int)$st->fetchColumn() < 1) {
            json_error('At least one active admin must remain', 409, 'last_admin');
        }
    }

    try {
        $st = $pdo->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = :id
            RETURNING id, username, role, phone, email, is_active");
        $st->execute($params);
        $row = $st->fetch();
        fee_audit($pdo, $me['sub'] ?? null, 'user_update', 'user', $id, [
            'fields' => array_keys($sets),
            'password_reset' => isset($params[':hash']),
        ]);
        json_ok($row);
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') {
            json_error('That username, phone or email is already in use', 409, 'duplicate');
        }
        json_error('Update failed: ' . $e->getMessage(), 500, 'update_failed');
    }
}

// ---- Link a citizen login to an owner record ----
if ($method === 'POST' && $action === 'link_owner') {
    $userId  = (int)($body['user_id'] ?? 0);
    $ownerId = (int)($body['owner_id'] ?? 0);
    $unlink  = !empty($body['unlink']);

    if ($userId <= 0) {
        json_error('user_id is required', 400, 'missing_user');
    }
    $st = $pdo->prepare("SELECT id, role FROM users WHERE id = :id");
    $st->execute([':id' => $userId]);
    $user = $st->fetch();
    if (!$user) { json_error('User not found', 404, 'user_not_found'); }
    if ($user['role'] !== 'citizen') { json_error('Only citizen accounts can be linked', 400, 'not_citizen'); }

    if ($unlink) {
        $pdo->prepare("UPDATE owners SET citizen_user_id = NULL, updated_at = now() WHERE citizen_user_id = :u")
            ->execute([':u' => $userId]);
        fee_audit($pdo, $me['sub'] ?? null, 'citizen_unlink', 'user', $userId, []);
        json_ok(['user_id' => $userId, 'linked' => 0]);
    }

    if ($ownerId <= 0) {
        json_error('owner_id is required', 400, 'missing_owner');
    }
    $st = $pdo->prepare("SELECT id, full_name FROM owners WHERE id = :id");
    $st->execute([':id' => $ownerId]);
    $owner = $st->fetch();
    if (!$owner) { json_error('Owner not found', 404, 'owner_not_found'); }

    // One login per owner record: claim it from whoever held it before.
    $pdo->prepare("UPDATE owners SET citizen_user_id = NULL WHERE citizen_user_id = :u AND id <> :o")
        ->execute([':u' => $userId, ':o' => $ownerId]);
    $pdo->prepare("UPDATE owners SET citizen_user_id = :u, updated_at = now() WHERE id = :o")
        ->execute([':u' => $userId, ':o' => $ownerId]);
    fee_audit($pdo, $me['sub'] ?? null, 'citizen_link_owner', 'user', $userId, ['owner_id' => $ownerId]);
    json_ok(['user_id' => $userId, 'owner_id' => $ownerId, 'full_name' => $owner['full_name']]);
}

json_error('Not found', 404, 'not_found');