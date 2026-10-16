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
<html lang="lo">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Parcels — Land Fee MVP</title>
<?php require __DIR__ . '/style.php'; ?>
</head>
<body>
<div class="app">
  <aside class="side">
    <div class="brand">ພະລັງງານການຊຳລະທຳນຽມ</div>
    <div class="side-nav"><?= admin_nav($role) ?></div>
    <div class="side-foot">Province: Savannakhet</div>
  </aside>
  <main class="main">
    <h1>Parcels</h1>
    <p class="sub">Registry of land parcels (from confirmed scans). Filter by district, parcel no, status.</p>
    <div class="form-box">
      <form method="get" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px;">
        <div class="form-group"><label>District</label><select name="district_id"><option value="">-- all --</option><?php foreach ($districts as $d): ?><option value="<?= $d['id'] ?>" <?= $district_id == $d['id'] ? 'selected' : '' ?>><?= e($d['name_lo']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>Parcel no</label><input name="parcel_no" value="<?= e($parcel_no) ?>"></div>
        <div class="form-group"><label>Status</label>
          <select name="status"><option value="">-- all --</option><option value="confirmed" <?= $status === 'confirmed' ? 'selected' : '' ?>>Confirmed</option><option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option><option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft</option></select></div>
        <div class="form-group"><button class="btn btn-green" type="submit">Filter</button></div>
      </form>
    </div>

    <?php if ($count === 0): ?><p class="empty">No parcels match the filter.</p><?php else: ?>
    <table style="margin-top:8px;">
      <thead><tr><th>Parcel No</th><th>District</th><th>Village</th><th>Area (m²)</th><th>Road</th><th>Status</th><th>Updated</th></tr></thead>
      <tbody>
        <?php foreach ($parcels as $p): ?>
        <tr>
          <td><a href="<?= $base ?>/parcel.php?id=<?= (int)$p['id'] ?>"><?= e($p['parcel_no']) ?></a></td>
          <td><?= e($p['district_name']) ?></td>
          <td><?= e($p['village_name']) ?></td>
          <td><?= number_format((float)$p['area_sqm'], 2) ?></td>
          <td><?= e(ucfirst($p['road_category'])) ?></td>
          <td><span class="pill pill-<?= e($p['status']) ?>"><?= e(ucfirst($p['status'])) ?></span></td>
          <td><?= e($p['updated_at'] ?? $p['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="sub" style="margin-top:8px;"><?= $count ?> parcel(s)</p>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
