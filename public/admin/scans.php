<?php
// public/admin/scans.php — scan review: upload + OCR, review parsed fields, confirm -> parcel + owners;
// discard permanently deletes a scan (and its image) so it leaves the queue without creating a parcel.
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
$role = $user['role'];
$token = $_SESSION['admin_token'];

// Same write rule as the API: district admin/officer (or super). Officers may
// discard only their own uploads; admins may discard any scan in their district.
$canWrite = !empty($user['is_super'])
    || (($user['level'] ?? '') === 'district' && in_array($role, ['admin', 'officer'], true));
$canDiscardScan = function (array $s) use ($user, $canWrite) {
    if (!$canWrite) return false;
    if ($user['role'] === 'admin') return true;
    return (int)($s['created_by'] ?? 0) === (int)($user['sub'] ?? 0);
};

$scanId = isset($_GET['scan_id']) ? (int)$_GET['scan_id'] : 0;
$scan = null; $parcel_input = []; $district_id = 0; $village_id = 0;
$parsed = []; $fieldConf = []; $overallConf = null; $ownerRows = [];
$errors = []; $success = '';

// --- POST: upload a new scan ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $ocrMethod = $_POST['ocr_method'] ?? 'tesseract';
    $data = api_upload('scans.php', $_FILES['image']['tmp_name'], $_FILES['image']['name'], $_FILES['image']['type'], $token, 'image', ['ocr_method' => $ocrMethod]);
    if (!empty($data['ok']) && !empty($data['data']['id'])) {
        header('Location: scans.php?scan_id=' . (int)$data['data']['id']);
        exit;
    }
    $errors[] = $data['error']['message'] ?? t('Upload failed');
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
            'sub_parcel_no'  => $_POST['sub_parcel_no'] ?? '',
            'area_sqm'       => $_POST['area_sqm'] ?? '',
            'road_category'  => $_POST['road_category'] ?? '',
            'zone_code'      => $_POST['zone_code'] ?? '',
            'village_id'     => $_POST['village_id'] ?? '',
            'district_id'    => $_POST['district_id'] ?? '',
            'status'         => $_POST['status'] ?? 'confirmed',
            'title_no'       => $_POST['title_no'] ?? '',
            'title_type'     => $_POST['title_type'] ?? '',
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
        $success = t('Parcel confirmed — ID {id} ({n} owner(s))', [
            'id' => $data['data']['parcel_id'],
            'n'  => count($data['data']['owner_ids'] ?? []),
        ]);
        $scanId = 0;
    } else {
        $errors = $data['error']['fields'] ?? [$data['error']['message'] ?? t('Failed to confirm')];
        if (!is_array($errors)) $errors = [$errors];
    }
}

// --- POST: discard → permanently delete the scan (no parcel) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['discard_scan_id'])) {
    $discardId = (int)$_POST['discard_scan_id'];
    $data = api_call('POST', 'scans/delete.php?id=' . $discardId, [], $token);
    if (!empty($data['ok'])) {
        $success = t('Scan discarded.');
        if ($discardId === $scanId) $scanId = 0;
    } else {
        $errors[] = $data['error']['message'] ?? t('Discard failed');
    }
}

// --- Load the scan for review ---
if ($scanId > 0) {
    $data = api_call('GET', 'scans.php?id=' . $scanId, null, $token);
    if (!empty($data['ok'])) {
        $scan = $data['data']['scan'];
        $parcel_input = $data['data']['parcel'] ?? [];
        $district_id = (int)($data['data']['district_id'] ?? 0);
        $village_id = (int)($data['data']['village_id'] ?? 0);
        $parsed = !empty($scan['parsed_fields']) ? (json_decode($scan['parsed_fields'], true) ?: []) : [];
        $fieldConf = !empty($scan['field_confidence']) ? (json_decode($scan['field_confidence'], true) ?: []) : [];
        $overallConf = $scan['overall_confidence'];
        if (!$district_id && !empty($parsed['district_id'])) $district_id = (int)$parsed['district_id'];
        if (!$village_id && !empty($parsed['village_id'])) $village_id = (int)$parsed['village_id'];

        if (!empty($parcel_input['id'])) {
            $ownerRows = api_call('GET', 'owners.php?parcel_id=' . (int)$parcel_input['id'], null, $token)['data']['owners'] ?? [];
        }
        if (!$ownerRows) {
            $names = $parsed['owner_names'] ?? (!empty($parsed['owner_name']) ? [$parsed['owner_name']] : []);
            foreach ($names as $n) {
                $ownerRows[] = ['full_name' => $n, 'id_number' => $parsed['owner_id_number'] ?? '', 'address' => $parsed['owner_address'] ?? ''];
            }
        }
    } else {
        $errors[] = $data['error']['message'] ?? t('Scan not found');
        $scanId = 0;
    }
}

