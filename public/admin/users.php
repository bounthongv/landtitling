<?php
// public/admin/users.php — admin user management: staff accounts (create/edit/reset/deactivate)
// and citizen accounts (read-only, plus linking a login to an owner record).
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
if ($role !== 'admin') { http_response_code(403); exit(t('Admins only.')); }
$token = $_SESSION['admin_token'];

$tab = $_GET['tab'] ?? 'staff';
$msg = '';
$msgType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do_action'] ?? '';
    $r = ['ok' => false, 'error' => ['message' => t('Unknown action')]];

    if ($do === 'user_create') {
        $r = api_call('POST', 'users.php', ['action' => 'create',
            'username' => $_POST['username'] ?? '', 'password' => $_POST['password'] ?? '',
            'role' => $_POST['role'] ?? 'officer', 'phone' => $_POST['phone'] ?? '',
            'email' => $_POST['email'] ?? ''], $token);
        $msg = !empty($r['ok']) ? t('Account created.') : ($r['error']['message'] ?? t('Create failed'));
    } elseif ($do === 'user_update') {
        $body = ['action' => 'update', 'id' => (int)($_POST['id'] ?? 0)];
        foreach (['username', 'role', 'phone', 'email'] as $f) {
            if (isset($_POST[$f])) { $body[$f] = $_POST[$f]; }
        }
        if (!empty($_POST['password'])) { $body['password'] = $_POST['password']; }
        if (isset($_POST['is_active'])) { $body['is_active'] = $_POST['is_active'] === '1'; }
        $r = api_call('POST', 'users.php', $body, $token);
        $msg = !empty($r['ok']) ? t('Account updated.') : ($r['error']['message'] ?? t('Update failed'));
    } elseif ($do === 'citizen_link') {
        $r = api_call('POST', 'users.php', ['action' => 'link_owner',
            'user_id' => (int)($_POST['user_id'] ?? 0),
            'owner_id' => (int)($_POST['owner_id'] ?? 0),
            'unlink' => isset($_POST['unlink'])], $token);
        $msg = !empty($r['ok'])
            ? (!empty($_POST['unlink']) ? t('Citizen unlinked.') : t('Citizen linked to owner.'))
            : ($r['error']['message'] ?? t('Link failed'));
    }
    $msgType = !empty($r['ok']) ? 'ok' : 'err';
}

$q = trim((string)($_GET['q'] ?? ''));
$staff = $tab === 'staff'
    ? api_call('GET', 'users.php?kind=staff' . ($q !== '' ? '&q=' . urlencode($q) : ''), null, $token)['data'] ?? []
    : [];
$citizens = $tab === 'citizens'
    ? api_call('GET', 'users.php?kind=citizens' . ($q !== '' ? '&q=' . urlencode($q) : ''), null, $token)['data'] ?? []
    : [];

// Owner search for the link form
$ownerQ = trim((string)($_GET['owner_q'] ?? ''));
$candidates = [];
$linkingUser = (int)($_GET['link_user'] ?? 0);
if ($linkingUser > 0) {
    $candidates = api_call('GET', 'users.php?candidates=1' . ($ownerQ !== '' ? '&q=' . urlencode($ownerQ) : ''), null, $token)['data'] ?? [];
}
?>
<!DOCTYPE html>
<html lang="<?= i18n_current() ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= te('Users') ?> — Land Fee MVP</title>
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
    <h1><?= te('Users') ?></h1>
    <p class="sub"><?= te('Staff accounts sign in with a username and password. Citizen accounts sign in with an emailed OTP, and need their login linked to an owner record before they can see any land.') ?></p>

    <div class="form-box" style="padding:0;">
      <table><tbody><tr>
        <td style="border:none;padding:0"><a class="btn <?= $tab === 'staff' ? 'btn-green' : '' ?> btn-sm" href="?tab=staff"><?= te('Staff') ?></a></td>
        <td style="border:none;padding:0"><a class="btn <?= $tab === 'citizens' ? 'btn-green' : '' ?> btn-sm" href="?tab=citizens"><?= te('Citizens') ?></a></td>
      </tr></tbody></table>
    </div>

    <?php if ($msg): ?><p class="flash <?= e($msgType) ?>"><?= e($msg) ?></p><?php endif; ?>

    <div class="form-box">
      <form method="get" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px;">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <div class="form-group"><label><?= te('Search') ?></label><input name="q" value="<?= e($q) ?>"></div>
        <div class="form-group"><label>&nbsp;</label><button class="btn btn-green" type="submit"><?= te('Filter') ?></button></div>
      </form>
    </div>

