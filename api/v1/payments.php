<?php
// API v1: payments — a settlement against an annual fee obligation (schema v2).
// Roles: officer/admin act on the queue; citizens may create + view their own slip.
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';
require_once __DIR__ . '/../../lib/slip.php';
require_once __DIR__ . '/../../lib/fee.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];

/** Obligation + parcel + owner context for a payment. */
function payment_context(PDO $pdo, array $payment): array {
    $out = ['obligation' => null, 'parcel' => null, 'owners' => []];
    if (!empty($payment['obligation_id'])) {
        $st = $pdo->prepare("SELECT o.*, d.name_lo AS district_name FROM fee_obligations o
            JOIN districts d ON d.id = o.district_id WHERE o.id = :id");
        $st->execute([':id' => (int)$payment['obligation_id']]);
        $out['obligation'] = $st->fetch() ?: null;
    }
    $st = $pdo->prepare("SELECT p.*, d.name_lo AS district_name, v.name_lo AS village_name
        FROM parcels p JOIN districts d ON d.id = p.district_id JOIN villages v ON v.id = p.village_id
        WHERE p.id = :id");
    $st->execute([':id' => (int)$payment['parcel_id']]);
    $out['parcel'] = $st->fetch() ?: null;
    $st = $pdo->prepare("SELECT o.*, po.role, po.share_pct FROM parcel_owners po
        JOIN owners o ON o.id = po.owner_id WHERE po.parcel_id = :pid ORDER BY o.id");
    $st->execute([':pid' => (int)$payment['parcel_id']]);
    $out['owners'] = $st->fetchAll();
    return $out;
}

/**
 * District scope check for staff acting on a payment.
 * Province-level staff (district_id null) may act anywhere in the province;
 * district-level staff only on their own district's parcels.
 */
function payment_in_scope(array $me, $districtId): bool {
    $scope = staff_scope($me);
    if ($scope['district_id'] === null) { return true; }
    return $districtId !== null && (int)$districtId === $scope['district_id'];
}

/** District id of the parcel behind a payment row. */
function payment_district(PDO $pdo, array $payment): ?int {
    $st = $pdo->prepare("SELECT p.district_id FROM payments pay
        JOIN parcels p ON p.id = pay.parcel_id WHERE pay.id = :id");
    $st->execute([':id' => (int)$payment['id']]);
    $d = $st->fetchColumn();
    return $d === false ? null : (int)$d;
}

// ---- CREATE a payment (settlement) against an obligation ----
// ?settle=1 = counter fast path (officer): money already in hand (cash/cheque),
// the payment is created directly PAID with verify_method='counter'.
if ($method === 'POST' && !isset($_GET['id'])) {
    $me = require_auth(['citizen', 'officer', 'admin']);
    $body = read_json_body();
    $settle = isset($_GET['settle']);

    $obligationId = (int)($body['obligation_id'] ?? 0);
    if (!$obligationId) {
        // Allow parcel_id + fee_year and resolve/assess the obligation first.
        $parcelId = (int)($body['parcel_id'] ?? 0);
        $feeYear  = (int)($body['fee_year'] ?? date('Y'));
        if ($parcelId > 0) {
            $ob = fee_upsert_obligation($pdo, $parcelId, $feeYear);
            if ($ob) { $obligationId = (int)$ob['id']; }
        }
    }
    if ($obligationId <= 0) {
        json_error('obligation_id (or parcel_id) is required', 400, 'missing_obligation');
    }

    $st = $pdo->prepare("SELECT o.*, p.parcel_no FROM fee_obligations o
        JOIN parcels p ON p.id = o.parcel_id WHERE o.id = :id");
    $st->execute([':id' => $obligationId]);
    $ob = $st->fetch();
    if (!$ob) { json_error('Obligation not found', 404, 'obligation_not_found'); }

    // District staff may only raise payments for their own district's parcels.
    if ($me['role'] !== 'citizen') {
        $st2 = $pdo->prepare("SELECT p.district_id FROM parcels p WHERE p.id = :id");
        $st2->execute([':id' => (int)$ob['parcel_id']]);
        $obDistrict = $st2->fetchColumn();
        $obDistrict = $obDistrict === false ? null : (int)$obDistrict;
        if (!payment_in_scope($me, $obDistrict)) {
            json_error('Payment outside your district', 403, 'out_of_scope');
        }
    }

    // Settle against what is still outstanding.
    $outstanding = round(((float)$ob['amount_due'] + (float)$ob['penalty']) - (float)$ob['amount_paid'], 2);
    if ($outstanding <= 0) {
        json_error('Obligation is already settled', 409, 'already_settled');
    }

    $method_ = $body['method'] ?? 'counter';
    if (!in_array($method_, ['counter', 'bank_transfer', 'mobile', 'cash', 'cheque'], true)) {
        json_error('Invalid payment method', 400, 'invalid_method');
    }
    // Cheque is a district-office channel only — a citizen cannot present a cheque through the app.
    if ($me['role'] === 'citizen' && in_array($method_, ['cheque', 'cash'], true)) {
        json_error('This payment method is only available at the district office', 403, 'counter_method_only');
    }
    if ($settle) {
        require_staff_write($me);
        if (!in_array($method_, ['cash', 'cheque'], true)) {
            json_error('Counter settlement accepts cash or cheque only', 400, 'invalid_method');
        }
    }
    $payerOwnerId = !empty($body['payer_owner_id']) ? (int)$body['payer_owner_id'] : null;
    $amount = isset($body['amount']) && $body['amount'] !== '' ? (float)$body['amount'] : $outstanding;
    if ($amount <= 0) { json_error('amount must be positive', 400, 'invalid_amount'); }
    $amount = min(round($amount, 2), $outstanding);

    $reference = slip_generate_no($pdo);
    $qr = slip_qr_payload($reference, $amount);
    $chequeNo = trim((string)($body['cheque_no'] ?? $body['bank_ref'] ?? '')) ?: null;

    $st = $pdo->prepare("INSERT INTO payments
        (obligation_id, parcel_id, fee_year, payer_owner_id, method, amount, reference_no, slip_qr, status,
         paid_at, verified_by, verify_method, verify_note, bank_ref)
        VALUES (:oid, :pid, :fy, :poid, :m, :amt, :ref, :qr, :status,
         :paid_at, :vby, :vm, :vnote, :bref) RETURNING id");
    $st->execute([
        ':oid' => $obligationId, ':pid' => (int)$ob['parcel_id'], ':fy' => (int)$ob['fee_year'],
        ':poid' => $payerOwnerId, ':m' => $method_, ':amt' => $amount, ':ref' => $reference, ':qr' => $qr,
        ':status' => $settle ? 'PAID' : 'ISSUED',
        ':paid_at' => $settle ? date('Y-m-d H:i:s') : null,
        ':vby' => $settle ? $me['sub'] : null,
        ':vm' => $settle ? 'counter' : null,
        ':vnote' => $settle ? (trim((string)($body['note'] ?? '')) ?: null) : null,
        ':bref' => $chequeNo,
    ]);
    $paymentId = (int)$st->fetch()['id'];
    if ($settle) {
        $obligation = fee_settle_obligation($pdo, $obligationId);
        fee_audit($pdo, $me['sub'] ?? null, 'payment_counter', 'payment', $paymentId, [
            'obligation_id' => $obligationId, 'amount' => $amount, 'reference_no' => $reference,
            'method' => $method_, 'cheque_no' => $chequeNo,
        ]);
        json_ok(['id' => $paymentId, 'reference_no' => $reference, 'amount' => $amount,
            'status' => 'PAID', 'obligation' => $obligation, 'receipt' => 'payments.php?id=' . $paymentId . '&receipt=1'], 201);
    }
    fee_audit($pdo, $me['sub'] ?? null, 'payment_issue', 'payment', $paymentId, [
        'obligation_id' => $obligationId, 'amount' => $amount, 'reference_no' => $reference, 'method' => $method_,
    ]);
    json_ok(['id' => $paymentId, 'reference_no' => $reference, 'amount' => $amount,
        'slip_qr' => $qr, 'status' => 'ISSUED', 'due_date' => $ob['due_date']], 201);
}

// ---- Printable slip / receipt ----
if ($method === 'GET' && isset($_GET['id']) && (isset($_GET['slip']) || isset($_GET['receipt']))) {
    $receipt = isset($_GET['receipt']);
    $me = require_auth(['citizen', 'officer', 'admin', 'manager']);
    $id = (int)$_GET['id'];
    $st = $pdo->prepare("SELECT * FROM payments WHERE id = :id");
    $st->execute([':id' => $id]);
    $payment = $st->fetch();
    if (!$payment) { json_error('Payment not found', 404, 'not_found'); }
    if ($me['role'] === 'citizen') {
        $owns = (int)$pdo->query("SELECT COUNT(*) FROM parcel_owners po JOIN owners o ON o.id = po.owner_id
            WHERE o.citizen_user_id = " . (int)$me['sub'] . " AND po.parcel_id = " . (int)$payment['parcel_id'])->fetchColumn();
        if (!$owns) {
            json_error('Forbidden', 403, 'forbidden');
        }
    } elseif (!payment_in_scope($me, payment_district($pdo, $payment))) {
        json_error('Payment outside your district', 403, 'out_of_scope');
    }
    $ctx = payment_context($pdo, $payment);
    $slip = $payment;
    $slip['amount']        = (float)$payment['amount'];
    $slip['rate_per_sqm']  = (float)($ctx['obligation']['rate_per_sqm'] ?? 0);
    $slip['due_date']      = $ctx['obligation']['due_date'] ?? '';
    $officerName = '';
    if ($receipt && !empty($payment['verified_by'])) {
        $stU = $pdo->prepare("SELECT username FROM users WHERE id = :id");
        $stU->execute([':id' => (int)$payment['verified_by']]);
        $officerName = (string)($stU->fetchColumn() ?: '');
    }
    $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=' . urlencode((string)$payment['slip_qr']);
    header('Content-Type: text/html; charset=UTF-8');
    echo slip_render_html($slip, $ctx['parcel'] ?? [], $ctx['parcel']['district_name'] ?? '', $ctx['parcel']['village_name'] ?? '', $qr_url, $receipt, $officerName);
    exit;
}

// ---- Upload the paid slip photo ----
if ($method === 'POST' && isset($_GET['id']) && isset($_GET['receipt'])) {
    $me = require_auth(['citizen', 'officer', 'admin']);
    $id = (int)$_GET['id'];
    if (!isset($_FILES['slip']) || $_FILES['slip']['error'] !== UPLOAD_ERR_OK) {
        json_error('No slip file uploaded', 400, 'no_file');
    }
    $st = $pdo->prepare("SELECT id, parcel_id, citizen_user_id FROM payments WHERE id = :id");
    $st->execute([':id' => $id]);
    $pRow = $st->fetch();
    if (!$pRow) { json_error('Payment not found', 404, 'not_found'); }
    if ($me['role'] !== 'citizen') {
        if (!payment_in_scope($me, payment_district($pdo, $pRow))) {
            json_error('Payment outside your district', 403, 'out_of_scope');
        }
    } else {
        // A citizen may only attach a receipt to a payment they made, or to one
        // on land they own. Without this, any citizen could file a receipt
        // against any payment simply by guessing its id.
        $owns = $pdo->prepare("SELECT 1 FROM parcel_owners po
            JOIN owners o ON o.id = po.owner_id
            WHERE po.parcel_id = :pid AND o.citizen_user_id = :uid");
        $owns->execute([':pid' => (int)$pRow['parcel_id'], ':uid' => (int)$me['sub']]);
        $made = (int)($pRow['citizen_user_id'] ?? 0) === (int)$me['sub'];
        if (!$made && !$owns->fetch()) {
            json_error('Forbidden', 403, 'forbidden');
        }
    }

    $cfg = require __DIR__ . '/../../lib/config.php';
    $uploadDir = $cfg['uploads'];
    if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }
    $ext = strtolower(pathinfo($_FILES['slip']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf', 'webp'], true)) {
        json_error('Unsupported file type', 400, 'bad_type');
    }
    $filename = uniqid('slip_', true) . '.' . $ext;
    if (!move_uploaded_file($_FILES['slip']['tmp_name'], $uploadDir . $filename)) {
        json_error('Failed to save slip', 500, 'upload_failed');
    }
    $pdo->prepare("UPDATE payments SET slip_path = :p WHERE id = :id")
        ->execute([':p' => '/public/uploads/' . $filename, ':id' => $id]);
    json_ok(['id' => $id, 'slip_path' => '/public/uploads/' . $filename]);
}

// ---- VERIFY (officer) ----
if ($method === 'POST' && isset($_GET['id']) && isset($_GET['verify'])) {
    $me = require_auth(['officer', 'admin']);
    require_staff_write($me);
    $id = (int)$_GET['id'];
    $body = read_json_body();
    $st = $pdo->prepare("SELECT * FROM payments WHERE id = :id");
    $st->execute([':id' => $id]);
    $payment = $st->fetch();
    if (!$payment) { json_error('Payment not found', 404, 'not_found'); }
    if (!payment_in_scope($me, payment_district($pdo, $payment))) {
        json_error('Payment outside your district', 403, 'out_of_scope');
    }
    if ($payment['status'] === 'PAID') { json_error('Already paid', 409, 'already_paid'); }
    if ($payment['status'] === 'REJECTED') { json_error('Payment was rejected', 409, 'rejected'); }

    // Amount actually received: defaults to the slip amount, but the officer may
    // record less (partial) or more (overpaid) — fee_settle_obligation derives
    // the obligation status from the SUM of PAID payments.
    $received = (isset($body['amount']) && $body['amount'] !== '' && $body['amount'] !== null)
        ? round((float)$body['amount'], 2)
        : null;
    if ($received !== null && $received <= 0) {
        json_error('amount must be positive', 400, 'invalid_amount');
    }

    $sets  = "status='PAID', paid_at=CURRENT_TIMESTAMP, verified_by=:v, verify_method='manual', verify_note=:n";
    $params = [':v' => $me['sub'], ':n' => $body['note'] ?? null];
    if ($received !== null) {
        $sets .= ', amount = :amt';
        $params[':amt'] = $received;
    }
    $params[':id'] = $id;
    $pdo->prepare("UPDATE payments SET $sets WHERE id=:id")->execute($params);
    $obligation = !empty($payment['obligation_id']) ? fee_settle_obligation($pdo, (int)$payment['obligation_id']) : null;
    fee_audit($pdo, $me['sub'] ?? null, 'payment_verify', 'payment', $id, [
        'reference_no' => $payment['reference_no'], 'amount' => $received ?? (float)$payment['amount'], 'method' => 'manual',
    ]);
    json_ok(['id' => $id, 'status' => 'PAID', 'amount' => $received ?? (float)$payment['amount'], 'obligation' => $obligation]);
}

// ---- REJECT (officer) ----
if ($method === 'POST' && isset($_GET['id']) && isset($_GET['reject'])) {
    $me = require_auth(['officer', 'admin']);
    require_staff_write($me);
    $id = (int)$_GET['id'];
    $body = read_json_body();
    $st = $pdo->prepare("SELECT * FROM payments WHERE id = :id");
    $st->execute([':id' => $id]);
    $payment = $st->fetch();
    if (!$payment) { json_error('Payment not found', 404, 'not_found'); }
    if (!payment_in_scope($me, payment_district($pdo, $payment))) {
        json_error('Payment outside your district', 403, 'out_of_scope');
    }
    if ($payment['status'] !== 'ISSUED') { json_error('Payment is not in ISSUED state', 409, 'bad_state'); }

    $pdo->prepare("UPDATE payments SET status='REJECTED', verified_by=:v, verify_method='manual',
        verify_note=:n WHERE id=:id")
        ->execute([':v' => $me['sub'], ':n' => $body['reason'] ?? null, ':id' => $id]);
    $obligation = !empty($payment['obligation_id']) ? fee_settle_obligation($pdo, (int)$payment['obligation_id']) : null;
    fee_audit($pdo, $me['sub'] ?? null, 'payment_reject', 'payment', $id, [
        'reference_no' => $payment['reference_no'], 'reason' => $body['reason'] ?? null,
    ]);
    json_ok(['id' => $id, 'status' => 'REJECTED', 'obligation' => $obligation]);
}

// ---- WAIVE the obligation behind a payment (officer) ----
if ($method === 'POST' && isset($_GET['id']) && isset($_GET['waive'])) {
    $me = require_auth(['officer', 'admin']);
    require_staff_write($me);
    $id = (int)$_GET['id'];
    $body = read_json_body();
    $st = $pdo->prepare("SELECT * FROM payments WHERE id = :id");
    $st->execute([':id' => $id]);
    $payment = $st->fetch();
    if (!$payment) { json_error('Payment not found', 404, 'not_found'); }
    if (!payment_in_scope($me, payment_district($pdo, $payment))) {
        json_error('Payment outside your district', 403, 'out_of_scope');
    }
    if (empty($payment['obligation_id'])) { json_error('Payment has no obligation', 409, 'no_obligation'); }

    $pdo->prepare("UPDATE fee_obligations SET status='WAIVED', updated_at=now() WHERE id=:id")
        ->execute([':id' => (int)$payment['obligation_id']]);
    fee_audit($pdo, $me['sub'] ?? null, 'obligation_waive', 'obligation', (int)$payment['obligation_id'], [
        'reason' => $body['reason'] ?? null, 'reference_no' => $payment['reference_no'],
    ]);
    json_ok(['id' => $id, 'obligation_id' => (int)$payment['obligation_id'], 'status' => 'WAIVED']);
}

// ---- LIST ----
if ($method === 'GET') {
    $me = require_auth(['citizen', 'officer', 'admin', 'manager']);
    $status   = $_GET['status'] ?? null;
    $methodF  = $_GET['method'] ?? '';
    $feeYear  = isset($_GET['fee_year']) ? (int)$_GET['fee_year'] : 0;
    $district = isset($_GET['district_id']) ? (int)$_GET['district_id'] : 0;
    $paidAfter = $_GET['paid_after'] ?? '';

    $where = [];
    $params = [];
    if ($status !== null && $status !== '') { $where[] = 'pay.status = :s'; $params[':s'] = $status; }
    if ($methodF !== '') { $where[] = 'pay.method = :m'; $params[':m'] = $methodF; }
    if ($feeYear)  { $where[] = 'pay.fee_year = :fy'; $params[':fy'] = $feeYear; }
    if ($paidAfter !== '') { $where[] = 'pay.paid_at >= :pa'; $params[':pa'] = $paidAfter; }
    if ($district) { $where[] = 'par.district_id = :did'; $params[':did'] = $district; }
    // District staff see only their own district's payments.
    if ($me['role'] !== 'citizen') {
        $scope = staff_scope($me);
        if ($scope['district_id'] !== null) {
            $where[] = 'par.district_id = :scope_did';
            $params[':scope_did'] = $scope['district_id'];
        }
    }
    if ($me['role'] === 'citizen') {
        // A citizen sees payments only for parcels they own (owners.citizen_user_id).
        // Note payer_owner_id points at owners.id, NOT users.id — it cannot be used to
        // scope citizens directly.
        $where[] = 'pay.parcel_id IN (SELECT po.parcel_id FROM parcel_owners po
                    JOIN owners o ON o.id = po.owner_id WHERE o.citizen_user_id = :me)';
        $params[':me'] = $me['sub'];
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "SELECT pay.*, par.parcel_no, par.area_sqm, d.name_lo AS district_name, v.name_lo AS village_name,
            o.full_name AS payer_name, ob.status AS obligation_status
        FROM payments pay
        JOIN parcels par ON par.id = pay.parcel_id
        JOIN districts d ON d.id = par.district_id
        JOIN villages v ON v.id = par.village_id
        LEFT JOIN owners o ON o.id = pay.payer_owner_id
        LEFT JOIN fee_obligations ob ON ob.id = pay.obligation_id
        $whereSql
        ORDER BY pay.created_at DESC LIMIT 300";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    // The list is capped at 300 rows; these are the totals for the same filters.
    $totStmt = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(pay.amount), 0) AS total
        FROM payments pay
        JOIN parcels par ON par.id = pay.parcel_id
        $whereSql");
    $totStmt->execute($params);
    $tot = $totStmt->fetch();
    json_ok([
        'payments' => $rows,
        'count' => count($rows),
        'total' => (int)$tot['n'],
        'amount_total' => (float)$tot['total'],
    ]);
}

json_error('Not found', 404, 'not_found');