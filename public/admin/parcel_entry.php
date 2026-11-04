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
if ($is_manager) { header('Location: monitor.php'); exit; }   // data entry is not a manager screen
$role = $user['role'];
if (!in_array($role, ['officer','admin'], true)) { http_response_code(403); exit(t('Insufficient permissions.')); }
$token = $_SESSION['admin_token'];

// ---- Scan review (the single scan → parcel workflow) ----
// Upload, OCR review, confirm and discard all live in the Scan tab below.
// scans.php is only a redirect here, so there is one confirm path and every
// scan that becomes a parcel gets its fee obligation assessed.
$canWrite = !empty($user['is_super'])
    || (($user['level'] ?? '') === 'district' && in_array($role, ['admin', 'officer'], true));
$canDiscardScan = function (array $s) use ($user, $canWrite) {
    if (!$canWrite) return false;
    if ($user['role'] === 'admin') return true;
    return (int)($s['created_by'] ?? 0) === (int)($user['sub'] ?? 0);
};

$scanId = isset($_GET['scan_id']) ? (int)$_GET['scan_id'] : 0;
$scan = null; $scanParcel = []; $scanDistrictId = 0; $scanVillageId = 0;
$scanParsed = []; $scanFieldConf = []; $scanOverallConf = null; $scanOwners = [];
$scanErrors = []; $scanSuccess = '';

// --- POST: upload a new scan ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $ocrMethod = $_POST['ocr_method'] ?? 'tesseract';
    $data = api_upload('scans.php', $_FILES['image']['tmp_name'], $_FILES['image']['name'], $_FILES['image']['type'], $token, 'image', ['ocr_method' => $ocrMethod]);
    if (!empty($data['ok']) && !empty($data['data']['id'])) {
        header('Location: parcel_entry.php?tab=scan&scan_id=' . (int)$data['data']['id']);
        exit;
    }
    $scanErrors[] = $data['error']['message'] ?? t('Upload failed');
}

// --- POST: confirm/edit → create or update parcel (+ owners) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['confirm_parcel']) && $scanId > 0) {
    $owners = [];
    foreach (($_POST['owners'] ?? []) as $o) {
        if (trim($o['full_name'] ?? '') === '') continue;
        $owners[] = [
            'id'        => ($o['id'] ?? '') !== '' ? (int)$o['id'] : null,
            'full_name' => $o['full_name'],
            'id_number' => $o['id_number'] ?? '',
            'address'   => $o['address'] ?? '',
            'role'      => $o['role'] ?? 'owner',
        ];
    }
    $body = [
        'parcel' => [
            'parcel_no'      => $_POST['parcel_no'] ?? '',
            'map_ref_no'     => $_POST['map_ref_no'] ?? '',
            'area_sqm'       => $_POST['area_sqm'] ?? '',
            'road_category'  => $_POST['road_category'] ?? '',
            'zone_code'      => $_POST['zone_code'] ?? '',
            'village_id'     => $_POST['village_id'] ?? '',
            'district_id'    => $_POST['district_id'] ?? '',
            'status'         => $_POST['status'] ?? 'confirmed',
            'title_no'       => $_POST['title_no'] ?? '',
            'title_type'     => 'land_title', // only one document type is handled
            'book_no'        => $_POST['book_no'] ?? '',
            'volume_no'      => $_POST['volume_no'] ?? '',
            'page_no'        => $_POST['page_no'] ?? '',
            'land_use_class' => $_POST['land_use_class'] ?? '',
            'issued_date'    => $_POST['issued_date'] ?? '',
        ],
        'owners' => $owners,
    ];
    $data = api_call('POST', 'scans/confirm.php?id=' . $scanId, $body, $token);
    if (!empty($data['ok'])) {
        $scanSuccess = t('Parcel confirmed — ID {id} ({n} owner(s))', [
            'id' => $data['data']['parcel_id'],
            'n'  => count($data['data']['owner_ids'] ?? []),
        ]);
        $scanId = 0;
    } else {
        $scanErrors = $data['error']['fields'] ?? [$data['error']['message'] ?? t('Failed to confirm')];
        if (!is_array($scanErrors)) $scanErrors = [$scanErrors];
    }
}

// --- POST: discard → permanently delete the scan (no parcel) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['discard_scan_id'])) {
    $discardId = (int)$_POST['discard_scan_id'];
    $data = api_call('POST', 'scans/delete.php?id=' . $discardId, [], $token);
    if (!empty($data['ok'])) {
        $scanSuccess = t('Scan discarded.');
        if ($discardId === $scanId) $scanId = 0;
    } else {
        $scanErrors[] = $data['error']['message'] ?? t('Discard failed');
    }
}

// --- Load the scan for review ---
if ($scanId > 0) {
    $data = api_call('GET', 'scans.php?id=' . $scanId, null, $token);
    if (!empty($data['ok'])) {
        $scan = $data['data']['scan'];
        $scanParcel = $data['data']['parcel'] ?? [];
        $scanDistrictId = (int)($data['data']['district_id'] ?? 0);
        $scanVillageId = (int)($data['data']['village_id'] ?? 0);
        $scanParsed = !empty($scan['parsed_fields']) ? (json_decode($scan['parsed_fields'], true) ?: []) : [];
        $scanFieldConf = !empty($scan['field_confidence']) ? (json_decode($scan['field_confidence'], true) ?: []) : [];
        $scanOverallConf = $scan['overall_confidence'];
        if (!$scanDistrictId && !empty($scanParsed['district_id'])) $scanDistrictId = (int)$scanParsed['district_id'];
        if (!$scanVillageId && !empty($scanParsed['village_id'])) $scanVillageId = (int)$scanParsed['village_id'];

        if (!empty($scanParcel['id'])) {
            $scanOwners = api_call('GET', 'owners.php?parcel_id=' . (int)$scanParcel['id'], null, $token)['data']['owners'] ?? [];
        }
        if (!$scanOwners) {
            $names = $scanParsed['owner_names'] ?? (!empty($scanParsed['owner_name']) ? [$scanParsed['owner_name']] : []);
            foreach ($names as $n) {
                $scanOwners[] = ['full_name' => $n, 'id_number' => $scanParsed['owner_id_number'] ?? '', 'address' => $scanParsed['owner_address'] ?? ''];
            }
        }
    } else {
        $scanErrors[] = $data['error']['message'] ?? t('Scan not found');
        $scanId = 0;
    }
}

// Prefer an already-saved parcel value, else fall back to the parsed field.
$val = function ($key) use ($scanParcel, $scanParsed) {
    if (isset($scanParcel[$key]) && $scanParcel[$key] !== '' && $scanParcel[$key] !== null) return $scanParcel[$key];
    return $scanParsed[$key] ?? '';
};
function scan_conf_badge($c) {
    if ($c === null || $c === '' || $c === false) return '<span class="conf conf-none">—</span>';
    $c = (int)$c;
    $cls = $c >= 80 ? 'conf-high' : ($c >= 50 ? 'conf-mid' : 'conf-low');
    return '<span class="conf ' . $cls . '">' . $c . '%</span>';
}

