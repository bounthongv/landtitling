<?php
// public/admin/parcel_entry.php
// Unified parcel entry — flat 33-field form matching the Lao Land Title Certificate (ໃບຕາດິນ) layout.
// No section grouping. Field order and labels follow the exact certificate numbering (#1–#33).
// Address fields (#12–#15) are reordered to: #15 (ແຂວງ) → #14 (ເມືອງ) → #12 (ບ້ານ) → #13 (ຫ້ອງ)
// with province → district → village cascade dropdowns.
//
// DB field mapping:
//   #1  ເລກທີ                              → parcels.title_no
//   #2  ອອກຄັ້ງທີ                         → parcels.order_no
//   #3  ທີ່ຕັ້ງຕອນດິນ                    → parcels.location_code
//   #4  ແຂວງ (land location)              → filter for #5
//   #5  ເມືອງ (land location)            → parcels.district_id (FK)
//   #6  ບ້ານ (land location)             → parcels.village_id (FK)
//   #7  ຫ້ອງ                               → parcels.unit_no
//   #8  ອອກໃຫ້ແກ່                         → owners[0].full_name
//   #9  ວັນເດືອນປີເກີດ                    → owners[0].date_of_birth
//   #10 ສັນຊາດ                             → owners[0].nationality
//   #11 ອາຊີບ                              → owners[0].occupation
//   #15 ແຂວງ (address)                   → owners[0].address_province
//   #14 ເມືອງ (address)                  → owners[0].address_district
//   #12 ບ້ານ (address)                   → owners[0].address_village
//   #13 ຫ້ອງ (address)                    → owners[0].address_unit
//   #16 ຊື່ຜົວຫລືເມຍ                    → owners[1].full_name (owner_type=spouse)
//   #17 ວັນເດືອນປີເກີດ (spouse)         → owners[1].date_of_birth
//   #18 ສັນຊາດ (spouse)                 → owners[1].nationality
//   #19 ອາຊີບ (spouse)                   → owners[1].occupation
//   #20 ການໄດ້ມາຂອງສິດນຳໃຊ້ທີ່ດິນ     → parcels.acquisition_method
//   #21 ຖານະເປັນເຈົ້າຂອງສິດນຳໃຊ້ທີ່ດິນ → parcels.ownership_status
//   #22 ປະເພດທີ່ດິນ                     → parcels.land_use_class
//   #23 ເຂດ                               → parcels.zone_text
//   #24 ປຶ້ມທະບຽນທີ່ດິນ ເື່ອງທີ່      → parcels.book_no
//   #25 ໃບທີ                              → parcels.page_no
//   #26 ແຜນທີ່ດິນເລກທີ                    → parcels.map_ref_no
//   #27 ຕອນດິນເລກທີ                      → parcels.parcel_no
//   #28 ເນື້ອທີ່ດິນ                      → parcels.area_sqm
//   #29 ມາດຕາສ່ວນ                        → parcels.map_scale
//   #30 ອອກໃຫ້ທີ່                         → parcels.issued_place
//   #31–33 ວັນທີ / ເດືອນ / ປີ           → parcels.issued_date (combined YYYY-MM-DD)

require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
$role = $user['role'];
if (!in_array($role, ['officer','admin','manager'], true)) { http_response_code(403); exit(t('Insufficient permissions.')); }
$token = $_SESSION['admin_token'];

// ---- Edit mode: fetch existing parcel if ?id= is provided ----
$editParcel = null;
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($editId > 0) {
    $resp = api_call('GET', 'parcels.php?id=' . $editId, null, $token);
    if (!empty($resp['ok']) && !empty($resp['data']['parcels'])) {
        $editParcel = $resp['data']['parcels'][0];
        // Also fetch owners for this parcel
        $ownResp = api_call('GET', 'owners.php?parcel_id=' . $editId, null, $token);
        $editOwners = $ownResp['data']['owners'] ?? [];
    }
}

