<?php
// public/admin/obligations.php — annual obligations list + arrears/aging + generation tool
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
$role = $user['role'];
$token = $_SESSION['admin_token'];

// Managers are read-only: they can filter and read, never generate or adjust.
if ($is_manager && $_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(403);
    exit(t('Managers have read-only access.'));
}

$fee_year    = isset($_GET['fee_year'])    ? (int)$_GET['fee_year']    : 0;
$province_id = isset($_GET['province_id']) ? (int)$_GET['province_id'] : 0;
$district_id = isset($_GET['district_id']) ? (int)$_GET['district_id'] : 0;
$view        = $_GET['view'] ?? '';       // '' = all, 'arrears' = overdue only
$status      = $_GET['status'] ?? '';
$penalty_id  = isset($_GET['penalty']) ? (int)$_GET['penalty'] : 0;

// ---- POST: officer waive/reduce of the late-payment surcharge ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'penalty') {
    $mode = ($_POST['mode'] ?? 'set') === 'auto' ? 'auto' : 'set';
    $body = [
        'action'        => 'penalty',
        'obligation_id' => (int)($_POST['obligation_id'] ?? 0),
        'mode'          => $mode,
    ];
    if ($mode === 'set') {
        $body['amount'] = $_POST['amount'] ?? '';
        $body['reason'] = $_POST['reason'] ?? '';
    }
    $r = api_call('POST', 'fee_obligations.php', $body, $token);
    $back = trim(preg_replace('/(^|&)penalty=\d+/', '', $_SERVER['QUERY_STRING'] ?? ''), '&');
    $q = ['msg' => !empty($r['ok']) ? 'penalty_ok' : 'penalty_err'];
    if (empty($r['ok']) && !empty($r['error']['message'])) {
        $q['e'] = $r['error']['message'];
    }
    header('Location: obligations.php?' . ($back !== '' ? $back . '&' : '') . http_build_query($q));
    exit;
}

// ---- POST: generate obligations (all / by province / by district) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    $year = (int)($_POST['fee_year'] ?? date('Y'));
    $gpid = (int)($_POST['province_id'] ?? 0);
    $gdid = (int)($_POST['district_id'] ?? 0);
    $r = api_call('POST', 'fee_obligations.php', [
        'action' => 'generate',
        'fee_year' => $year,
        'province_id' => $gpid ?: null,
        'district_id' => $gdid ?: null,
    ], $token);
    // Stay on the scope that was just assessed instead of resetting the page.
    $back = ['fee_year' => $year];
    if ($gpid) { $back['province_id'] = $gpid; }
    if ($gdid) { $back['district_id'] = $gdid; }
    if (!empty($r['ok'])) {
        $back['msg'] = 'generated';
        $back['n'] = (int)($r['data']['assessed'] ?? 0);
    } else {
        $back['msg'] = 'error';
    }
    header('Location: obligations.php?' . http_build_query($back));
    exit;
}

$qs = [];
if ($fee_year)    { $qs['fee_year'] = $fee_year; }
if ($province_id) { $qs['province_id'] = $province_id; }
if ($district_id) { $qs['district_id'] = $district_id; }
if ($view)        { $qs['view'] = $view; }
if ($status !== '') { $qs['status'] = $status; }

$apiQs = $qs;
if ($view === 'arrears') { $apiQs['overdue'] = 1; }
$url = 'fee_obligations.php' . ($apiQs ? '?' . http_build_query($apiQs) : '');

$resp = api_call('GET', $url, null, $token);
$obligations = $resp['data']['obligations'] ?? [];
$count = $resp['data']['count'] ?? 0;

// Summaries
$yearsResp = api_call('GET', 'fee_years.php', null, $token);
$years = $yearsResp['data']['years'] ?? [];
$districts = api_call('GET', 'districts.php', null, $token)['data'] ?? [];
// Province dropdown: one row per province, value = province id (same pattern as parcels.php).
$provinces = [];
foreach ($districts as $drow) {
    $pid = (int)($drow['province_id'] ?? 0);
    $pname = $drow['province_name'] ?? '';
    if ($pid && $pname && !isset($provinces[$pid])) { $provinces[$pid] = $pname; }
}
asort($provinces);

