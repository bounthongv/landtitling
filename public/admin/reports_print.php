<?php
// public/admin/reports_print.php — standalone A4 preview of a report.
// Printing and PDF download both go through the browser's print dialog: the
// server has no PDF engine, and the bundled Lao font is what makes the report
// render correctly. "Download PDF" opens this page with auto=1, which opens the
// dialog so the officer can pick "Save as PDF" as the destination.
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }

$allowed = ['report', 'province_id', 'district_id', 'village_id', 'fee_year', 'road_category', 'from', 'to', 'q', 'actor', 'action_filter'];
$params = [];
foreach ($allowed as $k) {
    if (isset($_GET[$k]) && $_GET[$k] !== '') $params[$k] = (string)$_GET[$k];
}
if (empty($params['report'])) $params['report'] = 'collection';
$report = $params['report'];

// The period picker resolves to two dates; the print page resolves them the same
// way so a link carrying the raw picker fields still prints the right range.
$period = report_period($_GET);
if ($period['from'] !== '') { $params['from'] = $period['from']; } else { unset($params['from']); }
if ($period['to']   !== '') { $params['to']   = $period['to']; } else { unset($params['to']); }
$qs = http_build_query($params);

$d = api_call('GET', 'reports.php?' . $qs, null, $_SESSION['admin_token']);
$data = $d['ok'] ? $d['data'] : null;
$error = $d['ok'] ? null : ($d['error']['message'] ?? t('Report failed'));

$reportLabels = [
    'collection'   => t('Collection'),
    'arrears'      => t('Arrears & aging'),
    'registration' => t('Data entry progress'),
    'register'     => t('Parcel & owner register'),
    'bank'         => t('Bank reconciliation'),
    'rates'        => t('Rate schedule'),
    'audit'        => t('Audit trail'),
    'mof'          => t('MOF export (assessed/collected/outstanding)'),
];
$title = $reportLabels[$report] ?? ucfirst(str_replace('_', ' ', $report));

// The printed document is an official one, so the report name normally carries
// the "ລາຍງານ" prefix. The officer can type a heading of their own on reports.php;
// what is typed is printed exactly as typed, and only an empty box falls back to
// the generated name.
$typedTitle = isset($_GET['rtitle']) ? trim((string)$_GET['rtitle']) : '';
$heading = t('Report: {name}', ['name' => $title]);
if ($typedTitle !== '') { $heading = (string)$_GET['rtitle']; }

// The header's geo line reflects only what the officer selected: a province
// pick, a district pick, and/or a village pick. No scope fallback — a report
// filtered to one province shows just that province, even for staff whose
// session is pinned to a district.
// Names are Lao only, dropping the English that the registry stores alongside.
$provinces = api_call('GET', 'refdata.php?table=provinces', null, $_SESSION['admin_token'])['data'] ?? [];
$districts = api_call('GET', 'districts.php', null, $_SESSION['admin_token'])['data'] ?? [];
$provinceName = '';
if (isset($params['province_id']) && (int)$params['province_id'] > 0) {
    foreach ($provinces as $pv) {
        if ((int)$pv['id'] === (int)$params['province_id']) {
            $provinceName = (string)$pv['name_lo'];
            break;
        }
    }
}
$districtName = '';
$selDistrictId = (int)($params['district_id'] ?? 0);
if ($selDistrictId > 0) {
    foreach ($districts as $di) {
        if ((int)$di['id'] === $selDistrictId) {
            $districtName = (string)$di['name_lo'];
            break;
        }
    }
}

$villageName = '';
$villageId = (int)($params['village_id'] ?? 0);
if ($villageId > 0) {
    $villages = api_call('GET', 'villages.php?district_id=' . $selDistrictId, null, $_SESSION['admin_token'])['data'] ?? [];
    foreach ($villages as $vi) {
        if ((int)$vi['id'] === $villageId) { $villageName = (string)$vi['name_lo']; break; }
    }
}

$filters = [];
if ($provinceName !== '') {
    $filters[t('Province')] = $provinceName;
}
if ($districtName !== '') {
    $filters[t('District')] = $districtName;
}
if ($villageName !== '') {
    $filters[t('Village')] = $villageName;
}
if (!empty($params['fee_year']))       $filters[t('Fee year')] = $params['fee_year'];
if (!empty($params['road_category']))  $filters[t('Road')] = t(ucfirst($params['road_category']));
// No period entry here: the line above already says what the report was made
// for, and repeating the interval as two dates underneath it reads as a second
// filter rather than as the same choice.
if (!empty($params['q']))              $filters[t('Search parcel/title')] = $params['q'];
if (!empty($params['actor']))          $filters[t('Actor')] = $params['actor'];
if (!empty($params['action_filter']))  $filters[t('Action')] = $params['action_filter'];

