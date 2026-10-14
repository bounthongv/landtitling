<?php
// API v1: scans/{id}/confirm — officer confirms/edits, creates or updates parcel
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];
$me = require_auth(['officer', 'admin']);

$id = (int)($_GET['id'] ?? '0');
if (!$id) { json_error('Scan id required', 400, 'missing_id'); }

// Fetch the scan
$stmt = $pdo->prepare("SELECT ts.*, p.parcel_no, p.area_sqm, p.road_category, p.village_id, p.district_id
    FROM title_scans ts
    LEFT JOIN parcels p ON p.id = ts.parcel_id
    WHERE ts.id = :id");
$stmt->execute([':id' => $id]);
$scan = $stmt->fetch();
if (!$scan) { json_error('Scan not found', 404, 'not_found'); }

// Build existing parcel data into the payload for the form
$payload = [
    'id' => $scan['id'],
    'ocr_extracted' => $scan['ocr_extracted'] ?? null,
    'ocr_text' => $scan['ocr_raw'] ?? null,
    'parcel' => [
        'id' => $scan['parcel_id'] ?? null,
        'parcel_no' => $scan['parcel_no'] ?? '',
        'sub_parcel_no' => $scan['sub_parcel_no'] ?? '',
        'area_sqm' => $scan['area_sqm'] ?? '',
        'road_category' => $scan['road_category'] ?? '',
        'village_id' => $scan['village_id'] ?? '',
        'district_id' => $scan['district_id'] ?? '',
        'status' => $scan['parcel_status'] ?? 'confirmed',
    ],
    'district_id' => $scan['district_id'] ?? '',
    'village_id' => $scan['village_id'] ?? '',
];

// ---- POST: confirm form ----//
if ($method === 'POST') {
    $body = read_json_body();
    $parcel = $body['parcel'] ?? [];
    $parcel_no = trim($parcel['parcel_no'] ?? '');
    $area = $parcel['area_sqm'] ?? '';
    $road = $parcel['road_category'] ?? '';
    $village = $parcel['village_id'] ?? '';
    $district = $parcel['district_id'] ?? '';
    $status = $parcel['status'] ?? 'confirmed';

    $err = collect_errors([
        'parcel_no' => validate_required($parcel_no, 'parcel no'),
        'area_sqm' => validate_required($area, 'area') ?: validate_positive($area, 'area'),
        'road_category' => validate_required($road, 'road category')
            ?: validate_enum($road, ['main','branch','alley','none'], 'road category'),
        'village_id' => validate_required($village, 'village') ?: validate_int($village, 'village'),
        'district_id' => validate_required($district, 'district') ?: validate_int($district, 'district'),
    ]);
    reject_on_errors($err);

    $districtId = (int)$district;
    $villageId = (int)$village;

    // Check village belongs to selected district
    $stmt = $pdo->prepare("SELECT id FROM villages WHERE id = :id AND district_id = :did");
    $stmt->execute([':id' => $villageId, ':did' => $districtId]);
    if (!$stmt->fetch()) { json_error('Village does not belong to selected district', 422, 'bad_village'); }

    // Find or update parcel
    if ($parcel['id']) {
        $stmt = $pdo->prepare("UPDATE parcels SET parcel_no = :pn, sub_parcel_no = :spn, area_sqm = :a, road_category = :rc,
            village_id = :vid, district_id = :did, status = :st, scanned_at = CURRENT_TIMESTAMP, ocr_confidence = 0
            WHERE id = :id");
        $stmt->execute([':pn' => $parcel_no, ':spn' => $parcel['sub_parcel_no'] ?? null, ':a' => (float)$area,
            ':rc' => $road, ':vid' => $villageId, ':did' => $districtId, ':st' => $status, ':id' => $parcel['id']]);
        $parcelId = $parcel['id'];
    } else {
        $stmt = $pdo->prepare("INSERT INTO parcels (parcel_no, sub_parcel_no, village_id, district_id, area_sqm, road_category, status, scanned_at, ocr_confidence)
            VALUES (:pn, :spn, :vid, :did, :a, :rc, :st, CURRENT_TIMESTAMP, 0) RETURNING id");
        $stmt->execute([':pn' => $parcel_no, ':spn' => $parcel['sub_parcel_no'] ?? null,
            ':vid' => $villageId, ':did' => $districtId, ':a' => (float)$area, ':rc' => $road, ':st' => $status]);
        $parcelId = (int)$stmt->fetch()['id'];
    }

    // Link the title scan to the parcel and mark confirmed
    $stmt = $pdo->prepare("UPDATE title_scans SET parcel_id = :pid, verified_by = :v, verified_at = CURRENT_TIMESTAMP WHERE id = :id");
    $stmt->execute([':pid' => $parcelId, ':v' => $me['sub'], ':id' => $id]);

    json_ok(['parcel_id' => $parcelId, 'status' => 'confirmed'], 200);
}
json_error('Method not allowed', 405, 'method_not_allowed');
