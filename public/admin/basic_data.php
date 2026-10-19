<?php
// public/admin/basic_data.php — admin maintenance of basic data:
// province, districts, villages, road categories, title types, land use, zone rates, fee years.
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
if ($role !== 'admin') { http_response_code(403); exit('Admins only.'); }
$token = $_SESSION['admin_token'];

$tab = $_GET['tab'] ?? 'province';
$msg = '';
$msgType = 'ok';

function flash_type(string $m): string {
    if (stripos($m, 'cannot') !== false || stripos($m, 'already') !== false || stripos($m, 'failed') !== false) {
        return 'err';
    }
    return 'ok';
}

// ---- POST handlers ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do_action'] ?? '';
    $table = $_POST['table'] ?? '';

    // Lookup tables via refdata.php
    if ($do === 'ref_save') {
        $body = ['code' => $_POST['code'] ?? '', 'name_lo' => $_POST['name_lo'] ?? '',
                 'name_en' => $_POST['name_en'] ?? ''];
        foreach (['note', 'sort_order'] as $extra) {
            if (isset($_POST[$extra])) { $body[$extra] = $_POST[$extra]; }
        }
        $id = (int)($_POST['id'] ?? 0);
        $r = $id
            ? api_call('PUT', 'refdata.php?table=' . urlencode($table) . '&id=' . $id, $body, $token)
            : api_call('POST', 'refdata.php?table=' . urlencode($table), $body, $token);
        $msg = !empty($r['ok']) ? ucfirst(str_replace('_', ' ', $table)) . ' saved.' : ($r['error']['message'] ?? 'Save failed');
        $msgType = !empty($r['ok']) ? 'ok' : 'err';
    }

    if ($do === 'ref_delete') {
        $r = api_call('POST', 'refdata.php?table=' . urlencode($table) . '&code=' . urlencode($_POST['code'] ?? ''), null, $token);
        $msg = !empty($r['ok']) ? 'Deleted.' : ($r['error']['message'] ?? 'Delete failed');
        $msgType = !empty($r['ok']) ? 'ok' : 'err';
    }

    // Districts
    if ($do === 'district_save') {
        $body = ['code' => $_POST['code'] ?? '', 'name_lo' => $_POST['name_lo'] ?? '',
                 'name_en' => $_POST['name_en'] ?? '', 'province_id' => (int)($_POST['province_id'] ?? 0)];
        $id = (int)($_POST['id'] ?? 0);
        $r = $id ? api_call('PUT', 'districts.php?id=' . $id, $body, $token)
                 : api_call('POST', 'districts.php', $body, $token);
        $msg = !empty($r['ok']) ? 'District saved.' : ($r['error']['message'] ?? 'Save failed');
        $msgType = !empty($r['ok']) ? 'ok' : 'err';
    }
    if ($do === 'district_delete') {
        $r = api_call('POST', 'districts.php?id=' . (int)($_POST['id'] ?? 0), null, $token);
        $msg = !empty($r['ok']) ? 'District deleted.' : ($r['error']['message'] ?? 'Delete failed');
        $msgType = !empty($r['ok']) ? 'ok' : 'err';
    }

    // Villages
    if ($do === 'village_save') {
        $body = ['code' => $_POST['code'] ?? '', 'name_lo' => $_POST['name_lo'] ?? '',
                 'name_en' => $_POST['name_en'] ?? '', 'district_id' => (int)($_POST['district_id'] ?? 0)];
        $id = (int)($_POST['id'] ?? 0);
        $r = $id ? api_call('PUT', 'villages.php?id=' . $id, $body, $token)
                 : api_call('POST', 'villages.php', $body, $token);
        $msg = !empty($r['ok']) ? 'Village saved.' : ($r['error']['message'] ?? 'Save failed');
        $msgType = !empty($r['ok']) ? 'ok' : 'err';
    }
    if ($do === 'village_delete') {
        $r = api_call('POST', 'villages.php?id=' . (int)($_POST['id'] ?? 0), null, $token);
        $msg = !empty($r['ok']) ? 'Village deleted.' : ($r['error']['message'] ?? 'Delete failed');
        $msgType = !empty($r['ok']) ? 'ok' : 'err';
    }

    // Zone rates (fee per m²) — zone codes, not district+road
    if ($do === 'zone_save') {
        $code   = trim((string)($_POST['code'] ?? ''));
        $rate   = (float)($_POST['rate_per_sqm'] ?? 0);
        $nameLo = trim((string)($_POST['name_lo'] ?? ''));
        if ($code === '' || $rate <= 0 || $nameLo === '') {
            $msg = 'Zone code, Lao name and a positive rate are required.';
            $msgType = 'err';
        } else {
            $r = api_call('PUT', 'rates.php?code=' . urlencode($code), [
                'rate_per_sqm' => $rate,
                'name_lo'      => $nameLo,
                'name_en'      => $_POST['name_en'] ?? null,
                'note'         => $_POST['note'] ?? null,
            ], $token);
            $msg = !empty($r['ok']) ? 'Zone ' . $code . ' updated.' : ($r['error']['message'] ?? 'Update failed');
            $msgType = !empty($r['ok']) ? 'ok' : 'err';
        }
    }
    if ($do === 'zone_add') {
        $r = api_call('POST', 'rates.php', [
            'code'         => trim((string)($_POST['code'] ?? '')),
            'name_lo'      => trim((string)($_POST['name_lo'] ?? '')),
            'name_en'      => $_POST['name_en'] ?? null,
            'rate_per_sqm' => (float)($_POST['rate_per_sqm'] ?? 0),
            'note'         => $_POST['note'] ?? null,
        ], $token);
        $msg = !empty($r['ok']) ? 'Zone ' . e(($_POST['code'] ?? '')) . ' added.' : ($r['error']['message'] ?? 'Add failed');
        $msgType = !empty($r['ok']) ? 'ok' : 'err';
    }
    if ($do === 'zone_delete') {
        $r = api_call('POST', 'rates.php?code=' . urlencode((string)($_POST['code'] ?? '')), [], $token);
        $msg = !empty($r['ok']) ? 'Zone deleted.' : ($r['error']['message'] ?? 'Delete failed');
        $msgType = !empty($r['ok']) ? 'ok' : 'err';
    }

    // Fee years
    if ($do === 'year_save') {
        $r = api_call('POST', 'fee_years.php', [
            'year'     => (int)($_POST['year'] ?? 0),
            'due_date' => $_POST['due_date'] ?? '',
            'status'   => $_POST['status'] ?? 'open',
        ], $token);
        $msg = !empty($r['ok']) ? 'Fee year saved.' : ($r['error']['message'] ?? 'Save failed');
        $msgType = !empty($r['ok']) ? 'ok' : 'err';
    }
    if ($do === 'year_generate') {
        $r = api_call('POST', 'fee_obligations.php', [
            'action' => 'generate',
            'fee_year' => (int)($_POST['year'] ?? 0),
            'district_id' => (int)($_POST['district_id'] ?? 0) ?: null,
        ], $token);
        $msg = !empty($r['ok'])
            ? 'Assessed ' . (int)($r['data']['assessed'] ?? 0) . ' obligation(s).'
            : ($r['error']['message'] ?? 'Generation failed');
        $msgType = !empty($r['ok']) ? 'ok' : 'err';
    }
}