$totalDue = 0.0;
$totalPaid = 0.0;
$totalPenalty = 0.0;
$overdueCount = 0;
$penaltyRow = null;
foreach ($obligations as $o) {
    $totalDue += (float)$o['amount_due'];
    $totalPaid += (float)$o['amount_paid'];
    $totalPenalty += (float)$o['penalty'];
    if (in_array($o['status'], ['UNPAID', 'PARTIAL'], true) && $o['days_overdue'] > 0) { $overdueCount++; }
    if ($penalty_id && (int)$o['id'] === $penalty_id) { $penaltyRow = $o; }
}
$msg = $_GET['msg'] ?? '';
// Generate-box year choices: only years that actually exist in
// Basic Data → Fee years. Adding this year and next year unconditionally let an
// officer bill a year nobody had created, and generating it silently opened that
// year with a guessed Dec-31 due date — which the nightly cron then keeps billing.
$genYears = [];
foreach ($years as $y) { $genYears[] = (int)$y['year']; }
if (!$genYears) { $genYears[] = (int)date('Y'); } // fresh DB: keep the control usable
$genYears = array_values(array_unique($genYears));
rsort($genYears);
$genYear = $fee_year ?: (int)date('Y');
if (!in_array($genYear, $genYears, true)) { $genYear = $genYears[0]; }

