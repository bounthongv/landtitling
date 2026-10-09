<?php
// API: parcels CRUD
require_once __DIR__ . '/../lib/db.php';
require_method($_SERVER['REQUEST_METHOD']);

$pdo = db_connect();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // List parcels with optional filters
    $district_id = isset($_GET['district_id']) ? intval($_GET['district_id']) : null;
    $village_id = isset($_GET['village_id']) ? intval($_GET['village_id']) : null;
    $status = isset($_GET['status']) ? $_GET['status'] : null;

    $sql = "SELECT p.*, v.name_lo AS village_name, d.name_lo AS district_name 
            FROM parcels p 
            JOIN villages v ON p.village_id = v.id 
            JOIN districts d ON p.district_id = d.id 
            WHERE 1=1";
    $params = [];
    if ($district_id) {
        $sql .= " AND p.district_id = ?";
        $params[] = $district_id;
    }
    if ($village_id) {
        $sql .= " AND p.village_id = ?";
        $params[] = $village_id;
    }
    if ($status) {
        $sql .= " AND p.status = ?";
        $params[] = $status;
    }
    $sql .= " ORDER BY p.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $parcels = $stmt->fetchAll();
    json_output($parcels);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Create a new parcel
    $data = json_decode(file_get_contents('php://input'), true);
    $required = ['parcel_no', 'village_id', 'district_id', 'area_sqm'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            json_output(['error' => "Missing field: $field"], 400);
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO parcels (parcel_no, sub_parcel_no, village_id, district_id, area_sqm, road_category, status, scanned_at, ocr_confidence)
        VALUES (:parcel_no, :sub_parcel_no, :village_id, :district_id, :area_sqm, :road_category, :status, :scanned_at, :ocr_confidence)
    ");
    $result = $stmt->execute([
        'parcel_no' => $data['parcel_no'],
        'sub_parcel_no' => $data['sub_parcel_no'] ?? null,
        'village_id' => $data['village_id'],
        'district_id' => $data['district_id'],
        'area_sqm' => $data['area_sqm'],
        'road_category' => $data['road_category'] ?? null,
        'status' => $data['status'] ?? 'confirmed',
        'scanned_at' => $data['scanned_at'] ?? null,
        'ocr_confidence' => $data['ocr_confidence'] ?? null
    ]);

    if ($result) {
        $newId = $pdo->lastInsertId();
        json_output(['id' => $newId], 201);
    } else {
        json_output(['error' => 'Failed to create parcel'], 500);
    }
}

// We could add PUT/PATCH for update and DELETE for delete, but not needed for MVP now.
?>