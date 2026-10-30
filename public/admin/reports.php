<?php
// public/admin/reports.php — report picker + generic renderer + CSV export
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
$role = $user['role'];
$token = $_SESSION['admin_token'];

$report   = $_GET['report'] ?? 'collection';
$province = isset($_GET['province_id']) ? (int)$_GET['province_id'] : 0;
$district = isset($_GET['district_id']) ? (int)$_GET['district_id'] : 0;
$fee_year = isset($_GET['fee_year']) ? (int)$_GET['fee_year'] : 0;
$road     = $_GET['road_category'] ?? '';
$from     = $_GET['from'] ?? '';
$to       = $_GET['to'] ?? '';
$q        = $_GET['q'] ?? '';
$actor    = $_GET['actor'] ?? '';
$action   = $_GET['action_filter'] ?? '';

// ---- Scope ----------------------------------------------------------------
// super admin  : every province; may filter by province and/or district
// province user: own province only (Savannakhet for now); may filter by district
// district user: own district only — the scope filters are hidden entirely
$is_super   = !empty($user['is_super']);
$level      = $user['level'] ?? 'district';
$myDistrict = isset($user['district_id']) ? (int)$user['district_id'] : 0;
// Province-level staff are pinned to Savannakhet (id=1) until multi-province rollout.
$myProvince     = $level === 'province' ? 1 : 0;
$canFilterProv  = $is_super;
$canFilterDist  = $is_super || $level === 'province';

// Effective province for the query: super admin may pick one, province staff are pinned.
$effProvince = $is_super ? $province : $myProvince;
// A district pick is only meaningful for staff allowed to filter by district.
if (!$canFilterDist) { $district = 0; }

// Reports that ignore the date range / that only make sense for certain filters.
$dateReports   = ['collection', 'audit'];
$yearReports   = ['collection', 'arrears', 'mof'];
$parcelFilters = ['collection', 'arrears', 'registration', 'register', 'mof'];

$params = ['report=' . urlencode($report)];
if ($effProvince && in_array($report, $parcelFilters, true)) { $params[] = 'province_id=' . $effProvince; }
if ($district && in_array($report, $parcelFilters, true)) { $params[] = 'district_id=' . $district; }
if ($fee_year && in_array($report, $yearReports, true)) { $params[] = 'fee_year=' . $fee_year; }
if ($road !== '') { $params[] = 'road_category=' . urlencode($road); }
if ($from && in_array($report, $dateReports, true)) { $params[] = 'from=' . $from; }
if ($to   && in_array($report, $dateReports, true)) { $params[] = 'to=' . $to; }
if ($q !== '' && $report === 'register')            { $params[] = 'q=' . urlencode($q); }
if ($actor !== '' && $report === 'audit')           { $params[] = 'actor=' . urlencode($actor); }
if ($action !== '' && $report === 'audit')          { $params[] = 'action=' . urlencode($action); }
$qs = implode('&', $params);

$d = api_call('GET', 'reports.php?' . $qs, null, $token);
$data = $d['ok'] ? $d['data'] : null;
$error = $d['ok'] ? null : ($d['error']['message'] ?? t('Report failed'));

$provinces = api_call('GET', 'refdata.php?table=provinces', null, $token)['data'] ?? [];
// Only active provinces are offered as filters.
$provinces = array_values(array_filter($provinces, fn($pv) => !isset($pv['is_active']) || (int)$pv['is_active'] === 1));
$districts = api_call('GET', 'districts.php', null, $token)['data'] ?? [];
$years = api_call('GET', 'fee_years.php', null, $token)['data']['years'] ?? [];

// Group districts by province so the district dropdown can be rebuilt when the
// province changes (super admin) without another round trip.
$districtsByProvince = [];
foreach ($districts as $di) {
    $districtsByProvince[(int)($di['province_id'] ?? 0)][] = $di;
}

