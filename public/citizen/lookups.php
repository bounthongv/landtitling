<?php
// public/citizen/lookups.php — parcel lookup by parcel_no
$results = [];
$parcelNo = $_GET['parcel_no'] ?? '';
$count = 0;
if ($parcelNo !== '') {
    $ch = curl_init('http://localhost/api/v1/parcels.php?parcel_no=' . urlencode($parcelNo));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch); curl_close($ch);
    $d = json_decode($resp, true);
    if (!empty($d['ok'])) {
        $results = $d['data']['parcels'] ?? [];
        $count = $d['data']['count'] ?? 0;
    }
}
?>
<div class="card">
  <h2>ຄົ້ນຫາພື້ນທີ່</h2>
  <p class="sub">Scan your land title (parcel no) to see the land parcel.</p>
  <form method="get" action="lookups.php" class="form-group">
    <div class="form-group" style="margin-bottom:0;">
      <label for="parcel_no">Parcel number</label>
      <input type="text" id="parcel_no" name="parcel_no" placeholder="e.g. 1301/001" value="<?= e($parcelNo) ?>">
    </div>
    <button class="btn" type="submit">ຄົ້ນຫາ</button>
  </form>
  <p class="sub" style="margin-bottom:0;">No parcel number yet? Scan a title photo in <a href="/LandTitling/public/admin/scans.php" style="color:var(--green);">Officer Review</a> to register it.</p>

  <?php if ($count === 0): ?>
    <p class="empty">No parcel found with that number.</p>
  <?php else: ?>
    <ul class="list">
      <?php foreach ($results as $p): ?>
      <li>
        <div class="name">Parcel <?= e($p['parcel_no']) ?></div>
        <div>District: <?= e($p['district_name'] ?? '') ?></div>
        <div>Village: <?= e($p['village_name'] ?? '') ?></div>
        <div style="color:var(--muted);font-size:13px;">Area: <?= number_format((float)$p['area_sqm'] ?? 0, 2) ?> m² &nbsp;·&nbsp; Status: <?= e(ucfirst($p['status'] ?? '')) ?></div>
        <div style="margin-top:6px;"><a class="btn btn-gold btn-sm" style="width:auto;padding:8px 16px;font-size:14px;" href="quote.php?parcel_no=<?= e($p['parcel_no']) ?>&district_id=<?= $p['district_id'] ?? '' ?>">ເບິ່ງຄ່າຊຳລະ →</a></div>
      </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