// --- Data for the Scan tab ---
$scanProvinces = api_call('GET', 'refdata.php?table=provinces')['data'] ?? [];
$allDistricts = api_call('GET', 'districts.php', null, $token)['data'] ?? [];
$scanProvinceId = 0;
if ($scanDistrictId) {
    foreach ($allDistricts as $d) {
        if ((int)$d['id'] === $scanDistrictId) { $scanProvinceId = (int)$d['province_id']; break; }
    }
}
$scanDistricts = $scanProvinceId
    ? array_values(array_filter($allDistricts, fn($d) => (int)$d['province_id'] === $scanProvinceId))
    : [];
$scanVillages = $scanDistrictId ? (api_call('GET', 'villages.php?district_id=' . $scanDistrictId)['data'] ?? []) : [];
$scanZones = api_call('GET', 'rates.php', null, $token)['data']['zones'] ?? [];
// The scan review mirrors the entry form: the zone list stays empty until a
// district *and* village are known, then offers only the village's own zone —
// and only once that zone has rate rows. An existing parcel keeps its saved
// zone visible even if it predates the mapping, so the officer can correct it.
$scanZoneOpts = [];
if ($scanDistrictId && $scanVillageId) {
    $scanVillageRow = null;
    foreach ($scanVillages as $sv) {
        if ((int)$sv['id'] === $scanVillageId) { $scanVillageRow = $sv; break; }
    }
    $scanVillageZone = $scanVillageRow && !empty($scanVillageRow['zone_id'])
        ? strtoupper(trim((string)$scanVillageRow['zone_id'])) : '';
    if ($scanVillageZone !== '') {
        foreach ($scanZones as $sz) {
            if (strtoupper((string)$sz['code']) === $scanVillageZone
                && (int)($sz['district_id'] ?? 0) === $scanDistrictId
                && !empty($sz['tiers'])) {
                $scanZoneOpts[] = $sz;
                break;
            }
        }
    }
}
$savedScanZone = !empty($scanParcel['id']) ? strtoupper(trim((string)($scanParcel['zone_code'] ?? ''))) : '';
if ($savedScanZone !== '' && !in_array($savedScanZone, array_column($scanZoneOpts, 'code'), true)) {
    $scanZoneOpts[] = ['code' => $savedScanZone, 'district_name' => '', 'plan_zone' => ''];
}
$pendingScans = api_call('GET', 'scans.php?status=pending', null, $token)['data']['scans'] ?? [];

$parsedLabels = [
    'parcel_no' => t('Parcel no'), 'map_ref_no' => t('Map sheet'), 'area_sqm' => t('Area (m²)'),
    'title_no' => t('Title no'), 'book_no' => t('Book'),
    'volume_no' => t('Volume'), 'page_no' => t('Page'), 'land_use_class' => t('Land use'),
    'issued_date' => t('Issued date'), 'province' => t('Province'),
    'district_name' => t('District (text)'), 'village_name' => t('Village (text)'),
    'district_id' => t('District id'), 'village_id' => t('Village id'),
    'owner_name' => t('Owner name'), 'owner_id_number' => t('Owner ID'), 'owner_address' => t('Owner address'),
];
$landUseClasses = ['residential' => t('Residential'), 'agricultural' => t('Agricultural'), 'commercial' => t('Commercial'), 'other' => t('Other')];