$parsedLabels = [
    'parcel_no'            => t('Parcel no'),
    'sub_parcel_no'        => t('Sub-parcel'),
    'area_sqm'             => t('Area (m²)'),
    'title_no'             => t('Title no'),
    'book_no'              => t('Book'),
    'volume_no'            => t('Volume'),
    'page_no'              => t('Page'),
    'land_use_class'       => t('Land use'),
    'issued_date'          => t('Issued date'),
    'district_name'        => t('District (text)'),
    'village_name'         => t('Village (text)'),
    'district_id'          => t('District id'),
    'village_id'           => t('Village id'),
    'owner_name'           => t('Owner name'),
    'owner_names'          => t('Owner(s)'),
    'owner_id_number'      => t('Owner ID'),
    'owner_address'        => t('Owner address'),
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
  .entry-page { max-width: 1100px; margin: 0 auto; }
  .entry-tabs { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
  .entry-tabs button { padding: 9px 18px; border: 1px solid #ccc; background: #fff; cursor: pointer; border-radius: 4px; font-size: 14px; }
  .entry-tabs button.active { background: #4a7c59; color: #fff; border-color: #4a7c59; }

  /* Flat certificate form — mirrors the Lao Land Title Certificate layout:
       a single-column document where each field is a "label: value" row in
       certificate order, with a header block and a footer. */
    .cert-grid {
      max-width: 860px;
      margin: 0 auto;
      border: 2px solid #4a7c59;
      border-radius: 6px;
      background: #fffdf5;
      padding: 0;
    }
    .cert-head {
      text-align: center;
      padding: 14px 12px;
      border-bottom: 2px solid #4a7c59;
      background: #f4f9f4;
    }
    .cert-head .l1 { font-size: 13px; color: #333; }
    .cert-head .l2 { font-size: 12px; color: #555; }
    .cert-head .l3 { font-size: 12px; color: #555; }
    /* Tax footer block — like the header frame, distinct colour, sits below
       the certificate fields (these two are system fields, not on the paper form). */
    .cert-foot {
      border-top: 2px solid #4a7c59;
      background: #e8f0ea;
      padding: 12px;
      text-align: left;
    }
    .cert-foot .ft-title { font-size: 13px; font-weight: 700; color: #1b5e20; margin-bottom: 8px; }
    .cert-foot .inline { display: flex; flex-wrap: wrap; gap: 10px 20px; }
    .cert-foot .inline .ci { display: flex; align-items: center; gap: 6px; }
    .cert-foot .inline .ci label { min-width: 0; font-weight: 600; font-size: 12px; color: #1b5e20; }
    .cert-foot .inline .ci select { width: 200px; padding: 7px 9px; border: 1px solid #4a7c59; border-radius: 3px; font-size: 14px; }
    .cert-row {
      display: flex;
      flex-wrap: wrap;
      gap: 4px 18px;
      align-items: flex-start;
      padding: 8px 12px;
      border-bottom: 1px solid #e8e0d0;
    }
    .cert-row:last-child { border-bottom: none; }
    .cert-row label {
      font-size: 13px; font-weight: 600; color: #222;
      min-width: 150px;
    }
    .cert-row .val {
      flex: 1;
      min-width: 200px;
    }
    .cert-row input, .cert-row select {
      padding: 7px 9px; border: 1px solid #ccc; border-radius: 3px;
      font-size: 14px; width: 100%; box-sizing: border-box;
    }
    .cert-row input:focus, .cert-row select:focus {
      border-color: #4a7c59; outline: none;
    }
    .cert-row .inline { display: flex; flex-wrap: wrap; gap: 6px 16px; }
    .cert-row .inline .ci { display: flex; align-items: center; gap: 6px; }
    .cert-row .inline .ci label { min-width: 0; font-weight: 500; font-size: 12px; color: #444; }
    .cert-row .inline .ci input, .cert-row .inline .ci select { width: 110px; }
    .cert-row.wide { }
    @media (max-width: 768px) { .cert-row .val { min-width: 100%; } }

  .form-actions { display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap; }
  .preview-box { padding: 10px 12px; margin-top: 14px; font-size: 13px; border-radius: 4px; background: #fafafa; border: 1px solid #ddd; }
  .preview-box.ok { background: #eaf4ea; border-color: #2e7d32; }
  .preview-box.err { background: #fdecea; border-color: #c62828; }

  /* Scan tab */
  .entry-tools { background: #f5f5f5; padding: 14px; margin-bottom: 14px; border-radius: 4px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
  .entry-tools .grow { flex: 1; }
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

    <div class="entry-page">
      <div class="entry-tabs">
        <button class="active" data-tab="manual"><?= te('Manual') ?></button>
        <button data-tab="scan"><?= te('Scan') ?></button>
        <button data-tab="import"><?= te('Import') ?></button>
      </div>

      <!-- ========== MANUAL TAB ========== -->
      <div data-content="manual">
        <form id="parcel-form">
          <div class="cert-grid">
            <!-- Certificate header -->
            <div class="cert-head">
              <div class="l1"><?= te('Ministry of Natural Resources and Environment') ?></div>
              <div class="l2"><?= te('Province/District Land &amp; Environment Office') ?></div>
              <div class="l3"><?= te('LAND TITLE') ?> — <?= te('ໃບຕາດິນ') ?></div>
            </div>
            <!-- #1 -->
            <div class="cert-row">
              <label><?= te('Ref No') ?></label>
              <div class="val"><input type="text" name="ref_no" required></div>
            </div>
            <!-- #2 -->
            <div class="cert-row">
              <label><?= te('Issue Batch') ?></label>
              <div class="val"><input type="text" name="issue_batch"></div>
            </div>
            <!-- #3 -->
            <div class="cert-row">
              <label><?= te('Parcel Location') ?></label>
              <div class="val"><input type="text" name="location" placeholder="10.002.0027"></div>
            </div>
            <!-- #4-7: Province / District / Village / Unit -->
            <div class="cert-row">
              <label><?= te('Parcel address') ?></label>
              <div class="inline">
                <span class="ci"><label><?= te('Province') ?></label><select name="province" id="prov-select"><option value=""></option></select></span>
                <span class="ci"><label><?= te('District') ?></label><select name="district_id" id="dist-select"><option value=""></option></select></span>
                <span class="ci"><label><?= te('Village') ?></label><select name="village_id" id="vill-select"><option value=""></option></select></span>
                <span class="ci"><label><?= te('Unit') ?></label><input type="text" name="unit"></span>
              </div>
            </div>
            <!-- #8 -->
            <div class="cert-row">
              <label><?= te('Issued To') ?></label>
              <div class="val"><input type="text" name="owner_name" required></div>
            </div>
            <!-- #9-11: DOB / Nationality / Occupation -->
            <div class="cert-row">
              <label><?= te('Owner identity') ?></label>
              <div class="inline">
                <span class="ci"><label><?= te('Date of Birth') ?></label><input type="date" name="dob"></span>
                <span class="ci"><label><?= te('Nationality') ?></label><input type="text" name="nationality"></span>
                <span class="ci"><label><?= te('Occupation') ?></label><input type="text" name="occupation"></span>
              </div>
            </div>
            <!-- #12-15: current address village/unit/district/province (cascade order: prov, dist, vill, unit) -->
            <div class="cert-row">
              <label><?= te('Current address') ?></label>
              <div class="inline">
                <span class="ci"><label><?= te('Province') ?></label><select name="addr_province" id="addr-prov-select"><option value=""></option></select></span>
                <span class="ci"><label><?= te('District') ?></label><select name="addr_district" id="addr-dist-select"><option value=""></option></select></span>
                <span class="ci"><label><?= te('Village') ?></label><select name="addr_village" id="addr-vill-select"><option value=""></option></select></span>
                <span class="ci"><label><?= te('Unit') ?></label><input type="text" name="addr_unit"></span>
              </div>
            </div>
            <!-- #16-19: spouse block -->
            <div class="cert-row">
              <label><?= te('Spouse') ?></label>
              <div class="inline">
                <span class="ci" style="flex:1; min-width:220px;"><label><?= te('Spouse Name') ?></label><input type="text" name="spouse_name"></span>
                <span class="ci"><label><?= te('Date of Birth') ?></label><input type="date" name="spouse_dob"></span>
                <span class="ci"><label><?= te('Nationality') ?></label><input type="text" name="spouse_nat"></span>
                <span class="ci"><label><?= te('Occupation') ?></label><input type="text" name="spouse_occ"></span>
              </div>
            </div>
            <!-- #20 -->
            <div class="cert-row">
              <label><?= te('Land Right Acquired') ?></label>
              <div class="val"><input type="text" name="acquired"></div>
            </div>
            <!-- #21 -->
            <div class="cert-row">
              <label><?= te('Land Right Owner Status') ?></label>
              <div class="val"><input type="text" name="status"></div>
            </div>
            <!-- #22-23: land type + descriptive zone -->
            <div class="cert-row">
              <label><?= te('Land type / Zone') ?></label>
              <div class="inline">
                <span class="ci" style="flex:1; min-width:200px;"><label><?= te('Land Type') ?></label><input type="text" name="land_type"></span>
                <span class="ci" style="flex:1; min-width:200px;"><label><?= te('Zone') ?></label><input type="text" name="zone" placeholder="e.g. ດິນປຽງ — ຕາມເຂດບ້ານ"></span>
              </div>
            </div>
            <!-- #24-27: book/leaf/map/parcel -->
                        <div class="cert-row">
              <label><?= te('Registration') ?></label>
              <div class="inline">
                <span class="ci"><label><?= te('Registration Book') ?></label><input type="text" name="book"></span>
                <span class="ci"><label><?= te('Leaf No') ?></label><input type="text" name="leaf"></span>
                <span class="ci"><label><?= te('Map No') ?></label><input type="text" name="map_no" placeholder="e.g. A250-038"></span>
                <span class="ci"><label><?= te('Parcel No') ?></label><input type="text" name="parcel_no" required></span>
              </div>
            </div>
            <!-- #28-29: area + scale -->
            <div class="cert-row">
              <label><?= te('Land measure') ?></label>
              <div class="inline">
                <span class="ci"><label><?= te('Land Area') ?></label><input type="number" step="0.01" min="0" name="area" required></span>
                <span class="ci"><label><?= te('Scale') ?></label><input type="text" name="scale" placeholder="e.g. 1/8000"></span>
              </div>
            </div>
            <!-- #30-33: issued at + date -->
                        <div class="cert-row">
                          <label><?= te('Issue location') ?></label>
                          <div class="inline">
                            <span class="ci"><label><?= te('Issued At') ?></label><select name="issued_at" id="issued-prov-select"><option value=""></option></select></span>
                <span class="ci"><label><?= te('Day') ?></label><input type="number" min="1" max="31" name="issue_day"></span>
                <span class="ci"><label><?= te('Month') ?></label><input type="number" min="1" max="12" name="issue_month"></span>
                <span class="ci"><label><?= te('Year') ?></label><input type="number" min="1900" max="2100" name="issue_year"></span>
              </div>
            </div>
            <!-- System fields (not on the paper certificate): valuation zone + road access -->
            <div class="cert-foot">
              <div class="ft-title"><?= te('Tax') ?></div>
              <div class="inline">
                <span class="ci"><label><?= te('Valuation zone (tax)') ?></label><select name="zone_code" id="zone-code-select" required><option value=""><?= te('-- select --') ?></option></select></span>
                <span class="ci"><label><?= te('Road access') ?></label><select name="road_category" required>
                  <?php foreach (['main' => 'Main road', 'branch' => 'Branch road', 'alley' => 'Alley', 'none' => 'None'] as $rc => $rcLabel): ?>
                  <option value="<?= $rc ?>" <?= $rc === 'none' ? 'selected' : '' ?>><?= te($rcLabel) ?></option>
                  <?php endforeach; ?>
                </select></span>
              </div>
            </div>
          </div>

          <div class="form-actions">
            <button type="submit" name="save_action" value="draft" class="btn btn-gold"><?= te('Save as Draft') ?></button>
            <button type="submit" name="save_action" value="confirm" class="btn btn-green"><?= te('Save and Confirm') ?></button>
            <button type="button" class="btn btn-red" id="clear-form"><?= te('Clear') ?></button>
          </div>
        </form>
        <div id="save-result" class="preview-box" style="display:none;"></div>
      </div>

      <!-- ========== SCAN TAB ========== -->
      <div data-content="scan" style="display:none;">
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

      <!-- ========== IMPORT TAB ========== -->
      <div data-content="import" style="display:none;">
        <div class="entry-tools">
          <button type="button" class="btn btn-gold" id="download-template"><?= te('Download Template') ?></button>
          <input type="file" id="import-file" accept=".csv,text/csv" class="grow">
          <button type="button" class="btn btn-green" id="do-import"><?= te('Import &amp; Match') ?></button>
        </div>
        <p class="sub" style="font-size:12px;"><?= te('Import a CSV of parcels. Download the template, fill it in, then upload.') ?></p>
        <div id="import-result" class="preview-box" style="display:none;"></div>
      </div>
    </div>
  </main>
</div>

<script>
const TOKEN = '<?= e($token) ?>';
const PARSING_LABELS = <?= json_encode($parsedLabels, JSON_UNESCAPED_UNICODE) ?>;
const I18N = <?= json_encode([
  'select'        => t('-- select --'),
  'savedId'       => t('Parcel saved — ID {id}'),
  'saveFailed'    => t('Save failed'),
  'importFailed'  => t('Import failed'),
  'chooseFile'    => t('Choose a file first.'),
  'loading'       => t('Loading…'),
  'ocrFailed'     => t('Failed to process scan'),
  'tryAnother'    => t('Try another image'),
  'uploadProcess' => t('Upload & Process'),
  'conf'          => t('Conf.'),
  'noFields'      => t('No fields parsed — fill them in below.'),
  'applied'       => t('OCR applied to the form. Review, correct, then Save.'),
  ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

// Edit parcel data passed from PHP
const EDIT_PARCEL = <?php echo json_encode($editParcel ?? null, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const EDIT_OWNERS = <?php echo json_encode($editOwners ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const EDIT_MODE = !!EDIT_PARCEL;

/* ---------- helpers ---------- */
function esc(s) { return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
function v(name) {
  const el = document.querySelector('[name="' + name + '"]');
  return el ? el.value.trim() : '';
}
function setVal(name, val) {
  const el = document.querySelector('[name="' + name + '"]');
  if (el && val) el.value = String(val);
}
function showResult(msg, ok) {
  const box = document.getElementById('save-result');
  box.dataset.sticky = '0';
  box.className = 'preview-box ' + (ok ? 'ok' : 'err');
  box.textContent = msg;
  box.style.display = 'block';
  box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  setTimeout(() => { if (box.dataset.sticky !== '1') box.style.display = 'none'; }, ok ? 5000 : 8000);
}

/* ---------- tabs ---------- */
document.querySelectorAll('.entry-tabs button').forEach(btn => {
  btn.addEventListener('click', () => switchTab(btn.dataset.tab));
});
function switchTab(name) {
  document.querySelectorAll('.entry-tabs button').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
  document.querySelectorAll('[data-content]').forEach(p => p.style.display = (p.dataset.content === name) ? '' : 'none');
}

/* ---------- load districts + villages + provinces ---------- */
let allDistricts = [];
let allZones     = [];
let provinces    = [];
// Villages are loaded per district on demand (there are ~8.8k nationwide, too
// many to fetch at once). Cache by district id.
const villageCache = {};   // districtId -> [villages]
let allVillages    = [];   // flattened current district's villages (for submit/fill)

async function ensureVillages(districtId) {
  const did = Number(districtId);
  if (!did || villageCache[did]) return villageCache[did] || [];
  try {
    const r = await fetch('/api/v1/villages.php?district_id=' + did + '&limit=500', {
      headers: { 'Authorization': 'Bearer ' + TOKEN }
    });
    const j = await r.json();
    const list = (j && j.data) ? j.data : [];
    villageCache[did] = list;
    return list;
  } catch (e) { return []; }
}

async function loadGeoData() {
  const dRes = await fetch('/api/v1/districts.php', { headers: { 'Authorization': 'Bearer ' + TOKEN } });
  const dJson = await dRes.json();
  allDistricts = dJson.data || [];

  const zRes = await fetch('/api/v1/rates.php', { headers: { 'Authorization': 'Bearer ' + TOKEN } });
  const zJson = await zRes.json();
  allZones = zJson.data?.zones || [];
  populateZoneSelect(null);

  // Extract unique provinces from districts (each district has province_name)
  const provSet = new Set();
  allDistricts.forEach(d => {
    const p = d.province_name || d.province || '';
    if (p) provSet.add(p);
  });
  provinces = [...provSet].sort();
  populateProvSelects();
}

function populateProvSelects() {
  const opts = '<option value="">' + esc(I18N.select) + '</option>' +
    provinces.map(p => '<option value="' + esc(p) + '">' + esc(p) + '</option>').join('');
  document.getElementById('prov-select').innerHTML = opts;
  document.getElementById('addr-prov-select').innerHTML = opts;
  document.getElementById('issued-prov-select').innerHTML = opts;
}

/* ---------- valuation zones: filter by selected district ---------- */
function populateZoneSelect(districtId) {
  const sel = document.getElementById('zone-code-select');
  if (!sel) return;
  const did = districtId ? Number(districtId) : 0;
  const opts = allZones.filter(z => !did || !z.district_id || Number(z.district_id) === did);
  sel.innerHTML = '<option value="">' + esc(I18N.select) + '</option>' +
    opts.map(z => '<option value="' + esc(z.code) + '">' +
      esc(z.code + (z.district_name ? ' — ' + z.district_name : '') + (z.plan_zone ? ' / ' + z.plan_zone : '')) +
      '</option>').join('');
}

/* ---------- land location cascade: #4 province → #5 district (id) → #6 village (id) ---------- */
document.getElementById('prov-select').addEventListener('change', function() {
  const prov = this.value;
  const opts = '<option value="">' + esc(I18N.select) + '</option>' +
    allDistricts.filter(d => !prov || (d.province_name || d.province) === prov)
      .map(d => '<option value="' + d.id + '">' + esc(d.name_lo) + '</option>').join('');
  document.getElementById('dist-select').innerHTML = opts;
  document.getElementById('vill-select').innerHTML = '<option value="">' + esc(I18N.select) + '</option>';
});

document.getElementById('dist-select').addEventListener('change', async function() {
  const dId = parseInt(this.value, 10);
  document.getElementById('vill-select').innerHTML = '<option value="">' + esc(I18N.select) + '</option>';
  populateZoneSelect(dId || null);
  if (!dId) { document.getElementById('vill-select').innerHTML = '<option value="">' + esc(I18N.select) + '</option>'; return; }
  const vils = await ensureVillages(dId);
  allVillages = vils;
  const opts = '<option value="">' + esc(I18N.select) + '</option>' +
    vils.map(v => '<option value="' + v.id + '">' + esc(v.name_lo) + '</option>').join('');
  document.getElementById('vill-select').innerHTML = opts;
});

/* Auto-fill the valuation zone from the chosen parcel village (#6).
   A village maps to exactly one zone (villages.zone_id); picking the village
   selects that zone in the Tax footer. The officer can still override it. */
document.getElementById('vill-select').addEventListener('change', function() {
  const vid = parseInt(this.value, 10);
  const v = allVillages.find(x => x.id === vid);
  const zoneCode = v && v.zone_id ? v.zone_id : '';
  const zsel = document.getElementById('zone-code-select');
  if (!zsel) return;
  if (zoneCode) {
    // Ensure the option exists (it should, since the zone is mapped to this village).
    let found = false;
    Array.from(zsel.options).forEach(o => { if (o.value === zoneCode) found = true; });
    if (!found) {
      const opt = document.createElement('option');
      opt.value = zoneCode;
      opt.textContent = zoneCode;
      zsel.appendChild(opt);
    }
    zsel.value = zoneCode;
  } else {
    zsel.value = '';
  }
});

/* ---------- address cascade: #15 province → #14 district (name) → #12 village (name) ---------- */
document.getElementById('addr-prov-select').addEventListener('change', function() {
  const prov = this.value;
  const opts = '<option value="">' + esc(I18N.select) + '</option>' +
    allDistricts.filter(d => !prov || (d.province_name || d.province) === prov)
      .map(d => '<option value="' + esc(d.name_lo) + '">' + esc(d.name_lo) + '</option>').join('');
  document.getElementById('addr-dist-select').innerHTML = opts;
  document.getElementById('addr-vill-select').innerHTML = '<option value="">' + esc(I18N.select) + '</option>';
});

document.getElementById('addr-dist-select').addEventListener('change', async function() {
  const dName = this.value;
  const d = allDistricts.find(x => x.name_lo === dName);
  const dId = d ? d.id : null;
  document.getElementById('addr-vill-select').innerHTML = '<option value="">' + esc(I18N.select) + '</option>';
  if (!dId) return;
  const vils = await ensureVillages(dId);
  const opts = '<option value="">' + esc(I18N.select) + '</option>' +
    vils.map(v => '<option value="' + esc(v.name_lo) + '">' + esc(v.name_lo) + '</option>').join('');
  document.getElementById('addr-vill-select').innerHTML = opts;
});

/* ---------- form submit ---------- */
document.getElementById('parcel-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  // Which button submitted the form? e.submitter is the <button> that fired it;
  // fall back to the active element (covers Enter-key submits too).
  const submitter = e.submitter || document.activeElement;
  const saveAction = (submitter && submitter.value) || 'confirm';

  const parcel = {
    id:                 EDIT_MODE ? EDIT_PARCEL.id : undefined,
    status:             saveAction === 'draft' ? 'draft' : 'confirmed',
    title_no:           v('ref_no') || null,
    order_no:           v('issue_batch') || null,
    location_code:      v('location') || null,
    district_id:        parseInt(v('district_id'), 10) || null,
    village_id:         parseInt(v('village_id'), 10) || null,
    unit_no:            v('unit') || null,
    acquisition_method: v('acquired') || null,
    ownership_status:   v('status') || null,
    land_use_class:     v('land_type') || null,
    zone_text:          v('zone') || null,
    zone_code:          v('zone_code') || null,
    road_category:      v('road_category') || null,
    book_no:            v('book') || null,
    page_no:            v('leaf') || null,
    map_ref_no:         v('map_no') || null,
    parcel_no:          v('parcel_no') || null,
    area_sqm:           v('area') || null,
    map_scale:          v('scale') || null,
    issued_place:       v('issued_at') || null,
  };

  // Combine day/month/year → ISO date
  const d = parseInt(v('issue_day'), 10);
  const m = parseInt(v('issue_month'), 10);
  const y = parseInt(v('issue_year'), 10);
  if (y && m && d) {
    parcel.issued_date = y + '-' + String(m).padStart(2,'0') + '-' + String(d).padStart(2,'0');
  }

  // Remove empty keys (keep required)
  Object.keys(parcel).forEach(k => { if (parcel[k] === null || parcel[k] === '') delete parcel[k]; });

  const owners = [];
  if (v('owner_name')) {
    owners.push({
      full_name:           v('owner_name'),
      date_of_birth:       v('dob') || undefined,
      nationality:         v('nationality') || undefined,
      occupation:          v('occupation') || undefined,
      address_province:    v('addr_province') || undefined,
      address_district:    v('addr_district') || undefined,
      address_village:     v('addr_village') || undefined,
      address_unit:        v('addr_unit') || undefined,
    });
  }
  if (v('spouse_name')) {
    owners.push({
      full_name:      v('spouse_name'),
      owner_type:     'spouse',
      role:           'co_owner',
      date_of_birth:  v('spouse_dob') || undefined,
      nationality:    v('spouse_nat') || undefined,
      occupation:     v('spouse_occ') || undefined,
    });
  }

  const res = await fetch('/api/v1/parcels.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + TOKEN },
    body: JSON.stringify({ parcel, owners }),
  });
  const result = await res.json();
  if (result.ok) {
    showResult(I18N.savedId.replace('{id}', result.data.parcel_id), true);
    document.getElementById('parcel-form').reset();
    populateProvSelects();
    document.getElementById('dist-select').innerHTML = '<option value="">' + esc(I18N.select) + '</option>';
    document.getElementById('vill-select').innerHTML = '<option value="">' + esc(I18N.select) + '</option>';
    document.getElementById('addr-dist-select').innerHTML = '<option value="">' + esc(I18N.select) + '</option>';
    document.getElementById('addr-vill-select').innerHTML = '<option value="">' + esc(I18N.select) + '</option>';
  } else {
    const box = document.getElementById('save-result');
    box.dataset.sticky = '1';
    box.className = 'preview-box err';
    box.style.display = 'block';
    const msg = result.error && result.error.fields
          ? Object.values(result.error.fields).join('; ')
          : ((result.error && result.error.message) || I18N.saveFailed);
    box.textContent = msg;
  }
});

document.getElementById('clear-form').addEventListener('click', () => {
  document.getElementById('parcel-form').reset();
  populateProvSelects();
  document.getElementById('dist-select').innerHTML = '<option value="">' + esc(I18N.select) + '</option>';
  document.getElementById('vill-select').innerHTML = '<option value="">' + esc(I18N.select) + '</option>';
  document.getElementById('addr-dist-select').innerHTML = '<option value="">' + esc(I18N.select) + '</option>';
  document.getElementById('addr-vill-select').innerHTML = '<option value="">' + esc(I18N.select) + '</option>';
  document.getElementById('save-result').style.display = 'none';
});

/* ---------- SCAN TAB ---------- */
let lastScan = null;

function confBadge(c) {
  if (c === null || c === undefined || c === '' || c === false)
    return '<span class="conf conf-none">—</span>';
  c = parseInt(c, 10);
  const cls = c >= 80 ? 'conf-high' : (c >= 50 ? 'conf-mid' : 'conf-low');
  return '<span class="conf ' + cls + '">' + c + '%</span>';
}

document.getElementById('process-ocr').addEventListener('click', async () => {
  const file = document.getElementById('scan-file').files[0];
  if (!file) { showScanError(I18N.chooseFile); return; }
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
      method: 'POST', body: fd,
      headers: { 'Authorization': 'Bearer ' + TOKEN },
    });
    const j = await res.json();
    if (!j.ok) { showScanError((j.error && j.error.message) || I18N.ocrFailed); return; }
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
  if (typeof parsed === 'string') { try { parsed = JSON.parse(parsed); } catch(e) { parsed = {}; } }
  parsed = parsed || {};
  let conf = scan.field_confidence;
  if (typeof conf === 'string') { try { conf = JSON.parse(conf); } catch(e) { conf = {}; } }
  conf = conf || {};

  document.getElementById('scan-preview').src = scan.image_path;
  document.getElementById('overall-conf').innerHTML =
    '<span class="overall">' + esc(I18N.conf) + ' ' +
    esc(scan.overall_confidence ?? '—') +
    ((scan.overall_confidence !== null && scan.overall_confidence !== undefined) ? '%' : '') + '</span>';
  document.getElementById('ocr-raw').textContent =
    (typeof scan.ocr_raw === 'string' ? scan.ocr_raw : JSON.stringify(scan.ocr_raw, null, 2)) || '—';

  const keys = Object.keys(parsed);
  document.getElementById('parsed-fields').innerHTML = keys.length
    ? '<table class="parsed-table"><tbody>' + keys.map(k =>
        '<tr><td>' + esc(PARSING_LABELS[k] || k) + '</td><td>' +
        esc(Array.isArray(parsed[k]) ? parsed[k].join(', ') : parsed[k]) +
        '</td><td>' + confBadge(conf[k]) + '</td></tr>'
      ).join('') + '</tbody></table>'
    : '<p class="empty">' + esc(I18N.noFields) + '</p>';
  document.getElementById('scan-results').classList.add('show');
}

document.getElementById('apply-ocr').addEventListener('click', async () => {
  if (!lastScan) return;
  let parsed = lastScan.parsed_fields;
  if (typeof parsed === 'string') { try { parsed = JSON.parse(parsed); } catch(e) { parsed = {}; } }
  parsed = parsed || {};

  // Parcel fields
  const map = {
    title_no: 'ref_no', order_no: 'issue_batch', location_code: 'location',
    unit_no: 'unit', acquisition_method: 'acquired', ownership_status: 'status',
    land_use_class: 'land_type', zone_text: 'zone', book_no: 'book',
    page_no: 'leaf', map_ref_no: 'map_no', parcel_no: 'parcel_no',
    map_parcel_no: 'parcel_no', area_sqm: 'area', map_scale: 'scale',
    issued_place: 'issued_at',
  };
  Object.entries(map).forEach(([apiKey, formName]) => {
    if (parsed[apiKey]) setVal(formName, parsed[apiKey]);
  });

  // Land location cascade: set district_id and village_id selects
    if (parsed.district_id) {
      document.getElementById('prov-select').value = '';
      document.getElementById('dist-select').innerHTML =
        '<option value="">' + esc(I18N.select) + '</option>' +
        allDistricts.map(d => '<option value="' + d.id + '">' + esc(d.name_lo) + '</option>').join('');
      document.getElementById('dist-select').value = String(parsed.district_id);
      document.getElementById('dist-select').dispatchEvent(new Event('change'));
      // The dist-select change handler loads villages asynchronously; wait for them
      // before setting the village and firing the zone auto-fill.
      const dId = parseInt(parsed.district_id, 10);
      const vils = await ensureVillages(dId);
      allVillages = vils;
      document.getElementById('vill-select').innerHTML =
        '<option value="">' + esc(I18N.select) + '</option>' +
        vils.map(v => '<option value="' + v.id + '">' + esc(v.name_lo) + '</option>').join('');
      if (parsed.village_id) {
        setVal('village_id', parsed.village_id);
        // Fire the change so the valuation-zone auto-fill runs for the scanned village.
        document.getElementById('vill-select').dispatchEvent(new Event('change'));
      }
    }

  // Owner (#8–#11)
  const names = parsed.owner_names || (parsed.owner_name ? [parsed.owner_name] : []);
  if (names[0]) setVal('owner_name', names[0]);
  if (parsed.date_of_birth)          setVal('dob', parsed.date_of_birth);
  if (parsed.nationality)            setVal('nationality', parsed.nationality);
  if (parsed.occupation)             setVal('occupation', parsed.occupation);

  // Address (#12–#15)
  if (parsed.owner_address_province) setVal('addr_province', parsed.owner_address_province);
  if (parsed.owner_address_district) setVal('addr_district', parsed.owner_address_district);
  if (parsed.owner_address_village)  setVal('addr_village', parsed.owner_address_village);
  if (parsed.owner_address_unit)     setVal('addr_unit', parsed.owner_address_unit);

  // Spouse (#16–#19)
  if (parsed.spouse_name)            setVal('spouse_name', parsed.spouse_name);
  if (parsed.spouse_dob)             setVal('spouse_dob', parsed.spouse_dob);
  if (parsed.spouse_nationality)     setVal('spouse_nat', parsed.spouse_nationality);
  if (parsed.spouse_occupation)      setVal('spouse_occ', parsed.spouse_occupation);

  // Issued date (#31–#33)
  if (parsed.issued_date && /^\d{4}-\d{2}-\d{2}$/.test(parsed.issued_date)) {
    const [py, pm, pd] = parsed.issued_date.split('-');
    setVal('issue_year', py); setVal('issue_month', pm); setVal('issue_day', pd);
  }

  switchTab('manual');
  showResult(I18N.applied, true);
});

document.getElementById('reprocess').addEventListener('click', () => {
  document.getElementById('scan-file').value = '';
  document.getElementById('scan-results').classList.remove('show');
  document.getElementById('scan-file').focus();
});

/* ---------- IMPORT TAB ---------- */
document.getElementById('download-template').addEventListener('click', () => {
  window.open('/api/v1/parcels/import.php?template=1', '_blank');
});
document.getElementById('do-import').addEventListener('click', async () => {
  const file = document.getElementById('import-file').files[0];
  const box = document.getElementById('import-result');
  box.style.display = 'none';
  if (!file) { box.className = 'preview-box err'; box.textContent = I18N.chooseFile; box.style.display = 'block'; return; }
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
  if (errs.length) html += '<ul>' + errs.slice(0,20).map(r => '<li>Line ' + r.line + ': ' + esc(r.message) + '</li>').join('') + '</ul>';
  box.innerHTML = html;
});

// Pre-fill form when editing an existing parcel
function prefillEditParcel() {
  if (!EDIT_MODE || !EDIT_PARCEL) return;
  const p = EDIT_PARCEL;

  // Direct text inputs
  const directMap = {
    'title_no': 'ref_no',
    'order_no': 'issue_batch',
    'location_code': 'location',
    'unit_no': 'unit',
    'acquisition_method': 'acquired',
    'ownership_status': 'status',
    'land_use_class': 'land_type',
    'zone_text': 'zone',
    'book_no': 'book',
    'page_no': 'leaf',
    'map_ref_no': 'map_no',
    'parcel_no': 'parcel_no',
    'area_sqm': 'area',
    'map_scale': 'scale',
    'issued_place': 'issued_at',
    'sub_parcel_no': 'sub_parcel_no',
  };
  Object.entries(directMap).forEach(([apiKey, formName]) => {
    if (p[apiKey]) setVal(formName, p[apiKey]);
  });

  // Select dropdowns - these need to be set after loadGeoData populates them
  // We'll set them after a short delay to let the cascades populate
  setTimeout(() => {
    if (p.district_id) setVal('district_id', p.district_id);
    if (p.village_id) setVal('village_id', p.village_id);
    if (p.zone_code) setVal('zone_code', p.zone_code);
    if (p.road_category) setVal('road_category', p.road_category);

    // Issued date
    if (p.issued_date && /^\d{4}-\d{2}-\d{2}$/.test(p.issued_date)) {
      const [py, pm, pd] = p.issued_date.split('-');
      setVal('issue_year', py);
      setVal('issue_month', pm);
      setVal('issue_day', pd);
    }

    // Owners
    if (EDIT_OWNERS && EDIT_OWNERS.length) {
      const o = EDIT_OWNERS[0];
      if (o.full_name) setVal('owner_name', o.full_name);
      if (o.date_of_birth) setVal('dob', o.date_of_birth);
      if (o.nationality) setVal('nationality', o.nationality);
      if (o.occupation) setVal('occupation', o.occupation);
      if (o.address_province) setVal('addr_province', o.address_province);
      if (o.address_district) setVal('addr_district', o.address_district);
      if (o.address_village) setVal('addr_village', o.address_village);
      if (o.address_unit) setVal('addr_unit', o.address_unit);
    }
    if (EDIT_OWNERS && EDIT_OWNERS.length > 1) {
      const s = EDIT_OWNERS[1];
      if (s.full_name) setVal('spouse_name', s.full_name);
      if (s.date_of_birth) setVal('spouse_dob', s.date_of_birth);
      if (s.nationality) setVal('spouse_nat', s.nationality);
      if (s.occupation) setVal('spouse_occ', s.occupation);
    }
  }, 300);
}

prefillEditParcel();

loadGeoData();
</script>
</body>
</html>