$autoPrint = isset($_GET['auto']) && $data;

// Signature block — whatever the officer left in the four boxes on reports.php.
// All four slots always render so blanking a box leaves its position empty rather
// than letting the neighbours slide across the line. A cleared box comes back as
// sig2= (present but empty); an absent parameter falls back to the default label.
$signLabels = [];
for ($i = 1; $i <= 4; $i++) {
    $signLabels[] = isset($_GET['sig' . $i]) ? trim((string)$_GET['sig' . $i]) : t('Signature {n}', ['n' => $i]);
}
$hasSignature = count(array_filter($signLabels, fn($s) => $s !== '')) > 0;
?>
<!DOCTYPE html>
<html lang="<?= i18n_current() ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — <?= te('Reports') ?></title>
<style>
  @font-face { font-family: 'Noto Sans Lao'; font-style: normal; font-weight: 400; font-display: swap; src: url('../fonts/NotoSansLao-Regular.ttf') format('truetype'); }
  @font-face { font-family: 'Noto Sans Lao'; font-style: normal; font-weight: 700; font-display: swap; src: url('../fonts/NotoSansLao-Bold.ttf') format('truetype'); }
  * { box-sizing: border-box; }
  body { font-family: 'Phetsarath OT', 'Noto Sans Lao', 'Segoe UI', system-ui, sans-serif; background: #eef3ea; color: #1a2e1a; margin: 0; line-height: 1.45; font-size: 14px; }
  .toolbar { position: sticky; top: 0; z-index: 10; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 10px 16px; background: #144016; color: #fff; }
  .toolbar a, .toolbar button { font: inherit; font-size: 14px; padding: 7px 14px; border-radius: 5px; border: 1px solid rgba(255,255,255,.45); background: transparent; color: #fff; cursor: pointer; text-decoration: none; }
  .toolbar a:hover, .toolbar button:hover { background: rgba(255,255,255,.12); }
  .toolbar .primary { background: #f9a825; border-color: #f9a825; color: #1a2e1a; font-weight: 700; }
  .toolbar .primary:hover { background: #ffb300; }
  .toolbar .spacer { flex: 1; }
  .hint { padding: 8px 16px; background: #fff8e1; border-bottom: 1px solid #f0d98c; color: #5c4a00; font-size: 13px; }
  .doc { max-width: 1160px; margin: 16px auto; background: #fff; padding: 26px 30px; box-shadow: 0 2px 14px rgba(0,0,0,.08); }
  .doc-head { text-align: center; padding-bottom: 12px; margin-bottom: 14px; border-bottom: 2px solid #1b5e20; }
  .state-title { font-size: 20px; font-weight: 700; color: #1a2e1a; letter-spacing: .2px; }
  .state-motto { font-size: 16px; font-weight: 700; color: #1a2e1a; margin-top: 3px; }
  .doc-head .org { margin-top: 12px; }
  .doc-head .meta { justify-content: center; }
  .doc-head .filters { justify-content: center; }
  .org { font-size: 13px; color: #5f6b5f; letter-spacing: .3px; }
  h1 { margin: 2px 0 4px; font-size: 22px; color: #1b5e20; }
  .meta { color: #5f6b5f; font-size: 12.5px; display: flex; gap: 16px; flex-wrap: wrap; }
  .filters { margin-top: 10px; display: flex; gap: 6px 18px; flex-wrap: wrap; font-size: 13px; }
  .filters b { color: #1b5e20; font-weight: 700; }
  .summary { display: flex; gap: 10px; flex-wrap: wrap; margin: 18px 0 14px; }
  .sum-item { border: 1px solid #cbd5c0; border-radius: 6px; padding: 8px 12px; min-width: 140px; background: #f7faf5; }
  .sum-label { font-size: 11.5px; color: #5f6b5f; text-transform: uppercase; letter-spacing: .4px; }
  .sum-value { font-size: 18px; font-weight: 700; color: #1b5e20; }
  table { width: 100%; border-collapse: collapse; font-size: 12.5px; margin-top: 10px; }
  th, td { border: 1px solid #cbd5c0; padding: 5px 7px; text-align: left; vertical-align: top; }
  thead th { background: #e8f0ea; color: #144016; font-weight: 700; }
  tbody tr:nth-child(even) { background: #f7f9f6; }
  .empty, .err { padding: 14px; border: 1px solid #cbd5c0; border-radius: 6px; background: #f7faf5; }
  .err { border-color: #c62828; background: #fdecea; color: #8e1b1b; }
  .foot { margin-top: 16px; font-size: 11.5px; color: #5f6b5f; border-top: 1px solid #cbd5c0; padding-top: 8px; }
  .sigs { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-top: 36px; break-inside: avoid; }
  .sig { min-width: 0; text-align: center; font-size: 13px; color: #1a2e1a; border-top: 1px dotted #8a948a; padding-top: 6px; }
  .sig-blank { border-top-color: transparent; }
  @media print {
    @page { size: A4 landscape; margin: 10mm; }
    body { background: #fff; font-size: 11px; }
    .toolbar, .hint { display: none !important; }
    .doc { max-width: none; margin: 0; padding: 0; box-shadow: none; }
    .state-title { font-size: 15px; }
    .state-motto { font-size: 13px; }
    .doc-head { padding-bottom: 6px; margin-bottom: 8px; }
    .sigs { margin-top: 22px; gap: 10px; }
    .sig { font-size: 11px; }
    h1 { font-size: 16px; }
    .summary { margin: 10px 0; gap: 6px; }
    .sum-item { padding: 5px 8px; min-width: 110px; }
    .sum-value { font-size: 14px; }
    table { font-size: 10px; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
  }
</style>
</head>
<body>
<div class="toolbar">
  <a href="reports.php?<?= e($qs) ?>">← <?= te('Back to reports') ?></a>
  <span class="spacer"></span>
  <button type="button" class="primary" onclick="window.print()"><?= te('Print') ?></button>
  <button type="button" onclick="showPdfHint(); window.print()"><?= te('Download PDF') ?></button>
</div>
<div class="hint" id="pdf-hint"<?= isset($_GET['auto']) ? '' : ' style="display:none"' ?>><?= te('In the print dialog choose Save as PDF to download this report as a PDF.') ?></div>

<div class="doc">
  <div class="doc-head">
    <div class="state-title">ສາທາລະນະລັດ ປະຊາທິປະໄຕ ປະຊາຊົນລາວ</div>
    <div class="state-motto">ສັນຕິພາບ ເອກະລາດ ປະຊາທິປະໄຕ ເອກະພາບ ວັດທະນະຖາວອນ</div>
    <div class="org"><?= te('Land Tax Collection System') ?></div>
    <h1><?= e($heading) ?></h1>
    <div class="meta">
      <?php if ($period['words'] !== ''): ?><span><?= e($period['words']) ?></span><?php endif; ?>
      <?php if ($data): ?><span><?= te('{n} row(s)', ['n' => count($data['rows'])]) ?></span><?php endif; ?>
      <span><?= te('Generated {date}', ['date' => date('d/m/Y H:i')]) ?></span>
    </div>
    <?php if ($filters): ?>
    <div class="filters">
      <?php foreach ($filters as $label => $value): ?>
      <span><b><?= e($label) ?>:</b> <?= e($value) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($error): ?>
    <p class="err"><?= e($error) ?></p>
  <?php elseif ($data): ?>

    <?php if (!empty($data['summary'])): ?>
    <div class="summary">
      <?php foreach ($data['summary'] as $k => $v): ?>
        <?php if (is_array($v)) { continue; } ?>
        <div class="sum-item">
          <div class="sum-label"><?= e(t(ucfirst(str_replace('_', ' ', $k)))) ?></div>
          <div class="sum-value"><?= e(is_float($v) ? number_format($v, 0) : (string)$v) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($report === 'registration' && !empty($data['summary']['confidence_distribution'])): ?>
    <table>
      <thead><tr><th><?= te('OCR confidence') ?></th><th><?= te('Scans') ?></th></tr></thead>
      <tbody>
        <?php foreach ($data['summary']['confidence_distribution'] as $b): ?>
        <tr><td><?= e(t($b['bucket'])) ?></td><td><?= (int)$b['scans'] ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <?php if ($report === 'bank' && !empty($data['summary']['by_status'])): ?>
    <table>
      <thead><tr><th><?= te('Match status') ?></th><th><?= te('Rows') ?></th><th><?= te('Total (kip)') ?></th></tr></thead>
      <tbody>
        <?php foreach ($data['summary']['by_status'] as $st => $vals): ?>
        <tr><td><?= e(t($st)) ?></td><td><?= (int)$vals['rows'] ?></td><td><?= number_format((float)$vals['total_kip'], 0) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <?php endif; ?>

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
              // Same readability rule as the on-screen table: group digits on
              // money/count columns, but never on identifiers, years or dates.
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

    <?php if ($hasSignature): ?>
    <div class="sigs">
      <?php foreach ($signLabels as $s): ?>
      <div class="sig<?= $s === '' ? ' sig-blank' : '' ?>"><?= e($s) ?></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="foot"><?= te('Land Tax Collection System') ?> — <?= e($title) ?></div>
  <?php endif; ?>
</div>
<script>
function showPdfHint() {
  var h = document.getElementById('pdf-hint');
  if (h) h.style.display = 'block';
}
<?php if ($autoPrint): ?>
window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 350); });
<?php endif; ?>
</script>
</body>
</html>