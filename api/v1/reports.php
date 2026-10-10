<?php
// API v1: reports — fee aggregates for MOF reconciliation (CSV or JSON)
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';

$me = require_auth(['admin', 'officer']);
$pdo = db_connect();

$district_id = isset($_GET['district_id']) ? (int)$_GET['district_id'] : null;
$from = $_GET['from'] ?? null; // YYYY-MM-DD
$to = $_GET['to'] ?? null;
$format = $_GET['format'] ?? 'json'; // json | csv

$where = [];
$params = [];
if ($district_id) {
    $where[] = "p.district_id = :district_id";
    $params[':district_id'] = $district_id;
}
if ($from) {
    $where[] = "p.created_at >= :from";
    $params[':from'] = $from;
}
if ($to) {
    $where[] = "p.created_at <= :to";
    $params[':to'] = $to;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "SELECT
            d.name_lo AS district,
            DATE(p.created_at) AS pay_date,
            p.transaction_type,
            parc.road_category,
            COUNT(*) AS n,
            SUM(p.fee_total) AS total
        FROM payments p
        JOIN districts d ON p.district_id = d.id
        LEFT JOIN parcels parc ON p.parcel_id = parc.id
        $whereSql
        GROUP BY d.name_lo, DATE(p.created_at), p.transaction_type, parc.road_category
        ORDER BY pay_date DESC, district";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Grand total for the period
$grandSql = "SELECT COUNT(*) AS n, SUM(fee_total) AS total FROM payments p $whereSql";
$gStmt = $pdo->prepare($grandSql);
$gStmt->execute($params);
$grand = $gStmt->fetch();

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="landfee-report.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['district', 'pay_date', 'transaction_type', 'road_category', 'count', 'total_kips']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['district'], $r['pay_date'], $r['transaction_type'], $r['road_category'], $r['n'], $r['total']]);
    }
    fclose($out);
    exit;
}

json_ok([
    'summary' => ['payments' => (int)$grand['n'], 'total_kips' => $grand['total'] ? (float)$grand['total'] : 0.0],
    'rows' => $rows,
]);