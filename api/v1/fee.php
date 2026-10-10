<?php
// API v1: fee quote — flat rate = zone_rate(district, road_category) x area
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/validate.php';
require_method('POST');
$body = read_json_body();

$district_id = $body['district_id'] ?? null;
$road_category = $body['road_category'] ?? null;
$area_sqm = $body['area_sqm'] ?? null;

$err = collect_errors([
    'district_id'   => validate_required($district_id, 'district') ?: validate_int($district_id, 'district'),
    'road_category' => validate_required($road_category, 'road category')
        ?: validate_enum($road_category, ['main', 'branch', 'alley', 'none'], 'road category'),
    'area_sqm'      => validate_required($area_sqm, 'area') ?: validate_positive($area_sqm, 'area'),
]);
reject_on_errors($err);

$pdo = db_connect();
$stmt = $pdo->prepare("
    SELECT rate_per_sqm FROM zones_rates
    WHERE district_id = :d AND road_category = :rc AND valid_from <= CURRENT_DATE
    ORDER BY valid_from DESC LIMIT 1
");
$stmt->execute([':d' => (int)$district_id, ':rc' => $road_category]);
$row = $stmt->fetch();
if (!$row) {
    json_error('Zone rate not found for this district/category', 404, 'rate_not_found');
}

$rate = (float)$row['rate_per_sqm'];
$area = (float)$area_sqm;
$fee_total = round($rate * $area, 2);

json_ok([
    'district_id'   => (int)$district_id,
    'road_category' => $road_category,
    'area_sqm'      => $area,
    'rate_per_sqm'  => $rate,
    'fee_total'     => $fee_total,
]);