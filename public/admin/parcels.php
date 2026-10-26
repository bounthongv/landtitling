<?php
// public/admin/parcels.php — parcel registry (search/filter/list)
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
$role = $user['role'];

$district_id = isset($_GET['district_id']) ? (int)$_GET['district_id'] : 0;
$village_id   = isset($_GET['village_id']) ? (int)$_GET['village_id'] : 0;
$province    = $_GET['province'] ?? '';
$parcel_no   = $_GET['parcel_no'] ?? '';
$status      = $_GET['status'] ?? '';

$url = 'parcels.php';
if ($district_id) { $url .= '?district_id=' . $district_id; }
if ($province)    { $url .= ($district_id ? '&' : '?') . 'province=' . urlencode($province); }
if ($parcel_no)   { $url .= ($district_id || $province ? '&' : '?') . 'parcel_no=' . urlencode($parcel_no); }
if ($status)      { $url .= ($district_id || $province || $parcel_no ? '&' : '?') . 'status=' . urlencode($status); }

$parcels = [];
$count = 0;
$d = api_call('GET', $url, null, $_SESSION['admin_token']);
if (!empty($d['ok'])) { $parcels = $d['data']['parcels'] ?? []; $count = $d['data']['count'] ?? 0; }

// districts for filter
$districts = api_call('GET', 'districts.php')['data'] ?? [];
$provinces = [];
foreach ($districts as $drow) {
    $p = $drow['province_name'] ?? $drow['province'] ?? '';
    if ($p && !in_array($p, $provinces, true)) { $provinces[] = $p; }
}
sort($provinces);

// Province filter on the fetched set (server-side province filter could be added
// to the API later; for now the result set is capped at 200 by the API).
if ($province !== '') {
    $parcels = array_values(array_filter($parcels, function($p) use ($province) {
        return ($p['province'] ?? '') === $province;
    }));
    $count = count($parcels);
}

// Village filter on the fetched set.
if ($village_id > 0) {
    $parcels = array_values(array_filter($parcels, function($p) use ($village_id) {
        return (int)($p['village_id'] ?? 0) === $village_id;
    }));
    $count = count($parcels);
}

// Batch-fetch current zone rates so we can show Rate (kip/m²) and Tax (kip)
// without an N+1 query per parcel.
$pdo = db_connect();
$zoneRates = [];   // "ZONE:ROAD" => rate_per_sqm
$zoneFallback = []; // ZONE => rate_per_sqm (legacy zones with no tiers)

