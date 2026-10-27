<?php
// API v1: citizen_payments.php — the citizen's own payments.
//
// Anyone may pay for any parcel: a citizen does not have to own it, and the
// parcel does not have to be linked to their account — they know the parcel
// number from their land title, and paying the tax on it is the point. What the
// endpoint does record is *who paid* (`payments.citizen_user_id`), so the
// payment appears in that citizen's own history.
// See docs/9-citizen-app-api-contract.md §3.
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';
require_once __DIR__ . '/../../lib/slip.php';
require_once __DIR__ . '/../../lib/fee.php';

$pdo    = db_connect();
$me     = require_auth(['citizen', 'officer', 'admin']);
$method = $_SERVER['REQUEST_METHOD'];
$meId   = (int)$me['sub'];

/** The one parcel the citizen named — EXACT number match, never a lookup by prefix. */
function citizen_parcel_by_no(PDO $pdo, string $parcelNo): array {
    $st = $pdo->prepare("SELECT id AS parcel_id, parcel_no FROM parcels
        WHERE parcel_no = :pn ORDER BY id");
    $st->execute([':pn' => $parcelNo]);
    $rows = $st->fetchAll();
    if (!$rows) {
        json_error('No parcel with that number', 404, 'parcel_not_found');
    }
    if (count($rows) > 1) {
        json_error('That parcel number exists more than once — ask the land office which one is yours',
            400, 'ambiguous_parcel');
    }
    return $rows[0];
}

/** Does this citizen own the parcel behind a payment record? */
function citizen_owns_parcel(PDO $pdo, int $userId, $parcelId): bool {
    $st = $pdo->prepare("SELECT 1 FROM parcel_owners po
        JOIN owners o ON o.id = po.owner_id
        WHERE po.parcel_id = :pid AND o.citizen_user_id = :uid");
    $st->execute([':pid' => (int)$parcelId, ':uid' => $userId]);
    return (bool)$st->fetch();
}

// ---- GET: payments this citizen made, plus those on land they own ----------
if ($method === 'GET') {
    // Two distinct placeholders: pdo_pgsql does not tolerate one named
    // parameter bound twice in the same statement.
    $st = $pdo->prepare("SELECT pay.id, pay.reference_no, pay.amount, pay.status, pay.method,
            pay.fee_year, pay.created_at, pay.paid_at, pay.slip_path, pay.verify_note,
            p.parcel_no, d.name_lo AS district_name, v.name_lo AS village_name,
            o.status AS obligation_status
        FROM payments pay
        JOIN parcels p   ON p.id = pay.parcel_id
        JOIN districts d ON d.id = p.district_id
        JOIN villages  v ON v.id = p.village_id
        LEFT JOIN fee_obligations o ON o.id = pay.obligation_id
        WHERE pay.citizen_user_id = :made
           OR pay.parcel_id IN (
                SELECT po.parcel_id
                  FROM parcel_owners po
                  JOIN owners ow ON ow.id = po.owner_id
                 WHERE ow.citizen_user_id = :owns)
        ORDER BY pay.created_at DESC, pay.id DESC");
    $st->execute([':made' => $meId, ':owns' => $meId]);
    $rows = $st->fetchAll();
    json_ok(['payments' => $rows, 'count' => count($rows)]);
}

// ---- POST ?id=N&receipt=1: attach the photo of the paid bank slip ----------
if ($method === 'POST' && isset($_GET['id']) && isset($_GET['receipt'])) {
    $id = (int)$_GET['id'];
    $st = $pdo->prepare("SELECT id, parcel_id, citizen_user_id FROM payments WHERE id = :id");
    $st->execute([':id' => $id]);
    $payment = $st->fetch();
    if (!$payment) {
        json_error('Payment not found', 404, 'not_found');
    }

    // A citizen may only attach a receipt to a payment they made, or to one on
    // land they own. This is about whose record is being touched — it does not
    // restrict who may PAY (see the create branch below).
    if ($me['role'] === 'citizen') {
        $made = (int)($payment['citizen_user_id'] ?? 0) === $meId;
        $owns = citizen_owns_parcel($pdo, $meId, $payment['parcel_id']);
        if (!$made && !$owns) {
            json_error('Forbidden', 403, 'forbidden');
        }
    }

    if (empty($_FILES['slip']['name'])) {
        json_error('No file uploaded', 400, 'no_file');
    }
    $cfg       = require __DIR__ . '/../../lib/config.php';
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

    $path = '/public/uploads/' . $filename;
    $pdo->prepare("UPDATE payments SET slip_path = :p WHERE id = :id")
        ->execute([':p' => $path, ':id' => $id]);
    fee_audit($pdo, $meId, 'payment_receipt', 'payment', $id, ['slip_path' => $path]);
    json_ok(['id' => $id, 'slip_path' => $path]);
}

// ---- POST: issue a payment slip -------------------------------------------
if ($method === 'POST') {
    $body       = read_json_body();
    $parcelNo   = trim((string)($body['parcel_no'] ?? ''));
    $parcelIdIn = (int)($body['parcel_id'] ?? 0);
    $feeYear    = (int)($body['fee_year'] ?? 0) ?: (int)date('Y');
    $payMethod  = (string)($body['method'] ?? 'counter');

    if ($parcelNo === '' && $parcelIdIn <= 0) {
        json_error('parcel_no (or parcel_id) is required', 400, 'missing_parcel');
    }
    if (!in_array($payMethod, ['counter', 'bank_transfer', 'mobile', 'cash'], true)) {
        json_error('Invalid payment method', 400, 'invalid_method');
    }

    if ($parcelNo !== '') {
        $parcel = citizen_parcel_by_no($pdo, $parcelNo);
    } else {
        $st = $pdo->prepare("SELECT id AS parcel_id, parcel_no FROM parcels WHERE id = :id");
        $st->execute([':id' => $parcelIdIn]);
        $parcel = $st->fetch();
        if (!$parcel) {
            json_error('No parcel with that id', 404, 'parcel_not_found');
        }
    }
    $parcelId = (int)$parcel['parcel_id'];

    // 1. Price it server-side and persist the assessment. The rate comes from
    //    the parcel's zone code — never from the client.
    $ob = fee_upsert_obligation($pdo, $parcelId, $feeYear);
    if (!$ob) {
        json_error('Parcel not found', 404, 'parcel_not_found');
    }

    // 2. Refuse when there is nothing left to pay.
    $outstanding = round(((float)$ob['amount_due'] + (float)$ob['penalty']) - (float)$ob['amount_paid'], 2);
    if ($outstanding <= 0) {
        json_error('This parcel has nothing left to pay for ' . $feeYear, 409, 'already_settled');
    }

    // Amount defaults to the full balance and is capped at it (partials allowed).
    $amount = (isset($body['amount']) && $body['amount'] !== '' && $body['amount'] !== null)
        ? (float)$body['amount']
        : $outstanding;
    if ($amount <= 0) {
        json_error('amount must be positive', 400, 'invalid_amount');
    }
    $amount = min(round($amount, 2), $outstanding);

    // 3./4. Reference, QR payload, payment row attributed to the payer.
    $reference = slip_generate_no($pdo);
    $qr        = slip_qr_payload($reference, $amount, (string)$parcel['parcel_no']);

    $st = $pdo->prepare("INSERT INTO payments
        (obligation_id, parcel_id, fee_year, payer_owner_id, citizen_user_id, method, amount,
         reference_no, slip_qr, status)
        VALUES (:oid, :pid, :fy, NULL, :cuid, :m, :amt, :ref, :qr, 'ISSUED') RETURNING id");
    $st->execute([
        ':oid'  => (int)$ob['id'],
        ':pid'  => $parcelId,
        ':fy'   => $feeYear,
        ':cuid' => $meId,
        ':m'    => $payMethod,
        ':amt'  => $amount,
        ':ref'  => $reference,
        ':qr'   => $qr,
    ]);
    $paymentId = (int)$st->fetch()['id'];

    // 5. Audit it.
    fee_audit($pdo, $meId, 'payment_issue', 'payment', $paymentId, [
        'obligation_id' => (int)$ob['id'],
        'amount'        => $amount,
        'reference_no'  => $reference,
        'method'        => $payMethod,
        'channel'       => 'citizen',
    ]);

    json_ok([
        'id'           => $paymentId,
        'reference_no' => $reference,
        'amount'       => $amount,
        'slip_qr'      => $qr,
        'status'       => 'ISSUED',
        'due_date'     => $ob['due_date'],
        'parcel_id'    => $parcelId,
        'fee_year'     => $feeYear,
        'outstanding'  => $outstanding,
    ], 201);
}

json_error('Method not allowed', 405, 'method_not_allowed');
