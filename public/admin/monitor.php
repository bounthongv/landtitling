<?php
// public/admin/monitor.php — read-only monitoring overview for manager accounts.
// Managers do not enter data: this page answers "how much have we collected,
// what is still owed, which receipts wait for an officer, and how much land do
// we cover?" on a phone. Every figure is scoped server-side (dashboard.php).
require __DIR__ . '/common.php';
require_once __DIR__ . '/../../lib/fee.php';   // payment_method_label()

if (!$is_logged_in) { header('Location: login.php'); exit; }
if ($role !== 'manager') { header('Location: index.php'); exit; }

$token = $_SESSION['admin_token'];
$year = isset($_GET['fee_year']) ? (int)$_GET['fee_year'] : 0;
$districtId = isset($_GET['district_id']) ? (int)$_GET['district_id'] : 0;

$qs = [];
if ($year) { $qs['fee_year'] = $year; }
if ($districtId) { $qs['district_id'] = $districtId; }
$d = api_call('GET', 'dashboard.php' . ($qs ? '?' . http_build_query($qs) : ''), null, $token);
$data = !empty($d['ok']) ? $d['data'] : null;
$error = !empty($d['ok']) ? null : ($d['error']['message'] ?? t('Could not load the dashboard data — check the API.'));

/** Whole kip with thousands separators. */
function mon_kip($n): string { return number_format((float)$n, 0); }
/** Percentage for display and CSS widths (dot decimal, no separators). */
function mon_pct($n): string { return number_format((float)$n, 1, '.', ''); }
/** Land area: square metres, with hectares once the figure gets large. */
function mon_area($sqm): string {
    $sqm = (float)$sqm;
    $out = number_format($sqm, 0) . ' m²';
    if ($sqm >= 10000) {
        $out .= ' (' . number_format($sqm / 10000, 1) . ' ha)';
    }
    return $out;
}

$k = $data['kpis'] ?? [];
$p = $data['parcels'] ?? [];
$receipts = $data['receipts'] ?? [];
$bd = $data['breakdown'] ?? ['group' => 'district', 'rows' => [], 'total_groups' => 0];
$aging = $data['aging'] ?? [];
$recent = $data['recent'] ?? [];
$allYears = $data['all_years'] ?? [];
$selectedYear = (int)($data['fee_year'] ?? ($year ?: date('Y')));
$years = $data['years'] ?? [$selectedYear];

$scopeLabel = '';
if (!empty($data['scope']['filtered_district']['name'])) {
    $scopeLabel = t('District: {name}', ['name' => $data['scope']['filtered_district']['name']]);
} elseif (!empty($data['scope']['district_name'])) {
    $scopeLabel = (string)$data['scope']['district_name'];
} elseif (!empty($data['scope']['province_name'])) {
    $scopeLabel = (string)$data['scope']['province_name'];
}

