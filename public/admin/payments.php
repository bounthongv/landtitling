<?php
// public/admin/payments.php — payments by channel: counter (cash/cheque),
// bank & mobile proofs (review), and the full ledger.
require __DIR__ . '/common.php';
require_once __DIR__ . '/../../lib/fee.php';   // payment_method_label()
if (!$is_logged_in) { header('Location: login.php'); exit; }
$role = $user['role'];
$token = $_SESSION['admin_token'];

// Active tab comes from the URL (POST forms post back to the same URL, so the
// tab survives every action). Counter is the default — that is the daily action.
$tab = $_GET['tab'] ?? 'counter';
if (!in_array($tab, ['counter', 'bank', 'all'], true)) { $tab = 'counter'; }

$status = $_GET['status'] ?? 'ISSUED';
$methodF = $_GET['method'] ?? '';
$fee_year = isset($_GET['fee_year']) ? (int)$_GET['fee_year'] : 0;

$msg = '';
$msgType = 'ok';
$lastReceipt = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['do_action'])) {
    $id = (int)($_POST['payment_id'] ?? 0);
    $action = $_POST['do_action'];                 // verify | reject | waive | settle
    $body = [];
    if ($action === 'reject') {
        $reason = trim((string)($_POST['reason'] ?? ''));
        // Officer's own reason; the blank box falls back to the default.
        $body['reason'] = $reason !== '' ? $reason : t('Rejected by officer');
    }
    if ($action === 'verify') {
        $body['note'] = $_POST['note'] ?? null;
        // Amount actually received (optional) — partial/overpaid recorded here.
        if (isset($_POST['amount']) && $_POST['amount'] !== '') {
            $body['amount'] = (float)$_POST['amount'];
        }
    }
    if ($action === 'waive')  { $body['reason'] = $_POST['reason'] ?? t('Waived by officer'); }
    if ($action === 'settle') {
        // Counter flow: money already collected at the office.
        // cash/cheque: create + settle in one step (verify_method='counter').
        // bank_transfer/mobile: the citizen paid at the bank / mobile money and
        // brought the proof in — create as ISSUED, attach the evidence photo
        // if provided, then verify so the obligation settles. If the verify
        // step fails, the payment stays ISSUED in the Bank tab's review queue.
        $parcelNo = trim((string)($_POST['parcel_no'] ?? ''));
        $year = (int)($_POST['fee_year'] ?? date('Y'));
        $method = $_POST['method'] ?? 'cash';
        $chequeNo = trim((string)($_POST['cheque_no'] ?? ''));
        $bankRef = trim((string)($_POST['bank_ref'] ?? ''));
        $amount = (isset($_POST['amount']) && $_POST['amount'] !== '') ? (float)$_POST['amount'] : null;
        $slipUpload = (!empty($_FILES['slip']) && ($_FILES['slip']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK)
            ? $_FILES['slip'] : null;
        $pid = (int)($_POST['parcel_id'] ?? 0);
        // parcel_id is authoritative: parcel numbers repeat across villages, so
        // resolving by number alone could settle the wrong parcel.
        if (!$pid && $parcelNo !== '') {
            $res = api_call('GET', 'parcels.php?parcel_no=' . urlencode($parcelNo), null, $token);
            foreach (($res['data']['parcels'] ?? []) as $row) {
                if (($row['parcel_no'] ?? '') === $parcelNo) { $pid = (int)$row['id']; break; }
            }
        }
        if (!$pid) {
            $msg = t('Parcel not found.');
            $msgType = 'err';
        } else {
            $body = ['parcel_id' => $pid, 'fee_year' => $year, 'method' => $method, 'note' => $_POST['note'] ?? null];
            if (!empty($_POST['payer_owner_id'])) { $body['payer_owner_id'] = (int)$_POST['payer_owner_id']; }
            if ($amount !== null) { $body['amount'] = $amount; }
            if (in_array($method, ['bank_transfer', 'mobile'], true)) {
                // Walk-in bank/mobile payment: ISSUED → attach evidence → verify.
                if ($bankRef !== '') { $body['bank_ref'] = $bankRef; }
                $d = api_call('POST', 'payments.php', $body, $token);
                if (empty($d['ok'])) {
                    $msg = $d['error']['message'] ?? t('Settle failed.');
                    $msgType = 'err';
                } else {
                    $lastId = (int)$d['data']['id'];
                    $slipWarn = attach_counter_evidence($lastId, $slipUpload, $token);
                    $v = api_call('POST', 'payments.php?id=' . $lastId . '&verify=1', ['note' => $_POST['note'] ?? null], $token);
                    if (!empty($v['ok'])) {
                        $msg = t('Payment received and settled — receipt ready.') . $slipWarn;
                        $lastReceipt = 'payments.php?id=' . $lastId . '&receipt=1';
                    } else {
                        $msg = ($v['error']['message'] ?? t('Settle failed.'))
                            . ' — ' . t('Payment is recorded as awaiting proof — review it in the Bank tab.') . $slipWarn;
                        $msgType = 'err';
                    }
                }
            } else {
                if ($chequeNo !== '') { $body['cheque_no'] = $chequeNo; }
                $d = api_call('POST', 'payments.php?settle=1', $body, $token);
                if (!empty($d['ok'])) {
                    $lastId = (int)($d['data']['id'] ?? 0);
                    $msg = t('Payment received and settled — receipt ready.') . attach_counter_evidence($lastId, $slipUpload, $token);
                    $lastReceipt = $d['data']['receipt'] ?? '';
                } else {
                    $msg = $d['error']['message'] ?? t('Settle failed.');
                    $msgType = 'err';
                }
            }
        }
    } elseif ($id) {
        $d = api_call('POST', 'payments.php?id=' . $id . '&' . $action . '=1', $body, $token);
        if (!empty($d['ok'])) {
            $msg = ['verify' => t('Payment verified — obligation settled.'),
                    'reject' => t('Payment rejected.'),
                    'waive'  => t('Obligation waived.')][$action] ?? t('Done');
        } else {
            $msg = $d['error']['message'] ?? t('Action failed');
            $msgType = 'err';
        }
    }
}

/** Attach the officer's evidence photo (receipt / cheque / bank slip /
 *  mobile money message) to a just-created counter payment.
 *  Returns '' on success or no file, or a short warning sentence to append
 *  to the result message — an upload failure must not undo the settlement. */
function attach_counter_evidence(int $paymentId, ?array $file, string $token): string
{
    if ($paymentId <= 0 || empty($file)) { return ''; }
    $u = api_upload('payments.php?id=' . $paymentId . '&receipt=1',
        $file['tmp_name'], $file['name'], $file['type'] ?? 'application/octet-stream',
        $token, 'slip');
    if (!empty($u['ok'])) { return ''; }
    return ' ' . t('Evidence photo upload failed (payment was settled anyway).');
}

$years = api_call('GET', 'fee_years.php', null, $token)['data']['years'] ?? [];

// Counter entry can settle arrears, so offer every year back to 2016 (the first
// full year of the current land-tax decree) plus any configured fee years.
$counterYears = array_unique(array_merge(
    range((int)date('Y'), 2016),
    array_map(fn($y) => (int)$y['year'], $years)
));
rsort($counterYears);

// ---- Per-tab data ----------------------------------------------------------
$payments = []; $count = 0;
$countIssued = $countPaid = $countRej = 0;
$counterReceived = []; $counterReceivedTotal = 0.0;
$bankIssued = []; $awaitingProof = []; $matchedToday = [];

if ($tab === 'all' || $tab === 'counter') {
    $qs = 'status=' . urlencode($status);
    if ($methodF !== '') { $qs .= '&method=' . urlencode($methodF); }
    if ($fee_year) { $qs .= '&fee_year=' . $fee_year; }
    $d = api_call('GET', 'payments.php?' . $qs, null, $token);
    $payments = $d['data']['payments'] ?? [];
    $count = $d['data']['count'] ?? 0;
    $countIssued = api_call('GET', 'payments.php?status=ISSUED', null, $token)['data']['count'] ?? 0;
    $countPaid   = api_call('GET', 'payments.php?status=PAID', null, $token)['data']['count'] ?? 0;
    $countRej    = api_call('GET', 'payments.php?status=REJECTED', null, $token)['data']['count'] ?? 0;
}

if ($tab === 'counter') {
    // Counter receipt log: every PAID in-office receipt (all four methods),
    // newest first. The API caps each query at the 300 newest rows, so this
    // is the working history for re-printing receipts — the All payments tab
    // and Reports cover deeper history.
    foreach (['cash', 'cheque', 'bank_transfer', 'mobile'] as $m) {
        $d = api_call('GET', 'payments.php?status=PAID&method=' . $m, null, $token);
        foreach (($d['data']['payments'] ?? []) as $row) {
            $counterReceived[] = $row;
            $counterReceivedTotal += (float)$row['amount'];
        }
    }
    usort($counterReceived, fn($a, $b) => strcmp((string)($b['paid_at'] ?? ''), (string)($a['paid_at'] ?? '')));
}

if ($tab === 'bank') {
    // Review queue: issued bank/mobile payments (oldest first), split into
    // "has evidence" vs "awaiting proof"; plus today's auto-matched ones.
    // A selected tax year narrows the queue; paid_after keeps the matched
    // scan to today's rows instead of the full history.
    $yearQs = $fee_year ? '&fee_year=' . $fee_year : '';
    foreach (['bank_transfer', 'mobile'] as $m) {
        $d = api_call('GET', 'payments.php?status=ISSUED&method=' . $m . $yearQs, null, $token);
        foreach (($d['data']['payments'] ?? []) as $row) { $bankIssued[] = $row; }
        $d2 = api_call('GET', 'payments.php?status=PAID&method=' . $m . '&paid_after=' . date('Y-m-d'), null, $token);
        foreach (($d2['data']['payments'] ?? []) as $row) {
            if (($row['verify_method'] ?? '') === 'auto_statement') { $matchedToday[] = $row; }
        }
    }
    usort($bankIssued, fn($a, $b) => strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? '')));
    $awaitingProof = array_values(array_filter($bankIssued, function ($p) {
        return empty($p['slip_path']) && empty($p['bank_ref']);
    }));
    // Paginate the queue (50/page, oldest first) so a big backlog stays workable.
    $bankPerPage = 50;
    $bankTotalPages = max(1, (int)ceil(count($bankIssued) / $bankPerPage));
    $bankPage = min(max(1, (int)($_GET['page'] ?? 1)), $bankTotalPages);
    $bankQueue = array_slice($bankIssued, ($bankPage - 1) * $bankPerPage, $bankPerPage);
}

