<?php
// public/citizen/payment-view.php — printable slip, receipt upload, payment tracking
require __DIR__ . '/common.php';
citizen_require_login();
$token = citizen_token();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header('Location: index.php#payments'); exit; }

$err = '';
$flash = '';

// ---- Upload the bank/counter receipt photo ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['upload_receipt'])) {
    if (empty($_FILES['slip']) || $_FILES['slip']['error'] !== UPLOAD_ERR_OK) {
        $err = 'Choose a photo of your receipt.';
    } else {
        $r = citizen_api_upload('payments.php?id=' . $id . '&receipt=1',
            $_FILES['slip']['tmp_name'], $_FILES['slip']['name'], $_FILES['slip']['type'], $token, 'slip');
        if (!empty($r['ok'])) {
            $flash = 'Receipt uploaded. An officer will verify it.';
        } else {
            $err = citizen_error($r);
        }
    }
}

// Find this payment among the citizen's own payments (API enforces ownership)
$payments = citizen_data(citizen_api('GET', 'payments.php', null, $token))['payments'] ?? [];
$payment = null;
foreach ($payments as $p) {
    if ((int)$p['id'] === $id) { $payment = $p; break; }
}
if (!$payment) {
    $err = $err ?: 'Payment not found.';
}

// Obligation context for rate / due date
$ob = null;
if ($payment && !empty($payment['obligation_id'])) {
    foreach (citizen_data(citizen_api('GET', 'my_parcels.php', null, $token))['parcels'] ?? [] as $mp) {
        foreach ($mp['obligations'] ?? [] as $o) {
            if ((int)$o['id'] === (int)$payment['obligation_id']) { $ob = $o; break 2; }
        }
    }
}

$qrPayload = (string)($payment['slip_qr'] ?? '');
$qrUrl = $qrPayload !== ''
    ? 'https://api.qrserver.com/v1/create-qr-code/?size=160x160&data=' . urlencode($qrPayload)
    : '';
$pill = ['ISSUED' => 'pending', 'PAID' => 'paid', 'REJECTED' => 'rej', 'REFUNDED' => 'rej'][$payment['status'] ?? ''] ?? 'pending';
?>
<!DOCTYPE html>
<html lang="lo">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Slip — Savannakhet Land Fee</title>
<?php require __DIR__ . '/style.php'; ?>
</head>
<body>
<div class="app" style="padding-bottom:24px;">
  <header class="app-header">
    <div class="title">ບິນຊຳລະ</div>
    <div class="sub"><?= $payment ? e($payment['reference_no']) : '' ?></div>
  </header>
  <main>
    <?php if ($flash): ?><div class="alert ok"><?= e($flash) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>

    <?php if (!$payment): ?>
      <div class="card"><p class="empty">Payment not found.</p></div>
    <?php else: ?>
      <div class="card">
        <div style="text-align:center;padding:6px 0 12px;">
          <span class="pill pill-<?= e($pill) ?>"><?= e($payment['status']) ?></span>
        </div>

        <table class="slip-table">
          <tr><td>ເລກທີອ້າງອງິງ / Reference</td><td class="ref-box"><?= e($payment['reference_no']) ?></td></tr>
          <tr><td>ຕອນດິນ / Parcel</td><td><?= e($payment['parcel_no']) ?></td></tr>
          <tr><td>District / Village</td><td><?= e($payment['district_name']) ?> / <?= e($payment['village_name']) ?></td></tr>
          <tr><td>Fee year</td><td><?= e($payment['fee_year']) ?></td></tr>
          <tr><td>Area</td><td><?= number_format((float)$payment['area_sqm'], 2) ?> m²</td></tr>
          <tr><td>ວິທີຊຳລະ / Method</td><td><?= e(ucfirst((string)$payment['method'])) ?></td></tr>
          <?php if ($ob): ?><tr><td>Due date</td><td><?= e($ob['due_date'] ?? '—') ?></td></tr><?php endif; ?>
          <tr class="amount"><td>Total</td><td><?= kip($payment['amount']) ?></td></tr>
        </table>

        <?php if ($qrUrl): ?>
          <div class="qr-box">
            <p style="text-align:center;">Scan at bank / counter</p>
            <img src="<?= e($qrUrl) ?>" alt="payment QR">
          </div>
        <?php endif; ?>

        <button class="btn btn-block" type="button" onclick="window.print()">Print slip</button>
      </div>

      <div class="card">
        <h3>Tracking</h3>
        <ul class="list">
          <li>
            <span class="pill pill-<?= e($pill) ?>"><?= e($payment['status']) ?></span>
            <span class="name">Slip created</span>
            <span style="color:var(--muted);font-size:12px;"><?= e($payment['created_at'] ?? '') ?></span>
          </li>
          <?php if (!empty($payment['paid_at'])): ?>
          <li>
            <span class="pill pill-paid">PAID</span>
            <span class="name">Payment recorded</span>
            <span style="color:var(--muted);font-size:12px;"><?= e($payment['paid_at']) ?></span>
          </li>
          <?php endif; ?>
          <?php if (!empty($payment['verify_method'])): ?>
          <li>
            <span class="pill pill-issued"><?= e($payment['verify_method']) ?></span>
            <span class="name">Verified by <?= e($payment['verify_method'] === 'auto_statement' ? 'bank statement match' : 'officer') ?></span>
            <span style="color:var(--muted);font-size:12px;"><?= e($payment['paid_at'] ?? '') ?></span>
          </li>
          <?php endif; ?>
          <?php if (!empty($payment['verify_note'])): ?>
          <li>
            <span class="pill pill-rej">Note</span>
            <span class="name"><?= e($payment['verify_note']) ?></span>
            <span></span>
          </li>
          <?php endif; ?>
        </ul>
      </div>

      <div class="card">
        <h3>Upload your receipt</h3>
        <?php if (!empty($payment['slip_path'])): ?>
          <p class="sub" style="margin-top:0;">Receipt on file:</p>
          <img src="<?= e($payment['slip_path']) ?>" style="max-width:220px;border-radius:6px;background:#fff;" alt="receipt">
        <?php endif; ?>
        <?php if ($payment['status'] !== 'PAID'): ?>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="upload_receipt" value="1">
          <div class="form-group">
            <label>Photo of bank/counter receipt</label>
            <input type="file" name="slip" accept="image/*" required>
          </div>
          <button class="btn btn-gold" type="submit">Upload receipt</button>
        </form>
        <p class="foot-note">Pay using the reference above, then upload the receipt here.</p>
        <?php else: ?>
          <p class="sub" style="margin-bottom:0;">This payment is settled — no receipt needed.</p>
        <?php endif; ?>
      </div>

      <p style="text-align:center;"><a href="index.php#payments">← ການຊຳລະຂອງຂ້າຮ່າງ</a></p>
    <?php endif; ?>
  </main>
</div>
</body>
</html>