$initialTab = $_GET['tab'] ?? 'manual';
if ($scanId > 0) $initialTab = 'scan';
if (!in_array($initialTab, ['manual', 'scan', 'import'], true)) $initialTab = 'manual';

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
  .entry-tabs button { padding: 9px 18px; border: 1px solid #ccc; background: #fff; cursor: pointer; border-radius: 4px; font-size: 16px; }
  .entry-tabs button.active { background: #4a7c59; color: #fff; border-color: #4a7c59; }
  .ocr-method { display: flex; flex-wrap: wrap; gap: 8px 16px; }
  .ocr-method label { display: flex; align-items: center; gap: 6px; cursor: pointer; }
  .owner-row { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)) auto; gap: 8px; align-items: end; margin-bottom: 8px; }
  @media (max-width: 720px) { .owner-row { grid-template-columns: 1fr; } }

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
      border-bottom: 2px solid #3a6a4a;
      background: #4a7c59;
    }
    .cert-head .l1 { font-size: 16px; color: #fff; }
    .cert-head .l2 { font-size: 14px; color: #eef5ef; }
    .cert-head .l3 { font-size: 14px; color: #eef5ef; }
    /* Tax footer block — like the header frame, distinct colour, sits below
       the certificate fields (these two are system fields, not on the paper form). */
    .cert-foot {
      border-top: 2px solid #4a7c59;
      background: #e8f0ea;
      padding: 12px;
      text-align: left;
    }
    .cert-foot .ft-title { font-size: 16px; font-weight: 700; color: #1b5e20; margin-bottom: 8px; }
    .cert-foot .inline { display: flex; flex-wrap: wrap; gap: 10px 20px; }
    .cert-foot .inline .ci { display: flex; align-items: center; gap: 6px; }
    .cert-foot .inline .ci label { min-width: 0; font-weight: 600; font-size: 16px; color: #1b5e20; }
    .cert-foot .inline .ci select { width: 200px; padding: 7px 9px; border: 1px solid #4a7c59; border-radius: 3px; font-size: 16px; }
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
      font-size: 16px; font-weight: 600; color: #222;
      min-width: 150px;
    }
    .cert-row .val {
      flex: 1;
      min-width: 200px;
    }
    .cert-row input, .cert-row select {
      padding: 7px 9px; border: 1px solid #ccc; border-radius: 3px;
      font-size: 16px; width: 100%; box-sizing: border-box;
    }
    .cert-row input:focus, .cert-row select:focus {
      border-color: #4a7c59; outline: none;
    }
    .cert-row .inline { display: flex; flex-wrap: wrap; gap: 6px 16px; }
    .cert-row .inline .ci { display: flex; align-items: center; gap: 6px; }
    .cert-row .inline .ci label { min-width: 0; font-weight: 500; font-size: 14px; color: #444; }
    .cert-row .inline .ci input, .cert-row .inline .ci select { width: 130px; }
    /* Land location (#4–#6): three equal columns on a computer; on a phone the
       three selects stack in one label column so none of them shifts sideways
       when the row wraps. */
    .cert-row.geo .inline {
      flex: 1 1 300px;
      display: grid;
      /* Three columns when the row is wide enough; otherwise they wrap, but
         always starting in the same column, so the selects stay aligned. */
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 6px 16px;
    }
    .cert-row.geo .inline .ci {
      display: grid;
      /* One fixed label track for all three, so a wrapped row keeps every
         select on the same x — a max-content label would shift each one. */
      grid-template-columns: 6em minmax(0, 1fr);
      align-items: center;
      gap: 6px;
    }
    .cert-row.geo .inline .ci select { width: 100%; min-width: 0; }
    @media (max-width: 1024px) {
      .cert-row.geo { display: block; }
      .cert-row.geo > label { display: block; margin-bottom: 6px; }
      .cert-row.geo .inline { flex: none; grid-template-columns: 1fr; gap: 8px; }
    }
    .cert-row.wide { }
    @media (max-width: 768px) { .cert-row .val { min-width: 100%; } }

  /* Phase 1: only the fields an officer must fill carry the green treatment —
     the same frame as the tax footer. No asterisks and no "(optional)" wording;
     the colour says it. */
  .req-field input, .req-field select {
    border: 1px solid #4a7c59;
    background: #e8f0ea;
  }
  .req-field input:focus, .req-field select:focus { background: #fff; }
  /* The two footer fields are required too — give them the same fill as the
     seven above, so all nine read identically (green border + light green). */
  .cert-foot select { background: #e8f0ea; }
  .cert-foot select:focus { background: #fff; }
  .cert-row .inline .ci.req-field label { color: #1b5e20; font-weight: 600; }
  .cert-row.req-row > label { color: #1b5e20; }
  .req-legend {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    padding: 8px 0 10px; font-size: 14px; font-weight: 600; color: #fff;
  }
  .req-legend .swatch {
    display: inline-block; width: 24px; height: 16px; border-radius: 3px;
    border: 1px solid #fff; background: #e8f0ea;
  }
  /* Certificate fields that Phase 1 does not require stay available, but folded
     away so the working form is short. Order inside keeps the paper's numbering. */
  .opt-block {
    margin: 10px 12px; border: 1px dashed #c9c0aa; border-radius: 4px;
    background: #fffdf8; padding: 0;
  }
  .opt-block > summary {
    cursor: pointer; padding: 9px 12px; font-size: 16px;
    font-weight: 600; color: #6b5b3e;
  }
  .opt-block[open] > summary { border-bottom: 1px dashed #c9c0aa; }
  .opt-block .cert-row { padding-left: 12px; padding-right: 12px; }
  /* Tax estimate under the footer fields. */
  .tax-preview {
    display: none; margin-top: 10px; padding: 8px 10px; border-radius: 4px;
    background: #fff; border: 1px solid #4a7c59; font-size: 16px; color: #1b5e20;
  }
  .tax-preview.show { display: block; }
  .tax-preview strong { font-size: 18px; }
  .zone-warn { display: none; margin-top: 6px; font-size: 14px; font-weight: 600; color: #b71c1c; }
  .zone-warn.show { display: block; }

  .form-actions { display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap; }
  .preview-box { padding: 10px 12px; margin-top: 14px; font-size: 16px; border-radius: 4px; background: #fafafa; border: 1px solid #ddd; }
  .preview-box.ok { background: #eaf4ea; border-color: #2e7d32; }
  .preview-box.err { background: #fdecea; border-color: #c62828; }

  /* Scan tab */
  .entry-tools { background: #f5f5f5; padding: 14px; margin-bottom: 14px; border-radius: 4px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
  .entry-tools .grow { flex: 1; }
  .ocr-method { display: flex; gap: 16px; flex-wrap: wrap; margin: 8px 0; }
  .ocr-method label { display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 16px; }
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
        <button class="<?= $initialTab === 'manual' ? 'active' : '' ?>" data-tab="manual"><?= te('Manual') ?></button>
        <button class="<?= $initialTab === 'scan' ? 'active' : '' ?>" data-tab="scan"><?= te('Scan') ?></button>
        <button class="<?= $initialTab === 'import' ? 'active' : '' ?>" data-tab="import"><?= te('Import') ?></button>
      </div>

      <!-- ========== MANUAL TAB ========== -->
      <div data-content="manual"<?= $initialTab === 'manual' ? '' : ' style="display:none;"' ?>>
        <form id="parcel-form">
          <div class="cert-grid">
            <!-- Certificate header -->
            <div class="cert-head">
              <div class="l1"><?= te('Ministry of Agriculture and Environment') ?></div>
              <div class="l2"><?= te('Province/District Land &amp; Environment Office') ?></div>
              <div class="l3"><?= te('LAND TITLE') ?> — <?= te('ໃບຕາດິນ') ?></div>
              <div class="req-legend"><span class="swatch"></span><?= te('Required fields') ?></div>
            </div>
            <!-- #1 -->
            <div class="cert-row req-row">
              <label><?= te('Title no') ?></label>
              <div class="val req-field"><input type="text" name="ref_no" required></div>
            </div>
            <!-- #4-6: land location. #7 Unit moved into Optional details. -->
            <div class="cert-row geo">
              <label><?= te('Parcel address') ?></label>
              <div class="inline">
                <span class="ci req-field"><label><?= te('Province') ?></label><select name="province" id="prov-select" required><option value=""></option></select></span>
                <span class="ci req-field"><label><?= te('District') ?></label><select name="district_id" id="dist-select" required><option value=""></option></select></span>
                <span class="ci req-field"><label><?= te('Village') ?></label><select name="village_id" id="vill-select" required><option value=""></option></select></span>
              </div>
            </div>
            <!-- #8 -->
            <div class="cert-row req-row">
              <label><?= te('Issued To') ?></label>
              <div class="val req-field"><input type="text" name="owner_name" required></div>
            </div>
            <!-- Owner phone: optional, but it is the bridge to the citizen app
                 (citizen sign-in is by phone number), so it stays visible. -->
            <div class="cert-row">
              <label><?= te('Phone') ?></label>
              <div class="val"><input type="text" name="owner_phone" placeholder="020 5555 1234"></div>
            </div>
            <!-- #26 then #27: the map sheet + parcel number on that sheet are the
                 parcel's identity on paper — a number can repeat on other sheets. -->
            <div class="cert-row req-row">
              <label><?= te('Map sheet') ?></label>
              <div class="val req-field"><input type="text" name="map_no" required placeholder="e.g. A250-038"></div>
            </div>
            <!-- #27 -->
            <div class="cert-row req-row">
              <label><?= te('Parcel No') ?></label>
              <div class="val req-field"><input type="text" name="parcel_no" required></div>
            </div>
            <!-- #28 -->
            <div class="cert-row req-row">
              <label><?= te('Land Area') ?></label>
              <div class="val req-field"><input type="number" step="0.01" min="0" name="area" required></div>
            </div>

            <!-- Certificate fields Phase 1 does not require. Kept in certificate
                 order, so an officer can still follow the paper top-to-bottom. -->
            <details class="opt-block" id="opt-block">
              <summary><?= te('Optional details') ?></summary>
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
              <!-- #7 -->
              <div class="cert-row">
                <label><?= te('Unit') ?></label>
                <div class="val"><input type="text" name="unit"></div>
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
              <!-- #12-15: current address (cascade order: prov, dist, vill, unit) -->
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
              <!-- #22/#23. Land Type is a lookup code (FK: land_use_classes), not
                   free text — options are loaded from the API. #23 zone_text is
                   descriptive only; the real tax zone is in the footer. -->
              <div class="cert-row">
                <label><?= te('Land type / Zone') ?></label>
                <div class="inline">
                  <span class="ci" style="flex:1; min-width:200px;"><label><?= te('Land Type') ?></label><select name="land_type" id="land-type-select"><option value=""><?= te('-- select --') ?></option></select></span>
                  <span class="ci" style="flex:1; min-width:200px;"><label><?= te('Zone') ?></label><input type="text" name="zone" placeholder="e.g. ດິນປຽງ — ຕາມເຂດບ້ານ"></span>
                </div>
              </div>
              <!-- #24-25: registration book / leaf. (#26 map sheet and #27
                   parcel no. are required and sit at the top of the form.) -->
              <div class="cert-row">
                <label><?= te('Registration') ?></label>
                <div class="inline">
                  <span class="ci"><label><?= te('Registration Book') ?></label><input type="text" name="book"></span>
                  <span class="ci"><label><?= te('Leaf No') ?></label><input type="text" name="leaf"></span>
                </div>
              </div>
              <!-- #29 -->
              <div class="cert-row">
                <label><?= te('Scale') ?></label>
                <div class="val"><input type="text" name="scale" placeholder="e.g. 1/8000"></div>
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
            </details>
            <!-- System fields (not on the paper certificate): valuation zone + road access -->
            <div class="cert-foot">
                          <div class="ft-title"><?= te('Tax') ?></div>
                          <div class="inline">
                            <span class="ci"><label><?= te('Valuation zone (tax)') ?></label><select name="zone_code" id="zone-code-select" required><option value=""><?= te('-- select --') ?></option></select></span>
                            <!-- No default: Road access decides which of the zone's four rates
                                 applies, so it must be a deliberate choice. Defaulting it to
                                 "none" silently billed every parcel at the lowest rate. -->
                            <span class="ci"><label><?= te('Road access') ?></label><select name="road_category" id="road-category-select" required>
                              <option value=""><?= te('-- select --') ?></option>
                              <?php foreach (['main' => 'Main road', 'branch' => 'Branch road', 'alley' => 'Alley', 'none' => 'None'] as $rc => $rcLabel): ?>
                              <option value="<?= $rc ?>"><?= te($rcLabel) ?></option>
                              <?php endforeach; ?>
                            </select></span>
                          </div>
                          <div class="zone-warn" id="zone-dist-warn"></div>
                          <div class="zone-warn" id="zone-warn"><?= te('This village is not mapped to a valuation zone — ask the administrator to map it before this parcel can be billed.') ?></div>
                          <div class="zone-warn" id="zone-rate-warn"></div>
                          <div class="tax-preview" id="tax-preview"></div>
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
      <!-- The only scan → parcel workflow: upload + OCR, review the parsed
           fields, confirm/correct, or discard. It used to be scans.php; that
           URL now redirects here so there is one confirm path. -->
      <div data-content="scan"<?= $initialTab === 'scan' ? '' : ' style="display:none;"' ?>>
        <?php if ($scanSuccess): ?><div class="form-box" style="border-color:#2e7d32; background:#eaf4ea;"><?= e($scanSuccess) ?></div><?php endif; ?>
        <?php foreach ($scanErrors as $err): ?><div class="form-box" style="border-color:#c62828; background:#fdecea;"><?= e(is_array($err) ? implode('; ', $err) : $err) ?></div><?php endforeach; ?>

        <?php if (!$scan): ?>
          <div class="form-box">
            <h3><?= te('Upload a Land Title Scan') ?></h3>
            <form method="post" enctype="multipart/form-data" action="parcel_entry.php?tab=scan">
              <div class="form-group">
                <label><?= te('Land title image (JPG/PNG)') ?></label>
                <input type="file" name="image" accept="image/*" required>
                <p class="sub" style="font-size:12px;"><?= te('OCR reads the title text and pre-fills the fields below. You confirm and correct everything.') ?></p>
              </div>
              <div class="form-group">
                <label><?= te('OCR Method') ?></label>
                <div class="ocr-method">
                  <label><input type="radio" name="ocr_method" value="tesseract" checked> <?= te('Tesseract (local, free, fast)') ?></label>
                  <label><input type="radio" name="ocr_method" value="gemini"> <?= te('Gemini 2.5 Flash (AI, higher accuracy, requires API key)') ?></label>
                </div>
              </div>
              <button type="submit" class="btn btn-green"><?= te('Upload & Process') ?></button>
            </form>
          </div>

          <div class="form-box">
            <h3><?= te('Pending in queue ({n})', ['n' => count($pendingScans)]) ?></h3>
            <?php if (empty($pendingScans)): ?>
              <p class="empty"><?= te('No scans waiting for review.') ?></p>
            <?php else: ?>
            <table>
              <thead><tr><th>#</th><th><?= te('Uploaded') ?></th><th><?= te('Uploaded by') ?></th><th><?= te('Status') ?></th><th><?= te('Conf.') ?></th><th><?= te('Parcel') ?></th><th></th></tr></thead>
              <tbody>
                <?php foreach ($pendingScans as $s): ?>
                <tr>
                  <td><?= (int)$s['id'] ?></td>
                  <td><?= e($s['created_at']) ?></td>
                  <td><?= e($s['created_by_name'] ?: '—') ?></td>
                  <td><span class="pill pill-pending"><?= e(t($s['status'])) ?></span></td>
                  <td><?= scan_conf_badge($s['overall_confidence'] ?? null) ?></td>
                  <td><?= e($s['parcel_no'] ?: '—') ?></td>
                  <td style="white-space:nowrap;">
                    <a class="btn btn-gold btn-sm" href="parcel_entry.php?tab=scan&scan_id=<?= (int)$s['id'] ?>"><?= te('Review →') ?></a>
                    <?php if ($canDiscardScan($s)): ?>
                    <form method="post" action="parcel_entry.php?tab=scan" style="display:inline;" onsubmit="return confirm(<?= e(json_encode(t('Discard this scan? The scan and its image will be deleted permanently.'), JSON_UNESCAPED_UNICODE)) ?>);">
                      <input type="hidden" name="discard_scan_id" value="<?= (int)$s['id'] ?>">
                      <button type="submit" class="btn btn-red btn-sm"><?= te('Discard') ?></button>
                    </form>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="grid" style="gap: 16px;">
            <div>
              <div class="form-box" style="border:2px solid #1b5e20;">
                <h3><?= te('Title scan #{id}', ['id' => (int)$scan['id']]) ?> &nbsp;<?= scan_conf_badge($scanOverallConf) ?></h3>
                <p class="sub"><?= e(t('Uploaded by {name}', ['name' => $scan['created_by_name'] ?: '—'])) ?></p>
                <img src="<?= e($scan['image_path']) ?>" style="width:100%; max-height:340px; object-fit:contain; border-radius:4px; background:#fff;" alt="<?= te('Scan') ?>">
              </div>
              <div class="form-box">
                <h3><?= te('OCR extracted text') ?></h3>
                <div class="ocr-box"><?= e($scan['ocr_raw'] ?? t('No text extracted.')) ?></div>
              </div>
            </div>
            <div>
              <div class="form-box">
                <h3><?= te('Parsed fields') ?></h3>
                <?php
                $shown = 0;
                ob_start(); ?>
                <table class="parsed-table"><tbody>
                <?php foreach ($parsedLabels as $k => $lbl):
                    if (!array_key_exists($k, $scanParsed) && !array_key_exists($k, $scanFieldConf)) continue;
                    $shown++;
                    $pv = $scanParsed[$k] ?? ''; ?>
                  <tr>
                    <td><?= e($lbl) ?></td>
                    <td><?= e(is_array($pv) ? implode(', ', $pv) : ($pv === '' ? '—' : $pv)) ?></td>
                    <td><?= scan_conf_badge($scanFieldConf[$k] ?? null) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody></table>
                <?php
                $tableHtml = ob_get_clean();
                echo $shown ? $tableHtml : '<p class="empty">' . te('No fields parsed from this scan — fill them in below.') . '</p>';
                ?>
              </div>
            </div>
          </div>

          <div class="form-box">
            <h3><?= te('Confirm / Edit Parcel Data') ?></h3>
            <form method="post" action="parcel_entry.php?tab=scan&amp;scan_id=<?= (int)$scan['id'] ?>">
              <input type="hidden" name="confirm_parcel" value="1">
              <div class="form-grid">
                <div class="form-group">
                  <label><?= te('Parcel number') ?></label>
                  <input name="parcel_no" value="<?= e($val('parcel_no')) ?>">
                </div>
                <div class="form-group">
                  <label><?= te('Map sheet') ?></label>
                  <input name="map_ref_no" value="<?= e($val('map_ref_no')) ?>" placeholder="e.g. A250-038" required>
                </div>
                <div class="form-group">
                  <label><?= te('Area (m²)') ?></label>
                  <input name="area_sqm" type="number" step="0.01" value="<?= e($val('area_sqm')) ?>">
                </div>
                <div class="form-group">
                  <label><?= te('Zone (fee rate)') ?></label>
                  <select name="zone_code" id="scan-zone-select" required>
                    <option value=""><?= te('-- select --') ?></option>
                    <?php foreach ($scanZoneOpts as $z): ?>
                    <option value="<?= e($z['code']) ?>" <?= ($val('zone_code')) === $z['code'] ? 'selected' : '' ?>><?= e($z['code']) ?><?= !empty($z['district_name']) ? ' — ' . e($z['district_name']) : '' ?><?= !empty($z['plan_zone']) ? ' / ' . e($z['plan_zone']) : '' ?></option>
                    <?php endforeach; ?>
                  </select>
                  <div class="zone-warn" id="scan-zone-dist-warn"></div>
                  <div class="zone-warn" id="scan-zone-vill-warn"><?= te('This village is not mapped to a valuation zone — ask the administrator to map it before this parcel can be billed.') ?></div>
                  <div class="zone-warn" id="scan-zone-rate-warn"></div>
                </div>
                <div class="form-group">
                  <label><?= te('Road access') ?></label>
                  <select name="road_category" id="scan-road-select" required>
                    <?php foreach (['main' => 'Main road', 'branch' => 'Branch road', 'alley' => 'Alley', 'none' => 'None'] as $rc => $rcLabel): ?>
                    <option value="<?= $rc ?>" <?= ($val('road_category') ?: 'none') === $rc ? 'selected' : '' ?>><?= te($rcLabel) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label><?= te('Province') ?></label>
                  <select id="province-select">
                    <option value=""><?= te('-- select province --') ?></option>
                    <?php foreach ($scanProvinces as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= $scanProvinceId === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name_lo']) ?> <?= e($p['name_en'] ?: '') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label><?= te('District') ?></label>
                  <select name="district_id" id="district-select">
                    <option value=""><?= te('-- select --') ?></option>
                    <?php foreach ($scanDistricts as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= $scanDistrictId === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name_lo']) ?> <?= e($d['name_en'] ?: '') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label><?= te('Village') ?></label>
                  <select name="village_id" id="village-select">
                    <option value=""><?= te('-- select --') ?></option>
                    <?php foreach ($scanVillages as $v): ?>
                    <option value="<?= (int)$v['id'] ?>" data-zone="<?= e($v['zone_id'] ?? '') ?>" <?= $scanVillageId === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['name_lo']) ?> <?= e($v['name_en'] ?: '') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label><?= te('Title number') ?></label>
                  <input name="title_no" value="<?= e($val('title_no')) ?>">
                </div>
                <div class="form-group">
                  <label><?= te('Book') ?></label>
                  <input name="book_no" value="<?= e($val('book_no')) ?>">
                </div>
                <div class="form-group">
                  <label><?= te('Volume') ?></label>
                  <input name="volume_no" value="<?= e($val('volume_no')) ?>">
                </div>
                <div class="form-group">
                  <label><?= te('Page') ?></label>
                  <input name="page_no" value="<?= e($val('page_no')) ?>">
                </div>
                <div class="form-group">
                  <label><?= te('Land use') ?></label>
                  <select name="land_use_class">
                    <option value=""><?= te('-- select --') ?></option>
                    <?php foreach ($landUseClasses as $v => $lbl): ?>
                    <option value="<?= $v ?>" <?= $val('land_use_class') === $v ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label><?= te('Issued date') ?></label>
                  <input name="issued_date" type="date" value="<?= e($val('issued_date')) ?>">
                </div>
                <div class="form-group">
                  <label><?= te('Status') ?></label>
                  <select name="status">
                    <option value="confirmed" <?= ($val('status') ?: 'confirmed') === 'confirmed' ? 'selected' : '' ?>><?= te('Confirmed') ?></option>
                    <option value="active" <?= $val('status') === 'active' ? 'selected' : '' ?>><?= te('Active') ?></option>
                  </select>
                </div>
              </div>

              <h3 style="margin-top:20px;"><?= te('Owner(s)') ?></h3>
              <div id="owners"></div>
              <button type="button" class="btn btn-blue btn-sm" onclick="addOwner()"><?= te('+ Add owner') ?></button>

              <div class="modal-actions" style="justify-content:flex-start;">
                <button type="submit" class="btn btn-green"><?= te('Confirm & Create Parcel') ?></button>
                <a href="parcel_entry.php?tab=scan" class="btn btn-gold"><?= te('New scan') ?></a>
              </div>
            </form>
            <?php if ($canDiscardScan($scan)): ?>
            <form method="post" action="parcel_entry.php?tab=scan&amp;scan_id=<?= (int)$scan['id'] ?>" style="margin-top:10px;" onsubmit="return confirm(<?= e(json_encode(t('Discard this scan? The scan and its image will be deleted permanently.'), JSON_UNESCAPED_UNICODE)) ?>);">
              <input type="hidden" name="discard_scan_id" value="<?= (int)$scan['id'] ?>">
              <button type="submit" class="btn btn-red"><?= te('Discard') ?></button>
            </form>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- ========== IMPORT TAB ========== -->
      <div data-content="import"<?= $initialTab === 'import' ? '' : ' style="display:none;"' ?>>
        <div class="entry-tools">
          <button type="button" class="btn btn-gold" id="download-template"><?= te('Download Template') ?></button>
          <input type="file" id="import-file" accept=".csv,text/csv" class="grow">
          <button type="button" class="btn btn-green" id="do-import"><?= te('Import &amp; Match') ?></button>
        </div>
        <p class="sub" style="font-size:14px;"><?= te('Import a CSV of parcels. Download the template, fill it in, then upload.') ?></p>
        <div id="import-result" class="preview-box" style="display:none;"></div>
      </div>
    </div>
  </main>
</div>

<script>
const TOKEN = '<?= e($token) ?>';
const I18N = <?= json_encode([
  'select'        => t('-- select --'),
  'savedId'       => t('Parcel saved — ID {id}'),
  'saveFailed'    => t('Save failed'),
  'importFailed'  => t('Import failed'),
  'chooseFile'    => t('Choose a file first.'),
  'taxEstimate'   => t('Estimated tax'),
  'kip'           => t('kip'),
  'zoneNoDistrict'=> t('No valuation zone has been created for this district yet — ask the administrator to add zone rates before billing.'),
  'zoneNoVillage' => t('This village is not mapped to a valuation zone — ask the administrator to map it before this parcel can be billed.'),
  'zoneNoVillageZone' => t('The valuation zone mapped to this village is not in the selected district — ask the administrator to fix the village mapping.'),
  'zoneNoRate'    => t('No zone rate has been set for this road access yet — ask the administrator to add it before billing.'),
  'fullName'      => t('Full name'),
  'idNumber'      => t('ID number'),
  'address'       => t('Address / residence'),
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
switchTab(<?= json_encode($initialTab) ?>);

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
  refreshZoneField();

  // Extract unique provinces from districts (each district has province_name)
  const provSet = new Set();
  allDistricts.forEach(d => {
    const p = d.province_name || d.province || '';
    if (p) provSet.add(p);
  });
  provinces = [...provSet].sort();
    populateProvSelects();
    await loadLandUseOptions();
  }

function populateProvSelects() {
  const opts = '<option value="">' + esc(I18N.select) + '</option>' +
    provinces.map(p => '<option value="' + esc(p) + '">' + esc(p) + '</option>').join('');
  document.getElementById('prov-select').innerHTML = opts;
  document.getElementById('addr-prov-select').innerHTML = opts;
  document.getElementById('issued-prov-select').innerHTML = opts;
}

/* ---------- valuation zone: blank until district + village resolve it ----------
   A zone belongs to one district (zones.district_id) and a village maps to
   exactly one zone (villages.zone_id), so the list is deliberately empty until
   a village is chosen. It also stays empty when the village's zone has no rate
   row yet — an officer cannot bill a parcel against an unrelated or unpriced
   zone. */
function zoneLabel(z) {
  return z.code + (z.district_name ? ' — ' + z.district_name : '') + (z.plan_zone ? ' / ' + z.plan_zone : '');
}
function zoneWarn(id, msg) {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = msg || '';
  el.classList.toggle('show', !!msg);
}
function zoneRateRows(z) {
  return (z && z.tiers) ? Object.keys(z.tiers).length : 0;
}
function setZoneOption(code) {
  const sel = document.getElementById('zone-code-select');
  if (!sel || !code) return;
  if (!Array.from(sel.options).some(o => o.value === code)) {
    const z = allZones.find(x => x.code === code);
    const opt = document.createElement('option');
    opt.value = code;
    opt.textContent = z ? zoneLabel(z) : code;
    sel.appendChild(opt);
  }
  sel.value = code;
}
function refreshZoneField() {
  const sel = document.getElementById('zone-code-select');
  if (!sel) return;
  sel.innerHTML = '<option value="">' + esc(I18N.select) + '</option>';
  zoneWarn('zone-dist-warn', '');
  zoneWarn('zone-warn', '');
  zoneWarn('zone-rate-warn', '');
  const did = parseInt(document.getElementById('dist-select').value, 10) || 0;
  if (!did) { updateTaxPreview(); return; }
  const districtZones = allZones.filter(z => Number(z.district_id) === did);
  if (!districtZones.length) {
    zoneWarn('zone-dist-warn', I18N.zoneNoDistrict);
    updateTaxPreview(); return;
  }
  const vid = parseInt(document.getElementById('vill-select').value, 10) || 0;
  if (!vid) { updateTaxPreview(); return; }
  const v = allVillages.find(x => x.id === vid);
  const code = v && v.zone_id ? v.zone_id : '';
  if (!code) {
    zoneWarn('zone-warn', I18N.zoneNoVillage);
    updateTaxPreview(); return;
  }
  const z = districtZones.find(x => x.code === code);
  if (!z) {
    zoneWarn('zone-dist-warn', I18N.zoneNoVillageZone);
    updateTaxPreview(); return;
  }
  if (!zoneRateRows(z)) {
    zoneWarn('zone-rate-warn', I18N.zoneNoRate);
    updateTaxPreview(); return;
  }
  sel.innerHTML += '<option value="' + esc(z.code) + '">' + esc(zoneLabel(z)) + '</option>';
  sel.value = z.code;
  updateTaxPreview();
}
// Warn when the chosen zone has no explicit rate row for the chosen road.
function rateWarning(zoneCode, road, zones) {
  if (!zoneCode) return '';
  const z = (zones || []).find(x => x.code === zoneCode);
  if (!z) return I18N.zoneNoRate;
  if (!road) return '';
  const hasTier = z.tiers && z.tiers[road];
  return hasTier ? '' : I18N.zoneNoRate;
}

/* ---------- land location cascade: #4 province → #5 district (id) → #6 village (id) ---------- */
document.getElementById('prov-select').addEventListener('change', function() {
  const prov = this.value;
  const opts = '<option value="">' + esc(I18N.select) + '</option>' +
    allDistricts.filter(d => !prov || (d.province_name || d.province) === prov)
      .map(d => '<option value="' + d.id + '">' + esc(d.name_lo) + '</option>').join('');
  document.getElementById('dist-select').innerHTML = opts;
  document.getElementById('vill-select').innerHTML = '<option value="">' + esc(I18N.select) + '</option>';
  refreshZoneField();
});

document.getElementById('dist-select').addEventListener('change', async function() {
  const dId = parseInt(this.value, 10);
  document.getElementById('vill-select').innerHTML = '<option value="">' + esc(I18N.select) + '</option>';
  refreshZoneField();
  if (!dId) { document.getElementById('vill-select').innerHTML = '<option value="">' + esc(I18N.select) + '</option>'; return; }
  const vils = await ensureVillages(dId);
  allVillages = vils;
  const opts = '<option value="">' + esc(I18N.select) + '</option>' +
    vils.map(v => '<option value="' + v.id + '">' + esc(v.name_lo) + '</option>').join('');
  document.getElementById('vill-select').innerHTML = opts;
});

/* The chosen village (#6) decides the valuation zone; refreshZoneField() keeps
   the list blank until the mapping and its rates both exist. */
document.getElementById('vill-select').addEventListener('change', refreshZoneField);

/* ---------- live tax estimate (rate × area) ----------
   The rate is the zone's tier for the chosen road access; rates.php already
   returns all four per zone, so this needs no extra request. Display only —
   the fee that is stored is computed server-side when the parcel is saved. */
function zoneRateFor(zoneCode, roadCategory) {
  const z = allZones.find(x => x.code === zoneCode);
  if (!z || !z.rates) return null;
  const r = z.rates[roadCategory];
  return (r === undefined || r === null || r === '') ? null : Number(r);
}
// A tier row is the rate actually maintained in Basic Data > Zone rates; the
// zone's single rate_per_sqm is only a legacy fallback, so it is not quoted.
function zoneHasTier(zoneCode, roadCategory) {
  const z = allZones.find(x => x.code === zoneCode);
  return !!(z && z.tiers && roadCategory && z.tiers[roadCategory]);
}
function updateTaxPreview() {
  const box = document.getElementById('tax-preview');
  if (!box) return;
  const zone = v('zone_code');
  const road = v('road_category');
  const area = parseFloat(v('area'));
  const rateWarn = document.getElementById('zone-rate-warn');
  // Only speak for a selected zone: with none, refreshZoneField() owns this
  // warning (e.g. "the village's zone has no rates at all").
  if (rateWarn && zone) {
    const msg = rateWarning(zone, road, allZones);
    rateWarn.textContent = msg;
    rateWarn.classList.toggle('show', !!msg);
  }
  const rate = (zone && road && zoneHasTier(zone, road)) ? zoneRateFor(zone, road) : null;
  if (!zone || !road || !(area > 0) || rate === null) {
    box.classList.remove('show');
    box.innerHTML = '';
    return;
  }
  const amount = Math.round(rate * area * 100) / 100;
  box.innerHTML = esc(I18N.taxEstimate) + ': <strong>' + amount.toLocaleString('en-US') + '</strong> '
    + esc(I18N.kip) + ' <span style="font-weight:500">('
    + rate.toLocaleString('en-US') + ' × ' + area + ' m²)</span>';
  box.classList.add('show');
}
// onchange/oninput properties rather than addEventListener: in mod_php an
// addEventListener('change') binding on a select can silently never fire.
document.getElementById('zone-code-select').onchange  = updateTaxPreview;
document.getElementById('road-category-select').onchange = updateTaxPreview;
const areaInput = document.querySelector('[name="area"]');
if (areaInput) areaInput.oninput = updateTaxPreview;

/* Land Type is a lookup code (FK → land_use_classes); load the options from the
   API so the form always matches the lookup table. Optional field: if it fails,
   the empty select simply stays empty. */
async function loadLandUseOptions() {
  const sel = document.getElementById('land-type-select');
  if (!sel) return;
  try {
    const r = await fetch('/api/v1/refdata.php?table=land_use_classes',
      { headers: { 'Authorization': 'Bearer ' + TOKEN } });
    const j = await r.json();
    const rows = Array.isArray(j.data) ? j.data : [];
    if (!rows.length) return;
    sel.innerHTML = '<option value="">' + esc(I18N.select) + '</option>' +
      rows.map(t => '<option value="' + esc(t.code) + '">' + esc(t.name_lo || t.name_en || t.code) + '</option>').join('');
  } catch (e) { /* optional field — leave it empty */ }
}

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
          phone:               v('owner_phone') || undefined,
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
    if (EDIT_MODE) {
      // Editing an existing parcel (?id=): leave the form and open the saved parcel's
      // detail so the officer sees the change took effect. Staying put and blanking the
      // fields looked like the Clear button had been pressed.
      const pid = encodeURIComponent(result.data.parcel_id);
      setTimeout(() => { window.location.href = 'parcel.php?id=' + pid + '&msg=saved'; }, 600);
    } else {
      // New parcel: blank the form so the next one can be entered.
      document.getElementById('parcel-form').reset();
      populateProvSelects();
      document.getElementById('dist-select').innerHTML = '<option value="">' + esc(I18N.select) + '</option>';
      document.getElementById('vill-select').innerHTML = '<option value="">' + esc(I18N.select) + '</option>';
      document.getElementById('addr-dist-select').innerHTML = '<option value="">' + esc(I18N.select) + '</option>';
      document.getElementById('addr-vill-select').innerHTML = '<option value="">' + esc(I18N.select) + '</option>';
    }
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

  // The cascades are walked in order and awaited, instead of poking values into
    // selects that do not hold their options yet (which silently did nothing).
    (async () => {
      // Land location: province → district → village → zone auto-fill
      if (p.district_id) {
        const d = allDistricts.find(x => Number(x.id) === Number(p.district_id));
        const provName = d ? (d.province_name || d.province || '') : '';
        if (provName) {
          document.getElementById('prov-select').value = provName;
          document.getElementById('prov-select').dispatchEvent(new Event('change'));
        }
        document.getElementById('dist-select').value = String(p.district_id);
        document.getElementById('dist-select').dispatchEvent(new Event('change'));
        const vils = await ensureVillages(parseInt(p.district_id, 10));
        allVillages = vils;
        document.getElementById('vill-select').innerHTML =
          '<option value="">' + esc(I18N.select) + '</option>' +
          vils.map(v => '<option value="' + v.id + '">' + esc(v.name_lo) + '</option>').join('');
        if (p.village_id) {
          setVal('village_id', p.village_id);
          document.getElementById('vill-select').dispatchEvent(new Event('change'));
        }
      }
      if (p.zone_code) setZoneOption(p.zone_code);
      if (p.road_category) setVal('road_category', p.road_category);
      updateTaxPreview();

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
        if (o.phone) setVal('owner_phone', o.phone);
        if (o.date_of_birth) setVal('dob', o.date_of_birth);
        if (o.nationality) setVal('nationality', o.nationality);
        if (o.occupation) setVal('occupation', o.occupation);
        if (o.address_unit) setVal('addr_unit', o.address_unit);
        // #12-15 address cascade stores NAMES, so it is walked the same way.
        if (o.address_province) {
          document.getElementById('addr-prov-select').value = o.address_province;
          document.getElementById('addr-prov-select').dispatchEvent(new Event('change'));
        }
        if (o.address_district) {
          document.getElementById('addr-dist-select').value = o.address_district;
          document.getElementById('addr-dist-select').dispatchEvent(new Event('change'));
          const ad = allDistricts.find(x => x.name_lo === o.address_district);
          if (ad) {
            const avils = await ensureVillages(ad.id);
            document.getElementById('addr-vill-select').innerHTML =
              '<option value="">' + esc(I18N.select) + '</option>' +
              avils.map(v => '<option value="' + esc(v.name_lo) + '">' + esc(v.name_lo) + '</option>').join('');
            if (o.address_village) setVal('addr_village', o.address_village);
          }
        }
      }
      if (EDIT_OWNERS && EDIT_OWNERS.length > 1) {
        const s = EDIT_OWNERS[1];
        if (s.full_name) setVal('spouse_name', s.full_name);
        if (s.date_of_birth) setVal('spouse_dob', s.date_of_birth);
        if (s.nationality) setVal('spouse_nat', s.nationality);
        if (s.occupation) setVal('spouse_occ', s.occupation);
      }

      // Editing an existing parcel: unfold the optional fields so nothing is
      // hidden, and refresh the tax estimate.
      const opt = document.getElementById('opt-block');
      if (opt) opt.open = true;
      updateTaxPreview();
    })();
  }

/* ---------- SCAN TAB: owner rows + province → district → village ---------- */
let scanOwnerIndex = 0;
function scanEscJs(s) { return (s == null ? '' : String(s)).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }
function scanOwnerRow(i, o) {
  o = o || {};
  return '<div class="owner-row">'
    + '<input type="hidden" name="owners[' + i + '][id]" value="' + scanEscJs(o.id) + '">'
    + '<div class="form-group"><label>' + I18N.fullName + '</label><input name="owners[' + i + '][full_name]" value="' + scanEscJs(o.full_name) + '"></div>'
    + '<div class="form-group"><label>' + I18N.idNumber + '</label><input name="owners[' + i + '][id_number]" value="' + scanEscJs(o.id_number) + '"></div>'
    + '<div class="form-group"><label>' + I18N.address + '</label><input name="owners[' + i + '][address]" value="' + scanEscJs(o.address) + '"></div>'
    + '<button type="button" class="btn btn-red btn-sm" onclick="this.parentNode.remove()">&times;</button>'
    + '</div>';
}
function addOwner() { document.getElementById('owners').insertAdjacentHTML('beforeend', scanOwnerRow(scanOwnerIndex++)); }

const scanOwnersEl = document.getElementById('owners');
if (scanOwnersEl) {
  const existingOwners = <?= json_encode(array_values($scanOwners), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  if (existingOwners.length === 0) addOwner();
  else existingOwners.forEach(o => scanOwnersEl.insertAdjacentHTML('beforeend', scanOwnerRow(scanOwnerIndex++, o)));
}

const scanProvinceSelect = document.getElementById('province-select');
const scanDistrictSelect = document.getElementById('district-select');
const scanVillageSelect = document.getElementById('village-select');

function scanFillSelect(sel, rows) {
  sel.innerHTML = '<option value="">' + I18N.select + '</option>';
  (rows || []).forEach(r => {
    const o = document.createElement('option');
    o.value = r.id;
    o.textContent = r.name_lo + (r.name_en ? ' (' + r.name_en + ')' : '');
    if (r.zone_id) o.setAttribute('data-zone', r.zone_id);
    sel.appendChild(o);
  });
}

/* ---------- scan review: the zone list follows the chosen district + village ----------
   Same rule as the entry form: blank until a district and village are chosen,
   then only the village's own zone (villages.zone_id), and only when that zone
   belongs to the district and has rate rows. The warnings say why it is blank,
   so a missing rate is caught here instead of at billing time. */
const SCAN_ZONES = <?= json_encode($scanZones, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
function scanZoneLabel(z) {
  if (!z) return '';
  return z.code + (z.district_name ? ' — ' + z.district_name : '') + (z.plan_zone ? ' / ' + z.plan_zone : '');
}
function scanWarn(id, msg) {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = msg || '';
  el.classList.toggle('show', !!msg);
}
function scanZoneRateRows(z) {
  return (z && z.tiers) ? Object.keys(z.tiers).length : 0;
}
function refreshScanZoneField() {
  const sel = document.getElementById('scan-zone-select');
  if (!sel) return;
  sel.innerHTML = '<option value="">' + I18N.select + '</option>';
  scanWarn('scan-zone-dist-warn', '');
  scanWarn('scan-zone-vill-warn', '');
  scanWarn('scan-zone-rate-warn', '');
  const did = parseInt(scanDistrictSelect.value, 10) || 0;
  if (!did) return;
  const districtZones = SCAN_ZONES.filter(z => Number(z.district_id) === did);
  if (!districtZones.length) { scanWarn('scan-zone-dist-warn', I18N.zoneNoDistrict); return; }
  const vid = parseInt(scanVillageSelect.value, 10) || 0;
  if (!vid) return;
  const opt = scanVillageSelect.options[scanVillageSelect.selectedIndex];
  const code = (opt && opt.getAttribute('data-zone')) || '';
  if (!code) { scanWarn('scan-zone-vill-warn', I18N.zoneNoVillage); return; }
  const z = districtZones.find(x => x.code === code);
  if (!z) { scanWarn('scan-zone-dist-warn', I18N.zoneNoVillageZone); return; }
  if (!scanZoneRateRows(z)) { scanWarn('scan-zone-rate-warn', I18N.zoneNoRate); return; }
  sel.innerHTML += '<option value="' + esc(z.code) + '">' + esc(scanZoneLabel(z)) + '</option>';
  sel.value = z.code;
  scanRateWarn();
}
// With no zone selected this is silent — refreshScanZoneField() owns the
// warning in that state (e.g. the village's zone has no rate rows at all).
function scanRateWarn() {
  const sel = document.getElementById('scan-zone-select');
  const roadSel = document.getElementById('scan-road-select');
  const w = document.getElementById('scan-zone-rate-warn');
  if (!sel || !w || !sel.value) return;
  const z = SCAN_ZONES.find(x => x.code === sel.value);
  const road = roadSel ? roadSel.value : '';
  const msg = (!z || (road && !(z.tiers && z.tiers[road]))) ? I18N.zoneNoRate : '';
  w.textContent = msg;
  w.classList.toggle('show', !!msg);
}

// Districts are national (148), so they load after a province is picked.
if (scanProvinceSelect && scanDistrictSelect && scanVillageSelect) {
  scanProvinceSelect.addEventListener('change', async () => {
    scanVillageSelect.innerHTML = '<option value="">' + I18N.select + '</option>';
    scanDistrictSelect.innerHTML = '<option value="">' + I18N.select + '</option>';
    refreshScanZoneField();
    if (!scanProvinceSelect.value) return;
    const r = await fetch('/api/v1/districts.php?province_id=' + encodeURIComponent(scanProvinceSelect.value), {
      headers: { 'Authorization': 'Bearer ' + TOKEN }
    });
    const j = await r.json();
    scanFillSelect(scanDistrictSelect, j.data);
  });
}

if (scanDistrictSelect && scanVillageSelect) {
  scanDistrictSelect.addEventListener('change', async () => {
    const val = scanDistrictSelect.value;
    scanVillageSelect.innerHTML = '<option value="">' + I18N.select + '</option>';
    refreshScanZoneField();
    if (!val) return;
    const r = await fetch('/api/v1/villages.php?district_id=' + encodeURIComponent(val), {
      headers: { 'Authorization': 'Bearer ' + TOKEN }
    });
    const j = await r.json();
    scanFillSelect(scanVillageSelect, j.data);
  });
}

const scanZoneSelect = document.getElementById('scan-zone-select');
const scanRoadSelect = document.getElementById('scan-road-select');
if (scanZoneSelect && scanRoadSelect) {
  scanRoadSelect.addEventListener('change', scanRateWarn);
  scanZoneSelect.addEventListener('change', scanRateWarn);
  scanVillageSelect.addEventListener('change', refreshScanZoneField);

  if (scanVillageSelect.value) scanVillageSelect.dispatchEvent(new Event('change'));
}

  // Geo data first, then the edit prefill: the prefill walks the province →
  // district → village cascade, which only exists once the lists are loaded.
  loadGeoData().then(() => prefillEditParcel());
</script>
</body>
</html>
