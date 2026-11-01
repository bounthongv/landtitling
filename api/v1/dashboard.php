<?php
// API v1: dashboard — read-only monitoring aggregates for manager accounts.
// Every figure is scoped by staff_scope(): district managers see only their
// district, province managers only their province, and may narrow to one
// district. Managers never write here; this endpoint only reads.
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/fee.php';

$me = require_auth(['manager']);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed', 405, 'method_not_allowed');
}

$scope = staff_scope($me);
$pdo = db_connect();

$scopeDistrict = $scope['district_id'];
// Province-level staff are pinned to Savannakhet until the multi-province rollout.
$provinceId = staff_province_id($me, $pdo);
$filterDistrict = ($scopeDistrict === null) ? max(0, (int)($_GET['district_id'] ?? 0)) : 0;
$year = (int)($_GET['fee_year'] ?? 0);

// A province user may only narrow to a district that belongs to their province.
if ($filterDistrict > 0 && $provinceId) {
    $chk = $pdo->prepare("SELECT 1 FROM districts WHERE id = :id AND province_id = :pv");
    $chk->execute([':id' => $filterDistrict, ':pv' => $provinceId]);
    if (!$chk->fetchColumn()) {
        $filterDistrict = 0;
    }
}

$years = array_map('intval', $pdo->query("SELECT year FROM fee_years ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN));
if (!$year) {
    $year = (int)date('Y');
}
if (!in_array($year, $years, true)) {
    $years[] = $year;
    rsort($years);
}

/** District/province scope clause for a table alias that has district_id. */
function dash_scope_sql(string $alias, array $scope, int $provinceId, int $filterDistrict, array &$params): array
{
    $alias = preg_replace('/[^A-Za-z0-9_]/', '', $alias);
    $where = [];
    if ($scope['district_id'] !== null) {
        $where[] = $alias . '.district_id = :sd';
        $params[':sd'] = (int)$scope['district_id'];
    } elseif ($provinceId) {
        $where[] = $alias . '.district_id IN (SELECT id FROM districts WHERE province_id = :pv)';
        $params[':pv'] = (int)$provinceId;
    }
    if ($filterDistrict) {
        $where[] = $alias . '.district_id = :df';
        $params[':df'] = (int)$filterDistrict;
    }
    return $where;
}

function dash_where(array $clauses): string
{
    return $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
}

$pen = fee_penalty_sql('o');

// ---- Fee-year KPIs --------------------------------------------------------
$params = [];
$where = dash_scope_sql('o', $scope, $provinceId, $filterDistrict, $params);
$where[] = 'o.fee_year = :fy';
$params[':fy'] = $year;

$stmt = $pdo->prepare("SELECT
        COUNT(*) AS obligations,
        COUNT(*) FILTER (WHERE o.status IN ('PAID','OVERPAID')) AS paid_obligations,
        COUNT(*) FILTER (WHERE o.status IN ('UNPAID','PARTIAL')) AS open_obligations,
        COUNT(*) FILTER (WHERE o.status IN ('UNPAID','PARTIAL') AND o.due_date < CURRENT_DATE) AS overdue_obligations,
        COUNT(*) FILTER (WHERE o.status = 'WAIVED') AS waived_obligations,
        COALESCE(SUM(o.amount_due), 0) AS assessed_kip,
        COALESCE(SUM(o.amount_paid), 0) AS collected_kip,
        COALESCE(SUM(LEAST(o.amount_paid, o.amount_due)), 0) AS tax_collected_kip,
        COALESCE(SUM(CASE WHEN o.status IN ('UNPAID','PARTIAL')
            THEN GREATEST(o.amount_due + $pen - o.amount_paid, 0) ELSE 0 END), 0) AS outstanding_kip,
        COALESCE(SUM(CASE WHEN o.status IN ('UNPAID','PARTIAL') AND o.due_date < CURRENT_DATE
            THEN GREATEST(o.amount_due + $pen - o.amount_paid, 0) ELSE 0 END), 0) AS overdue_kip,
        COALESCE(SUM(CASE WHEN o.status IN ('UNPAID','PARTIAL') THEN $pen ELSE 0 END), 0) AS surcharge_kip,
        COALESCE(SUM(CASE WHEN o.status = 'WAIVED' THEN o.amount_due ELSE 0 END), 0) AS waived_kip
    FROM fee_obligations o
    " . dash_where($where));
$stmt->execute($params);
$k = $stmt->fetch(PDO::FETCH_ASSOC);

$assessed = (float)$k['assessed_kip'];
$waived = (float)$k['waived_kip'];
$taxCollected = (float)$k['tax_collected_kip'];
$base = $assessed - $waived;
$rate = $base > 0 ? min(100.0, max(0.0, 100 * $taxCollected / $base)) : 0.0;

$kpis = [
    'obligations'        => (int)$k['obligations'],
    'paid_obligations'   => (int)$k['paid_obligations'],
    'open_obligations'   => (int)$k['open_obligations'],
    'overdue_obligations'=> (int)$k['overdue_obligations'],
    'waived_obligations' => (int)$k['waived_obligations'],
    'assessed_kip'       => $assessed,
    'collected_kip'      => (float)$k['collected_kip'],
    'tax_collected_kip'  => $taxCollected,
    'outstanding_kip'    => (float)$k['outstanding_kip'],
    'overdue_kip'        => (float)$k['overdue_kip'],
    'surcharge_kip'      => (float)$k['surcharge_kip'],
    'waived_kip'         => $waived,
    'collection_rate'    => round($rate, 1),
];

// ---- Arrears across all years ---------------------------------------------
$paramsAll = [];
$whereAll = dash_scope_sql('o', $scope, $provinceId, $filterDistrict, $paramsAll);
$stmt = $pdo->prepare("SELECT
        COUNT(*) FILTER (WHERE o.status IN ('UNPAID','PARTIAL')) AS open_obligations,
        COUNT(*) FILTER (WHERE o.status IN ('UNPAID','PARTIAL') AND o.due_date < CURRENT_DATE) AS overdue_obligations,
        COALESCE(SUM(CASE WHEN o.status IN ('UNPAID','PARTIAL')
            THEN GREATEST(o.amount_due + $pen - o.amount_paid, 0) ELSE 0 END), 0) AS outstanding_kip,
        COALESCE(SUM(CASE WHEN o.status IN ('UNPAID','PARTIAL') AND o.due_date < CURRENT_DATE
            THEN GREATEST(o.amount_due + $pen - o.amount_paid, 0) ELSE 0 END), 0) AS overdue_kip
    FROM fee_obligations o
    " . dash_where($whereAll));
$stmt->execute($paramsAll);
$ay = $stmt->fetch(PDO::FETCH_ASSOC);
$allYears = [
    'open_obligations'    => (int)$ay['open_obligations'],
    'overdue_obligations' => (int)$ay['overdue_obligations'],
    'outstanding_kip'     => (float)$ay['outstanding_kip'],
    'overdue_kip'         => (float)$ay['overdue_kip'],
];

// ---- Parcels and land area ------------------------------------------------
$paramsP = [];
$whereP = dash_scope_sql('par', $scope, $provinceId, $filterDistrict, $paramsP);
$stmt = $pdo->prepare("SELECT
        COUNT(*) AS parcels,
        COALESCE(SUM(par.area_sqm), 0) AS area_sqm,
        COUNT(*) FILTER (WHERE par.status IN ('confirmed','active')) AS confirmed_parcels,
        COUNT(*) FILTER (WHERE par.status = 'draft') AS draft_parcels,
        COUNT(*) FILTER (WHERE par.status = 'archived') AS archived_parcels
    FROM parcels par
    " . dash_where($whereP));
$stmt->execute($paramsP);
$p = $stmt->fetch(PDO::FETCH_ASSOC);
$parcels = [
    'parcels'           => (int)$p['parcels'],
    'area_sqm'          => (float)$p['area_sqm'],
    'confirmed_parcels' => (int)$p['confirmed_parcels'],
    'draft_parcels'     => (int)$p['draft_parcels'],
    'archived_parcels'  => (int)$p['archived_parcels'],
];

/** Payment scope clause (payments join parcels, so always alias `par`). */
function dash_payment_scope(array $scope, int $provinceId, int $filterDistrict, int $year, array &$params): array
{
    $where = dash_scope_sql('par', $scope, $provinceId, $filterDistrict, $params);
    $where[] = 'pay.fee_year = :fy';
    $params[':fy'] = $year;
    return $where;
}

// ---- Receipt pipeline -----------------------------------------------------
$paramsR = [];
$whereR = dash_payment_scope($scope, $provinceId, $filterDistrict, $year, $paramsR);
$stmt = $pdo->prepare("SELECT pay.status, COUNT(*) AS n,
        COALESCE(SUM(pay.amount), 0) AS amount_kip, MIN(pay.created_at) AS oldest
    FROM payments pay
    JOIN parcels par ON par.id = pay.parcel_id
    " . dash_where($whereR) . "
    GROUP BY pay.status");
$stmt->execute($paramsR);

$receipts = [];
foreach (['ISSUED', 'SUBMITTED', 'PAID', 'REJECTED', 'REFUNDED'] as $s) {
    $receipts[$s] = ['count' => 0, 'amount_kip' => 0.0, 'oldest' => null];
}
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $status = (string)$r['status'];
    if (!isset($receipts[$status])) {
        continue;
    }
    $receipts[$status] = [
        'count'      => (int)$r['n'],
        'amount_kip' => (float)$r['amount_kip'],
        'oldest'     => $r['oldest'],
    ];
}

// ---- Breakdown: district (province view) or village (district/filtered) ---
$group = ($scopeDistrict !== null || $filterDistrict > 0) ? 'village' : 'district';
$paramsB = [];
$whereB = dash_scope_sql('o', $scope, $provinceId, $filterDistrict, $paramsB);
$whereB[] = 'o.fee_year = :fy';
$paramsB[':fy'] = $year;

if ($group === 'district') {
    $stmt = $pdo->prepare("SELECT o.district_id AS id, d.name_lo AS name,
            COUNT(DISTINCT o.parcel_id) AS parcels,
            COALESCE(SUM(o.amount_due), 0) AS assessed_kip,
            COALESCE(SUM(LEAST(o.amount_paid, o.amount_due)), 0) AS collected_kip,
            COALESCE(SUM(CASE WHEN o.status IN ('UNPAID','PARTIAL')
                THEN GREATEST(o.amount_due + $pen - o.amount_paid, 0) ELSE 0 END), 0) AS outstanding_kip
        FROM fee_obligations o
        JOIN districts d ON d.id = o.district_id
        " . dash_where($whereB) . "
        GROUP BY o.district_id, d.name_lo
        ORDER BY outstanding_kip DESC, d.name_lo");
} else {
    $stmt = $pdo->prepare("SELECT v.id AS id, v.name_lo AS name,
            COUNT(DISTINCT o.parcel_id) AS parcels,
            COALESCE(SUM(o.amount_due), 0) AS assessed_kip,
            COALESCE(SUM(LEAST(o.amount_paid, o.amount_due)), 0) AS collected_kip,
            COALESCE(SUM(CASE WHEN o.status IN ('UNPAID','PARTIAL')
                THEN GREATEST(o.amount_due + $pen - o.amount_paid, 0) ELSE 0 END), 0) AS outstanding_kip
        FROM fee_obligations o
        JOIN parcels par ON par.id = o.parcel_id
        JOIN villages v ON v.id = par.village_id
        " . dash_where($whereB) . "
        GROUP BY v.id, v.name_lo
        ORDER BY outstanding_kip DESC, v.name_lo");
}
$stmt->execute($paramsB);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalGroups = count($rows);
if ($group === 'village' && $totalGroups > 15) {
    $rows = array_slice($rows, 0, 15);
}
$breakdownRows = [];
foreach ($rows as $r) {
    $assessedRow = (float)$r['assessed_kip'];
    $collectedRow = (float)$r['collected_kip'];
    $breakdownRows[] = [
        'id'              => (int)$r['id'],
        'name'            => (string)$r['name'],
        'parcels'         => (int)$r['parcels'],
        'assessed_kip'    => $assessedRow,
        'collected_kip'   => $collectedRow,
        'outstanding_kip' => (float)$r['outstanding_kip'],
        'rate'            => $assessedRow > 0 ? round(min(100, 100 * $collectedRow / $assessedRow), 1) : 0.0,
    ];
}

// ---- Arrears aging --------------------------------------------------------
$paramsA = [];
$whereA = dash_scope_sql('o', $scope, $provinceId, $filterDistrict, $paramsA);
$whereA[] = 'o.fee_year = :fy';
$paramsA[':fy'] = $year;
$whereA[] = "o.status IN ('UNPAID','PARTIAL')";
$whereA[] = 'o.due_date < CURRENT_DATE';
$stmt = $pdo->prepare("SELECT CASE
            WHEN days <= 30 THEN '0-30'
            WHEN days <= 90 THEN '31-90'
            WHEN days <= 180 THEN '91-180'
            ELSE '180+' END AS bucket,
        COUNT(*) AS n,
        COALESCE(SUM(outstanding), 0) AS outstanding_kip
    FROM (SELECT CURRENT_DATE - o.due_date AS days,
                 GREATEST(o.amount_due + $pen - o.amount_paid, 0) AS outstanding
          FROM fee_obligations o
          " . dash_where($whereA) . ") x
    GROUP BY 1");
$stmt->execute($paramsA);
$byBucket = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $byBucket[$r['bucket']] = ['count' => (int)$r['n'], 'outstanding_kip' => (float)$r['outstanding_kip']];
}
$aging = [];
foreach (['0-30', '31-90', '91-180', '180+'] as $b) {
    $aging[] = [
        'bucket'          => $b,
        'count'           => $byBucket[$b]['count'] ?? 0,
        'outstanding_kip' => $byBucket[$b]['outstanding_kip'] ?? 0.0,
    ];
}

// ---- Recent payments ------------------------------------------------------
$paramsRec = [];
$whereRec = dash_payment_scope($scope, $provinceId, $filterDistrict, $year, $paramsRec);
$whereRec[] = "pay.status IN ('PAID','SUBMITTED')";
$stmt = $pdo->prepare("SELECT pay.id, pay.reference_no, pay.amount, pay.method, pay.status,
        pay.created_at, pay.paid_at, par.id AS parcel_id, par.parcel_no,
        d.name_lo AS district_name, v.name_lo AS village_name
    FROM payments pay
    JOIN parcels par ON par.id = pay.parcel_id
    JOIN districts d ON d.id = par.district_id
    JOIN villages v ON v.id = par.village_id
    " . dash_where($whereRec) . "
    ORDER BY COALESCE(pay.paid_at, pay.created_at) DESC, pay.id DESC
    LIMIT 8");
$stmt->execute($paramsRec);
$recent = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $recent[] = [
        'id'            => (int)$r['id'],
        'reference_no'  => (string)$r['reference_no'],
        'amount'        => (float)$r['amount'],
        'method'        => (string)$r['method'],
        'status'        => (string)$r['status'],
        'created_at'    => $r['created_at'],
        'paid_at'       => $r['paid_at'],
        'parcel_id'     => (int)$r['parcel_id'],
        'parcel_no'     => (string)$r['parcel_no'],
        'district_name' => (string)$r['district_name'],
        'village_name'  => (string)$r['village_name'],
        'at'            => $r['paid_at'] ?: $r['created_at'],
    ];
}

// ---- Names for the scope line --------------------------------------------
$scopeDistrictName = null;
if ($scopeDistrict !== null) {
    $st = $pdo->prepare("SELECT name_lo FROM districts WHERE id = :id");
    $st->execute([':id' => $scopeDistrict]);
    $scopeDistrictName = $st->fetchColumn() ?: null;
}
$filteredDistrict = null;
if ($filterDistrict) {
    $st = $pdo->prepare("SELECT name_lo FROM districts WHERE id = :id");
    $st->execute([':id' => $filterDistrict]);
    $filteredDistrict = ['id' => $filterDistrict, 'name' => ($st->fetchColumn() ?: '')];
}
$provinceName = null;
if ($provinceId) {
    $st = $pdo->prepare("SELECT name_lo FROM provinces WHERE id = :id");
    $st->execute([':id' => $provinceId]);
    $provinceName = $st->fetchColumn() ?: null;
}

json_ok([
    'as_of'    => date('Y-m-d H:i'),
    'fee_year' => $year,
    'years'    => $years,
    'scope'    => [
        'district_id'      => $scopeDistrict,
        'district_name'    => $scopeDistrictName,
        'filtered_district'=> $filteredDistrict,
        'province_name'    => $provinceName,
    ],
    'kpis'      => $kpis,
    'all_years' => $allYears,
    'parcels'   => $parcels,
    'receipts'  => $receipts,
    'breakdown' => [
        'group'        => $group,
        'rows'         => $breakdownRows,
        'total_groups' => $totalGroups,
    ],
    'aging'  => $aging,
    'recent' => $recent,
]);
