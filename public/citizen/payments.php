<?php
// public/citizen/payments.php — my payments (track status)
citizen_require_login();
$token = citizen_token();

$d = citizen_api('GET', 'payments.php', null, $token);
$payments = citizen_data($d)['payments'] ?? [];
$err = citizen_error($d);
?>
<div class="card">
  <h2>ການຊຳລະຂອງຂ້າຮ່າງ</h2>
  <p class="sub">Track your land fee payments: ISSUED → PAID / REJECTED.</p>

  <?php if ($err): ?><div class="alert err"><?= e($err) ?></div><?php endif; ?>

  <?php if (!$payments): ?>
    <p class="empty">No payments yet. <a href="lookups.php">See my parcels</a> to pay a fee.</p>
  <?php else: ?>
    <ul class="list">
      <?php foreach ($payments as $p): ?>
        <?php
        $pill = ['ISSUED' => 'pending', 'PAID' => 'paid', 'REJECTED' => 'rej', 'REFUNDED' => 'rej'][$p['status']] ?? 'pending';
        ?>
        <li>
          <div class="row" style="justify-content:space-between; align-items:flex-start;">
            <div>
              <div class="name"><?= e($p['reference_no']) ?></div>
              <div style="color:var(--muted);font-size:13px;">
                Parcel <?= e($p['parcel_no']) ?> · year <?= e($p['fee_year']) ?> ·
                <?= e($p['district_name']) ?> / <?= e($p['village_name']) ?>
              </div>
            </div>
            <div style="text-align:right;">
              <span class="pill pill-<?= e($pill) ?>"><?= e($p['status']) ?></span>
            </div>
          </div>
          <div style="display:flex; justify-content:space-between; align-items:center; padding-top:8px;">
            <div style="font-size:13px;color:var(--muted);"><?= e(ucfirst((string)$p['method'])) ?></div>
            <div class="amount" style="font-size:18px;"><?= kip($p['amount']) ?></div>
          </div>
          <div style="margin-top:6px; display:flex; gap:8px;">
            <a class="btn btn-gold btn-sm" style="width:auto;padding:6px 12px;"
               href="payment-view.php?id=<?= (int)$p['id'] ?>">View →</a>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>