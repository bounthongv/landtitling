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
 * QR payload for a payment slip.
 *
 * The format is config-driven (BANK_QR_FORMAT) so the province can switch to a
 * payload their bank can read without touching the payment flow:
 *   text (default) — the plain reference line, retyped into the banking app
 *   emvco          — a scannable merchant-presented payload
 *   url            — a deep link into the bank's payment page
 *
 * The reference always travels inside the payload: it is what the bank
 * statement carries, and what lib/bank_match.php matches the payment on.
 */
function slip_qr_payload($slip_no, $amount, $parcelNo = null) {
    $cfg    = require __DIR__ . '/config.php';
    $format = strtolower(trim((string)($cfg['bank_qr_format'] ?? 'text')));
    $amount = number_format((float)$amount, 2, '.', '');
    $reference = (string)$slip_no;
    $parcel = (string)($parcelNo ?? '');

    if ($format === 'emvco') {
        return slip_qr_emvco($reference, $amount, $parcel, (string)($cfg['bank_qr_merchant_id'] ?? ''));
    }
    if ($format === 'url') {
        $tpl = (string)($cfg['bank_qr_url_template'] ?? '');
        if ($tpl !== '') {
            return str_replace(
                ['{ref}', '{amt}', '{parcel}'],
                [rawurlencode($reference), rawurlencode($amount), rawurlencode($parcel)],
                $tpl
            );
        }
        // No template configured yet — fall through to the plain line rather
        // than emit a QR the bank cannot read.
    }
    return $parcel !== ''
        ? "SLIP:$reference|AMT:$amount|PARCEL:$parcel"
        : "SLIP:$reference|AMT:$amount";
}

/** One EMVCo TLV field: 2-digit id + 2-digit length + value. */
function slip_qr_tlv(string $id, string $value): string {
    return $id . str_pad((string)strlen($value), 2, '0', STR_PAD_LEFT) . $value;
}

/**
 * CRC-16/CCITT-FALSE (poly 0x1021, init 0xFFFF, no reflection) — the checksum
 * an EMVCo payload carries in tag 63.
 * Reference vector: "123456789" -> 0x29B1.
 */
function slip_qr_crc16(string $data): string {
    $crc = 0xFFFF;
    $len = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $crc ^= (ord($data[$i]) << 8);
        for ($b = 0; $b < 8; $b++) {
            $crc = ($crc & 0x8000)
                ? ((($crc << 1) ^ 0x1021) & 0xFFFF)
                : (($crc << 1) & 0xFFFF);
        }
    }
    return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
}

/**
 * EMVCo merchant-presented payload (Lao, LAK).
 *
 * NOT YET VALIDATED against a real bank: the province has not supplied the
 * receiving merchant account, so this is built to the published EMVCo shape and
 * cannot be confirmed end to end until BANK_QR_MERCHANT_ID is set and the
 * bank confirms its own QR spec. The CRC is verified against the standard
 * test vector.
 */
function slip_qr_emvco(string $reference, string $amount, string $parcel, string $merchantId): string {
    $merchant  = slip_qr_tlv('00', 'A000000727') . slip_qr_tlv('01', $merchantId);

    $payload   = slip_qr_tlv('00', '01');          // payload format indicator
    $payload  .= slip_qr_tlv('01', '12');          // dynamic QR (single use)
    $payload  .= slip_qr_tlv('26', $merchant);     // merchant account information
    $payload  .= slip_qr_tlv('53', '360');         // currency: LAK
    $payload  .= slip_qr_tlv('54', $amount);       // transaction amount
    $payload  .= slip_qr_tlv('58', 'LK');          // country

    $additional = slip_qr_tlv('01', $reference);   // the reference the bank echoes back
    if ($parcel !== '') {
        $additional .= slip_qr_tlv('05', $parcel);
    }
    $payload  .= slip_qr_tlv('62', $additional);

    // Tag 63 is the CRC over everything before it, including its own "6304" id+length.
    return $payload . '6304' . slip_qr_crc16($payload . '6304');
}

/**
 * Render a printable A4 payment document as HTML (bilingual Lao/EN).
 * Expects the v2 payment row (reference_no, fee_year, amount, method) plus parcel context.
 * $receipt=true renders the settlement receipt (status, paid date, officer) instead
 * of the payment-order slip; $officerName is the verifying officer's username.
 */
