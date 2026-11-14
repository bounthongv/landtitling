<?php
// API v1: agent/collect.php — record cash collection by agent
require_once __DIR__ . '/../../../lib/db.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/validate.php';
require_once __DIR__ . '/../../../lib/slip.php';
require_once __DIR__ . '/../../../lib/fee.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];
$me = require_auth(['officer']); // agents are officer role + is_agent flag
$meId = (int)$me['sub'];

if ($method !== 'POST') {
    json_error('Method not allowed', 405, 'method_not_allowed');
}

// Verify caller is an agent
$st = $pdo->prepare("SELECT is_agent, agent_village_id, agent_district_id, agent_role, agent_license_no, username FROM users WHERE id = :id");
$st->execute([':id' => $meId]);
$agent = $st->fetch();
if (!$agent || !$agent['is_agent']) {
    json_error('Not authorized as agent', 403, 'not_agent');
}
$agentVillageId = (int)$agent['agent_village_id'];
$agentDistrictId = (int)$agent['agent_district_id'];
$agentRole = $agent['agent_role'] ?? 'agent';
$agentLicenseNo = $agent['agent_license_no'] ?? '';

// District scope: the agent may collect for any parcel in their district.
// The assigned village is the home label only. Older accounts without the
// district field derive it from the village.
if ($agentDistrictId <= 0 && $agentVillageId > 0) {
    $st2 = $pdo->prepare("SELECT district_id FROM villages WHERE id = :vid");
    $st2->execute([':vid' => $agentVillageId]);
    $agentDistrictId = (int)$st2->fetchColumn();
}
if ($agentDistrictId <= 0) {
    json_error('Agent account has no district assigned', 409, 'agent_unscoped');
}

$body = read_json_body();

// Required fields
$obligationId = (int)($body['obligation_id'] ?? 0);
$payerName = trim((string)($body['payer_name'] ?? ''));
$payerPhone = trim((string)($body['payer_phone'] ?? ''));
$payerIdCard = trim((string)($body['payer_id_card'] ?? ''));
$cashPhotoBase64 = $body['cash_photo_base64'] ?? null;
$idPhotoBase64 = $body['id_photo_base64'] ?? null;

if ($obligationId <= 0) {
    json_error('obligation_id is required', 400, 'missing_obligation_id');
}
if ($payerName === '') {
    json_error('payer_name is required', 400, 'missing_payer_name');
}
if ($payerPhone === '') {
    json_error('payer_phone is required', 400, 'missing_payer_phone');
}
$phoneErr = validate_phone($payerPhone, 'payer_phone');
if ($phoneErr !== null) {
    json_error($phoneErr, 400, 'invalid_payer_phone');
}
if ($payerIdCard === '') {
    json_error('payer_id_card is required', 400, 'missing_payer_id_card');
}

// Read-and-lock the obligation so two agents cannot collect the same one at
// once (FOR UPDATE only holds inside a transaction).
// The penalty comes from fee_penalty_sql(), the same expression the
// dashboard uses: an officer's waiver/reduction (penalty_override) must
// change what the agent is asked to collect, which the PHP-only
// fee_penalty_auto() mirror would not know about.
$penSql = fee_penalty_sql('o');
$pdo->beginTransaction();

