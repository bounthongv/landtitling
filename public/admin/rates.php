<?php
// public/admin/rates.php — zone rate table editor (flat, location-based)
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
$role = $user['role'];

$rates = [];
$d = api_call('GET', 'rates.php', null, $_SESSION['admin_token']);
if (!empty($d['ok'])) { $rates = $d['data']['rates'] ?? []; }

// districts for the editor list
$districts = [];
$districts = api_call('GET', 'districts.php')['data'] ?? [];

// build lookup by district_id
$ratesByDistrict = [];
foreach ($rates as $r) {
    $ratesByDistrict[$r['district_id']][$r['road_category']] = $r['rate_per_sqm'];
}

$editDistrict = isset($_GET['district_id']) ? (int)$_GET['district_id'] : 0;
$editCategory = $_GET['road_category'] ?? '';
$editRate = ($editDistrict && $editCategory) ? ($ratesByDistrict[$editDistrict][$editCategory] ?? 0) : 0;
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save_rate'])) {
    $body = [
        'district_id' => (int)($_POST['district_id'] ?? 0),
        'road_category' => $_POST['road_category'] ?? '',
        'rate_per_sqm' => (float)($_POST['rate_per_sqm'] ?? 0),
        'note' => $_POST['note'] ?? '',
    ];
    $d = api_call('PUT', 'rates.php', $body, $_SESSION['admin_token']);
    if (!empty($d['ok'])) {
        $msg = 'Rate saved: ' . number_format($body['rate_per_sqm'], 0) . ' kip/m²';
        $editDistrict = $body['district_id'];
        $editCategory = $body['road_category'];
        $editRate = $body['rate_per_sqm'];
    } else {
        $msg = $d['error']['message'] ?? 'Save failed';
    }
}
?>
<!DOCTYPE html>
<html lang="lo">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Zone Rates — Land Fee MVP</title>
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
    <h1>Zone Rates (flat, location-based)</h1>
    <p class="sub">Fee = rate × area. Rate depends only on district + road category. Flat across transaction types and land use.</p>

    <div class="grid">
      <div class="card">
        <?php if ($msg): ?><div style="color:#1b5e20; background:#eaf4ea; padding:8px 12px; border-radius:4px; margin-bottom:10px;"><?= e($msg) ?></div><?php endif; ?>
        <div class="form-box">
          <form method="post">
            <input type="hidden" name="save_rate" value="1">
            <div class="form-group">
              <label>Edit rate for district</label>
              <select name="district_id" id="rate-district">
                <option value="">-- select district --</option>
                <?php foreach ($districts as $d): ?>
                  <option value="<?= $d['id'] ?>" <?= $editDistrict == $d['id'] ? 'selected' : '' ?>><?= e($d['name_lo']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Road category</label>
              <select name="road_category" id="rate-cat">
                <option value="">-- select --</option>
                <option value="main" <?= $editCategory === 'main' ? 'selected' : '' ?>>Main road</option>
                <option value="branch" <?= $editCategory === 'branch' ? 'selected' : '' ?>>Branch road</option>
                <option value="alley" <?= $editCategory === 'alley' ? 'selected' : '' ?>>Alley</option>
                <option value="none" <?= $editCategory === 'none' ? 'selected' : '' ?>>None</option>
              </select>
            </div>
            <div class="form-group">
              <label>Rate per m² (kip)</label>
              <input name="rate_per_sqm" type="number" step="1000" value="<?= $editRate ?>">
              <p class="sub" style="font-size:12px;">Band: 80,000–180,000 kip/m²</p>
            </div>
            <div class="form-group">
              <label>Note (optional)</label>
              <input name="note">
            </div>
            <div class="modal-actions" style="justify-content:flex-start;">
              <button class="btn btn-green" type="submit">Save rate</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card" style="overflow-x:auto;">
        <table>
          <thead><tr><th>District</th><th>Main</th><th>Branch</th><th>Alley</th><th>None</th></tr></thead>
          <tbody>
            <?php foreach ($districts as $d): ?>
            <tr>
              <td><?= e($d['name_lo']) ?></td>
              <td><?= $ratesByDistrict[$d['id']]['main'] ?? '—' ?></td>
              <td><?= $ratesByDistrict[$d['id']]['branch'] ?? '—' ?></td>
              <td><?= $ratesByDistrict[$d['id']]['alley'] ?? '—' ?></td>
              <td><?= $ratesByDistrict[$d['id']]['none'] ?? '—' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <table style="margin-top:8px;">
        <thead><tr><th>ID</th><th>District</th><th>Category</th><th>Rate (kip/m²)</th><th>Valid from</th><th>Updated</th></tr></thead>
        <tbody>
          <?php foreach ($rates as $r): ?>
          <tr>
            <td><?= $r['id'] ?></td>
            <td><?= e($r['district_name']) ?></td>
            <td><?= e(ucfirst($r['road_category'])) ?></td>
            <td style="font-weight:600;"><?= number_format((float)$r['rate_per_sqm'], 0) ?></td>
            <td><?= e($r['valid_from']) ?></td>
            <td><?= e($r['updated_at'] ?? '-') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </main>
</div>
</body>
</html>
