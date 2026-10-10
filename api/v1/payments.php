<?php
// API v1: payments — create (ISSUED), list, slip, receipt, verify, reject
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';
require_once __DIR__ . '/../../lib/slip.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db_connect();

// ---- CREATE payment (citizen) ----
if ($method === 'POST' && empty($_GET['id'])) {
    $me = require_auth(['citizen', 'officer']);
    $body = read_json_body();

    $parcel_id = $body['parcel_id'] ?? null;
    $district_id = $body['district_id'] ?? null;
    $area_sqm = $body['area_sqm'] ?? null;
    $err = collect_errors([
        'parcel_id'   => validate_required($parcel_id, 'parcel') ?: validate_int($parcel_id, 'parcel'),
        'district_id' => validate_required($district_id, 'district') ?: validate_int($district_id, 'district'),
        'area_sqm'    => validate_required($area_sqm, 'area') ?: validate_positive($area_sqm, 'area'),
    ]);
    reject_on_errors($err);

    // Fetch the parcel to get road_category
    $stmt = $pdo->prepare("SELECT p.*, v.name_lo AS village_name, d.name_lo AS district_name
        FROM parcels p
        JOIN villages v ON p.village_id = v.id
        JOIN districts d ON p.district_id = d.id
        WHERE p.id = :id");
    $stmt->execute([':id' => (int)$parcel_id]);
    $parcel = $stmt->fetch();
    if (!$parcel) {
        json_error('Parcel not found', 404, 'not_found');
    }

    // Fetch zone rate (effective)
    $stmt = $pdo->prepare("SELECT rate_per_sqm FROM zones_rates
        WHERE district_id = :d AND road_category = :rc AND valid_from <= CURRENT_DATE
        ORDER BY valid_from DESC LIMIT 1");
    $stmt->execute([':d' => (int)$district_id, ':rc' => $parcel['road_category']]);
    $rate = $stmt->fetchColumn();
    if ($rate === false) {
        json_error('Zone rate not found', 404, 'rate_not_found');
    }
    $rate = (float)$rate;
    $fee_total = round($rate * (float)$area_sqm, 2);
    $slip_no = slip_generate_no($pdo);

    // Create payment (ISSUED)
    $stmt = $pdo->prepare("INSERT INTO payments
        (parcel_id, citizen_id, district_id, area_sqm, zone_rate, fee_total, status, slip_no, slip_qr)
        VALUES (:parcel_id, :citizen_id, :district_id, :area_sqm, :zone_rate, :fee_total, 'ISSUED', :slip_no, :slip_qr)
        RETURNING id, slip_no, fee_total, status, created_at");
    $qr = slip_qr_payload($slip_no, $fee_total);
    $stmt->execute([
        ':parcel_id'   => (int)$parcel_id,
        ':citizen_id'  => isset($body['citizen_id']) ? (int)$body['citizen_id'] : null,
        ':district_id' => (int)$district_id,
        ':area_sqm'    => (float)$area_sqm,
        ':zone_rate'   => $rate,
        ':fee_total'   => $fee_total,
        ':slip_no'     => $slip_no,
        ':slip_qr'     => $qr,
    ]);
    $payment = $stmt->fetch();
    $payment['road_category'] = $parcel['road_category'];
    $payment['due_date'] = date('Y-m-d', strtotime($payment['created_at'] . ' + 30 days'));
    json_ok($payment, 201);
}

// ---- GET slip (citizen) ----
if ($method === 'GET' && isset($_GET['id']) && isset($_GET['slip'])) {
    $me = require_auth(['citizen', 'officer']);
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $payment = $stmt->fetch();
    if (!$payment) {
        json_error('Payment not found', 404, 'not_found');
    }
    $stmt = $pdo->prepare("SELECT p.*, v.name_lo AS village_name, d.name_lo AS district_name
        FROM parcels p JOIN villages v ON p.village_id = v.id JOIN districts d ON p.district_id = d.id
        WHERE p.id = :id");
    $stmt->execute([':id' => (int)$payment['parcel_id']]);
    $parcel = $stmt->fetch();
    $payment['road_category'] = $parcel['road_category'] ?? null;
    $payment['due_date'] = date('Y-m-d', strtotime($payment['created_at'] . ' + 30 days'));
    $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=' . urlencode($payment['slip_qr']);
    header('Content-Type: text/html; charset=UTF-8');
    echo slip_render_html($payment, $parcel, $parcel['district_name'], $parcel['village_name'], $qr_url);
    exit;
}

// ---- UPLOAD receipt (citizen) ----
if ($method === 'POST' && isset($_GET['id']) && isset($_GET['receipt'])) {
    $me = require_auth(['citizen', 'officer']);
    $id = (int)$_GET['id'];
    if (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
        json_error('No receipt file uploaded', 400, 'no_file');
    }
    $cfg = require __DIR__ . '/../../lib/config.php';
    $uploadDir = $cfg['uploads'];
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $ext = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
    $filename = uniqid('rcpt_', true) . '.' . $ext;
    $dest = $uploadDir . $filename;
    if (!move_uploaded_file($_FILES['receipt']['tmp_name'], $dest)) {
        json_error('Failed to save receipt', 500, 'upload_failed');
    }
    $stmt = $pdo->prepare("UPDATE payments SET slip_path = :p, status = 'ISSUED' WHERE id = :id RETURNING id, status");
    $stmt->execute([':p' => '/uploads/' . $filename, ':id' => $id]);
    $updated = $stmt->fetch();
    if (!$updated) {
        json_error('Payment not found', 404, 'not_found');
    }
    json_ok($updated);
}

// ---- VERIFY (officer) ----
if ($method === 'POST' && isset($_GET['id']) && isset($_GET['verify'])) {
    $me = require_auth(['officer', 'admin']);
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("UPDATE payments SET status='PAID', paid_at=CURRENT_TIMESTAMP, verified_by=:v
        WHERE id=:id AND status='ISSUED' RETURNING id, status");
    $stmt->execute([':v' => $me['sub'], ':id' => $id]);
    $updated = $stmt->fetch();
    if (!$updated) {
        json_error('Payment not found or not in ISSUED state', 409, 'bad_state');
    }
    json_ok($updated);
}

// ---- REJECT (officer) ----
if ($method === 'POST' && isset($_GET['id']) && isset($_GET['reject'])) {
    $me = require_auth(['officer', 'admin']);
    $id = (int)$_GET['id'];
    $body = read_json_body();
    $stmt = $pdo->prepare("UPDATE payments SET status='REJECTED', verify_note=:n, verified_by=:v
        WHERE id=:id AND status='ISSUED' RETURNING id, status");
    $stmt->execute([':n' => $body['reason'] ?? null, ':v' => $me['sub'], ':id' => $id]);
    $updated = $stmt->fetch();
    if (!$updated) {
        json_error('Payment not found or not in ISSUED state', 409, 'bad_state');
    }
    json_ok($updated);
}

// ---- LIST (citizen: mine; officer: queue) ----
if ($method === 'GET') {
    $me = require_auth();
    $status = $_GET['status'] ?? null;
    if ($me['role'] === 'citizen') {
        $sql = "SELECT * FROM payments WHERE citizen_id = :c";
        $params = [':c' => $me['sub']];
    } else {
        $sql = "SELECT * FROM payments";
        $params = [];
    }
    if ($status) {
        $sql .= " AND status = :s";
        $params[':s'] = $status;
    }
    $sql .= " ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_ok($stmt->fetchAll());
}

json_error('Not found', 404, 'not_found');