$reportLabels = [
    'collection'  => t('Collection'),
    'arrears'     => t('Arrears & aging'),
    'registration'=> t('Data entry progress'),
    'register'    => t('Parcel & owner register'),
    'bank'        => t('Bank reconciliation'),
    'rates'       => t('Rate schedule'),
    'audit'       => t('Audit trail'),
    'mof'         => t('MOF export (assessed/collected/outstanding)'),
];
// Picker grouping only; the flat map drives the heading and the CSV link.
$reportGroups = [
    t('Money')    => ['collection', 'arrears', 'mof'],
    t('Registry') => ['registration', 'register'],
    t('Controls') => ['bank', 'rates', 'audit'],
];
?>
<!DOCTYPE html>
<html lang="<?= i18n_current() ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= te('Reports') ?> — Land Fee MVP</title>
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
    <h1><?= te('Reports') ?></h1>
    <p class="sub"><?= te('All figures are derived from payments and fee_obligations. Export any report as CSV.') ?></p>

    <div class="form-box">
      <form method="get">
        <div class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(170px,1fr)); gap:12px;">
        <div class="form-group"><label><?= te('Report') ?></label>
          <select name="report">
            <?php foreach ($reportGroups as $group => $keys): ?>
            <optgroup label="<?= e($group) ?>">
              <?php foreach ($keys as $k): ?>
              <option value="<?= $k ?>" <?= $report === $k ? 'selected' : '' ?>><?= e($reportLabels[$k]) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if (in_array($report, $parcelFilters, true)): ?>
        <?php if ($canFilterProv): ?>
        <div class="form-group"><label><?= te('Province') ?></label>
          <select name="province_id" id="f-province">
            <option value=""><?= te('-- all --') ?></option>
            <?php foreach ($provinces as $pv): ?>
            <option value="<?= (int)$pv['id'] ?>" <?= $province == $pv['id'] ? 'selected' : '' ?>><?= e($pv['name_lo']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php elseif ($level === 'province'): ?>
        <div class="form-group"><label><?= te('Province') ?></label>
          <input type="hidden" name="province_id" value="<?= (int)$myProvince ?>">
          <input type="text" value="<?= e($_SESSION['admin_user']['province_name'] ?? '') ?>" disabled>
        </div>
        <?php endif; ?>
        <?php if ($canFilterDist): ?>
        <div class="form-group"><label><?= te('District') ?></label>
          <select name="district_id" id="f-district">
            <option value=""><?= te('-- all --') ?></option>
          </select>
        </div>
        <?php else: ?>
        <div class="form-group"><label><?= te('District') ?></label>
          <input type="text" value="<?= e($_SESSION['admin_user']['district_name'] ?? '') ?>" disabled>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php if (in_array($report, $yearReports, true)): ?>
        <div class="form-group"><label><?= te('Fee year') ?></label>
          <select name="fee_year"><option value=""><?= te('-- all --') ?></option>
            <?php foreach ($years as $y): ?>
            <option value="<?= e($y['year']) ?>" <?= $fee_year == $y['year'] ? 'selected' : '' ?>><?= e($y['year']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <?php if (in_array($report, $parcelFilters, true) && $report !== 'register'): ?>
        <div class="form-group"><label><?= te('Road') ?></label>
          <select name="road_category"><option value=""><?= te('-- all --') ?></option>
            <?php foreach (['main','branch','alley','none'] as $rc): ?>
            <option value="<?= $rc ?>" <?= $road === $rc ? 'selected' : '' ?>><?= e(t(ucfirst($rc))) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <?php if ($report === 'register'): ?>
        <div class="form-group"><label><?= te('Search parcel/title') ?></label><input name="q" value="<?= e($q) ?>"></div>
        <?php endif; ?>
        <?php if ($report === 'audit'): ?>
        <div class="form-group"><label><?= te('Actor') ?></label><input name="actor" value="<?= e($actor) ?>" placeholder="<?= te('username or id') ?>"></div>
        <div class="form-group"><label><?= te('Action') ?></label><input name="action_filter" value="<?= e($action) ?>" placeholder="<?= te('e.g. auto_match') ?>"></div>
        <?php endif; ?>
        <div class="form-group"><label>&nbsp;</label><button class="btn btn-green" type="submit"><?= te('Run') ?></button></div>
        </div>

        <?php if (in_array($report, $dateReports, true)): ?>
        <div class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(170px,1fr)); gap:12px; margin-top:14px; padding-top:14px; border-top:1px solid var(--border);">
          <div class="form-group" style="margin-bottom:0;"><label><?= te('From') ?></label><input name="from" type="date" value="<?= e($from) ?>"></div>
          <div class="form-group" style="margin-bottom:0;"><label><?= te('To') ?></label><input name="to" type="date" value="<?= e($to) ?>"></div>
        </div>
        <?php endif; ?>
      </form>
    </div>

    <?php if ($error): ?><p class="flash err"><?= e($error) ?></p><?php endif; ?>

    <?php if ($data): ?>
      <div class="form-box">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
          <h2 style="margin:0"><?= e($reportLabels[$report] ?? $report) ?>
            <span class="sub" style="margin-left:8px"><?= te('{n} row(s)', ['n' => count($data['rows'])]) ?></span>
          </h2>
          <a class="btn btn-blue" href="<?= e($base) ?>/reports_export.php?<?= e($qs) ?>"><?= te('Download CSV') ?></a>
        </div>

        <?php if (!empty($data['summary'])): ?>
        <div class="cards" style="margin-top:14px">
          <?php foreach ($data['summary'] as $k => $v): ?>
            <?php if (is_array($v)) { continue; } ?>
            <div class="card"><h3><?= e(t(ucfirst(str_replace('_', ' ', $k)))) ?></h3>
              <p class="num"><?= e(is_float($v) ? number_format($v, 0) : (string)$v) ?></p></div>
          <?php endforeach; ?>
        </div>
        <?php if ($report === 'registration' && !empty($data['summary']['confidence_distribution'])): ?>
        <table style="margin-top:10px">
          <thead><tr><th><?= te('OCR confidence') ?></th><th><?= te('Scans') ?></th></tr></thead>
          <tbody>
            <?php foreach ($data['summary']['confidence_distribution'] as $b): ?>
            <tr><td><?= e(t($b['bucket'])) ?></td><td><?= (int)$b['scans'] ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
        <?php if ($report === 'bank' && !empty($data['summary']['by_status'])): ?>
        <table style="margin-top:10px">
          <thead><tr><th><?= te('Match status') ?></th><th><?= te('Rows') ?></th><th><?= te('Total (kip)') ?></th></tr></thead>
          <tbody>
            <?php foreach ($data['summary']['by_status'] as $st => $vals): ?>
            <tr><td><?= e(t($st)) ?></td><td><?= (int)$vals['rows'] ?></td><td><?= number_format((float)$vals['total_kip'], 0) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="form-box" style="overflow-x:auto">
        <?php if (!$data['rows']): ?>
          <p class="empty"><?= te('No data for this report.') ?></p>
        <?php else: ?>
        <table>
          <thead><tr><?php foreach ($data['columns'] as $c): ?><th><?= e(t($c)) ?></th><?php endforeach; ?></tr></thead>
          <tbody>
            <?php foreach ($data['rows'] as $row): ?>
            <tr>
              <?php foreach (array_values($row) as $i => $cell): ?>
                <?php
                  // Readability: group digits on money/count columns, but never on
                  // identifiers, years, dates or short codes.
                  $label = $data['columns'][$i] ?? '';
                  $plain = is_array($cell) ? json_encode($cell) : (string)$cell;
                  $isNum = !is_array($cell) && is_numeric($plain);
                  $skip  = preg_match('/year|^id|no\.|date|status|conf/i', $label);
                  if ($isNum && !$skip && (float)$plain >= 10000) {
                      $plain = number_format((float)$plain, (strpos($plain, '.') !== false ? 2 : 0));
                  }
                ?>
                <td><?= e($plain) ?></td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </main>
</div>
<?php if ($canFilterDist): ?>
<script>
// The district dropdown follows the selected province. District data is embedded
// from the server, so switching province needs no extra round trip.
(function () {
  var byProvince = <?= json_encode($districtsByProvince, JSON_UNESCAPED_UNICODE) ?>;
  var allDistricts = <?= json_encode($districts, JSON_UNESCAPED_UNICODE) ?>;
  var provinceSel = document.getElementById('f-province');
  var districtSel = document.getElementById('f-district');
  if (!districtSel) return;
  var allLabel = <?= json_encode(t('-- all --')) ?>;
  var current = <?= json_encode((string)$district) ?>;

  function option(d, withProvince) {
    var label = d.name_lo + (withProvince && d.province_name ? ' — ' + d.province_name : '');
    return '<option value="' + d.id + '"' + (String(d.id) === current ? ' selected' : '') + '>' + label + '</option>';
  }

  function fill(pid) {
    var opts = '<option value="">' + allLabel + '</option>';
    if (pid) {
      var list = byProvince[pid] || [];
      for (var i = 0; i < list.length; i++) { opts += option(list[i], false); }
    } else {
      // No province chosen: offer every district, tagged with its province.
      for (var j = 0; j < allDistricts.length; j++) { opts += option(allDistricts[j], true); }
    }
    districtSel.innerHTML = opts;
  }

  var pid = provinceSel ? provinceSel.value : <?= json_encode((string)$myProvince) ?>;
  fill(pid);
  if (provinceSel) {
    provinceSel.addEventListener('change', function () {
      current = '';
      fill(this.value);
    });
  }
})();
</script>
<?php endif; ?>
</body>
</html>