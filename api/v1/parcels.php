<?php
// API v1: parcels — GET list/search, POST create
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];
$me = require_auth(['citizen', 'officer', 'admin']);

// ---- GET: list/search ----//
if ($method === 'GET') {
    $district = $_GET['district_id'] ?? null;
    $village = $_GET['village_id'] ?? null;
    $parcel_no = $_GET['parcel_no'] ?? '';
    $status = $_GET['status'] ?? null;

    $where = [];
    $params = [];
    if ($district) { $where[] = 'p.district_id = :district'; $params[':district'] = (int)$district; }
    if ($village) { $where[] = 'p.village_id = :village'; $params[':village'] = (int)$village; }
    if ($parcel_no !== '') { $where[] = 'p.parcel_no ILIKE :parcel'; $params[':parcel'] = '%' . $parcel_no . '%'; }
    if ($status) { $where[] = 'p.status = :status'; $params[':status'] = $status; }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = $pdo->prepare("SELECT p.*, d.name_lo AS district_name, v.name_lo AS village_name
        FROM parcels p
        JOIN districts d ON d.id = p.district_id
        JOIN villages v ON v.id = p.village_id
        $whereSql
        ORDER BY p.updated_at DESC NULLS LAST, p.created_at DESC
        LIMIT 200");
    $stmt->execute($params);
    $parcels = $stmt->fetchAll();
    json_ok(['parcels' => $parcels, 'count' => count($parcels)], 200);
}

// ---- POST: create (officer via OCR confirm) ----//
if ($method === 'POST') {
    $me = require_auth(['officer', 'admin']);
    $body = read_json_body();
    $parcel_no = trim($body['parcel_no'] ?? '');
    $area = $body['area_sqm'] ?? 0;
    $road = $body['road_category'] ?? '';
    $village = $body['village_id'] ?? null;
    $district = $body['district_id'] ?? null;

    $err = collect_errors([
        'parcel_no' => validate_required($parcel_no, 'parcel no'),
        'area_sqm' => validate_positive($area, 'area'),
        'road_category' => validate_enum($road, ['main','branch','alley','none'], 'road category'),
        'village_id' => validate_int($village, 'village'),
        'district_id' => validate_int($district, 'district'),
    ]);
    reject_on_errors($err);

    $stmt = $pdo->prepare("INSERT INTO parcels (parcel_no, area_sqm, road_category, village_id, district_id, status, scanned_at, ocr_confidence)
        VALUES (:pn, :a, :rc, :vid, :did, 'confirmed', CURRENT_TIMESTAMP, 0) RETURNING id");
    $stmt->execute([':pn' => $parcel_no, ':a' => (float)$area, ':rc' => $road, ':vid' => (int)$village, ':did' => (int)$district]);
    $id = (int)$stmt->fetch()['id'];
    json_ok(['id' => $id], 201);
}
json_error('Method not allowed', 405, 'method_not_allowed');
