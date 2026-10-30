<?php
// API v1: my_parcels.php — the signed-in citizen's parcels and their annual obligations.
// Scoped strictly by owners.citizen_user_id, so a citizen can never see someone else's land.
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/fee.php';

$pdo = db_connect();
$me = require_auth(['citizen', 'officer', 'admin', 'manager']);
$scope = staff_scope($me);

// Officers may pass ?user_id= to inspect a specific citizen; citizens are always self-scoped.
$userId = (int)$me['sub'];
if ($me['role'] !== 'citizen' && !empty($_GET['user_id'])) {
    $userId = (int)$_GET['user_id'];
    // District staff may only inspect citizens in their own district.
    if ($scope['district_id'] !== null) {
        $dst = $pdo->prepare("SELECT 1 FROM users WHERE id = :uid AND district_id = :did");
        $dst->execute([':uid' => $userId, ':did' => $scope['district_id']]);
        if (!$dst->fetch()) { json_error('Citizen outside your district', 403, 'out_of_scope'); }
    }
}

$stmt = $pdo->prepare("SELECT p.id AS parcel_id, p.parcel_no, p.sub_parcel_no, p.area_sqm,
        p.road_category, p.title_no, p.title_type, p.issued_date, p.status,
        d.name_lo AS district_name, v.name_lo AS village_name,
        o.id AS owner_id, po.role AS owner_role, po.share_pct, o.full_name AS owner_name
    FROM parcel_owners po
    JOIN owners o ON o.id = po.owner_id
    JOIN parcels p ON p.id = po.parcel_id
    JOIN districts d ON d.id = p.district_id
    JOIN villages  v ON v.id = p.village_id
    WHERE o.citizen_user_id = :uid
    ORDER BY d.name_lo, v.name_lo, p.parcel_no");
$stmt->execute([':uid' => $userId]);
$rows = $stmt->fetchAll();

// Group obligations per parcel
$byParcel = [];
foreach ($rows as $r) {
    $pid = (int)$r['parcel_id'];
    if (!isset($byParcel[$pid])) {
        $byParcel[$pid] = $r;
        $byParcel[$pid]['obligations'] = [];
    }
}

if ($byParcel) {
    $ids = implode(',', array_map('intval', array_keys($byParcel)));
    $pen = fee_penalty_sql('o');
    $ostmt = $pdo->query("SELECT o.id, o.parcel_id, o.fee_year, o.amount_due, o.amount_paid,
            $pen AS penalty, o.status, o.due_date,
            (o.amount_due + $pen - o.amount_paid) AS outstanding
        FROM fee_obligations o WHERE o.parcel_id IN ($ids) ORDER BY o.fee_year DESC");
    foreach ($ostmt->fetchAll() as $ob) {
        $pid = (int)$ob['parcel_id'];
        if (isset($byParcel[$pid])) {
            $byParcel[$pid]['obligations'][] = $ob;
        }
    }
}

$parcels = array_values($byParcel);
json_ok(['parcels' => $parcels, 'count' => count($parcels)]);