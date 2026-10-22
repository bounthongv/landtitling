<?php
// API v1: reports (design §6). One endpoint, several reports, JSON or CSV.
// Every report returns ['columns'=>[], 'rows'=>[], 'summary'=>[]] so the UI can render
// any of them generically.
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';

$me = require_auth(['officer', 'admin', 'manager']);
$scope = staff_scope($me);
$pdo = db_connect();

$report  = $_GET['report'] ?? 'collection';
$format  = $_GET['format'] ?? 'json';
$district = isset($_GET['district_id']) ? (int)$_GET['district_id'] : 0;
$year     = isset($_GET['fee_year'])    ? (int)$_GET['fee_year']    : 0;
$road     = trim((string)($_GET['road_category'] ?? ''));
$from     = trim((string)($_GET['from'] ?? ''));
$to       = trim((string)($_GET['to'] ?? ''));
$actor    = trim((string)($_GET['actor'] ?? ''));
$action   = trim((string)($_GET['action'] ?? ''));

/** Shared parcel-side filters (parcel join alias `par`). */
function rep_parcel_filters(&$where, &$params, $district, $road, $scopeDistrict = null) {
    if ($district) { $where[] = 'par.district_id = :did';   $params[':did'] = $district; }
    // District staff are scoped to their own district regardless of the filter.
    if ($scopeDistrict !== null) {
        $where[] = 'par.district_id = :scope_did';
        $params[':scope_did'] = $scopeDistrict;
    }
    if ($road !== '') {
        $where[] = 'par.road_category = :rc';
        $params[':rc'] = $road;
    }
}

