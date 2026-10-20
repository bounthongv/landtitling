<?php
// API v1: title scans — POST upload+OCR, GET list / single
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/ocr.php';
require_once __DIR__ . '/../../lib/title_parser.php';
require_once __DIR__ . '/../../lib/title_template.php';
require_once __DIR__ . '/../../lib/auth.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];
$me = require_auth(['officer', 'admin']);

/**
 * Normalize Lao land use description to internal code.
 * Handles variations in spacing, punctuation, and Lao/English.
 */
function normalize_land_use_class(string $raw): string {
    $raw = mb_strtolower(trim($raw), 'UTF-8');
    $raw = preg_replace('/\s+/', ' ', $raw); // normalize whitespace
    
    // Remove common prefixes/suffixes and punctuation
    $raw = preg_replace('/^[\.\s]+|[\.\s]+$/', '', $raw);
    $raw = str_replace(['..', '.', ':', '|'], '', $raw);
    
    // Lao keywords mapping
    $laoMap = [
        'ກະສິກໍາ' => 'agricultural',
        'ການຄ້າ' => 'commercial',
        'ປະເພດທີ່ດິນ' => 'commercial',
        'ທີ່ຢູ່ອາໄສ' => 'residential',
        'ການຄ້າ' => 'commercial',
        'ພານິດ' => 'commercial',
        'ອາໄສ' => 'residential',
        'ສະຖານະ' => 'commercial',
    ];
    
    // English keywords mapping
    $engMap = [
        'agricultural' => 'agricultural',
        'farming' => 'agricultural',
        'commercial' => 'commercial',
        'business' => 'commercial',
        'residential' => 'residential',
        'housing' => 'residential',
        'industrial' => 'commercial',
    ];
    
    // Try Lao keywords first
    foreach ($laoMap as $keyword => $code) {
        if (mb_stripos($raw, $keyword) !== false) {
            return $code;
        }
    }
    
    // Try English keywords
    foreach ($engMap as $keyword => $code) {
        if (stripos($raw, $keyword) !== false) {
            return $code;
        }
    }
    
    return 'residential'; // default fallback
}

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
        // Detailed diagnostics (logged + returned) so we can see why the move fails.
        $errlog = 'move_uploaded_file FAILED: tmp=' . $file['tmp_name']
            . ' exists=' . (is_file($file['tmp_name']) ? 'yes' : 'no')
            . ' is_uploaded=' . (is_uploaded_file($file['tmp_name']) ? 'yes' : 'no')
            . ' dest=' . $destPath
            . ' dest_dir_writable=' . (is_writable($uploadDir) ? 'yes' : 'no')
            . ' file_err=' . ($file['error'] ?? '?')
            . ' client=' . ($_SERVER['REMOTE_ADDR'] ?? '?');
        error_log($errlog);
        json_error('Failed to save image: ' . $errlog, 500, 'upload_failed');
    }

    // Read OCR method from form (default: tesseract)
    $ocrMethod = $_POST['ocr_method'] ?? 'tesseract';

    // BOTH engines run through the same path: each returns a structured_fields
    // array keyed by the canonical field names (the same keys title_parse_text
    // produces). Tesseract parses its raw text via title_parse_text(); Gemini
    // maps its JSON output. The OCR "extracted text" box always shows the
    // structured JSON so the officer can verify what was read.
    if ($ocrMethod === 'gemini') {
        $ocrResult = ocr_gemini($destPath, $cfg);
        $engineLabel = 'gemini-2.5-flash';
        $structuredFields = $ocrResult['structured_fields'] ?? [];
        $rawText = $ocrResult['text'] ?? '';
    } else {
        // Tesseract: full-page text -> fuzzy structure (fallback + audit trail)
        $tessdataDir = $cfg['ocr_tessdata_dir'] ?? '';
        $ocrResult = ocr_extract_from_image($destPath, $tessdataDir);
        $engineLabel = 'tesseract-lao-psm3+roi';
        $rawText = trim($ocrResult['text'] ?? '');
        $parsed = title_parse_text($rawText, $pdo);
        $structuredFields = $parsed['fields'] ?? [];
    }
    if (isset($ocrResult['error'])) {
        json_error('OCR failed: ' . $ocrResult['error'], 500, 'ocr_failed');
    }

    $webPath = '/public/uploads/' . $filename;
    $fields = $structuredFields;
    $fieldConf = [];

    // Normalize values to canonical form and set per-field confidence.
    foreach ($fields as $key => $value) {
        // Normalize land_use_class from descriptive text to internal codes
        if ($key === 'land_use_class' && is_string($value)) {
            $fields[$key] = normalize_land_use_class($value);
        }
        // Gemini provides no per-field confidence; assign a high default.
        $fieldConf[$key] = $ocrMethod === 'gemini' ? 95 : ($parsed['confidence'][$key] ?? 60);
    }

    // Pass 2: position-based ROI read of the standard form layout. Each region is a
    // full "label: value" line; roi_extract_value() pulls just the value out of it.
    // Where a ROI value came through it overrides the structured guess
    // ONLY if its confidence is higher than the current confidence.
    $roi = ocr_extract_fields($destPath, title_template_regions(), $tessdataDir ?? '');
    if ($roi['error'] === null && !empty($roi['fields'])) {
        foreach ($roi['fields'] as $key => $r) {
            $raw = trim((string)$r['text']);
            if ($raw === '') continue;
            $c = (int)$r['confidence'];
            if ($c < 50) continue;                       // too noisy to trust
            $ex = roi_extract_value($key, $raw);
            if (!$ex['ok'] || $ex['value'] === null || $ex['value'] === '') continue;
            // Only override if ROI confidence is higher than current confidence
            $currentConf = $fieldConf[$key] ?? 0;
            if ($c > $currentConf) {
                $fields[$key] = $ex['value'];
                $fieldConf[$key] = $c;
            }
        }
    }

    // Final geography gate (runs after both passes): only trust auto-resolved
    // district/village ids on a NEAR-EXACT match (confidence >= 90). The fuzzy
    // matcher scores even completely different Lao district names ~70% (shared
    // consonants), so a 70-ish threshold misattributes parcels; 90 accepts a
    // 1-char OCR typo (~97%) but rejects unrelated names. Anything below is
    // nulled so the officer picks it in review instead of a wrong fee.
    if (!empty($fields['district_name'])) {
        [$did, $dc] = title_resolve_district($fields['district_name'], $pdo);
        $fields['district_id'] = ($did && $dc >= 90) ? $did : null;
        if ($did && $dc >= 90) $fieldConf['district_id'] = max($fieldConf['district_id'] ?? 0, $dc);
    } else {
        $fields['district_id'] = null;
    }
    if (!empty($fields['village_name'])) {
        [$vid, $vc] = title_resolve_village($fields['village_name'], $fields['district_id'] ?? null, $pdo);
        $fields['village_id'] = ($vid && $vc >= 90) ? $vid : null;
        if ($vid && $vc >= 90) $fieldConf['village_id'] = max($fieldConf['village_id'] ?? 0, $vc);
    } else {
        $fields['village_id'] = null;
    }

    $overall = title_overall_confidence($fieldConf);

    // The OCR raw box shows the structured JSON (both engines) so the officer can
    // verify what was parsed; the full page text is kept for the audit trail.
    $structuredJson = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $fullText = $rawText;

    $stmt = $pdo->prepare("
        INSERT INTO title_scans
            (parcel_id, image_path, status, ocr_engine, ocr_raw, ocr_words, parsed_fields, field_confidence, overall_confidence, created_by)
        VALUES
            (NULL, :p, 'parsed', :eng, :raw, :words::jsonb, :fields::jsonb, :conf::jsonb, :overall, :u)
        RETURNING id, image_path, status, ocr_engine, ocr_raw, parsed_fields, field_confidence, overall_confidence, created_at");
    $stmt->execute([
        ':p'       => $webPath,
        ':eng'     => $engineLabel,
        ':raw'     => $structuredJson,
        ':words'   => json_encode([], JSON_UNESCAPED_UNICODE),
        ':fields'  => json_encode($fields, JSON_UNESCAPED_UNICODE),
        ':conf'    => json_encode($fieldConf, JSON_UNESCAPED_UNICODE),
        ':overall' => $overall,
        ':u'       => $me['sub'],
    ]);
    json_ok($stmt->fetch(), 201);
}

json_error('Method not allowed', 405, 'method_not_allowed');