<?php if ($tab === 'staff'): ?>
    <div class="form-box">
      <h2><?= te('Staff accounts') ?></h2>
      <table style="margin-bottom:16px;">
        <thead><tr><th><?= te('Username') ?></th><th><?= te('Role') ?></th><th><?= te('Email') ?></th><th><?= te('Phone') ?></th><th><?= te('Active') ?></th><th><?= te('Scans verified') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($staff as $u): ?>
          <tr>
            <td><?= e($u['username']) ?></td>
            <td><?= e(t($u['role'])) ?></td>
            <td><?= e($u['email'] ?? '—') ?></td>
            <td><?= e($u['phone']) ?></td>
            <td><?= $u['is_active'] ? '<span class="pill pill-paid">' . te('active') . '</span>' : '<span class="pill pill-rej">' . te('disabled') . '</span>' ?></td>
            <td><?= (int)$u['scans_verified'] ?></td>
            <td><a href="?tab=staff&edit=<?= (int)$u['id'] ?>"><?= te('Edit') ?></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php
      $editId = (int)($_GET['edit'] ?? 0);
      $edit = null;
      if ($editId) {
          foreach ($staff as $u) { if ((int)$u['id'] === $editId) { $edit = $u; break; } }
      }
      if ($edit): ?>
        <h3><?= te('Edit: {username}', ['username' => e($edit['username'])]) ?></h3>
        <form method="post" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:12px;">
          <input type="hidden" name="do_action" value="user_update"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
          <div class="form-group"><label><?= te('Username') ?></label><input name="username" value="<?= e($edit['username']) ?>"></div>
          <div class="form-group"><label><?= te('Role') ?></label>
            <select name="role"><option value="officer" <?= $edit['role'] === 'officer' ? 'selected' : '' ?>><?= te('officer') ?></option>
            <option value="admin" <?= $edit['role'] === 'admin' ? 'selected' : '' ?>><?= te('admin') ?></option></select>
          </div>
          <div class="form-group"><label><?= te('Email') ?></label><input name="email" value="<?= e($edit['email'] ?? '') ?>"></div>
          <div class="form-group"><label><?= te('Phone') ?></label><input name="phone" value="<?= e($edit['phone']) ?>"></div>
          <div class="form-group"><label><?= te('New password (blank = keep)') ?></label><input name="password" type="password" minlength="8"></div>
          <div class="form-group"><label><?= te('Active') ?></label>
            <select name="is_active"><option value="1" <?= $edit['is_active'] ? 'selected' : '' ?>><?= te('yes') ?></option>
            <option value="0" <?= !$edit['is_active'] ? 'selected' : '' ?>><?= te('no') ?></option></select>
          </div>
          <div class="form-group"><label>&nbsp;</label><button class="btn btn-green" type="submit"><?= te('Save') ?></button></div>
        </form>
      <?php endif; ?>

      <h3 style="margin-top:18px;"><?= te('New staff account') ?></h3>
      <form method="post" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:12px;">
        <input type="hidden" name="do_action" value="user_create">
        <div class="form-group"><label><?= te('Username') ?></label><input name="username" required></div>
        <div class="form-group"><label><?= te('Password') ?></label><input name="password" type="password" minlength="8" required></div>
        <div class="form-group"><label><?= te('Role') ?></label>
          <select name="role"><option value="officer"><?= te('officer') ?></option><option value="admin"><?= te('admin') ?></option></select>
        </div>
        <div class="form-group"><label><?= te('Email') ?></label><input name="email" type="email"></div>
        <div class="form-group"><label><?= te('Phone') ?></label><input name="phone" placeholder="+856..."></div>
        <div class="form-group"><label>&nbsp;</label><button class="btn btn-green" type="submit"><?= te('Create') ?></button></div>
      </form>
    </div>

<?php else: ?>
    <div class="form-box">
      <h2><?= te('Citizen accounts') ?></h2>
      <p class="sub"><?= te('Created automatically the first time someone requests a code with an email address. A citizen only sees land once a login is linked to an owner record.') ?></p>
      <table>
        <thead><tr><th>ID</th><th><?= te('Email') ?></th><th><?= te('Phone') ?></th><th><?= te('Active') ?></th><th><?= te('Owners linked') ?></th><th><?= te('Parcels') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($citizens as $c): ?>
          <tr>
            <td><?= (int)$c['id'] ?></td>
            <td><?= e($c['email'] ?? '—') ?></td>
            <td><?= e($c['phone']) ?></td>
            <td><?= $c['is_active'] ? te('yes') : te('no') ?></td>
            <td><?= (int)$c['linked_owners'] ?></td>
            <td><?= (int)$c['linked_parcels'] ?></td>
            <td><a href="?tab=citizens&link_user=<?= (int)$c['id'] ?>"><?= te('Link owner') ?></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($linkingUser > 0): ?>
    <div class="form-box">
      <h2><?= te('Link citizen #{id} to an owner', ['id' => (int)$linkingUser]) ?></h2>
      <form method="get" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:12px; margin-bottom:12px;">
        <input type="hidden" name="tab" value="citizens"><input type="hidden" name="link_user" value="<?= (int)$linkingUser ?>">
        <div class="form-group"><label><?= te('Search owners') ?></label><input name="owner_q" value="<?= e($ownerQ) ?>"></div>
        <div class="form-group"><label>&nbsp;</label><button class="btn btn-green" type="submit"><?= te('Search') ?></button></div>
      </form>
      <table>
        <thead><tr><th><?= te('Owner') ?></th><th><?= te('ID no') ?></th><th><?= te('Parcels') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($candidates as $o): ?>
          <tr>
            <td><?= e($o['full_name']) ?></td>
            <td><?= e($o['id_number'] ?? '—') ?></td>
            <td><?= e($o['parcels'] ?? '—') ?></td>
            <td>
              <form method="post" style="display:inline">
                <input type="hidden" name="do_action" value="citizen_link">
                <input type="hidden" name="user_id" value="<?= (int)$linkingUser ?>">
                <input type="hidden" name="owner_id" value="<?= (int)$o['id'] ?>">
                <button class="btn btn-green btn-sm" type="submit"><?= te('Link') ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <form method="post" style="margin-top:12px;">
        <input type="hidden" name="do_action" value="citizen_link">
        <input type="hidden" name="user_id" value="<?= (int)$linkingUser ?>">
        <input type="hidden" name="unlink" value="1">
        <button class="btn btn-red btn-sm" type="submit"><?= te('Unlink all owners from this citizen') ?></button>
      </form>
    </div>
    <?php endif; ?>
<?php endif; ?>

  </main>
</div>
</body>
</html>