<?php
// public/admin/reports.php — report picker + generic renderer + CSV export
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
$role = $user['role'];
$token = $_SESSION['admin_token'];

$report   = $_GET['report'] ?? 'collection';
$province = isset($_GET['province_id']) ? (int)$_GET['province_id'] : 0;
$district = isset($_GET['district_id']) ? (int)$_GET['district_id'] : 0;
$village  = isset($_GET['village_id'])  ? (int)$_GET['village_id']  : 0;
$fee_year = isset($_GET['fee_year']) ? (int)$_GET['fee_year'] : 0;
$road     = $_GET['road_category'] ?? '';
// The period picker collapses to the two dates the report API understands.
$period   = report_period($_GET);
$from     = $period['from'];
$to       = $period['to'];
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
// Only the rate schedule stays out: it is a table of legal values rather than
// events, it holds one version per zone and road so there is no rate history to
// look back at, and a schedule is read as at a date, not between two of them.
$dateReports   = ['collection', 'arrears', 'registration', 'register', 'bank', 'audit', 'mof'];
// What the picked period actually selects, because the same picker means
// different things on different screens and a wrong reading costs a signature.
$periodMeaning = [
    'collection'   => t('Payments received in the period'),
    'arrears'      => t('Obligations falling due in the period; balances stay as of today'),
    'registration' => t('Parcels registered and title pages scanned in the period'),
    'register'     => t('Titles issued in the period; parcels with no issue date are not listed'),
    'bank'         => t('Statement rows and the payments they settle, in the period'),
    'audit'        => t('Log entries in the period'),
    'mof'          => t('Collected in the period; assessed and outstanding stay annual'),
];
$yearReports   = ['collection', 'arrears', 'mof'];
$parcelFilters = ['collection', 'arrears', 'registration', 'register', 'mof'];

// Whether this report reads a period at all, and the picker fields that say
// which interval was chosen. Only from= and to= reach the report API, so the
// document links carry the picker as submitted and reports_print.php resolves
// it the same way the screen does — a quarter has to come back as quarter 2.
// The dates travel with the picker rather than in the core query so the
// browser can substitute the boxes as they now stand.
$usePeriod = in_array($report, $dateReports, true);

$params = ['report=' . urlencode($report)];
$dates  = [];
if ($effProvince && in_array($report, $parcelFilters, true)) { $params[] = 'province_id=' . $effProvince; }
if ($district && in_array($report, $parcelFilters, true)) { $params[] = 'district_id=' . $district; }
if ($village && in_array($report, $parcelFilters, true)) { $params[] = 'village_id=' . $village; }
if ($fee_year && in_array($report, $yearReports, true)) { $params[] = 'fee_year=' . $fee_year; }
if ($road !== '') { $params[] = 'road_category=' . urlencode($road); }
if ($from && $usePeriod) { $dates[] = 'from=' . $from; }
if ($to   && $usePeriod) { $dates[] = 'to=' . $to; }
if ($q !== '' && $report === 'register')            { $params[] = 'q=' . urlencode($q); }
if ($actor !== '' && $report === 'audit')           { $params[] = 'actor=' . urlencode($actor); }
if ($action !== '' && $report === 'audit')          { $params[] = 'action=' . urlencode($action); }
$qsCore = implode('&', $params);
$qs     = $qsCore . ($dates ? '&' . implode('&', $dates) : '');

// Signature placeholders for the printed document. They are not part of the
// report query — only of the print links, appended after the report labels are
// known. A box the officer cleared comes back as sig2= (present but empty) and
// must stay empty, so the default label is used only when the parameter is
// absent, not when it is blank.
$sigValues = [];
$sigPairs  = [];
for ($i = 1; $i <= 4; $i++) {
    $k = 'sig' . $i;
    $sigValues[$i] = isset($_GET[$k]) ? (string)$_GET[$k] : t('Signature {n}', ['n' => $i]);
    $sigPairs[] = $k . '=' . urlencode($sigValues[$i]);
}

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

