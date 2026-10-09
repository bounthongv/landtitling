<?php
require_once __DIR__ . '/config.php';

function db_connect() {
    $cfg = require __DIR__ . '/config.php';
    $dsn = "pgsql:host={$cfg['pgsql_host']};port={$cfg['pgsql_port']};dbname={$cfg['pgsql_db']}";
    $pdo = new PDO($dsn, $cfg['pgsql_user'], $cfg['pgsql_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET timezone = '{$cfg['timezone']}'");
    return $pdo;
}

function json_output($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function require_method($method) {
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        json_output(['error' => 'Method not allowed'], 405);
    }
}

function require_auth() {
    session_start();
    if (empty($_SESSION['user_id'])) {
        json_output(['error' => 'Unauthorized'], 401);
    }
}