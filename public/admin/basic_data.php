<?php
// public/admin/basic_data.php — admin maintenance of basic data:
// province, districts, villages, road/title/land-use lookups, zone rates and
// fee years.
//
// Every add/edit form opens in the shared popup dialog (public/admin/modal.php):
// each form lives in a <template> and the row's Edit button fills it from its
// own data-* attributes, so one template serves both "add" and "edit".
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
if ($role !== 'admin') { http_response_code(403); exit(t('Admins only.')); }
$token = $_SESSION['admin_token'];

$tabLabels = [
    'province' => t('Province'),
    'districts'=> t('Districts'),
    'villages' => t('Villages'),
    'zones'    => t('Zone rates (fee)'),
    'years'    => t('Fee years'),
    'refdata'  => t('Lookups (road / title / land use)'),
];
// The sidebar submenu links these; an unknown ?tab= falls back to the first.
$tab = (string)($_GET['tab'] ?? 'province');
if (!isset($tabLabels[$tab])) { $tab = 'province'; }

$msg = '';
$msgType = 'ok';

/**
 * Sortable column header. Links to ?tab=&sort=<key>&dir=asc|desc and flips the
 * direction when that column is already the active sort. The key is whitelisted
 * here and re-validated by the API against the table's own columns.
 */
function th_sort(string $key, string $label, string $sort, string $dir, string $tab, array $extra = []): string {
    $isCurrent = ($sort === $key);
    $next  = ($isCurrent && $dir === 'asc') ? 'desc' : 'asc';
    // Extra params (e.g. the active province filter) ride along so sorting a
    // column never drops the current filter.
    $href  = '?' . http_build_query(array_merge(['tab' => $tab], $extra, ['sort' => $key, 'dir' => $next]));
    $arrow = $isCurrent ? '<span class="arrow">' . ($dir === 'asc' ? '▲' : '▼') . '</span>' : '';
    return '<a class="th-sort" href="' . e($href) . '" title="' . te('Sort by {label}', ['label' => $label]) . '">' . e($label) . $arrow . '</a>';
}

/**
 * District <select> options grouped by province. The national geography holds
 * ~148 districts, so one flat list is unusable; grouping also lets the
 * province filter hide whole groups instead of individual options.
 */
