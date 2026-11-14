<?php
// API v1: agent/handovers.php — agent handover batch management
require_once __DIR__ . '/../../../lib/db.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/fee.php';

$pdo    = db_connect();
$method = $_SERVER['REQUEST_METHOD'];
$me     = require_auth(['officer']); // agents are officer role + is_agent flag
$meId   = (int)$me['sub'];

// Verify caller is an agent
$st = $pdo->prepare("SELECT is_agent, agent_village_id, agent_district_id, agent_role FROM users WHERE id = :id");
$st->execute([':id' => $meId]);
$agent = $st->fetch();
if (!$agent || !$agent['is_agent']) {
    json_error('Not authorized as agent', 403, 'not_agent');
}
$agentVillageId  = (int)$agent['agent_village_id'];
$agentDistrictId = (int)$agent['agent_district_id'];
$agentRole       = $agent['agent_role'] ?? 'agent';

// The village is the agent's home label (kept on the batch for reporting);
// the working scope is the district. Older accounts derive it from the village.
if ($agentDistrictId <= 0 && $agentVillageId > 0) {
    $st2 = $pdo->prepare("SELECT district_id FROM villages WHERE id = :vid");
    $st2->execute([':vid' => $agentVillageId]);
    $agentDistrictId = (int)$st2->fetchColumn();
}
if ($agentVillageId <= 0 && $agentDistrictId <= 0) {
    json_error('Agent account has no village or district assigned', 409, 'agent_unscoped');
}

/**
 * Collision-free batch reference: AH-{year}-{seq6}.
 * Same scheme as slip_generate_no(): a dedicated sequence, never MAX()+1,
 * which races between two agents handing over at the same time.
 */
function agent_handover_no(PDO $pdo): string {
    static $ensured = false;
    if (!$ensured) {
        $ensured = true;
        try {
            $pdo->exec("CREATE SEQUENCE IF NOT EXISTS agent_handover_ref_seq");
            $row = $pdo->query("SELECT COALESCE(MAX((regexp_split_to_array(batch_reference, '-'))[3]::bigint), 0) AS m
                                FROM agent_handovers
                                WHERE batch_reference ~ '^AH-[0-9]{4}-[0-9]{6}$'")->fetch();
            $max = (int)($row['m'] ?? 0);
            if ($max > 0) {
                $pdo->exec("SELECT setval('agent_handover_ref_seq', $max, true)");
            }
        } catch (\Throwable $e) {
            // fall through — uq_ah_batch_ref still protects us
        }
    }
    $seq = (int)$pdo->query("SELECT nextval('agent_handover_ref_seq') AS s")->fetch()['s'];
    return 'AH-' . date('Y') . '-' . str_pad((string)$seq, 6, '0', STR_PAD_LEFT);
}

if ($method === 'GET') {
    $status = (string)($_GET['status'] ?? '');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $where  = ['ah.agent_id = :aid'];
    $params = [':aid' => $meId];

    if (in_array($status, ['pending', 'verified', 'rejected'], true)) {
        $where[] = 'ah.status = :st';
        $params[':st'] = $status;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM agent_handovers ah $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT ah.*, v.name_lo AS village_name
        FROM agent_handovers ah
        LEFT JOIN villages v ON v.id = ah.agent_village_id
        $whereSql
        ORDER BY ah.created_at DESC, ah.id DESC
        LIMIT :lim OFFSET :off");
    $params[':lim'] = $limit;
    $params[':off'] = $offset;
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $payStmt = $pdo->prepare("SELECT p.id, p.reference_no, p.amount, p.parcel_id,
                p.status AS payment_status, par.parcel_no
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
        'agent' => ['village_id' => $agentVillageId, 'district_id' => $agentDistrictId, 'role' => $agentRole],
    ]);
}

if ($method === 'POST') {
    $body       = read_json_body();
    $paymentIds = $body['payment_ids'] ?? [];

    if (!is_array($paymentIds) || empty($paymentIds)) {
        json_error('payment_ids array is required', 400, 'missing_payment_ids');
    }
    $paymentIds = array_values(array_unique(array_map('intval', $paymentIds)));
    if (count($paymentIds) > 500) {
        json_error('Too many payments in one handover (max 500)', 400, 'too_many_payments');
    }

    // Named parameters only: PDO cannot mix positional and named placeholders.
    $in     = [];
    $params = [':aid' => $meId];
    foreach ($paymentIds as $i => $pid) {
        $key = ':p' . $i;
        $in[] = $key;
        $params[$key] = $pid;
    }
    $inSql = implode(',', $in);

    $st = $pdo->prepare("SELECT id, reference_no, amount, parcel_id
        FROM payments
        WHERE id IN ($inSql)
          AND payment_method = 'cash_agent'
          AND agent_id = :aid
          AND handover_id IS NULL
          AND status IN ('ISSUED','SUBMITTED')
        FOR UPDATE");
    $pdo->beginTransaction();
    try {
        $st->execute($params);
        $payments = $st->fetchAll();

        if (count($payments) !== count($paymentIds)) {
            $pdo->rollBack();
            json_error('Some payments are not yours, not cash collections, already handed over, or not open',
                400, 'invalid_payment_selection');
        }

        $amountTotal  = 0.0;
        foreach ($payments as $p) {
            $amountTotal += (float)$p['amount'];
        }
        $amountTotal  = round($amountTotal, 2);
        $paymentCount = count($payments);
        $batchRef     = agent_handover_no($pdo);

        $st = $pdo->prepare("INSERT INTO agent_handovers
            (agent_id, agent_village_id, district_officer_id, amount_total, payment_count,
             status, batch_reference, handed_over_at)
            VALUES (:aid, :vid, NULL, :amt, :cnt, 'pending', :bref, now())
            RETURNING id");
        $st->execute([
            ':aid'  => $meId,
            ':vid'  => $agentVillageId > 0 ? $agentVillageId : null,
            ':amt'  => $amountTotal,
            ':cnt'  => $paymentCount,
            ':bref' => $batchRef,
        ]);
        $handoverId = (int)$st->fetch()['id'];

        // The cash changes hands now; the officer's receipt of it is verified_at.
        // Build a fresh parameter set: the SELECT's :aid must not be bound to
        // this statement (PDO rejects unused placeholders on a native prepare).
        $upParams = [':hid' => $handoverId];
        foreach ($paymentIds as $i => $pid) {
            $upParams[':p' . $i] = $pid;
        }
        $pdo->prepare("UPDATE payments SET handover_id = :hid, handed_over_at = now()
            WHERE id IN ($inSql)")->execute($upParams);

        fee_audit($pdo, $meId, 'agent_handover_create', 'agent_handover', $handoverId, [
            'batch_reference' => $batchRef,
            'amount_total' => $amountTotal,
            'payment_count' => $paymentCount,
            'payment_ids' => $paymentIds,
        ]);

        $pdo->commit();

        json_ok([
            'id' => $handoverId,
            'handover_id' => $handoverId,
            'batch_reference' => $batchRef,
            'amount_total' => $amountTotal,
            'payment_count' => $paymentCount,
            'status' => 'pending',
            'created_at' => date('c'),
            'payments' => $payments,
        ], 201);

    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Agent handover create error: ' . $e->getMessage());
        json_error('Failed to create handover batch', 500, 'handover_failed');
    }
}

json_error('Method not allowed', 405, 'method_not_allowed');
