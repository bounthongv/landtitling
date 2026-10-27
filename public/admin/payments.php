<?php
// public/admin/payments.php — payment verification queue (v2 settlements)
require __DIR__ . '/common.php';
require_once __DIR__ . '/../../lib/fee.php';   // payment_method_label()
if (!$is_logged_in) { header('Location: login.php'); exit; }
$role = $user['role'];
$token = $_SESSION['admin_token'];

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
    if ($action === 'reject') { $body['reason'] = $_POST['reason'] ?? t('Rejected by officer'); }
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

// Queue + counts
$qs = 'status=' . urlencode($status);
if ($methodF !== '') { $qs .= '&method=' . urlencode($methodF); }
if ($fee_year) { $qs .= '&fee_year=' . $fee_year; }
$d = api_call('GET', 'payments.php?' . $qs, null, $token);
$payments = $d['data']['payments'] ?? [];

$countIssued = api_call('GET', 'payments.php?status=ISSUED', null, $token)['data']['count'] ?? 0;
$countPaid   = api_call('GET', 'payments.php?status=PAID', null, $token)['data']['count'] ?? 0;
$countRej    = api_call('GET', 'payments.php?status=REJECTED', null, $token)['data']['count'] ?? 0;

$years = api_call('GET', 'fee_years.php', null, $token)['data']['years'] ?? [];
?>
<!DOCTYPE html>
<html lang="<?= i18n_current() ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= te('Verify Payments') ?> — Land Fee MVP</title>
<?php require __DIR__ . '/style.php'; ?>
</head>
<body>
<div class="app">
  <aside class="side">
    <?= admin_side_top() ?>
    <?= admin_nav($role) ?>
    <?= admin_side_foot() ?>
  </aside>
  <main class="main">
    <h1><?= te('Payment Verify Queue') ?></h1>
    <p class="sub"><?= te('Each payment settles an annual obligation. Bank-statement matches arrive already marked PAID — this queue is for slip review and overrides.') ?></p>

    <div class="cards">
      <div class="card"><h3><?= te('Issued (to verify)') ?></h3><p class="num"><?= (int)$countIssued ?></p></div>
      <div class="card"><h3><?= te('Paid') ?></h3><p class="num"><?= (int)$countPaid ?></p></div>
      <div class="card"><h3><?= te('Rejected') ?></h3><p class="num"><?= (int)$countRej ?></p></div>
    </div>

    <?php if ($msg): ?>
      <p class="flash <?= $msgType === 'err' ? 'err' : 'ok' ?>"><?= e($msg) ?>
        <?php if ($lastReceipt): ?> <a href="<?= $base ?>/slip_print.php?id=<?= (int)($lastId ?? 0) ?>&receipt=1" target="_blank" class="btn btn-green btn-sm"><?= te('Print receipt') ?></a><?php endif; ?>
      </p>
    <?php endif; ?>

    <div class="form-box">
      <form method="get" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px;">
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

    <div class="form-box" style="border-left:4px solid #b8860b;">
      <h2><?= te('Record counter payment') ?></h2>
      <p class="sub"><?= te('Cash or cheque received at the district office — settles the obligation immediately and prints the receipt.') ?></p>
      <form method="post" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:12px;">
        <input type="hidden" name="do_action" value="settle">
        <div class="form-group"><label><?= te('Parcel no') ?></label><input name="parcel_no" required></div>
        <div class="form-group"><label><?= te('Owner') ?></label>
          <select name="payer_owner_id" id="counter-owner">
            <option value=""><?= te('-- select parcel first --') ?></option>
          </select>
        </div>
        <div class="form-group"><label><?= te('Tax year') ?></label>
          <select name="fee_year">
            <?php foreach ($years as $y): ?>
            <option value="<?= e($y['year']) ?>" <?= (int)$y['year'] === (int)date('Y') ? 'selected' : '' ?>><?= e($y['year']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label><?= te('Method') ?></label>
          <select name="method" id="counter-method">
            <option value="cash"><?= e(payment_method_label('cash')) ?></option>
            <option value="cheque"><?= e(payment_method_label('cheque')) ?></option>
          </select>
        </div>
        <div class="form-group" id="counter-cheque-group"><label><?= te('Cheque no') ?></label><input name="cheque_no"></div>
        <div class="form-group"><label><?= te('Amount received') ?> (<?= te('optional') ?>)</label><input name="amount" type="number" step="any" min="0" placeholder="<?= te('full balance') ?>"></div>
        <div class="form-group"><label><?= te('Note') ?> (<?= te('optional') ?>)</label><input name="note"></div>
        <div class="form-group" style="align-self:end;"><button class="btn btn-gold" type="submit"><?= te('Receive & settle') ?></button></div>
      </form>
    </div>

    <?php if (!$payments): ?>
      <p class="empty"><?= te('No payments in status {status}.', ['status' => t($status)]) ?></p>
    <?php else: ?>
      <?php foreach ($payments as $p): ?>
      <div class="form-box" style="padding:14px; border-left:4px solid #1b5e20;">
        <div style="display:grid; grid-template-columns: 1fr auto; gap:8px 16px; align-items:start;">
          <div>
            <b><?= e($p['reference_no']) ?></b> — <?= te('Parcel') ?>
            <a href="<?= $base ?>/parcel.php?id=<?= (int)$p['parcel_id'] ?>"><?= e($p['parcel_no']) ?></a>
            <p class="sub">
              <?= te('Year') ?> <?= e($p['fee_year']) ?> &nbsp;|&nbsp;
              <?= e($p['district_name']) ?> / <?= e($p['village_name']) ?> &nbsp;|&nbsp;
              <?= te('Area') ?> <?= number_format((float)$p['area_sqm'], 2) ?> m²
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
              <form method="post" style="display:inline">
                <input type="hidden" name="do_action" value="verify">
                <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                <input name="amount" type="number" step="any" min="0" value="<?= number_format((float)$p['amount'], 2, '.', '') ?>" title="<?= te('Amount received') ?>" style="width:110px;">
                <input name="note" placeholder="<?= te('Note') ?>" title="<?= te('Note') ?>" style="width:110px;">
                <button class="btn btn-green btn-sm" type="submit"><?= te('Verify') ?></button>
              </form>
              <form method="post" style="display:inline">
                <input type="hidden" name="do_action" value="reject">
                <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                <input type="hidden" name="reason" value="<?= te('Rejected by officer') ?>">
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
      <?php endforeach; ?>
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
  // Counter form: show Cheque no only when method = cheque.
  var cm = document.getElementById('counter-method');
  var cg = document.getElementById('counter-cheque-group');
  if (cm && cg) {
    var sync = function() { cg.style.display = cm.value === 'cheque' ? '' : 'none'; };
    cm.onchange = sync;
    sync();
  }
  // Counter form: when the parcel no is entered, load its owners into the
  // Owner (payer) dropdown — parcel lookup, then owners of that parcel.
  var parcelInput = document.querySelector('input[name=parcel_no]');
  var ownerSel = document.getElementById('counter-owner');
  if (parcelInput && ownerSel) {
    function esc(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
    parcelInput.onchange = function() {
      var no = this.value.trim();
      ownerSel.innerHTML = '<option value=""><?= te('-- select parcel first --') ?></option>';
      if (!no) return;
      ownerSel.innerHTML = '<option value=""><?= te('-- loading --') ?></option>';
      fetch(API_BASE + '/parcels.php?parcel_no=' + encodeURIComponent(no) + '&limit=10',
            { headers: { 'Authorization': 'Bearer ' + ADMIN_TOKEN } })
        .then(function(r) { return r.json(); })
        .then(function(d) {
          var rows = (d && d.data && d.data.parcels) ? d.data.parcels : [];
          var pid = 0;
          for (var i = 0; i < rows.length; i++) {
            if (rows[i].parcel_no === no) { pid = rows[i].id; break; }
          }
          if (!pid) {
            ownerSel.innerHTML = '<option value=""><?= te('Parcel not found.') ?></option>';
            return null;
          }
          return fetch(API_BASE + '/owners.php?parcel_id=' + pid,
                       { headers: { 'Authorization': 'Bearer ' + ADMIN_TOKEN } });
        })
        .then(function(r2) { return r2 ? r2.json() : null; })
        .then(function(d2) {
          if (!d2) return;
          var owners = (d2.data && d2.data.owners) ? d2.data.owners : [];
          var opts = '<option value=""><?= te('-- unspecified --') ?></option>';
          for (var i = 0; i < owners.length; i++) {
            opts += '<option value="' + owners[i].id + '">' + esc(owners[i].full_name) + '</option>';
          }
          ownerSel.innerHTML = opts;
        })
        .catch(function() { ownerSel.innerHTML = '<option value=""><?= te('-- error --') ?></option>'; });
    };
  }
})();
</script>
</body>
</html>