$provinces   = api_call('GET', 'refdata.php?table=provinces', null, $token)['data'] ?? [];
$districts   = api_call('GET', 'districts.php', null, $token)['data'] ?? [];
$refdata     = api_call('GET', 'refdata.php', null, $token)['data'] ?? [];
$zones       = api_call('GET', 'rates.php', null, $token)['data']['zones'] ?? [];
$years       = api_call('GET', 'fee_years.php', null, $token)['data']['years'] ?? [];

$vProvince  = (int)($_GET['province_id'] ?? 0);
$vDistrict  = (int)($_GET['district_id'] ?? 0);
$vQuery     = trim((string)($_GET['q'] ?? ''));
$villages   = [];
if ($tab === 'villages') {
    // 200 keeps the page usable; narrow it with the province/district filter or search.
    $u = 'villages.php?limit=200';
    if ($vProvince) { $u .= '&province_id=' . $vProvince; }
    if ($vDistrict) { $u .= '&district_id=' . $vDistrict; }
    if ($vQuery !== '') { $u .= '&q=' . urlencode($vQuery); }
    $villages = api_call('GET', $u, null, $token)['data'] ?? [];
}
// Districts for the village filter cascade: all of them carry province_id, so the
// client can hide districts of other provinces without an extra call.
$allDistricts = $districts;

