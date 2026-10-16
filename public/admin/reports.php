<?php
// public/admin/reports.php — report picker + generic renderer + CSV export
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
$role = $user['role'];
$token = $_SESSION['admin_token'];

$report   = $_GET['report'] ?? 'collection';
$district = isset($_GET['district_id']) ? (int)$_GET['district_id'] : 0;
$fee_year = isset($_GET['fee_year']) ? (int)$_GET['fee_year'] : 0;
$road     = $_GET['road_category'] ?? '';
$from     = $_GET['from'] ?? '';
$to       = $_GET['to'] ?? '';
$q        = $_GET['q'] ?? '';
$actor    = $_GET['actor'] ?? '';
$action   = $_GET['action_filter'] ?? '';

// Reports that ignore the date range / that only make sense for certain filters.
$dateReports   = ['collection', 'audit'];
$parcelFilters = ['collection', 'arrears', 'registration', 'register', 'mof'];

$params = ['report=' . urlencode($report)];
if ($district && in_array($report, $parcelFilters, true)) { $params[] = 'district_id=' . $district; }
if ($fee_year)    { $params[] = 'fee_year=' . $fee_year; }
if ($road !== '') { $params[] = 'road_category=' . urlencode($road); }
if ($from && in_array($report, $dateReports, true)) { $params[] = 'from=' . $from; }
if ($to   && in_array($report, $dateReports, true)) { $params[] = 'to=' . $to; }
if ($q !== '' && $report === 'register')            { $params[] = 'q=' . urlencode($q); }
if ($actor !== '' && $report === 'audit')           { $params[] = 'actor=' . urlencode($actor); }
if ($action !== '' && $report === 'audit')          { $params[] = 'action=' . urlencode($action); }
$qs = implode('&', $params);

$d = api_call('GET', 'reports.php?' . $qs, null, $token);
$data = $d['ok'] ? $d['data'] : null;
$error = $d['ok'] ? null : ($d['error']['message'] ?? 'Report failed');

$districts = api_call('GET', 'districts.php')['data'] ?? [];
$years = api_call('GET', 'fee_years.php', null, $token)['data']['years'] ?? [];

$reports = [
    'collection'  => 'Collection',
    'arrears'     => 'Arrears & aging',
    'registration'=> 'Registration',
    'register'    => 'Parcel & owner register',
    'bank'        => 'Bank reconciliation',
    'rates'       => 'Rate schedule',
    'audit'       => 'Audit trail',
    'mof'         => 'MOF export (assessed/collected/outstanding)',
];
?>
<!DOCTYPE html>
<html lang="lo">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reports — Land Fee MVP</title>
<?php require __DIR__ . '/style.php'; ?>
</head>
<body>
<div class="app">
  <aside class="side">
    <div class="brand">ພະລັງງານການຊຳລະທຳນຽມ</div>
    <div class="side-nav"><?= admin_nav($role) ?></div>
    <div class="side-foot">Province: Savannakhet</div>
  </aside>
  <main class="main">
    <h1>Reports</h1>
    <p class="sub">All figures are derived from <b>payments</b> and <b>fee_obligations</b>. Export any report as CSV.</p>

    <div class="form-box">
      <form method="get" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(170px,1fr)); gap:12px;">
        <div class="form-group"><label>Report</label>
          <select name="report">
            <?php foreach ($reports as $k => $label): ?>
            <option value="<?= $k ?>" <?= $report === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if (in_array($report, $parcelFilters, true)): ?>
        <div class="form-group"><label>District</label>
          <select name="district_id"><option value="">-- all --</option>
            <?php foreach ($districts as $di): ?>
            <option value="<?= $di['id'] ?>" <?= $district == $di['id'] ? 'selected' : '' ?>><?= e($di['name_lo']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="form-group"><label>Fee year</label>
          <select name="fee_year"><option value="">-- all --</option>
            <?php foreach ($years as $y): ?>
            <option value="<?= e($y['year']) ?>" <?= $fee_year == $y['year'] ? 'selected' : '' ?>><?= e($y['year']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if (in_array($report, $parcelFilters, true) && $report !== 'register'): ?>
        <div class="form-group"><label>Road</label>
          <select name="road_category"><option value="">-- all --</option>
            <?php foreach (['main','branch','alley','none'] as $rc): ?>
            <option value="<?= $rc ?>" <?= $road === $rc ? 'selected' : '' ?>><?= e(ucfirst($rc)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <?php if ($report === 'register'): ?>
        <div class="form-group"><label>Search parcel/title</label><input name="q" value="<?= e($q) ?>"></div>
        <?php endif; ?>
        <?php if (in_array($report, $dateReports, true)): ?>
        <div class="form-group"><label>From</label><input name="from" type="date" value="<?= e($from) ?>"></div>
        <div class="form-group"><label>To</label><input name="to" type="date" value="<?= e($to) ?>"></div>
        <?php endif; ?>
        <?php if ($report === 'audit'): ?>
        <div class="form-group"><label>Actor</label><input name="actor" value="<?= e($actor) ?>" placeholder="username or id"></div>
        <div class="form-group"><label>Action</label><input name="action_filter" value="<?= e($action) ?>" placeholder="e.g. auto_match"></div>
        <?php endif; ?>
        <div class="form-group"><button class="btn btn-green" type="submit">Run</button></div>
      </form>
    </div>

    <?php if ($error): ?><p class="flash err"><?= e($error) ?></p><?php endif; ?>

    <?php if ($data): ?>
      <div class="form-box">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
          <h2 style="margin:0"><?= e($reports[$report] ?? $report) ?>
            <span class="sub" style="margin-left:8px"><?= count($data['rows']) ?> row(s)</span>
          </h2>
          <a class="btn btn-blue" href="<?= e($base) ?>/reports_export.php?<?= e($qs) ?>">Download CSV</a>
        </div>

        <?php if (!empty($data['summary'])): ?>
        <div class="cards" style="margin-top:14px">
          <?php foreach ($data['summary'] as $k => $v): ?>
            <?php if (is_array($v)) { continue; } ?>
            <div class="card"><h3><?= e(str_replace('_', ' ', $k)) ?></h3>
              <p class="num"><?= e(is_float($v) ? number_format($v, 0) : (string)$v) ?></p></div>
          <?php endforeach; ?>
        </div>
        <?php if ($report === 'registration' && !empty($data['summary']['confidence_distribution'])): ?>
        <table style="margin-top:10px">
          <thead><tr><th>OCR confidence</th><th>Scans</th></tr></thead>
          <tbody>
            <?php foreach ($data['summary']['confidence_distribution'] as $b): ?>
            <tr><td><?= e($b['bucket']) ?></td><td><?= (int)$b['scans'] ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
        <?php if ($report === 'bank' && !empty($data['summary']['by_status'])): ?>
        <table style="margin-top:10px">
          <thead><tr><th>Match status</th><th>Rows</th><th>Total (kip)</th></tr></thead>
          <tbody>
            <?php foreach ($data['summary']['by_status'] as $st => $vals): ?>
            <tr><td><?= e($st) ?></td><td><?= (int)$vals['rows'] ?></td><td><?= number_format((float)$vals['total_kip'], 0) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="form-box" style="overflow-x:auto">
        <?php if (!$data['rows']): ?>
          <p class="empty">No data for this report.</p>
        <?php else: ?>
        <table>
          <thead><tr><?php foreach ($data['columns'] as $c): ?><th><?= e($c) ?></th><?php endforeach; ?></tr></thead>
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
</body>
</html>