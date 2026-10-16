<?php
// public/citizen/payments.php — list of my payments (track status)
require __DIR__ . '/common.php';
$token = $_SESSION['citizen_token'] ?? '';
if (!$token) { header('Location: login.php'); exit; }

$payments = [];
$ch = curl_init($api_base . '/payments.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$resp = curl_exec($ch); curl_close($ch);
$d = json_decode($resp, true);
$payments = $d['data'] ?? [];

// Fetch parcel details for each payment
foreach ($payments as $i => $p) {
    if (!empty($p['parcel_id'])) {
        $ch = curl_init($api_base . '/parcels.php?id=' . $p['parcel_id']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $resp = curl_exec($ch); curl_close($ch);
        $d = json_decode($resp, true);
        $parcel = $d['data']['parcels'][0] ?? [];
        $payments[$i]['parcel_no'] = $parcel['parcel_no'] ?? 'N/A';
        $payments[$i]['district_name'] = $parcel['district_name'] ?? 'N/A';
        $payments[$i]['village_name'] = $parcel['village_name'] ?? 'N/A';
        $payments[$i]['area_sqm'] = $parcel['area_sqm'] ?? $p['area_sqm'] ?? 0;
    }
}
?>
<div class="card">
  <h2>ການຊຳລະຂອງຂ້າຮ່າງ</h2>
  <p class="sub">Track your land fee payments: ISSUED → PAID / REJECTED.</p>

  <?php if (empty($payments)): ?>
    <p class="empty">No payments yet. <a href="quote.php">Make a payment</a> to see it here.</p>
  <?php else: ?>
    <ul class="list">
      <?php foreach ($payments as $p): ?>
      <li>
        <div class="row" style="justify-content:space-between; align-items:flex-start;">
          <div>
            <div class="name">#<?= e($p['slip_no'] ?? 'N/A') ?></div>
            <div style="color:var(--muted);font-size:13px;">Parcel <?= e($p['parcel_no'] ?? 'N/A') ?> · <?= e($p['district_name'] ?? 'N/A') ?> / <?= e($p['village_name'] ?? 'N/A') ?></div>
          </div>
          <div style="text-align:right;">
            <span class="pill pill-<?= e($p['status']) ?>"><?= e(ucfirst($p['status'])) ?></span>
          </div>
        </div>
        <div style="display:flex; justify-content:space-between; align-items:center; padding-top:8px;">
          <div style="font-weight:600;"><?= number_format((float)$p['area_sqm'], 2) ?> m²</div>
          <div class="amount" style="font-size:18px;"><?= number_format((float)$p['fee_total'], 0) ?> kip</div>
        </div>
        <div style="margin-top:6px; display:flex; gap:8px;">
          <a class="btn btn-gold btn-sm" style="width:auto;padding:6px 12px;" href="/payment-view.php?id=<?= $p['id'] ?>">View →</a>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