switch ($report) {

// ---- Collection: kip collected by district/village/month/year/road ----
case 'collection': {
    $where = ["pay.status = 'PAID'"];
    $params = [];
    rep_parcel_filters($where, $params, $district, $road, $scope['district_id']);
    if ($year)   { $where[] = 'o.fee_year = :fy';       $params[':fy'] = $year; }
    if ($from)   { $where[] = 'pay.paid_at >= :from';    $params[':from'] = $from . ' 00:00:00'; }
    if ($to)     { $where[] = 'pay.paid_at <= :to';      $params[':to']   = $to . ' 23:59:59'; }
    $w = 'WHERE ' . implode(' AND ', $where);

    $stmt = $pdo->prepare("SELECT d.name_lo AS district, v.name_lo AS village, par.road_category,
            o.fee_year, DATE_TRUNC('month', pay.paid_at)::date AS month,
            COUNT(*) AS payments, COALESCE(SUM(pay.amount), 0) AS collected_kip
        FROM payments pay
        JOIN fee_obligations o ON o.id = pay.obligation_id
        JOIN parcels par ON par.id = pay.parcel_id
        JOIN districts d ON d.id = par.district_id
        JOIN villages  v ON v.id = par.village_id
        $w
        GROUP BY d.name_lo, v.name_lo, par.road_category, o.fee_year, DATE_TRUNC('month', pay.paid_at)
        ORDER BY month DESC, district, village");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(pay.amount),0) AS total
        FROM payments pay
        JOIN fee_obligations o ON o.id = pay.obligation_id
        JOIN parcels par ON par.id = pay.parcel_id $w");
    $stmt->execute($params);
    $tot = $stmt->fetch();
    $result = [
        'columns' => ['District', 'Village', 'Road', 'Fee year', 'Month', 'Payments', 'Collected (kip)'],
        'rows'    => $rows,
        'summary' => ['payments' => (int)($tot['n'] ?? 0), 'collected_kip' => (float)($tot['total'] ?? 0)],
    ];
    break;
}

// ---- Arrears & aging: unpaid/partial obligations, years behind ----
case 'arrears': {
    $where = [];
    $params = [];
    rep_parcel_filters($where, $params, $district, $road, $scope['district_id']);
    if ($year) { $where[] = 'o.fee_year = :fy'; $params[':fy'] = $year; }
    $w = 'WHERE ' . implode(' AND ', $where);

    $stmt = $pdo->prepare("SELECT par.parcel_no, d.name_lo AS district, v.name_lo AS village,
            o.fee_year, o.due_date, o.amount_due, o.amount_paid, o.penalty,
            (o.amount_due + o.penalty - o.amount_paid) AS outstanding,
            (CURRENT_DATE - o.due_date) AS days_overdue,
            CASE WHEN CURRENT_DATE > o.due_date THEN 'overdue' ELSE 'not yet due' END AS bucket,
            (SELECT COUNT(*) FROM fee_obligations x
              WHERE x.parcel_id = o.parcel_id AND x.status IN ('UNPAID','PARTIAL')
                AND x.fee_year <= o.fee_year) AS years_behind
        FROM fee_obligations o
        JOIN parcels par ON par.id = o.parcel_id
        JOIN districts d ON d.id = par.district_id
        JOIN villages  v ON v.id = par.village_id
        $w
        ORDER BY days_overdue DESC, d.name_lo, par.parcel_no");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT o.parcel_id) AS parcels, COUNT(*) AS obligations,
            COALESCE(SUM(o.amount_due + o.penalty - o.amount_paid),0) AS outstanding
        FROM fee_obligations o JOIN parcels par ON par.id = o.parcel_id $w");
    $stmt->execute($params);
    $tot = $stmt->fetch();
    $result = [
        'columns' => ['Parcel', 'District', 'Village', 'Year', 'Due date', 'Amount due', 'Paid',
                      'Penalty', 'Outstanding', 'Days overdue', 'Bucket', 'Years behind'],
        'rows'    => $rows,
        'summary' => [
            'parcels'          => (int)($tot['parcels'] ?? 0),
            'obligations'      => (int)($tot['obligations'] ?? 0),
            'outstanding_kip'  => (float)($tot['outstanding'] ?? 0),
        ],
    ];
    break;
}

// ---- Registration: parcels per district, scan backlog, OCR confidence ----
case 'registration': {
    $where = [];
    $params = [];
    rep_parcel_filters($where, $params, $district, $road, $scope['district_id']);
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare("SELECT d.name_lo AS district,
            COUNT(*) AS parcels,
            COALESCE(SUM(p.area_sqm),0) AS total_area_sqm,
            COUNT(*) FILTER (WHERE p.status = 'confirmed') AS confirmed,
            COUNT(*) FILTER (WHERE p.status = 'draft')     AS draft,
            COUNT(DISTINCT p.road_category) AS road_categories
        FROM parcels p JOIN districts d ON d.id = p.district_id
        $w GROUP BY d.name_lo ORDER BY d.name_lo");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $sParcels = (int)($pdo->query("SELECT COUNT(*) FROM parcels")->fetchColumn());
    $sScans   = (int)($pdo->query("SELECT COUNT(*) FROM title_scans")->fetchColumn());
    $sPending = (int)($pdo->query("SELECT COUNT(*) FROM title_scans WHERE parcel_id IS NULL")->fetchColumn());
    $sAvgConf = $pdo->query("SELECT COALESCE(AVG(overall_confidence),0) FROM title_scans
                             WHERE overall_confidence > 0")->fetchColumn();

    $confRows = $pdo->query("SELECT CASE
                WHEN overall_confidence >= 80 THEN '80-100 (high)'
                WHEN overall_confidence >= 50 THEN '50-79 (medium)'
                WHEN overall_confidence >  0 THEN '1-49 (low)'
                ELSE '0 (unparsed)' END AS bucket,
            COUNT(*) AS scans
        FROM title_scans GROUP BY 1 ORDER BY 1")->fetchAll();

    $result = [
        'columns' => ['District', 'Parcels', 'Total area (m²)', 'Confirmed', 'Draft', 'Road categories'],
        'rows'    => $rows,
        'summary' => [
            'parcels'          => $sParcels,
            'total_area_sqm'   => (float)($rows ? array_sum(array_column($rows, 'total_area_sqm')) : 0),
            'scans'            => $sScans,
            'scans_pending'    => $sPending,
            'avg_ocr_confidence' => round((float)$sAvgConf, 1),
            'confidence_distribution' => $confRows,
        ],
    ];
    break;
}

// ---- Parcel & owner register (full export) ----
case 'register': {
    $where = [];
    $params = [];
    // District staff see only their own district regardless of the explicit filter.
    if ($scope['district_id'] !== null) {
        $where[] = 'p.district_id = :scope_did';
        $params[':scope_did'] = $scope['district_id'];
    }
    if ($district && $scope['district_id'] === null) { $where[] = 'p.district_id = :did'; $params[':did'] = $district; }
    if ($road !== '') { $where[] = 'p.road_category = :rc'; $params[':rc'] = $road; }
    if (($s = trim((string)($_GET['status'] ?? ''))) !== '') {
        $where[] = 'p.status = :st'; $params[':st'] = $s;
    }
    if (!empty($_GET['q'])) {
        $where[] = '(p.parcel_no ILIKE :q OR p.title_no ILIKE :q)';
        $params[':q'] = '%' . trim((string)$_GET['q']) . '%';
    }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare("SELECT p.parcel_no, p.sub_parcel_no, p.title_no, p.title_type,
            p.book_no, p.volume_no, p.page_no, p.land_use_class, p.issued_date,
            p.area_sqm, p.road_category, d.name_lo AS district, v.name_lo AS village, p.status,
            (SELECT COUNT(*) FROM parcel_owners po WHERE po.parcel_id = p.id) AS owner_count,
            (SELECT string_agg(ow.full_name || COALESCE(' (' || ow.id_number || ')', ''), '; ')
               FROM parcel_owners po JOIN owners ow ON ow.id = po.owner_id
              WHERE po.parcel_id = p.id) AS owners
        FROM parcels p
        JOIN districts d ON d.id = p.district_id
        JOIN villages  v ON v.id = p.village_id
        $w ORDER BY d.name_lo, v.name_lo, p.parcel_no");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $result = [
        'columns' => ['Parcel', 'Sub', 'Title no', 'Title type', 'Book', 'Volume', 'Page', 'Land use',
                      'Issued', 'Area (m²)', 'Road', 'District', 'Village', 'Status', 'No. of owners', 'Owners'],
        'rows'    => $rows,
        'summary' => ['parcels' => count($rows)],
    ];
    break;
}

// ---- Bank reconciliation ----
case 'bank': {
    $rows = $pdo->query("SELECT b.id AS import_id, b.filename, b.imported_at, t.match_status,
            COUNT(*) AS rows_count, COALESCE(SUM(t.amount),0) AS total_kip
        FROM bank_transactions t JOIN bank_imports b ON b.id = t.import_id
        GROUP BY b.id, b.filename, b.imported_at, t.match_status
        ORDER BY b.imported_at DESC, t.match_status")->fetchAll();

    $stmtRows = $pdo->query("SELECT match_status, COUNT(*) AS n, COALESCE(SUM(amount),0) AS total
        FROM bank_transactions GROUP BY match_status")->fetchAll();
    $byStatus = [];
    $matchedTotal = 0.0;
    foreach ($stmtRows as $s) {
        $byStatus[$s['match_status']] = ['rows' => (int)$s['n'], 'total_kip' => (float)$s['total']];
        if ($s['match_status'] === 'matched') { $matchedTotal = (float)$s['total']; }
    }
    $collected = (float)($pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='PAID'")->fetchColumn());

    $result = [
        'columns' => ['Import', 'File', 'Imported', 'Match status', 'Rows', 'Total (kip)'],
        'rows'    => $rows,
        'summary' => [
            'by_status'        => $byStatus,
            'statement_matched_kip' => $matchedTotal,
            'payments_paid_kip'      => $collected,
            'difference_kip'         => $collected - $matchedTotal,
        ],
    ];
    break;
}

// ---- Rate schedule (current zones) ----
case 'rates': {
    $rows = $pdo->query("SELECT code AS zone_code, name_lo, name_en, rate_per_sqm, valid_from, note, updated_at,
            CASE WHEN is_active THEN 'current' ELSE 'inactive' END AS currency
        FROM zones
        ORDER BY is_active DESC, code")->fetchAll();
    $result = [
        'columns' => ['Zone', 'Name (Lao)', 'Name (EN)', 'Rate (kip/m²)', 'Valid from', 'Status', 'Note', 'Updated'],
        'rows'    => $rows,
        'summary' => ['zones' => count($rows)],
    ];
    break;
}

// ---- Audit trail ----
case 'audit': {
    $where = [];
    $params = [];
    if ($actor !== '')  { $where[] = '(u.username ILIKE :ac OR a.actor_id = :acid)'; $params[':ac'] = '%'.$actor.'%'; $params[':acid'] = (int)$actor; }
    if ($action !== '') { $where[] = 'a.action ILIKE :act'; $params[':act'] = '%'.$action.'%'; }
    if ($from) { $where[] = 'a.at >= :from'; $params[':from'] = $from . ' 00:00:00'; }
    if ($to)   { $where[] = 'a.at <= :to';   $params[':to']   = $to . ' 23:59:59'; }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = $pdo->prepare("SELECT a.id, a.at, u.username, a.action, a.entity, a.entity_id, a.detail
        FROM audit_log a LEFT JOIN users u ON u.id = a.actor_id $w
        ORDER BY a.at DESC, a.id DESC LIMIT 1000");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $result = [
        'columns' => ['ID', 'When', 'Actor', 'Action', 'Entity', 'Entity ID', 'Detail'],
        'rows'    => $rows,
        'summary' => ['entries' => count($rows)],
    ];
    break;
}

// ---- MOF export, re-based on payments + fee_obligations ----
case 'mof': {
    $where = [];
    $params = [];
    rep_parcel_filters($where, $params, $district, $road, $scope['district_id']);
    if ($year) { $where[] = 'o.fee_year = :fy'; $params[':fy'] = $year; }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare("SELECT o.fee_year, d.name_lo AS district, v.name_lo AS village,
            o.road_category,
            COUNT(*) AS parcels,
            COALESCE(SUM(o.amount_due),0)          AS assessed_kip,
            COALESCE(SUM(o.amount_paid),0)         AS collected_kip,
            COALESCE(SUM(o.amount_due + o.penalty - o.amount_paid),0) AS outstanding_kip
        FROM fee_obligations o
        JOIN parcels par ON par.id = o.parcel_id
        JOIN districts d ON d.id = par.district_id
        JOIN villages  v ON v.id = par.village_id
        $w
        GROUP BY o.fee_year, d.name_lo, v.name_lo, o.road_category
        ORDER BY o.fee_year DESC, district, village, o.road_category");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $result = [
        'columns' => ['Fee year', 'District', 'Village', 'Road', 'Parcels',
                      'Assessed (kip)', 'Collected (kip)', 'Outstanding (kip)'],
        'rows'    => $rows,
        'summary' => ['rows' => count($rows)],
    ];
    break;
}

default:
    json_error('Unknown report: ' . $report, 400, 'unknown_report');
}

// ---- Output ----
if ($format === 'csv') {
    $safe = preg_replace('/[^a-z0-9_-]/i', '', $report);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="landfee-' . $safe . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $result['columns']);
    foreach ($result['rows'] as $r) {
        fputcsv($out, array_map(fn($v) => is_array($v) ? json_encode($v) : (string)$v, array_values($r)));
    }
    fclose($out);
    exit;
}

json_ok($result);