$tabLabels = [
    'province' => 'Province',
    'districts'=> 'Districts',
    'villages' => 'Villages',
    'zones'    => 'Zone rates (fee)',
    'years'    => 'Fee years',
    'refdata'  => 'Lookups (road / title / land use)',
];
// Submenu lives in the sidebar (admin_nav); guard against an unknown tab.
if (!isset($tabLabels[$tab])) {
    $tab = 'province';
}
?>
<!DOCTYPE html>
<html lang="lo">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($tabLabels[$tab]) ?> — Basic Data — Land Fee MVP</title>
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
    <h1><?= e($tabLabels[$tab]) ?></h1>
    <p class="sub">Province, districts, villages, lookups, zone rates (the fee) and fee years.
      Fee values are placeholders — set them to whatever the province decides.</p>

    <?php if ($msg): ?><p class="flash <?= e($msgType) ?>"><?= e($msg) ?></p><?php endif; ?>

<?php if ($tab === 'province'): ?>
    <div class="form-box">
      <h2>Province</h2>
      <table style="margin-bottom:14px;">
        <thead><tr><th>Code</th><th>Lao</th><th>English</th><th>Active</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($provinces as $p): ?>
          <tr>
            <td><?= e($p['code']) ?></td>
            <td><?= e($p['name_lo']) ?></td>
            <td><?= e($p['name_en']) ?></td>
            <td><?= $p['is_active'] ? 'yes' : 'no' ?></td>
            <td>
              <form method="post" style="display:inline">
                <input type="hidden" name="do_action" value="ref_delete"><input type="hidden" name="table" value="provinces">
                <input type="hidden" name="code" value="<?= e($p['code']) ?>">
                <button class="btn btn-red btn-sm" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <form method="post" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:12px;">
        <input type="hidden" name="do_action" value="ref_save"><input type="hidden" name="table" value="provinces">
        <div class="form-group"><label>Code</label><input name="code" required></div>
        <div class="form-group"><label>Name (Lao)</label><input name="name_lo" required></div>
        <div class="form-group"><label>Name (EN)</label><input name="name_en"></div>
        <div class="form-group"><label>&nbsp;</label><button class="btn btn-green" type="submit">Add province</button></div>
      </form>
    </div>

