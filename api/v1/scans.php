<?php
// API v1: title scans — POST upload+OCR, GET list / single
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/ocr.php';
require_once __DIR__ . '/../../lib/title_parser.php';
require_once __DIR__ . '/../../lib/auth.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];
$me = require_auth(['officer', 'admin']);

// ---- GET: single scan (for the review form) ----//
if ($method === 'GET' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("
        SELECT ts.*, p.parcel_no, p.sub_parcel_no, p.area_sqm, p.road_category,
               p.village_id, p.district_id, p.status AS parcel_status
        FROM title_scans ts
        LEFT JOIN parcels p ON p.id = ts.parcel_id
        WHERE ts.id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) { json_error('Scan not found', 404, 'not_found'); }

    $parcel = null;
    if ($row['parcel_id']) {
        $parcel = [
            'id' => (int)$row['parcel_id'],
            'parcel_no' => $row['parcel_no'],
            'sub_parcel_no' => $row['sub_parcel_no'],
            'area_sqm' => $row['area_sqm'],
            'road_category' => $row['road_category'],
            'village_id' => $row['village_id'],
            'district_id' => $row['district_id'],
            'status' => $row['parcel_status'],
        ];
    }
    json_ok([
        'scan' => [
            'id' => (int)$row['id'],
            'image_path' => $row['image_path'],
            'status' => $row['status'],
            'ocr_raw' => $row['ocr_raw'],
            'parsed_fields' => $row['parsed_fields'],
            'field_confidence' => $row['field_confidence'],
            'overall_confidence' => $row['overall_confidence'] !== null ? (int)$row['overall_confidence'] : null,
            'parcel_id' => $row['parcel_id'] !== null ? (int)$row['parcel_id'] : null,
            'created_at' => $row['created_at'],
        ],
        'parcel' => $parcel,
        'district_id' => $parcel['district_id'] ?? null,
        'village_id' => $parcel['village_id'] ?? null,
    ]);
}

// ---- GET: list (review queue) ----//
if ($method === 'GET') {
    $status = $_GET['status'] ?? 'pending';
    if ($status === 'pending') {
        $where = "ts.status NOT IN ('confirmed','rejected')";
        $params = [];
    } else {
        $where = "ts.status = :s";
        $params = [':s' => $status];
    }
    $stmt = $pdo->prepare("
        SELECT ts.id, ts.image_path, ts.status, ts.overall_confidence, ts.parcel_id, ts.created_at,
               p.parcel_no, p.area_sqm, p.road_category, p.status AS parcel_status,
               d.name_lo AS district_name, v.name_lo AS village_name
        FROM title_scans ts
        LEFT JOIN parcels p ON p.id = ts.parcel_id
        LEFT JOIN villages v ON p.village_id = v.id
        LEFT JOIN districts d ON p.district_id = d.id
        WHERE $where
        ORDER BY ts.created_at DESC
        LIMIT 100");
    $stmt->execute($params);
    $scans = $stmt->fetchAll();
    json_ok(['scans' => $scans], 200, ['count' => count($scans)]);
}

// ---- POST: upload + OCR (creates a scan in ocr_done state) ----//
if ($method === 'POST') {
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        json_error('No image uploaded', 400, 'no_file');
    }
    $file = $_FILES['image'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
        json_error('Invalid image type', 400, 'bad_type');
    }
    $cfg = require __DIR__ . '/../../lib/config.php';
    $uploadDir = $cfg['uploads'];
    if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
    $filename = uniqid('scan_', true) . '.' . $ext;
    $destPath = $uploadDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        json_error('Failed to save image', 500, 'upload_failed');
    }
    $ocrResult = ocr_extract_from_image($destPath);
    if (isset($ocrResult['error'])) {
        json_error('OCR failed: ' . $ocrResult['error'], 500, 'ocr_failed');
    }

    $webPath = '/public/uploads/' . $filename;
    $text = trim($ocrResult['text'] ?? '');
    $words = $ocrResult['words'] ?? [];

    // Parse the recognised text into structured fields (nothing is auto-committed).
    $parsed = title_parse_text($text, $pdo);
    $fields = $parsed['fields'];
    $fieldConf = $parsed['confidence'];
    $overall = (int)$parsed['overall'];

    $stmt = $pdo->prepare("
        INSERT INTO title_scans
            (parcel_id, image_path, status, ocr_engine, ocr_raw, ocr_words, parsed_fields, field_confidence, overall_confidence, created_by)
        VALUES
            (NULL, :p, 'parsed', :eng, :raw, :words::jsonb, :fields::jsonb, :conf::jsonb, :overall, :u)
        RETURNING id, image_path, status, ocr_raw, parsed_fields, field_confidence, overall_confidence, created_at");
    $stmt->execute([
        ':p'       => $webPath,
        ':eng'     => 'tesseract lao+eng --psm 6',
        ':raw'     => $text,
        ':words'   => json_encode($words, JSON_UNESCAPED_UNICODE),
        ':fields'  => json_encode($fields, JSON_UNESCAPED_UNICODE),
        ':conf'    => json_encode($fieldConf, JSON_UNESCAPED_UNICODE),
        ':overall' => $overall,
        ':u'       => $me['sub'],
    ]);
    json_ok($stmt->fetch(), 201);
}

json_error('Method not allowed', 405, 'method_not_allowed');
