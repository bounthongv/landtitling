<?php
// API v1: fee quote — flat rate = tier_rate(zone_code, road_category) x area
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/validate.php';
require_once __DIR__ . '/../../lib/fee.php';
require_method('POST');
$body = read_json_body();

$zone_code = strtoupper(trim((string)($body['zone_code'] ?? '')));
$area_sqm  = $body['area_sqm'] ?? null;
$road      = trim((string)($body['road_category'] ?? '')) ?: null;

$err = collect_errors([
    'zone_code' => validate_required($zone_code, 'zone code'),
    'area_sqm'  => validate_required($area_sqm, 'area') ?: validate_positive($area_sqm, 'area'),
]);
reject_on_errors($err);

$pdo = db_connect();
$rate = zone_rate_for($pdo, $zone_code, $road);
if (!$rate) {
    json_error('Zone rate not found for code ' . $zone_code, 404, 'rate_not_found');
}

$area = (float)$area_sqm;
$fee_total = round((float)$rate['rate_per_sqm'] * $area, 2);

json_ok([
    'zone_code'     => $zone_code,
    'road_category' => $rate['road_category'],
    'area_sqm'      => $area,
    'rate_per_sqm'  => (float)$rate['rate_per_sqm'],
    'fee_total'     => $fee_total,
]);
