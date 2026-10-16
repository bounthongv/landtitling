<?php
/**
 * Payment slip helpers: unique payment reference + QR payload + printable A4 slip.
 */

/**
 * Collision-free payment reference: LFE-{year}-{seq6}.
 * Uses a dedicated sequence (never COUNT(*)+1, which races and reuses numbers
 * after deletes). The sequence is created lazily so existing installs work.
 */
function slip_generate_no($pdo) {
    static $ensured = false;
    if (!$ensured) {
        $ensured = true;
        try {
            $pdo->exec("CREATE SEQUENCE IF NOT EXISTS payment_ref_seq");
            // Keep the sequence ahead of any references already issued.
            $row = $pdo->query("SELECT COALESCE(MAX((regexp_split_to_array(reference_no, '-'))[3]::bigint), 0) AS m
                                FROM payments WHERE reference_no ~ '^LFE-[0-9]{4}-[0-9]{6}$'")->fetch();
            $max = (int)($row['m'] ?? 0);
            if ($max > 0) {
                $pdo->exec("SELECT setval('payment_ref_seq', $max, true)");
            }
        } catch (\Throwable $e) {
            // fall through — the UNIQUE constraint below still protects us
        }
    }
    $seq = (int)$pdo->query("SELECT nextval('payment_ref_seq') AS s")->fetch()['s'];
    return 'LFE-' . date('Y') . '-' . str_pad((string)$seq, 6, '0', STR_PAD_LEFT);
}

/**
 * QR payload: plain reference for MVP (reference + amount).
 * Real LaoQR standard can be swapped in later (see design open item).
 */
function slip_qr_payload($slip_no, $amount) {
    return "SLIP:$slip_no|AMT:$amount";
}

/**
 * Render a printable A4 payment slip as HTML (bilingual Lao/EN).
 * Expects the v2 payment row (reference_no, fee_year, amount, method) plus parcel context.
 */
function slip_render_html(array $payment, array $parcel, $district_name, $village_name, $qr_url) {
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $amount = number_format((float)$payment['amount'], 0);
    $area   = number_format((float)($parcel['area_sqm'] ?? 0), 0);
    $rate   = number_format((float)($payment['rate_per_sqm'] ?? 0), 0);
    $year   = $h($payment['fee_year'] ?? date('Y'));
    $ref    = $h($payment['reference_no'] ?? '');
    $method = $h(ucfirst((string)($payment['method'] ?? 'counter')));
    $due    = $h($payment['due_date'] ?? '');
    $district_name = $h($district_name);
    $village_name  = $h($village_name);
    $parcel_no     = $h($parcel['parcel_no'] ?? '');
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
    <p>Land Fee Payment Slip — {$year}</p>
  </div>
  <div class="row"><span class="label">ເລກທີອ້າງອງິງ / Reference</span><span>{$ref}</span></div>
  <div class="row"><span class="label">ແຂວງ / Province</span><span>ສະຫວັນນະເຂດ (Savannakhet)</span></div>
  <div class="row"><span class="label">ເມືອງ / District</span><span>{$district_name}</span></div>
  <div class="row"><span class="label">ບ້ານ / Village</span><span>{$village_name}</span></div>
  <div class="row"><span class="label">ຕອນດິນ / Parcel No.</span><span>{$parcel_no}</span></div>
  <div class="row"><span class="label">ພື້ນທີ່ / Area</span><span>{$area} ຕາລາງແມັດ (m²)</span></div>
  <div class="row"><span class="label">ອັດຕາ / Rate</span><span>{$rate} ກີບ/ມ²</span></div>
  <div class="row"><span class="label">ວິທີຊຳລະ / Method</span><span>{$method}</span></div>
  <div class="amount">{$amount} ກີບ (kip)</div>
  <div class="qr"><img src="{$qr_url}" width="160" height="160" alt="QR"></div>
  <div class="foot">ກະລຸນາຊຳລະທີ່ທະນາຄານ ຫຼື ຫ້ອງການອາກອນ ພາຍໃນວັນທີ {$due} ແລ້ວສົ່ງຫຼັກຖານການຊຳລະ</div>
</div>
</body>
</html>
HTML;
}