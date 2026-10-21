<?php
// public/admin/index.php — dashboard
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
$role = $user['role'];
?>
<!DOCTYPE html>
<html lang="<?= i18n_current() ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= te('Dashboard') ?> — Land Fee MVP</title>
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
    <h1><?= te('Dashboard') ?></h1>
    <?php if ($role === 'admin'): ?><p class="sub"><?= te('Province officer back-office. Managed by the officer role for day-to-day work.') ?></p><?php endif; ?>

    <section class="cards">
      <div class="card stat"><h3><?= te('Pending scans') ?></h3><p class="num" id="scan-count">—</p></div>
      <div class="card stat"><h3><?= te('Parcels') ?></h3><p class="num" id="parcel-count">—</p></div>
      <div class="card stat"><h3><?= te('Issued payments') ?></h3><p class="num" id="issued-count">—</p></div>
      <div class="card stat"><h3><?= te('Total fees (kips)') ?></h3><p class="num" id="fee-total">—</p></div>
    </section>

    <div class="grid">
      <div class="card"><h3><?= te('Pending scans to confirm') ?></h3><p class="sub" id="scan-summary"><?= te('Loading…') ?></p></div>
      <div class="card"><h3><?= te('Zone rates (locational, flat)') ?></h3><p class="sub"><?= te('Kip per m². Rate depends only on district + road category.') ?></p></div>
    </div>
  </main>
</div>
<script>
const TOKEN = '<?= e($_SESSION['admin_token'] ?? '') ?>';
const I18N = <?= json_encode([
    'noScans'      => t('No pending scans right now.'),
    'pendingScans' => t('{n} pending scan(s) to review.'),
    'kip'          => t('kip'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
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
  document.getElementById('fee-total').textContent = total.toLocaleString('en-US') + ' ' + I18N.kip;

  const sc = document.getElementById('scan-summary');
  if (!scanResp.data || scanResp.data.scans.length === 0) {
    sc.textContent = I18N.noScans;
  } else {
    sc.textContent = I18N.pendingScans.replace('{n}', scanResp.data.scans.length);
  }
})();
</script>
</body>
</html>
