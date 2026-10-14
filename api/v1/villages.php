<?php
// API v1: villages reference by district (public)
require_once __DIR__ . '/../../lib/db.php';
require_method('GET');

$district_id = isset($_GET['district_id']) ? intval($_GET['district_id']) : 0;
if (!$district_id) {
    json_error('District ID is required', 400, 'missing_id');
}

$pdo = db_connect();
$stmt = $pdo->prepare("SELECT id, code, name_lo, name_en FROM villages WHERE district_id = :did ORDER BY name_lo");
$stmt->execute([':did' => $district_id]);
$villages = $stmt->fetchAll();
json_ok($villages);