/** One payment card, shared by the bank review tab and the All ledger. */
function render_payment_card(array $p, string $base): void
{
    ?>
      <div class="form-box" style="padding:14px; border-left:4px solid #1b5e20;">
        <div style="display:grid; grid-template-columns: 1fr auto; gap:8px 16px; align-items:start;">
          <div>
            <b><?= e($p['reference_no']) ?></b> — <?= te('Parcel') ?>
            <a href="<?= $base ?>/parcel.php?id=<?= (int)$p['parcel_id'] ?>"><?= e($p['parcel_no']) ?></a>
            <p class="sub">
              <?= te('Year') ?> <?= e($p['fee_year']) ?> &nbsp;|&nbsp;
              <?= e($p['district_name']) ?> / <?= e($p['village_name']) ?> &nbsp;|&nbsp;
              <?= te('Area') ?> <?= number_format((float)$p['area_sqm'], 2) ?> m2
            </p>
            <p><b><?= te('Amount') ?>:</b> <?= number_format((float)$p['amount'], 0) ?> <?= te('kip') ?>
               &nbsp;<b><?= te('Method') ?>:</b> <?= e(payment_method_label((string)$p['method'])) ?>
               <?php if ($p['payer_name']): ?> &nbsp;<b><?= te('Payer') ?>:</b> <?= e($p['payer_name']) ?><?php endif; ?>
            </p>
            <?php if ($p['obligation_status']): ?>
              <p class="sub"><?= te('Obligation') ?>: <span class="pill pill-<?= e(strtolower($p['obligation_status'])) ?>"><?= e(t($p['obligation_status'])) ?></span></p>
            <?php endif; ?>
            <?php if (!empty($p['verify_method'])): ?>
              <p class="sub"><?= te('Verified via {method}', ['method' => e($p['verify_method'])]) ?><?= $p['paid_at'] ? te(' on {date}', ['date' => e($p['paid_at'])]) : '' ?></p>
            <?php endif; ?>
            <?php if (!empty($p['bank_ref'])): ?>
              <p class="sub"><?= te('Bank ref') ?>: <?= e($p['bank_ref']) ?></p>
            <?php endif; ?>
            <?php if (!empty($p['slip_path'])): ?>
              <p><b><?= te('Slip photo') ?>:</b><br><img src="<?= e($p['slip_path']) ?>" class="slip-zoom" style="max-width:220px; border-radius:4px; background:#fff; cursor:zoom-in;" alt="<?= te('Slip photo') ?>" onerror="this.style.display='none'; this.insertAdjacentHTML('afterend', '<span style=color:#c62828;font-size:13px;>Photo file not found</span>');"></p>
            <?php endif; ?>
            <p><?php if ($p['status'] === 'PAID'): ?>
              <a href="<?= $base ?>/slip_print.php?id=<?= (int)$p['id'] ?>&receipt=1" target="_blank" class="btn btn-green btn-sm"><?= te('Print receipt') ?></a>
            <?php else: ?>
              <a href="<?= $base ?>/slip_print.php?id=<?= (int)$p['id'] ?>&slip=1" target="_blank" class="btn btn-blue btn-sm"><?= te('Print slip') ?></a>
            <?php endif; ?></p>
          </div>
          <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <span class="pill pill-<?= e(strtolower($p['status'])) ?>"><?= e(t($p['status'])) ?></span>
            <?php if ($p['status'] === 'ISSUED' && in_array($p['method'], ['bank_transfer', 'mobile'], true) && empty($p['slip_path']) && empty($p['bank_ref'])): ?>
              <span class="pill pill-warn" style="background:#fdecea; border:1px solid #c62828; color:#b71c1c;"><?= te('Awaiting proof') ?></span>
            <?php endif; ?>
            <?php if ($p['status'] === 'ISSUED'): ?>
              <form method="post" style="display:inline-flex; flex-wrap:wrap; gap:6px; align-items:flex-end;">
                <input type="hidden" name="do_action" value="verify">
                <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                <label style="display:flex; flex-direction:column; gap:2px; font-size:11px; color:#666;">
                  <?= te('Amount received') ?>
                  <input name="amount" type="number" step="any" min="0" value="<?= number_format((float)$p['amount'], 2, '.', '') ?>" style="width:120px;">
                </label>
                <label style="display:flex; flex-direction:column; gap:2px; font-size:11px; color:#666;">
                  <?= te('Note') ?>
                  <input name="note" style="width:120px;">
                </label>
                <button class="btn btn-green btn-sm" type="submit"><?= te('Verify') ?></button>
              </form>
              <form method="post" style="display:inline-flex; flex-wrap:wrap; gap:6px; align-items:flex-end;">
                <input type="hidden" name="do_action" value="reject">
                <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                <label style="display:flex; flex-direction:column; gap:2px; font-size:11px; color:#666;">
                  <?= te('Reason') ?>
                  <input name="reason" placeholder="<?= te('optional') ?>" style="width:140px;">
                </label>
                <button class="btn btn-red btn-sm" type="submit"><?= te('Reject') ?></button>
              </form>
            <?php endif; ?>
            <?php if ($p['status'] === 'PAID' && !empty($p['obligation_id'])): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="do_action" value="waive">
                <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                <input type="hidden" name="reason" value="<?= te('Waived by officer') ?>">
                <button class="btn btn-gold btn-sm" type="submit"><?= te('Waive') ?></button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="<?= i18n_current() ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= te('Verify Payments') ?> — Land Fee MVP</title>
<?php require __DIR__ . '/style.php'; ?>
<style>
  .pay-tabs { display:flex; gap:8px; margin:14px 0 18px; flex-wrap:wrap; }
  .pay-tabs a { padding:9px 18px; border:1px solid #ccc; border-radius:4px; background:#fff; color:#333; text-decoration:none; font-weight:600; }
  .pay-tabs a.active { background:#4a7c59; color:#fff; border-color:#4a7c59; }
  .bank-strip { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:16px; }
</style>
</head>
<body>
<div class="app">
  <aside class="side">
    <?= admin_side_top() ?>
    <?= admin_nav($role) ?>
    <?= admin_side_foot() ?>
  </aside>
  <main class="main">
    <h1><?= te('Payments') ?></h1>
    <p class="sub"><?= te('Counter records every in-office payment (cash, cheque, bank, mobile). Slips issued by the citizen mobile app are reviewed on the Mobile app tab.') ?></p>

    <div class="pay-tabs">
      <a href="?tab=counter" class="<?= $tab === 'counter' ? 'active' : '' ?>"><?= te('Office counter') ?></a>
      <a href="?tab=bank" class="<?= $tab === 'bank' ? 'active' : '' ?>"><?= te('Mobile app') ?></a>
      <a href="?tab=all" class="<?= $tab === 'all' ? 'active' : '' ?>"><?= te('All payments') ?></a>
    </div>

    <?php if ($msg): ?>
      <p class="flash <?= $msgType === 'err' ? 'err' : 'ok' ?>"><?= e($msg) ?>
        <?php if ($lastReceipt): ?> <a href="<?= $base ?>/slip_print.php?id=<?= (int)($lastId ?? 0) ?>&receipt=1" target="_blank" class="btn btn-green btn-sm"><?= te('Print receipt') ?></a><?php endif; ?>
      </p>
    <?php endif; ?>

    <?php if ($tab === 'counter'): ?>
      <!-- ================= OFFICE COUNTER ================= -->
      <div class="form-box" style="border-left:4px solid #b8860b;">
        <h2><?= te('Record counter payment') ?></h2>
        <p class="sub"><?= te('Cash, cheque, bank or mobile payment collected at the district office — settles the obligation immediately and prints the receipt.') ?></p>
        <h3 style="margin:14px 0 4px;"><?= te('Step 1 — verify the obligation') ?></h3>
        <p class="sub"><?= te('Enter any one field, or combine them to narrow the search.') ?></p>
        <div class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px;">
          <div class="form-group"><label><?= te('Parcel no') ?></label><input id="counter-parcel"></div>
          <div class="form-group"><label><?= te('Owner name') ?></label><input id="counter-owner-name"></div>
          <div class="form-group"><label><?= te('Telephone') ?></label><input id="counter-phone" inputmode="tel" placeholder="020 555 1234"></div>
          <div class="form-group"><label><?= te('Tax year') ?></label>
            <select id="counter-year">
              <?php foreach ($counterYears as $y): ?>
              <option value="<?= (int)$y ?>" <?= (int)$y === (int)date('Y') ? 'selected' : '' ?>><?= (int)$y ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label><?= te('Method') ?></label>
            <select id="counter-method">
              <option value="cash"><?= e(payment_method_label('cash')) ?></option>
              <option value="cheque"><?= e(payment_method_label('cheque')) ?></option>
              <option value="bank_transfer"><?= e(payment_method_label('bank_transfer')) ?></option>
              <option value="mobile"><?= e(payment_method_label('mobile')) ?></option>
            </select>
          </div>
          <div class="form-group"><label>&nbsp;</label><button id="counter-verify" class="btn btn-gold" type="button"><?= te('Search parcel') ?></button></div>
        </div>
        <div id="counter-verify-result" style="display:none; margin-top:10px; padding:10px 12px; border-radius:6px; font-size:14px;"></div>
        <div id="counter-matches" style="display:none; margin-top:10px;"></div>

        <div id="counter-record-box" style="display:none; margin-top:14px; padding-top:12px; border-top:1px dashed #c9b458;">
          <h3 style="margin:0 0 2px;"><?= te('Step 2 — record & settle') ?></h3>
          <p class="sub" id="counter-record-note"></p>
          <form method="post" enctype="multipart/form-data" class="form-grid" id="counter-settle-form" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px;">
            <input type="hidden" name="do_action" value="settle">
            <input type="hidden" name="parcel_id">
            <input type="hidden" name="parcel_no">
            <input type="hidden" name="fee_year">
            <input type="hidden" name="method">
            <div class="form-group"><label><?= te('Owner') ?></label>
              <select name="payer_owner_id" id="counter-owner">
                <option value=""><?= te('-- select owner (payer) --') ?></option>
              </select>
            </div>
            <div class="form-group"><label><?= te('Amount received') ?> (<?= te('optional') ?>)</label><input name="amount" type="number" step="any" min="0" placeholder="<?= te('full balance') ?>"></div>
            <div class="form-group" id="counter-cheque-group" style="display:none;"><label><?= te('Cheque no') ?></label><input name="cheque_no"></div>
            <div class="form-group" id="counter-bankref-group" style="display:none;"><label><?= te('Bank ref') ?></label><input name="bank_ref" placeholder="<?= te('from the slip') ?>"></div>
            <div class="form-group"><label><span id="counter-evidence-label"><?= te('Receipt photo') ?></span> <span class="sub"><?= te('optional') ?></span></label>
              <input type="file" name="slip" accept="image/jpeg,image/png,image/webp,application/pdf">
            </div>
            <div class="form-group"><label><?= te('Note') ?> (<?= te('optional') ?>)</label><input name="note"></div>
            <div class="form-group"><label>&nbsp;</label><button class="btn btn-gold" type="submit"><?= te('Record & settle') ?></button></div>
          </form>
        </div>
      </div>

      <div class="form-box">
        <h2><?= te('Received') ?></h2>
        <p class="sub"><?= te('{n} payment(s), {amount} kip.', ['n' => count($counterReceived), 'amount' => number_format($counterReceivedTotal, 0)]) ?></p>
        <?php if (!$counterReceived): ?>
          <p class="empty"><?= te('No counter payments recorded yet.') ?></p>
        <?php else: ?>
        <table>
          <thead><tr><th><?= te('Reference') ?></th><th><?= te('Parcel') ?></th><th><?= te('Owner') ?></th><th><?= te('Method') ?></th><th><?= te('Amount') ?></th><th><?= te('Date & time') ?></th><th></th></tr></thead>
          <tbody>
            <?php foreach ($counterReceived as $p): ?>
            <tr>
              <td><?= e($p['reference_no']) ?></td>
              <td><a href="<?= $base ?>/parcel.php?id=<?= (int)$p['parcel_id'] ?>"><?= e($p['parcel_no']) ?></a></td>
              <td><?= e($p['payer_name'] ?: '—') ?></td>
              <td><?= e(payment_method_label((string)$p['method'])) ?><?= $p['bank_ref'] ? ' (' . e($p['bank_ref']) . ')' : '' ?></td>
              <td><?= number_format((float)$p['amount'], 0) ?></td>
              <td><?= $p['paid_at'] ? e(date('d M Y H:i', strtotime((string)$p['paid_at']))) : '—' ?></td>
              <td><a href="<?= $base ?>/slip_print.php?id=<?= (int)$p['id'] ?>&receipt=1" target="_blank" class="btn btn-green btn-sm"><?= te('Receipt') ?></a></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

    <?php elseif ($tab === 'bank'): ?>
      <!-- ================= MOBILE APP PROOFS ================= -->
      <p class="sub" style="margin:0 0 10px;"><?= te('Slips issued by the citizen mobile app + payments auto-matched from bank statements. A citizen who brings a paper slip into the office is recorded in the Office counter tab — it will not appear here.') ?></p>
      <div class="bank-strip">
        <div class="card"><h3><?= te('To review') ?></h3><p class="num"><?= count($bankIssued) ?></p></div>
        <div class="card"><h3><?= te('Awaiting proof') ?></h3><p class="num"><?= count($awaitingProof) ?></p></div>
        <div class="card"><h3><?= te('Matched automatically today') ?></h3><p class="num"><?= count($matchedToday) ?></p></div>
      </div>

      <div class="form-box">
        <form method="get" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px;">
          <input type="hidden" name="tab" value="bank">
          <div class="form-group"><label><?= te('Tax year') ?></label>
            <select name="fee_year"><option value=""><?= te('-- all --') ?></option>
              <?php foreach ($years as $y): ?>
              <option value="<?= e($y['year']) ?>" <?= $fee_year == $y['year'] ? 'selected' : '' ?>><?= e($y['year']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label>&nbsp;</label><button class="btn btn-green" type="submit"><?= te('Filter') ?></button></div>
        </form>
      </div>

      <?php if ($awaitingProof): ?>
        <div class="form-box" style="border-left:4px solid #c62828; background:#fdf3f2;">
          <h2><?= te('Awaiting proof — contact these citizens') ?></h2>
          <ul style="margin:6px 0 0 18px;">
            <?php foreach ($awaitingProof as $p): ?>
            <li><b><?= e($p['reference_no']) ?></b> — <?= e($p['parcel_no']) ?> (<?= e($p['payer_name'] ?: t('no owner recorded')) ?>), <?= te('issued') ?> <?= e(substr((string)$p['created_at'], 0, 10)) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if (!$bankIssued): ?>
        <p class="empty"><?= te('No bank or mobile payments waiting for review.') ?></p>
      <?php else: ?>
        <p class="sub" style="margin:4px 0 8px;">
          <?= te('Page {page} of {pages} — {n} record(s)', ['page' => $bankPage, 'pages' => $bankTotalPages, 'n' => count($bankQueue)]) ?>
          <?php if ($bankPage > 1): ?><a class="btn btn-blue btn-sm" href="?tab=bank&fee_year=<?= (int)$fee_year ?>&page=<?= $bankPage - 1 ?>"><?= te('Previous') ?></a><?php endif; ?>
          <?php if ($bankPage < $bankTotalPages): ?><a class="btn btn-blue btn-sm" href="?tab=bank&fee_year=<?= (int)$fee_year ?>&page=<?= $bankPage + 1 ?>"><?= te('Next') ?></a><?php endif; ?>
        </p>
        <?php foreach ($bankQueue as $p): ?>
        <div id="card-<?= (int)$p['id'] ?>" class="form-box" style="border-left:4px solid #e6951a; margin-bottom:12px;">
          <div style="display:grid; grid-template-columns:1fr auto; gap:12px 20px; align-items:start;">
            <div>
              <b style="font-size:16px;"><?= e($p['reference_no']) ?></b>
              <span class="pill pill-<?= e(strtolower($p['status'])) ?>" style="margin-left:8px;"><?= e(t($p['status'])) ?></span>
              <?php if ($p['status'] === 'ISSUED' && empty($p['slip_path']) && empty($p['bank_ref'])): ?>
                <span class="pill pill-warn awaiting-badge" style="background:#fdecea; border:1px solid #c62828; color:#b71c1c; margin-left:4px;"><?= te('Awaiting proof') ?></span>
              <?php endif; ?>
              <p class="sub" style="margin:4px 0 0;">
                <?= te('Parcel') ?>
                <a href="<?= $base ?>/parcel.php?id=<?= (int)$p['parcel_id'] ?>"><?= e($p['parcel_no']) ?></a>
                &nbsp;|&nbsp; <?= te('Year') ?> <?= e($p['fee_year']) ?>
                &nbsp;|&nbsp; <?= e($p['district_name']) ?> / <?= e($p['village_name']) ?>
                &nbsp;|&nbsp; <?= te('Area') ?> <?= number_format((float)$p['area_sqm'], 2) ?> m²
              </p>
              <p style="margin:4px 0;">
                <b><?= te('Amount') ?>:</b> <?= number_format((float)$p['amount'], 0) ?> <?= te('kip') ?>
                &nbsp;<b><?= te('Method') ?>:</b> <?= e(payment_method_label((string)$p['method'])) ?>
                <?php if ($p['payer_name']): ?> &nbsp;<b><?= te('Payer') ?>:</b> <?= e($p['payer_name']) ?><?php endif; ?>
                <?php if (!empty($p['bank_ref'])): ?> &nbsp;<b><?= te('Bank ref') ?>:</b> <?= e($p['bank_ref']) ?><?php endif; ?>
              </p>
              <?php if (!empty($p['slip_path'])): ?>
                <p style="margin:4px 0;"><b><?= te('Slip photo') ?>:</b><br>
                  <img src="<?= e($p['slip_path']) ?>" class="slip-zoom" style="max-width:200px; border-radius:4px; background:#fff; cursor:zoom-in;" alt="<?= te('Slip photo') ?>" onerror="this.style.display='none'; this.insertAdjacentHTML('afterend', '<span style=color:#c62828;font-size:13px;>Photo file not found</span>');">
                </p>
              <?php endif; ?>
              <?php if (!empty($p['verify_method'])): ?>
                <p class="sub"><?= te('Verified via {method}', ['method' => e($p['verify_method'])]) ?><?php if ($p['paid_at']): ?> <?= te('on {date}', ['date' => e($p['paid_at'])]) ?><?php endif; ?></p>
              <?php endif; ?>
            </div>
            <div style="display:flex; flex-direction:column; gap:8px; align-self:start;">
              <?php if ($p['status'] === 'ISSUED' && empty($p['slip_path'])): ?>
                <button class="js-upload-slip btn" type="button" data-payment-id="<?= (int)$p['id'] ?>"
                  style="border-color:#1565c0; color:#0d47a1; min-width:150px;">
                  <?= te('Upload slip') ?>
                </button>
              <?php endif; ?>
              <?php if ($p['status'] === 'ISSUED'): ?>
                <button class="js-modal btn btn-green" type="button" data-form="verify-modal"
                  data-title="<?= te('Verify payment') ?>" data-submit="<?= te('Confirm & verify') ?>"
                  data-payment-id="<?= (int)$p['id'] ?>" data-amount="<?= number_format((float)$p['amount'], 2, '.', '') ?>">
                  <?= te('Verify') ?>
                </button>
                <button class="js-modal btn btn-red" type="button" data-form="reject-modal"
                  data-title="<?= te('Reject payment') ?>" data-submit="<?= te('Reject') ?>"
                  data-payment-id="<?= (int)$p['id'] ?>">
                  <?= te('Reject') ?>
                </button>
              <?php else: ?>
                <span class="pill pill-<?= e(strtolower($p['status'])) ?>"><?= e(t($p['status'])) ?></span>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <?php if ($matchedToday): ?>
        <div class="form-box">
          <h2><?= te('Matched automatically today') ?></h2>
          <p class="sub"><?= te('Paid by bank statement match — nothing to do.') ?></p>
          <table>
            <thead><tr><th><?= te('Reference') ?></th><th><?= te('Parcel') ?></th><th><?= te('Amount') ?></th><th><?= te('Time') ?></th><th></th></tr></thead>
            <tbody>
              <?php foreach ($matchedToday as $p): ?>
              <tr>
                <td><?= e($p['reference_no']) ?></td>
                <td><?= e($p['parcel_no']) ?></td>
                <td><?= number_format((float)$p['amount'], 0) ?></td>
                <td><?= e(substr((string)$p['paid_at'], 11, 5)) ?></td>
                <td><a href="<?= $base ?>/slip_print.php?id=<?= (int)$p['id'] ?>&receipt=1" target="_blank" class="btn btn-green btn-sm"><?= te('Receipt') ?></a></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

    <?php else: ?>
      <!-- ================= ALL PAYMENTS ================= -->
      <div class="cards">
        <div class="card"><h3><?= te('Issued (to verify)') ?></h3><p class="num"><?= (int)$countIssued ?></p></div>
        <div class="card"><h3><?= te('Paid') ?></h3><p class="num"><?= (int)$countPaid ?></p></div>
        <div class="card"><h3><?= te('Rejected') ?></h3><p class="num"><?= (int)$countRej ?></p></div>
      </div>

      <div class="form-box">
        <form method="get" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px;">
          <input type="hidden" name="tab" value="all">
          <div class="form-group"><label><?= te('Status') ?></label>
            <select name="status">
              <?php foreach (['ISSUED','PAID','REJECTED','REFUNDED'] as $s): ?>
              <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(t($s)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label><?= te('Method') ?></label>
            <select name="method">
              <option value=""><?= te('-- all --') ?></option>
              <?php foreach (['cash','bank_transfer','mobile','cheque','counter'] as $m): ?>
              <option value="<?= $m ?>" <?= $methodF === $m ? 'selected' : '' ?>><?= e(payment_method_label($m)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label><?= te('Tax year') ?></label>
            <select name="fee_year"><option value=""><?= te('-- all --') ?></option>
              <?php foreach ($years as $y): ?>
              <option value="<?= e($y['year']) ?>" <?= $fee_year == $y['year'] ? 'selected' : '' ?>><?= e($y['year']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label>&nbsp;</label><button class="btn btn-green" type="submit"><?= te('Filter') ?></button></div>
        </form>
      </div>

      <?php if (!$payments): ?>
        <p class="empty"><?= te('No payments in status {status}.', ['status' => t($status)]) ?></p>
      <?php else: ?>
        <?php foreach ($payments as $p): render_payment_card($p, $base); endforeach; ?>
      <?php endif; ?>
    <?php endif; ?>
  </main>
</div>
<div id="slip-lightbox" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.8); z-index:999; align-items:center; justify-content:center; cursor:zoom-out;" onclick="this.style.display='none'">
  <div id="slip-lightbox-msg" style="display:none; color:#fff; font-size:18px; text-align:center; padding:20px;"></div>
  <img src="" alt="" style="max-width:92vw; max-height:92vh; background:#fff; border-radius:6px;" onerror="this.style.display='none'; var m=document.getElementById('slip-lightbox-msg'); m.style.display='block'; m.textContent='Image not found: ' + this.src;">
</div>
<script>
// API endpoint + session token for the owner lookup (the API reads the header only).
var API_BASE = <?= json_encode($api_base) ?>;
var ADMIN_TOKEN = <?= json_encode($_SESSION['admin_token'] ?? '') ?>;
(function(){
  // Slip photo click-to-zoom.
  var lb = document.getElementById('slip-lightbox');
  document.addEventListener('click', function(e) {
    if (e.target && e.target.classList && e.target.classList.contains('slip-zoom')) {
      lb.style.display = 'flex';
      var lbImg = lb.querySelector('img');
      var lbMsg = document.getElementById('slip-lightbox-msg');
      lbImg.style.display = 'block';
      lbMsg.style.display = 'none';
      lbImg.src = e.target.src;
    }
  });
  // Two-step counter flow:
  //   1. officer types the parcel no, picks tax year + method, and clicks
  //      "Verify obligation" — the obligation for that year is shown in a
  //      dedicated result panel (nothing is collected on a guess).
  //   2. only when there is something to collect, the record & settle box
  //      appears with the expected amount pre-filled; the officer then
  //      records the payment (amount optional = full balance).
  var MSG = {
    loading:  <?= json_encode(t('-- loading --')) ?>,
    notfound: <?= json_encode(t('Parcel not found.')) ?>,
    noMatch:  <?= json_encode(t('No parcels match the filter.')) ?>,
    ownerPh:  <?= json_encode(t('-- select owner (payer) --')) ?>,
    assessed: <?= json_encode(t('Obligation for {year} ({status}) — due {due} kip, paid {paid} kip, balance {out} kip')) ?>,
    settled:  <?= json_encode(t('Obligation already settled — nothing to collect')) ?>,
    fresh:    <?= json_encode(t('No obligation for {year} yet — settles at {amt} kip (zone {zone}, {rate} kip/m²)')) ?>,
    norate:   <?= json_encode(t('No zone rate set for this parcel — assign a valuation zone first')) ?>,
    expect:   <?= json_encode(t('Obligation verified — expected {amt} kip.')) ?>,
    needCrit: <?= json_encode(t('Enter a parcel number, owner name or telephone.')) ?>,
    badPhone: <?= json_encode(t('Telephone must contain at least 8 digits.')) ?>,
    searchFailed: <?= json_encode(t('Search failed.')) ?>,
    matches:  <?= json_encode(t('{n} parcel(s) matched — select one, or refine your search.')) ?>,
    showing:  <?= json_encode(t('Showing {shown} of {total} — refine your search to narrow it down.')) ?>,
    select:   <?= json_encode(t('Select')) ?>,
    colParcel:<?= json_encode(t('Parcel')) ?>,
    colOwner: <?= json_encode(t('Owner')) ?>,
    colVillage:<?= json_encode(t('Village')) ?>,
    colArea:  <?= json_encode(t('Area')) ?>,
    status: { <?php foreach (['UNPAID', 'PARTIAL', 'PAID', 'OVERPAID', 'WAIVED'] as $s) { echo json_encode($s) . ': ' . json_encode(t($s)) . ', '; } ?> }
  };
  var parcelInput = document.getElementById('counter-parcel');
  var yearSel = document.getElementById('counter-year');
  var methodSel = document.getElementById('counter-method');
  var verifyBtn = document.getElementById('counter-verify');
  var verifyRes = document.getElementById('counter-verify-result');
  var recordBox = document.getElementById('counter-record-box');
  var recordNote = document.getElementById('counter-record-note');
  var settleForm = document.getElementById('counter-settle-form');
  var ownerSel = document.getElementById('counter-owner');
  var ownerNameInput = document.getElementById('counter-owner-name');
  var phoneInput = document.getElementById('counter-phone');
  var matchesBox = document.getElementById('counter-matches');
  var parcelIdInput = settleForm ? settleForm.querySelector('input[name=parcel_id]') : null;
  var amountInput = settleForm ? settleForm.querySelector('input[name=amount]') : null;
  var chequeGroup = document.getElementById('counter-cheque-group');
  var bankRefGroup = document.getElementById('counter-bankref-group');
  var evidenceLabel = document.getElementById('counter-evidence-label');
  var slipInput = settleForm ? settleForm.querySelector('input[name=slip]') : null;
  var bankRefInput = settleForm ? settleForm.querySelector('input[name=bank_ref]') : null;
  // What the officer photographs depends on the method.
  var EVIDENCE_LABEL = {
    cash: <?= json_encode(t('Receipt photo')) ?>,
    cheque: <?= json_encode(t('Photo of cheque')) ?>,
    bank_transfer: <?= json_encode(t('Photo of bank slip')) ?>,
    mobile: <?= json_encode(t('Photo of mobile money message')) ?>
  };

  function fillText(tpl, vars) {
    return tpl.replace(/\{(\w+)\}/g, function (_, k) {
      return (vars[k] === undefined || vars[k] === null) ? '' : vars[k];
    });
  }
  function fmtKip(n) { return Number(n || 0).toLocaleString('en-US'); }
  function esc(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
  function showVerify(html, cls) {
    verifyRes.style.display = 'block';
    verifyRes.style.background = cls === 'ok' ? '#e8f5e9' : (cls === 'err' ? '#fdecea' : '#f6f2e7');
    verifyRes.innerHTML = html;
  }
  function resetRecord() {
    recordBox.style.display = 'none';
    recordNote.textContent = '';
    ownerSel.innerHTML = '<option value="">' + esc(MSG.ownerPh) + '</option>';
    if (amountInput) { amountInput.value = ''; }
    if (slipInput) { slipInput.value = ''; }
    if (bankRefInput) { bankRefInput.value = ''; }
  }
  function syncEvidence() {
    var m = methodSel ? methodSel.value : 'cash';
    if (chequeGroup) { chequeGroup.style.display = m === 'cheque' ? '' : 'none'; }
    if (bankRefGroup) { bankRefGroup.style.display = (m === 'bank_transfer' || m === 'mobile') ? '' : 'none'; }
    if (evidenceLabel) { evidenceLabel.textContent = EVIDENCE_LABEL[m] || EVIDENCE_LABEL.cash; }
  }

  if (methodSel) {
    methodSel.onchange = function() {
      syncEvidence();
      if (settleForm && settleForm.style.display !== 'none') {
        settleForm.querySelector('input[name=method]').value = methodSel.value;
      }
    };
    syncEvidence();
  }
  // Changing any search field after a result invalidates it — search again.
  function resetSearch() {
    resetRecord();
    verifyRes.style.display = 'none';
    verifyRes.innerHTML = '';
    if (matchesBox) { matchesBox.style.display = 'none'; matchesBox.innerHTML = ''; }
    if (parcelIdInput) { parcelIdInput.value = ''; }
  }
  var searchInputs = [parcelInput, ownerNameInput, phoneInput];
  for (var si = 0; si < searchInputs.length; si++) {
    if (searchInputs[si]) { searchInputs[si].onchange = resetSearch; }
  }
  if (yearSel) { yearSel.onchange = resetSearch; }

  function digitsOf(s) { return String(s == null ? '' : s).replace(/\D+/g, ''); }

  // Mirrors the server rule: name contains the text, phone compares last 8 digits.
  function ownerMatches(o) {
    var nq = ownerNameInput ? ownerNameInput.value.trim().toLowerCase() : '';
    var pt = phoneInput ? digitsOf(phoneInput.value).slice(-8) : '';
    var ok = true;
    if (nq) { ok = ok && String(o.full_name || '').toLowerCase().indexOf(nq) >= 0; }
    if (pt.length === 8) { ok = ok && digitsOf(o.phone).slice(-8) === pt; }
    return ok;
  }

  // Price one parcel and open step 2 — same rules however it was selected.
  function openFee(parcelId, parcelNo) {
    var yr = yearSel.value;
    resetRecord();
    if (parcelIdInput) { parcelIdInput.value = parcelId; }
    showVerify('<b>' + esc(MSG.loading) + '</b>', 'warn');
    fetch(API_BASE + '/parcel_fee.php?parcel_id=' + encodeURIComponent(parcelId) + '&fee_year=' + encodeURIComponent(yr),
          { headers: { 'Authorization': 'Bearer ' + ADMIN_TOKEN } })
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (!d || !d.ok) { showVerify(esc(MSG.notfound), 'err'); return; }
        var data = d.data;
        var p = data.parcel || {};
        var ob = data.obligation;
        var payable = 0;
        if (ob) {
          var out = Number(ob.outstanding) || 0;
          if (out > 0) {
            showVerify('<b>' + esc(fillText(MSG.assessed, {
              year: yr,
              status: MSG.status[ob.status] || ob.status,
              due: fmtKip(ob.amount_due),
              paid: fmtKip(ob.amount_paid),
              out: fmtKip(out)
            })) + '</b>', 'warn');
            payable = out;
          } else {
            showVerify('<b>' + esc(MSG.settled) + '</b>', 'ok');
            return;
          }
        } else if (data.rate_found) {
          showVerify('<b>' + esc(fillText(MSG.fresh, {
            year: yr,
            amt: fmtKip(data.amount_due),
            zone: p.zone_code || '?',
            rate: fmtKip(data.rate_per_sqm)
          })) + '</b>', 'warn');
          payable = Number(data.amount_due) || 0;
        } else {
          showVerify('<b>' + esc(MSG.norate) + '</b>', 'err');
          return;
        }
        // Something to collect — open step 2, pre-filled with the expected amount.
        settleForm.querySelector('input[name=parcel_no]').value = parcelNo || '';
        settleForm.querySelector('input[name=fee_year]').value = yr;
        settleForm.querySelector('input[name=method]').value = methodSel.value;
        var owners = data.owners || [];
        ownerSel.innerHTML = '<option value="">' + esc(MSG.ownerPh) + '</option>';
        var preselect = '';
        for (var i = 0; i < owners.length; i++) {
          ownerSel.innerHTML += '<option value="' + owners[i].id + '">' + esc(owners[i].full_name) + '</option>';
          if (!preselect && ownerMatches(owners[i])) { preselect = String(owners[i].id); }
        }
        if (preselect) { ownerSel.value = preselect; }
        if (amountInput && payable > 0) { amountInput.value = payable; }
        recordNote.textContent = payable > 0 ? fillText(MSG.expect, { amt: fmtKip(payable) }) : '';
        syncEvidence();
        recordBox.style.display = 'block';
      })
      .catch(function() { showVerify(esc(MSG.notfound), 'err'); });
  }

  // Result list when the search matches more than one parcel.
  function renderMatches(rows, total) {
    var h = '<table><thead><tr><th>' + esc(MSG.colParcel) + '</th><th>' + esc(MSG.colOwner)
      + '</th><th>' + esc(MSG.colVillage) + '</th><th>' + esc(MSG.colArea) + '</th><th></th></tr></thead><tbody>';
    for (var i = 0; i < rows.length; i++) {
      var r = rows[i];
      var names = [];
      for (var j = 0; j < (r.owners || []).length; j++) { names.push(esc(r.owners[j].full_name)); }
      if (!names.length) { names.push(esc(r.owners_names || '')); }
      var label = r.parcel_no + (r.sub_parcel_no ? ' / ' + r.sub_parcel_no : '');
      h += '<tr><td>' + esc(label) + '</td><td>' + names.join(', ') + '</td><td>'
        + esc((r.village_name || '') + ' · ' + (r.district_name || '')) + '</td><td>' + esc(r.area_sqm) + '</td>'
        + '<td><button type="button" class="btn btn-gold btn-sm" data-pid="' + Number(r.id)
        + '" data-pno="' + esc(r.parcel_no) + '">' + esc(MSG.select) + '</button></td></tr>';
    }
    h += '</tbody></table>';
    if (total > rows.length) {
      h += '<p class="sub">' + esc(fillText(MSG.showing, { shown: rows.length, total: total })) + '</p>';
    }
    matchesBox.innerHTML = h;
    matchesBox.style.display = 'block';
    var btns = matchesBox.querySelectorAll('button[data-pid]');
    for (var k = 0; k < btns.length; k++) {
      btns[k].onclick = function() {
        matchesBox.style.display = 'none';
        openFee(this.getAttribute('data-pid'), this.getAttribute('data-pno'));
      };
    }
  }

  if (verifyBtn) {
    verifyBtn.onclick = function() {
      var no = parcelInput.value.trim();
      var name = ownerNameInput ? ownerNameInput.value.trim() : '';
      var phone = phoneInput ? phoneInput.value.trim() : '';
      if (!no && !name && !phone) {
        showVerify(esc(MSG.needCrit), 'err');
        parcelInput.focus();
        return;
      }
      if (phone && digitsOf(phone).length < 8) {
        showVerify(esc(MSG.badPhone), 'err');
        phoneInput.focus();
        return;
      }
      resetSearch();
      showVerify('<b>' + esc(MSG.loading) + '</b>', 'warn');
      var qs = 'limit=15&with_owners=1';
      if (no)    { qs += '&parcel_no=' + encodeURIComponent(no); }
      if (name)  { qs += '&owner_name=' + encodeURIComponent(name); }
      if (phone) { qs += '&phone=' + encodeURIComponent(phone); }
      fetch(API_BASE + '/parcels.php?' + qs, { headers: { 'Authorization': 'Bearer ' + ADMIN_TOKEN } })
        .then(function(r) { return r.json(); })
        .then(function(d) {
          if (!d || !d.ok) { showVerify(esc(MSG.searchFailed), 'err'); return; }
          var rows = (d.data && d.data.parcels) || [];
          var total = (d.data && d.data.total) || rows.length;
          if (!rows.length) { showVerify(esc(MSG.noMatch), 'err'); return; }
          if (rows.length === 1) {
            verifyRes.style.display = 'none';
            openFee(rows[0].id, rows[0].parcel_no);
            return;
          }
          showVerify('<b>' + esc(fillText(MSG.matches, { n: total })) + '</b>', 'warn');
          renderMatches(rows, total);
        })
        .catch(function() { showVerify(esc(MSG.searchFailed), 'err'); });
    };
  }
})();
</script>

<!-- ==================== BANK TAB MODALS ==================== -->
<template id="verify-modal">
  <form class="form-grid" style="grid-template-columns:1fr;">
    <input type="hidden" name="do_action" value="verify">
    <input type="hidden" name="payment_id">
    <div class="form-group">
      <label><?= te('Amount received') ?></label>
      <input name="amount" type="number" step="any" min="0">
      <p class="sub"><?= te('Leave blank to accept the slip amount.') ?></p>
    </div>
    <div class="form-group">
      <label><?= te('Note') ?> (<?= te('optional') ?>)</label>
      <input name="note">
    </div>
  </form>
</template>

<template id="reject-modal">
  <form class="form-grid" style="grid-template-columns:1fr;">
    <input type="hidden" name="do_action" value="reject">
    <input type="hidden" name="payment_id">
    <div class="form-group">
      <label><?= te('Reason') ?> (<?= te('optional') ?>)</label>
      <input name="reason" placeholder="<?= te('optional') ?>">
      <p class="sub"><?= te('If blank, the default reason will be used.') ?></p>
    </div>
  </form>
</template>

<div id="bank-modal-bg" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:200; align-items:center; justify-content:center; backdrop-filter:blur(2px);" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:#fff; border-radius:16px; padding:28px; max-width:480px; width:94%; box-shadow:0 20px 60px rgba(0,0,0,0.2); display:flex; flex-direction:column; max-height:92vh;">
    <h2 id="bank-modal-title" style="margin:0 0 16px; font-size:20px; font-weight:700;"></h2>
    <div id="bank-modal-content"></div>
    <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:20px;">
      <button class="btn btn-red" type="button" id="bank-modal-cancel"><?= te('Cancel') ?></button>
      <button class="btn btn-green" type="button" id="bank-modal-submit"><?= te('Confirm') ?></button>
    </div>
  </div>
</div>

<script>
(function(){
  var BG = document.getElementById('bank-modal-bg');
  var TITLE = document.getElementById('bank-modal-title');
  var CONTENT = document.getElementById('bank-modal-content');
  var SUBMIT = document.getElementById('bank-modal-submit');
  var CANCEL = document.getElementById('bank-modal-cancel');

  var MSG = {
    verified:   'Payment verified — obligation settled.',
    rejected:   'Payment rejected.',
    error:      'Action failed.'
  };

  // Open modal from a js-modal button
  document.addEventListener('click', function(e) {
    var btn = e.target.closest ? e.target.closest('.js-modal') : null;
    if (!btn) return;
    e.preventDefault();
    var formId = btn.dataset.form;
    var tpl = document.getElementById(formId);
    if (!tpl) return;

    TITLE.textContent = btn.dataset.title || '';
    SUBMIT.textContent = btn.dataset.submit || 'Confirm';
    CONTENT.innerHTML = '';
    CONTENT.appendChild(tpl.content.cloneNode(true));

    // Fill form fields from button data-* attributes
    var form = CONTENT.querySelector('form');
    if (form) {
      form.querySelectorAll('[name]').forEach(function(field) {
        var key = field.name.replace(/-./g, function(m) { return m[1].toUpperCase(); });
        var val = btn.dataset[key.toLowerCase()] || btn.dataset[field.name];
        if (val !== undefined) field.value = val;
      });
      SUBMIT.onclick = function() { submitModal(form, btn.dataset.action || formId); };
    }

    BG.style.display = 'flex';
  });

  // Close handlers
  CANCEL.onclick = function() { BG.style.display = 'none'; };
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && BG.style.display === 'flex') BG.style.display = 'none';
  });

  function submitModal(form, action) {
    var formData = new FormData(form);
    var body = {};
    var paymentId = formData.get('payment_id');
    for (var [k, v] of formData.entries()) {
      if (k !== 'do_action' && k !== 'payment_id') body[k] = v;
    }

    SUBMIT.disabled = true;
    SUBMIT.textContent = '…';
    fetch(API_BASE + '/payments.php?id=' + paymentId + '&' + (action === 'verify-modal' ? 'verify' : 'reject') + '=1', {
      method: 'POST',
      headers: { 'Authorization': 'Bearer ' + ADMIN_TOKEN, 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      BG.style.display = 'none';
      if (d && d.ok) {
        var card = document.getElementById('card-' + paymentId);
        if (card) {
          card.style.transition = 'opacity 0.3s';
          card.style.opacity = '0';
          setTimeout(function() { card.remove(); }, 300);
        }
        showFlash(action === 'verify-modal' ? 'ok' : 'err', action === 'verify-modal' ? MSG.verified : MSG.rejected);
      } else {
        alert((d && d.error && d.error.message) || MSG.error);
        SUBMIT.disabled = false;
        SUBMIT.textContent = action === 'verify-modal' ? 'Confirm & verify' : 'Reject';
      }
    })
    .catch(function() {
      alert(MSG.error);
      SUBMIT.disabled = false;
      SUBMIT.textContent = 'Confirm';
    });
  }

  function showFlash(type, text) {
    var flash = document.createElement('p');
    flash.className = 'flash ' + type;
    flash.textContent = text;
    var main = document.querySelector('main');
    if (main) { main.insertBefore(flash, main.firstChild); }
    setTimeout(function() { flash.style.opacity = '0'; setTimeout(function() { flash.remove(); }, 300); }, 4000);
  }

  // Slip photo upload: the officer takes/scan a photo of the paper slip the
  // citizen brought from the bank counter, and files it against the payment.
  document.querySelectorAll('.js-upload-slip').forEach(function(btn) {
    var label = <?= json_encode(t('Upload slip')) ?>;
    btn.addEventListener('click', function() {
      var input = document.createElement('input');
      input.type = 'file';
      input.accept = 'image/jpeg,image/png,image/webp,application/pdf';
      input.onchange = function() {
        var file = input.files && input.files[0];
        if (!file) return;
        var pid = btn.dataset.paymentId;
        btn.disabled = true;
        btn.textContent = '…';
        var fd = new FormData();
        fd.append('slip', file);
        fetch(API_BASE + '/payments.php?id=' + pid + '&receipt=1', {
          method: 'POST',
          headers: { 'Authorization': 'Bearer ' + ADMIN_TOKEN },
          body: fd
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
          if (d && d.ok) {
            // Show the photo on the card, clear the "Awaiting proof" badge,
            // and hide the now-unneeded upload button.
            var card = document.getElementById('card-' + pid);
            if (card) {
              var left = card.firstElementChild.firstElementChild;
              var p = document.createElement('p');
              p.style.margin = '4px 0';
              p.innerHTML = '<b><?= json_encode(t('Slip photo')) ?>:</b><br>';
              var img = document.createElement('img');
              img.src = d.data.slip_path;
              img.className = 'slip-zoom';
              img.style.cssText = 'max-width:200px; border-radius:4px; background:#fff; cursor:zoom-in;';
              p.appendChild(img);
              left.appendChild(p);
              var badge = card.querySelector('.awaiting-badge');
              if (badge) badge.remove();
            }
            btn.remove();
            showFlash('ok', <?= json_encode(t('Slip photo uploaded.')) ?>);
          } else {
            showFlash('err', (d && d.error && d.error.message) || MSG.error);
            btn.disabled = false;
            btn.textContent = label;
          }
        })
        .catch(function() {
          showFlash('err', MSG.error);
          btn.disabled = false;
          btn.textContent = label;
        });
      };
      input.click();
    });
  });
})();
</script>
