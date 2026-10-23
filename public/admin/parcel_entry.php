<?php
// public/admin/parcel_entry.php — unified parcel entry page (scan, manual, import)
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
$role = $user['role'];
$allowedRoles = ['officer', 'admin', 'manager'];
if (!in_array($role, $allowedRoles, true)) { http_response_code(403); exit(t('Insufficient permissions.')); }
$token = $_SESSION['admin_token'];
?>
<!DOCTYPE html>
<html lang="<?= i18n_current() ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= te('Parcel Entry') ?> - Land Fee MVP</title>
<?php require __DIR__ . '/style.php'; ?>
<style>
  .entry-page { max-width: 1200px; margin: 0 auto; padding: 20px; }
  .entry-tabs { display: flex; gap: 8px; margin-bottom: 20px; }
  .entry-tabs button { padding: 10px 20px; border: 1px solid #ccc; background: #fff; cursor: pointer; }
  .entry-tabs button.active { background: #4a7c59; color: #fff; border-color: #4a7c59; }
  .entry-tools { background: #f5f5f5; padding: 15px; margin-bottom: 20px; border-radius: 4px; }
  .entry-tools button { margin-right: 10px; margin-bottom: 10px; }
  .entry-form { background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px; }
  .form-section { margin-bottom: 25px; padding-bottom: 15px; border-bottom: 1px solid #eee; }
  .form-section h3 { margin: 0 0 15px 0; color: #333; }
  .form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 15px; }
  .form-group { margin-bottom: 0; }
  .form-group label { display: block; font-size: 13px; margin-bottom: 4px; font-weight: 500; }
  .form-group input, .form-group select { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 3px; box-sizing: border-box; }
  .form-actions { display: flex; gap: 10px; margin-top: 20px; }
  .preview-box { background: #fafafa; padding: 10px; margin-top: 15px; font-size: 12px; }
  #scan-preview { max-width: 300px; max-height: 300px; margin: 10px 0; display: none; }
  #ocr-result { background: #e8f5e9; padding: 10px; margin-top: 10px; border-radius: 3px; font-size: 12px; max-height: 150px; overflow-y: auto; }
  .owner-row { display: flex; gap: 10px; margin-bottom: 10px; align-items: center; }
  .owner-row input { flex: 1; }
  .owner-row label { flex: 0 0 150px; }
  .row-buttons { display: flex; gap: 5px; }
  .btn-sm { padding: 4px 8px; font-size: 12px; }
  .btn-remove { background: #d32f2f; color: #fff; border: none; }
  .btn-add { background: #4a7c59; color: #fff; }
</style>
</head>
<body>
<div class="app">
  <aside class="side">
    <?= admin_side_top() ?>
    <?= admin_nav($role) ?>
    <?= admin_side_foot() ?>
  </aside>
  <main class="main">
    <h1><?= te('Parcel Entry') ?></h1>
    <p class="sub"><?= te('Add parcels via Scan, Manual, or Import') ?></p>
    
    <div class="entry-page">
      <!-- Tabs -->
      <div class="entry-tabs">
        <button class="active" data-tab="manual"><?= te('Manual') ?></button>
        <button data-tab="scan"><?= te('Scan') ?></button>
        <button data-tab="import"><?= te('Import') ?></button>
      </div>
      
      <!-- Manual Entry -->
      <div class="entry-form" data-content="manual">
        <h3><?= te('Parcel Information') ?></h3>
        <form id="parcel-form" method="post">
          <div class="form-grid">
            <div class="form-group">
              <label><?= te('District') ?></label>
              <select name="district_id" id="district-select" required>
                <option value=""><?= te('-- select district --') ?></option>
              </select>
            </div>
            <div class="form-group">
              <label><?= te('Village') ?></label>
              <select name="village_id" id="village-select" required>
                <option value=""><?= te('-- select --') ?></option>
              </select>
            </div>
            <div class="form-group">
              <label><?= te('Parcel No') ?></label>
              <input name="parcel_no" type="text" required>
            </div>
            <div class="form-group">
              <label><?= te('Sub-parcel No') ?></label>
              <input name="sub_parcel_no" type="text">
            </div>
            <div class="form-group">
              <label><?= te('Area (m²)') ?></label>
              <input name="area_sqm" type="number" step="0.01" required>
            </div>
            <div class="form-group">
              <label><?= te('Zone Code') ?></label>
              <input name="zone_code" type="text">
            </div>
            <div class="form-group">
              <label><?= te('Road Category') ?></label>
              <select name="road_category">
                <option value=""><?= te('-- select --') ?></option>
                <option value="main"><?= te('Main') ?></option>
                <option value="branch"><?= te('Branch') ?></option>
                <option value="alley"><?= te('Alley') ?></option>
                <option value="none"><?= te('None') ?></option>
              </select>
            </div>
            <div class="form-group">
              <label><?= te('Title Type') ?></label>
              <select name="title_type">
                <option value=""><?= te('-- select --') ?></option>
                <option value="land_title"><?= te('Land title') ?></option>
                <option value="survey_cert"><?= te('Survey certificate') ?></option>
                <option value="concession"><?= te('Concession') ?></option>
                <option value="other"><?= te('Other') ?></option>
              </select>
            </div>
            <div class="form-group">
              <label><?= te('Title No') ?></label>
              <input name="title_no" type="text">
            </div>
            <div class="form-group">
              <label><?= te('Book No') ?></label>
              <input name="book_no" type="text">
            </div>
            <div class="form-group">
              <label><?= te('Volume') ?></label>
              <input name="volume_no" type="text">
            </div>
            <div class="form-group">
              <label><?= te('Page') ?></label>
              <input name="page_no" type="text">
            </div>
            <div class="form-group">
              <label><?= te('Land Use') ?></label>
              <select name="land_use_class">
                <option value=""><?= te('-- select --') ?></option>
                <option value="residential"><?= te('Residential') ?></option>
                <option value="agricultural"><?= te('Agricultural') ?></option>
                <option value="commercial"><?= te('Commercial') ?></option>
                <option value="other"><?= te('Other') ?></option>
              </select>
            </div>
            <div class="form-group">
              <label><?= te('Issued Date') ?></label>
              <input name="issued_date" type="date">
            </div>
          </div>
          
          <h3 style="margin-top:30px;"><?= te('Owner(s)') ?></h3>
          <div id="owners-container">
            <div class="owner-row" data-owner="1">
              <label><?= te('Full Name') ?></label>
              <input type="text" name="owners[0][full_name]" required>
              <label><?= te('ID No') ?></label>
              <input type="text" name="owners[0][id_number]">
              <label><?= te('Phone') ?></label>
              <input type="text" name="owners[0][phone]">
            </div>
          </div>
          <button type="button" class="btn-sm btn-add" id="add-owner"><?= te('+ Add Owner') ?></button>
          
          <div class="form-actions">
            <button type="submit" class="btn btn-green"><?= te('Save') ?></button>
            <button type="button" class="btn btn-red" id="clear-form"><?= te('Clear') ?></button>
          </div>
        </form>
        <div id="save-result" class="preview-box" style="display:none;"></div>
      </div>
      
      <!-- Scan Entry -->
      <div class="entry-form" data-content="scan" style="display:none;">
        <h3><?= te('Upload Title Scan') ?></h3>
        <div id="scan-upload">
          <input type="file" id="scan-file" accept="image/*">
          <button type="button" class="btn btn-green" id="process-ocr"><?= te('Process OCR') ?></button>
        </div>
        <img id="scan-preview">
        <div id="ocr-result" style="display:none;"></div>
        <div style="margin-top:20px;">
          <button type="button" class="btn btn-green" id="apply-ocr"><?= te('Apply OCR Result') ?></button>
        </div>
      </div>
      
      <!-- Import Entry -->
      <div class="entry-form" data-content="import" style="display:none;">
        <div class="entry-tools">
          <button type="button" class="btn btn-green" id="download-template"><?= te('Download Template') ?></button>
          <button type="button" class="btn btn-green" id="open-import-modal"><?= te('Import CSV') ?></button>
        </div>
        <div id="import-preview" class="preview-box" style="display:none;"></div>
      </div>
    </div>
  </main>
</div>

<script>
const TOKEN = '<?= e($_SESSION['admin_token'] ?? '') ?>';

// Tab switching
document.querySelectorAll('.entry-tabs button').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.entry-tabs button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.entry-form').forEach(f => f.style.display = 'none');
    document.querySelector(`[data-content="${btn.dataset.tab}"]`).style.display = 'block';
  });
});

// District/Village cascade
let districts = [];
let villagesByDistrict = {};

async function loadData() {
  const dRes = await fetch('/api/v1/districts.php', { headers: { 'Authorization': 'Bearer ' + TOKEN } });
  districts = (await dRes.json()).data || [];
  
  districts.forEach(d => {
    villagesByDistrict[d.id] = [];
  });
  
  const vRes = await fetch('/api/v1/villages.php', { headers: { 'Authorization': 'Bearer ' + TOKEN } });
  const villages = (await vRes.json()).data || [];
  villages.forEach(v => {
    if (villagesByDistrict[v.district_id]) {
      villagesByDistrict[v.district_id].push(v);
    }
  });
  
  populateDistrictSelect();
}

function populateDistrictSelect() {
  const sel = document.getElementById('district-select');
  sel.innerHTML = '<option value=""><?= te('-- select district --') ?></option>';
  districts.forEach(d => {
    sel.innerHTML += `<option value="${d.id}">${d.name_lo}</option>`;
  });
}

function populateVillageSelect(districtId) {
  const sel = document.getElementById('village-select');
  sel.innerHTML = '<option value=""><?= te('-- select --') ?></option>';
  (villagesByDistrict[districtId] || []).forEach(v => {
    sel.innerHTML += `<option value="${v.id}">${v.name_lo}</option>`;
  });
}

document.getElementById('district-select').addEventListener('change', function() {
  populateVillageSelect(this.value);
});

// Form submission
document.getElementById('parcel-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.target;
  const data = {};
  
  // Collect form fields
  new FormData(form).forEach((value, name) => {
    data[name] = value;
  });
  
  // Collect owners
  data.owners = [];
  document.querySelectorAll('.owner-row').forEach((row, idx) => {
    const fullName = row.querySelector('input[name*="full_name"]').value;
    if (fullName) {
      data.owners.push({
        full_name: fullName,
        id_number: row.querySelector('input[name*="id_number"]').value,
        phone: row.querySelector('input[name*="phone"]').value
      });
    }
  });
  
  // Update district/village
  data.district_id = document.getElementById('district-select').value;
  data.village_id = document.getElementById('village-select').value;
  
  try {
    const res = await fetch('/api/v1/parcels.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + TOKEN },
      body: JSON.stringify({ parcel: data })
    });
    const result = await res.json();
    
    const resultBox = document.getElementById('save-result');
    resultBox.style.display = 'block';
    resultBox.className = result.ok ? 'preview-box' : 'preview-box';
    resultBox.textContent = result.ok ? '✅ <?= te('Saved successfully') ?>' : '❌ ' + (result.error?.message || 'Error');
  } catch (e) {
    alert('Error: ' + e.message);
  }
});

// Add owner row
document.getElementById('add-owner').addEventListener('click', () => {
  const idx = document.querySelectorAll('.owner-row').length;
  const row = document.createElement('div');
  row.className = 'owner-row';
  row.dataset.owner = idx;
  row.innerHTML = `
    <label><?= te('Full Name') ?></label>
    <input type="text" name="owners[${idx}][full_name]" required>
    <label><?= te('ID No') ?></label>
    <input type="text" name="owners[${idx}][id_number]">
    <label><?= te('Phone') ?></label>
    <input type="text" name="owners[${idx}][phone]">
    <button type="button" class="btn-sm btn-remove"><?= te('Remove') ?></button>
  `;
  row.querySelector('button').addEventListener('click', () => row.remove());
  document.getElementById('owners-container').appendChild(row);
});

// Clear form
document.getElementById('clear-form').addEventListener('click', () => {
  document.getElementById('parcel-form').reset();
  document.getElementById('owners-container').innerHTML = `
    <div class="owner-row" data-owner="1">
      <label><?= te('Full Name') ?></label>
      <input type="text" name="owners[0][full_name]" required>
      <label><?= te('ID No') ?></label>
      <input type="text" name="owners[0][id_number]">
      <label><?= te('Phone') ?></label>
      <input type="text" name="owners[0][phone]">
    </div>
  `;
  document.getElementById('save-result').style.display = 'none';
});

// Download template
document.getElementById('download-template').addEventListener('click', () => {
  window.location.href = '/api/v1/parcels/import.php?template=1';
});

// Load initial data
loadData();
</script>
</body>
</html>