$rate = (float)($k['collection_rate'] ?? 0);
$assessed = (float)($k['assessed_kip'] ?? 0);
$taxCollected = (float)($k['tax_collected_kip'] ?? 0);
$submitted = $receipts['SUBMITTED'] ?? ['count' => 0, 'amount_kip' => 0];
$payBase = $base . '/payments.php?tab=all';
$yearQs = '&fee_year=' . $selectedYear;
$maxAging = 0.0;
foreach ($aging as $a) { $maxAging = max($maxAging, (float)$a['outstanding_kip']); }
?>
<!DOCTYPE html>
<html lang="<?= i18n_current() ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= te('Overview') ?> — Land Fee MVP</title>
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
    <div class="toolbar monitor-head">
      <div>
        <h1><?= te('Overview') ?></h1>
        <p class="sub" style="margin-bottom:0">
          <?= te('Read-only monitoring') ?><?= $scopeLabel !== '' ? ' · ' . e($scopeLabel) : '' ?>
          <?php if ($data): ?> · <?= te('As of {date}', ['date' => e($data['as_of'])]) ?><?php endif; ?>
        </p>
      </div>
      <?php if ($data): ?>
      <form method="get" class="monitor-year">
        <?php if ($districtId): ?><input type="hidden" name="district_id" value="<?= (int)$districtId ?>"><?php endif; ?>
        <label for="monitor-year"><?= te('Fee year') ?></label>
        <select id="monitor-year" name="fee_year" onchange="this.form.submit()">
          <?php foreach ($years as $y): ?>
          <option value="<?= (int)$y ?>" <?= (int)$y === $selectedYear ? 'selected' : '' ?>><?= (int)$y ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php endif; ?>
    </div>

    <?php if ($error): ?>
      <p class="flash err"><?= e($error) ?></p>
    <?php else: ?>

    <?php if (!empty($data['scope']['filtered_district'])): ?>
    <p class="monitor-filter-chip">
      <span class="pill pill-confirmed"><?= te('District: {name}', ['name' => e($data['scope']['filtered_district']['name'])]) ?></span>
      <a href="<?= e($base) ?>/monitor.php?fee_year=<?= $selectedYear ?>"><?= te('Province overview') ?></a>
    </p>
    <?php endif; ?>

    <section class="monitor-hero">
      <div class="mh-label"><?= te('Collection progress') ?> · <?= $selectedYear ?></div>
      <div class="big"><?= mon_pct($rate) ?>%</div>
      <div class="mh-line">
        <b><?= mon_kip($taxCollected) ?></b> / <?= mon_kip($assessed) ?> <?= te('kip') ?>
        <span class="mh-sub"><?= te('Collected of assessed') ?></span>
      </div>
      <div class="mbar"><i style="width: <?= mon_pct(min(100, max(0, $rate))) ?>%"></i></div>
      <div class="mh-meta">
        <span><?= te('{paid} of {total} obligations paid', [
            'paid'  => (int)($k['paid_obligations'] ?? 0),
            'total' => (int)($k['obligations'] ?? 0),
        ]) ?></span>
        <span><?= te('Late-payment surcharge') ?>: <?= mon_kip($k['surcharge_kip'] ?? 0) ?> <?= te('kip') ?></span>
      </div>
    </section>

    <section class="kpi-grid">
      <a class="kpi<?= (int)($k['overdue_obligations'] ?? 0) > 0 ? ' alert' : '' ?>"
         href="<?= e($base) ?>/obligations.php?view=arrears<?= $yearQs ?>">
        <span class="label"><?= te('Outstanding') ?></span>
        <span class="value"><?= mon_kip($k['outstanding_kip'] ?? 0) ?> <small><?= te('kip') ?></small></span>
        <span class="hint"><?= te('{n} open obligation(s)', ['n' => (int)($k['open_obligations'] ?? 0)]) ?></span>
      </a>
      <a class="kpi<?= (int)($k['overdue_obligations'] ?? 0) > 0 ? ' alert' : '' ?>"
         href="<?= e($base) ?>/obligations.php?view=arrears<?= $yearQs ?>">
        <span class="label"><?= te('Overdue') ?></span>
        <span class="value"><?= mon_kip($k['overdue_kip'] ?? 0) ?> <small><?= te('kip') ?></small></span>
        <span class="hint"><?= te('{n} overdue obligation(s)', ['n' => (int)($k['overdue_obligations'] ?? 0)]) ?></span>
      </a>
      <a class="kpi" href="<?= e($payBase) ?>&status=SUBMITTED<?= $yearQs ?>">
        <span class="label"><?= te('Receipts to verify') ?></span>
        <span class="value"><?= (int)($submitted['count'] ?? 0) ?></span>
        <span class="hint"><?= mon_kip($submitted['amount_kip'] ?? 0) ?> <?= te('kip') ?> · <?= te('SUBMITTED') ?></span>
      </a>
      <a class="kpi" href="<?= e($base) ?>/parcels.php">
        <span class="label"><?= te('Land area') ?></span>
        <span class="value sm"><?= mon_area($p['area_sqm'] ?? 0) ?></span>
        <span class="hint"><?= te('{n} parcel(s)', ['n' => (int)($p['parcels'] ?? 0)]) ?>
          · <?= te('Confirmed') ?> <?= (int)($p['confirmed_parcels'] ?? 0) ?>,
          <?= te('Draft') ?> <?= (int)($p['draft_parcels'] ?? 0) ?></span>
      </a>
    </section>

    <section class="monitor-section">
      <h2><?= te('Receipt status') ?></h2>
      <div class="pipeline">
        <?php foreach (['ISSUED', 'SUBMITTED', 'PAID', 'REJECTED'] as $s): ?>
        <?php $r = $receipts[$s] ?? ['count' => 0, 'amount_kip' => 0]; ?>
        <a class="pc" href="<?= e($payBase) ?>&status=<?= $s ?><?= $yearQs ?>">
          <span class="pill pill-<?= strtolower($s) ?>"><?= te($s) ?></span>
          <span class="n"><?= (int)($r['count'] ?? 0) ?></span>
          <span class="hint"><?= mon_kip($r['amount_kip'] ?? 0) ?> <?= te('kip') ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="monitor-section">
      <h2><?= te($bd['group'] === 'village' ? 'By village' : 'By district') ?></h2>
      <?php if (empty($bd['rows'])): ?>
        <p class="empty"><?= te('No obligations for {year} yet.', ['year' => $selectedYear]) ?></p>
      <?php else: ?>
        <?php foreach ($bd['rows'] as $row): ?>
          <?php
            $href = $bd['group'] === 'district'
                ? $base . '/monitor.php?fee_year=' . $selectedYear . '&district_id=' . (int)$row['id']
                : '';
            $rowRate = (float)($row['rate'] ?? 0);
          ?>
          <?php if ($href): ?><a class="breakdown-row" href="<?= e($href) ?>"><?php else: ?><div class="breakdown-row"><?php endif; ?>
            <span class="name"><?= e($row['name']) ?></span>
            <span class="bar"><i style="width: <?= mon_pct(min(100, max(0, $rowRate))) ?>%"></i></span>
            <span class="nums">
              <b><?= mon_kip($row['collected_kip']) ?></b> / <?= mon_kip($row['assessed_kip']) ?> <?= te('kip') ?>
              <span class="nums-sub"><?= te('Outstanding') ?> <?= mon_kip($row['outstanding_kip']) ?></span>
            </span>
          <?php if ($href): ?></a><?php else: ?></div><?php endif; ?>
        <?php endforeach; ?>
        <?php if ((int)($bd['total_groups'] ?? 0) > count($bd['rows'])): ?>
          <p class="sub"><?= te('Showing the top {n} by outstanding.', ['n' => count($bd['rows'])]) ?></p>
        <?php endif; ?>
        <p class="link-more">
          <a href="<?= e($base) ?>/reports.php?report=collection<?= $yearQs ?>"><?= te('View full report') ?></a>
        </p>
      <?php endif; ?>
    </section>

    <section class="monitor-section">
      <h2><?= te('Arrears aging') ?> · <?= $selectedYear ?></h2>
      <?php foreach ($aging as $a): ?>
      <div class="aging-row">
        <span class="ar-label"><?= te($a['bucket'] . ' days') ?></span>
        <span class="bar"><i style="width: <?= $maxAging > 0 ? mon_pct(100 * (float)$a['outstanding_kip'] / $maxAging) : '0.0' ?>%"></i></span>
        <span class="ar-num">
          <?= mon_kip($a['outstanding_kip']) ?> <?= te('kip') ?>
          <span class="nums-sub"><?= te('{n} obligation(s)', ['n' => (int)$a['count']]) ?></span>
        </span>
      </div>
      <?php endforeach; ?>
      <p class="sub" style="margin-bottom:0">
        <?= te('Across all years') ?>: <?= te('{n} overdue obligation(s)', ['n' => (int)($allYears['overdue_obligations'] ?? 0)]) ?>,
        <?= mon_kip($allYears['outstanding_kip'] ?? 0) ?> <?= te('kip') ?> <?= te('Outstanding') ?>
      </p>
    </section>

    <section class="monitor-section">
      <h2><?= te('Recent payments') ?></h2>
      <?php if (!$recent): ?>
        <p class="empty"><?= te('No payments yet.') ?></p>
      <?php else: ?>
        <?php foreach ($recent as $r): ?>
        <div class="receipt-row">
          <div class="rr-left">
            <a href="<?= e($base) ?>/parcel.php?id=<?= (int)$r['parcel_id'] ?>"><?= e($r['parcel_no']) ?></a>
            <span class="sub"><?= e($r['village_name']) ?> · <?= e($r['district_name']) ?> · <?= e($r['reference_no']) ?></span>
            <span class="sub"><?= e(payment_method_label((string)$r['method'])) ?></span>
          </div>
          <div class="rr-right">
            <b><?= mon_kip($r['amount']) ?> <?= te('kip') ?></b>
            <span class="pill pill-<?= strtolower($r['status']) ?>"><?= te($r['status']) ?></span>
            <span class="sub"><?= e(date('d/m/Y H:i', strtotime((string)$r['at']))) ?></span>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <?php endif; ?>
  </main>
</div>
</body>
</html>
