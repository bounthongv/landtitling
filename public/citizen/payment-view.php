<?php
// public/citizen/payment-view.php — printable slip + tracking
session_start();
$token = $_SESSION['citizen_token'] ?? '';
if (!$token) { header('Location: login.php'); exit; }

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header('Location: payments.php'); exit; }

$payments = [];
$ch = curl_init('http://localhost/api/v1/payments.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resp = curl_exec($ch); curl_close($ch);
$d = json_decode($resp, true);
$payments = $d['data'] ?? [];

$payById = null;
foreach ($payments as $p) {
    if ((int)$p['id'] === $id) { $payById = $p; break; }
}
$payment = $payById;

$parcel = [];
if ($payment && !empty($payment['parcel_id'])) {
    $ch = curl_init('http://localhost/api/v1/parcels.php?id=' . $payment['parcel_id']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch); curl_close($ch);
    $d = json_decode($resp, true);
    $parcel = $d['data']['parcels'][0] ?? [];
}
?>
<div class="app">
  <header class="app-header">
    <div class="title">ການຊຳລະ</div>
    <div class="sub">Payment #<?= e($payment['slip_no'] ?? 'N/A') ?></div>
  </header>
  <main>
    <div class="card">
      <?php if ($payment && !$parcel): ?>
        <div style="color:#1b5e20; background:#eaf4ea; padding:10px; border-radius:6px; margin-bottom:12px;">
          ✅ Payment <?= e($payment['status']) ?> · <?= number_format((float)$payment['fee_total'], 0) ?> kip
        </div>
      <?php endif; ?>

      <?php if ($parcel): ?>
        <div class="card" style="border-color:#1b5e20; background:#f8faf7;">
          <p class="sub" style="margin-top:0;"><strong>Parcel details</strong> — <?= e($parcel['parcel_no']) ?></p>
          <p class="sub">District: <?= e($parcel['district_name'] ?? '') ?> · Village: <?= e($parcel['village_name'] ?? '') ?></p>
          <p class="sub">Area: <?= number_format((float)$parcel['area_sqm'] ?? 0, 2) ?> m² · Road: <?= e(ucfirst($parcel['road_category'] ?? '')) ?></p>
        </div>
      <?php endif; ?>

      <?php if ($payment): ?>
        <div class="card">
          <h3>Payment details</h3>
          <table class="slip-table">
            <tr><td>Status</td><td><span class="pill pill-<?= e($payment['status']) ?>"><?= e(ucfirst($payment['status'])) ?></span></td></tr>
            <tr><td>Slip number</td><td><?= e($payment['slip_no'] ?? 'N/A') ?></td></tr>
            <tr><td>District</td><td><?= e($parcel['district_name'] ?? $payment['district_id'] ?? 'N/A') ?></td></tr>
            <tr><td>Village</td><td><?= e($parcel['village_name'] ?? 'N/A') ?></td></tr>
            <tr><td>Parcel</td><td><?= e($parcel['parcel_no'] ?? 'N/A') ?></td></tr>
            <tr><td>Area</td><td><?= number_format((float)($payment['area_sqm'] ?? $parcel['area_sqm'] ?? 0), 2) ?> m²</td></tr>
            <tr><td>Rate</td><td><?= number_format((float)($payment['zone_rate'] ?? 0), 0) ?> kip/m²</td></tr>
            <tr class="amount"><td>Total</td><td><?= number_format((float)$payment['fee_total'], 0) ?> kip</td></tr>
          </table>
          <?php if (!empty($payment['slip_path'])): ?>
            <h4>Bank/Counter receipt (uploaded)</h4>
            <img src="/LandTitling/<?= e($payment['slip_path']) ?>" style="max-width:200px; border-radius:6px; background:#fff;" alt="slip">
          <?php else: ?>
            <div class="qr-box">
              <?php if ($payment['slip_no']): ?>
                <p>Slip QR payload:</p>
                <code style="font-size:12px; word-break:break-all;"><?= e($payment['slip_qr'] ?? 'N/A') ?></code>
                <p class="sub" style="margin-top:8px;">Print / save this slip to pay at bank or counter.</p>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="card">
        <h3>Tracking</h3>
        <ul class="list">
          <li>
            <span class="pill pill-<?= e($payment['status']) ?>"><?= e(ucfirst($payment['status'])) ?></span>
            <span class="name">Created</span>
            <span style="color:var(--muted);font-size:12px;"><?= e($payment['created_at'] ?? 'N/A') ?></span>
          </li>
          <?php if (!empty($payment['paid_at'])): ?>
          <li>
            <span class="pill pill-paid">PAID</span>
            <span class="name">Paid at bank/counter</span>
            <span style="color:var(--muted);font-size:12px;"><?= e($payment['paid_at']) ?></span>
          </li>
          <?php endif; ?>
          <?php if (!empty($payment['verify_note'])): ?>
          <li>
            <span class="pill pill-rej">Rejected</span>
            <span class="name">Verification note</span>
            <span style="color:var(--muted);font-size:12px;"><?= e($payment['verify_note']) ?></span>
          </li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </main>
</div>
</body>
</html>
