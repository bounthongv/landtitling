<?php
// API v1: annual fee obligations — GET list/arrears, POST generate/recompute
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';
require_once __DIR__ . '/../../lib/fee.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];
$me = require_auth(['officer', 'admin', 'manager']);
$scope = staff_scope($me);

// ---- GET: list obligations (filters: fee_year, district_id, parcel_id, status, overdue) ----
if ($method === 'GET') {
    $fee_year    = isset($_GET['fee_year'])    ? (int)$_GET['fee_year']    : 0;
    $province_id = isset($_GET['province_id']) ? (int)$_GET['province_id'] : 0;
    $district_id = isset($_GET['district_id']) ? (int)$_GET['district_id'] : 0;
    $parcel_id   = isset($_GET['parcel_id'])   ? (int)$_GET['parcel_id']   : 0;
    $status      = $_GET['status'] ?? '';
    $overdue     = !empty($_GET['overdue']);

    $where = [];
    $params = [];
    if ($fee_year)    { $where[] = 'o.fee_year = :fy';       $params[':fy'] = $fee_year; }
    if ($province_id) {
        $where[] = 'o.district_id IN (SELECT id FROM districts WHERE province_id = :pid)';
        $params[':pid'] = $province_id;
    }
    if ($district_id) { $where[] = 'o.district_id = :did';   $params[':did'] = $district_id; }
    if ($parcel_id)   { $where[] = 'o.parcel_id = :pid';     $params[':pid'] = $parcel_id; }
    if ($status !== '') {
        $where[] = 'o.status = :st';
        $params[':st'] = $status;
    } elseif ($overdue) {
        $where[] = "o.status IN ('UNPAID','PARTIAL')";
    }
    if ($overdue) {
        $where[] = 'o.due_date < CURRENT_DATE';
    }
    // District staff see only their own district's obligations.
    if ($scope['district_id'] !== null) {
        $where[] = 'o.district_id = :scope_did';
        $params[':scope_did'] = $scope['district_id'];
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare("SELECT o.*, p.parcel_no, p.sub_parcel_no,
            d.name_lo AS district_name, v.name_lo AS village_name,
            (CURRENT_DATE - o.due_date) AS days_overdue
        FROM fee_obligations o
        JOIN parcels  p ON p.id = o.parcel_id
        JOIN districts d ON d.id = o.district_id
        JOIN villages  v ON v.id = p.village_id
        $whereSql
        ORDER BY o.fee_year DESC,
                 CASE WHEN o.status IN ('UNPAID','PARTIAL') THEN 0 ELSE 1 END,
                 o.due_date ASC, p.parcel_no ASC
        LIMIT 500");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    json_ok(['obligations' => $rows, 'count' => count($rows)]);
}

// ---- POST: generate obligations (all / by district / single parcel) ----
if ($method === 'POST') {
    $body   = read_json_body();
    $action = $body['action'] ?? 'generate';

    if ($action === 'generate') {
        require_staff_write($me);   // only district admin/officer assess fees
        $year       = (int)($body['fee_year'] ?? date('Y'));
        $districtId = (int)($body['district_id'] ?? 0);
        $parcelId   = (int)($body['parcel_id'] ?? 0);

        // District staff may only assess their own district's parcels.
        if ($scope['district_id'] !== null) {
            if ($districtId && $districtId !== $scope['district_id']) {
                json_error('You can only assess fees for your own district', 403, 'out_of_scope');
            }
            $districtId = $scope['district_id'];
        }

        [$billableWhere, $params] = fee_billable_sql('parcels');
        $sql    = 'SELECT id FROM parcels WHERE ' . $billableWhere;
        if ($districtId) { $sql .= ' AND district_id = :did'; $params[':did'] = $districtId; }
        if ($parcelId)   { $sql .= ' AND id = :pid';         $params[':pid'] = $parcelId; }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $assessed = 0;
        $skipped  = 0;
        $total    = 0.0;
        foreach ($stmt->fetchAll() as $row) {
            $ob = fee_upsert_obligation($pdo, $row['id'], $year);
            if (!$ob) { $skipped++; continue; }
            $assessed++;
            $total += (float)$ob['amount_due'];
        }
        fee_audit($pdo, $me['sub'] ?? null, 'generate', 'fee_year', $year, [
            'district_id' => $districtId ?: null,
            'parcel_id'   => $parcelId ?: null,
            'assessed'    => $assessed,
            'total_due'   => $total,
        ]);
        json_ok([
            'fee_year'    => $year,
            'assessed'    => $assessed,
            'skipped'     => $skipped,
            'total_due'   => $total,
        ]);
    }

    // ---- POST: recompute one parcel's obligation (audited) ----
    if ($action === 'recompute') {
        require_staff_write($me);   // only district admin/officer recompute fees
        $parcelId = (int)($body['parcel_id'] ?? 0);
        $year     = (int)($body['fee_year'] ?? date('Y'));
        if ($parcelId <= 0) {
            json_error('parcel_id is required', 400, 'missing_parcel_id');
        }
        if ($scope['district_id'] !== null) {
            $dst = $pdo->prepare("SELECT district_id FROM parcels WHERE id = :id");
            $dst->execute([':id' => $parcelId]);
            $pd = $dst->fetchColumn();
            if ($pd === false || (int)$pd !== $scope['district_id']) {
                json_error('Parcel outside your district', 403, 'out_of_scope');
            }
        }

        $stmt = $pdo->prepare("SELECT amount_due, amount_paid, status
            FROM fee_obligations WHERE parcel_id = :pid AND fee_year = :fy");
        $stmt->execute([':pid' => $parcelId, ':fy' => $year]);
        $before = $stmt->fetch() ?: null;

        $ob = fee_upsert_obligation($pdo, $parcelId, $year);
        if (!$ob) {
            json_error('Parcel not found', 404, 'parcel_not_found');
        }

        fee_audit($pdo, $me['sub'] ?? null, 'recompute', 'parcel', $parcelId, [
            'fee_year' => $year,
            'before'   => $before,
            'after'    => $ob,
        ]);
        json_ok(['obligation' => $ob, 'before' => $before]);
    }

    json_error('Unknown action', 400, 'bad_action');
}

json_error('Method not allowed', 405, 'method_not_allowed');