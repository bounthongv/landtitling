<?php
// public/citizen/quote.php — review the assessed fee for a year and issue a payment slip
$token = citizen_token();
citizen_require_login();

$obligationId = (int)($_GET['obligation_id'] ?? $_POST['obligation_id'] ?? 0);
$parcelId     = (int)($_GET['parcel_id'] ?? $_POST['parcel_id'] ?? 0);
$method_      = $_POST['method'] ?? 'counter';
$err = '';
$created = null;

// ---- Issue a payment (explicit action; no payment is created just by viewing) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['submit_payment'])) {
    if (!$obligationId) {
        $err = 'No obligation selected.';
    } else {
        $body = ['obligation_id' => $obligationId, 'method' => $method_];
        if ($parcelId) { $body['parcel_id'] = $parcelId; }
        $d = citizen_api('POST', 'payments.php', $body, $token);
        if (!empty($d['ok'])) {
            $created = citizen_data($d);
        } else {
            $err = citizen_error($d);
        }
    }
}

// ---- Load the obligation for display ----
$ob = null;
$parcel = null;
if ($obligationId) {
    $mine = citizen_data(citizen_api('GET', 'my_parcels.php', null, $token))['parcels'] ?? [];
    foreach ($mine as $mp) {
        if ((int)$mp['parcel_id'] === $parcelId || !$parcelId) {
            foreach ($mp['obligations'] as $o) {
                if ((int)$o['id'] === $obligationId) {
                    $ob = $o;
                    $parcel = $mp;
                    $parcelId = (int)$mp['parcel_id'];
                    break 2;
                }
            }
        }
    }
    if (!$ob) {
        $err = $err ?: 'That fee is not linked to your account.';
    }
}
?>
<div class="card">
  <h2>ເບິ່ງຄ່າຊຳລະ</h2>
  <p class="sub">Fee = zone rate (location) × area. One fee per parcel per year.</p>

  <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>

  <?php if ($created): ?>
    <div class="card" style="background:#d4edda; border-color:#2e7d32;">
      <h3>✅ ສາງບິນຊຳລະແລ້ວ — slip created</h3>
      <p class="sub">Status: <span class="pill pill-pending"><?= e($created['status']) ?></span> (awaiting payment)</p>
      <p class="sub">Reference: <strong><?= e($created['reference_no']) ?></strong></p>
      <p class="sub">Amount: <strong><?= kip($created['amount']) ?></strong></p>
      <p class="sub">Pay at any bank or counter using the reference above, then upload your receipt.</p>
      <a class="btn btn-gold" href="payment-view.php?id=<?= (int)$created['id'] ?>">View slip + upload receipt →</a>
    </div>
  <?php endif; ?>

  <?php if ($ob && !$created): ?>
    <?php $outstanding = (float)$ob['outstanding']; ?>
    <div class="card" style="background:#eaf4ea; border-color:#bbdefb;">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;">
        <div>
          <div style="font-size:12px;color:var(--muted);">Parcel</div>
          <div style="font-size:16px;"><strong><?= e($parcel['parcel_no']) ?></strong></div>
        </div>
        <div style="text-align:right;">
          <div style="font-size:12px;color:var(--muted);">Fee year</div>
          <div style="font-size:16px;"><strong><?= e($ob['fee_year']) ?></strong></div>
        </div>
      </div>
      <div style="font-size:12px;color:var(--muted);margin-top:6px;">
        <?= e($parcel['district_name']) ?> / <?= e($parcel['village_name']) ?> ·
        <?= number_format((float)$parcel['area_sqm'], 2) ?> m² ·
        <?= e(ucfirst((string)$parcel['road_category'])) ?>
      </div>
      <table class="slip-table" style="margin-top:12px;">
        <tr><td>Assessed</td><td><?= number_format((float)$ob['amount_due'], 0) ?> ກີບ</td></tr>
        <tr><td>Already paid</td><td><?= number_format((float)$ob['amount_paid'], 0) ?> ກີບ</td></tr>
        <?php if ((float)$ob['penalty'] > 0): ?>
          <tr><td>Penalty</td><td><?= number_format((float)$ob['penalty'], 0) ?> ກີບ</td></tr>
        <?php endif; ?>
        <tr class="amount"><td>Outstanding</td><td><?= kip($outstanding) ?></td></tr>
        <tr><td>Due date</td><td><?= e($ob['due_date'] ?? '—') ?></td></tr>
      </table>

      <?php if ($outstanding > 0): ?>
      <form method="post" action="quote.php">
        <input type="hidden" name="obligation_id" value="<?= (int)$ob['id'] ?>">
        <input type="hidden" name="parcel_id" value="<?= (int)$parcelId ?>">
        <div class="form-group">
          <label>ວິທີຊຳລະ / Payment method</label>
          <select name="method">
            <option value="counter"     <?= $method_ === 'counter' ? 'selected' : '' ?>>ທີ່ຫຼັກຖານ (counter)</option>
            <option value="bank_transfer" <?= $method_ === 'bank_transfer' ? 'selected' : '' ?>>ຫຼັກຖານທະນາຄານ (bank transfer)</option>
            <option value="mobile"      <?= $method_ === 'mobile' ? 'selected' : '' ?>>ທະນາຄານມືຖືອ (mobile)</option>
          </select>
        </div>
        <button class="btn btn-gold" type="submit" name="submit_payment" value="1">
          ສ້າງບິນຊຳລະ · Create slip · <?= kip($outstanding) ?>
        </button>
      </form>
      <?php else: ?>
        <p class="sub">This year is already settled. <a href="lookups.php">Back to my parcels</a>.</p>
      <?php endif; ?>
    </div>
  <?php elseif (!$created && !$err): ?>
    <p class="empty">Select a parcel year from <a href="lookups.php">my parcels</a> to see its fee.</p>
  <?php endif; ?>
</div>