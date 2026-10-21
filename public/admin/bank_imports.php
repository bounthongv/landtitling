<?php
// public/admin/bank_imports.php — import bank statements, review match results, override
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
$role = $user['role'];
$token = $_SESSION['admin_token'];

$import_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$msg = '';
$msgType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do_action'] ?? '';

    if ($do === 'import') {
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $msg = t('Choose a CSV file first.'); $msgType = 'err';
        } else {
            $f = $_FILES['file'];
            $fields = ['date_col','amount_col','reference_col','bank_ref_col','no_header'];
            $r = api_upload_multipart('bank_imports.php', $f, $fields, $_POST, $token, 'file');
            if (!empty($r['ok'])) {
                $s = $r['data']['summary'];
                $msg = t('Imported {rows} row(s): {matched} matched, {overpaid} overpaid, {partial} partial, {duplicate} duplicate, {unmatched} unmatched.', [
                    'rows' => $r['data']['rows'], 'matched' => $s['matched'], 'overpaid' => $s['overpaid'],
                    'partial' => $s['partial'], 'duplicate' => $s['duplicate'], 'unmatched' => $s['unmatched'],
                ]);
                $import_id = (int)$r['data']['import_id'];
            } else {
                $msg = $r['error']['message'] ?? t('Import failed'); $msgType = 'err';
            }
        }
    }

    if ($do === 'rematch') {
        $r = api_call('POST', 'bank_imports.php?id=' . (int)($_POST['import_id'] ?? 0) . '&rematch=1', [], $token);
        if (!empty($r['ok'])) {
            $s = $r['data']['summary'];
            $msg = t('Re-matched: {matched} matched, {overpaid} overpaid, {partial} partial, {duplicate} duplicate, {unmatched} unmatched.', [
                'matched' => $s['matched'], 'overpaid' => $s['overpaid'], 'partial' => $s['partial'],
                'duplicate' => $s['duplicate'], 'unmatched' => $s['unmatched'],
            ]);
            $import_id = (int)($_POST['import_id'] ?? 0);
        } else {
            $msg = $r['error']['message'] ?? t('Re-match failed'); $msgType = 'err';
        }
    }

    if ($do === 'apply' || $do === 'ignore') {
        $txn = (int)($_POST['txn_id'] ?? 0);
        $body = ['action' => $do];
        if ($do === 'apply') { $body['payment_id'] = (int)($_POST['payment_id'] ?? 0); }
        $r = api_call('POST', 'bank_imports.php?txn_id=' . $txn, $body, $token);
        if (!empty($r['ok'])) {
            $msg = $do === 'apply' ? t('Transaction applied to payment.') : t('Transaction ignored.');
            $import_id = (int)($_POST['import_id'] ?? 0);
        } else {
            $msg = $r['error']['message'] ?? t('Action failed'); $msgType = 'err';
        }
    }
}

$imports = api_call('GET', 'bank_imports.php', null, $token)['data']['imports'] ?? [];

