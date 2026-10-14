<?php
// API v1: zone rates (flat, location-based) — GET list, PUT update
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';
require_once __DIR__ . '/../../lib/slip.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];
$me = require_auth(['officer', 'admin']);

// ---- GET: list rates ----//
if ($method === 'GET') {
    $stmt = $pdo->query("SELECT z.*, d.name_lo AS district_name
          FROM zones_rates z JOIN districts d ON d.id = z.district_id
          ORDER BY d.name_lo, z.road_category");
    $rates = $stmt->fetchAll();
    json_ok(['rates' => $rates]);
}

// ---- PUT /rates/{id} ----//
if ($method === 'PUT') {
    $id = (int)($_GET['id'] ?? '0');
    if (!$id) { json_error('Rate id required', 400, 'missing_id'); }
    $body = read_json_body();
    $rate = $body['rate_per_sqm'] ?? null;
    $note = $body['note'] ?? null;

    $err = collect_errors([
        'rate_per_sqm' => validate_required($rate, 'rate') ?: validate_positive($rate, 'rate'),
        'district_id' => validate_required($body['district_id'] ?? null, 'district') ?: validate_int($body['district_id'] ?? null, 'district'),
        'road_category' => validate_required($body['road_category'] ?? null, 'road') ?: validate_enum($body['road_category'] ?? null, ['main','branch','alley','none'], 'road'),
    ]);
    reject_on_errors($err);
    if (!isset($body['district_id']) || !isset($body['road_category'])) {
        json_error('district_id and road_category required', 400, 'missing_fields');
    }

    // Ensure district exists
    $stmt = $pdo->prepare("SELECT id FROM districts WHERE id = :id");
    $stmt->execute([':id' => (int)$body['district_id']]);
    if (!$stmt->fetch()) { json_error('District not found', 404, 'district_not_found'); }

    $stmt = $pdo->prepare("UPDATE zones_rates SET rate_per_sqm = :rate, note = :note, updated_at = CURRENT_TIMESTAMP
        WHERE id = :id AND district_id = :did AND road_category = :rc");
    $stmt->execute([':rate' => (float)$rate, ':note' => $note, ':id' => $id, ':did' => (int)$body['district_id'], ':rc' => $body['road_category']]);
    if ($stmt->rowCount() === 0) {
        // Insert if not found (create)
        $stmt = $pdo->prepare("INSERT INTO zones_rates (district_id, road_category, rate_per_sqm, note) VALUES (:did, :rc, :rate, :note)");
        $stmt->execute([':did' => (int)$body['district_id'], ':rc' => $body['road_category'], ':rate' => (float)$rate, ':note' => $note]);
    }
    json_ok(['rate_per_sqm' => (float)$rate], 200);
}
json_error('Method not allowed', 405, 'method_not_allowed');