// Validate obligation exists and is in agent's village
$st = $pdo->prepare("SELECT o.*, p.id AS parcel_id, p.village_id, p.district_id, p.parcel_no,
        p.sub_parcel_no, p.zone_code, p.road_category, p.area_sqm, p.title_no, p.map_ref_no,
        p.province,
        $penSql AS penalty_live,
        d.name_lo AS district_name, v.name_lo AS village_name,
        own.owner_id, own.citizen_user_id,
        own.full_name AS owner_name, own.id_number AS owner_id_number, own.phone AS owner_phone
    FROM fee_obligations o
    JOIN parcels p ON p.id = o.parcel_id
    JOIN districts d ON d.id = p.district_id
    JOIN villages v ON v.id = p.village_id
    LEFT JOIN LATERAL (
        SELECT po.owner_id, ow.full_name, ow.id_number, ow.phone, ow.citizen_user_id
          FROM parcel_owners po
          JOIN owners ow ON ow.id = po.owner_id
         WHERE po.parcel_id = p.id AND po.role = 'owner'
         ORDER BY ow.id
         LIMIT 1
    ) own ON TRUE
    WHERE o.id = :oid
    FOR UPDATE OF o");
$st->execute([':oid' => $obligationId]);
$ob = $st->fetch();

if (!$ob) {
    json_error('Obligation not found', 404, 'obligation_not_found');
}

// Check agent scope — the collection covers the whole district, so only the
// district must match; the agent's home village is not a restriction.
if ((int)$ob['district_id'] !== $agentDistrictId) {
    json_error('Obligation not in your assigned district', 403, 'obligation_not_in_district');
}

// Check obligation is payable
if (!in_array($ob['status'], ['UNPAID', 'PARTIAL'], true)) {
    json_error('Obligation is not payable (status: ' . $ob['status'] . ')', 409, 'obligation_not_payable');
}

// Reject if ANY agent already has an open cash collection for this obligation.
// The unique index uq_cash_agent_obligation_open enforces the same rule, but
// checking first gives a clear message (and covers the same agent retrying).
$st = $pdo->prepare("SELECT id, agent_id FROM payments
    WHERE obligation_id = :oid AND payment_method = 'cash_agent'
      AND status IN ('ISSUED','SUBMITTED')
    LIMIT 1");
$st->execute([':oid' => $obligationId]);
if ($existing = $st->fetch()) {
    $sameAgent = (int)$existing['agent_id'] === $meId;
    json_error(
        $sameAgent ? 'This obligation was already collected by you'
                   : 'This obligation is already being collected by another agent',
        409,
        'already_collected'
    );
}

// Calculate amount due (including live penalty, override-aware)
$pen = (float)$ob['penalty_live'];
$outstanding = round(((float)$ob['amount_due'] + $pen) - (float)$ob['amount_paid'], 2);
if ($outstanding <= 0) {
    json_error('Nothing left to pay on this obligation', 409, 'already_settled');
}

// Amount defaults to full outstanding
$amount = isset($body['amount']) && $body['amount'] !== '' && $body['amount'] !== null
    ? (float)$body['amount']
    : $outstanding;
if ($amount <= 0) {
    json_error('amount must be positive', 400, 'invalid_amount');
}
$amount = min(round($amount, 2), $outstanding);

try {
    // Generate reference
    $reference = slip_generate_no($pdo);
    $qr = slip_qr_payload($reference, $amount, (string)$ob['parcel_no']);

    // Insert payment
    $st = $pdo->prepare("INSERT INTO payments
        (obligation_id, parcel_id, fee_year, payer_owner_id, citizen_user_id,
         method, amount, reference_no, slip_qr, status, bank_code,
         payment_method, agent_id, agent_role, payer_name, payer_phone, payer_id_card,
         collected_at)
        VALUES (:oid, :pid, :fy, :powner, :pcit,
                'cash', :amt, :ref, :qr, 'SUBMITTED', NULL,
                'cash_agent', :aid, :arole, :pname, :pphone, :pidcard,
                now())
        RETURNING id");
    // Link the payment to the parcel owner and, when the owner has a citizen
    // app account, to that account — so the citizen sees "Cash via [agent]"
    // under their own parcel and can pull the receipt (docs/19 §2.D).
    $ownerId = (int)($ob['owner_id'] ?? 0);
    $citizenUserId = (int)($ob['citizen_user_id'] ?? 0);
    $st->execute([
        ':oid'    => $obligationId,
        ':pid'    => (int)$ob['parcel_id'],
        ':fy'     => (int)$ob['fee_year'],
        ':powner' => $ownerId > 0 ? $ownerId : null,
        ':pcit'   => $citizenUserId > 0 ? $citizenUserId : null,
        ':amt'    => $amount,
        ':ref'    => $reference,
        ':qr'     => $qr,
        ':aid'    => $meId,
        ':arole'  => $agentRole,
        ':pname'  => $payerName,
        ':pphone' => $payerPhone,
        ':pidcard'=> $payerIdCard,
    ]);
    $paymentId = (int)$st->fetch()['id'];

    // Save receipt HTML (immutable). Rendered as a RECEIPT, not a slip: the
    // citizen already handed cash to the agent, so the print copy names the
    // collector ("Received by") and thanks them — a pre-payment slip would
    // tell the citizen to go pay at the bank.
    $parcel = [
        'parcel_no' => $ob['parcel_no'],
        'sub_parcel_no' => $ob['sub_parcel_no'],
        'area_sqm' => $ob['area_sqm'],
        'title_no' => $ob['title_no'],
        'map_ref_no' => $ob['map_ref_no'],
        'province' => $ob['province'] ?? 'Savannakhet',
    ];
    $agentLabel = trim((string)$agent['username']);
    if ($agentRole !== '' && $agentLabel !== '') {
        $agentLabel .= ' (' . $agentRole . ')';
    } elseif ($agentRole !== '') {
        $agentLabel = $agentRole;
    }
    $receiptHtml = slip_render_html([
        'reference_no' => $reference,
        'amount' => $amount,
        'fee_year' => $ob['fee_year'],
        'method' => 'cash_agent',
        'due_date' => $ob['due_date'],
        'rate_per_sqm' => $ob['rate_per_sqm'],
        'amount_due' => $ob['amount_due'],
        'penalty' => $pen,
        'amount_paid' => $ob['amount_paid'],
    ], $parcel, $ob['district_name'], $ob['village_name'], $qr, true, $agentLabel);

    $st = $pdo->prepare("INSERT INTO payment_receipts (payment_id, receipt_html) VALUES (:pid, :html)");
    $st->execute([':pid' => $paymentId, ':html' => $receiptHtml]);

    // Audit
    fee_audit($pdo, $meId, 'agent_collect', 'payment', $paymentId, [
        'obligation_id' => $obligationId,
        'parcel_id' => (int)$ob['parcel_id'],
        'amount' => $amount,
        'reference_no' => $reference,
        'agent_id' => $meId,
        'agent_role' => $agentRole,
        'payer_name' => $payerName,
        'payer_phone' => $payerPhone,
        'payer_id_card' => $payerIdCard,
        'collected_at' => date('c'),
    ]);

    $pdo->commit();

    json_ok([
        'payment_id' => $paymentId,
        'reference_no' => $reference,
        'amount' => $amount,
        'status' => 'SUBMITTED',
        'collected_at' => date('c'),
        'receipt_url' => '/api/v1/agent/receipt.php?payment_id=' . $paymentId,
        'obligation' => [
            'id' => $obligationId,
            'fee_year' => (int)$ob['fee_year'],
            'parcel_no' => $ob['parcel_no'],
            'owner_name' => $ob['owner_name'],
            'outstanding_before' => $outstanding,
            'outstanding_after' => round($outstanding - $amount, 2),
        ],
    ], 201);

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    // Another agent won the race between the check and the insert.
    if ($e instanceof PDOException && $e->getCode() === '23505') {
        json_error('This obligation is already being collected by another agent', 409, 'already_collected');
    }
    error_log('Agent collect error: ' . $e->getMessage());
    json_error('Failed to record collection', 500, 'collect_failed');
}