// Prefer an already-saved parcel value, else fall back to the parsed field.
$val = function ($key) use ($parcel_input, $parsed) {
    if (isset($parcel_input[$key]) && $parcel_input[$key] !== '' && $parcel_input[$key] !== null) return $parcel_input[$key];
    return $parsed[$key] ?? '';
};
function conf_badge($c) {
    if ($c === null || $c === '' || $c === false) return '<span class="conf conf-none">—</span>';
    $c = (int)$c;
    $cls = $c >= 80 ? 'conf-high' : ($c >= 50 ? 'conf-mid' : 'conf-low');
    return '<span class="conf ' . $cls . '">' . $c . '%</span>';
}

// --- Data for the page ---
// Geography is national now (18 provinces / 148 districts / 8,828 villages), so the
// district list is scoped by province: the officer picks province -> district ->
// village. Preselect the province of any district the OCR already resolved.
$provinces = api_call('GET', 'refdata.php?table=provinces')['data'] ?? [];
$allDistricts = api_call('GET', 'districts.php')['data'] ?? [];
$province_id = 0;
if ($district_id) {
    foreach ($allDistricts as $d) {
        if ((int)$d['id'] === $district_id) { $province_id = (int)$d['province_id']; break; }
    }
}
$districts = $province_id
    ? array_values(array_filter($allDistricts, fn($d) => (int)$d['province_id'] === $province_id))
    : [];
$villages = $district_id ? (api_call('GET', 'villages.php?district_id=' . $district_id)['data'] ?? []) : [];
$zones = api_call('GET', 'rates.php', null, $token)['data']['zones'] ?? [];
$pendingScans = api_call('GET', 'scans.php?status=pending', null, $token)['data']['scans'] ?? [];

