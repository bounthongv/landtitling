<?php
// public/admin/index.php — dashboard
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
$role = $user['role'];
?>
<!DOCTYPE html>
<html lang="lo">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard — Land Fee MVP</title>
<?php require __DIR__ . '/style.php'; ?>
</head>
<body>
<div class="app">
  <aside class="side">
    <div class="brand">ພະລັງງານການຊຳລະທຳນຽມ</div>
    <div class="side-nav"><?= admin_nav($role) ?></div>
    <div class="side-foot">Province: <?= e(admin_province_name()) ?></div>
  </aside>
  <main class="main">
    <h1>Dashboard</h1>
    <?php if ($role === 'admin'): ?><p class="sub">Province officer back-office. Managed by the officer role for day-to-day work.</p><?php endif; ?>

    <section class="cards">
      <div class="card stat"><h3>Pending scans</h3><p class="num" id="scan-count">—</p></div>
      <div class="card stat"><h3>Parcels</h3><p class="num" id="parcel-count">—</p></div>
      <div class="card stat"><h3>Issued payments</h3><p class="num" id="issued-count">—</p></div>
      <div class="card stat"><h3>Total fees (kips)</h3><p class="num" id="fee-total">—</p></div>
    </section>

    <div class="grid">
      <div class="card"><h3>Pending scans to confirm</h3><p class="sub" id="scan-summary">Loading…</p></div>
      <div class="card"><h3>Zone rates (locational, flat)</h3><p class="sub">Kip per m². Rate depends only on district + road category.</p></div>
    </div>
  </main>
</div>
<script>
const TOKEN = '<?= e($_SESSION['admin_token'] ?? '') ?>';
async function api(path, opts) {
  const r = await fetch(path, {
    ...opts,
    headers: { 'Content-Type': 'application/json', ...(opts.headers || {}), 'Authorization': 'Bearer ' + TOKEN },
  });
  const j = await r.json();
  return j;
}
(async () => {
  const [scanResp, parcelResp, payResp] = await Promise.all([
    api('/api/v1/scans.php?status=pending'),
    api('/api/v1/parcels.php'),
    api('/api/v1/payments.php?status=ISSUED'),
  ]);
  document.getElementById('scan-count').textContent = (scanResp.data?.scans?.length ?? 0);
  document.getElementById('parcel-count').textContent = (parcelResp.data?.count ?? 0);
  const issued = payResp.data ?? [];
  document.getElementById('issued-count').textContent = issued.length;
  const total = issued.reduce((s, p) => s + (parseFloat(p.fee_total) || 0), 0);
  document.getElementById('fee-total').textContent = total.toLocaleString('en-LK') + ' kip';

  const sc = document.getElementById('scan-summary');
  if (!scanResp.data || scanResp.data.scans.length === 0) {
    sc.textContent = 'No pending scans right now.';
  } else {
    sc.innerHTML = '<b>' + scanResp.data.scans.length + '</b> pending scan(s) to review.';
  }
})();
</script>
</body>
</html>
