<?php
// public/admin/users.php — admin user management: staff accounts (create/edit/reset/deactivate)
// and citizen accounts (read-only, plus linking a login to an owner record).
require __DIR__ . '/common.php';
if (!$is_logged_in) { header('Location: login.php'); exit; }
if ($role !== 'admin') { http_response_code(403); exit('Admins only.'); }
$token = $_SESSION['admin_token'];

$tab = $_GET['tab'] ?? 'staff';
$msg = '';
$msgType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do_action'] ?? '';
    $r = ['ok' => false, 'error' => ['message' => 'Unknown action']];

    if ($do === 'user_create') {
        $r = api_call('POST', 'users.php', ['action' => 'create',
            'username' => $_POST['username'] ?? '', 'password' => $_POST['password'] ?? '',
            'role' => $_POST['role'] ?? 'officer', 'phone' => $_POST['phone'] ?? '',
            'email' => $_POST['email'] ?? ''], $token);
        $msg = !empty($r['ok']) ? 'Account created.' : ($r['error']['message'] ?? 'Create failed');
    } elseif ($do === 'user_update') {
        $body = ['action' => 'update', 'id' => (int)($_POST['id'] ?? 0)];
        foreach (['username', 'role', 'phone', 'email'] as $f) {
            if (isset($_POST[$f])) { $body[$f] = $_POST[$f]; }
        }
        if (!empty($_POST['password'])) { $body['password'] = $_POST['password']; }
        if (isset($_POST['is_active'])) { $body['is_active'] = $_POST['is_active'] === '1'; }
        $r = api_call('POST', 'users.php', $body, $token);
        $msg = !empty($r['ok']) ? 'Account updated.' : ($r['error']['message'] ?? 'Update failed');
    } elseif ($do === 'citizen_link') {
        $r = api_call('POST', 'users.php', ['action' => 'link_owner',
            'user_id' => (int)($_POST['user_id'] ?? 0),
            'owner_id' => (int)($_POST['owner_id'] ?? 0),
            'unlink' => isset($_POST['unlink'])], $token);
        $msg = !empty($r['ok'])
            ? (!empty($_POST['unlink']) ? 'Citizen unlinked.' : 'Citizen linked to owner.')
            : ($r['error']['message'] ?? 'Link failed');
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
<html lang="lo">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Users — Land Fee MVP</title>
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
    <h1>Users</h1>
    <p class="sub">Staff accounts sign in with a username and password. Citizen accounts sign in with an
      emailed OTP, and need their login linked to an owner record before they can see any land.</p>

    <div class="form-box" style="padding:0;">
      <table><tbody><tr>
        <td style="border:none;padding:0"><a class="btn <?= $tab === 'staff' ? 'btn-green' : '' ?> btn-sm" href="?tab=staff">Staff</a></td>
        <td style="border:none;padding:0"><a class="btn <?= $tab === 'citizens' ? 'btn-green' : '' ?> btn-sm" href="?tab=citizens">Citizens</a></td>
      </tr></tbody></table>
    </div>

    <?php if ($msg): ?><p class="flash <?= e($msgType) ?>"><?= e($msg) ?></p><?php endif; ?>

    <div class="form-box">
      <form method="get" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr)); gap:12px;">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <div class="form-group"><label>Search</label><input name="q" value="<?= e($q) ?>"></div>
        <div class="form-group"><label>&nbsp;</label><button class="btn btn-green" type="submit">Filter</button></div>
      </form>
    </div>