// The printed title travels to the document only, never to the API query. An
// empty box falls back to the report's own name, because an official document
// with no heading is not what the officer meant to produce.
//
// Changing the report submits this very form, so the box arrives in the URL with
// whatever it held — switching from Collection to Audit trail otherwise printed
// "Report: Collection" over the audit table. A title equal to some other
// report's generated name is therefore stale and is regenerated; anything the
// officer typed by hand is kept exactly as typed.
$defaultTitle = t('Report: {name}', ['name' => $reportLabels[$report] ?? $report]);
$otherTitles  = [];
foreach ($reportLabels as $key => $label) {
    if ($key !== $report) { $otherTitles[] = t('Report: {name}', ['name' => $label]); }
}
$typedTitle = isset($_GET['rtitle']) ? (string)$_GET['rtitle'] : '';
$rtitle = (trim($typedTitle) === '' || in_array($typedTitle, $otherTitles, true)) ? $defaultTitle : $typedTitle;

// Reporting periods are calendar facts, so the year list must not depend on
// which fee years an admin happens to have opened: the last eleven years.
$periodYears = range((int)date('Y'), (int)date('Y') - 10);
$pMonth   = isset($_GET['p_month'])   ? (int)$_GET['p_month']   : (int)date('n');
$pQuarter = isset($_GET['p_quarter'])  ? (int)$_GET['p_quarter'] : (int)ceil(date('n') / 3);
$pHalf    = isset($_GET['p_half'])     ? (int)$_GET['p_half']    : (date('n') <= 6 ? 1 : 2);
$pYearM   = isset($_GET['p_year_m'])   ? (int)$_GET['p_year_m']  : (int)date('Y');
$pYearQ   = isset($_GET['p_year_q'])   ? (int)$_GET['p_year_q']  : (int)date('Y');
$pYearH   = isset($_GET['p_year_h'])   ? (int)$_GET['p_year_h']  : (int)date('Y');
$pYear    = isset($_GET['p_year'])     ? (int)$_GET['p_year']    : (int)date('Y');