$detail = null;
$txns = [];
if ($import_id) {
    $d = api_call('GET', 'bank_imports.php?id=' . $import_id, null, $token);
    if (!empty($d['ok'])) {
        $detail = $d['data']['import'];
        $txns = $d['data']['transactions'];
    }
}
?>
<!DOCTYPE html>
<html lang="<?= i18n_current() ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= te('Bank Import') ?> — Land Fee MVP</title>
<?php require __DIR__ . '/style.php'; ?>
</head>
<body>
<div class="app">
  <aside class="side">
    <div class="brand"><?= te("Land Tax Collection System") ?></div>
    <div class="side-nav"><?= admin_nav($role) ?></div>
    <?= admin_side_foot() ?>
  </aside>
  <main class="main">
    <h1><?= te('Bank Statement Import') ?></h1>
    <p class="sub"><?= te('Import a statement; rows are matched to payments by the LFE-YYYY-NNNNNN reference. Exact and over amounts settle automatically; partial, duplicate and unknown rows stay for you.') ?></p>

    <?php if ($msg): ?><p class="flash <?= $msgType === 'err' ? 'err' : 'ok' ?>"><?= e($msg) ?></p><?php endif; ?>

    <div class="form-box">
      <h2><?= te('Upload statement (CSV)') ?></h2>
      <form method="post" enctype="multipart/form-data" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px;">
        <input type="hidden" name="do_action" value="import">
        <div class="form-group"><label><?= te('CSV file') ?></label><input type="file" name="file" accept=".csv,text/csv" required></div>
        <div class="form-group"><label><?= te('Date col #') ?></label><input name="date_col" type="number" min="0" placeholder="<?= te('auto') ?>"></div>
        <div class="form-group"><label><?= te('Amount col #') ?></label><input name="amount_col" type="number" min="0" placeholder="<?= te('auto') ?>"></div>
        <div class="form-group"><label><?= te('Reference col #') ?></label><input name="reference_col" type="number" min="0" placeholder="<?= te('auto') ?>"></div>
        <div class="form-group"><label><?= te('Bank ref col #') ?></label><input name="bank_ref_col" type="number" min="0" placeholder="<?= te('auto') ?>"></div>
        <div class="form-group"><label><?= te('Header row') ?></label>
          <select name="no_header"><option value=""><?= te('Has header row') ?></option><option value="1"><?= te('No header row') ?></option></select>
        </div>
        <div class="form-group"><button class="btn btn-blue" type="submit"><?= te('Import & match') ?></button></div>
      </form>
      <p class="sub"><?= te('Leave column numbers blank to auto-detect from the header (Date, Amount, Reference, Bank Ref).') ?></p>
    </div>

    <div class="form-box">
      <h2><?= te('Imports') ?></h2>
      <?php if (!$imports): ?><p class="empty"><?= te('No imports yet.') ?></p><?php else: ?>
      <table>
        <thead><tr><th><?= te('File') ?></th><th><?= te('Imported') ?></th><th><?= te('By') ?></th><th><?= te('Rows') ?></th><th><?= te('Matched') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($imports as $im): ?>
          <tr>
            <td><a href="?id=<?= (int)$im['id'] ?>"><?= e($im['filename']) ?></a></td>
            <td><?= e($im['imported_at']) ?></td>
            <td><?= e($im['imported_by_name'] ?? '—') ?></td>
            <td><?= (int)$im['row_count'] ?></td>
            <td><?= (int)$im['matched_count'] ?></td>
            <td>
              <form method="post" style="display:inline">
                <input type="hidden" name="do_action" value="rematch">
                <input type="hidden" name="import_id" value="<?= (int)$im['id'] ?>">
                <button class="btn btn-sm" type="submit"><?= te('Re-match') ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <?php if ($detail): ?>
    <div class="form-box">
      <h2><?= te('Transactions') ?> — <?= e($detail['filename']) ?></h2>
      <?php if (!$txns): ?><p class="empty"><?= te('No transactions.') ?></p><?php else: ?>
      <table>
        <thead><tr><th><?= te('Date') ?></th><th><?= te('Amount') ?></th><th><?= te('Reference') ?></th><th><?= te('Bank ref') ?></th><th><?= te('Status') ?></th><th><?= te('Payment') ?></th><th><?= te('Parcel') ?></th><th><?= te('Actions') ?></th></tr></thead>
        <tbody>
        <?php foreach ($txns as $t): ?>
          <tr>
            <td><?= e($t['txn_date'] ?? '—') ?></td>
            <td><?= number_format((float)$t['amount'], 0) ?></td>
            <td><?= e($t['reference_no'] ?? '—') ?></td>
            <td><?= e($t['bank_ref'] ?? '—') ?></td>
            <td><span class="pill pill-<?= e($t['match_status'] === 'matched' ? 'paid' : ($t['match_status'] === 'ignored' ? 'waived' : 'pending')) ?>"><?= e(t($t['match_status'])) ?></span></td>
            <td><?= $t['pay_ref'] ? e($t['pay_ref']) : '—' ?></td>
            <td><?= $t['parcel_no'] ? e($t['parcel_no']) : '—' ?></td>
            <td>
              <?php if (in_array($t['match_status'], ['partial','unmatched'], true) && !empty($t['matched_payment_id'])): ?>
                <form method="post" style="display:inline">
                  <input type="hidden" name="do_action" value="apply">
                  <input type="hidden" name="txn_id" value="<?= (int)$t['id'] ?>">
                  <input type="hidden" name="payment_id" value="<?= (int)$t['matched_payment_id'] ?>">
                  <input type="hidden" name="import_id" value="<?= (int)$import_id ?>">
                  <button class="btn btn-green btn-sm" type="submit"><?= te('Apply') ?></button>
                </form>
              <?php endif; ?>
              <?php if ($t['match_status'] !== 'ignored' && $t['match_status'] !== 'matched'): ?>
                <form method="post" style="display:inline">
                  <input type="hidden" name="do_action" value="ignore">
                  <input type="hidden" name="txn_id" value="<?= (int)$t['id'] ?>">
                  <input type="hidden" name="import_id" value="<?= (int)$import_id ?>">
                  <button class="btn btn-sm" type="submit"><?= te('Ignore') ?></button>
                </form>
              <?php endif; ?>
            </td>
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