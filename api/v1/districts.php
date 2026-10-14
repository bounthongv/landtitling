<?php
// API v1: districts reference (public)
require_once __DIR__ . '/../../lib/db.php';
require_method('GET');

$pdo = db_connect();
$stmt = $pdo->query("SELECT id, code, name_lo, name_en FROM districts ORDER BY name_lo");
$districts = $stmt->fetchAll();
json_ok($districts);
