<?php
// public/admin/agent_handovers.php — district officer reviews cash handovers
// submitted by village collection agents. Verifying marks the batch's payments
// PAID and settles their obligations; rejecting returns every payment to the
// agent's pending list. Managers see the list read-only.
require __DIR__ . '/common.php';
require_once __DIR__ . '/../../lib/fee.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
if (!in_array($role, ['admin', 'officer', 'manager'], true)) {
    http_response_code(403);
    exit(t('Insufficient permissions.'));
}
if ($is_manager && $_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(403);
    exit(t('Managers have read-only access.'));
}

$token = $_SESSION['admin_token'];
$status = $_GET['status'] ?? 'pending';
if (!in_array($status, ['pending', 'verified', 'rejected', 'all'], true)) { $status = 'pending'; }

$msg = '';
$msgType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['do_action'])) {
    $id = (int)($_POST['handover_id'] ?? 0);
    $action = $_POST['do_action'] === 'reject' ? 'reject' : 'verify';
    $body = [];
    if ($action === 'reject') {
        $reason = trim((string)($_POST['reason'] ?? ''));
        $body['reason'] = $reason !== '' ? $reason : t('Rejected by officer');
    } else {
        $notes = trim((string)($_POST['notes'] ?? ''));
        if ($notes !== '') { $body['notes'] = $notes; }
        $rejectIds = array_filter(array_map('intval', (array)($_POST['reject_payment'] ?? [])));
        if ($rejectIds) { $body['reject_payment_ids'] = array_values($rejectIds); }
    }
    $r = api_call('POST', 'officer/agent-handover.php?id=' . $id . '&action=' . $action, $body, $token);
    if (!empty($r['ok'])) {
        $msg = $action === 'reject' ? t('Handover rejected.') : t('Handover verified.');
    } else {
        $msg = $r['error']['message'] ?? t('Action failed');
        $msgType = 'err';
    }
}

$listStatus = $status === 'all' ? '' : $status;
$res = api_call('GET', 'officer/agent-handover.php' . ($listStatus !== '' ? '?status=' . urlencode($listStatus) : ''), null, $token);
$rows = $res['data']['handovers'] ?? [];
$total = (int)($res['data']['total'] ?? count($rows));
?>
<!DOCTYPE html>
<html lang="<?= i18n_current() ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= te('Agent Handovers') ?> - Land Fee MVP</title>
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
    <h1><?= te('Agent Handovers') ?></h1>
    <p class="sub"><?= te('Village agents collect land-tax cash and hand it to the district in batches. Verify a batch once the cash is counted; rejecting it returns every payment to the agent for correction.') ?></p>

    <?php if ($msg): ?><p class="flash <?= e($msgType) ?>"><?= e($msg) ?></p><?php endif; ?>

    <div class="form-box">
      <form method="get" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px;">
        <div class="form-group"><label><?= te('Status') ?></label>
          <select name="status">
            <?php foreach (['pending', 'verified', 'rejected', 'all'] as $s): ?>
              <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(t(ucfirst($s))) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>&nbsp;</label><button class="btn btn-green" type="submit"><?= te('Filter') ?></button></div>
      </form>
    </div>

    <div class="form-box">
      <h2><?= te('Batches') ?> (<?= $total ?>)</h2>
      <?php if (!$rows): ?>
        <p class="empty"><?= te('No handovers in this status.') ?></p>
      <?php else: ?>
      <table>
        <thead><tr>
          <th><?= te('Batch') ?></th><th><?= te('Agent') ?></th><th><?= te('Village') ?></th>
          <th><?= te('Payments') ?></th><th><?= te('Amount') ?></th><th><?= te('Created') ?></th>
          <th><?= te('Status') ?></th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $h): ?>
          <tr>
            <td><b><?= e($h['batch_reference']) ?></b></td>
            <td><?= e($h['agent_username']) ?><?= !empty($h['agent_role']) ? '<br><span class="sub">' . e(t(ucfirst((string)$h['agent_role']))) . '</span>' : '' ?></td>
            <td><?= e($h['village_name'] ?? '—') ?><br><span class="sub"><?= e($h['district_name'] ?? '—') ?></span></td>
            <td><?= (int)($h['payment_count'] ?? count($h['payments'] ?? [])) ?></td>
            <td><?= number_format((float)$h['amount_total']) ?> <?= te('kip') ?></td>
            <td><?= e(substr((string)$h['created_at'], 0, 16)) ?></td>
            <td><span class="pill pill-<?= e($h['status'] === 'verified' ? 'paid' : ($h['status'] === 'rejected' ? 'rej' : 'issued')) ?>"><?= e(t(ucfirst((string)$h['status']))) ?></span></td>
            <td>
              <details>
                <summary class="btn btn-blue btn-sm" style="display:inline-block;cursor:pointer;"><?= te('View') ?></summary>
                <table style="margin-top:8px;">
                  <thead><tr><th><?= te('Reference') ?></th><th><?= te('Parcel') ?></th><th><?= te('Payer') ?></th><th><?= te('Amount') ?></th><th><?= te('Status') ?></th></tr></thead>
                  <tbody>
                  <?php foreach (($h['payments'] ?? []) as $p): ?>
                    <tr>
                      <td><?= e($p['reference_no']) ?></td>
                      <td><?= e($p['parcel_no'] ?? '—') ?></td>
                      <td><?= e($p['payer_name'] ?? '—') ?></td>
                      <td><?= number_format((float)$p['amount']) ?></td>
                      <td><?= e(t((string)$p['payment_status'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </details>
              <?php if ($h['status'] === 'pending' && $role !== 'manager'): ?>
                <div style="display:flex;gap:6px;margin-top:6px;">
                  <form method="post" onsubmit="return confirm('<?= te('Verify this batch? The cash must be counted.') ?>');">
                    <input type="hidden" name="do_action" value="verify">
                    <input type="hidden" name="handover_id" value="<?= (int)$h['id'] ?>">
                    <button class="btn btn-green btn-sm" type="submit"><?= te('Verify') ?></button>
                  </form>
                  <form method="post">
                    <input type="hidden" name="do_action" value="reject">
                    <input type="hidden" name="handover_id" value="<?= (int)$h['id'] ?>">
                    <input name="reason" placeholder="<?= te('Reason') ?>" required style="max-width:150px;">
                    <button class="btn btn-red btn-sm" type="submit"><?= te('Reject') ?></button>
                  </form>
                </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </main>
  <div class="footer"></div>
</div>
</body>
</html>
