<?php
// public/admin/reports.php — MOF fee reports (CSV export)
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
$role = $user['role'];

$district_id = isset($_GET['district_id']) ? (int)$_GET['district_id'] : 0;
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$format = $_GET['format'] ?? 'csv';

$d = ['ok' => true, 'data' => []];
$params = '';
if ($district_id) { $params .= ($params ? '&' : '') . 'district_id=' . $district_id; }
if ($from) { $params .= ($params ? '&' : '') . 'from=' . $from; }
if ($to) { $params .= ($params ? '&' : '') . 'to=' . $to; }
if ($format) { $params .= ($params ? '&' : '') . 'format=' . $format; }

$d = api_call('GET', 'reports.php' . ($params ? '?' . $params : ''));

$districts = [];
$districts = api_call('GET', 'districts.php')['data'] ?? [];
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
    <h1>Fee Reports (MOF)</h1>
    <p class="sub">Download CSV for provincial fee reconciliation.</p>

    <div class="form-box">
      <form method="get" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px;">
        <div class="form-group"><label>District</label><select name="district_id"><option value="">-- all --</option><?php foreach ($districts as $d): ?><option value="<?= $d['id'] ?>" <?= $district_id == $d['id'] ? 'selected' : '' ?>><?= e($d['name_lo']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label>From</label><input name="from" type="date" value="<?= e($from) ?>"></div>
        <div class="form-group"><label>To</label><input name="to" type="date" value="<?= e($to) ?>"></div>
        <div class="form-group"><label>Format</label>
          <select name="format"><option value="csv" <?= $format === 'csv' ? 'selected' : '' ?>>CSV</option><option value="json" <?= $format === 'json' ? 'selected' : '' ?>>JSON</option></select></div>
        <div class="form-group"><button class="btn btn-green" type="submit">Download</button></div>
      </form>
    </div>

    <?php if (!empty($d['ok']) && !empty($d['data'])): ?>
    <?php if ($format === 'csv'): ?>
      <p class="sub" id="report-desc">Downloading CSV report for <?= $params ?>.</p>
      <?php
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="landfee-report.csv"');
        $out = fopen('php://output', 'w');
        if (empty($d['data']['rows'])) {
            fputcsv($out, ['district', 'pay_date', 'transaction_type', 'road_category', 'count', 'total_kips']);
        } else {
            foreach ($d['data']['rows'] as $r) {
                fputcsv($out, [$r['district'] ?? '', $r['pay_date'] ?? '', $r['transaction_type'] ?? '', $r['road_category'] ?? '', $r['n'] ?? '', $r['total'] ?? '']);
            }
        }
        fclose($out);
        exit;
      ?>
    <?php else: ?>
    <div class="grid">
      <div class="card"><h3>Summary</h3><p class="sub">Payments: <?= e($d['data']['summary']['payments'] ?? '0') ?> &nbsp;|&nbsp; Total: <?= number_format((float)($d['data']['summary']['total_kips'] ?? 0), 0) ?> kip</p></div>
      <div class="card" style="overflow-x:auto;">
        <table>
          <thead><tr><th>District</th><th>Pay date</th><th>Trans type</th><th>Road</th><th>Count</th><th>Total kips</th></tr></thead>
          <tbody>
            <?php foreach ($d['data']['rows'] as $r): ?>
            <tr>
              <td><?= e($r['district'] ?? '') ?></td>
              <td><?= e($r['pay_date'] ?? '') ?></td>
              <td><?= e($r['transaction_type'] ?? '') ?></td>
              <td><?= e($r['road_category'] ?? '') ?></td>
              <td><?= e($r['n'] ?? '') ?></td>
              <td style="font-weight:600;"><?= number_format((float)($r['total'] ?? 0), 0) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
