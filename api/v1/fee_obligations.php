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

    $pen = fee_penalty_sql('o');
    $stmt = $pdo->prepare("SELECT o.*, p.parcel_no, p.sub_parcel_no,
            d.name_lo AS district_name, v.name_lo AS village_name,
            (CURRENT_DATE - o.due_date) AS days_overdue,
            $pen AS penalty_live,
            (o.amount_due + $pen - o.amount_paid) AS outstanding
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
    // The stored penalty is only authoritative once the obligation is settled;
    // while it is still owed the surcharge is derived (see fee_penalty_sql).
    foreach ($rows as &$row) {
        $row['penalty'] = (float)$row['penalty_live'];
        unset($row['penalty_live']);
    }
    unset($row);
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

    // ---- POST: officer waive/reduce of the late-payment surcharge (audited) ----
    if ($action === 'penalty') {
        require_staff_write($me);
        $obligationId = (int)($body['obligation_id'] ?? 0);
        if ($obligationId <= 0) {
            json_error('obligation_id is required', 400, 'missing_obligation_id');
        }
        $mode = (string)($body['mode'] ?? '');
        if (!in_array($mode, ['auto', 'set'], true)) {
            json_error('mode must be auto or set', 400, 'invalid_mode');
        }

        $pen  = fee_penalty_sql('o');
        $stmt = $pdo->prepare("SELECT o.*, CURRENT_DATE::text AS today,
                p.district_id AS parcel_district, $pen AS penalty_live
            FROM fee_obligations o
            JOIN parcels p ON p.id = o.parcel_id
            WHERE o.id = :id");
        $stmt->execute([':id' => $obligationId]);
        $ob = $stmt->fetch();
        if (!$ob) { json_error('Obligation not found', 404, 'not_found'); }
        if ($scope['district_id'] !== null && (int)$ob['parcel_district'] !== $scope['district_id']) {
            json_error('Obligation outside your district', 403, 'out_of_scope');
        }
        if (!in_array($ob['status'], ['UNPAID', 'PARTIAL'], true)) {
            json_error('Penalties can only be adjusted while the obligation is unpaid', 409, 'bad_state');
        }

        $before = [
            'penalty'          => (float)$ob['penalty_live'],
            'penalty_override' => $ob['penalty_override'] !== null ? (float)$ob['penalty_override'] : null,
        ];
        $reason = null;
        if ($mode === 'auto') {
            $pdo->prepare("UPDATE fee_obligations
                SET penalty_override = NULL, penalty_note = NULL,
                    penalty_set_by = NULL, penalty_set_at = NULL, updated_at = now()
                WHERE id = :id")->execute([':id' => $obligationId]);
        } else {
            $reason = trim((string)($body['reason'] ?? ''));
            if ($reason === '') { json_error('A reason is required', 400, 'missing_reason'); }
            if (mb_strlen($reason) > 500) { $reason = mb_substr($reason, 0, 500); }

            // Waive or reduce only: the override can never exceed the automatic
            // 10%, and there is nothing to override before the due date.
            $auto = fee_penalty_auto((float)$ob['amount_due'], $ob['due_date'] ?? null, (string)$ob['today']);
            if ($auto <= 0) {
                json_error('This obligation is not past its due date', 409, 'not_late');
            }
            if (!isset($body['amount']) || !is_numeric($body['amount'])) {
                json_error('amount is required', 400, 'invalid_amount');
            }
            $amount = round((float)$body['amount'], 2);
            if ($amount < 0 || $amount > $auto + 0.005) {
                json_error('The reduction cannot exceed the automatic surcharge', 400, 'invalid_amount');
            }
            $pdo->prepare("UPDATE fee_obligations
                SET penalty_override = :amt, penalty_note = :n,
                    penalty_set_by = :by, penalty_set_at = now(), updated_at = now()
                WHERE id = :id")
                ->execute([':amt' => $amount, ':n' => $reason, ':by' => $me['sub'], ':id' => $obligationId]);
        }

        $stmt = $pdo->prepare("SELECT o.*, $pen AS penalty_live FROM fee_obligations o WHERE o.id = :id");
        $stmt->execute([':id' => $obligationId]);
        $after = $stmt->fetch();
        fee_audit($pdo, $me['sub'] ?? null, 'obligation_penalty', 'obligation', $obligationId, [
            'mode'   => $mode,
            'before' => $before,
            'after'  => [
                'penalty'          => (float)$after['penalty_live'],
                'penalty_override' => $after['penalty_override'] !== null ? (float)$after['penalty_override'] : null,
            ],
            'reason' => $reason,
        ]);
        $after['penalty'] = (float)$after['penalty_live'];
        unset($after['penalty_live']);
        json_ok(['obligation' => $after]);
    }

    json_error('Unknown action', 400, 'bad_action');
}

json_error('Method not allowed', 405, 'method_not_allowed');