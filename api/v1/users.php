<?php
// API v1: users.php — admin user management.
// Staff (admin/officer/manager): read lists; account changes are admin-only.
// Linking a citizen login to an owner record is a staff write, district-scoped —
// that link is what makes a parcel visible in the citizen app (owners.citizen_user_id).
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';
require_once __DIR__ . '/../../lib/fee.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];
$me = require_auth(['admin', 'officer', 'manager']);
$scope = staff_scope($me);

// Migration 020 adds the agent columns. Probe once so this endpoint keeps
// working on a server where the migration has not been applied yet.
$hasAgentCols = false;
try {
    $hasAgentCols = (bool)$pdo->query("SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'users' AND column_name = 'is_agent'")->fetchColumn();
} catch (\Throwable $e) {
    $hasAgentCols = false;
}

// ---- GET: owner candidates (for linking), staff, or citizens ----
if ($method === 'GET') {
    // Candidate owners to link to a citizen login. Officers usually know the parcel
    // number rather than the owner's name, so both are searchable. District staff
    // only see owners in their district (or who own a parcel there).
    if ($_GET['candidates'] ?? '') {
        $q = trim((string)($_GET['q'] ?? ''));
        $sql = "WITH scoped_parcels AS (
                    SELECT po.owner_id, p.id AS parcel_id, p.parcel_no
                    FROM parcel_owners po
                    JOIN parcels p ON p.id = po.parcel_id
                    WHERE p.district_id = COALESCE(CAST(:did AS integer), p.district_id)
                )
                SELECT o.id, o.full_name, o.id_number, o.phone, o.citizen_user_id,
                       COUNT(sp.parcel_id) AS parcel_count,
                       string_agg(sp.parcel_no, ', ' ORDER BY sp.parcel_no) AS parcels
                FROM owners o
                LEFT JOIN scoped_parcels sp ON sp.owner_id = o.id
                WHERE (o.district_id = COALESCE(CAST(:did2 AS integer), o.district_id)
                       OR sp.owner_id IS NOT NULL)";
        $params = [':did' => $scope['district_id'], ':did2' => $scope['district_id']];
        if ($q !== '') {
            $like = '%' . $q . '%';
            $sql .= " AND (o.full_name ILIKE :q1 OR o.id_number ILIKE :q2 OR o.phone ILIKE :q3
                        OR EXISTS (SELECT 1 FROM scoped_parcels sp2
                                   WHERE sp2.owner_id = o.id AND sp2.parcel_no ILIKE :q4))";
            $params += [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like];
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
        // District staff see only their own district's citizens.
        if ($scope['district_id'] !== null) {
            $where[] = 'u.district_id = :scope_did';
            $params[':scope_did'] = $scope['district_id'];
        }
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
    // District staff see only their own district's staff.
    $params = [];
    if ($scope['district_id'] !== null) {
        $where[] = 'u.district_id = :scope_did';
        $params[':scope_did'] = $scope['district_id'];
    }
    if ($q !== '') {
        $where[] = '(u.username ILIKE :q OR u.phone ILIKE :q OR u.email ILIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }
    $w = 'WHERE ' . implode(' AND ', $where);
    $agentSelect = $hasAgentCols
        ? ", u.is_agent, u.agent_village_id, u.agent_role, u.agent_license_no,
             v.name_en AS agent_village_name, v.name_lo AS agent_village_name_lo"
        : '';
    $villageJoin = $hasAgentCols ? ' LEFT JOIN villages v ON v.id = u.agent_village_id' : '';
    $st = $pdo->prepare("SELECT u.id, u.username, u.role, u.level, u.district_id, u.phone, u.email, u.is_active, u.created_at,
            d.name_en AS district_name, d.name_lo AS district_name_lo, d.province_id,
            p.name_lo AS province_name{$agentSelect},
            (SELECT COUNT(*) FROM title_scans s WHERE s.verified_by = u.id AND s.status = 'confirmed') AS scans_verified
        FROM users u
        LEFT JOIN districts d ON d.id = u.district_id
        LEFT JOIN provinces p ON p.id = d.province_id{$villageJoin}
        $w ORDER BY u.role, u.username NULLS LAST");
    $st->execute($params);
    json_ok($st->fetchAll());
}

$body = read_json_body();
$action = $body['action'] ?? ($_GET['action'] ?? '');

// ---- Create a staff account ----
if ($method === 'POST' && $action === 'create') {
    // Account management is admin-only (district admin: own district; province admin: anywhere).
    if (($me['role'] ?? '') !== 'admin') {
        json_error('Only admins can create accounts', 403, 'forbidden');
    }
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    $role     = trim((string)($body['role'] ?? 'officer'));
    $phone    = trim((string)($body['phone'] ?? ''));
    $email    = trim((string)($body['email'] ?? ''));

    if (!in_array($role, ['admin', 'officer', 'manager'], true)) {
        json_error('Staff role must be admin, officer or manager', 400, 'invalid_role');
    }
    $level = trim((string)($body['level'] ?? 'district'));
    if (!in_array($level, ['district', 'province', 'national'], true)) {
        json_error('Level must be district, province or national', 400, 'invalid_level');
    }
    // District-level staff may only create users in their own district.
    // Province-level staff (district_id null) may specify any district in the province.
    $districtId = null;
    if ($level === 'province' || $level === 'national') {
        $districtId = null; // province- and national-level users have no district
    } elseif ($scope['district_id'] !== null) {
        $districtId = $scope['district_id'];
    } elseif (isset($body['district_id']) && (int)$body['district_id'] > 0) {
        $districtId = (int)$body['district_id'];
    }

    $err = collect_errors([
        'username' => validate_required($username, 'username'),
        'password' => mb_strlen($password) < 8 ? 'password must be at least 8 characters' : null,
    ]);
    reject_on_errors($err);

    // Optional cash-agent setup (migration 020). An agent is an officer account
    // pinned to one village; the village's district defines their scope.
    $isAgent = !empty($body['is_agent']);
    $agentVillageId = isset($body['agent_village_id']) ? (int)$body['agent_village_id'] : 0;
    $agentRole = trim((string)($body['agent_role'] ?? ''));
    $agentLicense = trim((string)($body['agent_license_no'] ?? ''));
    if ($isAgent) {
        if (!$hasAgentCols) {
            json_error('Agent support is not installed (migration 020)', 409, 'agent_not_supported');
        }
        if ($role !== 'officer') {
            json_error('Only officer accounts can be cash agents', 400, 'invalid_agent_role');
        }
        if ($agentVillageId <= 0) {
            json_error('Choose the village this agent collects for', 400, 'missing_agent_village');
        }
        $vst = $pdo->prepare("SELECT district_id FROM villages WHERE id = :id");
        $vst->execute([':id' => $agentVillageId]);
        $villageDistrict = $vst->fetchColumn();
        if ($villageDistrict === false) {
            json_error('Unknown village', 404, 'village_not_found');
        }
        if ($scope['district_id'] !== null && (int)$villageDistrict !== $scope['district_id']) {
            json_error('Village outside your district', 403, 'out_of_scope');
        }
        $districtId = (int)$villageDistrict;
        $level = 'district';
    }

    // users.phone is NOT NULL, so staff need one even if they never log in by phone.
    if ($phone === '') {
        $phone = '+8569' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }

    try {
        $agentCols = '';
        $agentVals = '';
        $agentParams = [];
        if ($hasAgentCols) {
            $agentCols = ', is_agent, agent_village_id, agent_district_id, agent_role, agent_license_no';
            $agentVals = ', CAST(:is_agent AS boolean), :av, :ad, :ar, :al';
            $agentParams = [
                ':is_agent' => $isAgent ? 'true' : 'false',
                ':av' => $isAgent ? $agentVillageId : null,
                ':ad' => $isAgent ? $districtId : null,
                ':ar' => $isAgent && $agentRole !== '' ? $agentRole : null,
                ':al' => $isAgent && $agentLicense !== '' ? $agentLicense : null,
            ];
        }
        $st = $pdo->prepare("INSERT INTO users (role, username, phone, email, password_hash, is_active, level, district_id{$agentCols})
            VALUES (:role, :un, :ph, :em, :hash, TRUE, :level, :did{$agentVals})
            RETURNING id, username, role, level, phone, email, is_active, district_id");
        $st->execute([
            ':role' => $role, ':un' => $username, ':ph' => $phone,
            ':em' => $email ?: null, ':hash' => password_hash($password, PASSWORD_DEFAULT),
            ':level' => $level, ':did' => $districtId,
        ] + $agentParams);
        $row = $st->fetch();
        fee_audit($pdo, $me['sub'] ?? null, 'user_create', 'user', (int)$row['id'], [
            'username' => $username, 'role' => $role, 'is_agent' => $isAgent,
        ]);
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
    if (($me['role'] ?? '') !== 'admin') {
        json_error('Only admins can modify accounts', 403, 'forbidden');
    }
    $id = (int)($body['id'] ?? 0);
    if (!$id) {
        json_error('id is required', 400, 'missing_id');
    }
    $st = $pdo->prepare("SELECT id, username, role, is_active, district_id FROM users WHERE id = :id");
    $st->execute([':id' => $id]);
    $user = $st->fetch();
    if (!$user) {
        json_error('User not found', 404, 'not_found');
    }
    // District staff may only modify users in their own district.
    if ($scope['district_id'] !== null && (int)$user['district_id'] !== $scope['district_id']) {
        json_error('User outside your district', 403, 'out_of_scope');
    }

    // Turning agent mode ON must leave a usable agent: officer role and a
    // village. Validate the effective state, not just this request's fields.
    if ($hasAgentCols && !empty($body['is_agent'])) {
        $effectiveRole = (string)($body['role'] ?? $user['role']);
        if ($effectiveRole !== 'officer') {
            json_error('Only officer accounts can be cash agents', 400, 'invalid_agent_role');
        }
        $effectiveVillage = array_key_exists('agent_village_id', $body)
            ? (int)$body['agent_village_id']
            : null;
        if ($effectiveVillage === null) {
            $st = $pdo->prepare("SELECT agent_village_id FROM users WHERE id = :id");
            $st->execute([':id' => $id]);
            $effectiveVillage = (int)$st->fetchColumn();
        }
        if ($effectiveVillage <= 0) {
            json_error('Choose the village this agent collects for', 400, 'missing_agent_village');
        }
    }

    $sets = [];
    $params = [':id' => $id];
    foreach (['username', 'phone', 'email'] as $f) {
        if (array_key_exists($f, $body)) {
            $sets[] = "$f = :$f";
            $params[':' . $f] = trim((string)$body[$f]) ?: null;
        }
    }
    // District staff may not change district_id of another user.
    if ($scope['district_id'] !== null && array_key_exists('district_id', $body)) {
        json_error('You cannot change a user\'s district', 403, 'read_only');
    }
    if (array_key_exists('district_id', $body)) {
        $sets[] = 'district_id = :district_id';
        $params[':district_id'] = (int)$body['district_id'] > 0 ? (int)$body['district_id'] : null;
    }
    if (array_key_exists('level', $body) && in_array($body['level'], ['district', 'province', 'national'], true)) {
        $sets[] = 'level = :level';
        $params[':level'] = $body['level'];
    }
    if (!empty($body['password'])) {
        if (mb_strlen((string)$body['password']) < 8) {
            json_error('Password must be at least 8 characters', 400, 'weak_password');
        }
        $sets[] = 'password_hash = :hash';
        $params[':hash'] = password_hash((string)$body['password'], PASSWORD_DEFAULT);
    }
    if (array_key_exists('role', $body) && in_array($body['role'], ['admin', 'officer', 'manager', 'citizen'], true)) {
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
    // Cash-agent fields (migration 020).
    if ($hasAgentCols && array_key_exists('is_agent', $body)) {
        $isAgent = !empty($body['is_agent']);
        $sets[] = 'is_agent = CAST(:is_agent AS boolean)';
        $params[':is_agent'] = $isAgent ? 'true' : 'false';
        if (!$isAgent) {
            $sets[] = 'agent_village_id = NULL';
            $sets[] = 'agent_district_id = NULL';
        }
    }
    if ($hasAgentCols && array_key_exists('agent_village_id', $body)) {
        $vid = (int)$body['agent_village_id'];
        if ($vid > 0) {
            $vst = $pdo->prepare("SELECT district_id FROM villages WHERE id = :id");
            $vst->execute([':id' => $vid]);
            $villageDistrict = $vst->fetchColumn();
            if ($villageDistrict === false) {
                json_error('Unknown village', 404, 'village_not_found');
            }
            if ($scope['district_id'] !== null && (int)$villageDistrict !== $scope['district_id']) {
                json_error('Village outside your district', 403, 'out_of_scope');
            }
            $sets[] = 'agent_village_id = :av';
            $sets[] = 'agent_district_id = :ad';
            $sets[] = 'district_id = :agent_did';
            $sets[] = "level = 'district'";
            $params[':av'] = $vid;
            $params[':ad'] = (int)$villageDistrict;
            $params[':agent_did'] = (int)$villageDistrict;
        } else {
            $sets[] = 'agent_village_id = NULL';
            $sets[] = 'agent_district_id = NULL';
        }
    }
    if ($hasAgentCols) {
        foreach (['agent_role', 'agent_license_no'] as $f) {
            if (array_key_exists($f, $body)) {
                $sets[] = "$f = :$f";
                $params[':' . $f] = trim((string)$body[$f]) ?: null;
            }
        }
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
    require_staff_write($me);   // linking grants a citizen access to an owner's parcels
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
    // District staff may only link owners in their district (or who own a parcel there).
    $ownerSql = "SELECT o.id, o.full_name FROM owners o
        WHERE o.id = :id
          AND (o.district_id = COALESCE(CAST(:did AS integer), o.district_id)";
    $ownerArgs = [':id' => $ownerId, ':did' => $scope['district_id']];
    if ($scope['district_id'] !== null) {
        $ownerSql .= " OR EXISTS (SELECT 1 FROM parcel_owners po
                        JOIN parcels p ON p.id = po.parcel_id
                        WHERE po.owner_id = o.id AND p.district_id = :did2)";
        $ownerArgs[':did2'] = $scope['district_id'];
    }
    $ownerSql .= ")";
    $st = $pdo->prepare($ownerSql);
    $st->execute($ownerArgs);
    $owner = $st->fetch();
    if (!$owner) {
        $chk = $pdo->prepare("SELECT 1 FROM owners WHERE id = :id");
        $chk->execute([':id' => $ownerId]);
        if (!$chk->fetchColumn()) { json_error('Owner not found', 404, 'owner_not_found'); }
        json_error('Owner outside your district', 403, 'out_of_scope');
    }

    // One login per owner record: claim it from whoever held it before.
    $pdo->prepare("UPDATE owners SET citizen_user_id = NULL WHERE citizen_user_id = :u AND id <> :o")
        ->execute([':u' => $userId, ':o' => $ownerId]);
    $pdo->prepare("UPDATE owners SET citizen_user_id = :u, updated_at = now() WHERE id = :o")
        ->execute([':u' => $userId, ':o' => $ownerId]);
    fee_audit($pdo, $me['sub'] ?? null, 'citizen_link_owner', 'user', $userId, ['owner_id' => $ownerId]);
    json_ok(['user_id' => $userId, 'owner_id' => $ownerId, 'full_name' => $owner['full_name']]);
}

json_error('Not found', 404, 'not_found');