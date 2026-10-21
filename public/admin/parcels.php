<?php
// public/admin/parcels.php — parcel registry (search/filter/list)
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
$role = $user['role'];

$district_id = isset($_GET['district_id']) ? (int)$_GET['district_id'] : 0;
$parcel_no = $_GET['parcel_no'] ?? '';
$status = $_GET['status'] ?? '';

$url = 'parcels.php';
if ($district_id) { $url .= '?district_id=' . $district_id; }
if ($parcel_no) { $url .= ($district_id ? '&' : '?') . 'parcel_no=' . urlencode($parcel_no); }
if ($status) { $url .= ($district_id || $parcel_no ? '&' : '?') . 'status=' . $status; }

$parcels = [];
$count = 0;
$d = api_call('GET', $url, null, $_SESSION['admin_token']);
if (!empty($d['ok'])) { $parcels = $d['data']['parcels'] ?? []; $count = $d['data']['count'] ?? 0; }

// districts for filter
$districts = [];
$districts = api_call('GET', 'districts.php')['data'] ?? [];
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
    <div class="brand"><?= te("Land Tax Collection System") ?></div>
    <div class="side-nav"><?= admin_nav($role) ?></div>
    <?= admin_side_foot() ?>
  </aside>
  <main class="main">
    <h1><?= te('Parcels') ?></h1>
    <p class="sub"><?= te('Registry of land parcels (from confirmed scans). Filter by district, parcel no, status.') ?></p>
    <div class="form-box">
      <form method="get" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px;">
        <div class="form-group"><label><?= te('District') ?></label><select name="district_id"><option value=""><?= te('-- all --') ?></option><?php foreach ($districts as $d): ?><option value="<?= $d['id'] ?>" <?= $district_id == $d['id'] ? 'selected' : '' ?>><?= e($d['name_lo']) ?><?= !empty($d['province_name']) ? ' — ' . e($d['province_name']) : '' ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label><?= te('Parcel no') ?></label><input name="parcel_no" value="<?= e($parcel_no) ?>"></div>
        <div class="form-group"><label><?= te('Status') ?></label>
          <select name="status"><option value=""><?= te('-- all --') ?></option><option value="confirmed" <?= $status === 'confirmed' ? 'selected' : '' ?>><?= te('Confirmed') ?></option><option value="active" <?= $status === 'active' ? 'selected' : '' ?>><?= te('Active') ?></option><option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>><?= te('Draft') ?></option></select></div>
        <div class="form-group"><button class="btn btn-green" type="submit"><?= te('Filter') ?></button></div>
      </form>
    </div>

    <?php if ($count === 0): ?><p class="empty"><?= te('No parcels match the filter.') ?></p><?php else: ?>
    <table style="margin-top:8px;">
      <thead><tr><th><?= te('Parcel No') ?></th><th><?= te('District') ?></th><th><?= te('Village') ?></th><th><?= te('Area (m²)') ?></th><th><?= te('Road') ?></th><th><?= te('Status') ?></th><th><?= te('Updated') ?></th></tr></thead>
      <tbody>
        <?php foreach ($parcels as $p): ?>
        <tr>
          <td><a href="<?= $base ?>/parcel.php?id=<?= (int)$p['id'] ?>"><?= e($p['parcel_no']) ?></a></td>
          <td><?= e($p['district_name']) ?></td>
          <td><?= e($p['village_name']) ?></td>
          <td><?= number_format((float)$p['area_sqm'], 2) ?></td>
          <td><?= e(t(ucfirst($p['road_category']))) ?></td>
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
</body>
</html>
