<?php
// API v1: agent/receipt.php — receipt for an agent cash payment.
//
// The project has no URL router (Apache serves the .php files directly), so the
// payment is named with a query string, matching the rest of the API
// (e.g. payments.php?id=N&slip=1):
//   GET agent/receipt.php?payment_id=123            -> JSON (app renders it)
//   GET agent/receipt.php?payment_id=123&format=html -> printable HTML
require_once __DIR__ . '/../../../lib/db.php';
require_once __DIR__ . '/../../../lib/auth.php';

$pdo    = db_connect();
$method = $_SERVER['REQUEST_METHOD'];
$me     = require_auth(['officer', 'citizen', 'admin']);
$meId   = (int)$me['sub'];

if ($method !== 'GET') {
    json_error('Method not allowed', 405, 'method_not_allowed');
}

$paymentId = (int)($_GET['payment_id'] ?? $_GET['id'] ?? 0);
if ($paymentId <= 0) {
    json_error('payment_id is required', 400, 'missing_payment_id');
}

$st = $pdo->prepare("SELECT p.*, pr.receipt_html,
        u.username AS agent_username, u.agent_role,
        par.parcel_no, par.sub_parcel_no, par.map_ref_no, par.title_no, par.area_sqm,
        d.name_lo AS district_name, v.name_lo AS village_name
    FROM payments p
    LEFT JOIN payment_receipts pr ON pr.payment_id = p.id
    LEFT JOIN users u ON u.id = p.agent_id
    LEFT JOIN parcels par ON par.id = p.parcel_id
    LEFT JOIN districts d ON d.id = par.district_id
    LEFT JOIN villages v ON v.id = par.village_id
    WHERE p.id = :pid");
$st->execute([':pid' => $paymentId]);
$row = $st->fetch();

if (!$row) {
    json_error('Payment not found', 404, 'not_found');
}

// Only the collecting agent, the payer's own account, or staff may read it.
$isAgent   = (int)($row['agent_id'] ?? 0) === $meId;
$isCitizen = $me['role'] === 'citizen' && (int)($row['citizen_user_id'] ?? 0) === $meId;
$isStaff   = in_array($me['role'], ['officer', 'admin'], true);
if (!$isAgent && !$isCitizen && !$isStaff) {
    json_error('Forbidden', 403, 'forbidden');
}

if (($_GET['format'] ?? 'json') === 'html') {
    header('Content-Type: text/html; charset=utf-8');
    echo $row['receipt_html'] ?: '<html><body>Receipt not available</body></html>';
    exit;
}

json_ok([
    'payment_id'    => (int)$row['id'],
    'reference_no'  => $row['reference_no'],
    'amount'        => (float)$row['amount'],
    'status'        => $row['status'],
    'method'        => $row['method'],
    'payment_method'=> $row['payment_method'],
    'fee_year'      => (int)$row['fee_year'],
    'collected_at'  => $row['collected_at'],
    'handed_over_at'=> $row['handed_over_at'],
    'agent_id'      => $row['agent_id'] !== null ? (int)$row['agent_id'] : null,
    'agent_username'=> $row['agent_username'],
    'agent_role'    => $row['agent_role'],
    'payer_name'    => $row['payer_name'],
    'payer_phone'   => $row['payer_phone'],
    'payer_id_card' => $row['payer_id_card'],
    'parcel_no'     => $row['parcel_no'],
    'sub_parcel_no' => $row['sub_parcel_no'],
    'map_ref_no'    => $row['map_ref_no'],
    'title_no'      => $row['title_no'],
    'area_sqm'      => $row['area_sqm'] !== null ? (float)$row['area_sqm'] : null,
    'district_name' => $row['district_name'],
    'village_name'  => $row['village_name'],
    'receipt_html'  => $row['receipt_html'],
]);
