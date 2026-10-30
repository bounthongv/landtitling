<?php
// public/admin/obligations.php — annual obligations list + arrears/aging + generation tool
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
$role = $user['role'];
$token = $_SESSION['admin_token'];

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

// ---- POST: generate obligations (all / by district) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    $year = (int)($_POST['fee_year'] ?? date('Y'));
    $did  = (int)($_POST['district_id'] ?? 0);
    $r = api_call('POST', 'fee_obligations.php', [
        'action' => 'generate',
        'fee_year' => $year,
        'district_id' => $did ?: null,
    ], $token);
    if (!empty($r['ok'])) {
        header('Location: obligations.php?fee_year=' . $year . '&msg=generated&n=' . (int)($r['data']['assessed'] ?? 0));
    } else {
        header('Location: obligations.php?msg=error');
    }
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

    <div class="form-box">
      <h2><?= te('Generate obligations') ?></h2>
      <form method="post" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px;">
        <input type="hidden" name="action" value="generate">
        <div class="form-group"><label><?= te('Tax year') ?></label>
          <select name="fee_year">
            <?php
            $thisYear = (int)date('Y');
            foreach (range($thisYear, $thisYear + 1) as $yy): ?>
            <option value="<?= $yy ?>"><?= $yy ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label><?= te('Province (optional)') ?></label>
          <select name="province_id" id="obl-gen-province"><option value=""><?= te('-- all --') ?></option>
            <?php foreach ($provinces as $pid => $pname): ?>
            <option value="<?= $pid ?>"><?= e($pname) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label><?= te('District (optional)') ?></label>
          <select name="district_id" id="obl-gen-district"><option value=""><?= te('-- all confirmed parcels --') ?></option>
            <?php foreach ($districts as $d): ?>
            <option value="<?= $d['id'] ?>"><?= e($d['name_lo']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>&nbsp;</label><button class="btn btn-blue" type="submit"><?= te('Generate') ?></button></div>
      </form>
      <p class="sub"><?= te('Safe to re-run: existing unpaid obligations are updated to the current rate; paid ones are left alone.') ?></p>
    </div>

    <?php if ($penaltyRow): ?>
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
            <?php if (in_array($o['status'], ['UNPAID', 'PARTIAL'], true) && (int)$o['days_overdue'] > 0): ?>
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
    var opts = '<option value="">-- all --</option>';
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

  // Generate-form cascade (no URL state; starts with all districts).
  var gProvince = document.getElementById('obl-gen-province');
  var gDistrict = document.getElementById('obl-gen-district');
  if (gProvince && gDistrict) {
    gProvince.onchange = function() { rebuildDistricts(this.value || '', gDistrict); };
  }
})();
</script>
</body>
</html>