<?php
// public/admin/scans.php — scan review queue: upload OCR + confirm/edit parcel
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
$role = $user['role'];

$scanId = isset($_GET['scan_id']) ? (int)$_GET['scan_id'] : 0;
$editId = $scanId; // same as scan id
$scan = null; $parcel = null; $errors = []; $success = '';

// --- POST: confirm/edit parcel ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['confirm_parcel'])) {
    $body = [
        'parcel' => [
            'parcel_no' => $_POST['parcel_no'] ?? '',
            'sub_parcel_no' => $_POST['sub_parcel_no'] ?? '',
            'area_sqm' => $_POST['area_sqm'] ?? '',
            'road_category' => $_POST['road_category'] ?? '',
            'village_id' => $_POST['village_id'] ?? '',
            'district_id' => $_POST['district_id'] ?? '',
            'status' => $_POST['status'] ?? 'confirmed',
        ],
    ];
    $ch = curl_init($api_base . '/scans/' . $editId . '/confirm.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $_SESSION['admin_token']]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    if (!empty($data['ok'])) {
        $success = 'Parcel confirmed ID: ' . $data['data']['parcel_id'];
        $scan = null; $editId = 0;
    } else {
        $errors = $data['error']['fields'] ?? [$data['error']['message'] ?? 'Failed to confirm'];
    }
}

// --- Load the scan data for the form ---
if ($editId > 0) {
    $ch = curl_init($api_base . '/scans/' . $editId . '/confirm.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $_SESSION['admin_token']]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp, true);
    if (!empty($data['ok'])) {
        $scan = $data['data'];
        $parcel_input = $scan['parcel'] ?? [];
    } else {
        $errors = $data['error']['message'] ?? 'Scan not found';
        $editId = 0;
    }
}

// --- Fetch districts/villages ---
$districts = $villages = [];
if ($editId > 0 && !empty($scan['district_id'])) {
    $ch = curl_init($api_base . '/districts.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch); curl_close($ch);
    $districts = json_decode($resp, true)['data'] ?? [];
    $ch = curl_init($api_base . '/villages.php?district_id=' . $scan['district_id']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch); curl_close($ch);
    $villages = json_decode($resp, true)['data'] ?? [];
}
?>
<!DOCTYPE html>
<html lang="lo">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Scan Review — Land Fee MVP</title>
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
    <h1>Scan Review</h1>
    <p class="sub">Upload a land title scan → OCR → confirm or edit parcel data</p>

    <?php if ($success): ?><div class="form-box" style="border-color:#2e7d32; background:#eaf4ea;"><?= e($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="form-box" style="border-color:#c62828; background:#fdecea;"><?= e($e) ?></div><?php endforeach; ?>

    <?php if (!$scan): ?>
      <div class="form-box">
        <h3>Upload a Land Title Scan</h3>
        <form method="post" enctype="multipart/form-data">
          <div class="form-group">
            <label>Land title image (JPG/PNG)</label>
            <input type="file" name="image" accept="image/*" required>
            <p class="sub" style="font-size:12px;">OCR reads the title to detect district, village, area, parcel number. You confirm and correct these after.</p>
          </div>
          <button type="submit" class="btn btn-green">Upload &amp; Process</button>
        </form>
      </div>
    <?php else: ?>
      <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 16px;">
        <div>
          <div class="form-box" style="border:2px solid #1b5e20;">
            <h3>Title scan</h3>
            <img src="<?= e($scan['image_path']) ?>" style="width:100%; max-height:340px; object-fit:contain; border-radius:4px; background:#fff;" alt="Scan">
          </div>
        </div>
        <div>
          <div class="form-box">
            <h3>OCR extracted text</h3>
            <div class="ocr-box"><?= e($scan['ocr_text'] ?? $scan['ocr_extracted']['raw_text'] ?? 'No text extracted.') ?></div>
          </div>
        </div>
      </div>

      <div class="form-box">
        <h3>Confirm / Edit Parcel Data</h3>
        <form method="post">
          <input type="hidden" name="confirm_parcel" value="1">
          <div class="form-grid">
            <div class="form-group">
              <label>Parcel number <span class="sub" style="font-weight:400;">(from title, or leave blank to let OCR suggest)</span></label>
              <input name="parcel_no" value="<?= e($parcel_input['parcel_no'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label>Sub-parcel number</label>
              <input name="sub_parcel_no" value="<?= e($parcel_input['sub_parcel_no'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label>Area (m²)</label>
              <input name="area_sqm" type="number" step="0.01" value="<?= e($parcel_input['area_sqm'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label>Road category</label>
              <select name="road_category">
                <option value="">-- select --</option>
                <option value="main" <?= ($parcel_input['road_category'] ?? '') === 'main' ? 'selected' : '' ?>>Main road</option>
                <option value="branch" <?= ($parcel_input['road_category'] ?? '') === 'branch' ? 'selected' : '' ?>>Branch road</option>
                <option value="alley" <?= ($parcel_input['road_category'] ?? '') === 'alley' ? 'selected' : '' ?>>Alley</option>
                <option value="none" <?= ($parcel_input['road_category'] ?? '') === 'none' ? 'selected' : '' ?>>None</option>
              </select>
            </div>
            <div class="form-group">
              <label>District</label>
              <select name="district_id" id="district-select">
                <option value="">-- select --</option>
                <?php foreach ($districts as $d): ?>
                  <option value="<?= $d['id'] ?>" <?= ($scan['district_id'] ?? '') == $d['id'] ? 'selected' : '' ?>><?= e($d['name_lo']) ?> <?= e($d['name_en'] ?: '') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Village</label>
              <select name="village_id" id="village-select">
                <option value="">-- select --</option>
                <?php foreach ($villages as $v): ?>
                  <option value="<?= $v['id'] ?>" <?= ($parcel_input['village_id'] ?? '') == $v['id'] ? 'selected' : '' ?>><?= e($v['name_lo']) ?> <?= e($v['name_en'] ?: '') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Status</label>
              <select name="status">
                <option value="confirmed" <?= ($parcel_input['status'] ?? 'confirmed') === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                <option value="active" <?= ($parcel_input['status'] ?? 'confirmed') === 'active' ? 'selected' : '' ?>>Active</option>
              </select>
            </div>
          </div>
          <div class="modal-actions" style="justify-content:flex-start;">
            <button type="submit" class="btn btn-green">Confirm &amp; Create Parcel</button>
            <a href="index.php" class="btn btn-gold">New scan</a>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </main>
</div>
<script>
const TOKEN = '<?= e($_SESSION['admin_token'] ?? '') ?>';
// district -> villages
const districtSelect = document.getElementById('district-select');
const villageSelect = document.getElementById('village-select');
if (districtSelect) {
  districtSelect.addEventListener('change', async () => {
    const val = districtSelect.value;
    villageSelect.innerHTML = '<option value="">-- select --</option>';
    if (!val) return;
    const r = await fetch('api/v1/villages.php?district_id=' + val, {
      headers: { 'Authorization': 'Bearer ' + TOKEN }
    });
    const j = await r.json();
    (j.data || []).forEach(v => {
      const o = document.createElement('option'); o.value = v.id; o.textContent = v.name_lo + (v.name_en ? ' (' + v.name_en + ')' : ''); villageSelect.appendChild(o);
    });
  });
}
</script>
</body>
</html>
