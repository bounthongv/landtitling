<?php
// public/admin/parcel.php — parcel detail with annual obligation history
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
$role = $user['role'];
$token = $_SESSION['admin_token'];

$parcel_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($parcel_id <= 0) { header('Location: parcels.php'); exit; }

$resp = api_call('GET', 'parcels.php?id=' . $parcel_id, null, $token);
$parcel = ($resp['data']['parcels'][0] ?? null);

// Obligation history for this parcel
$oblResp = api_call('GET', 'fee_obligations.php?parcel_id=' . $parcel_id, null, $token);
$obligations = $oblResp['data']['obligations'] ?? [];

// Owners
$ownResp = api_call('GET', 'owners.php?parcel_id=' . $parcel_id, null, $token);
$owners = $ownResp['data']['owners'] ?? [];

$flash = $_GET['msg'] ?? '';
$thisYear = (int)date('Y');

// Officer recompute of the current-year obligation (POST from the button)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'recompute') {
    $year = (int)($_POST['fee_year'] ?? $thisYear);
    $r = api_call('POST', 'fee_obligations.php', ['action' => 'recompute', 'parcel_id' => $parcel_id, 'fee_year' => $year], $token);
    if (!empty($r['ok'])) {
        header('Location: parcel.php?id=' . $parcel_id . '&msg=recomputed');
    } else {
        header('Location: parcel.php?id=' . $parcel_id . '&msg=error');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?= i18n_current() ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= te('Parcel') ?> <?= e($parcel['parcel_no'] ?? '') ?> — Land Fee MVP</title>
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
    <?php if ($flash === 'recomputed'): ?><p class="flash ok"><?= te('Current-year obligation recomputed.') ?></p><?php endif; ?>
    <?php if ($flash === 'error'): ?><p class="flash err"><?= te('Recompute failed.') ?></p><?php endif; ?>

    <?php if (!$parcel): ?>
      <p class="empty"><?= te('Parcel not found.') ?></p>
    <?php else: ?>
    <h1><?= te('Parcel') ?> <?= e($parcel['parcel_no']) ?><?= $parcel['sub_parcel_no'] ? ' / ' . e($parcel['sub_parcel_no']) : '' ?></h1>
    <p class="sub"><?= te('Annual fee obligations — fee = zone rate × area (location-based).') ?></p>

    <div class="form-box">
      <h2><?= te('Title') ?></h2>
      <table>
        <tbody>
          <tr><th><?= te('District') ?></th><td><?= e($parcel['district_name']) ?></td></tr>
          <tr><th><?= te('Village') ?></th><td><?= e($parcel['village_name']) ?></td></tr>
          <tr><th><?= te('Area (m²)') ?></th><td><?= number_format((float)$parcel['area_sqm'], 2) ?></td></tr>
          <tr><th><?= te('Road category') ?></th><td><?= e(t(ucfirst($parcel['road_category'] ?? '—'))) ?></td></tr>
          <tr><th><?= te('Title no') ?></th><td><?= e($parcel['title_no'] ?? '—') ?></td></tr>
          <tr><th><?= te('Title type') ?></th><td><?= e(t($parcel['title_type'] ?? '—')) ?></td></tr>
          <tr><th><?= te('Book / Volume / Page') ?></th><td><?= e($parcel['book_no'] ?? '—') ?> / <?= e($parcel['volume_no'] ?? '—') ?> / <?= e($parcel['page_no'] ?? '—') ?></td></tr>
          <tr><th><?= te('Land use') ?></th><td><?= e(t($parcel['land_use_class'] ?? '—')) ?></td></tr>
          <tr><th><?= te('Issued date') ?></th><td><?= e($parcel['issued_date'] ?? '—') ?></td></tr>
          <tr><th><?= te('Status') ?></th><td><span class="pill pill-<?= e($parcel['status']) ?>"><?= e(t(ucfirst($parcel['status']))) ?></span></td></tr>
        </tbody>
      </table>
    </div>

    <div class="form-box">
      <h2><?= te('Owners') ?></h2>
      <?php if (!$owners): ?><p class="empty"><?= te('No owners recorded.') ?></p><?php else: ?>
      <table>
        <thead><tr><th><?= te('Name') ?></th><th><?= te('ID number') ?></th><th><?= te('Role') ?></th><th><?= te('Address') ?></th></tr></thead>
        <tbody>
        <?php foreach ($owners as $o): ?>
          <tr><td><?= e($o['full_name']) ?></td><td><?= e($o['id_number'] ?? '—') ?></td><td><?= e(t($o['role'])) ?></td><td><?= e($o['address'] ?? '—') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <div class="form-box">
      <h2><?= te('Obligation history') ?></h2>
      <?php if (!$obligations): ?>
        <p class="empty"><?= te('No obligations yet.') ?></p>
        <form method="post">
          <input type="hidden" name="action" value="recompute">
          <input type="hidden" name="fee_year" value="<?= $thisYear ?>">
          <button class="btn btn-green" type="submit"><?= te('Assess {year} obligation', ['year' => $thisYear]) ?></button>
        </form>
      <?php else: ?>
      <table>
        <thead><tr><th><?= te('Year') ?></th><th><?= te('Rate (kip/m²)') ?></th><th><?= te('Area (m²)') ?></th><th><?= te('Due') ?></th><th><?= te('Paid') ?></th><th><?= te('Penalty') ?></th><th><?= te('Status') ?></th><th><?= te('Due date') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($obligations as $o): ?>
          <tr>
            <td><?= e($o['fee_year']) ?></td>
            <td><?= number_format((float)$o['rate_per_sqm'], 0) ?></td>
            <td><?= number_format((float)$o['area_sqm'], 2) ?></td>
            <td><strong><?= number_format((float)$o['amount_due'], 0) ?></strong></td>
            <td><?= number_format((float)$o['amount_paid'], 0) ?></td>
            <td><?= number_format((float)$o['penalty'], 0) ?></td>
            <td><span class="pill pill-<?= e(strtolower($o['status'])) ?>"><?= e(t($o['status'])) ?></span></td>
            <td><?= e($o['due_date']) ?></td>
            <td>
              <?php if ((float)$o['amount_paid'] <= 0): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="recompute">
                <input type="hidden" name="fee_year" value="<?= e($o['fee_year']) ?>">
                <button class="btn btn-sm" type="submit"><?= te('Recompute') ?></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
    <?php if (in_array($parcel['status'] ?? '', ['draft', 'confirmed'], true)): ?>
    <div class="form-actions" style="margin-top:16px;">
      <a href="<?= $base ?>/parcel_entry.php?id=<?= (int)$parcel['id'] ?>" class="btn btn-gold"><?= te('Edit parcel') ?></a>
    </div>
    <?php endif; ?>
    <p><a href="<?= $base ?>/parcels.php">&larr; <?= te('Back to parcels') ?></a></p>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
# OPcache clear trigger
