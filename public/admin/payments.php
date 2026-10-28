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
        // Counter fast path: money in hand (cash/cheque) — create + settle in one step.
        $parcelNo = trim((string)($_POST['parcel_no'] ?? ''));
        $year = (int)($_POST['fee_year'] ?? date('Y'));
        $method = $_POST['method'] ?? 'cash';
        $chequeNo = trim((string)($_POST['cheque_no'] ?? ''));
        $amount = (isset($_POST['amount']) && $_POST['amount'] !== '') ? (float)$_POST['amount'] : null;
        $pid = 0;
        if ($parcelNo !== '') {
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
            if ($chequeNo !== '')  { $body['cheque_no'] = $chequeNo; }
            $d = api_call('POST', 'payments.php?settle=1', $body, $token);
            if (!empty($d['ok'])) {
                $msg = t('Payment received and settled — receipt ready.');
                $lastId = (int)($d['data']['id'] ?? 0);
                $lastReceipt = $d['data']['receipt'] ?? '';
            } else {
                $msg = $d['error']['message'] ?? t('Settle failed.');
                $msgType = 'err';
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

$years = api_call('GET', 'fee_years.php', null, $token)['data']['years'] ?? [];

// ---- Per-tab data ----------------------------------------------------------
$payments = []; $count = 0;
$countIssued = $countPaid = $countRej = 0;
$counterToday = []; $counterTodayTotal = 0.0;
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
    // Today's counter receipts (cash + cheque, paid today) — the officer's
    // answer to "what did we collect at the counter today?". paid_after keeps
    // the query to today's rows instead of scanning the full history.
    foreach (['cash', 'cheque'] as $m) {
        $d = api_call('GET', 'payments.php?status=PAID&method=' . $m . '&paid_after=' . date('Y-m-d'), null, $token);
        foreach (($d['data']['payments'] ?? []) as $row) {
            $counterToday[] = $row;
            $counterTodayTotal += (float)$row['amount'];
        }
    }
    usort($counterToday, fn($a, $b) => strcmp((string)($b['paid_at'] ?? ''), (string)($a['paid_at'] ?? '')));
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
              <p><b><?= te('Slip photo') ?>:</b><br><img src="<?= e($p['slip_path']) ?>" class="slip-zoom" style="max-width:220px; border-radius:4px; background:#fff; cursor:zoom-in;" alt="<?= te('Slip photo') ?>"></p>
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
    <p class="sub"><?= te('Counter cash/cheque, bank transfer and mobile payments — one tab per channel.') ?></p>

    <div class="pay-tabs">
      <a href="?tab=counter" class="<?= $tab === 'counter' ? 'active' : '' ?>"><?= te('Office counter') ?></a>
      <a href="?tab=bank" class="<?= $tab === 'bank' ? 'active' : '' ?>"><?= te('Bank & mobile proofs') ?></a>
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
        <p class="sub"><?= te('Cash or cheque received at the district office — settles the obligation immediately and prints the receipt.') ?></p>
        <h3 style="margin:14px 0 4px;"><?= te('Step 1 — verify the obligation') ?></h3>
        <div class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px; align-items:end;">
          <div class="form-group"><label><?= te('Parcel no') ?></label><input id="counter-parcel" required></div>
          <div class="form-group"><label><?= te('Tax year') ?></label>
            <select id="counter-year">
              <?php foreach ($years as $y): ?>
              <option value="<?= e($y['year']) ?>" <?= (int)$y['year'] === (int)date('Y') ? 'selected' : '' ?>><?= e($y['year']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label><?= te('Method') ?></label>
            <select id="counter-method">
              <option value="cash"><?= e(payment_method_label('cash')) ?></option>
              <option value="cheque"><?= e(payment_method_label('cheque')) ?></option>
            </select>
          </div>
          <div class="form-group" style="margin:0;"><label>&nbsp;</label><button id="counter-verify" class="btn btn-gold" type="button"><?= te('Verify obligation') ?></button></div>
        </div>
        <div id="counter-verify-result" style="display:none; margin-top:10px; padding:10px 12px; border-radius:6px; font-size:14px;"></div>

        <div id="counter-record-box" style="display:none; margin-top:14px; padding-top:12px; border-top:1px dashed #c9b458;">
          <h3 style="margin:0 0 2px;"><?= te('Step 2 — record & settle') ?></h3>
          <p class="sub" id="counter-record-note"></p>
          <form method="post" class="form-grid" id="counter-settle-form" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px; align-items:end;">
            <input type="hidden" name="do_action" value="settle">
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
            <div class="form-group"><label><?= te('Note') ?> (<?= te('optional') ?>)</label><input name="note"></div>
            <div class="form-group" style="margin:0;"><label>&nbsp;</label><button class="btn btn-gold" type="submit"><?= te('Record & settle') ?></button></div>
          </form>
        </div>
      </div>

      <div class="form-box">
        <h2><?= te('Received today') ?></h2>
        <p class="sub"><?= te('{n} payment(s), {amount} kip today.', ['n' => count($counterToday), 'amount' => number_format($counterTodayTotal, 0)]) ?></p>
        <?php if (!$counterToday): ?>
          <p class="empty"><?= te('No counter payments recorded today yet.') ?></p>
        <?php else: ?>
        <table>
          <thead><tr><th><?= te('Reference') ?></th><th><?= te('Parcel') ?></th><th><?= te('Owner') ?></th><th><?= te('Method') ?></th><th><?= te('Amount') ?></th><th><?= te('Time') ?></th><th></th></tr></thead>
          <tbody>
            <?php foreach ($counterToday as $p): ?>
            <tr>
              <td><?= e($p['reference_no']) ?></td>
              <td><a href="<?= $base ?>/parcel.php?id=<?= (int)$p['parcel_id'] ?>"><?= e($p['parcel_no']) ?></a></td>
              <td><?= e($p['payer_name'] ?: '—') ?></td>
              <td><?= e(payment_method_label((string)$p['method'])) ?><?= $p['bank_ref'] ? ' (' . e($p['bank_ref']) . ')' : '' ?></td>
              <td><?= number_format((float)$p['amount'], 0) ?></td>
              <td><?= e(substr((string)$p['paid_at'], 11, 5)) ?></td>
              <td><a href="<?= $base ?>/slip_print.php?id=<?= (int)$p['id'] ?>&receipt=1" target="_blank" class="btn btn-green btn-sm"><?= te('Receipt') ?></a></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>

    <?php elseif ($tab === 'bank'): ?>
      <!-- ================= BANK & MOBILE PROOFS ================= -->
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
          <?= te('Page {page} of {pages} — {n} record(s)', ['page' => $bankPage, 'pages' => $bankTotalPages, 'n' => count($bankIssued)]) ?>
          <?php if ($bankPage > 1): ?><a class="btn btn-blue btn-sm" href="?tab=bank&fee_year=<?= (int)$fee_year ?>&page=<?= $bankPage - 1 ?>"><?= te('Previous') ?></a><?php endif; ?>
          <?php if ($bankPage < $bankTotalPages): ?><a class="btn btn-blue btn-sm" href="?tab=bank&fee_year=<?= (int)$fee_year ?>&page=<?= $bankPage + 1 ?>"><?= te('Next') ?></a><?php endif; ?>
        </p>
        <?php foreach ($bankQueue as $p): render_payment_card($p, $base); endforeach; ?>
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
  <img src="" alt="" style="max-width:92vw; max-height:92vh; background:#fff; border-radius:6px;">
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
      lb.querySelector('img').src = e.target.src;
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
    ownerPh:  <?= json_encode(t('-- select owner (payer) --')) ?>,
    assessed: <?= json_encode(t('Obligation for {year} ({status}) — due {due} kip, paid {paid} kip, balance {out} kip')) ?>,
    settled:  <?= json_encode(t('Obligation already settled — nothing to collect')) ?>,
    fresh:    <?= json_encode(t('No obligation for {year} yet — settles at {amt} kip (zone {zone}, {rate} kip/m²)')) ?>,
    norate:   <?= json_encode(t('No zone rate set for this parcel — assign a valuation zone first')) ?>,
    expect:   <?= json_encode(t('Obligation verified — expected {amt} kip.')) ?>,
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
  var amountInput = settleForm ? settleForm.querySelector('input[name=amount]') : null;
  var chequeGroup = document.getElementById('counter-cheque-group');

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
  }
  function syncCheque() { if (chequeGroup) { chequeGroup.style.display = methodSel.value === 'cheque' ? '' : 'none'; } }

  if (methodSel) {
    methodSel.onchange = function() {
      syncCheque();
      if (settleForm && settleForm.style.display !== 'none') {
        settleForm.querySelector('input[name=method]').value = methodSel.value;
      }
    };
    syncCheque();
  }
  // Changing parcel/year after a verify invalidates the result — re-verify.
  if (parcelInput) {
    parcelInput.onchange = yearSel.onchange = function() {
      resetRecord();
      verifyRes.style.display = 'none';
      verifyRes.innerHTML = '';
    };
  }
  if (verifyBtn) {
    verifyBtn.onclick = function() {
      var no = parcelInput.value.trim();
      if (!no) { parcelInput.focus(); return; }
      var yr = yearSel.value;
      resetRecord();
      showVerify('<b>' + esc(MSG.loading) + '</b>', 'warn');
      fetch(API_BASE + '/parcel_fee.php?parcel_no=' + encodeURIComponent(no) + '&fee_year=' + encodeURIComponent(yr),
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
          settleForm.querySelector('input[name=parcel_no]').value = no;
          settleForm.querySelector('input[name=fee_year]').value = yr;
          settleForm.querySelector('input[name=method]').value = methodSel.value;
          var owners = data.owners || [];
          ownerSel.innerHTML = '<option value="">' + esc(MSG.ownerPh) + '</option>';
          for (var i = 0; i < owners.length; i++) {
            ownerSel.innerHTML += '<option value="' + owners[i].id + '">' + esc(owners[i].full_name) + '</option>';
          }
          if (amountInput && payable > 0) { amountInput.value = payable; }
          recordNote.textContent = payable > 0 ? fillText(MSG.expect, { amt: fmtKip(payable) }) : '';
          syncCheque();
          recordBox.style.display = 'block';
        })
        .catch(function() { showVerify(esc(MSG.notfound), 'err'); });
    };
  }
})();
</script>
</body>
</html>
