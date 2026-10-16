<?php
// API v1: owners — create/update owners and link them to parcels (co-ownership).
//   GET  ?id={id}          single owner (+ their parcels)
//   GET  ?parcel_id={id}   owners of a parcel
//   POST {full_name,…[, parcel_id, role, share_pct]}                    create (optionally link)
//   PUT  ?id={id}          update an owner
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];
$me = require_auth(['officer', 'admin']);

$roles = ['owner', 'co_owner', 'representative'];
$ownerTypes = ['individual', 'organization', 'joint'];

function owner_fields(array $in): array {
    return [
        'full_name'   => trim((string)($in['full_name'] ?? '')),
        'id_number'   => trim((string)($in['id_number'] ?? '')) ?: null,
        'id_type'     => trim((string)($in['id_type'] ?? '')) ?: null,
        'phone'       => trim((string)($in['phone'] ?? '')) ?: null,
        'address'     => trim((string)($in['address'] ?? '')) ?: null,
        'village_id'  => !empty($in['village_id']) ? (int)$in['village_id'] : null,
        'district_id' => !empty($in['district_id']) ? (int)$in['district_id'] : null,
    ];
}

if ($method === 'GET' && isset($_GET['parcel_id'])) {
    $st = $pdo->prepare("SELECT o.*, po.share_pct, po.role
        FROM parcel_owners po JOIN owners o ON o.id = po.owner_id
        WHERE po.parcel_id = :pid ORDER BY o.full_name");
    $st->execute([':pid' => (int)$_GET['parcel_id']]);
    $rows = $st->fetchAll();
    json_ok(['owners' => $rows], 200, ['count' => count($rows)]);
}

if ($method === 'GET' && isset($_GET['id'])) {
    $st = $pdo->prepare("SELECT * FROM owners WHERE id = :id");
    $st->execute([':id' => (int)$_GET['id']]);
    $owner = $st->fetch();
    if (!$owner) { json_error('Owner not found', 404, 'not_found'); }
    $st = $pdo->prepare("SELECT po.parcel_id, po.role, po.share_pct, p.parcel_no
        FROM parcel_owners po JOIN parcels p ON p.id = po.parcel_id WHERE po.owner_id = :id");
    $st->execute([':id' => (int)$_GET['id']]);
    json_ok(['owner' => $owner, 'parcels' => $st->fetchAll()]);
}

if ($method === 'POST' || $method === 'PUT') {
    $in = read_json_body();
    $f = owner_fields($in);
    if ($f['full_name'] === '') { json_error('Owner full name is required', 422, 'validation_failed', ['full_name' => 'owner full name is required']); }
    $ownerType = in_array($in['owner_type'] ?? '', $ownerTypes, true) ? $in['owner_type'] : 'individual';

    if ($method === 'POST') {
        $st = $pdo->prepare("INSERT INTO owners (full_name, id_number, id_type, phone, address, village_id, district_id, owner_type)
            VALUES (:fn, :idn, :idt, :ph, :ad, :vid, :did, :ot) RETURNING *");
        $st->execute([
            ':fn' => $f['full_name'], ':idn' => $f['id_number'], ':idt' => $f['id_type'],
            ':ph' => $f['phone'], ':ad' => $f['address'], ':vid' => $f['village_id'],
            ':did' => $f['district_id'], ':ot' => $ownerType,
        ]);
        $owner = $st->fetch();
        $ownerId = (int)$owner['id'];
    } else {
        $ownerId = (int)($_GET['id'] ?? 0);
        if (!$ownerId) { json_error('Owner id required', 400, 'missing_id'); }
        $st = $pdo->prepare("UPDATE owners SET full_name=:fn, id_number=:idn, id_type=:idt, phone=:ph,
            address=:ad, village_id=:vid, district_id=:did, owner_type=:ot, updated_at=CURRENT_TIMESTAMP
            WHERE id=:id RETURNING *");
        $st->execute([
            ':fn' => $f['full_name'], ':idn' => $f['id_number'], ':idt' => $f['id_type'],
            ':ph' => $f['phone'], ':ad' => $f['address'], ':vid' => $f['village_id'],
            ':did' => $f['district_id'], ':ot' => $ownerType, ':id' => $ownerId,
        ]);
        $owner = $st->fetch();
        if (!$owner) { json_error('Owner not found', 404, 'not_found'); }
    }

    // Optional link to a parcel
    if (!empty($in['parcel_id'])) {
        $role = in_array($in['role'] ?? '', $roles, true) ? $in['role'] : 'owner';
        $share = (isset($in['share_pct']) && $in['share_pct'] !== '') ? (float)$in['share_pct'] : null;
        $pdo->prepare("INSERT INTO parcel_owners (parcel_id, owner_id, share_pct, role)
            VALUES (:pid, :oid, :sh, :role)
            ON CONFLICT (parcel_id, owner_id) DO UPDATE SET share_pct = EXCLUDED.share_pct, role = EXCLUDED.role")
            ->execute([':pid' => (int)$in['parcel_id'], ':oid' => $ownerId, ':sh' => $share, ':role' => $role]);
    }
    json_ok(['owner' => $owner], $method === 'POST' ? 201 : 200);
}

json_error('Method not allowed', 405, 'method_not_allowed');
