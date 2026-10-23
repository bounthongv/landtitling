<?php
// public/admin/parcel_entry.php — unified parcel entry: manual form + scan (OCR) + CSV import.
// The Scan tab uploads to the existing scans API (Tesseract or Gemini) and auto-fills the
// shared manual form with the parsed fields, exactly like the old scans.php review flow.
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
$role = $user['role'];
$allowedRoles = ['officer', 'admin', 'manager'];
if (!in_array($role, $allowedRoles, true)) { http_response_code(403); exit(t('Insufficient permissions.')); }
$token = $_SESSION['admin_token'];

// OCR result field labels (same canonical keys title_parser produces).
$parsedLabels = [
    'parcel_no'            => t('Parcel no'),
    'sub_parcel_no'        => t('Sub-parcel'),
    'area_sqm'             => t('Area (m²)'),
    'title_no'             => t('Title no'),
    'title_type'           => t('Title type'),
    'book_no'              => t('Book'),
    'volume_no'            => t('Volume'),
    'page_no'              => t('Page'),
    'land_use_class'       => t('Land use'),
    'issued_date'          => t('Issued date'),
    'province'             => t('Province'),
    'district_name'        => t('District (text)'),
    'village_name'         => t('Village (text)'),
    'district_id'          => t('District id'),
    'village_id'           => t('Village id'),
    'owner_name'           => t('Owner name'),
    'owner_names'          => t('Owner(s)'),
    'owner_id_number'      => t('Owner ID'),
    'owner_address'        => t('Owner address'),
    'road_category'        => t('Road category'),
    'order_no'             => t('Order no.'),
    'location_code'        => t('Location code'),
    'unit_no'              => t('Unit'),
    'acquisition_method'   => t('Acquired'),
    'ownership_status'     => t('Ownership status'),
    'zone_text'            => t('Zone (desc)'),
    'map_ref_no'           => t('Map ref'),
    'map_parcel_no'        => t('Map parcel no.'),
    'map_scale'            => t('Map scale'),
    'issued_place'         => t('Issued at'),
    'date_of_birth'        => t('Date of birth'),
    'nationality'          => t('Nationality'),
    'occupation'           => t('Occupation'),
    'owner_address_village'=> t('Addr village'),
    'owner_address_unit'   => t('Addr unit'),
    'owner_address_district'=> t('Addr district'),
    'owner_address_province'=> t('Addr province'),
    'spouse_name'          => t('Spouse name'),
    'spouse_dob'           => t('Spouse DOB'),
    'spouse_nationality'   => t('Spouse nationality'),
    'spouse_occupation'    => t('Spouse occupation'),
];
?>
<!DOCTYPE html>
<html lang="<?= i18n_current() ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= te('Parcel Entry') ?> - Land Fee MVP</title>
<?php require __DIR__ . '/style.php'; ?>
<style>
  .entry-page { max-width: 1200px; margin: 0 auto; }
  .entry-tabs { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
  .entry-tabs button { padding: 9px 18px; border: 1px solid #ccc; background: #fff; cursor: pointer; border-radius: 4px; font-size: 14px; }
  .entry-tabs button.active { background: #4a7c59; color: #fff; border-color: #4a7c59; }
  .entry-tools { background: #f5f5f5; padding: 14px; margin-bottom: 14px; border-radius: 4px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
  .entry-tools .grow { flex: 1; }
  .form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 14px; }
  .form-group { margin-bottom: 0; }
  .form-group label { display: block; font-size: 13px; margin-bottom: 4px; font-weight: 500; }
  .form-group input, .form-group select { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 3px; box-sizing: border-box; }
  .form-actions { display: flex; gap: 10px; margin-top: 18px; flex-wrap: wrap; }
  .preview-box { padding: 10px 12px; margin-top: 14px; font-size: 13px; border-radius: 4px; background: #fafafa; border: 1px solid #ddd; }
  .preview-box.ok { background: #eaf4ea; border-color: #2e7d32; }
  .preview-box.err { background: #fdecea; border-color: #c62828; }
  .owner-row { display: flex; gap: 10px; margin-bottom: 14px; align-items: flex-start; flex-wrap: wrap; background: #fafafa; padding: 12px; border-radius: 6px; border: 1px solid #eee; }
  .owner-row input, .owner-row select { padding: 7px 8px; border: 1px solid #ccc; border-radius: 3px; }
  .owner-row .col { display: flex; flex-direction: column; gap: 4px; }
  .owner-row .col label { font-size: 12px; color: #666; font-weight: 400; }
  .owner-row .col input { min-width: 110px; }
  .owner-row .col.fn { flex: 2; min-width: 200px; }
  .owner-row .col.id { flex: 1; min-width: 130px; }
  .btn-remove { background: #d32f2f; color: #fff; border: none; padding: 6px 10px; font-size: 12px; border-radius: 3px; cursor: pointer; }
  .btn-add { background: #4a7c59; color: #fff; border: none; padding: 7px 12px; font-size: 13px; border-radius: 3px; cursor: pointer; }
  .ocr-method { display: flex; gap: 16px; flex-wrap: wrap; margin: 8px 0; }
  .ocr-method label { display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 14px; }
  .scan-results { display: none; margin-top: 16px; }
  .scan-results.show { display: block; }
  .scan-img { max-width: 340px; max-height: 320px; object-fit: contain; border: 1px solid #ccc; border-radius: 4px; background: #fff; }
  .parsed-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .parsed-table td { padding: 5px 8px; border-bottom: 1px solid #eee; vertical-align: top; }
  .parsed-table td:first-child { width: 130px; color: #555; }
  .ocr-raw { background: #f7f7f7; border: 1px solid #ddd; border-radius: 4px; padding: 10px; font-size: 12px; max-height: 160px; overflow-y: auto; white-space: pre-wrap; font-family: monospace; }
  .conf { display: inline-block; min-width: 38px; text-align: center; padding: 1px 6px; border-radius: 3px; font-size: 11px; font-weight: 600; }
  .conf-high { background: #c8e6c9; color: #1b5e20; }
  .conf-mid  { background: #fff3c4; color: #7a5c00; }
  .conf-low  { background: #ffcdd2; color: #b71c1c; }
  .conf-none { background: #eee; color: #888; }
  .overall { font-weight: 600; }
  .field-set { margin-bottom: 22px; }
  .field-set h3 { margin: 0 0 10px 0; font-size: 15px; color: #333; border-bottom: 1px solid #e0e0e0; padding-bottom: 6px; }
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
      <div class="entry-tabs">
        <button class="active" data-tab="manual"><?= te('Manual') ?></button>
        <button data-tab="scan"><?= te('Scan') ?></button>
        <button data-tab="import"><?= te('Import') ?></button>
      </div>

      <!-- ===== MANUAL / shared form ===== -->
      <div class="form-box" data-content="manual">
        <form id="parcel-form">
          <div class="field-set">
            <h3><?= te('Geography') ?></h3>
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
            </div>
          </div>

          <div class="field-set">
            <h3><?= te('Parcel Identification') ?></h3>
            <div class="form-grid">
              <div class="form-group">
                <label><?= te('Parcel No') ?></label>
                <input name="parcel_no" type="text" required>
              </div>
              <div class="form-group">
                <label><?= te('Sub-parcel No') ?></label>
                <input name="sub_parcel_no" type="text">
              </div>
              <div class="form-group">
                <label><?= te('Location code (#30)') ?></label>
                <input name="location_code" type="text" placeholder="e.g. 10.002.0027">
              </div>
              <div class="form-group">
                <label><?= te('Order no. (#2)') ?></label>
                <input name="order_no" type="text">
              </div>
              <div class="form-group">
                <label><?= te('Unit (#6)') ?></label>
                <input name="unit_no" type="text">
              </div>
              <div class="form-group">
                <label><?= te('Area (m²)') ?></label>
                <input name="area_sqm" type="number" step="0.01" min="0" required>
              </div>
              <div class="form-group">
                <label><?= te('Zone Code (LLR7)') ?></label>
                <input name="zone_code" type="text" placeholder="e.g. B3">
              </div>
              <div class="form-group">
                <label><?= te('Zone text (#23)') ?></label>
                <input name="zone_text" type="text" placeholder="e.g. plain land-country side">
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
                <label><?= te('Map ref (#26)') ?></label>
                <input name="map_ref_no" type="text" placeholder="e.g. A250-038">
              </div>
              <div class="form-group">
                <label><?= te('Map parcel (#27)') ?></label>
                <input name="map_parcel_no" type="text">
              </div>
              <div class="form-group">
                <label><?= te('Map scale (#29)') ?></label>
                <input name="map_scale" type="text" placeholder="e.g. 1/8000">
              </div>
            </div>
          </div>

          <div class="field-set">
            <h3><?= te('Certificate / Title') ?></h3>
            <div class="form-grid">
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
                <label><?= te('Book no (#24)') ?></label>
                <input name="book_no" type="text">
              </div>
              <div class="form-group">
                <label><?= te('Leaf no (#25)') ?></label>
                <input name="page_no" type="text">
              </div>
              <div class="form-group">
                <label><?= te('Land Use (#22)') ?></label>
                <select name="land_use_class">
                  <option value=""><?= te('-- select --') ?></option>
                  <option value="residential"><?= te('Residential') ?></option>
                  <option value="agricultural"><?= te('Agricultural') ?></option>
                  <option value="commercial"><?= te('Commercial') ?></option>
                  <option value="other"><?= te('Other') ?></option>
                </select>
              </div>
              <div class="form-group">
                <label><?= te('Acquired (#20)') ?></label>
                <input name="acquisition_method" type="text" placeholder="e.g. transfer">
              </div>
              <div class="form-group">
                <label><?= te('Ownership status (#21)') ?></label>
                <input name="ownership_status" type="text" placeholder="e.g. original asset-female">
              </div>
              <div class="form-group">
                <label><?= te('Issued Date') ?></label>
                <input name="issued_date" type="date">
              </div>
              <div class="form-group">
                <label><?= te('Issued at (footer)') ?></label>
                <input name="issued_place" type="text" placeholder="e.g. ວຽງຈັນ">
              </div>
            </div>
          </div>

          <div class="field-set">
            <h3><?= te('Owner(s)') ?></h3>
            <div id="owners-container"></div>
            <button type="button" class="btn-add" id="add-owner" style="margin-bottom:8px;"><?= te('+ Add owner') ?></button>
            <button type="button" class="btn-add" id="add-spouse" style="margin-bottom:8px; margin-left: 8px; background:#555;"><?= te('Spouse Address') ?></button>
          </div>

          <div class="form-actions">
            <button type="submit" class="btn btn-green"><?= te('Save') ?></button>
            <button type="button" class="btn btn-red" id="clear-form"><?= te('Clear') ?></button>
          </div>
        </form>
        <div id="save-result" class="preview-box" style="display:none;"></div>
      </div>

      <!-- ===== SCAN / OCR ===== -->
      <div class="form-box" data-content="scan" style="display:none;">
        <div class="entry-tools">
          <input type="file" id="scan-file" accept="image/*" class="grow">
          <label style="font-size:13px; color:#555;"><?= te('Land title image (JPG/PNG)') ?></label>
          <button type="button" class="btn btn-green" id="process-ocr"><?= te('Upload &amp; Process') ?></button>
        </div>

        <div class="form-group">
          <label><?= te('OCR Method') ?></label>
          <div class="ocr-method">
            <label><input type="radio" name="ocr_method" value="tesseract" checked> <?= te('Tesseract (local, free, fast)') ?></label>
            <label><input type="radio" name="ocr_method" value="gemini"> <?= te('Gemini 2.5 Flash (AI, higher accuracy, requires API key)') ?></label>
          </div>
          <p class="sub" style="font-size:12px;"><?= te('OCR reads the title text and pre-fills the fields. You confirm and correct everything.') ?></p>
        </div>

        <div id="scan-error" class="preview-box err" style="display:none;"></div>

        <div class="scan-results" id="scan-results">
          <div class="grid" style="grid-template-columns: 340px 1fr; gap: 16px;">
            <div>
              <div class="form-box" style="border:2px solid #1b5e20;">
                <h3 style="display:flex; justify-content:space-between; align-items:center;">
                  <?= te('Scan') ?> <span id="overall-conf"></span>
                </h3>
                <img id="scan-preview" class="scan-img">
                <div style="margin-top:10px;">
                  <button type="button" class="btn btn-gold btn-sm" id="reprocess"><?= te('Try another image') ?></button>
                </div>
              </div>
              <details style="margin-top:12px;">
                <summary style="cursor:pointer; font-weight:600;"><?= te('OCR extracted text') ?></summary>
                <div id="ocr-raw" class="ocr-raw" style="margin-top:8px;"></div>
              </details>
            </div>
            <div>
              <div class="form-box">
                <h3><?= te('Parsed fields') ?></h3>
                <div id="parsed-fields"></div>
              </div>
              <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
                <button type="button" class="btn btn-green" id="apply-ocr"><?= te('Apply to form') ?></button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ===== IMPORT / CSV ===== -->
      <div class="form-box" data-content="import" style="display:none;">
        <div class="entry-tools">
          <button type="button" class="btn btn-gold" id="download-template"><?= te('Download Template') ?></button>
          <input type="file" id="import-file" accept=".csv,text/csv" class="grow">
          <button type="button" class="btn btn-green" id="do-import"><?= te('Import &amp; Match') ?></button>
        </div>
        <p class="sub" style="font-size:12px;"><?= te('Import a CSV of parcels. Download the template, fill it in, then upload. New rows create parcels; rows with an existing parcel_no in the same village update it.') ?></p>
        <div id="import-result" class="preview-box" style="display:none;"></div>
      </div>
    </div>
  </main>
</div>

<script>
const TOKEN = '<?= e($token) ?>';
const PARSING_LABELS = <?= json_encode($parsedLabels, JSON_UNESCAPED_UNICODE) ?>;
const I18N = <?= json_encode([
  'select'         => t('-- select --'),
  'selectDistrict' => t('-- select district --'),
  'fullName'       => t('Full name'),
  'address'        => t('Address'),
  'remove'         => t('Remove'),
  'savedId'        => t('Parcel saved — ID {id}'),
  'saveFailed'     => t('Save failed'),
  'importFailed'   => t('Import failed'),
  'chooseCsv'      => t('Choose a file first.'),
  'loading'        => t('Loading…'),
  'failedConfirm'  => t('Failed to process scan'),
  'tryAnother'     => t('Try another image'),
  'uploadProcess'  => t('Upload & Process'),
  'conf'           => t('Conf.'),
  'noFields'       => t('No fields parsed — fill them in below.'),
  'applied'        => t('OCR applied to the form. Review, correct, then Save.'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

document.querySelectorAll('.entry-tabs button').forEach(btn => {
  btn.addEventListener('click', () => switchTab(btn.dataset.tab));
});
function switchTab(name) {
  document.querySelectorAll('.entry-tabs button').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
  document.querySelectorAll('[data-content]').forEach(p => p.style.display = (p.dataset.content === name) ? '' : 'none');
}
function toast(msg, ok) {
  const box = document.getElementById('save-result');
  box.dataset.sticky = '0';
  box.className = 'preview-box ' + (ok ? 'ok' : 'err');
  box.textContent = msg;
  box.style.display = 'block';
  box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  setTimeout(() => { if (box.dataset.sticky !== '1') box.style.display = 'none'; }, ok ? 5000 : 8000);
}
function esc(s) { return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

let districts = [], villagesByDistrict = {};
async function loadData() {
  const dRes = await fetch('/api/v1/districts.php', { headers: { 'Authorization': 'Bearer ' + TOKEN } });
  districts = (await dRes.json()).data || [];
  districts.forEach(d => { villagesByDistrict[d.id] = []; });
  const vRes = await fetch('/api/v1/villages.php', { headers: { 'Authorization': 'Bearer ' + TOKEN } });
  const villages = (await vRes.json()).data || [];
  villages.forEach(v => { if (Array.isArray(villagesByDistrict[v.district_id])) villagesByDistrict[v.district_id].push(v); });
  populateDistrictSelect();
}
function populateDistrictSelect(keepValue) {
  const sel = document.getElementById('district-select');
  const keep = keepValue || sel.value;
  sel.innerHTML = '<option value="">' + esc(I18N.selectDistrict) + '</option>' +
    districts.map(d => '<option value="' + d.id + '">' + esc(d.name_lo) + '</option>').join('');
  if (keep) sel.value = keep;
}
function populateVillageSelect(districtId, keepValue) {
  const sel = document.getElementById('village-select');
  const keep = keepValue || sel.value;
  sel.innerHTML = '<option value="">' + esc(I18N.select) + '</option>' +
    (villagesByDistrict[districtId] || []).map(v => '<option value="' + v.id + '">' + esc(v.name_lo) + '</option>').join('');
  if (keep) sel.value = keep;
}
document.getElementById('district-select').addEventListener('change', function() {
  populateVillageSelect(this.value);
});

let ownerSeq = 0;
function addOwnerRow(o, isSpouse) {
  o = o || {};
  const i = ownerSeq++;
  const row = document.createElement('div');
  row.className = 'owner-row';
  row.dataset.owner = i;
  row.dataset.type = (o.owner_type === 'spouse' || isSpouse) ? 'spouse' : 'main';
  const spouseLabel = (o.owner_type === 'spouse' || isSpouse)
    ? '<label>' + esc(I18N.fullName) + ' (spouse)</label>'
    : '<label>' + esc(I18N.fullName) + '</label>';
  row.innerHTML =
    spouseLabel + '<input class="fn" type="text" data-k="full_name" ' + (isSpouse ? '' : 'required') + '>' +
    '<label>ID</label><input type="text" data-k="id_number">' +
    '<label>' + esc(t('Date of birth')) + '</label><input type="date" data-k="date_of_birth">' +
    '<label>' + esc(t('Nationality')) + '</label><input type="text" data-k="nationality">' +
    '<label>' + esc(t('Occupation')) + '</label><input type="text" data-k="occupation">' +
    '<label>' + esc(t('Address')) + '</label><input type="text" data-k="address">' +
    '<label>Addr village</label><input type="text" data-k="address_village">' +
    '<label>Addr unit</label><input type="text" data-k="address_unit">' +
    '<label>Addr district</label><input type="text" data-k="address_district">' +
    '<label>Addr province</label><input type="text" data-k="address_province">' +
    (isSpouse ? '<label>Share %</label><input type="number" data-k="share_pct" min="0" max="100" step="1" placeholder="e.g. 50">' : '') +
    (isSpouse ? '<label>Role</label><select data-k="role"><option value="co_owner">Co-owner</option><option value="owner">Owner</option><option value="representative">Representative</option></select>' : '') +
    '<button type="button" class="btn-remove">' + esc(I18N.remove) + '</button>';
  row.querySelector('button').addEventListener('click', () => row.remove());
  if (o.full_name)             row.querySelector('[data-k=full_name]').value = o.full_name;
  if (o.id_number)             row.querySelector('[data-k=id_number]').value = o.id_number;
  if (o.date_of_birth)         row.querySelector('[data-k=date_of_birth]').value = o.date_of_birth;
  if (o.nationality)           row.querySelector('[data-k=nationality]').value = o.nationality;
  if (o.occupation)            row.querySelector('[data-k=occupation]').value = o.occupation;
  if (o.address)               row.querySelector('[data-k=address]').value = o.address;
  if (o.address_village)       row.querySelector('[data-k=address_village]').value = o.address_village;
  if (o.address_unit)          row.querySelector('[data-k=address_unit]').value = o.address_unit;
  if (o.address_district)      row.querySelector('[data-k=address_district]').value = o.address_district;
  if (o.address_province)      row.querySelector('[data-k=address_province]').value = o.address_province;
  if (isSpouse && o.share_pct) row.querySelector('[data-k=share_pct]').value = o.share_pct;
  if (isSpouse && o.role)      row.querySelector('[data-k=role]').value = o.role;
  document.getElementById('owners-container').appendChild(row);
}
document.getElementById('add-owner').addEventListener('click', () => addOwnerRow());
document.getElementById('add-spouse').addEventListener('click', () => addOwnerRow({}, true));
function clearOwners() { document.getElementById('owners-container').innerHTML = ''; addOwnerRow(); }
clearOwners();

document.getElementById('parcel-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.target;
  const parcel = {};
  new FormData(form).forEach((value, name) => { parcel[name] = value; });
  parcel.district_id = document.getElementById('district-select').value;
  parcel.village_id  = document.getElementById('village-select').value;
  parcel.owners = Array.from(document.querySelectorAll('.owner-row')).map(row => {
    const o = {};
    row.querySelectorAll('[data-k]').forEach(inp => {
      const k = inp.dataset.k;
      const v = inp.value;
      if (k === 'share_pct' && v === '') return;
      o[k] = v;
    });
    return o;
  }).filter(o => o.full_name && o.full_name.trim() !== '');

  const res = await fetch('/api/v1/parcels.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + TOKEN },
    body: JSON.stringify({ parcel, owners: parcel.owners }),
  });
  const result = await res.json();
  if (result.ok) {
    toast(I18N.savedId.replace('{id}', result.data.parcel_id), true);
    form.reset();
    clearOwners();
    populateDistrictSelect();
  } else {
    const box = document.getElementById('save-result');
    box.dataset.sticky = '1';
    box.className = 'preview-box err';
    box.style.display = 'block';
    const msg = result.error && result.error.fields ? result.error.fields.join('; ') : ((result.error && result.error.message) || I18N.saveFailed);
    box.textContent = msg;
  }
});
document.getElementById('clear-form').addEventListener('click', () => {
  document.getElementById('parcel-form').reset();
  clearOwners();
  populateDistrictSelect();
  document.getElementById('save-result').style.display = 'none';
});

let lastScan = null;
function confBadge(c) {
  if (c === null || c === undefined || c === '' || c === false) return '<span class="conf conf-none">—</span>';
  c = parseInt(c, 10);
  const cls = c >= 80 ? 'conf-high' : (c >= 50 ? 'conf-mid' : 'conf-low');
  return '<span class="conf ' + cls + '">' + c + '%</span>';
}
document.getElementById('process-ocr').addEventListener('click', async () => {
  const file = document.getElementById('scan-file').files[0];
  if (!file) { showScanError(I18N.chooseCsv); return; }
  const method = document.querySelector('input[name=ocr_method]:checked').value;
  const fd = new FormData();
  fd.append('image', file);
  fd.append('ocr_method', method);
  document.getElementById('scan-error').style.display = 'none';
  document.getElementById('scan-results').classList.remove('show');
  const btn = document.getElementById('process-ocr');
  btn.disabled = true; btn.textContent = I18N.loading;
  try {
    const res = await fetch('/api/v1/scans.php', {
      method: 'POST', body: fd, headers: { 'Authorization': 'Bearer ' + TOKEN },
    });
    const j = await res.json();
    if (!j.ok) { showScanError((j.error && j.error.message) || I18N.failedConfirm); return; }
    lastScan = j.data;
    renderScanResults(lastScan);
  } catch (err) {
    showScanError(err.message);
  } finally {
    btn.disabled = false; btn.textContent = I18N.uploadProcess;
  }
});
function showScanError(msg) {
  const b = document.getElementById('scan-error');
  b.textContent = msg; b.style.display = 'block';
}
function renderScanResults(scan) {
  let parsed = scan.parsed_fields;
  if (typeof parsed === 'string') { try { parsed = JSON.parse(parsed); } catch (e) { parsed = {}; } }
  parsed = parsed || {};
  let conf = scan.field_confidence;
  if (typeof conf === 'string') { try { conf = JSON.parse(conf); } catch (e) { conf = {}; } }
  conf = conf || {};

  document.getElementById('scan-preview').src = scan.image_path;
  document.getElementById('overall-conf').innerHTML =
    '<span class="overall">' + esc(I18N.conf) + ' ' + esc(scan.overall_confidence ?? '—') + ((scan.overall_confidence !== null && scan.overall_confidence !== undefined) ? '%' : '') + '</span>';
  document.getElementById('ocr-raw').textContent =
    (typeof scan.ocr_raw === 'string' ? scan.ocr_raw : JSON.stringify(scan.ocr_raw, null, 2)) || '—';

  const keys = Object.keys(parsed);
  document.getElementById('parsed-fields').innerHTML = keys.length
    ? '<table class="parsed-table"><tbody>' + keys.map(k =>
        '<tr><td>' + esc(PARSING_LABELS[k] || k) + '</td><td>' + esc(Array.isArray(parsed[k]) ? parsed[k].join(', ') : parsed[k]) + '</td><td>' + confBadge(conf[k]) + '</td></tr>'
      ).join('') + '</tbody></table>'
    : '<p class="empty">' + esc(I18N.noFields) + '</p>';
  document.getElementById('scan-results').classList.add('show');
}
document.getElementById('apply-ocr').addEventListener('click', () => {
  if (!lastScan) return;
  let parsed = lastScan.parsed_fields;
  if (typeof parsed === 'string') { try { parsed = JSON.parse(parsed); } catch (e) { parsed = {}; } }
  parsed = parsed || {};
  const f = document.getElementById('parcel-form');
  const set = (name, val) => {
    if (val === null || val === undefined || val === '') return;
    const el = f.querySelector('[name=' + name + ']');
    if (el) el.value = String(val);
  };
  if (parsed.district_id) {
    populateDistrictSelect(String(parsed.district_id));
    populateVillageSelect(String(parsed.district_id), parsed.village_id ? String(parsed.village_id) : '');
  }
  ['parcel_no','sub_parcel_no','location_code','order_no','unit_no',
   'area_sqm','zone_code','zone_text','road_category','map_ref_no',
   'map_parcel_no','map_scale','title_type','title_no','book_no',
   'page_no','land_use_class','acquisition_method','ownership_status',
   'issued_date','issued_place'].forEach(k => set(k, parsed[k]));
  clearOwners();
  const names = parsed.owner_names || (parsed.owner_name ? [parsed.owner_name] : []);
  names.forEach(n => {
    addOwnerRow({
      full_name: n, id_number: parsed.owner_id_number,
      date_of_birth: parsed.date_of_birth, nationality: parsed.nationality,
      occupation: parsed.occupation, address: parsed.owner_address,
      address_village: parsed.owner_address_village,
      address_unit: parsed.owner_address_unit,
      address_district: parsed.owner_address_district,
      address_province: parsed.owner_address_province,
    });
  });
  if (parsed.spouse_name && parsed.spouse_name !== '') {
    addOwnerRow({
      full_name: parsed.spouse_name,
      owner_type: 'spouse', role: 'co_owner',
      date_of_birth: parsed.spouse_dob, nationality: parsed.spouse_nationality,
      occupation: parsed.spouse_occupation,
    }, true);
  }
  switchTab('manual');
  toast(I18N.applied, true);
});
document.getElementById('reprocess').addEventListener('click', () => {
  document.getElementById('scan-file').value = '';
  document.getElementById('scan-results').classList.remove('show');
  document.getElementById('scan-file').focus();
});

document.getElementById('download-template').addEventListener('click', () => {
  window.open('/api/v1/parcels/import.php?template=1', '_blank');
});
document.getElementById('do-import').addEventListener('click', async () => {
  const file = document.getElementById('import-file').files[0];
  const box = document.getElementById('import-result');
  box.style.display = 'none';
  if (!file) { box.className = 'preview-box err'; box.textContent = I18N.chooseCsv; box.style.display = 'block'; return; }
  const text = await file.text();
  const res = await fetch('/api/v1/parcels/import.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + TOKEN },
    body: JSON.stringify({ csv: text, has_header: true }),
  });
  const j = await res.json();
  box.style.display = 'block';
  if (!j.ok) { box.className = 'preview-box err'; box.textContent = (j.error && j.error.message) || I18N.importFailed; return; }
  const d = j.data;
  box.className = 'preview-box ok';
  let html = '<strong>' + d.created + ' created, ' + d.errors + ' error(s) of ' + d.total + ' row(s)</strong>';
  const errs = (d.results || []).filter(r => r.status === 'error');
  if (errs.length) html += '<ul>' + errs.slice(0, 20).map(r => '<li>Line ' + r.line + ': ' + esc(r.message) + '</li>').join('') + '</ul>';
  box.innerHTML = html;
});

loadData();
</script>
</body>
</html>
