<?php
/**
 * Payment slip helpers: unique slip number + QR generation (via Google Chart API for MVP).
 */

function slip_generate_no($pdo) {
    // Format: LFE-YYYY-XXXXXX (incrementing)
    $year = date('Y');
    $stmt = $pdo->query("SELECT COUNT(*) AS c FROM payments WHERE slip_no LIKE '" . $year . "-%'");
    $count = (int)$stmt->fetch()['c'] + 1;
    return "LFE-" . $year . "-" . str_pad((string)$count, 6, '0', STR_PAD_LEFT);
}

/**
 * QR payload: plain reference for MVP (slip no + amount).
 * Real LaoQR standard can be swapped in later (see design open item).
 */
function slip_qr_payload($slip_no, $amount) {
    return "SLIP:$slip_no|AMT:$amount";
}

/**
 * Render a printable A4 payment slip as HTML (bilingual Lao/EN).
 */
function slip_render_html(array $payment, array $parcel, $district_name, $village_name, $qr_url) {
    $amount = number_format((float)$payment['fee_total'], 0);
    $area = number_format((float)$payment['area_sqm'], 0);
    $rate = number_format((float)$payment['zone_rate'], 0);
    return <<<HTML
<!DOCTYPE html>
<html lang="lo">
<head><meta charset="UTF-8"><title>Land Fee Payment Slip</title>
<style>
  body { font-family: sans-serif; padding: 30px; }
  .slip { border: 2px solid #333; padding: 24px; max-width: 700px; margin: auto; }
  .head { text-align: center; border-bottom: 1px solid #999; padding-bottom: 12px; }
  .row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dotted #ccc; }
  .label { font-weight: bold; }
  .amount { font-size: 24px; font-weight: bold; text-align: center; margin: 16px 0; }
  .qr { text-align: center; margin: 16px 0; }
  .foot { text-align: center; font-size: 12px; margin-top: 16px; color: #555; }
</style>
</head>
<body>
<div class="slip">
  <div class="head">
    <h2>ບັກຊຳລະຄ່າທຳນຽມທີ່ດິນ</h2>
    <p>Land Fee Payment Slip</p>
  </div>
  <div class="row"><span class="label">ເລກທີ / Slip No.</span><span>{$payment['slip_no']}</span></div>
  <div class="row"><span class="label">ແຂວງ / Province</span><span>ສະຫວັນນະເຂດ (Savannakhet)</span></div>
  <div class="row"><span class="label">ເມືອງ / District</span><span>{$district_name}</span></div>
  <div class="row"><span class="label">ບ້ານ / Village</span><span>{$village_name}</span></div>
  <div class="row"><span class="label">ຕອນດິນ / Parcel No.</span><span>{$parcel['parcel_no']}</span></div>
  <div class="row"><span class="label">ພື້ນທີ່ / Area</span><span>{$area} ຕາລາງແມັດ (m²)</span></div>
  <div class="row"><span class="label">ອັດຕາ / Rate</span><span>{$rate} ກີບ/ມ²</span></div>
  <div class="row"><span class="label">ປະເພດຖະໜົນ / Road</span><span>{$payment['road_category']}</span></div>
  <div class="amount">{$amount} ກີບ (kip)</div>
  <div class="qr"><img src="{$qr_url}" width="160" height="160" alt="QR"></div>
  <div class="foot">ກະລຸນາຊຳລະທີ່ທະນາຄານ ຫຼື ຫ້ອງການອາກອນ ພາຍໃນວັນທີ {$payment['due_date']} ແລ້ວສົ່ງຫຼັກຖານການຊຳລະ</div>
</div>
</body>
</html>
HTML;
}