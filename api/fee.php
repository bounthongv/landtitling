<?php
// API: fee quote
require_once __DIR__ . '/../lib/db.php';
require_method('POST');

$data = json_decode(file_get_contents('php://input'), true);
$district_id = intval($data['district_id'] ?? 0);
$road_category = $data['road_category'] ?? '';
$area = floatval($data['area'] ?? 0);

if (!$district_id || !$road_category || $area <= 0) {
    json_output(['error' => 'Missing parameters'], 400);
}

$pdo = db_connect();
$stmt = $pdo->prepare("
    SELECT rate_per_sqm FROM zones_rates 
    WHERE district_id = ? AND road_category = ? AND valid_from <= CURRENT_DATE 
    ORDER BY valid_from DESC LIMIT 1
");
$stmt->execute([$district_id, $road_category]);
$row = $stmt->fetch();

if (!$row) {
    json_output(['error' => 'Zone rate not found'], 404);
}

$rate = floatval($row['rate_per_sqm']);
$fee = $rate * $area;

json_output([
    'rate_per_sqm' => $rate,
    'area_sqm' => $area,
    'fee_total' => $fee,
]);