<?php if ($tab === 'staff'): ?>
    <div class="form-box">
      <h2>Staff accounts</h2>
      <table style="margin-bottom:16px;">
        <thead><tr><th>Username</th><th>Role</th><th>Email</th><th>Phone</th><th>Active</th><th>Scans verified</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($staff as $u): ?>
          <tr>
            <td><?= e($u['username']) ?></td>
            <td><?= e($u['role']) ?></td>
            <td><?= e($u['email'] ?? '—') ?></td>
            <td><?= e($u['phone']) ?></td>
            <td><?= $u['is_active'] ? '<span class="pill pill-paid">active</span>' : '<span class="pill pill-rej">disabled</span>' ?></td>
            <td><?= (int)$u['scans_verified'] ?></td>
            <td><a href="?tab=staff&edit=<?= (int)$u['id'] ?>">Edit</a></td>
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
        <h3>Edit: <?= e($edit['username']) ?></h3>
        <form method="post" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:12px;">
          <input type="hidden" name="do_action" value="user_update"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
          <div class="form-group"><label>Username</label><input name="username" value="<?= e($edit['username']) ?>"></div>
          <div class="form-group"><label>Role</label>
            <select name="role"><option value="officer" <?= $edit['role'] === 'officer' ? 'selected' : '' ?>>officer</option>
            <option value="admin" <?= $edit['role'] === 'admin' ? 'selected' : '' ?>>admin</option></select>
          </div>
          <div class="form-group"><label>Email</label><input name="email" value="<?= e($edit['email'] ?? '') ?>"></div>
          <div class="form-group"><label>Phone</label><input name="phone" value="<?= e($edit['phone']) ?>"></div>
          <div class="form-group"><label>New password (blank = keep)</label><input name="password" type="password" minlength="8"></div>
          <div class="form-group"><label>Active</label>
            <select name="is_active"><option value="1" <?= $edit['is_active'] ? 'selected' : '' ?>>yes</option>
            <option value="0" <?= !$edit['is_active'] ? 'selected' : '' ?>>no</option></select>
          </div>
          <div class="form-group"><label>&nbsp;</label><button class="btn btn-green" type="submit">Save</button></div>
        </form>
      <?php endif; ?>

      <h3 style="margin-top:18px;">New staff account</h3>
      <form method="post" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:12px;">
        <input type="hidden" name="do_action" value="user_create">
        <div class="form-group"><label>Username</label><input name="username" required></div>
        <div class="form-group"><label>Password</label><input name="password" type="password" minlength="8" required></div>
        <div class="form-group"><label>Role</label>
          <select name="role"><option value="officer">officer</option><option value="admin">admin</option></select>
        </div>
        <div class="form-group"><label>Email</label><input name="email" type="email"></div>
        <div class="form-group"><label>Phone</label><input name="phone" placeholder="+856..."></div>
        <div class="form-group"><label>&nbsp;</label><button class="btn btn-green" type="submit">Create</button></div>
      </form>
    </div>

<?php else: ?>
    <div class="form-box">
      <h2>Citizen accounts</h2>
      <p class="sub">Created automatically the first time someone requests a code with an email address.
        A citizen only sees land once a login is linked to an owner record.</p>
      <table>
        <thead><tr><th>ID</th><th>Email</th><th>Phone</th><th>Active</th><th>Owners linked</th><th>Parcels</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($citizens as $c): ?>
          <tr>
            <td><?= (int)$c['id'] ?></td>
            <td><?= e($c['email'] ?? '—') ?></td>
            <td><?= e($c['phone']) ?></td>
            <td><?= $c['is_active'] ? 'yes' : 'no' ?></td>
            <td><?= (int)$c['linked_owners'] ?></td>
            <td><?= (int)$c['linked_parcels'] ?></td>
            <td><a href="?tab=citizens&link_user=<?= (int)$c['id'] ?>">Link owner</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($linkingUser > 0): ?>
    <div class="form-box">
      <h2>Link citizen #<?= (int)$linkingUser ?> to an owner</h2>
      <form method="get" class="form-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:12px; margin-bottom:12px;">
        <input type="hidden" name="tab" value="citizens"><input type="hidden" name="link_user" value="<?= (int)$linkingUser ?>">
        <div class="form-group"><label>Search owners</label><input name="owner_q" value="<?= e($ownerQ) ?>"></div>
        <div class="form-group"><label>&nbsp;</label><button class="btn btn-green" type="submit">Search</button></div>
      </form>
      <table>
        <thead><tr><th>Owner</th><th>ID no</th><th>Parcels</th><th></th></tr></thead>
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
                <button class="btn btn-green btn-sm" type="submit">Link</button>
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
        <button class="btn btn-red btn-sm" type="submit">Unlink all owners from this citizen</button>
      </form>
    </div>
    <?php endif; ?>
<?php endif; ?>

  </main>
</div>
</body>
</html>