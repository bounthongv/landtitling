<?php
// public/admin/users.php — admin user management: staff accounts (create/edit/reset/deactivate)
// and citizen accounts (read-only, plus linking a login to an owner record).
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
// District admin/officer/manager and province admin/officer/manager can manage users.
// District admin: create users in own district; province admin: create users anywhere.
// All can view within their scope.
$allowedRoles = ['admin', 'officer', 'manager'];
if (!in_array($role, $allowedRoles, true)) { http_response_code(403); exit(t('Insufficient permissions.')); }
$token = $_SESSION['admin_token'];

$tab = $_GET['tab'] ?? 'staff';
$msg = '';
$msgType = 'ok';

// Fetch districts for dropdowns
$districts = api_call('GET', 'districts.php', null, $token)['data'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do_action'] ?? '';
    $r = ['ok' => false, 'error' => ['message' => t('Unknown action')]];

    if ($do === 'user_create') {
        $r = api_call('POST', 'users.php', ['action' => 'create',
            'username' => $_POST['username'] ?? '', 'password' => $_POST['password'] ?? '',
            'role' => $_POST['role'] ?? 'officer', 'level' => $_POST['level'] ?? 'district',
            'district_id' => (int)($_POST['district_id'] ?? 0),
            'phone' => $_POST['phone'] ?? '', 'email' => $_POST['email'] ?? ''], $token);
        $msg = !empty($r['ok']) ? t('Account created.') : ($r['error']['message'] ?? t('Create failed'));
    } elseif ($do === 'user_update') {
        $body = ['action' => 'update', 'id' => (int)($_POST['id'] ?? 0)];
        foreach (['username', 'role', 'phone', 'email', 'level'] as $f) {
            if (isset($_POST[$f])) { $body[$f] = $_POST[$f]; }
        }
        if (isset($_POST['district_id'])) { $body['district_id'] = (int)$_POST['district_id']; }
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
    <?= admin_side_top() ?>
    <?= admin_nav($role) ?>
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
        <thead><tr><th><?= te('Username') ?></th><th><?= te('Role') ?></th><th><?= te('Level') ?></th><th><?= te('District') ?></th><th><?= te('Email') ?></th><th><?= te('Phone') ?></th><th><?= te('Active') ?></th><th><?= te('Scans verified') ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($staff as $u): ?>
          <tr>
            <td><?= e($u['username']) ?></td>
            <td><?= e(t($u['role'])) ?></td>
            <td><?= e(t($u['level'] ?? 'district')) ?></td>
            <td><?php
                $dName = $u['district_name'] ?? ($u['district_id'] ?? '—');
                if (i18n_current() === 'lo' && !empty($u['district_name_lo'])) { $dName = $u['district_name_lo']; }
                echo e($dName);
            ?></td>
            <td><?= e($u['email'] ?? '—') ?></td>
            <td><?= e($u['phone']) ?></td>
            <td><?= $u['is_active'] ? '<span class="pill pill-paid">' . te('active') . '</span>' : '<span class="pill pill-rej">' . te('disabled') . '</span>' ?></td>
            <td><?= (int)$u['scans_verified'] ?></td>
            <td><button class="btn btn-blue btn-sm js-modal" type="button"
                data-form="staff-form" data-title="<?= te('Edit user') ?>" data-submit="<?= te('Update user') ?>" data-do-action="user_update"
                data-id="<?= (int)$u['id'] ?>" data-username="<?= e($u['username']) ?>"
                data-role="<?= e($u['role']) ?>" data-level="<?= e($u['level'] ?? 'district') ?>"
                data-district-id="<?= (int)($u['district_id'] ?? 0) ?>"
                data-email="<?= e($u['email'] ?? '') ?>" data-phone="<?= e($u['phone']) ?>"
                data-is-active="<?= $u['is_active'] ? '1' : '0' ?>"><?= te('Edit') ?></button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($role === 'admin'): ?>
      <div class="toolbar" style="margin-top:16px;">
        <h2><?= te('Staff accounts') ?></h2>
        <button class="btn btn-green js-modal" type="button"
          data-form="staff-form" data-title="<?= te('Add staff') ?>" data-submit="<?= te('Add staff') ?>" data-do-action="user_create"><?= te('Add staff') ?></button>
      </div>
      <?php endif; ?>
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

  <template id="staff-form">
    <form method="post" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px;">
      <input type="hidden" name="do_action">
      <input type="hidden" name="id" value="0">
      <div class="form-group"><label><?= te('Username') ?></label><input name="username" required data-readonly="username"></div>
      <div class="form-group"><label><?= te('Password') ?></label><input name="password" type="password" minlength="8" placeholder="Min 8 chars"></div>
      <div class="form-group"><label><?= te('Role') ?></label>
        <select name="role"><option value="officer"><?= te('officer') ?></option><option value="manager"><?= te('manager') ?></option><option value="admin"><?= te('admin') ?></option></select>
      </div>
      <div class="form-group"><label><?= te('Level') ?></label>
        <select name="level"><option value="district"><?= te('district') ?></option><option value="province"><?= te('province') ?></option></select>
      </div>
      <div class="form-group"><label><?= te('District') ?></label>
        <select name="district_id"><option value=""><?= te('None (province level)') ?></option>
        <?php foreach (($districts ?? []) as $d): ?>
          <option value="<?= (int)$d['id'] ?>"><?= e($d['name_lo']) ?></option>
        <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label><?= te('Email') ?></label><input name="email" type="email"></div>
      <div class="form-group"><label><?= te('Phone') ?></label><input name="phone"></div>
      <div class="form-group"><label><?= te('Active') ?></label>
        <select name="is_active"><option value="1"><?= te('yes') ?></option><option value="0"><?= te('no') ?></option></select>
      </div>
    </form>
  </template>

  <?php require __DIR__ . '/modal.php'; ?>
</body>
</html>