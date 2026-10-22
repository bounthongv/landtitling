<?php
// API v1: parcels — GET list/search, POST create
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];
$me = require_auth(['citizen', 'officer', 'admin', 'manager']);
$staff = $me['role'] !== 'citizen';
$scope = staff_scope($me);

// ---- GET: list/search / fetch by id ----
if ($method === 'GET') {
    $district = $_GET['district_id'] ?? null;
    $parcel_no = $_GET['parcel_no'] ?? '';
    $status = $_GET['status'] ?? null;
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    // Citizens may look up a specific parcel they name, or their own, but must not be
    // able to enumerate the whole land registry.
    $mineOnly = !empty($_GET['mine']);
    if ($me['role'] === 'citizen' && !$id && $parcel_no === '' && !$mineOnly) {
        json_error('Specify a parcel number, or use mine=1 to list your own parcels', 403, 'listing_forbidden');
    }
    if ($mineOnly) {
        if ($me['role'] !== 'citizen') {
            json_error('mine=1 is only available to citizens', 400, 'bad_request');
        }
        $stmt = $pdo->prepare("SELECT p.*, d.name_lo AS district_name, v.name_lo AS village_name
            FROM parcels p
            JOIN districts d ON d.id = p.district_id
            JOIN villages v ON v.id = p.village_id
            WHERE p.id IN (SELECT po.parcel_id FROM parcel_owners po
                           JOIN owners o ON o.id = po.owner_id
                           WHERE o.citizen_user_id = :me)
            ORDER BY p.created_at DESC LIMIT 200");
        $stmt->execute([':me' => $me['sub']]);
        $rows = $stmt->fetchAll();
        json_ok(['parcels' => $rows, 'count' => count($rows)], 200);
    }

    if ($id) {
        $stmt = $pdo->prepare("SELECT p.*, d.name_lo AS district_name, v.name_lo AS village_name
            FROM parcels p JOIN districts d ON d.id = p.district_id JOIN villages v ON v.id = p.village_id
            WHERE p.id = :id");
        $stmt->execute([':id' => $id]);
        $rows = $stmt->fetchAll();
        if ($me['role'] === 'citizen') {
            $owns = (int)$pdo->query("SELECT COUNT(*) FROM parcel_owners po JOIN owners o ON o.id = po.owner_id
                WHERE o.citizen_user_id = " . (int)$me['sub'] . " AND po.parcel_id = " . $id)->fetchColumn();
            if (!$owns) {
                json_error('Forbidden', 403, 'forbidden');
            }
        }
        if ($staff && $scope['district_id'] !== null) {
            foreach ($rows as $row) {
                if ((int)$row['district_id'] !== $scope['district_id']) {
                    json_error('Parcel outside your district', 403, 'out_of_scope');
                }
            }
        }
        json_ok(['parcels' => $rows, 'count' => count($rows)], 200);
    }

    $where = [];
    $params = [];
    if ($district) { $where[] = 'p.district_id = :district'; $params[':district'] = (int)$district; }
    if ($parcel_no !== '') { $where[] = 'p.parcel_no ILIKE :parcel'; $params[':parcel'] = '%' . $parcel_no . '%'; }
    if ($status) { $where[] = 'p.status = :status'; $params[':status'] = $status; }
    if ($staff && $scope['district_id'] !== null) {
        $where[] = 'p.district_id = :scope_district';
        $params[':scope_district'] = $scope['district_id'];
    }

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

// ---- POST: create (officer via OCR confirm) ----
if ($method === 'POST') {
    require_staff_write($me);   // only district admin/officer create records
    $body = read_json_body();
    $parcel_no = trim($body['parcel_no'] ?? '');
    $area = $body['area_sqm'] ?? 0;
    $zone = trim($body['zone_code'] ?? '');
    $road = $body['road_category'] ?? ''; // optional legacy field, kept for display
    $village = $body['village_id'] ?? null;
    $district = $body['district_id'] ?? null;

    $err = collect_errors([
        'parcel_no' => validate_required($parcel_no, 'parcel no'),
        'area_sqm' => validate_positive($area, 'area'),
        'zone_code' => validate_required($zone, 'zone code'),
        'village_id' => validate_int($village, 'village'),
        'district_id' => validate_int($district, 'district'),
    ]);
    reject_on_errors($err);

    // District staff may only create parcels in their own district.
    if ($scope['district_id'] !== null && (int)$district !== $scope['district_id']) {
        json_error('You can only create parcels in your own district', 403, 'out_of_scope');
    }

    $stmt = $pdo->prepare("INSERT INTO parcels (parcel_no, area_sqm, zone_code, road_category, village_id, district_id, status, scanned_at, ocr_confidence)
        VALUES (:pn, :a, :zc, :rc, :vid, :did, 'confirmed', CURRENT_TIMESTAMP, 0) RETURNING id");
    $stmt->execute([':pn' => $parcel_no, ':a' => (float)$area, ':zc' => $zone, ':rc' => $road ?: null, ':vid' => (int)$village, ':did' => (int)$district]);
    $id = (int)$stmt->fetch()['id'];
    json_ok(['id' => $id], 201);
}
json_error('Method not allowed', 405, 'method_not_allowed');
