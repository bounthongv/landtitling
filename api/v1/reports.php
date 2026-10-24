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
$province = isset($_GET['province_id']) ? (int)$_GET['province_id'] : 0;
$district = isset($_GET['district_id']) ? (int)$_GET['district_id'] : 0;
$year     = isset($_GET['fee_year'])    ? (int)$_GET['fee_year']    : 0;
$road     = trim((string)($_GET['road_category'] ?? ''));
$from     = trim((string)($_GET['from'] ?? ''));
$to       = trim((string)($_GET['to'] ?? ''));
$actor    = trim((string)($_GET['actor'] ?? ''));
$action   = trim((string)($_GET['action'] ?? ''));

// Province-level users are scoped to Savannakhet (id=1) until multi-province rollout.
// Super admin sees all; district users are already district-scoped.
$level = $me['level'] ?? 'district';
$is_super = !empty($me['is_super']);
if (!$is_super && $level === 'province') {
    $province = 1; // Savannakhet
} elseif (!$is_super) {
    $province = 0; // district staff: the district scope filter already restricts them
}

/** Shared parcel-side filters (parcel join alias `par`). */
function rep_parcel_filters(&$where, &$params, $district, $road, $scopeDistrict = null, $province = 0) {
    // Province filter via subquery: the summary queries join `par` but not `districts`,
    // so an alias-based filter would break them.
    if ($province) {
        $where[] = 'par.district_id IN (SELECT id FROM districts WHERE province_id = :prov)';
        $params[':prov'] = $province;
    }
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
    rep_parcel_filters($where, $params, $district, $road, $scope['district_id'], $province);
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
    rep_parcel_filters($where, $params, $district, $road, $scope['district_id'], $province);
    if ($year) { $where[] = 'o.fee_year = :fy'; $params[':fy'] = $year; }
    // No filter at all (e.g. super admin, nothing selected) must not emit a bare WHERE.
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

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
    rep_parcel_filters($where, $params, $district, $road, $scope['district_id'], $province);
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // NOTE: parcels are aliased `par` here — rep_parcel_filters() emits `par.*` clauses.
    $stmt = $pdo->prepare("SELECT d.name_lo AS district,
            COUNT(*) AS parcels,
            COALESCE(SUM(par.area_sqm),0) AS total_area_sqm,
            COUNT(*) FILTER (WHERE par.status = 'confirmed') AS confirmed,
            COUNT(*) FILTER (WHERE par.status = 'draft')     AS draft,
            COUNT(DISTINCT par.road_category) AS road_categories
        FROM parcels par JOIN districts d ON d.id = par.district_id
        $w GROUP BY d.name_lo ORDER BY d.name_lo");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Summary counters must respect the same scope as the rows, otherwise a
    // district officer sees province-wide totals next to their own rows.
    $parcelWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $sParcelStmt = $pdo->prepare("SELECT COUNT(*) FROM parcels par $parcelWhere");
    $sParcelStmt->execute($params);
    $sParcels = (int)$sParcelStmt->fetchColumn();

    $scanWhere = '';
    $scanParams = [];
    if ($scope['district_id'] !== null) {
        $scanWhere = 'WHERE district_id = :scope_did';
        $scanParams[':scope_did'] = $scope['district_id'];
    } elseif ($province) {
        $scanWhere = 'WHERE district_id IN (SELECT id FROM districts WHERE province_id = :prov)';
        $scanParams[':prov'] = $province;
    }
    $sScansStmt = $pdo->prepare("SELECT COUNT(*) FROM title_scans $scanWhere");
    $sScansStmt->execute($scanParams);
    $sScans = (int)$sScansStmt->fetchColumn();

    $sPendingStmt = $pdo->prepare("SELECT COUNT(*) FROM title_scans $scanWhere" . ($scanWhere ? ' AND' : ' WHERE') . ' parcel_id IS NULL');
    $sPendingStmt->execute($scanParams);
    $sPending = (int)$sPendingStmt->fetchColumn();

    $sAvgStmt = $pdo->prepare("SELECT COALESCE(AVG(overall_confidence),0) FROM title_scans "
        . ($scanWhere ? $scanWhere . ' AND' : ' WHERE') . ' overall_confidence > 0');
    $sAvgStmt->execute($scanParams);
    $sAvgConf = $sAvgStmt->fetchColumn();

    $confStmt = $pdo->prepare("SELECT CASE
                WHEN overall_confidence >= 80 THEN '80-100 (high)'
                WHEN overall_confidence >= 50 THEN '50-79 (medium)'
                WHEN overall_confidence >  0 THEN '1-49 (low)'
                ELSE '0 (unparsed)' END AS bucket,
            COUNT(*) AS scans
        FROM title_scans $scanWhere GROUP BY 1 ORDER BY 1");
    $confStmt->execute($scanParams);
    $confRows = $confStmt->fetchAll();

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
    // Province filter
    if ($province) {
        $where[] = 'p.district_id IN (SELECT id FROM districts WHERE province_id = :prov)';
        $params[':prov'] = $province;
    }
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
    // Bank rows are only attributable to a district through the payment they
    // matched, so district staff see their own district's matched transactions
    // (unmatched rows belong to no district and stay hidden from them).
    $bankWhere = '';
    $bankParams = [];
    if ($scope['district_id'] !== null) {
        $bankWhere = 'WHERE t.matched_payment_id IN (
            SELECT pay.id FROM payments pay JOIN parcels par ON par.id = pay.parcel_id
            WHERE par.district_id = :scope_did)';
        $bankParams[':scope_did'] = $scope['district_id'];
    } elseif ($province) {
        $bankWhere = 'WHERE t.matched_payment_id IN (
            SELECT pay.id FROM payments pay JOIN parcels par ON par.id = pay.parcel_id
            WHERE par.district_id IN (SELECT id FROM districts WHERE province_id = :prov))';
        $bankParams[':prov'] = $province;
    }
    $w = $bankWhere;

    // Payments-side clause for the collected total (same scope, different table).
    $payWhere = '';
    if ($scope['district_id'] !== null) {
        $payWhere = 'AND par.district_id = :scope_did';
    } elseif ($province) {
        $payWhere = 'AND par.district_id IN (SELECT id FROM districts WHERE province_id = :prov)';
    }

    $stmt = $pdo->prepare("SELECT b.id AS import_id, b.filename, b.imported_at, t.match_status,
            COUNT(*) AS rows_count, COALESCE(SUM(t.amount),0) AS total_kip
        FROM bank_transactions t JOIN bank_imports b ON b.id = t.import_id
        $w
        GROUP BY b.id, b.filename, b.imported_at, t.match_status
        ORDER BY b.imported_at DESC, t.match_status");
    $stmt->execute($bankParams);
    $rows = $stmt->fetchAll();

    $stmtRows = $pdo->prepare("SELECT match_status, COUNT(*) AS n, COALESCE(SUM(amount),0) AS total
        FROM bank_transactions t $w GROUP BY match_status");
    $stmtRows->execute($bankParams);
    $stmtRows = $stmtRows->fetchAll();
    $byStatus = [];
    $matchedTotal = 0.0;
    foreach ($stmtRows as $s) {
        $byStatus[$s['match_status']] = ['rows' => (int)$s['n'], 'total_kip' => (float)$s['total']];
        if ($s['match_status'] === 'matched') { $matchedTotal = (float)$s['total']; }
    }
    $collectedStmt = $pdo->prepare("SELECT COALESCE(SUM(pay.amount),0) FROM payments pay
        JOIN parcels par ON par.id = pay.parcel_id
        WHERE pay.status='PAID' $payWhere");
    $collectedStmt->execute($bankParams);
    $collected = (float)$collectedStmt->fetchColumn();

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

// ---- Rate schedule (current zones × road-access tiers) ----
case 'rates': {
    $rows = $pdo->query("SELECT z.code AS zone_code, z.name_lo, z.name_en,
            d.name_lo AS district_name, z.plan_zone,
            COALESCE(MAX(CASE WHEN t.road_category = 'main'   THEN t.rate_per_sqm END), z.rate_per_sqm) AS main,
            COALESCE(MAX(CASE WHEN t.road_category = 'branch' THEN t.rate_per_sqm END), z.rate_per_sqm) AS branch,
            COALESCE(MAX(CASE WHEN t.road_category = 'alley'  THEN t.rate_per_sqm END), z.rate_per_sqm) AS alley,
            COALESCE(MAX(CASE WHEN t.road_category = 'none'   THEN t.rate_per_sqm END), z.rate_per_sqm) AS none,
            MAX(t.valid_from) AS valid_from,
            CASE WHEN z.is_active THEN 'current' ELSE 'inactive' END AS currency
        FROM zones z
        LEFT JOIN districts d ON d.id = z.district_id
        LEFT JOIN (
            SELECT DISTINCT ON (zone_code, road_category)
                   zone_code, road_category, rate_per_sqm, valid_from
            FROM zone_rate_tiers
            WHERE valid_from <= CURRENT_DATE
            ORDER BY zone_code, road_category, valid_from DESC, updated_at DESC
        ) t ON t.zone_code = z.code
        GROUP BY z.code, z.name_lo, z.name_en, d.name_lo, d.code, z.plan_zone,
                 z.rate_per_sqm, z.is_active
        ORDER BY z.is_active DESC, d.code NULLS LAST, z.code")->fetchAll();
    $result = [
        'columns' => ['Zone', 'Name (Lao)', 'Name (EN)', 'District', 'Plan',
                      'Main', 'Branch', 'Alley', 'None', 'Valid from', 'Status'],
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
    // Scope by the actor's district: district staff only see their own district's
    // audit trail, province staff see their province's, super admin sees all.
    if ($scope['district_id'] !== null) {
        $where[] = 'u.district_id = :scope_did';
        $params[':scope_did'] = $scope['district_id'];
    } elseif ($province) {
        $where[] = '(u.district_id IS NULL OR u.district_id IN (SELECT id FROM districts WHERE province_id = :prov))';
        $params[':prov'] = $province;
    }
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
    rep_parcel_filters($where, $params, $district, $road, $scope['district_id'], $province);
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