<?php elseif ($tab === 'districts'): ?>
    <div class="form-box">
      <h2>Districts</h2>
      <table style="margin-bottom:14px;">
        <thead><tr><th>Code</th><th>Lao</th><th>English</th><th>Province</th><th>Villages</th><th>Parcels</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($districts as $d): ?>
          <tr>
            <td><?= e($d['code']) ?></td>
            <td><?= e($d['name_lo']) ?></td>
            <td><?= e($d['name_en']) ?></td>
            <td><?= e($d['province_name'] ?? '—') ?></td>
            <td><?= (int)$d['village_count'] ?></td>
            <td><?= (int)$d['parcel_count'] ?></td>
            <td>
              <div style="display:inline-flex; gap:6px;">
                <button class="btn btn-blue btn-sm" type="button" onclick="editDistrict(<?= (int)$d['id'] ?>, '<?= e($d['code']) ?>', '<?= e($d['name_lo']) ?>', '<?= e($d['name_en'] ?? '') ?>', '<?= (int)$d['province_id'] ?>')">Edit</button>
                <form method="post" style="display:inline">
                  <input type="hidden" name="do_action" value="district_delete"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                  <button class="btn btn-red btn-sm" type="submit">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <form method="post" class="form-grid" id="district-form" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:12px;">
        <input type="hidden" name="do_action" value="district_save">
        <input type="hidden" name="id" id="district-id" value="0">
        <div class="form-group"><label>Code</label><input name="code" id="district-code" required></div>
        <div class="form-group"><label>Name (Lao)</label><input name="name_lo" id="district-name-lo" required></div>
        <div class="form-group"><label>Name (EN)</label><input name="name_en" id="district-name-en"></div>
        <div class="form-group"><label>Province</label>
          <select name="province_id" id="district-province" required><?php foreach ($provinces as $p): ?>
            <option value="<?= (int)$p['id'] ?>"><?= e($p['name_lo']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="form-group"><label>&nbsp;</label><button class="btn btn-green" type="submit" id="district-submit">Add district</button></div>
      </form>
      <script>
      function editDistrict(id, code, nameLo, nameEn, provinceId) {
        document.getElementById('district-id').value = id;
        document.getElementById('district-code').value = code;
        document.getElementById('district-name-lo').value = nameLo;
        document.getElementById('district-name-en').value = nameEn;
        document.getElementById('district-province').value = provinceId;
        document.getElementById('district-submit').textContent = 'Update district';
        document.getElementById('district-form').scrollIntoView({behavior:'smooth'});
      }
      </script>
    </div>

<?php elseif ($tab === 'villages'): ?>
    <div class="form-box">
      <h2>Villages</h2>
      <form method="get" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px; margin-bottom:14px;">
        <input type="hidden" name="tab" value="villages">
        <div class="form-group"><label>Province</label>
          <select name="province_id" id="v-province"><option value="">-- all --</option>
            <?php foreach ($provinces as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= $vProvince == $p['id'] ? 'selected' : '' ?>><?= e($p['name_lo']) ?></option>
            <?php endforeach; ?></select>
        </div>
        <div class="form-group"><label>District</label>
          <select name="district_id" id="v-district"><option value="">-- all --</option>
            <?php foreach ($districts as $d): ?>
            <option value="<?= (int)$d['id'] ?>" data-province="<?= (int)$d['province_id'] ?>" <?= $vDistrict == $d['id'] ? 'selected' : '' ?>><?= e($d['name_lo']) ?></option>
            <?php endforeach; ?></select>
        </div>
        <div class="form-group"><label>Search</label><input name="q" value="<?= e($vQuery) ?>"></div>
        <div class="form-group"><label>&nbsp;</label><button class="btn btn-green" type="submit">Filter</button></div>
      </form>
      <script>
      // Province -> district cascade: hide districts of other provinces (like tax-ets).
      (function(){
        const prov = document.getElementById('v-province');
        const dist = document.getElementById('v-district');
        if (!prov || !dist) return;
        function filterDistricts(){
          const pid = prov.value;
          [...dist.options].forEach(o => {
            if (!o.value) return; // keep the "-- all --" placeholder
            o.hidden = (pid && o.dataset.province !== pid);
          });
          // If the selected district is now hidden, reset to "-- all --".
          if (dist.value && dist.selectedOptions[0] && dist.selectedOptions[0].hidden) {
            dist.value = '';
          }
        }
        prov.addEventListener('change', filterDistricts);
        filterDistricts();
      })();
      </script>
      <p class="sub"><?= count($villages) ?> village(s) shown<?= count($villages) >= 200 ? ' (first 200 — narrow with the district filter or search)' : '' ?>.</p>
      <table style="margin-bottom:14px;">
        <thead><tr><th>Code</th><th>Lao</th><th>English</th><th>District</th><th>Parcels</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($villages as $v): ?>
          <tr>
            <td><?= e($v['code']) ?></td>
            <td><?= e($v['name_lo']) ?></td>
            <td><?= e($v['name_en']) ?></td>
            <td><?= e($v['district_name']) ?></td>
            <td><?= (int)$v['parcel_count'] ?></td>
            <td>
              <div style="display:inline-flex; gap:6px;">
                <button class="btn btn-blue btn-sm" type="button" onclick="editVillage(<?= (int)$v['id'] ?>, '<?= e($v['code']) ?>', '<?= e($v['name_lo']) ?>', '<?= e($v['name_en'] ?? '') ?>', '<?= (int)$v['district_id'] ?>')">Edit</button>
                <form method="post" style="display:inline">
                  <input type="hidden" name="do_action" value="village_delete"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                  <button class="btn btn-red btn-sm" type="submit">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <form method="post" class="form-grid" id="village-form" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:12px;">
        <input type="hidden" name="do_action" value="village_save">
        <input type="hidden" name="id" id="village-id" value="0">
        <div class="form-group"><label>Code</label><input name="code" id="village-code" required></div>
        <div class="form-group"><label>Name (Lao)</label><input name="name_lo" id="village-name-lo" required></div>
        <div class="form-group"><label>Name (EN)</label><input name="name_en" id="village-name-en"></div>
        <div class="form-group"><label>District</label>
          <select name="district_id" id="village-district" required><?php foreach ($districts as $d): ?>
            <option value="<?= (int)$d['id'] ?>"><?= e($d['name_lo']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="form-group"><label>&nbsp;</label><button class="btn btn-green" type="submit" id="village-submit">Add village</button></div>
      </form>
      <script>
      function editVillage(id, code, nameLo, nameEn, districtId) {
        document.getElementById('village-id').value = id;
        document.getElementById('village-code').value = code;
        document.getElementById('village-name-lo').value = nameLo;
        document.getElementById('village-name-en').value = nameEn;
        document.getElementById('village-district').value = districtId;
        document.getElementById('village-submit').textContent = 'Update village';
        document.getElementById('village-form').scrollIntoView({behavior:'smooth'});
      }
      </script>
    </div>

<?php elseif ($tab === 'zones'): ?>
    <div class="form-box">
      <h2>Zone rates — the fee per m²</h2>
      <p class="sub">Fee = zone rate × area. A zone is a valuation area code (e.g. G17, A7, C7, C8)
        like in the land office system. Add zones, then set each rate in kip/m².
        These start as placeholders; revise them when you get the official figures
        from the Land Department or MOF.</p>
      <table style="margin-bottom:14px;">
        <thead><tr><th>Code</th><th>Name (Lao)</th><th>Name (EN)</th><th>Rate (kip/m²)</th><th>Active</th><th>Note</th><th style="width:130px">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($zones as $z): ?>
          <tr>
            <td><strong><?= e($z['code']) ?></strong></td>
            <td><?= e($z['name_lo']) ?></td>
            <td><?= e($z['name_en'] ?? '') ?></td>
            <td><?= number_format((float)$z['rate_per_sqm'], 0) ?></td>
            <td><?= $z['is_active'] ? 'yes' : 'no' ?></td>
            <td><?= e($z['note'] ?? '') ?></td>
            <td>
              <div style="display:inline-flex; gap:6px;">
                <button class="btn btn-blue btn-sm" type="button"
                  onclick="editZone('<?= e($z['code']) ?>','<?= e($z['name_lo']) ?>','<?= e($z['name_en'] ?? '') ?>','<?= (float)$z['rate_per_sqm'] ?>','<?= e($z['note'] ?? '') ?>')">Edit</button>
                <form method="post" style="display:inline">
                  <input type="hidden" name="do_action" value="zone_delete"><input type="hidden" name="code" value="<?= e($z['code']) ?>">
                  <button class="btn btn-red btn-sm" type="submit">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$zones): ?>
          <tr><td colspan="7" class="muted">No zones yet — add one below.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>

      <h3 style="margin-top:6px;">Add zone</h3>
      <form method="post" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:12px;" id="zone-form">
        <input type="hidden" name="do_action" id="zone-action" value="zone_add">
        <div class="form-group"><label>Code</label><input name="code" id="zone-code" required placeholder="e.g. B3"></div>
        <div class="form-group"><label>Name (Lao)</label><input name="name_lo" id="zone-name-lo" required></div>
        <div class="form-group"><label>Name (EN)</label><input name="name_en" id="zone-name-en"></div>
        <div class="form-group"><label>Rate (kip/m²)</label><input name="rate_per_sqm" id="zone-rate" type="number" min="0" step="1" required></div>
        <div class="form-group"><label>Note</label><input name="note" id="zone-note"></div>
        <div class="form-group"><label>&nbsp;</label><button class="btn btn-green" type="submit" id="zone-submit">Add zone</button></div>
      </form>
      <script>
      function editZone(code, nameLo, nameEn, rate, note) {
        document.getElementById('zone-code').value = code;
        document.getElementById('zone-name-lo').value = nameLo;
        document.getElementById('zone-name-en').value = nameEn;
        document.getElementById('zone-rate').value = rate;
        document.getElementById('zone-note').value = note;
        document.getElementById('zone-action').value = 'zone_save';
        document.getElementById('zone-submit').textContent = 'Update zone';
        document.getElementById('zone-form').scrollIntoView({behavior:'smooth'});
      }
      </script>
    </div>

<?php elseif ($tab === 'years'): ?>
    <div class="form-box">
      <h2>Fee years</h2>
      <table style="margin-bottom:14px;">
        <thead><tr><th>Year</th><th>Due date</th><th>Status</th><th>Obligations</th><th>Assessed</th><th>Collected</th></tr></thead>
        <tbody>
        <?php foreach ($years as $y): ?>
          <tr>
            <td><?= e($y['year']) ?></td>
            <td><?= e($y['due_date']) ?></td>
            <td><?= e($y['status']) ?></td>
            <td><?= (int)$y['obligation_count'] ?></td>
            <td><?= number_format((float)$y['total_due'], 0) ?></td>
            <td><?= number_format((float)$y['total_paid'], 0) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <form method="post" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:12px;">
        <input type="hidden" name="do_action" value="year_save">
        <div class="form-group"><label>Year</label><input name="year" type="number" min="2000" max="2100" required></div>
        <div class="form-group"><label>Due date</label><input name="due_date" type="date"></div>
        <div class="form-group"><label>Status</label>
          <select name="status"><option value="draft">draft</option><option value="open">open</option><option value="closed">closed</option></select>
        </div>
        <div class="form-group"><label>&nbsp;</label><button class="btn btn-green" type="submit">Save year</button></div>
      </form>
      <h2 style="margin-top:20px;">Generate obligations</h2>
      <form method="post" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:12px;">
        <input type="hidden" name="do_action" value="year_generate">
        <div class="form-group"><label>Year</label><input name="year" type="number" min="2000" max="2100" required></div>
        <div class="form-group"><label>District (optional)</label>
          <select name="district_id"><option value="">-- all confirmed parcels --</option>
            <?php foreach ($districts as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name_lo']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="form-group"><label>&nbsp;</label><button class="btn btn-blue" type="submit">Generate</button></div>
      </form>
    </div>

<?php elseif ($tab === 'refdata'): ?>
<?php
$refGroups = [
    'road_categories' => 'Road categories',
    'title_types'     => 'Title types',
    'land_use_classes'=> 'Land use classes',
];
foreach ($refGroups as $tbl => $label): ?>
    <div class="form-box">
      <h2><?= e($label) ?></h2>
      <table style="margin-bottom:12px;">
        <thead><tr><th>Code</th><th>Lao</th><th>English</th><th>Sort</th><th></th></tr></thead>
        <tbody>
        <?php foreach (($refdata[$tbl] ?? []) as $row): ?>
          <tr>
            <td><?= e($row['code']) ?></td>
            <td><?= e($row['name_lo']) ?></td>
            <td><?= e($row['name_en']) ?></td>
            <td><?= e($row['sort_order'] ?? '') ?></td>
            <td>
              <form method="post" style="display:inline">
                <input type="hidden" name="do_action" value="ref_delete"><input type="hidden" name="table" value="<?= e($tbl) ?>">
                <input type="hidden" name="code" value="<?= e($row['code']) ?>">
                <button class="btn btn-red btn-sm" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <form method="post" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:12px;">
        <input type="hidden" name="do_action" value="ref_save"><input type="hidden" name="table" value="<?= e($tbl) ?>">
        <div class="form-group"><label>Code</label><input name="code" required></div>
        <div class="form-group"><label>Lao</label><input name="name_lo" required></div>
        <div class="form-group"><label>English</label><input name="name_en"></div>
        <div class="form-group"><label>Sort</label><input name="sort_order" type="number"></div>
        <div class="form-group"><label>&nbsp;</label><button class="btn btn-green" type="submit">Add</button></div>
      </form>
    </div>
<?php endforeach; ?>
<?php endif; ?>

  </main>
</div>
</body>
</html>