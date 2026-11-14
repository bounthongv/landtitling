<?php
// API v1: officer/agent-handover.php — district officer reviews agent handovers.
//
// No URL router exists, so the batch and action travel in the query string:
//   GET  officer/agent-handover.php?status=pending       -> list (staff + manager)
//   POST officer/agent-handover.php?id=42&action=verify  -> verify (staff only)
//   POST officer/agent-handover.php?id=42&action=reject  -> reject all (staff only)
require_once __DIR__ . '/../../../lib/db.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/fee.php';

$pdo    = db_connect();
$method = $_SERVER['REQUEST_METHOD'];
$me     = require_auth(['officer', 'admin', 'manager']);
$meId   = (int)$me['sub'];
$scope  = staff_scope($me);

// ---- GET: list handovers awaiting (or already given) review -----------------
if ($method === 'GET') {
    $status = (string)($_GET['status'] ?? 'pending');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $where  = ['1=1'];
    $params = [];

    if ($scope['district_id'] !== null) {
        $where[] = 'u.agent_district_id = :scope_did';
        $params[':scope_did'] = $scope['district_id'];
    }
    $scopeProvince = staff_province_id($me, $pdo);
    if ($scopeProvince) {
        $where[] = 'u.agent_district_id IN (SELECT id FROM districts WHERE province_id = :scope_prov)';
        $params[':scope_prov'] = $scopeProvince;
    }
    if (in_array($status, ['pending', 'verified', 'rejected'], true)) {
        $where[] = 'ah.status = :st';
        $params[':st'] = $status;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*)
        FROM agent_handovers ah
        JOIN users u ON u.id = ah.agent_id
        $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT ah.*, u.username AS agent_username, u.agent_role, u.agent_license_no,
            v.name_lo AS village_name, d.name_lo AS district_name,
            vo.username AS verified_by_username
        FROM agent_handovers ah
        JOIN users u ON u.id = ah.agent_id
        LEFT JOIN villages v ON v.id = ah.agent_village_id
        LEFT JOIN districts d ON d.id = u.agent_district_id
        LEFT JOIN users vo ON vo.id = ah.verified_by
        $whereSql
        ORDER BY ah.created_at DESC, ah.id DESC
        LIMIT :lim OFFSET :off");
    $params[':lim'] = $limit;
    $params[':off'] = $offset;
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $payStmt = $pdo->prepare("SELECT p.id, p.reference_no, p.amount, p.parcel_id,
                p.status AS payment_status, p.payer_name, p.payer_phone, p.payer_id_card,
                par.parcel_no
            FROM payments p
            JOIN parcels par ON par.id = p.parcel_id
            WHERE p.handover_id = :hid
            ORDER BY p.id");
        $payStmt->execute([':hid' => (int)$row['id']]);
        $row['payments'] = $payStmt->fetchAll();
    }
    unset($row);

    json_ok([
        'handovers' => $rows,
        'count' => count($rows),
        'total' => $total,
        'page' => $page,
        'limit' => $limit,
    ]);
}

// ---- POST: verify / reject --------------------------------------------------
require_staff_write($me);   // managers are read-only

if ($method !== 'POST') {
    json_error('Method not allowed', 405, 'method_not_allowed');
}

$handoverId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$action     = (string)($_GET['action'] ?? $_POST['action'] ?? '');
if ($handoverId <= 0 || !in_array($action, ['verify', 'reject'], true)) {
    json_error('id and action=verify|reject are required', 400, 'bad_request');
}