if (!empty($parcels)) {
    $zoneCodes = [];
    foreach ($parcels as $p) {
        $zc = strtoupper(trim((string)($p['zone_code'] ?? '')));
        if ($zc && !in_array($zc, $zoneCodes, true)) { $zoneCodes[] = $zc; }
    }
    if ($zoneCodes) {
        $in = implode(',', array_fill(0, count($zoneCodes), '?'));
        // Current tier rate per (zone_code, road_category)
        $st = $pdo->prepare("
            SELECT DISTINCT ON (t.zone_code, t.road_category) t.zone_code, t.road_category, t.rate_per_sqm
            FROM zone_rate_tiers t
            WHERE t.zone_code IN ($in)
            ORDER BY t.zone_code, t.road_category, t.valid_from DESC
        ");
        $st->execute($zoneCodes);
        foreach ($st->fetchAll() as $r) {
            $key = strtoupper($r['zone_code']) . ':' . strtolower($r['road_category']);
            $zoneRates[$key] = (float)$r['rate_per_sqm'];
        }
        // Legacy fallback: zones.rate_per_sqm for zones with no tiers
        $st2 = $pdo->prepare("SELECT code, rate_per_sqm FROM zones WHERE code IN ($in) AND is_active = TRUE");
        $st2->execute($zoneCodes);
        foreach ($st2->fetchAll() as $z) {
            $zoneFallback[strtoupper($z['code'])] = (float)$z['rate_per_sqm'];
        }
    }
}

foreach ($parcels as &$p) {
    $zc = strtoupper(trim((string)($p['zone_code'] ?? '')));
    $rc = strtolower(trim((string)($p['road_category'] ?? '')));
    $key = $zc . ':' . $rc;
    $rate = $zoneRates[$key] ?? ($zoneFallback[$zc] ?? 0.0);
    $p['_rate_per_sqm'] = $rate;
    $p['_tax_amount']   = round($rate * (float)($p['area_sqm'] ?? 0), 2);
}
unset($p);

// Road category Lao mapping (same as fee.php)
$roadMap = [
    'main'      => 'ທາງຫຼັກ',
    'connector' => 'ທາງສາຍເຊືອມຕໍ່',
    'branch'    => 'ທາງສາຍແຍກ',
    'alley'     => 'ທາງສາຍແຍກ',
    'none'      => 'ທາງດັງເດີມ / ບໍ່ມີທາງ',
];
?>
<!DOCTYPE html>
<html lang="<?= i18n_current() ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= te('Parcels') ?> — Land Fee MVP</title>
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
    <h1><?= te('Parcels') ?></h1>
    <p class="sub"><?= te('Registry of land parcels. Filter by province, district, parcel no, status.') ?></p>
    <div class="form-box">
      <form method="get" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px;">
        <div class="form-group"><label><?= te('Province') ?></label>
          <select name="province"><option value=""><?= te('-- all --') ?></option><?php foreach ($provinces as $p): ?><option value="<?= e($p) ?>" <?= $province === $p ? 'selected' : '' ?>><?= e($p) ?></option><?php endforeach; ?></select>
        </div>
        <div class="form-group"><label><?= te('District') ?></label><select name="district_id" id="parcels-district"><option value=""><?= te('-- all --') ?></option><?php foreach ($districts as $d): ?><option value="<?= $d['id'] ?>" <?= $district_id == $d['id'] ? 'selected' : '' ?>><?= e($d['name_lo']) ?><?= !empty($d['province_name']) ? ' — ' . e($d['province_name']) : '' ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label><?= te('Village') ?></label><select name="village_id" id="parcels-village" disabled><option value=""><?= te('-- select district first --') ?></option></select></div>
        <div class="form-group"><label><?= te('Status') ?></label>
          <select name="status"><option value=""><?= te('-- all --') ?></option><option value="confirmed" <?= $status === 'confirmed' ? 'selected' : '' ?>><?= te('Confirmed') ?></option><option value="active" <?= $status === 'active' ? 'selected' : '' ?>><?= te('Active') ?></option><option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>><?= te('Draft') ?></option></select></div>
        <div class="form-group"><label><?= te('Parcel no') ?></label><input name="parcel_no" value="<?= e($parcel_no) ?>"></div>
        <div class="form-group"><button class="btn btn-green" type="submit"><?= te('Filter') ?></button></div>
      </form>
    </div>

    <?php if ($count === 0): ?><p class="empty"><?= te('No parcels match the filter.') ?></p><?php else: ?>
    <table style="margin-top:8px;">
      <thead>
        <tr>
          <th><?= te('Parcel No') ?></th>
          <th><?= te('Province') ?></th>
          <th><?= te('District') ?></th>
          <th><?= te('Village') ?></th>
          <th><?= te('Area (m²)') ?></th>
          <th><?= te('Valuation Zone') ?></th>
          <th><?= te('Road category') ?></th>
          <th><?= te('Rate') ?></th>
          <th><?= te('Tax') ?></th>
          <th><?= te('Status') ?></th>
          <th><?= te('Updated') ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($parcels as $p): ?>
        <tr>
          <td><a href="<?= $base ?>/parcel.php?id=<?= (int)$p['id'] ?>"><?= e($p['parcel_no']) ?></a></td>
          <td><?= e($p['province'] ?? '') ?></td>
          <td><?= e($p['district_name']) ?></td>
          <td><?= e($p['village_name']) ?></td>
          <td><?= number_format((float)$p['area_sqm'], 2) ?></td>
          <td><?= e($p['zone_code'] ?? '—') ?></td>
          <td><?= e($roadMap[strtolower((string)($p['road_category'] ?? ''))] ?? ucfirst($p['road_category'] ?? '')) ?></td>
          <td><?= $p['_rate_per_sqm'] > 0 ? number_format((float)$p['_rate_per_sqm'], 0) . ' ກີບ' : '—' ?></td>
          <td><?= $p['_tax_amount'] > 0 ? number_format((float)$p['_tax_amount'], 0) . ' ກີບ' : '—' ?></td>
          <td><span class="pill pill-<?= e($p['status']) ?>"><?= e(t(ucfirst($p['status']))) ?></span></td>
          <td><?= e($p['updated_at'] ?? $p['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="sub" style="margin-top:8px;"><?= te('{n} parcel(s)', ['n' => $count]) ?></p>
    <?php endif; ?>
  </main>
</div>

<script>
(function(){
  const districtSel = document.getElementById('parcels-district');
  const villageSel  = document.getElementById('parcels-village');
  if (!districtSel || !villageSel) return;

  // If a district is already selected on load, load its villages immediately
  // so the current village_id filter is reflected in the dropdown.
  if (districtSel.value) { loadVillages(districtSel.value, false); }

  districtSel.addEventListener('change', function() {
    loadVillages(this.value || '', true);
  });

  async function loadVillages(districtId, reset) {
    if (!districtId) {
      villageSel.innerHTML = '<option value=""><?= e(te('-- select district first --')) ?></option>';
      villageSel.disabled = true;
      return;
    }
    villageSel.innerHTML = '<option value=""><?= e(te('-- loading --')) ?></option>';
    villageSel.disabled = false;
    try {
      const res = await fetch('/api/v1/villages.php?district_id=' + districtId + '&limit=500');
      const json = await res.json();
      const list = (json && json.data) ? json.data : [];
      const opts = '<option value=""><?= e(te('-- all --')) ?></option>' +
        list.map(v => '<option value="' + v.id + '">' + (v.name_lo || v.name) + '</option>').join('');
      villageSel.innerHTML = opts;
      // Restore selected village_id from query string if present
      const urlParams = new URLSearchParams(window.location.search);
      const vid = urlParams.get('village_id');
      if (vid && list.some(v => String(v.id) === String(vid))) {
        villageSel.value = vid;
      }
    } catch (e) {
      villageSel.innerHTML = '<option value=""><?= e(te('-- error loading --')) ?></option>';
    }
  }
})();
</script>
</body>
</html>