function district_options(array $districts, array $provinces, int $selected = 0): string {
    $provinceName = [];
    foreach ($provinces as $p) { $provinceName[(int)$p['id']] = (string)($p['name_lo'] ?? ''); }
    $groups = [];
    foreach ($districts as $d) {
        $pid = (int)($d['province_id'] ?? 0);
        if (!isset($groups[$pid])) {
            $groups[$pid] = [
                'label' => $provinceName[$pid] ?? (string)($d['province_name'] ?? ''),
                'items' => [],
            ];
        }
        $groups[$pid]['items'][] = $d;
    }
    $html = '';
    foreach ($groups as $pid => $g) {
        $html .= '<optgroup label="' . e($g['label']) . '" data-province="' . $pid . '">';
        foreach ($g['items'] as $d) {
            $html .= '<option value="' . (int)$d['id'] . '"' . ($selected === (int)$d['id'] ? ' selected' : '') . '>' . e($d['name_lo']) . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html;
}

// ---- POST handlers ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do_action'] ?? '';
    $table = $_POST['table'] ?? '';

    // Lookup tables via refdata.php — creates are ref_add, updates are
    // ref_update (by code: every refdata table is code-keyed).
    if ($do === 'ref_add' || $do === 'ref_update') {
        $body = ['code' => $_POST['code'] ?? '', 'name_lo' => $_POST['name_lo'] ?? '',
                 'name_en' => $_POST['name_en'] ?? ''];
        // Optional columns, only when the tab actually posts them (is_active comes
        // from the province form; the other lookup tables have no such column).
        foreach (['note', 'sort_order', 'is_active'] as $extra) {
            if (isset($_POST[$extra])) { $body[$extra] = $_POST[$extra]; }
        }
        if ($do === 'ref_add') {
            $r = api_call('POST', 'refdata.php?table=' . urlencode($table), $body, $token);
        } else {
            $r = api_call('PUT', 'refdata.php?table=' . urlencode($table) . '&code=' . urlencode((string)($_POST['code'] ?? '')), $body, $token);
        }
        $msg = !empty($r['ok']) ? t('{name} saved.', ['name' => t(ucfirst(str_replace('_', ' ', $table)))]) : ($r['error']['message'] ?? t('Save failed'));
        $msgType = !empty($r['ok']) ? 'ok' : 'err';
    }

    if ($do === 'ref_delete') {
        $r = api_call('POST', 'refdata.php?table=' . urlencode($table) . '&code=' . urlencode($_POST['code'] ?? ''), null, $token);
        $msg = !empty($r['ok']) ? t('Deleted.') : ($r['error']['message'] ?? t('Delete failed'));
        $msgType = !empty($r['ok']) ? 'ok' : 'err';
    }

    // Districts
    if ($do === 'district_save') {
        $body = ['code' => $_POST['code'] ?? '', 'name_lo' => $_POST['name_lo'] ?? '',
                 'name_en' => $_POST['name_en'] ?? '', 'province_id' => (int)($_POST['province_id'] ?? 0),
                 'zone_letter' => $_POST['zone_letter'] ?? ''];
        $id = (int)($_POST['id'] ?? 0);
        $r = $id ? api_call('PUT', 'districts.php?id=' . $id, $body, $token)
                 : api_call('POST', 'districts.php', $body, $token);
        $msg = !empty($r['ok']) ? t('District saved.') : ($r['error']['message'] ?? t('Save failed'));
        $msgType = !empty($r['ok']) ? 'ok' : 'err';
    }
    if ($do === 'district_delete') {
        $r = api_call('POST', 'districts.php?id=' . (int)($_POST['id'] ?? 0), null, $token);
        $msg = !empty($r['ok']) ? t('District deleted.') : ($r['error']['message'] ?? t('Delete failed'));
        $msgType = !empty($r['ok']) ? 'ok' : 'err';
    }

    // Villages
    if ($do === 'village_save') {
        $body = ['code' => $_POST['code'] ?? '', 'name_lo' => $_POST['name_lo'] ?? '',
                 'name_en' => $_POST['name_en'] ?? '', 'district_id' => (int)($_POST['district_id'] ?? 0)];
        $id = (int)($_POST['id'] ?? 0);
        $r = $id ? api_call('PUT', 'villages.php?id=' . $id, $body, $token)
                 : api_call('POST', 'villages.php', $body, $token);
        $msg = !empty($r['ok']) ? t('Village saved.') : ($r['error']['message'] ?? t('Save failed'));
        $msgType = !empty($r['ok']) ? 'ok' : 'err';
    }
    if ($do === 'village_delete') {
        $r = api_call('POST', 'villages.php?id=' . (int)($_POST['id'] ?? 0), null, $token);
        $msg = !empty($r['ok']) ? t('Village deleted.') : ($r['error']['message'] ?? t('Delete failed'));
        $msgType = !empty($r['ok']) ? 'ok' : 'err';
    }

    // Zone rates (fee per m²) — a matrix: zone × road-access tier
    if ($do === 'zone_save' || $do === 'zone_add') {
        $code   = strtoupper(trim((string)($_POST['code'] ?? '')));
        $nameLo = trim((string)($_POST['name_lo'] ?? ''));
        $rates  = [];
                foreach (['main', 'branch', 'alley', 'none'] as $rc) {
                    $rates[$rc] = (float)($_POST['rate_' . $rc] ?? 0);
                }
                // Villages assigned to this zone (a zone can cover several villages).
                $villageIds = [];
                foreach ((array)($_POST['village_ids'] ?? []) as $vid) {
                    $vid = (int)$vid;
                    if ($vid > 0) { $villageIds[] = $vid; }
                }
        if ($code === '' || $nameLo === '' || max($rates) <= 0) {
            $msg = t('Zone code, Lao name and at least one positive rate are required.');
            $msgType = 'err';
        } else {
            $body = [
                'code'        => $code,
                'name_lo'     => $nameLo,
                'name_en'     => $_POST['name_en'] ?? null,
                'note'        => $_POST['note'] ?? null,
                'is_active'   => ($_POST['is_active'] ?? '1') === '1',
                'district_id' => (int)($_POST['district_id'] ?? 0) ?: null,
                'plan_zone'   => $_POST['plan_zone'] ?? null,
                'sort_order'  => (int)($_POST['sort_order'] ?? 0),
                                'rates'       => $rates,
                                'village_ids' => $villageIds,
                            ];
            if ($do === 'zone_add') {
                $r = api_call('POST', 'rates.php', $body, $token);
                $msg = !empty($r['ok']) ? t('Zone {code} added.', ['code' => $code]) : ($r['error']['message'] ?? t('Add failed'));
            } else {
                $r = api_call('PUT', 'rates.php?code=' . urlencode($code), $body, $token);
                $msg = !empty($r['ok']) ? t('Zone {code} updated.', ['code' => $code]) : ($r['error']['message'] ?? t('Update failed'));
            }
            $msgType = !empty($r['ok']) ? 'ok' : 'err';
        }
    }
    if ($do === 'zone_delete') {
        $r = api_call('POST', 'rates.php?code=' . urlencode((string)($_POST['code'] ?? '')), [], $token);
        $msg = !empty($r['ok']) ? t('Zone deleted.') : ($r['error']['message'] ?? t('Delete failed'));
        $msgType = !empty($r['ok']) ? 'ok' : 'err';
    }

    // Fee years
    if ($do === 'year_save') {
        $r = api_call('POST', 'fee_years.php', [
            'year'     => (int)($_POST['year'] ?? 0),
            'due_date' => $_POST['due_date'] ?? '',
            'status'   => $_POST['status'] ?? 'open',
        ], $token);
        $msg = !empty($r['ok']) ? t('Fee year saved.') : ($r['error']['message'] ?? t('Save failed'));
        $msgType = !empty($r['ok']) ? 'ok' : 'err';
    }
    if ($do === 'year_generate') {
        $r = api_call('POST', 'fee_obligations.php', [
            'action' => 'generate',
            'fee_year' => (int)($_POST['year'] ?? 0),
            'district_id' => (int)($_POST['district_id'] ?? 0) ?: null,
        ], $token);
        $msg = !empty($r['ok'])
            ? t('Assessed {n} obligation(s).', ['n' => (int)($r['data']['assessed'] ?? 0)])
            : ($r['error']['message'] ?? t('Generation failed'));
        $msgType = !empty($r['ok']) ? 'ok' : 'err';
    }
}

// Province table sorting: default code ascending (the official order); clicking a
// header toggles asc/desc. Whitelisted, and the API validates against its columns.
$sortable = ['code', 'name_lo', 'name_en'];
$sort = (string)($_GET['sort'] ?? 'code');
$dir  = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
if (!in_array($sort, $sortable, true)) { $sort = 'code'; }

// Filter params (province_id is shared by the districts and villages tabs).
$vProvince  = (int)($_GET['province_id'] ?? 0);
$vDistrict  = (int)($_GET['district_id'] ?? 0);
$vQuery     = trim((string)($_GET['q'] ?? ''));

// Districts table: sortable headers (default code ascending, the official order)
// + province filter. $dExtra is threaded into the sort links so sorting keeps the
// filter applied.
$dSortable = ['code', 'name_lo', 'name_en', 'province', 'villages', 'parcels'];
$dSort = (string)($_GET['sort'] ?? 'code');
$dDir  = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
if (!in_array($dSort, $dSortable, true)) { $dSort = 'code'; }
$dExtra = $vProvince ? ['province_id' => $vProvince] : [];

// The provinces array feeds dropdowns on every tab, so it is always in official
// code order; only the province TABLE (its own tab) follows the clicked sort.
// Sharing one ?sort= param across tabs means a district sort like "Lao" would
// otherwise silently reorder the province dropdowns too.
$pSort = ($tab === 'province') ? $sort : 'code';
$pDir  = ($tab === 'province') ? $dir  : 'asc';

$provinces   = api_call('GET', 'refdata.php?table=provinces&sort=' . urlencode($pSort) . '&dir=' . urlencode($pDir), null, $token)['data'] ?? [];
$districts   = api_call('GET', 'districts.php?' . http_build_query(['sort' => $dSort, 'dir' => $dDir] + $dExtra), null, $token)['data'] ?? [];
// Every district, for the dropdowns inside the add/edit dialogs: a filtered list
// would hide the district of the row being edited.
$districtsAll = api_call('GET', 'districts.php?sort=name_lo&dir=asc', null, $token)['data'] ?? [];
$refdata     = api_call('GET', 'refdata.php', null, $token)['data'] ?? [];
// Road-category lookup by code: decree road types for the zone table headers
// and the add/edit modal labels.
$roadCatByCode = [];
foreach (($refdata['road_categories'] ?? []) as $rc) {
    $roadCatByCode[$rc['code']] = $rc;
}
$zones       = api_call('GET', 'rates.php', null, $token)['data']['zones'] ?? [];
$zonePlans   = api_call('GET', 'refdata.php?table=zone_plans', null, $token)['data'] ?? [];
// All villages, so the zone modal can assign villages to a zone.
$allVillages = [];
$vlist = api_call('GET', 'villages.php?limit=500&sort=name_lo&dir=asc', null, $token);
if (!empty($vlist['ok'])) { $allVillages = $vlist['data'] ?? []; }
// Villages already mapped to a zone (code => zone_code) for the modal default-selection.
$zoneVillages = [];
foreach ($allVillages as $v) {
    if (!empty($v['zone_id'])) { $zoneVillages[$v['id']] = $v['zone_id']; }
}
$years       = api_call('GET', 'fee_years.php', null, $token)['data']['years'] ?? [];

// Village table: sortable headers (default code ascending, the official order),
// narrowing with the province/district filter or search. The filter form carries
// sort/dir so submitting it keeps the current sort; the sort links carry the
// filter so sorting keeps the current filter.
$vSortable = ['code', 'name_lo', 'name_en', 'province', 'district', 'parcels'];
$vSort = (string)($_GET['sort'] ?? 'code');
$vDir  = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
if (!in_array($vSort, $vSortable, true)) { $vSort = 'code'; }

$villages   = [];
if ($tab === 'villages') {
    // 500 keeps the page usable; narrow it with the province/district filter or search.
    $u = 'villages.php?limit=500&sort=' . urlencode($vSort) . '&dir=' . urlencode($vDir);
    if ($vProvince) { $u .= '&province_id=' . $vProvince; }
    if ($vDistrict) { $u .= '&district_id=' . $vDistrict; }
    if ($vQuery !== '') { $u .= '&q=' . urlencode($vQuery); }
    $villages = api_call('GET', $u, null, $token)['data'] ?? [];
}
// Extra params for the sort links: the current province/district filters and the
// village search, so sorting never drops them.
$vExtra = [];
if ($vProvince) { $vExtra['province_id'] = $vProvince; }
if ($vDistrict) { $vExtra['district_id'] = $vDistrict; }
if ($vQuery !== '') { $vExtra['q'] = $vQuery; }

// The lookup tables on the "Lookups" tab. Each group gets its own form
// template, so a group without a sort_order column has no sort field.
$refGroups = [
    'road_categories'  => ['label' => t('Road categories'),  'one' => t('road category'),  'sort' => true],
    'title_types'      => ['label' => t('Title types'),      'one' => t('title type'),     'sort' => false],
    'land_use_classes' => ['label' => t('Land use classes'), 'one' => t('land use class'), 'sort' => false],
];
?>
<!DOCTYPE html>
<html lang="<?= i18n_current() ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($tabLabels[$tab]) ?> — <?= te('Basic Data') ?> — Land Fee MVP</title>
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
    <h1><?= e($tabLabels[$tab]) ?></h1>
    <p class="sub"><?= te('Province, districts, villages, lookups, zone rates (the fee) and fee years. Fee values are placeholders — set them to whatever the province decides.') ?></p>

    <?php if ($msg): ?><p class="flash <?= e($msgType) ?>"><?= e($msg) ?></p><?php endif; ?>

<?php if ($tab === 'province'): ?>
    <div class="form-box">
      <div class="toolbar">
        <h2><?= te('Province') ?></h2>
        <button class="btn btn-green js-modal" type="button"
          data-form="province-form" data-title="<?= te('Add province') ?>" data-submit="<?= te('Add province') ?>"><?= te('Add province') ?></button>
      </div>
      <table>
        <thead><tr>
          <th><?= th_sort('code', t('Code'), $sort, $dir, $tab) ?></th>
          <th><?= th_sort('name_lo', t('Lao'), $sort, $dir, $tab) ?></th>
          <th><?= th_sort('name_en', t('English'), $sort, $dir, $tab) ?></th>
          <th><?= te('Active') ?></th><th style="width:150px"><?= te('Actions') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($provinces as $p): ?>
          <tr>
            <td><?= e($p['code']) ?></td>
            <td><?= e($p['name_lo']) ?></td>
            <td><?= e($p['name_en'] ?? '') ?></td>
            <td><?= $p['is_active'] ? te('yes') : te('no') ?></td>
            <td class="table-actions">
              <button class="btn btn-blue btn-sm js-modal" type="button"
                data-form="province-form" data-title="<?= te('Edit province') ?>" data-submit="<?= te('Update province') ?>"
                data-do-action="ref_update"
                data-id="<?= (int)$p['id'] ?>" data-code="<?= e($p['code']) ?>" data-name-lo="<?= e($p['name_lo']) ?>"
                data-name-en="<?= e($p['name_en'] ?? '') ?>" data-is-active="<?= $p['is_active'] ? 1 : 0 ?>"><?= te('Edit') ?></button>
              <form method="post">
                <input type="hidden" name="do_action" value="ref_delete"><input type="hidden" name="table" value="provinces">
                <input type="hidden" name="code" value="<?= e($p['code']) ?>">
                <button class="btn btn-red btn-sm" type="submit"><?= te('Delete') ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <template id="province-form">
        <form method="post" class="form-grid">
          <input type="hidden" name="do_action" value="ref_add"><input type="hidden" name="table" value="provinces">
          <input type="hidden" name="id" value="0">
          <div class="form-group"><label><?= te('Code') ?></label><input name="code" required></div>
          <div class="form-group"><label><?= te('Name (Lao)') ?></label><input name="name_lo" required></div>
          <div class="form-group"><label><?= te('Name (EN)') ?></label><input name="name_en"></div>
          <div class="form-group"><label><?= te('Active') ?></label>
            <select name="is_active"><option value="1"><?= te('yes') ?></option><option value="0"><?= te('no') ?></option></select>
          </div>
        </form>
      </template>
    </div>

<?php elseif ($tab === 'districts'): ?>
    <div class="form-box">
      <div class="toolbar">
        <h2><?= te('Districts') ?></h2>
        <button class="btn btn-green js-modal" type="button"
          data-form="district-form" data-title="<?= te('Add district') ?>" data-submit="<?= te('Add district') ?>"><?= te('Add district') ?></button>
      </div>
      <form method="get" class="form-grid" style="margin-bottom:14px;">
        <input type="hidden" name="tab" value="districts">
        <input type="hidden" name="sort" value="<?= e($dSort) ?>">
        <input type="hidden" name="dir" value="<?= e($dDir) ?>">
        <div class="form-group"><label><?= te('Province') ?></label>
          <select name="province_id"><option value=""><?= te('-- all provinces --') ?></option>
            <?php foreach ($provinces as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= $vProvince == $p['id'] ? 'selected' : '' ?>><?= e($p['name_lo']) ?> <?= e($p['name_en'] ?: '') ?></option>
            <?php endforeach; ?></select>
        </div>
        <div class="form-group"><label>&nbsp;</label><button class="btn btn-green" type="submit"><?= te('Filter') ?></button></div>
        <?php if ($vProvince): ?>
        <div class="form-group"><label>&nbsp;</label><a class="btn btn-gold" href="?tab=districts&amp;sort=<?= e($dSort) ?>&amp;dir=<?= e($dDir) ?>"><?= te('Clear filter') ?></a></div>
        <?php endif; ?>
      </form>
      <p class="sub"><?= $vProvince ? te('{n} district(s) shown — filtered by province', ['n' => count($districts)]) : te('{n} district(s) shown (all provinces)', ['n' => count($districts)]) ?></p>
      <table>
        <thead><tr>
          <th><?= th_sort('code', t('Code'), $dSort, $dDir, $tab, $dExtra) ?></th>
          <th><?= th_sort('name_lo', t('Lao'), $dSort, $dDir, $tab, $dExtra) ?></th>
          <th><?= th_sort('name_en', t('English'), $dSort, $dDir, $tab, $dExtra) ?></th>
          <th><?= te('Zone letter') ?></th>
          <th><?= th_sort('province', t('Province'), $dSort, $dDir, $tab, $dExtra) ?></th>
          <th><?= th_sort('villages', t('Villages'), $dSort, $dDir, $tab, $dExtra) ?></th>
          <th><?= th_sort('parcels', t('Parcels'), $dSort, $dDir, $tab, $dExtra) ?></th>
          <th style="width:150px"><?= te('Actions') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($districts as $d): ?>
          <tr>
            <td><?= e($d['code']) ?></td>
            <td><?= e($d['name_lo']) ?></td>
            <td><?= e($d['name_en']) ?></td>
            <td><strong><?= e($d['zone_letter'] ?? '—') ?></strong></td>
            <td><?= e($d['province_name'] ?? '—') ?></td>
            <td><?= (int)$d['village_count'] ?></td>
            <td><?= (int)$d['parcel_count'] ?></td>
            <td class="table-actions">
              <button class="btn btn-blue btn-sm js-modal" type="button"
                data-form="district-form" data-title="<?= te('Edit district') ?>" data-submit="<?= te('Update district') ?>"
                data-id="<?= (int)$d['id'] ?>" data-code="<?= e($d['code']) ?>" data-name-lo="<?= e($d['name_lo']) ?>"
                data-name-en="<?= e($d['name_en'] ?? '') ?>" data-province-id="<?= (int)$d['province_id'] ?>"
                data-zone-letter="<?= e($d['zone_letter'] ?? '') ?>"><?= te('Edit') ?></button>
              <form method="post">
                <input type="hidden" name="do_action" value="district_delete"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button class="btn btn-red btn-sm" type="submit"><?= te('Delete') ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <template id="district-form">
        <form method="post" class="form-grid">
          <input type="hidden" name="do_action" value="district_save">
          <input type="hidden" name="id" value="0">
          <div class="form-group"><label><?= te('Code') ?></label><input name="code" required></div>
          <div class="form-group"><label><?= te('Name (Lao)') ?></label><input name="name_lo" required></div>
          <div class="form-group"><label><?= te('Name (EN)') ?></label><input name="name_en"></div>
          <div class="form-group"><label><?= te('Zone letter') ?></label>
            <input name="zone_letter" maxlength="5" placeholder="A" pattern="[A-Za-z]{1,5}">
            <p class="sub" style="font-size:12px;"><?= te('Prefix for zone codes in this district (A, B, …).') ?></p>
          </div>
          <div class="form-group"><label><?= te('Province') ?></label>
            <select name="province_id" required><?php foreach ($provinces as $p): ?>
              <option value="<?= (int)$p['id'] ?>"><?= e($p['name_lo']) ?></option><?php endforeach; ?></select>
          </div>
        </form>
      </template>
    </div>

<?php elseif ($tab === 'villages'): ?>
    <div class="form-box">
      <div class="toolbar">
        <h2><?= te('Villages') ?></h2>
        <button class="btn btn-green js-modal" type="button"
          data-form="village-form" data-title="<?= te('Add village') ?>" data-submit="<?= te('Add village') ?>"><?= te('Add village') ?></button>
      </div>
      <form method="get" class="form-grid" style="margin-bottom:14px;">
        <input type="hidden" name="tab" value="villages">
        <input type="hidden" name="sort" value="<?= e($vSort) ?>">
        <input type="hidden" name="dir" value="<?= e($vDir) ?>">
        <div class="form-group"><label><?= te('Province') ?></label>
          <select name="province_id" id="v-province"><option value=""><?= te('-- all --') ?></option>
            <?php foreach ($provinces as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= $vProvince == $p['id'] ? 'selected' : '' ?>><?= e($p['name_lo']) ?></option>
            <?php endforeach; ?></select>
        </div>
        <div class="form-group"><label><?= te('District') ?></label>
          <select name="district_id" id="v-district"><option value=""><?= te('-- all --') ?></option>
            <?= district_options($districtsAll, $provinces, $vDistrict) ?></select>
        </div>
        <div class="form-group"><label><?= te('Search') ?></label><input name="q" value="<?= e($vQuery) ?>"></div>
        <div class="form-group"><label>&nbsp;</label><button class="btn btn-green" type="submit"><?= te('Filter') ?></button></div>
      </form>
      <script>
      // Province -> district cascade in the filter form.
      (function(){
        const prov = document.getElementById('v-province');
        const dist = document.getElementById('v-district');
        if (!prov || !dist) return;
        function filterDistricts(){
          const pid = prov.value;
          [...dist.querySelectorAll('optgroup')].forEach(g => {
            g.style.display = (pid && g.dataset.province !== pid) ? 'none' : '';
          });
          // If the selected district is now hidden, reset to "-- all --".
          const sel = dist.selectedOptions[0];
          if (sel && sel.parentElement && sel.parentElement.tagName === 'OPTGROUP'
              && sel.parentElement.style.display === 'none') {
            dist.value = '';
          }
        }
        prov.addEventListener('change', filterDistricts);
        filterDistricts();
      })();
      // Province -> district cascade in the modal form.
      (function(){
        const mProv = document.getElementById('v-modal-province');
        const mDist = document.getElementById('v-modal-district');
        if (!mProv || !mDist) return;
        function filterModalDistricts(){
          const pid = mProv.value;
          [...mDist.querySelectorAll('optgroup')].forEach(g => {
            g.style.display = (pid && g.dataset.province !== pid) ? 'none' : '';
          });
          const sel = mDist.selectedOptions[0];
          if (sel && sel.parentElement && sel.parentElement.tagName === 'OPTGROUP'
              && sel.parentElement.style.display === 'none') {
            mDist.value = '';
          }
        }
        mProv.addEventListener('change', filterModalDistricts);
      })();
      </script>
      <p class="sub"><?= count($villages) >= 500
          ? te('{n} village(s) shown (first 500 — narrow with the district filter or search)', ['n' => count($villages)])
          : te('{n} village(s) shown', ['n' => count($villages)]) ?></p>
      <table>
        <thead><tr>
          <th><?= th_sort('code', t('Code'), $vSort, $vDir, $tab, $vExtra) ?></th>
          <th><?= th_sort('name_lo', t('Lao'), $vSort, $vDir, $tab, $vExtra) ?></th>
          <th><?= th_sort('name_en', t('English'), $vSort, $vDir, $tab, $vExtra) ?></th>
          <th><?= th_sort('district', t('District'), $vSort, $vDir, $tab, $vExtra) ?></th>
          <th><?= th_sort('province', t('Province'), $vSort, $vDir, $tab, $vExtra) ?></th>
          <th><?= th_sort('parcels', t('Parcels'), $vSort, $vDir, $tab, $vExtra) ?></th>
          <th style="width:150px"><?= te('Actions') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($villages as $v): ?>
          <tr>
            <td><?= e($v['code']) ?></td>
            <td><?= e($v['name_lo']) ?></td>
            <td><?= e($v['name_en']) ?></td>
            <td><?= e($v['district_name']) ?></td>
            <td><?= e($v['province_name'] ?? '—') ?></td>
            <td><?= (int)$v['parcel_count'] ?></td>
            <td class="table-actions">
              <button class="btn btn-blue btn-sm js-modal" type="button"
                data-form="village-form" data-title="<?= te('Edit village') ?>" data-submit="<?= te('Update village') ?>"
                data-id="<?= (int)$v['id'] ?>" data-code="<?= e($v['code']) ?>" data-name-lo="<?= e($v['name_lo']) ?>"
                data-name-en="<?= e($v['name_en'] ?? '') ?>" data-district-id="<?= (int)$v['district_id'] ?>"
                data-province-id="<?= (int)($v['province_id'] ?? 0) ?>"><?= te('Edit') ?></button>
              <form method="post">
                <input type="hidden" name="do_action" value="village_delete"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                <button class="btn btn-red btn-sm" type="submit"><?= te('Delete') ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <template id="village-form">
        <form method="post" class="form-grid">
          <input type="hidden" name="do_action" value="village_save">
          <input type="hidden" name="id" value="0">
          <div class="form-group"><label><?= te('Code') ?></label><input name="code" required></div>
          <div class="form-group"><label><?= te('Name (Lao)') ?></label><input name="name_lo" required></div>
          <div class="form-group"><label><?= te('Name (EN)') ?></label><input name="name_en"></div>
          <div class="form-group"><label><?= te('District') ?></label>
            <select name="district_id" required id="v-modal-district"><?= district_options($districtsAll, $provinces) ?></select>
          </div>
          <div class="form-group"><label><?= te('Province') ?></label>
            <select name="province_id" required id="v-modal-province"><?php foreach ($provinces as $p): ?>
              <option value="<?= (int)$p['id'] ?>"><?= e($p['name_lo']) ?></option><?php endforeach; ?></select>
          </div>
        </form>
      </template>
    </div>

<?php elseif ($tab === 'zones'): ?>
    <div class="form-box">
      <div class="toolbar">
        <h2><?= te('Zone rates — zone × road access') ?></h2>
        <button class="btn btn-green js-modal" type="button"
          data-form="zone-form" data-title="<?= te('Add zone') ?>" data-submit="<?= te('Add zone') ?>"
          data-do-action="zone_add"><?= te('Add zone') ?></button>
      </div>
      <p class="sub"><?= te('Fee = zone rate for the parcel’s road access × area. Each zone has four rates: main road, branch road, alley, and no road. Districts own a letter so codes stay unique (e.g. A1–A4 in district 1301). Rates below are placeholders until the province supplies the official figures.') ?></p>
      <table>
        <thead><tr>
                  <th><?= te('Code') ?></th><th><?= te('District') ?></th><th><?= te('Plan') ?></th>
                  <th><?= te('Name (Lao)') ?></th>
                  <th><?= te('Villages') ?></th>
                                    <?php foreach (['main', 'branch', 'alley', 'none'] as $rcCode): ?>
                                      <th title="<?= e($roadCatByCode[$rcCode]['name_en'] ?? $rcCode) ?>"><?= e($roadCatByCode[$rcCode]['name_lo'] ?? $rcCode) ?></th>
                                    <?php endforeach; ?>
                                    <th><?= te('Active') ?></th><th style="width:150px"><?= te('Actions') ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($zones as $z): $r = $z['rates'] ?? []; $vils = $z['villages'] ?? []; ?>
                  <tr>
                    <td><strong><?= e($z['code']) ?></strong></td>
                    <td><?= e($z['district_name'] ?? '—') ?></td>
                    <td><?= $z['plan_zone'] ? e($z['plan_zone'] . ' ' . ($z['plan_name'] ?? '')) : '—' ?></td>
                    <td><?= e($z['name_lo']) ?></td>
                    <td style="min-width:160px"><?php if ($vils): ?><?= e(implode(', ', $vils)) ?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                    <td><?= number_format((float)($r['main'] ?? 0), 0) ?></td>
                    <td><?= number_format((float)($r['branch'] ?? 0), 0) ?></td>
                    <td><?= number_format((float)($r['alley'] ?? 0), 0) ?></td>
                    <td><?= number_format((float)($r['none'] ?? 0), 0) ?></td>
                    <td><?= $z['is_active'] ? te('yes') : te('no') ?></td>
            <td class="table-actions">
              <button class="btn btn-blue btn-sm js-modal" type="button"
                data-form="zone-form" data-title="<?= te('Edit zone') ?>" data-submit="<?= te('Update zone') ?>"
                data-do-action="zone_save" data-readonly="code"
                data-code="<?= e($z['code']) ?>" data-name-lo="<?= e($z['name_lo']) ?>" data-name-en="<?= e($z['name_en'] ?? '') ?>"
                data-district-id="<?= (int)($z['district_id'] ?? 0) ?: '' ?>" data-plan-zone="<?= e($z['plan_zone'] ?? '') ?>"
                data-sort-order="<?= (int)($z['sort_order'] ?? 0) ?>"
                data-rate-main="<?= (float)($r['main'] ?? 0) ?>" data-rate-branch="<?= (float)($r['branch'] ?? 0) ?>"
                data-rate-alley="<?= (float)($r['alley'] ?? 0) ?>" data-rate-none="<?= (float)($r['none'] ?? 0) ?>"
                data-note="<?= e($z['note'] ?? '') ?>" data-is-active="<?= $z['is_active'] ? 1 : 0 ?>"><?= te('Edit') ?></button>
              <form method="post">
                <input type="hidden" name="do_action" value="zone_delete"><input type="hidden" name="code" value="<?= e($z['code']) ?>">
                <button class="btn btn-red btn-sm" type="submit"><?= te('Delete') ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$zones): ?>
          <tr><td colspan="11" class="muted"><?= te('No zones yet — add one.') ?></td></tr>
        <?php endif; ?>
        </tbody>
      </table>

      <template id="zone-form">
        <form method="post" class="form-grid">
          <input type="hidden" name="do_action" value="zone_add">
          <div class="form-group"><label><?= te('Code') ?></label><input name="code" required placeholder="A1"></div>
          <div class="form-group"><label><?= te('Name (Lao)') ?></label><input name="name_lo" required></div>
          <div class="form-group"><label><?= te('Name (EN)') ?></label><input name="name_en"></div>
          <div class="form-group"><label><?= te('District') ?></label>
            <select name="district_id"><option value=""><?= te('— unassigned / legacy —') ?></option>
              <?= district_options($districtsAll, $provinces) ?></select>
          </div>
          <div class="form-group"><label><?= te('Valuation zone') ?></label>
                      <select name="plan_zone"><option value=""><?= te('—') ?></option>
              <?php foreach ($zonePlans as $zp): ?>
                <option value="<?= e($zp['code']) ?>"><?= e($zp['code'] . ' — ' . $zp['name_lo']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label><?= te('Sort order') ?></label><input name="sort_order" type="number" value="0"></div>
                    <div class="form-group" style="grid-column:1/-1;"><label><?= te('Villages in this zone') ?></label>
                      <select name="village_ids" multiple size="8" style="width:100%;">
                        <?php
                        // Group villages by district; pre-select ones already in this zone.
                        $byDist = [];
                        foreach ($allVillages as $v) {
                            $byDist[$v['district_id']][] = $v;
                        }
                        $vDistNames = [];
                        foreach ($districtsAll as $dd) { $vDistNames[(int)$dd['id']] = $dd['name_lo']; }
                        ?>
                        <?php foreach ($byDist as $did => $vl): ?>
                          <optgroup label="<?= e($vDistNames[$did] ?? ('#' . $did)) ?>">
                            <?php foreach ($vl as $vv): ?>
                              <option value="<?= (int)$vv['id'] ?>" data-zone="<?= e($zoneVillages[$vv['id']] ?? '') ?>" data-district="<?= (int)$vv['district_id'] ?>"><?= e($vv['name_lo']) ?></option>
                            <?php endforeach; ?>
                          </optgroup>
                        <?php endforeach; ?>
                      </select>
                      <p class="sub" style="font-size:12px;"><?= te('A zone can cover several villages. Hold Ctrl/Cmd to select multiple.') ?></p>
                                          </div>
                                          <div class="form-group"><label><?= e($roadCatByCode['main']['name_lo'] ?? 'Main') ?> (kip/m²)</label><input name="rate_main" type="number" min="0" step="1" required></div>
                                                                          <div class="form-group"><label><?= e($roadCatByCode['branch']['name_lo'] ?? 'Branch') ?> (kip/m²)</label><input name="rate_branch" type="number" min="0" step="1" required></div>
                                                                          <div class="form-group"><label><?= e($roadCatByCode['alley']['name_lo'] ?? 'Alley') ?> (kip/m²)</label><input name="rate_alley" type="number" min="0" step="1" required></div>
                                                                          <div class="form-group"><label><?= e($roadCatByCode['none']['name_lo'] ?? 'No road') ?> (kip/m²)</label><input name="rate_none" type="number" min="0" step="1" required></div>
                                <div class="form-group"><label><?= te('Note') ?></label><input name="note"></div>
                                <div class="form-group"><label><?= te('Active') ?></label>
                                  <select name="is_active"><option value="1"><?= te('yes') ?></option><option value="0"><?= te('no') ?></option></select>
                                </div>
                              </form>
                            </template>
                            <script>
                                                        // When the zone modal opens: show only villages of the zone's
                                                        // district)Skip pre-select the ones already mapped to it. Reacts
                                                        // to district changes too.
                                                        (function () {
                                                          const modal = document.getElementById('modal-bg');
                                                          if (!modal) return;
                                                                                                                    const distOpts = { childList: true, subtree: true };
                                                          function syncVillages(form) {
                                                            const sel = form.querySelector('select[name="village_ids"]');
                                                            const distSel = form.querySelector('select[name="district_id"]');
                                                            if (!sel || !distSel) return;
                                                            const did = String(distSel.value || '');
                                                            const zoneInput = form.querySelector('input[name="code"]');
                                                            const zoneCode = zoneInput ? String(zoneInput.value).trim().toUpperCase() : '';
                                                            [...sel.options].forEach(function (o) {
                                                              // Show only the selected district's villages ('' = unassigned/legacy zone: show none).
                                                              const show = did !== '' && String(o.dataset.district || '') === did;
                                                              o.hidden = !show;
                                                              if (show) {
                                                                o.selected = zoneCode !== '' && (o.dataset.zone || '').toUpperCase() === zoneCode;
                                                              } else {
                                                                o.selected = false;
                                                              }
                                                            });
                                                            // Hide optgroups with no visible options.
                                                            [...sel.querySelectorAll('optgroup')].forEach(function (g) {
                                                              const any = Array.from(g.options).some(function (o) { return !o.hidden; });
                                                              g.hidden = !any;
                                                            });
                                                          }
                                                          const obs = new MutationObserver(function () {
                                                            const form = document.getElementById('modal-form');
                                                            if (!form) return;
                                                            const distSel = form.querySelector('select[name="district_id"]');
                                                            if (!distSel) return;
                                                            // Attach change listener once.
                                                            if (!distSel.dataset.villageBound) {
                                                              distSel.dataset.villageBound = '1';
                                                              distSel.addEventListener('change', function () { syncVillages(form); });
                                                            }
                                                            syncVillages(form);
                                                          });
                                                          obs.observe(modal, distOpts);
                                                        })();
                                                        </script>
                          </div>

<?php elseif ($tab === 'years'): ?>
    <div class="form-box">
      <div class="toolbar">
        <h2><?= te('Fee years') ?></h2>
        <div style="display:flex; gap:10px;">
          <button class="btn btn-green js-modal" type="button"
            data-form="year-form" data-title="<?= te('Add fee year') ?>" data-submit="<?= te('Add fee year') ?>"><?= te('Add fee year') ?></button>
          <button class="btn btn-blue js-modal" type="button"
            data-form="generate-form" data-title="<?= te('Generate obligations') ?>" data-submit="<?= te('Generate') ?>"
            data-year="<?= (int)($years[0]['year'] ?? 0) ?>"><?= te('Generate obligations') ?></button>
        </div>
      </div>
      <table>
        <thead><tr><th><?= te('Year') ?></th><th><?= te('Due date') ?></th><th><?= te('Status') ?></th><th><?= te('Obligations') ?></th><th><?= te('Assessed') ?></th><th><?= te('Collected') ?></th><th style="width:150px"><?= te('Actions') ?></th></tr></thead>
        <tbody>
        <?php foreach ($years as $y): ?>
          <tr>
            <td><?= e($y['year']) ?></td>
            <td><?= e($y['due_date']) ?></td>
            <td><?= e(t($y['status'])) ?></td>
            <td><?= (int)$y['obligation_count'] ?></td>
            <td><?= number_format((float)$y['total_due'], 0) ?></td>
            <td><?= number_format((float)$y['total_paid'], 0) ?></td>
            <td class="table-actions">
              <button class="btn btn-blue btn-sm js-modal" type="button"
                data-form="year-form" data-title="<?= te('Edit fee year') ?>" data-submit="<?= te('Update fee year') ?>"
                data-readonly="year" data-year="<?= (int)$y['year'] ?>" data-due-date="<?= e($y['due_date']) ?>"
                data-status="<?= e($y['status']) ?>"><?= te('Edit') ?></button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <template id="year-form">
        <form method="post" class="form-grid">
          <input type="hidden" name="do_action" value="year_save">
          <div class="form-group"><label><?= te('Year') ?></label><input name="year" type="number" min="2000" max="2100" required></div>
          <div class="form-group"><label><?= te('Due date') ?></label><input name="due_date" type="date"></div>
          <div class="form-group"><label><?= te('Status') ?></label>
            <select name="status"><option value="draft"><?= te('draft') ?></option><option value="open"><?= te('open') ?></option><option value="closed"><?= te('closed') ?></option></select>
          </div>
        </form>
      </template>

      <template id="generate-form">
        <form method="post" class="form-grid">
          <input type="hidden" name="do_action" value="year_generate">
          <div class="form-group"><label><?= te('Year') ?></label><input name="year" type="number" min="2000" max="2100" required></div>
          <div class="form-group"><label><?= te('District (optional)') ?></label>
            <select name="district_id"><option value=""><?= te('-- all confirmed parcels --') ?></option>
              <?= district_options($districtsAll, $provinces) ?></select>
          </div>
        </form>
      </template>
    </div>

<?php elseif ($tab === 'refdata'): ?>
<?php foreach ($refGroups as $tbl => $cfg): ?>
    <div class="form-box">
      <div class="toolbar">
        <h2><?= e($cfg['label']) ?></h2>
        <button class="btn btn-green js-modal" type="button"
          data-form="ref-form-<?= e($tbl) ?>"
          data-do-action="ref_add"
          data-title="<?= te('Add {thing}', ['thing' => $cfg['one']]) ?>"
          data-submit="<?= te('Add {thing}', ['thing' => $cfg['one']]) ?>"
          data-table="<?= e($tbl) ?>"><?= te('Add {thing}', ['thing' => $cfg['one']]) ?></button>
      </div>
      <table>
        <thead><tr><th><?= te('Code') ?></th><th><?= te('Lao') ?></th><th><?= te('English') ?></th><?php if ($cfg['sort']): ?><th><?= te('Sort') ?></th><?php endif; ?><th style="width:150px"><?= te('Actions') ?></th></tr></thead>
        <tbody>
        <?php foreach (($refdata[$tbl] ?? []) as $row): ?>
          <tr>
            <td><?= e($row['code']) ?></td>
            <td><?= e($row['name_lo']) ?></td>
            <td><?= e($row['name_en']) ?></td>
            <?php if ($cfg['sort']): ?><td><?= e($row['sort_order'] ?? '') ?></td><?php endif; ?>
            <td class="table-actions">
              <button class="btn btn-blue btn-sm js-modal" type="button"
                data-form="ref-form-<?= e($tbl) ?>"
                data-title="<?= te('Edit {thing}', ['thing' => $cfg['one']]) ?>"
                data-submit="<?= te('Update {thing}', ['thing' => $cfg['one']]) ?>"
                data-do-action="ref_update"
                data-table="<?= e($tbl) ?>"
                data-code="<?= e($row['code']) ?>" data-name-lo="<?= e($row['name_lo']) ?>"
                data-name-en="<?= e($row['name_en']) ?>"
                <?php if ($cfg['sort']): ?>data-sort-order="<?= (int)($row['sort_order'] ?? 0) ?>"<?php endif; ?>><?= te('Edit') ?></button>
              <form method="post">
                <input type="hidden" name="do_action" value="ref_delete"><input type="hidden" name="table" value="<?= e($tbl) ?>">
                <input type="hidden" name="code" value="<?= e($row['code']) ?>">
                <button class="btn btn-red btn-sm" type="submit"><?= te('Delete') ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!($refdata[$tbl] ?? [])): ?>
          <tr><td colspan="5" class="muted"><?= te('No data.') ?></td></tr>
        <?php endif; ?>
        </tbody>
      </table>

      <template id="ref-form-<?= e($tbl) ?>">
        <form method="post" class="form-grid">
          <input type="hidden" name="do_action" value="ref_add">
          <input type="hidden" name="table" value="<?= e($tbl) ?>">
          <div class="form-group"><label><?= te('Code') ?></label><input name="code" required></div>
          <div class="form-group"><label><?= te('Lao') ?></label><input name="name_lo" required></div>
          <div class="form-group"><label><?= te('English') ?></label><input name="name_en" required></div>
          <?php if ($cfg['sort']): ?>
          <div class="form-group"><label><?= te('Sort') ?></label><input name="sort_order" type="number" min="0"></div>
          <?php endif; ?>
        </form>
      </template>
    </div>
<?php endforeach; ?>
<?php endif; ?>

  </main>
</div>

<?php require __DIR__ . '/modal.php'; ?>
</body>
</html>
