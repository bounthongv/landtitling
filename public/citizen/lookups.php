<?php
// public/citizen/lookups.php — my parcels and what I owe for each year
$parcels = [];
$err = '';
$d = citizen_api('GET', 'my_parcels.php');
if (!empty($d['ok'])) {
    $parcels = citizen_data($d)['parcels'] ?? [];
} else {
    $err = citizen_error($d);
}

$thisYear = (int)date('Y');

/** Pick the row a citizen should act on: oldest unpaid year, else the newest. */
function parcel_focus_obligations(array $obligations): array {
    $unpaid = array_values(array_filter($obligations, function ($o) {
        return in_array($o['status'], ['UNPAID', 'PARTIAL'], true);
    }));
    if ($unpaid) {
        usort($unpaid, fn($a, $b) => (int)$a['fee_year'] <=> (int)$b['fee_year']);
        return [$unpaid[0], true];
    }
    return [$obligations[0] ?? null, false];
}
?>
<div class="card">
  <h2>ພື້ນທີ່ຂອງຂ້ານ</h2>
  <p class="sub">My parcels — each parcel owes one fee per year. ພື້ນທີ່ຂອງຂ້ານ ແລະ ຄ່າທີ່ຕ້ອງຊຳລະ</p>

  <?php if ($err): ?>
    <div class="alert err"><?= e($err) ?></div>
  <?php elseif (!$parcels): ?>
    <p class="empty">
      No parcels are linked to your phone number yet.<br>
      ຍັງບໍ່ມີພື້ນທີ່ຜູກລູກກັບເບີໂທຂອງທ່ານ — ກະລຸນາຕິດຕໍ່ກັບຫ້ອງການອາກອນ.
    </p>
    <p class="sub" style="margin-bottom:0;">No parcel number yet? An officer registers it from the land title in
      <a href="/public/admin/scans.php" style="color:var(--green);">Officer Review</a>.</p>
  <?php else: ?>
    <ul class="list">
      <?php foreach ($parcels as $p): ?>
        <?php [$focus, $isUnpaid] = parcel_focus_obligations($p['obligations'] ?? []); ?>
        <li>
          <div class="name">Parcel <?= e($p['parcel_no']) ?><?= !empty($p['sub_parcel_no']) ? ' / ' . e($p['sub_parcel_no']) : '' ?></div>
          <div style="font-size:13px;color:var(--muted);">
            <?= e($p['district_name']) ?> / <?= e($p['village_name']) ?>
          </div>
          <div style="font-size:13px;">
            <?= number_format((float)$p['area_sqm'], 2) ?> m² ·
            <?= e(ucfirst((string)$p['road_category'])) ?> ·
            ເລກທີ <?= e($p['title_no'] ?? '—') ?>
          </div>

          <?php if (count($p['obligations'] ?? []) > 1): ?>
            <div style="font-size:12px;color:var(--muted);margin-top:4px;">
              <?php foreach ($p['obligations'] as $o): ?>
                <span class="pill pill-<?= e(strtolower($o['status'])) ?>"><?= e($o['fee_year']) ?> · <?= e($o['status']) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ($focus): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;">
              <div>
                <div style="font-size:12px;color:var(--muted);">
                  <?= $isUnpaid ? 'Outstanding' : 'Settled' ?> · year <?= e($focus['fee_year']) ?>
                </div>
                <div class="amount" style="font-size:18px;">
                  <?= $isUnpaid ? kip($focus['outstanding']) : '✓ ' . e($focus['status']) ?>
                </div>
              </div>
              <?php if ($isUnpaid): ?>
                <a class="btn btn-gold btn-sm" style="width:auto;padding:8px 16px;font-size:14px;"
                   href="quote.php?obligation_id=<?= (int)$focus['id'] ?>&parcel_id=<?= (int)$p['parcel_id'] ?>">
                  ຈ່າຍ <?= e($focus['fee_year']) ?> →
                </a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>