<?php
// API v1: scans/reject.php?id={id} — discard a scan that will not become a parcel.
// The row (and its image) stay for audit; status 'rejected' takes it out of the
// review queue. A scan already confirmed against a parcel cannot be discarded.
require_once __DIR__ . '/../../../lib/db.php';
require_once __DIR__ . '/../../../lib/auth.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];
$me = require_auth(['officer', 'admin', 'manager']);
$scope = require_staff_write($me);   // only district admin/officer discard scans

if ($method !== 'POST') { json_error('Method not allowed', 405, 'method_not_allowed'); }

$id = (int)($_GET['id'] ?? '0');
if (!$id) { json_error('Scan id required', 400, 'missing_id'); }

$stmt = $pdo->prepare("SELECT ts.id, ts.status, ts.parcel_id, ts.district_id, ts.created_by,
                              p.district_id AS parcel_district
                       FROM title_scans ts LEFT JOIN parcels p ON p.id = ts.parcel_id
                       WHERE ts.id = :id");
$stmt->execute([':id' => $id]);
$scan = $stmt->fetch();
if (!$scan) { json_error('Scan not found', 404, 'not_found'); }

// District staff may only discard scans that belong to their own district.
$scanDistrict = $scan['district_id'] !== null ? (int)$scan['district_id']
    : ($scan['parcel_district'] !== null ? (int)$scan['parcel_district'] : null);
if ($scope['district_id'] !== null && $scanDistrict !== $scope['district_id']) {
    json_error('Scan outside your district', 403, 'out_of_scope');
}

// Officers may only discard their own uploads; admins may discard any scan in scope.
if (($me['role'] ?? '') === 'officer' && empty($me['is_super'])
    && (int)($scan['created_by'] ?? 0) !== (int)$me['sub']) {
    json_error('Officers can only discard scans they uploaded', 403, 'not_your_scan');
}

if ($scan['status'] === 'confirmed' || $scan['parcel_id'] !== null) {
    json_error('This scan is already confirmed and linked to a parcel', 409, 'already_confirmed');
}
if ($scan['status'] === 'rejected') {
    json_ok(['id' => $id, 'status' => 'rejected']);
}

$body = read_json_body();
$note = mb_substr(trim((string)($body['note'] ?? '')), 0, 200) ?: null;

$upd = $pdo->prepare("UPDATE title_scans
    SET status = 'rejected', note = COALESCE(:note, note),
        verified_by = :v, verified_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
    WHERE id = :id AND status NOT IN ('confirmed','rejected')");
$upd->execute([':note' => $note, ':v' => $me['sub'], ':id' => $id]);
if ($upd->rowCount() === 0) {
    json_error('This scan can no longer be discarded', 409, 'not_discardable');
}

json_ok(['id' => $id, 'status' => 'rejected']);
