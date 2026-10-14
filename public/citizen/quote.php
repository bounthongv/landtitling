<?php
// public/citizen/quote.php — fee quote + create payment
session_start();
$token = $_SESSION['citizen_token'] ?? '';
if (!$token) { header('Location: login.php'); exit; }

// Incoming parcel from lookup
$parcel = [];
$parcelNo = $_GET['parcel_no'] ?? '';
$statusMsg = '';
if ($parcelNo !== '') {
    $ch = curl_init('http://localhost/api/v1/parcels.php?parcel_no=' . urlencode($parcelNo));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch); curl_close($ch);
    $d = json_decode($resp, true);
    if (!empty($d['ok'])) { $parcel = $d['data']['parcels'][0] ?? []; }
}

// Fee quote
$rate = $fee = null;
$quoteDistrict = $_POST['district_id'] ?? ($_GET['district_id'] ?? '');
$quoteCategory = $_POST['road_category'] ?? '';
$quoteArea = $_POST['area_sqm'] ?? '';
if ($quoteDistrict && $quoteCategory && $quoteArea > 0) {
    $ch = curl_init('http://localhost/api/v1/fee/quote.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'district_id' => (int)$quoteDistrict,
        'road_category' => $quoteCategory,
        'area_sqm' => (float)$quoteArea,
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $resp = curl_exec($ch); curl_close($ch);
    $d = json_decode($resp, true);
    if (!empty($d['ok'])) {
        $rate = $d['data']['rate_per_sqm'];
        $fee = $d['data']['fee_total'];
    } else {
        $statusMsg = $d['error']['message'] ?? 'Quote failed';
    }
}

// Create payment
$created = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['submit_payment'])) {
    $parcelId = $_POST['parcel_id'] ?? null;
    $districtId = $_POST['district_id'] ?? null;
    $area = $_POST['area_sqm'] ?? null;
    if ($parcelId && $districtId && $area > 0) {
        $ch = curl_init('http://localhost/api/v1/payments.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'parcel_id' => (int)$parcelId,
            'district_id' => (int)$districtId,
            'area_sqm' => (float)$area,
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $resp = curl_exec($ch); curl_close($ch);
        $d = json_decode($resp, true);
        if (!empty($d['ok'])) {
            $created = $d['data'];
            $statusMsg = 'Payment ISSUED. Slip #: ' . e($created['slip_no']);
        } else {
            $statusMsg = $d['error']['message'] ?? 'Failed to create payment';
        }
    } else {
        $statusMsg = 'Please enter a parcel and a valid area.';
    }
}
?>
<div class="card">
  <h2>ເບິ່ງຄ່າຊຳລະ</h2>
  <p class="sub">Land Fee — Select location &amp; area to see the fee. Flat rate by location only.</p>

  <?php if ($statusMsg): ?><div style="background:#eaf4ea; border:1px solid #bbdefb; border-radius:6px; padding:10px; font-size:13px; margin-bottom:12px;"><?= e($statusMsg) ?></div><?php endif; ?>

  <?php if ($parcel && empty($_POST['district_id'])): ?>
    <form method="get" action="quote.php" class="form-group">
      <input type="hidden" name="parcel_no" value="<?= e($parcelNo) ?>">
      <div class="form-group">
        <label>Parcel: <?= e($parcel['parcel_no']) ?> — <?= e($parcel['district_name'] ?? '') ?> / <?= e($parcel['village_name'] ?? '') ?></label>
      </div>
      <div class="form-group">
        <label>Area (m²) — auto from title</label>
        <input type="number" step="0.01" value="<?= e($parcel['area_sqm']) ?>" disabled>
      </div>
      <button class="btn btn-gold" type="submit">ເບິ່ງຄ່າຊຳລະ</button>
    </form>
  <?php else: ?>
    <form method="post" action="quote.php">
      <div class="form-group">
        <label>District</label>
        <select name="district_id" id="quote-district">
          <option value="">-- select --</option>
          <?php
          $ch = curl_init('http://localhost/api/v1/districts.php');
          curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
          $resp = curl_exec($ch); curl_close($ch);
          $districts = json_decode($resp, true)['data'] ?? [];
          foreach ($districts as $d): $sel = ($d['id'] == $quoteDistrict) ? 'selected' : ''; ?>
            <option value="<?= $d['id'] ?>" <?= $sel ?>><?= e($d['name_lo']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Road category</label>
        <select name="road_category" id="quote-category">
          <option value="">-- select --</option>
          <option value="main" <?= $quoteCategory === 'main' ? 'selected' : '' ?>>Main road</option>
          <option value="branch" <?= $quoteCategory === 'branch' ? 'selected' : '' ?>>Branch road</option>
          <option value="alley" <?= $quoteCategory === 'alley' ? 'selected' : '' ?>>Alley</option>
          <option value="none" <?= $quoteCategory === 'none' ? 'selected' : '' ?>>None</option>
        </select>
      </div>
      <div class="form-group">
        <label>Area (m²)</label>
        <input type="number" step="0.01" name="area_sqm" value="<?= e($quoteArea) ?>">
      </div>
      <button class="btn" type="submit">Calculate Fee</button>
    </form>
  <?php endif; ?>

  <?php if ($rate && $fee !== null): ?>
    <div class="card" style="background:#eaf4ea; border-color:#bbdefb;">
      <div class="row">
        <div class="stat" style="flex:1;text-align:left;">
          <div class="lbl">Rate per m²</div>
          <div style="font-size:20px;"><?= number_format((float)$rate, 0) ?> kip</div>
        </div>
        <div class="stat" style="flex:1;text-align:left;">
          <div class="lbl">Area</div>
          <div style="font-size:20px;"><?= number_format((float)($quoteArea), 2) ?> m²</div>
        </div>
      </div>
      <div class="row" style="margin-top:12px;">
        <div class="stat" style="flex:1;text-align:left;">
          <div class="lbl">Total fee</div>
          <div class="amount" style="font-size:26px;"><?= number_format((float)$fee, 0) ?> kip</div>
        </div>
        <div class="stat" style="flex:1;text-align:right;font-size:13px;color:var(--muted);">Flat rate · location only</div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($created): ?>
    <div class="card" style="background:#d4edda; border-color:#2e7d32;">
      <h3>✅ Payment created</h3>
      <p class="sub">Status: <span class="pill pill-paid">PAID (officer to verify)</span></p>
      <p class="sub">Slip #: <strong><?= e($created['slip_no']) ?></strong></p>
      <p class="sub">Amount: <strong><?= number_format((float)$created['fee_total'], 0) ?> kip</strong></p>
      <p class="sub">Parcel: <?= e($created['parcel_no'] ?? 'N/A') ?></p>
      <a class="btn btn-gold" href="payment-view.php?id=<?= $created['id'] ?>">View slip + tracking →</a>
    </div>
  <?php endif; ?>
</div>
