<?php
// public/admin/obligations.php — annual obligations list + arrears/aging + generation tool
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
$role = $user['role'];
$token = $_SESSION['admin_token'];

$fee_year    = isset($_GET['fee_year'])    ? (int)$_GET['fee_year']    : 0;
$district_id = isset($_GET['district_id']) ? (int)$_GET['district_id'] : 0;
$view        = $_GET['view'] ?? '';       // '' = all, 'arrears' = overdue only
$status      = $_GET['status'] ?? '';

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
$districts = api_call('GET', 'districts.php')['data'] ?? [];

$totalDue = 0.0;
$totalPaid = 0.0;
$overdueCount = 0;
foreach ($obligations as $o) {
    $totalDue += (float)$o['amount_due'];
    $totalPaid += (float)$o['amount_paid'];
    if (in_array($o['status'], ['UNPAID', 'PARTIAL'], true) && $o['days_overdue'] > 0) { $overdueCount++; }
}
$msg = $_GET['msg'] ?? '';
?>
<!DOCTYPE html>
<html lang="lo">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Annual Obligations — Land Fee MVP</title>
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
    <?php if ($msg === 'generated'): ?><p class="flash ok">Generated obligations for <?= (int)($_GET['fee_year'] ?? 0) ?> — <?= (int)($_GET['n'] ?? 0) ?> parcel(s).</p><?php endif; ?>
    <?php if ($msg === 'error'): ?><p class="flash err">Generation failed.</p><?php endif; ?>

    <h1>Annual Obligations</h1>
    <p class="sub">Fee = zone rate (district × road category) × area. One obligation per parcel per year.</p>

    <div class="form-box">
      <form method="get" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px;">
        <div class="form-group"><label>Fee year</label>
          <select name="fee_year"><option value="">-- all --</option>
            <?php foreach ($years as $y): ?>
            <option value="<?= e($y['year']) ?>" <?= $fee_year == $y['year'] ? 'selected' : '' ?>><?= e($y['year']) ?> (<?= e($y['status']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>District</label>
          <select name="district_id"><option value="">-- all --</option>
            <?php foreach ($districts as $d): ?>
            <option value="<?= $d['id'] ?>" <?= $district_id == $d['id'] ? 'selected' : '' ?>><?= e($d['name_lo']) ?><?= !empty($d['province_name']) ? ' — ' . e($d['province_name']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Status</label>
          <select name="status"><option value="">-- all --</option>
            <?php foreach (['UNPAID','PARTIAL','PAID','OVERPAID','WAIVED','DISPUTED'] as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>View</label>
          <select name="view"><option value="">All</option><option value="arrears" <?= $view === 'arrears' ? 'selected' : '' ?>>Arrears only</option></select>
        </div>
        <div class="form-group"><button class="btn btn-green" type="submit">Filter</button></div>
      </form>
    </div>

    <div class="form-box">
      <h2>Generate obligations</h2>
      <form method="post" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px;">
        <input type="hidden" name="action" value="generate">
        <div class="form-group"><label>Fee year</label>
          <select name="fee_year">
            <?php
            $thisYear = (int)date('Y');
            foreach (range($thisYear, $thisYear + 1) as $yy): ?>
            <option value="<?= $yy ?>"><?= $yy ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>District (optional)</label>
          <select name="district_id"><option value="">-- all confirmed parcels --</option>
            <?php foreach ($districts as $d): ?>
            <option value="<?= $d['id'] ?>"><?= e($d['name_lo']) ?><?= !empty($d['province_name']) ? ' — ' . e($d['province_name']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><button class="btn btn-blue" type="submit">Generate</button></div>
      </form>
      <p class="sub">Safe to re-run: existing unpaid obligations are updated to the current rate; paid ones are left alone.</p>
    </div>

    <div class="form-box">
      <h2>Summary</h2>
      <table>
        <tbody>
          <tr><th>Count</th><td><?= $count ?></td></tr>
          <tr><th>Total due</th><td><?= number_format($totalDue, 0) ?> kip</td></tr>
          <tr><th>Total paid</th><td><?= number_format($totalPaid, 0) ?> kip</td></tr>
          <tr><th>Outstanding</th><td><?= number_format($totalDue - $totalPaid, 0) ?> kip</td></tr>
          <tr><th>Overdue (unpaid &amp; past due)</th><td><?= $overdueCount ?></td></tr>
        </tbody>
      </table>
    </div>

    <?php if ($count === 0): ?>
      <p class="empty">No obligations match the filter.</p>
    <?php else: ?>
    <table style="margin-top:8px;">
      <thead><tr><th>Year</th><th>Parcel</th><th>District</th><th>Village</th><th>Rate</th><th>Area</th><th>Due</th><th>Paid</th><th>Status</th><th>Due date</th><th>Overdue days</th></tr></thead>
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
          <td><span class="pill pill-<?= e(strtolower($o['status'])) ?>"><?= e($o['status']) ?></span></td>
          <td><?= e($o['due_date']) ?></td>
          <td><?= (int)$o['days_overdue'] > 0 ? (int)$o['days_overdue'] : '' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </main>
</div>
</body>
</html>