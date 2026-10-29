<?php
// API v1: parcel_fee.php — price one parcel the citizen names.
//
// The citizen knows the parcel number from their land title. The server reads
// the area and the zone code off the parcel and looks up the rate, so the amount
// is always the server's calculation: the app never computes a fee and the
// client cannot influence it. See docs/9-citizen-app-api-contract.md §2.
//
// Read-only on purpose: pricing must not create an obligation as a side effect
// of someone looking. `fee_upsert_obligation()` is what persists an assessment,
// and only the officer screens / citizen payment endpoint call it.
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/fee.php';

$pdo = db_connect();
$me  = require_auth(['citizen', 'officer', 'admin', 'manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405, 'method_not_allowed');
}

$parcelId = (int)($_GET['parcel_id'] ?? 0);
$parcelNo = trim((string)($_GET['parcel_no'] ?? ''));
$titleNo  = trim((string)($_GET['title_no'] ?? ''));
$mapRefNo = trim((string)($_GET['map_ref_no'] ?? ''));
if ($parcelId <= 0 && $parcelNo === '' && $titleNo === '') {
    json_error('parcel_id, parcel_no or title_no is required', 400, 'missing_parcel');
}
$feeYear = (int)($_GET['fee_year'] ?? 0) ?: (int)date('Y');

// District staff may only price their own district's parcels.
$scope = staff_scope($me);
$scoped = $me['role'] !== 'citizen' && $scope['district_id'] !== null;

// EXACT match only — a substring match would let anyone page through the land
// registry one character at a time. parcel_id disambiguates numbers that repeat
// across villages (parcel_no is unique only within district+village+sheet+sub).
$sql = "SELECT p.id AS parcel_id, p.parcel_no, p.sub_parcel_no, p.area_sqm,
        p.zone_code, p.road_category, p.title_no, p.map_ref_no, p.title_type,
        p.issued_date, p.status,
        d.name_lo AS district_name, v.name_lo AS village_name
    FROM parcels p
    JOIN districts d ON d.id = p.district_id
    JOIN villages  v ON v.id = p.village_id
    WHERE ";
$conds = [];
$args = [];
if ($parcelId > 0) {
    $conds[] = 'p.id = :pid';
    $args[':pid'] = $parcelId;
} else {
    if ($parcelNo !== '') { $conds[] = 'p.parcel_no = :pn'; $args[':pn'] = $parcelNo; }
    if ($titleNo !== '')  { $conds[] = 'p.title_no = :tn';  $args[':tn'] = $titleNo; }
}
// The map sheet (#26) narrows a parcel number that repeats on other sheets.
if ($mapRefNo !== '') { $conds[] = 'p.map_ref_no = :mr'; $args[':mr'] = $mapRefNo; }
$sql .= implode(' AND ', $conds);
if ($scoped) {
    $sql .= " AND p.district_id = :sd";
    $args[':sd'] = $scope['district_id'];
}
$sql .= " ORDER BY p.id";
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll();

if (!$rows) {
    json_error('No parcel with that number', 404, 'parcel_not_found');
}
if (count($rows) > 1) {
    json_error('That parcel number exists more than once — ask the land office which one is yours',
        400, 'ambiguous_parcel');
}
$parcel   = $rows[0];
$parcelId = (int)$parcel['parcel_id'];
$zoneCode = trim((string)($parcel['zone_code'] ?? ''));

// The rate comes from the parcel's zone code, never from the client.
if ($zoneCode !== '') {
    $fee = fee_compute_amount($pdo, $zoneCode, (float)$parcel['area_sqm'], $parcel['road_category'] ?? null);
} else {
    $fee = ['rate_per_sqm' => 0.0, 'amount' => 0.0, 'rate_found' => false];
}

// Owners are informational: anyone may pay for any parcel (see citizen_payments).
$ost = $pdo->prepare("SELECT o.id, o.full_name, o.id_number, po.role, po.share_pct
    FROM parcel_owners po
    JOIN owners o ON o.id = po.owner_id
    WHERE po.parcel_id = :pid
    ORDER BY o.id");
$ost->execute([':pid' => $parcelId]);
$owners = array_map(function (array $o): array {
    return [
        'id'        => (int)$o['id'],
        'full_name' => $o['full_name'],
        'id_number' => $o['id_number'],
        'role'      => $o['role'],
        'share_pct' => $o['share_pct'],
    ];
}, $ost->fetchAll());

// The assessed obligation for this year, when the parcel has been assessed.
$bst = $pdo->prepare("SELECT id, parcel_id, fee_year, zone_code, area_sqm, rate_per_sqm,
        amount_due, amount_paid, penalty, status, due_date,
        (amount_due + penalty - amount_paid) AS outstanding
    FROM fee_obligations
    WHERE parcel_id = :pid AND fee_year = :fy");
$bst->execute([':pid' => $parcelId, ':fy' => $feeYear]);
$obligation = $bst->fetch() ?: null;

// Every other year on record — the parcel's history, newest first.
$hst = $pdo->prepare("SELECT id, parcel_id, fee_year, zone_code, area_sqm, rate_per_sqm,
        amount_due, amount_paid, penalty, status, due_date,
        (amount_due + penalty - amount_paid) AS outstanding
    FROM fee_obligations
    WHERE parcel_id = :pid AND fee_year <> :fy
    ORDER BY fee_year DESC");
$hst->execute([':pid' => $parcelId, ':fy' => $feeYear]);

json_ok([
    'parcel' => [
        'parcel_id'     => $parcelId,
        'parcel_no'     => $parcel['parcel_no'],
        'sub_parcel_no' => $parcel['sub_parcel_no'],
        'area_sqm'      => (float)$parcel['area_sqm'],
        'zone_code'     => $zoneCode !== '' ? $zoneCode : null,
        'road_category' => $parcel['road_category'],
        'title_no'      => $parcel['title_no'],
        'map_ref_no'    => $parcel['map_ref_no'],
        'title_type'    => $parcel['title_type'],
        'issued_date'   => $parcel['issued_date'],
        'status'        => $parcel['status'],
        'district_name' => $parcel['district_name'],
        'village_name'  => $parcel['village_name'],
    ],
    'owners'       => $owners,
    'fee_year'     => $feeYear,
    'rate_per_sqm' => (float)$fee['rate_per_sqm'],
    'rate_found'   => (bool)$fee['rate_found'],
    'amount_due'   => (float)$fee['amount'],
    'obligation'   => $obligation,
    'history'      => $hst->fetchAll(),
]);
