<?php
// public/admin/payments.php — payment verification queue (v2 settlements)
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
$role = $user['role'];
$token = $_SESSION['admin_token'];

$status = $_GET['status'] ?? 'ISSUED';
$fee_year = isset($_GET['fee_year']) ? (int)$_GET['fee_year'] : 0;

$msg = '';
$msgType = 'ok';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['do_action'])) {
    $id = (int)($_POST['payment_id'] ?? 0);
    $action = $_POST['do_action'];                 // verify | reject | waive
    $body = [];
    if ($action === 'reject') { $body['reason'] = $_POST['reason'] ?? 'Rejected by officer'; }
    if ($action === 'verify') { $body['note'] = $_POST['note'] ?? null; }
    if ($action === 'waive')  { $body['reason'] = $_POST['reason'] ?? 'Waived by officer'; }
    $d = api_call('POST', 'payments.php?id=' . $id . '&' . $action . '=1', $body, $token);
    if (!empty($d['ok'])) {
        $msg = ['verify' => 'Payment verified — obligation settled.',
                'reject' => 'Payment rejected.',
                'waive'  => 'Obligation waived.'][$action] ?? 'Done';
    } else {
        $msg = $d['error']['message'] ?? 'Action failed';
        $msgType = 'err';
    }
}

// Queue + counts
$qs = 'status=' . urlencode($status);
if ($fee_year) { $qs .= '&fee_year=' . $fee_year; }
$d = api_call('GET', 'payments.php?' . $qs, null, $token);
$payments = $d['data']['payments'] ?? [];

$countIssued = api_call('GET', 'payments.php?status=ISSUED', null, $token)['data']['count'] ?? 0;
$countPaid   = api_call('GET', 'payments.php?status=PAID', null, $token)['data']['count'] ?? 0;
$countRej    = api_call('GET', 'payments.php?status=REJECTED', null, $token)['data']['count'] ?? 0;

$years = api_call('GET', 'fee_years.php', null, $token)['data']['years'] ?? [];
?>
<!DOCTYPE html>
<html lang="lo">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verify Payments — Land Fee MVP</title>
<?php require __DIR__ . '/style.php'; ?>
</head>
<body>
<div class="app">
  <aside class="side">
    <div class="brand">ພະລັງງານການຊຳລະທຳນຽມ</div>
    <div class="side-nav"><?= admin_nav($role) ?></div>
    <div class="side-foot">Province: Savannakhet</div>
  </aside>
  <main class="main">
    <h1>Payment Verify Queue</h1>
    <p class="sub">Each payment settles an annual obligation. Bank-statement matches arrive already marked
      <span class="pill pill-paid">PAID</span> &mdash; this queue is for slip review and overrides.</p>

    <div class="cards">
      <div class="card"><h3>Issued (to verify)</h3><p class="num"><?= (int)$countIssued ?></p></div>
      <div class="card"><h3>Paid</h3><p class="num"><?= (int)$countPaid ?></p></div>
      <div class="card"><h3>Rejected</h3><p class="num"><?= (int)$countRej ?></p></div>
    </div>

    <?php if ($msg): ?><p class="flash <?= $msgType === 'err' ? 'err' : 'ok' ?>"><?= e($msg) ?></p><?php endif; ?>

    <div class="form-box">
      <form method="get" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px;">
        <div class="form-group"><label>Status</label>
          <select name="status">
            <?php foreach (['ISSUED','PAID','REJECTED','REFUNDED'] as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Fee year</label>
          <select name="fee_year"><option value="">-- all --</option>
            <?php foreach ($years as $y): ?>
            <option value="<?= e($y['year']) ?>" <?= $fee_year == $y['year'] ? 'selected' : '' ?>><?= e($y['year']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><button class="btn btn-green" type="submit">Filter</button></div>
      </form>
    </div>

    <?php if (!$payments): ?>
      <p class="empty">No payments in status <?= e($status) ?>.</p>
    <?php else: ?>
      <?php foreach ($payments as $p): ?>
      <div class="form-box" style="padding:14px; border-left:4px solid #1b5e20;">
        <div style="display:grid; grid-template-columns: 1fr auto; gap:8px 16px; align-items:start;">
          <div>
            <b><?= e($p['reference_no']) ?></b> — Parcel
            <a href="<?= $base ?>/parcel.php?id=<?= (int)$p['parcel_id'] ?>"><?= e($p['parcel_no']) ?></a>
            <p class="sub">
              Year <?= e($p['fee_year']) ?> &nbsp;|&nbsp;
              <?= e($p['district_name']) ?> / <?= e($p['village_name']) ?> &nbsp;|&nbsp;
              Area <?= number_format((float)$p['area_sqm'], 2) ?> m²
            </p>
            <p><b>Amount:</b> <?= number_format((float)$p['amount'], 0) ?> kip
               &nbsp;<b>Method:</b> <?= e(ucfirst($p['method'])) ?>
               <?php if ($p['payer_name']): ?> &nbsp;<b>Payer:</b> <?= e($p['payer_name']) ?><?php endif; ?>
            </p>
            <?php if ($p['obligation_status']): ?>
              <p class="sub">Obligation: <span class="pill pill-<?= e(strtolower($p['obligation_status'])) ?>"><?= e($p['obligation_status']) ?></span></p>
            <?php endif; ?>
            <?php if (!empty($p['verify_method'])): ?>
              <p class="sub">Verified via <?= e($p['verify_method']) ?><?= $p['paid_at'] ? ' on ' . e($p['paid_at']) : '' ?></p>
            <?php endif; ?>
            <?php if (!empty($p['bank_ref'])): ?>
              <p class="sub">Bank ref: <?= e($p['bank_ref']) ?></p>
            <?php endif; ?>
            <?php if (!empty($p['slip_path'])): ?>
              <p><b>Slip photo:</b><br><img src="<?= e($p['slip_path']) ?>" style="max-width:220px; border-radius:4px; background:#fff;" alt="slip"></p>
            <?php endif; ?>
            <p><a href="<?= $api_base ?>/payments.php?id=<?= (int)$p['id'] ?>&slip=1" target="_blank">Print slip</a></p>
          </div>
          <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <span class="pill pill-<?= e(strtolower($p['status'])) ?>"><?= e($p['status']) ?></span>
            <?php if ($p['status'] === 'ISSUED'): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="do_action" value="verify">
                <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                <button class="btn btn-green btn-sm" type="submit">Verify</button>
              </form>
              <form method="post" style="display:inline">
                <input type="hidden" name="do_action" value="reject">
                <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                <input type="hidden" name="reason" value="Rejected by officer">
                <button class="btn btn-red btn-sm" type="submit">Reject</button>
              </form>
            <?php endif; ?>
            <?php if ($p['status'] === 'PAID' && !empty($p['obligation_id'])): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="do_action" value="waive">
                <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                <input type="hidden" name="reason" value="Waived by officer">
                <button class="btn btn-gold btn-sm" type="submit">Waive</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </main>
</div>
</body>
</html>