// The links' query: the report filters plus the picker as it now stands. The
// browser rebuilds that second part from the live controls, so printing after
// typing a date or moving a select cannot use an interval the officer has
// already changed — and the href is still right with scripting off.
$qsPicker = $usePeriod ? http_build_query([
    'from'      => $from,     'to'       => $to,
    'pmode'     => $period['mode'],
    'p_month'   => $pMonth,   'p_year_m' => $pYearM,
    'p_quarter' => $pQuarter, 'p_year_q' => $pYearQ,
    'p_half'    => $pHalf,    'p_year_h' => $pYearH,
    'p_year'    => $pYear,
]) : '';
$qsLive = $qsCore . ($qsPicker !== '' ? '&' . $qsPicker : '');
$qsDoc  = $qsLive . '&' . implode('&', $sigPairs) . '&rtitle=' . urlencode($rtitle);
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
    <p class="sub"><?= te('All figures are derived from payments and fee_obligations.') ?></p>

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
        <div class="form-group"><label><?= te('Village') ?></label>
          <select name="village_id" id="f-village">
            <option value=""><?= te('-- all --') ?></option>
          </select>
        </div>
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

        <div class="<?= $usePeriod ? 'per-split' : '' ?>" style="margin-top:14px; padding-top:14px; border-top:1px solid var(--border);">
        <?php if ($usePeriod): ?>
          <div class="per-col">
            <div class="per-head"><?= te('Period') ?></div>
            <?php if (!empty($periodMeaning[$report])): ?>
            <div class="per-hint"><?= e($periodMeaning[$report]) ?></div>
            <?php endif; ?>
            <?php
            // One row per mode; the radio decides which fields report_period() reads.
            $pm = $period['mode'];
            $mkYearSel = static function (string $name) use ($periodYears, $pYearM, $pYearQ, $pYearH, $pYear): string {
                $sel = ['p_year_m' => $pYearM, 'p_year_q' => $pYearQ, 'p_year_h' => $pYearH, 'p_year' => $pYear][$name];
                $out = '<select name="' . $name . '">';
                foreach ($periodYears as $yy) {
                    $out .= '<option value="' . $yy . '"' . ($yy === $sel ? ' selected' : '') . '>' . $yy . '</option>';
                }
                return $out . '</select>';
            };
            ?>
            <label class="per-row">
              <input type="radio" name="pmode" value="range" <?= $pm === 'range' ? 'checked' : '' ?>>
              <span class="per-lbl"><?= te('From date') ?></span>
              <span class="per-in">
                <input name="from" type="date" value="<?= e($from) ?>">
                <span class="per-sep"><?= te('To') ?></span>
                <input name="to" type="date" value="<?= e($to) ?>">
              </span>
            </label>
            <label class="per-row">
              <input type="radio" name="pmode" value="month" <?= $pm === 'month' ? 'checked' : '' ?>>
              <span class="per-lbl"><?= te('Month') ?></span>
              <span class="per-in">
                <select name="p_month">
                  <?php for ($mm = 1; $mm <= 12; $mm++): ?>
                  <option value="<?= $mm ?>" <?= $mm === $pMonth ? 'selected' : '' ?>><?= $mm ?></option>
                  <?php endfor; ?>
                </select>
                <?= $mkYearSel('p_year_m') ?>
              </span>
            </label>
            <label class="per-row">
              <input type="radio" name="pmode" value="quarter" <?= $pm === 'quarter' ? 'checked' : '' ?>>
              <span class="per-lbl"><?= te('Quarter') ?></span>
              <span class="per-in">
                <select name="p_quarter">
                  <?php for ($qq = 1; $qq <= 4; $qq++): ?>
                  <option value="<?= $qq ?>" <?= $qq === $pQuarter ? 'selected' : '' ?>><?= $qq ?></option>
                  <?php endfor; ?>
                </select>
                <?= $mkYearSel('p_year_q') ?>
              </span>
            </label>
            <label class="per-row">
              <input type="radio" name="pmode" value="half" <?= $pm === 'half' ? 'checked' : '' ?>>
              <span class="per-lbl"><?= te('6 months') ?></span>
              <span class="per-in">
                <select name="p_half">
                  <option value="1" <?= $pHalf === 1 ? 'selected' : '' ?>>1–6</option>
                  <option value="2" <?= $pHalf === 2 ? 'selected' : '' ?>>7–12</option>
                </select>
                <?= $mkYearSel('p_year_h') ?>
              </span>
            </label>
            <label class="per-row">
              <input type="radio" name="pmode" value="year" <?= $pm === 'year' ? 'checked' : '' ?>>
              <span class="per-lbl"><?= te('Year') ?></span>
              <span class="per-in"><?= $mkYearSel('p_year') ?></span>
            </label>
          </div>
        <?php endif; ?>
          <div class="doc-col">
            <div class="form-group">
              <label for="rep-rtitle"><?= te('Main title') ?></label>
              <input name="rtitle" id="rep-rtitle" value="<?= e($rtitle) ?>" maxlength="120">
            </div>
            <div class="form-grid" style="margin-top:12px;">
              <?php for ($i = 1; $i <= 4; $i++): ?>
              <div class="form-group" style="margin-bottom:0;">
                <label><?= te('Signature {n}', ['n' => $i]) ?></label>
                <input name="sig<?= $i ?>" value="<?= e($sigValues[$i]) ?>" maxlength="60">
              </div>
              <?php endfor; ?>
            </div>
          </div>
        </div>
      </form>
    </div>

    <?php if ($error): ?><p class="flash err"><?= e($error) ?></p><?php endif; ?>

    <?php if ($data): ?>
      <div class="form-box">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
          <h2 style="margin:0"><?= e($reportLabels[$report] ?? $report) ?>
            <span class="sub" style="margin-left:8px"><?= te('{n} row(s)', ['n' => count($data['rows'])]) ?></span>
          </h2>
          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a class="btn btn-gold" id="rep-print-link" href="<?= e($base) ?>/reports_print.php?<?= e($qsDoc) ?>" target="_blank" rel="noopener"><?= te('Preview & Print') ?></a>
            <a class="btn btn-green" id="rep-pdf-link" href="<?= e($base) ?>/reports_print.php?<?= e($qsDoc) ?>&amp;auto=1" target="_blank" rel="noopener"><?= te('Download PDF') ?></a>
            <a class="btn btn-blue" id="rep-csv-link" href="<?= e($base) ?>/reports_export.php?<?= e($qsLive) ?>"><?= te('Download CSV') ?></a>
          </div>
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
<script>
// The village dropdown follows the chosen district: the villages API caps its
// list, so we AJAX-load the selected district's villages rather than embed all
// of them. Scoped users (no district filter) seed from their own district.
(function () {
  var villageSel = document.getElementById('f-village');
  if (!villageSel) return;
  var districtSel = document.getElementById('f-district');
  var seedDistrict = <?= json_encode((string)($canFilterDist ? $district : $myDistrict)) ?>;
  var apiBase = <?= json_encode($api_base ?? '') ?>;

  function clearVillages(keep) {
    villageSel.innerHTML = '<option value=""><?= e(te('-- all --')) ?></option>';
    if (keep) villageSel.value = keep;
  }

  function loadVillages(did) {
    if (!did) { clearVillages(''); return; }
    clearVillages('');
    var url = apiBase + '/villages.php?district_id=' + encodeURIComponent(did);
    fetch(url, { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) return;
        var rows = j.data || [];
        var cur = villageSel.value;
        var html = '<option value=""><?= e(te('-- all --')) ?></option>';
        for (var i = 0; i < rows.length; i++) {
          var v = rows[i];
          var label = v.name_lo + (v.name_en ? ' — ' + v.name_en : '');
          html += '<option value="' + v.id + '">' + label + '</option>';
        }
        villageSel.innerHTML = html;
        if (cur) villageSel.value = cur;
      })
      .catch(function () {});
  }

  // Initial: seed from the district already on the page.
  loadVillages(districtSel ? districtSel.value : seedDistrict);
  if (districtSel) {
    districtSel.addEventListener('change', function () { loadVillages(this.value); });
  }
})();
</script>
<script>
// Choosing a report did not apply until Run was pressed, because the filters are
// a GET form. Meanwhile the print, PDF and CSV links were built when the page
// loaded, so an officer who picked a report and clicked straight through got the
// report that was open at the time — normally Collection. Applying the choice on
// change keeps the heading, the table and every link in step with the dropdown,
// and leaves the server deciding which filters apply to which report.
(function () {
  var reportSel = document.querySelector('select[name="report"]');
  if (reportSel) {
    reportSel.addEventListener('change', function () { reportSel.form.submit(); });
  }
})();

