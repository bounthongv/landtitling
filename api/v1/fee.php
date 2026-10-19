<?php
// API v1: fee quote — flat rate = zone_rate(zone_code) x area
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/validate.php';
require_method('POST');
$body = read_json_body();

$zone_code = trim((string)($body['zone_code'] ?? ''));
$area_sqm  = $body['area_sqm'] ?? null;

$err = collect_errors([
    'zone_code' => validate_required($zone_code, 'zone code'),
    'area_sqm'  => validate_required($area_sqm, 'area') ?: validate_positive($area_sqm, 'area'),
]);
reject_on_errors($err);

$pdo = db_connect();
$stmt = $pdo->prepare("
    SELECT rate_per_sqm FROM zones
    WHERE code = :zc AND is_active = TRUE AND valid_from <= CURRENT_DATE
    ORDER BY valid_from DESC LIMIT 1
");
$stmt->execute([':zc' => $zone_code]);
$row = $stmt->fetch();
if (!$row) {
    json_error('Zone rate not found for code ' . $zone_code, 404, 'rate_not_found');
}

$rate = (float)$row['rate_per_sqm'];
$area = (float)$area_sqm;
$fee_total = round($rate * $area, 2);

json_ok([
    'zone_code'    => $zone_code,
    'area_sqm'     => $area,
    'rate_per_sqm' => $rate,
    'fee_total'    => $fee_total,
]);