function slip_render_html(array $payment, array $parcel, $district_name, $village_name, $qr_url, $receipt = false, $officerName = '') {
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $amount = number_format((float)$payment['amount'], 0);
    $area   = number_format((float)($parcel['area_sqm'] ?? 0), 0);
    $rate   = number_format((float)($payment['rate_per_sqm'] ?? 0), 0);
    $year   = $h($payment['fee_year'] ?? date('Y'));
    $ref    = $h($payment['reference_no'] ?? '');
    $method = $h(payment_method_label((string)($payment['method'] ?? 'counter')));
    $due    = $h($payment['due_date'] ?? '');
    $district_name = $h($district_name);
    $village_name  = $h($village_name);
    $parcel_no     = $h($parcel['parcel_no'] ?? '');
    // Province comes from the parcel record when available, else the seeded default.
    $province = $h($parcel['province'] ?? 'Savannakhet');
    $titleLo  = $receipt ? 'ໃບຮັບເງິນ' : 'ໃບຊໍາລະ';
    $titleEn  = $receipt ? 'Land Fee Payment Receipt' : 'Land Fee Payment Slip';
    $paidAt   = $h($payment['paid_at'] ?? '');
    $officer  = $h($officerName);
    $statusLo = $receipt ? '<div class="paid-mark">ຈ່າຍແລ້ວ / PAID</div>' : '';
    $foot = $receipt
        ? 'ຊໍາລະສໍາເລັດແລ້ວ — ຂອບໃຈທີ່ຊໍາລະ / Payment received — thank you'
        : 'ກະລຸນາຊໍາລະທີ່ທະນາຄານ ຫຼື ຫ້ອງການອາກອນ ພາຍໃນວັນທີ ' . $due . ' ແລ້ວສົ່ງຫຼັກຖານການຊໍາລະ';
    return <<<HTML
<!DOCTYPE html>
<html lang="lo">
<head><meta charset="UTF-8"><title>{$titleEn}</title>
<style>
  body { font-family: sans-serif; padding: 30px; }
  .slip { border: 2px solid #333; padding: 24px; max-width: 700px; margin: auto; }
  .head { text-align: center; border-bottom: 1px solid #999; padding-bottom: 12px; }
  .row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px dotted #ccc; }
  .label { font-weight: bold; }
  .amount { font-size: 24px; font-weight: bold; text-align: center; margin: 16px 0; }
  .qr { text-align: center; margin: 16px 0; }
  .foot { text-align: center; font-size: 12px; margin-top: 16px; color: #555; }
  .paid-mark { display: inline-block; margin-top: 8px; padding: 4px 16px; border: 2px solid #1b5e20; color: #1b5e20; font-weight: bold; border-radius: 4px; }
</style>
</head>
<body>
<div class="slip">
  <div class="head">
    <h2>{$titleLo}</h2>
    <p>{$titleEn} — {$year}</p>
    {$statusLo}
  </div>
  <div class="row"><span class="label">ເລກທີອ້າງອງິງ / Reference</span><span>{$ref}</span></div>
  <div class="row"><span class="label">ແຂວງ / Province</span><span>{$province}</span></div>
  <div class="row"><span class="label">ເມືອງ / District</span><span>{$district_name}</span></div>
  <div class="row"><span class="label">ບ້ານ / Village</span><span>{$village_name}</span></div>
  <div class="row"><span class="label">ຕອນດິນ / Parcel No.</span><span>{$parcel_no}</span></div>
  <div class="row"><span class="label">ພື້ນທີ່ / Area</span><span>{$area} ຕາລາງແມັດ (m2)</span></div>
  <div class="row"><span class="label">ອັດຕາ / Rate</span><span>{$rate} ກີບ/ມ2</span></div>
  <div class="row"><span class="label">ວິທີຊໍາລະ / Method</span><span>{$method}</span></div>
HTML
    . ($receipt && $paidAt !== '' ? "
  <div class=\"row\"><span class=\"label\">ວັນທີຊໍາລະ / Paid on</span><span>{$paidAt}</span></div>" : '')
    . ($receipt && $officer !== '' ? "
  <div class=\"row\"><span class=\"label\">ຜູ້ຮັບເງິນ / Received by</span><span>{$officer}</span></div>" : '')
    . <<<HTML
  <div class="amount">{$amount} ກີບ (kip)</div>
  <div class="qr"><img src="{$qr_url}" width="160" height="160" alt="QR"></div>
  <div class="foot">{$foot}</div>
</div>
</body>
</html>
HTML;
}