// Per-year metadata for the Generate box. Two independent facts matter:
//  - past:  the due date has gone, so anything created now is overdue at once and
//           fee_penalty_sql() adds the 10% surcharge to it.
//  - cron:  whether the 02:00 job covers this year. This mirrors
//           cron_generate_obligations.php exactly (open AND (due today or later OR
//           year this year or later)) — a current year past its due date is still
//           covered, while a closed or future-past year is not.
$today    = date('Y-m-d');
$curYear  = (int)date('Y');
$genMeta = [];
foreach ($years as $y) {
    $yy  = (int)$y['year'];
    $due = substr((string)($y['due_date'] ?? ''), 0, 10);
    $st  = (string)($y['status'] ?? '');
    $genMeta[$yy] = [
        'status' => $st,
        'due'    => $due,
        'past'   => $due !== '' && $due < $today,
        'cron'   => $st === 'open' && (($due !== '' && $due >= $today) || $yy >= $curYear),
    ];
}
?>
<!DOCTYPE html>
<html lang="<?= i18n_current() ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= te('Annual Obligations') ?> — Land Fee MVP</title>
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
    <?php if ($msg === 'generated'): ?><p class="flash ok"><?= te('Generated obligations for {year} — {n} parcel(s).', ['year' => (int)($_GET['fee_year'] ?? 0), 'n' => (int)($_GET['n'] ?? 0)]) ?></p><?php endif; ?>
    <?php if ($msg === 'error'): ?><p class="flash err"><?= te('Generation failed.') ?></p><?php endif; ?>
    <?php if ($msg === 'penalty_ok'): ?><p class="flash ok"><?= te('Penalty updated.') ?></p><?php endif; ?>
    <?php if ($msg === 'penalty_err'): ?><p class="flash err"><?= te('Penalty update failed.') ?><?= !empty($_GET['e']) ? ': ' . e($_GET['e']) : '' ?></p><?php endif; ?>

    <h1><?= te('Annual Obligations') ?></h1>
    <p class="sub"><?= te('Fee = zone rate (district × road category) × area. One obligation per parcel per year.') ?></p>

    <div class="form-box">
      <form method="get" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px;">
        <div class="form-group"><label><?= te('Tax year') ?></label>
          <select name="fee_year"><option value=""><?= te('-- all --') ?></option>
            <?php foreach ($years as $y): ?>
            <option value="<?= e($y['year']) ?>" <?= $fee_year == $y['year'] ? 'selected' : '' ?>><?= e($y['year']) ?> (<?= e(t($y['status'])) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label><?= te('Province') ?></label>
          <select name="province_id" id="obl-province"><option value=""><?= te('-- all --') ?></option>
            <?php foreach ($provinces as $pid => $pname): ?>
            <option value="<?= $pid ?>" <?= $province_id === $pid ? 'selected' : '' ?>><?= e($pname) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label><?= te('District') ?></label>
          <select name="district_id" id="obl-district"><option value=""><?= te('-- all --') ?></option>
            <?php foreach ($districts as $d): ?>
            <option value="<?= $d['id'] ?>" <?= $district_id == $d['id'] ? 'selected' : '' ?>><?= e($d['name_lo']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label><?= te('Status') ?></label>
          <select name="status"><option value=""><?= te('-- all --') ?></option>
            <?php foreach (['UNPAID','PARTIAL','PAID','OVERPAID','WAIVED','DISPUTED'] as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(t($s)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label><?= te('View') ?></label>
          <select name="view"><option value=""><?= te('All') ?></option><option value="arrears" <?= $view === 'arrears' ? 'selected' : '' ?>><?= te('Arrears only') ?></option></select>
        </div>
        <div class="form-group"><label>&nbsp;</label><button class="btn btn-green" type="submit"><?= te('Filter') ?></button></div>
      </form>
    </div>

    <?php if (!$is_manager): ?>
    <div class="form-box">
      <h2><?= te('Generate obligations') ?></h2>
      <form method="post" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px;">
        <input type="hidden" name="action" value="generate">
        <div class="form-group"><label><?= te('Tax year') ?></label>
          <select name="fee_year" id="obl-gen-year">
            <?php foreach ($genYears as $yy): $m = $genMeta[$yy] ?? null; ?>
            <option value="<?= $yy ?>" data-past="<?= empty($m['past']) ? 0 : 1 ?>"
                    data-cron="<?= empty($m['cron']) ? 0 : 1 ?>"
                    <?= $genYear === $yy ? 'selected' : '' ?>><?= $yy ?><?php if ($m): ?>
              (<?= e(t($m['status'])) ?><?= $m['due'] !== '' ? ', ' . te('Due date') . ' ' . e($m['due']) : '' ?>)<?php endif; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label><?= te('Province (optional)') ?></label>
          <select name="province_id" id="obl-gen-province"><option value=""><?= te('-- all --') ?></option>
            <?php foreach ($provinces as $pid => $pname): ?>
            <option value="<?= $pid ?>" <?= $province_id == $pid ? 'selected' : '' ?>><?= e($pname) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label><?= te('District (optional)') ?></label>
          <select name="district_id" id="obl-gen-district" data-all-label="<?= te('-- all confirmed parcels --') ?>"><option value=""><?= te('-- all confirmed parcels --') ?></option>
            <?php foreach ($districts as $d): ?>
            <option value="<?= $d['id'] ?>" <?= $district_id == $d['id'] ? 'selected' : '' ?>><?= e($d['name_lo']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>&nbsp;</label><button class="btn btn-blue" type="submit"><?= te('Generate') ?></button></div>
      </form>
      <p class="flash err" id="obl-gen-past" style="display:none;"><?= te('Past due year: every obligation created now is overdue on arrival, so the 10% late-payment surcharge is added to it.') ?></p>
      <p class="flash ok" id="obl-gen-cron-yes" style="display:none;"><?= te('The nightly job already covers this year, so pressing Generate only does it sooner.') ?></p>
      <p class="flash err" id="obl-gen-cron-no" style="display:none;"><?= te('The nightly job will NOT bill this year (it is not open, or its due date has passed). Generate here is the only way to assess it, and you must re-run it after registering more parcels.') ?></p>
      <p class="sub"><?= te('Safe to re-run: existing unpaid obligations are updated to the current rate; paid ones are left alone.') ?></p>
    </div>
    <?php endif; ?>

    <?php if (!$is_manager && $penaltyRow): ?>
    <div class="form-box">
      <h2><?= te('Adjust penalty') ?> — <?= te('Parcel') ?> <?= e($penaltyRow['parcel_no']) ?> (<?= (int)$penaltyRow['fee_year'] ?>)</h2>
      <p class="sub">
        <?= te('Land tax') ?>: <?= number_format((float)$penaltyRow['amount_due'], 0) ?> <?= te('kip') ?> &nbsp;|&nbsp;
        <?= te('Late-payment surcharge') ?>: <?= number_format((float)$penaltyRow['penalty'], 0) ?> <?= te('kip') ?> &nbsp;|&nbsp;
        <?= te('Due date') ?>: <?= e($penaltyRow['due_date']) ?>
      </p>
      <form method="post" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px;">
        <input type="hidden" name="action" value="penalty">
        <input type="hidden" name="obligation_id" value="<?= (int)$penaltyRow['id'] ?>">
        <div class="form-group"><label><?= te('Penalty override') ?> (<?= te('kip') ?>)</label>
          <input type="number" name="amount" min="0" step="0.01"
                 value="<?= e($penaltyRow['penalty_override'] !== null ? $penaltyRow['penalty_override'] : $penaltyRow['penalty']) ?>">
        </div>
        <div class="form-group"><label><?= te('Reason') ?></label>
          <input type="text" name="reason" maxlength="500" value="<?= e($penaltyRow['penalty_note'] ?? '') ?>">
        </div>
        <div class="form-group"><label>&nbsp;</label>
          <button class="btn btn-blue" type="submit" name="mode" value="set"><?= te('Save') ?></button>
        </div>
        <div class="form-group"><label>&nbsp;</label>
          <button class="btn btn-green" type="submit" name="mode" value="auto"><?= te('Automatic (10%)') ?></button>
        </div>
      </form>
      <p class="sub"><?= te('The surcharge is 10% of the land tax when payment is late. An officer may waive or reduce it with a reason; every change is audited.') ?></p>
    </div>
    <?php endif; ?>

    <div class="form-box">
      <h2><?= te('Summary') ?></h2>
      <table>
        <tbody>
          <tr><th><?= te('Count') ?></th><td><?= $count ?></td></tr>
          <tr><th><?= te('Total due') ?></th><td><?= number_format($totalDue, 0) ?> <?= te('kip') ?></td></tr>
          <tr><th><?= te('Total penalty') ?></th><td><?= number_format($totalPenalty, 0) ?> <?= te('kip') ?></td></tr>
          <tr><th><?= te('Total paid') ?></th><td><?= number_format($totalPaid, 0) ?> <?= te('kip') ?></td></tr>
          <tr><th><?= te('Outstanding') ?></th><td><?= number_format($totalDue + $totalPenalty - $totalPaid, 0) ?> <?= te('kip') ?></td></tr>
          <tr><th><?= te('Overdue (unpaid & past due)') ?></th><td><?= $overdueCount ?></td></tr>
        </tbody>
      </table>
    </div>

    <?php if ($count === 0): ?>
      <p class="empty"><?= te('No obligations match the filter.') ?></p>
    <?php else: ?>
    <table style="margin-top:8px;">
      <thead><tr><th><?= te('Year') ?></th><th><?= te('Parcel') ?></th><th><?= te('District') ?></th><th><?= te('Village') ?></th><th><?= te('Rate') ?></th><th><?= te('Area') ?></th><th><?= te('Due') ?></th><th><?= te('Paid') ?></th><th><?= te('Penalty') ?></th><th><?= te('Status') ?></th><th><?= te('Due date') ?></th><th><?= te('Overdue days') ?></th></tr></thead>
      <tbody>
        <?php foreach ($obligations as $o): ?>
        <tr>
          <td><?= e($o['fee_year']) ?></td>
          <td><a href="<?= $base ?>/parcel.php?id=<?= (int)$o['parcel_id'] ?>"><?= e($o['parcel_no']) ?></a></td>
          <td><?= e($o['district_name']) ?></td>
          <td><?= e($o['village_name']) ?></td>
          <td><?= number_format((float)$o['rate_per_sqm'], 0) ?></td>
          <td><?= number_format((float)$o['area_sqm'], 2) ?></td>
          <td><?= number_format((float)$o['amount_due'], 0) ?></td>
          <td><?= number_format((float)$o['amount_paid'], 0) ?></td>
          <td>
            <?= number_format((float)$o['penalty'], 0) ?>
            <?php if ($o['penalty_override'] !== null): ?>
              <span class="sub">(<?= (float)$o['penalty'] > 0 ? te('reduced') : te('waived') ?>)</span>
            <?php endif; ?>
            <?php if (!$is_manager && in_array($o['status'], ['UNPAID', 'PARTIAL'], true) && (int)$o['days_overdue'] > 0): ?>
              <br><a href="obligations.php?<?= e(http_build_query(array_merge($qs, ['penalty' => (int)$o['id']]))) ?>"><?= te('Adjust') ?></a>
            <?php endif; ?>
          </td>
          <td><span class="pill pill-<?= e(strtolower($o['status'])) ?>"><?= e(t($o['status'])) ?></span></td>
          <td><?= e($o['due_date']) ?></td>
          <td><?= (int)$o['days_overdue'] > 0 ? (int)$o['days_overdue'] : '' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </main>
</div>
<script>
// All districts (id, name_lo, province_id) — province→district cascades on both
// forms filter this client-side; no extra API round-trip or auth header needed.
var allDistricts = <?= json_encode($districts, JSON_UNESCAPED_UNICODE) ?>;
(function(){
  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

  // Rebuild a district dropdown to only the selected province's districts.
  function rebuildDistricts(provinceId, districtSel) {
    var allLabel = districtSel.getAttribute('data-all-label') || '-- all --';
    var opts = '<option value="">' + esc(allLabel) + '</option>';
    for (var i = 0; i < allDistricts.length; i++) {
      var d = allDistricts[i];
      if (!provinceId || Number(d.province_id) === Number(provinceId)) {
        opts += '<option value="' + d.id + '">' + esc(d.name_lo || d.name_en || '') + '</option>';
      }
    }
    districtSel.innerHTML = opts;
  }

  // Filter form cascade.
  var fProvince = document.getElementById('obl-province');
  var fDistrict = document.getElementById('obl-district');
  if (fProvince && fDistrict) {
    fProvince.onchange = function() { rebuildDistricts(this.value || '', fDistrict); };
    // Initial state: province filter in the URL restricts the district list
    // before re-selecting the district.
    var initDistrict = fDistrict.value;
    if (fProvince.value) { rebuildDistricts(fProvince.value, fDistrict); }
    if (initDistrict)    { fDistrict.value = initDistrict; }
  }

  // Generate-form cascade: prefilled from the filters above, so what you see
  // filtered is what a Generate run assesses.
  var gProvince = document.getElementById('obl-gen-province');
  var gDistrict = document.getElementById('obl-gen-district');
  if (gProvince && gDistrict) {
    gProvince.onchange = function() { rebuildDistricts(this.value || '', gDistrict); };
    var genDistrict = gDistrict.value;
    if (gProvince.value) { rebuildDistricts(gProvince.value, gDistrict); }
    if (genDistrict)     { gDistrict.value = genDistrict; }
  }

  // Warn what the chosen year means before pressing Generate: a past-due year is
  // arrears work (overdue immediately, never re-billed by the nightly job), while
  // a not-yet-due year is already handled automatically.
  var genYearSel = document.getElementById('obl-gen-year');
  var notePast    = document.getElementById('obl-gen-past');
  var noteCronYes = document.getElementById('obl-gen-cron-yes');
  var noteCronNo  = document.getElementById('obl-gen-cron-no');
  function showGenNote() {
    if (!genYearSel || !notePast || !noteCronYes || !noteCronNo) return;
    var opt  = genYearSel.options[genYearSel.selectedIndex];
    var past = opt ? opt.getAttribute('data-past') : null;
    var cron = opt ? opt.getAttribute('data-cron') : null;
    // No meta (a year with no fee_years row yet): stay silent rather than guess.
    notePast.style.display    = past === '1' ? 'block' : 'none';
    noteCronYes.style.display = cron === '1' ? 'block' : 'none';
    noteCronNo.style.display  = cron === '0' ? 'block' : 'none';
  }
  if (genYearSel) { genYearSel.onchange = showGenNote; showGenNote(); }
})();
</script>
</body>
</html>