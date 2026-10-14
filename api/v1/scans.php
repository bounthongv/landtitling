<?php
// API v1: title scans — POST upload+OCR, GET list
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/ocr.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';
require_once __DIR__ . '/../../lib/slip.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];

// ---- GET: list scans ----//
if ($method === 'GET') {
    $me = require_auth(['officer', 'admin']);
    $status = $_GET['status'] ?? 'pending'; // pending | confirmed
    $stmt = $pdo->prepare("SELECT ts.*, p.parcel_no, p.area_sqm, p.road_category, p.status as parcel_status,
                d.name_lo AS district_name, v.name_lo AS village_name
          FROM title_scans ts
          LEFT JOIN parcels p ON p.id = ts.parcel_id
          LEFT JOIN villages v ON p.village_id = v.id
          LEFT JOIN districts d ON p.district_id = d.id
          WHERE ts.verified_by IS NULL
            AND (:s = 'pending' OR ts.id IN (SELECT DISTINCT parcel_id FROM parcels WHERE status = 'confirmed'))
          ORDER BY ts.created_at DESC
          LIMIT 100");
    $stmt->execute([':s' => $status]);
    $scans = $stmt->fetchAll();
    // Attach OCR text
    foreach ($scans as &$s) { $s['ocr_text'] = $s['ocr_raw'] ?? $s['ocr_extracted'] ?? null; }
    unset($s);
    json_ok(['scans' => $scans], 200, ['count' => count($scans)]);
}

// ---- POST: upload + OCR ----//
if ($method === 'POST') {
    $me = require_auth(['officer', 'admin']);
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
    $text = trim($ocrResult['text'] ?? '');
    $ocr_extracted = [];
    if ($text !== '') {
        $ocr_extracted = ['raw_text' => $text];
        // Try to pull structured fields out if present
        if (preg_match('/([A-Z0-9]{5,})/i', $text, $m)) { $ocr_extracted['parcel_no_hint'] = $m[1]; }
    }
    $stmt = $pdo->prepare("INSERT INTO title_scans (parcel_id, image_path, ocr_raw, ocr_extracted, created_by)
        VALUES (NULL, :p, :raw, :ext, :u) RETURNING id, image_path, ocr_extracted");
    $stmt->execute([
        ':p' => $destPath,
        ':raw' => $ocr_raw ?? '',
        ':ext' => $ocr_extracted,
        ':u' => $me['sub'],
    ]);
    $scan = $stmt->fetch();
    json_ok($scan, 201);
}
json_error('Method not allowed', 405, 'method_not_allowed');
