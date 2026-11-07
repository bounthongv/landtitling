<?php
// API v1: banks — reference list of Lao banks citizens may pay through.
// Public read (citizens need the picker when attaching a receipt); admin-only
// create/update/delete via Basic Data later.
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];

$me = require_auth(['citizen', 'admin', 'officer', 'manager']);

if ($method === 'GET') {
    $onlyActive = !isset($_GET['all']) || $_GET['all'] !== '1';
    $where = $onlyActive ? 'WHERE is_active = TRUE' : '';
    $stmt = $pdo->query("SELECT code, name_lo, name_en, logo_path, is_active, sort_order
                          FROM banks $where ORDER BY sort_order ASC, code ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    json_ok(['banks' => $rows]);
}

json_error('Method not allowed', 405, 'method_not_allowed');