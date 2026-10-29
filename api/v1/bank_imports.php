<?php
// API v1: bank statement imports — POST import CSV (+ auto-match), GET list/detail,
// POST re-match, POST per-transaction officer override.
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';
require_once __DIR__ . '/../../lib/fee.php';
require_once __DIR__ . '/../../lib/bank_match.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];
$me = require_auth(['officer', 'admin', 'manager']);
$scope = staff_scope($me);

/** True when the import was created by a user in the acting officer's district. */
function bank_import_in_scope(PDO $pdo, array $scope, int $importId): bool
{
    if ($scope['district_id'] === null) { return true; }
    $st = $pdo->prepare("SELECT 1 FROM bank_imports b
        JOIN users u ON u.id = b.imported_by
        WHERE b.id = :i AND u.district_id = :d");
    $st->execute([':i' => $importId, ':d' => $scope['district_id']]);
    return (bool)$st->fetchColumn();
}

// ---- GET: imports list, or one import with its transactions ----
if ($method === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id) {
        $st = $pdo->prepare("SELECT b.*, u.username AS imported_by_name FROM bank_imports b
            LEFT JOIN users u ON u.id = b.imported_by WHERE b.id = :id");
        $st->execute([':id' => $id]);
        $imp = $st->fetch();
        if (!$imp) { json_error('Import not found', 404, 'not_found'); }
        if (!bank_import_in_scope($pdo, $scope, $id)) {
            json_error('Import outside your district', 403, 'out_of_scope');
        }
        $st = $pdo->prepare("SELECT t.*, pay.reference_no AS pay_ref, pay.amount AS pay_amount,
                par.parcel_no, par.sub_parcel_no, d.name_lo AS district_name
            FROM bank_transactions t
            LEFT JOIN payments pay ON pay.id = t.matched_payment_id
            LEFT JOIN parcels par ON par.id = pay.parcel_id
            LEFT JOIN districts d ON d.id = par.district_id
            WHERE t.import_id = :i ORDER BY t.txn_date NULLS LAST, t.id");
        $st->execute([':i' => $id]);
        json_ok(['import' => $imp, 'transactions' => $st->fetchAll()]);
    }
    // Bank imports are scoped to the importer's district for district staff.
    if ($scope['district_id'] !== null) {
        $st = $pdo->prepare("SELECT b.*, u.username AS imported_by_name FROM bank_imports b
            LEFT JOIN users u ON u.id = b.imported_by WHERE b.imported_by IN
            (SELECT id FROM users WHERE district_id = :did) ORDER BY b.imported_at DESC");
        $st->execute([':did' => $scope['district_id']]);
    } else {
        $st = $pdo->query("SELECT b.*, u.username AS imported_by_name FROM bank_imports b
            LEFT JOIN users u ON u.id = b.imported_by ORDER BY b.imported_at DESC");
    }
    json_ok(['imports' => $st->fetchAll()]);
}

// ---- POST: import a statement (multipart file or JSON csv) then auto-match ----
if ($method === 'POST' && !isset($_GET['id'])) {
    require_staff_write($me);   // only district admin/officer import statements
    // Column mapping (0-based). Null = auto-detect from header by name.
    $map = [
        'date'      => isset($_POST['date_col'])      && $_POST['date_col']      !== '' ? (int)$_POST['date_col']      : null,
        'amount'    => isset($_POST['amount_col'])    && $_POST['amount_col']    !== '' ? (int)$_POST['amount_col']    : 1,
        'reference' => isset($_POST['reference_col']) && $_POST['reference_col'] !== '' ? (int)$_POST['reference_col'] : null,
        'bank_ref'  => isset($_POST['bank_ref_col'])  && $_POST['bank_ref_col']  !== '' ? (int)$_POST['bank_ref_col']  : null,
    ];
    $hasHeader = !isset($_POST['no_header']);

    $filename = 'statement.csv';
    if (!empty($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $csv = file_get_contents($_FILES['file']['tmp_name']);
        $filename = $_FILES['file']['name'] ?: $filename;
    } else {
        $body = read_json_body();
        if (!empty($body['csv'])) {
            $csv = (string)$body['csv'];
            $filename = $body['filename'] ?? $filename;
            foreach (['date_col','amount_col','reference_col','bank_ref_col'] as $k) {
                if (isset($body[$k]) && $body[$k] !== '' && $body[$k] !== null) { $map[str_replace('_col','',$k)] = (int)$body[$k]; }
            }
            if (isset($body['has_header'])) { $hasHeader = (bool)$body['has_header']; }
        } else {
            json_error('No CSV provided (multipart file or json csv)', 400, 'no_csv');
        }
    }

    $rows = bank_parse_csv($csv, $map, $hasHeader);
    if (!$rows) { json_error('No usable rows in statement', 400, 'empty_csv'); }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("INSERT INTO bank_imports (filename, imported_by, row_count)
            VALUES (:f, :u, 0) RETURNING id");
        $st->execute([':f' => $filename, ':u' => $me['sub']]);
        $importId = (int)$st->fetch()['id'];

        $ins = $pdo->prepare("INSERT INTO bank_transactions (import_id, txn_date, amount, reference_no, bank_ref, raw_line)
            VALUES (:i, :d, :a, :r, :b, :raw)");
        foreach ($rows as $r) {
            $ins->execute([
                ':i' => $importId, ':d' => $r['txn_date'], ':a' => $r['amount'],
                ':r' => $r['reference_no'], ':b' => $r['bank_ref'], ':raw' => $r['raw_line'],
            ]);
        }
        $pdo->prepare("UPDATE bank_imports SET row_count = :n WHERE id = :i")
            ->execute([':n' => count($rows), ':i' => $importId]);
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        json_error('Import failed: ' . $e->getMessage(), 500, 'import_failed');
    }

    $summary = bank_match_run($pdo, $importId, $me['sub'] ?? null, $scope['district_id']);
    $pdo->prepare("UPDATE bank_imports SET matched_count = :m WHERE id = :i")
        ->execute([':m' => $summary['matched'] + $summary['overpaid'], ':i' => $importId]);
    fee_audit($pdo, $me['sub'] ?? null, 'bank_import', 'bank_import', $importId, [
        'filename' => $filename, 'rows' => count($rows), 'summary' => $summary,
    ]);
    json_ok(['import_id' => $importId, 'rows' => count($rows), 'summary' => $summary], 201);
}

// ---- POST: re-run matching for an import ----
if ($method === 'POST' && isset($_GET['id']) && isset($_GET['rematch'])) {
    require_staff_write($me);
    $id = (int)$_GET['id'];
    $st = $pdo->prepare("SELECT id FROM bank_imports WHERE id = :id");
    $st->execute([':id' => $id]);
    $imp = $st->fetch();
    if (!$imp) { json_error('Import not found', 404, 'not_found'); }
    // District staff may only rematch their own imports.
    if (!bank_import_in_scope($pdo, $scope, $id)) {
        json_error('Import outside your district', 403, 'out_of_scope');
    }
    $summary = bank_match_run($pdo, $id, $me['sub'] ?? null, $scope['district_id']);
    $pdo->prepare("UPDATE bank_imports SET matched_count = :m WHERE id = :i")
        ->execute([':m' => $summary['matched'] + $summary['overpaid'], ':i' => $id]);
    fee_audit($pdo, $me['sub'] ?? null, 'bank_rematch', 'bank_import', $id, ['summary' => $summary]);
    json_ok(['import_id' => $id, 'summary' => $summary]);
}

// ---- POST: officer override for one transaction (apply / ignore) ----
if ($method === 'POST' && isset($_GET['txn_id'])) {
    require_staff_write($me);
    $txnId = (int)$_GET['txn_id'];
    $body = read_json_body();
    $action = $body['action'] ?? '';
    $st = $pdo->prepare("SELECT * FROM bank_transactions WHERE id = :id");
    $st->execute([':id' => $txnId]);
    $t = $st->fetch();
    if (!$t) { json_error('Transaction not found', 404, 'not_found'); }
    // District staff may only act on transactions from their own district's imports.
    if (!bank_import_in_scope($pdo, $scope, (int)$t['import_id'])) {
        json_error('Transaction outside your district', 403, 'out_of_scope');
    }

    if ($action === 'ignore') {
        $pdo->prepare("UPDATE bank_transactions SET match_status='ignored' WHERE id=:id")
            ->execute([':id' => $txnId]);
        fee_audit($pdo, $me['sub'] ?? null, 'bank_txn_ignore', 'bank_transaction', $txnId, []);
        json_ok(['txn_id' => $txnId, 'match_status' => 'ignored']);
    }

    if ($action === 'apply') {
        $payId = (int)($body['payment_id'] ?? $t['matched_payment_id'] ?? 0);
        if (!$payId) { json_error('payment_id required', 400, 'missing_payment'); }
        $st = $pdo->prepare("SELECT * FROM payments WHERE id = :id");
        $st->execute([':id' => $payId]);
        $pay = $st->fetch();
        if (!$pay) { json_error('Payment not found', 404, 'not_found'); }
        if ($pay['status'] === 'PAID') { json_error('Payment already paid', 409, 'already_paid'); }
        // The explicit payment_id must also be in the actor's district.
        if ($scope['district_id'] !== null) {
            $dst = $pdo->prepare("SELECT district_id FROM parcels WHERE id = :pid");
            $dst->execute([':pid' => (int)$pay['parcel_id']]);
            $pd = $dst->fetchColumn();
            if ($pd === false || (int)$pd !== (int)$scope['district_id']) {
                json_error('Payment outside your district', 403, 'out_of_scope');
            }
        }

        $pdo->prepare("UPDATE payments SET status='PAID', paid_at=CURRENT_TIMESTAMP, verified_by=:v,
            verify_method='manual', bank_import_id=:imp, verify_note=:n WHERE id=:id")
            ->execute([':v' => $me['sub'], ':imp' => (int)$t['import_id'], ':n' => $body['note'] ?? 'statement override', ':id' => $payId]);
        $obligation = !empty($pay['obligation_id']) ? fee_settle_obligation($pdo, (int)$pay['obligation_id']) : null;
        $pdo->prepare("UPDATE bank_transactions SET matched_payment_id=:pid, match_status='matched' WHERE id=:id")
            ->execute([':pid' => $payId, ':id' => $txnId]);
        fee_audit($pdo, $me['sub'] ?? null, 'bank_txn_apply', 'bank_transaction', $txnId, [
            'payment_id' => $payId, 'obligation_status' => $obligation['status'] ?? null,
        ]);
        json_ok(['txn_id' => $txnId, 'payment_id' => $payId, 'match_status' => 'matched', 'obligation' => $obligation]);
    }

    json_error('Unknown action', 400, 'bad_action');
}

json_error('Not found', 404, 'not_found');