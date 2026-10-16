<?php
// public/admin/payments.php — payment verification queue (verify/reject)
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
$role = $user['role'];

$status = $_GET['status'] ?? 'ISSUED';
$filter = $status;

$payments = [];
$d = api_call('GET', 'payments.php?status=' . $status);
if (!empty($d['ok'])) { $payments = $d['data'] ?? []; }

$districts = [];
$districts = api_call('GET', 'districts.php')['data'] ?? [];
$districtMap = [];
foreach ($districts as $d) { $districtMap[$d['id']] = $d['name_lo']; }

$action = $_GET['action'] ?? ''; // verify, reject
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['do_action'])) {
    $id = (int)($_POST['payment_id'] ?? 0);
    $action = $_POST['do_action'];
    $postBody = $action === 'reject' ? ['reason' => $_POST['reason'] ?? 'Rejected'] : [];
        $d = api_call('POST', 'payments.php?id=' . $id . '&' . ($action === 'verify' ? 'verify' : 'reject'), $postBody, $_SESSION['admin_token']);
        if (!empty($d['ok'])) {
        $msg = ($action === 'verify' ? 'Payment verified (PAID).' : 'Payment rejected.') . ' ' . $id;
    } else {
        $msg = $d['error']['message'] ?? 'Action failed';
    }
}
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
    <p class="sub">Citizens upload their bank/counter payment slip. You verify it to PAID or reject it.</p>
    <div class="cards" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr));">
      <div class="card"><h3>Issued (to verify)</h3><p class="num" id="count"><?= $filter ?></p></div>
      <div class="card"><h3>PAID</h3><p class="num" id="paid-count">—</p></div>
      <div class="card"><h3>Rejected</h3><p class="num" id="rej-count">—</p></div>
    </div>

    <?php if ($msg): ?><div class="form-box" style="border-color:#2e7d32; background:#eaf4ea;"><?= e($msg) ?></div><?php endif; ?>

    <?php if (empty($payments)): ?><p class="empty">No payments in status <?= e(ucfirst($filter)) ?>.</p><?php else: ?>
    <?php foreach ($payments as $p): ?>
    <div class="form-box" style="padding:14px; border-left:4px solid #1b5e20;">
      <div style="display:grid; grid-template-columns: 1fr auto; gap:8px 16px; align-items:center;">
        <div>
          <b>Slip #<?= e($p['slip_no'] ?? 'N/A') ?></b> — Parcel <?= e($p['parcel_no'] ?? 'N/A') ?>
          <p class="sub">District: <?= e($districtMap[$p['district_id']] ?? $p['district_id']) ?> &nbsp;|&nbsp; Area: <?= number_format((float)$p['area_sqm'], 2) ?> m²</p>
          <p><b>Amount due:</b> <?= number_format((float)$p['fee_total'], 2) ?> kip</p>
          <p><b>QR payload:</b> <?= e($p['slip_qr'] ?? 'N/A') ?></p>
          <?php if (!empty($p['slip_path'])): ?>
            <p><b>Receipt image:</b></p>
            <img src="<?= e($p['slip_path']) ?>" style="max-width:200px; border-radius:4px; background:#fff;" alt="slip">
          <?php endif; ?>
        </div>
        <div style="display:flex; gap:8px; align-items:center;">
          <span class="pill pill-<?= e($p['status']) ?>"><?= e(ucfirst($p['status'])) ?></span>
          <?php if ($p['status'] === 'ISSUED'): ?>
          <form method="post" style="display:inline;">
            <input type="hidden" name="do_action" value="verify">
            <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
            <button class="btn btn-green btn-sm">Verify</button>
          </form>
          <?php else: ?>
          <form method="post" style="display:inline;">
            <input type="hidden" name="do_action" value="reject">
            <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
            <input type="hidden" name="reason" value="Payment verified as paid">
            <button class="btn btn-red btn-sm">Reject</button>
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