$parsedLabels = [
    'parcel_no' => t('Parcel no'), 'sub_parcel_no' => t('Sub-parcel'), 'area_sqm' => t('Area (m²)'),
    'title_no' => t('Title no'), 'title_type' => t('Title type'), 'book_no' => t('Book'),
    'volume_no' => t('Volume'), 'page_no' => t('Page'), 'land_use_class' => t('Land use'),
    'issued_date' => t('Issued date'), 'province' => t('Province'),
    'district_name' => t('District (text)'), 'village_name' => t('Village (text)'),
    'district_id' => t('District id'), 'village_id' => t('Village id'),
    'owner_name' => t('Owner name'), 'owner_id_number' => t('Owner ID'), 'owner_address' => t('Owner address'),
];
$titleTypes = ['land_title' => t('Land title'), 'survey_cert' => t('Survey certificate'), 'concession' => t('Concession'), 'other' => t('Other')];
$landUseClasses = ['residential' => t('Residential'), 'agricultural' => t('Agricultural'), 'commercial' => t('Commercial'), 'other' => t('Other')];
?>
<!DOCTYPE html>
<html lang="<?= i18n_current() ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= te('Scan Review') ?> — Land Fee MVP</title>
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
    <h1><?= te('Scan Review') ?></h1>
    <p class="sub"><?= te('Upload a land title scan → OCR → review the parsed fields → confirm or correct') ?></p>

    <?php if ($success): ?><div class="form-box" style="border-color:#2e7d32; background:#eaf4ea;"><?= e($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $err): ?><div class="form-box" style="border-color:#c62828; background:#fdecea;"><?= e(is_array($err) ? implode('; ', $err) : $err) ?></div><?php endforeach; ?>

    <?php if (!$scan): ?>
          <div class="form-box">
            <h3><?= te('Upload a Land Title Scan') ?></h3>
            <form method="post" enctype="multipart/form-data" action="scans.php">
              <div class="form-group">
                <label><?= te('Land title image (JPG/PNG)') ?></label>
                <input type="file" name="image" accept="image/*" required>
                <p class="sub" style="font-size:12px;"><?= te('OCR reads the title text and pre-fills the fields below. You confirm and correct everything.') ?></p>
              </div>
              <div class="form-group">
                <label><?= te('OCR Method') ?></label>
                <div style="display:flex; gap:16px; flex-wrap:wrap;">
                  <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                    <input type="radio" name="ocr_method" value="tesseract" checked>
                    <span><?= te('Tesseract (local, free, fast)') ?></span>
                  </label>
                  <label style="display:flex; align-items:center; gap:6px; cursor:pointer;">
                    <input type="radio" name="ocr_method" value="gemini">
                    <span><?= te('Gemini 2.5 Flash (AI, higher accuracy, requires API key)') ?></span>
                  </label>
                </div>
                <p class="sub" style="font-size:12px;"><?= te('OCR reads the title text and pre-fills the fields below. You confirm and correct everything.') ?></p>
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
              <td><?= conf_badge($s['overall_confidence'] ?? null) ?></td>
              <td><?= e($s['parcel_no'] ?: '—') ?></td>
              <td style="white-space:nowrap;">
                <a class="btn btn-gold btn-sm" href="scans.php?scan_id=<?= (int)$s['id'] ?>"><?= te('Review →') ?></a>
                <?php if ($canDiscardScan($s)): ?>
                <form method="post" action="scans.php" style="display:inline;" onsubmit="return confirm(<?= e(json_encode(t('Discard this scan? The scan and its image will be deleted permanently.'), JSON_UNESCAPED_UNICODE)) ?>);">
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
      <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 16px;">
        <div>
          <div class="form-box" style="border:2px solid #1b5e20;">
            <h3><?= te('Title scan #{id}', ['id' => (int)$scan['id']]) ?> &nbsp;<?= conf_badge($overallConf) ?></h3>
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
                if (!array_key_exists($k, $parsed) && !array_key_exists($k, $fieldConf)) continue;
                $shown++;
                $v = $parsed[$k] ?? ''; ?>
              <tr>
                <td><?= e($lbl) ?></td>
                <td><?= e(is_array($v) ? implode(', ', $v) : ($v === '' ? '—' : $v)) ?></td>
                <td><?= conf_badge($fieldConf[$k] ?? null) ?></td>
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
        <form method="post" action="scans.php?scan_id=<?= (int)$scan['id'] ?>">
          <input type="hidden" name="confirm_parcel" value="1">
          <div class="form-grid">
            <div class="form-group">
              <label><?= te('Parcel number') ?></label>
              <input name="parcel_no" value="<?= e($val('parcel_no')) ?>">
            </div>
            <div class="form-group">
              <label><?= te('Sub-parcel number') ?></label>
              <input name="sub_parcel_no" value="<?= e($val('sub_parcel_no')) ?>">
            </div>
            <div class="form-group">
              <label><?= te('Area (m²)') ?></label>
              <input name="area_sqm" type="number" step="0.01" value="<?= e($val('area_sqm')) ?>">
            </div>
            <div class="form-group">
              <label><?= te('Zone (fee rate)') ?></label>
              <select name="zone_code" required>
                <option value=""><?= te('-- select --') ?></option>
                <?php foreach ($zones as $z): ?>
                <option value="<?= e($z['code']) ?>" <?= ($val('zone_code')) === $z['code'] ? 'selected' : '' ?>><?= e($z['code']) ?> — <?= e($z['district_name'] ?? te('unassigned')) ?><?= $z['plan_zone'] ? ' / ' . e($z['plan_zone']) : '' ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label><?= te('Road access') ?></label>
              <select name="road_category" required>
                <?php foreach (['main' => 'Main road', 'branch' => 'Branch road', 'alley' => 'Alley', 'none' => 'None'] as $rc => $rcLabel): ?>
                <option value="<?= $rc ?>" <?= ($val('road_category') ?: 'none') === $rc ? 'selected' : '' ?>><?= te($rcLabel) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label><?= te('Province') ?></label>
              <select id="province-select">
                <option value=""><?= te('-- select province --') ?></option>
                <?php foreach ($provinces as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= $province_id === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name_lo']) ?> <?= e($p['name_en'] ?: '') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label><?= te('District') ?></label>
              <select name="district_id" id="district-select">
                <option value=""><?= te('-- select --') ?></option>
                <?php foreach ($districts as $d): ?>
                <option value="<?= (int)$d['id'] ?>" <?= $district_id === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name_lo']) ?> <?= e($d['name_en'] ?: '') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label><?= te('Village') ?></label>
              <select name="village_id" id="village-select">
                <option value=""><?= te('-- select --') ?></option>
                <?php foreach ($villages as $v): ?>
                <option value="<?= (int)$v['id'] ?>" <?= $village_id === (int)$v['id'] ? 'selected' : '' ?>><?= e($v['name_lo']) ?> <?= e($v['name_en'] ?: '') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label><?= te('Title number') ?></label>
              <input name="title_no" value="<?= e($val('title_no')) ?>">
            </div>
            <div class="form-group">
              <label><?= te('Title type') ?></label>
              <select name="title_type">
                <option value=""><?= te('-- select --') ?></option>
                <?php foreach ($titleTypes as $v => $lbl): ?>
                <option value="<?= $v ?>" <?= $val('title_type') === $v ? 'selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
              </select>
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
            <a href="scans.php" class="btn btn-gold"><?= te('New scan') ?></a>
          </div>
        </form>
        <?php if ($canDiscardScan($scan)): ?>
        <form method="post" action="scans.php?scan_id=<?= (int)$scan['id'] ?>" style="margin-top:10px;" onsubmit="return confirm(<?= e(json_encode(t('Discard this scan? The scan and its image will be deleted permanently.'), JSON_UNESCAPED_UNICODE)) ?>);">
          <input type="hidden" name="discard_scan_id" value="<?= (int)$scan['id'] ?>">
          <button type="submit" class="btn btn-red"><?= te('Discard') ?></button>
        </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </main>
</div>
<script>
const TOKEN = '<?= e($token) ?>';
const I18N = <?= json_encode([
    'select'   => t('-- select --'),
    'fullName' => t('Full name'),
    'idNumber' => t('ID number'),
    'address'  => t('Address / residence'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
function escJs(s) { return (s == null ? '' : String(s)).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }
let ownerIndex = 0;
function ownerRow(i, o) {
  o = o || {};
  return '<div class="owner-row">'
    + '<input type="hidden" name="owners[' + i + '][id]" value="' + escJs(o.id) + '">'
    + '<div class="form-group"><label>' + I18N.fullName + '</label><input name="owners[' + i + '][full_name]" value="' + escJs(o.full_name) + '"></div>'
    + '<div class="form-group"><label>' + I18N.idNumber + '</label><input name="owners[' + i + '][id_number]" value="' + escJs(o.id_number) + '"></div>'
    + '<div class="form-group"><label>' + I18N.address + '</label><input name="owners[' + i + '][address]" value="' + escJs(o.address) + '"></div>'
    + '<button type="button" class="btn btn-red btn-sm" onclick="this.parentNode.remove()">&times;</button>'
    + '</div>';
}
function addOwner() { document.getElementById('owners').insertAdjacentHTML('beforeend', ownerRow(ownerIndex++)); }

const ownersEl = document.getElementById('owners');
if (ownersEl) {
  const existing = <?= json_encode(array_values($ownerRows), JSON_UNESCAPED_UNICODE) ?>;
  if (existing.length === 0) addOwner();
  else existing.forEach(o => ownersEl.insertAdjacentHTML('beforeend', ownerRow(ownerIndex++, o)));
}

const provinceSelect = document.getElementById('province-select');
const districtSelect = document.getElementById('district-select');
const villageSelect = document.getElementById('village-select');

function fillSelect(sel, rows) {
  sel.innerHTML = '<option value=""><?= te('-- select --') ?></option>';
  (rows || []).forEach(r => {
    const o = document.createElement('option');
    o.value = r.id;
    o.textContent = r.name_lo + (r.name_en ? ' (' + r.name_en + ')' : '');
    sel.appendChild(o);
  });
}

// Province -> District cascade. Districts are national (148), so we never list
// them all: pick a province first, then its districts load.
if (provinceSelect && districtSelect && villageSelect) {
  provinceSelect.addEventListener('change', async () => {
    villageSelect.innerHTML = '<option value=""><?= te('-- select --') ?></option>';
    districtSelect.innerHTML = '<option value=""><?= te('-- select --') ?></option>';
    if (!provinceSelect.value) return;
    const r = await fetch('/api/v1/districts.php?province_id=' + encodeURIComponent(provinceSelect.value), {
      headers: { 'Authorization': 'Bearer ' + TOKEN }
    });
    const j = await r.json();
    fillSelect(districtSelect, j.data);
  });
}

// District -> Village cascade.
if (districtSelect && villageSelect) {
  districtSelect.addEventListener('change', async () => {
    const val = districtSelect.value;
    villageSelect.innerHTML = '<option value=""><?= te('-- select --') ?></option>';
    if (!val) return;
    const r = await fetch('/api/v1/villages.php?district_id=' + encodeURIComponent(val), {
      headers: { 'Authorization': 'Bearer ' + TOKEN }
    });
    const j = await r.json();
    fillSelect(villageSelect, j.data);
  });
}
</script>
</body>
</html>