$st = $pdo->prepare("SELECT ah.*, u.agent_district_id, u.agent_village_id
    FROM agent_handovers ah
    JOIN users u ON u.id = ah.agent_id
    WHERE ah.id = :hid");
$st->execute([':hid' => $handoverId]);
$handover = $st->fetch();

if (!$handover) {
    json_error('Handover batch not found', 404, 'not_found');
}
if ($scope['district_id'] !== null && (int)$handover['agent_district_id'] !== $scope['district_id']) {
    json_error('Handover from outside your district', 403, 'out_of_scope');
}
if ($handover['status'] !== 'pending') {
    json_error('Handover already processed (status: ' . $handover['status'] . ')', 409, 'bad_state');
}

$body = read_json_body();

if ($action === 'reject') {
    $reason = trim((string)($body['reason'] ?? ''));
    if ($reason === '') {
        json_error('A reason is required to reject', 400, 'missing_reason');
    }
    if (mb_strlen($reason) > 500) {
        $reason = mb_substr($reason, 0, 500);
    }

    $pdo->beginTransaction();
    try {
        // Return every payment to the agent's pending pile.
        $st = $pdo->prepare("UPDATE payments
            SET handover_id = NULL, handed_over_at = NULL, status = 'ISSUED'
            WHERE handover_id = :hid AND status IN ('ISSUED','SUBMITTED')");
        $st->execute([':hid' => $handoverId]);
        $rejectedCount = $st->rowCount();

        $pdo->prepare("UPDATE agent_handovers
            SET status = 'rejected', district_officer_id = :by, notes = :notes, updated_at = now()
            WHERE id = :hid")->execute([':by' => $meId, ':notes' => $reason, ':hid' => $handoverId]);

        fee_audit($pdo, $meId, 'agent_handover_reject', 'agent_handover', $handoverId, [
            'batch_reference' => $handover['batch_reference'],
            'rejected_count'  => $rejectedCount,
            'reason'          => $reason,
        ]);

        $pdo->commit();
        json_ok([
            'handover_id' => $handoverId,
            'status' => 'rejected',
            'rejected_count' => $rejectedCount,
        ]);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('Agent handover reject error: ' . $e->getMessage());
        json_error('Failed to reject handover', 500, 'reject_failed');
    }
}

// ---- verify ----------------------------------------------------------------
$rejectIds = array_map('intval', (array)($body['reject_payment_ids'] ?? []));
$notes     = trim((string)($body['notes'] ?? ''));

$pdo->beginTransaction();
try {
    $st = $pdo->prepare("SELECT id, reference_no, amount, obligation_id, status
        FROM payments
        WHERE handover_id = :hid
        ORDER BY id
        FOR UPDATE");
    $st->execute([':hid' => $handoverId]);
    $payments = $st->fetchAll();
    if (empty($payments)) {
        $pdo->rollBack();
        json_error('No payments in this handover batch', 400, 'empty_batch');
    }

    $verifiedCount    = 0;
    $rejectedCount    = 0;
    $rejectedDetails  = [];
    $obligationsToSettle = [];

    foreach ($payments as $p) {
        $pid = (int)$p['id'];
        if (in_array($pid, $rejectIds, true) && $p['status'] !== 'PAID') {
            $pdo->prepare("UPDATE payments
                SET handover_id = NULL, handed_over_at = NULL, status = 'ISSUED'
                WHERE id = :pid")->execute([':pid' => $pid]);
            $rejectedCount++;
            $rejectedDetails[] = ['payment_id' => $pid, 'reference_no' => $p['reference_no']];
            continue;
        }

        if ($p['status'] === 'PAID') {
            $verifiedCount++;
            continue;   // already settled (defensive; status pending implies not)
        }

        // The officer has the cash: the payment is PAID and the obligation is
        // settled through the canonical helper (stamps the surcharge correctly).
        $pdo->prepare("UPDATE payments
            SET status = 'PAID', paid_at = now(), verified_by = :by,
                verify_method = 'manual', verify_note = :note,
                handover_officer_id = :by
            WHERE id = :pid")
            ->execute([':by' => $meId, ':note' => 'Agent cash handover verified', ':pid' => $pid]);
        $verifiedCount++;
        if (!empty($p['obligation_id'])) {
            $obligationsToSettle[(int)$p['obligation_id']] = true;
        }
    }

    foreach (array_keys($obligationsToSettle) as $obId) {
        fee_settle_obligation($pdo, $obId);
    }

    // All rejected => rejected; otherwise the batch is verified (any rejected
    // rows went back to the agent's pending list above).
    //
    // Native pgsql prepares (ATTR_EMULATE_PREPARES = false) cannot reuse one
    // named placeholder in several places — Postgres then deduces conflicting
    // types. Two statements avoid repeating :st/:by.
    $newStatus = $verifiedCount === 0 ? 'rejected' : 'verified';
    if ($newStatus === 'verified') {
        $pdo->prepare("UPDATE agent_handovers
            SET status = 'verified', district_officer_id = :by,
                verified_at = now(), verified_by = :by2,
                notes = :notes, updated_at = now()
            WHERE id = :hid")
            ->execute([':by' => $meId, ':by2' => $meId, ':notes' => $notes ?: null, ':hid' => $handoverId]);
    } else {
        $pdo->prepare("UPDATE agent_handovers
            SET status = 'rejected', district_officer_id = :by,
                notes = :notes, updated_at = now()
            WHERE id = :hid")
            ->execute([':by' => $meId, ':notes' => $notes ?: null, ':hid' => $handoverId]);
    }

    fee_audit($pdo, $meId, 'agent_handover_verify', 'agent_handover', $handoverId, [
        'batch_reference'  => $handover['batch_reference'],
        'verified_count'   => $verifiedCount,
        'rejected_count'   => $rejectedCount,
        'rejected_payments'=> $rejectedDetails,
        'notes'            => $notes,
    ]);

    $pdo->commit();
    json_ok([
        'handover_id' => $handoverId,
        'status' => $newStatus,
        'verified_count' => $verifiedCount,
        'rejected_count' => $rejectedCount,
        'rejected_payments' => $rejectedDetails,
    ]);
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('Agent handover verify error: ' . $e->getMessage());
    json_error('Failed to verify handover', 500, 'verify_failed');
}
