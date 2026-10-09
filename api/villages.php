<?php
// API: list villages by district_id
require_once __DIR__ . '/../lib/db.php';
require_method('GET');

$district_id = isset($_GET['district_id']) ? intval($_GET['district_id']) : 0;
if (!$district_id) {
    json_output(['error' => 'District ID is required'], 400);
}

$pdo = db_connect();
$stmt = $pdo->prepare("SELECT id, code, name_lo, name_en FROM villages WHERE district_id = ? ORDER BY name_lo");
$stmt->execute([$district_id]);
$villages = $stmt->fetchAll();
json_output($villages);