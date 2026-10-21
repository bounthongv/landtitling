<?php
// public/admin/login.php — officer login via JWT
$cfg = require __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/i18n.php';
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = ['action' => 'login'];
    $body['username'] = $_POST['username'] ?? '';
    $body['password'] = $_POST['password'] ?? '';
    $ch = curl_init($cfg['api_base'] . '/auth.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($resp, true);
    if (!empty($data['ok']) && !empty($data['data']['token'])) {
        session_start();
        $_SESSION['admin_token'] = $data['data']['token'];
        $_SESSION['admin_user'] = $data['data']['user'];
        header('Location: ' . $cfg['admin_base'] . '/index.php');
        exit;
    } else {
        $error = $data['error']['message'] ?? t('Login failed');
    }
}
?>
<!DOCTYPE html>
<html lang="<?= i18n_current() ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= te('Officer Login') ?> — Land Fee MVP</title>
<style>
  body { font-family: 'Segoe UI', 'Noto Sans Lao', sans-serif; background: #eef3ea; color: #1a2e1a; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
  .card { background: #fff; padding: 28px; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,.12); width: 360px; }
  h1 { font-size: 20px; color: #1b5e20; margin-top: 0; }
  .sub { color: #555; font-size: 13px; margin-bottom: 20px; }
  .form-group { margin-bottom: 16px; }
  label { font-size: 13px; color: #333; display: block; margin-bottom: 4px; }
  input { width: 100%; padding: 9px; box-sizing: border-box; border: 1px solid #bbb; border-radius: 4px; }
  button { background: #1b5e20; color: #fff; padding: 10px 14px; border: none; border-radius: 4px; font-size: 14px; cursor: pointer; width: 100%; }
  button:hover { background: #2e7d32; }
  .msg { color: #b71c1c; background: #fdecea; padding: 10px; border-radius: 4px; font-size: 13px; margin-bottom: 12px; }
  .foot { font-size: 12px; color: #777; text-align: center; margin-top: 16px; }
  .lang-switch { text-align: right; font-size: 12px; margin-bottom: 10px; }
  .lang-switch a { color: #1b5e20; text-decoration: none; padding: 2px 6px; border-radius: 3px; }
  .lang-switch a:hover { background: #e8f0e6; }
  .lang-switch a.active { background: #1b5e20; color: #fff; font-weight: 700; }
  .lang-switch span { color: #999; margin: 0 2px; }
</style>
</head>
<body>
<div class="card">
  <?= lang_switch('login.php') ?>
  <h1><?= te('Officer Login') ?></h1>
  <p class="sub"><?= te('Land Fee Payment — Savannakhet Province') ?></p>
  <?php if ($error): ?><div class="msg"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <div class="form-group"><label><?= te('Username') ?></label><input name="username" value="sav_officer" required></div>
    <div class="form-group"><label><?= te('Password') ?></label><input name="password" type="password" value="demo1234" required></div>
    <button type="submit"><?= te('Sign in') ?></button>
  </form>
  <p class="foot"><?= te('Demo credentials') ?>: <code>sav_officer</code> / <code>demo1234</code></p>
</div>
</body>
</html>