// The same reasoning covers the period picker: a quarter or a year is a choice,
// not text being typed, so picking one applies it at once and the table on
// screen cannot disagree with the document being printed. The two date boxes
// still wait for Run to redraw the table, but the links read them as typed.
(function () {
  var col = document.querySelector('.per-col');
  if (!col) return;
  col.querySelectorAll('input[type="radio"], select').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
  });
})();

// The print, PDF and CSV buttons are links, so they carried whatever the picker
// said when the page loaded: typing a date range and clicking straight through
// printed the interval from the last run. Every href is rebuilt from the form as
// it now stands. A cleared signature box is sent as sig2= (present but empty);
// the print page keeps that slot as an empty position rather than reverting to
// its default label.
(function () {
  var printBase  = <?= json_encode($base . '/reports_print.php?') ?>;
  var exportBase = <?= json_encode($base . '/reports_export.php?') ?>;
  var coreQs     = <?= json_encode($qsCore) ?>;
  var printLink  = document.getElementById('rep-print-link');
  var pdfLink    = document.getElementById('rep-pdf-link');
  var csvLink    = document.getElementById('rep-csv-link');
  var form       = document.querySelector('form');
  if (!printLink || !pdfLink || !csvLink || !form) return;

  function field(name) { return form.querySelector('[name="' + name + '"]'); }
  function val(name) { var el = field(name); return el ? el.value : ''; }

  // The picker exactly as the form would submit it: every row sends its
  // controls, and the print page reads only the checked mode's.
  function pickerQs() {
    if (!field('pmode')) return '';
    var checked = form.querySelector('input[name="pmode"]:checked');
    var parts = ['pmode=' + encodeURIComponent(checked ? checked.value : 'range')];
    ['from', 'to', 'p_month', 'p_year_m', 'p_quarter', 'p_year_q',
     'p_half', 'p_year_h', 'p_year'].forEach(function (n) {
      parts.push(n + '=' + encodeURIComponent(val(n)));
    });
    return '&' + parts.join('&');
  }

  function boxes() {
    var out = [];
    for (var i = 1; i <= 4; i++) { out.push(field('sig' + i)); }
    return out;
  }

  function refresh() {
    var core = coreQs + pickerQs();
    var parts = [];
    boxes().forEach(function (b, i) {
      parts.push('sig' + (i + 1) + '=' + encodeURIComponent(b ? b.value : ''));
    });
    parts.push('rtitle=' + encodeURIComponent(val('rtitle')));
    var href = printBase + core + '&' + parts.join('&');
    printLink.href = href;
    pdfLink.href   = href + '&auto=1';
    csvLink.href   = exportBase + core;
  }

  form.addEventListener('input', refresh);
  form.addEventListener('change', refresh);
  refresh();
})();
</script>
</body>
</html>