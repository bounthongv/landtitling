<?php
// public/citizen/index.php — citizen app shell
require __DIR__ . '/common.php';
$token = $_SESSION['citizen_token'] ?? '';
$user = $_SESSION['citizen_user'] ?? null;
if (!$token) { header('Location: login.php'); exit; }

// Fetch user name
$name = 'Citizen';
if (!empty($user['id'])) {
    $ch = curl_init($api_base . '/auth.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Use a server-side bootstrap to get user name via a new endpoint is not done here;
    // we'll accept the token and show the user id/role.
    $u = json_decode($user, true);
    $name = $u['phone'] ?? 'Citizen';
}

$tab = $_GET['tab'] ?? 'lookup';
?>
<!DOCTYPE html>
<html lang="lo">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Land Fee — Savannakhet</title>
<?php require __DIR__ . '/style.php'; ?>
</head>
<body>
<div class="app">
  <header class="app-header">
    <div class="title">ປເມີນຊຳລະທຳນຽມ</div>
    <div class="sub">ສະຫວັນນະເຂດ · Savannakhet</div>
  </header>
  <main>
    <?php if ($tab === 'lookup'): ?>
      <?php include 'lookups.php'; ?>
    <?php elseif ($tab === 'quote'): ?>
      <?php include 'quote.php'; ?>
    <?php elseif ($tab === 'payments'): ?>
      <?php include 'payments.php'; ?>
    <?php endif; ?>
  </main>
  <nav class="tab-nav">
    <button class="<?= $tab === 'lookup' ? 'active' : '' ?>" data-tab="lookup">ຄົ້ນຫາພື້ນທີ່</button>
    <button class="<?= $tab === 'quote' ? 'active' : '' ?>" data-tab="quote">ຄ່າຊຳລະ</button>
    <button class="<?= $tab === 'payments' ? 'active' : '' ?>" data-tab="payments">ການຊຳລະຂອງຂ້າຮ່າງ</button>
  </nav>
</div>
<div class="toast" id="toast"></div>
<script>
const TOKEN = '<?= e($token) ?>';
const shop = {
  async api(path, opts) {
    const r = await fetch(path, {
      ...opts,
      headers: { 'Content-Type': 'application/json', ...(opts.headers||{}), 'Authorization': 'Bearer ' + TOKEN }
    });
    return r.json();
  },
  showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3500);
  }
};

// Tab switching
document.querySelectorAll('.tab-nav button').forEach(b => {
  b.addEventListener('click', () => {
    document.querySelectorAll('.tab-nav button').forEach(x => x.classList.remove('active'));
    b.classList.add('active');
    window.location.hash = b.dataset.tab;
  });
});
const hash = window.location.hash.replace('#', '');
if (hash) {
  const btn = document.querySelector(`.tab-nav button[data-tab="${hash}"]`);
  if (btn) btn.click();
}
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('service-worker.js').catch(() => {});
}
</script>
</body>
</html>
