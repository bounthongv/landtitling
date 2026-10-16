<?php
// public/citizen/login.php — phone OTP login
require __DIR__ . '/common.php';
$error = '';
$statusMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['otp_request'])) {
    $phone = $_POST['phone'] ?? '';
    if (!preg_match('/^\+?[0-9]{8,15}$/', $phone)) {
        $error = 'Enter a valid Lao phone number (e.g. +856****9999)';
    } else {
        $d = citizen_api('POST', 'auth.php', ['action' => 'otp/request', 'phone' => $phone], '');
        if (!empty($d['ok'])) {
            $_SESSION['citizen_phone'] = $phone;
            // The OTP token is stateless and signed by the API — carry it through verify.
            $_SESSION['citizen_otp_token'] = $d['data']['otp_token'] ?? '';
            $_SESSION['citizen_otp_sent'] = time();
            $statusMessage = 'OTP code sent.';
            $statusCode = $d['data']['debug_code'] ?? '';   // only present when OTP_DEBUG=1
        } else {
            $error = citizen_error($d) ?: 'Failed to request OTP';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['otp_verify'])) {
    $phone = $_POST['phone'] ?? ($_SESSION['citizen_phone'] ?? '');
    $code  = $_POST['code'] ?? '';
    $otp   = $_SESSION['citizen_otp_token'] ?? '';
    if ($phone !== ($_SESSION['citizen_phone'] ?? '')) {
        $error = 'This OTP was sent to a different number. Please sign in again.';
    } elseif (time() - ($_SESSION['citizen_otp_sent'] ?? 0) > 300) {
        $error = 'OTP has expired. Request a new code.';
    } else {
        $d = citizen_api('POST', 'auth.php', [
            'action' => 'otp/verify', 'phone' => $phone, 'code' => $code, 'otp_token' => $otp,
        ], '');
        if (!empty($d['ok']) && !empty($d['data']['token'])) {
            $_SESSION['citizen_token'] = $d['data']['token'];
            $_SESSION['citizen_user'] = $d['data']['user'] ?? ['id' => 0, 'role' => 'citizen'];
            unset($_SESSION['citizen_otp_token'], $_SESSION['citizen_otp_sent']);
            header('Location: ' . $citizen_base . '/index.php');
            exit;
        } else {
            $error = citizen_error($d) ?: 'Invalid code';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="lo">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login — Savannakhet Land Fee</title>
<?php require __DIR__ . '/style.php'; ?>
</head>
<body>
<div class="app" style="padding:24px 16px 0;">
  <div class="card" style="max-width:360px; margin:0 auto;">
    <h2>ຂັບລະຫັດ — ເຂົ້າຊາຍ</h2>
    <p class="sub">Land Fee — Citizen Login</p>
    <?php if ($statusMessage): ?>
      <div style="background:#eaf4ea; border:1px solid #bbdefb; border-radius:6px; padding:10px; font-size:13px; margin-bottom:12px;">
        <?= e($statusMessage) ?>
        <?php if ($statusCode): ?>
          <div style="margin-top:6px; font-family:monospace; font-size:14px;">[OTP CODE] <?= e($statusCode) ?></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($error): ?><div style="color:#b71c1c; background:#fdecea; padding:10px; border-radius:6px; font-size:13px; margin-bottom:12px;"><?= e($error) ?></div><?php endif; ?>

    <?php if (empty($_SESSION['citizen_otp_token'])): ?>
      <form method="post">
        <div class="form-group">
          <label>Phone number (+856 Lao)</label>
          <input name="phone" placeholder="+856 20 557 9999" value="+856205579999" required>
        </div>
        <button type="submit" name="otp_request" class="btn">Request OTP</button>
      </form>
    <?php else: ?>
      <form method="post">
        <div class="form-group">
          <label>Enter your 6-digit OTP (sent via SMS)</label>
          <input name="code" placeholder="••••••" inputmode="numeric" maxlength="6" required autocomplete="one-time-code">
        </div>
        <button type="submit" name="otp_verify" class="btn">Verify OTP</button>
      </form>
    <?php endif; ?>
    <p class="foot-note" style="text-align:center;margin-top:12px;">SMS gateway not yet wired — see <code>otp_send_sms()</code> in <code>lib/otp.php</code>.</p>
  </div>
</div>
</body>
</html>
