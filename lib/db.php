<?php
require_once __DIR__ . '/config.php';

function db_connect() {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $cfg = require __DIR__ . '/config.php';
    $dsn = "pgsql:host={$cfg['pgsql_host']};port={$cfg['pgsql_port']};dbname={$cfg['pgsql_db']}";
    $pdo = new PDO($dsn, $cfg['pgsql_user'], $cfg['pgsql_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET timezone = '{$cfg['timezone']}'");
    return $pdo;
}

/**
 * Standard response envelope: { ok: bool, data|error, meta? }
 */
function json_ok($data = null, $status = 200, $meta = null) {
    http_response_code($status);
    header('Content-Type: application/json');
    $body = ['ok' => true, 'data' => $data];
    if ($meta !== null) {
        $body['meta'] = $meta;
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error($message, $status = 400, $code = null, $field_errors = null) {
    http_response_code($status);
    header('Content-Type: application/json');
    $err = ['message' => $message];
    if ($code !== null) {
        $err['code'] = $code;
    }
    if ($field_errors !== null) {
        $err['fields'] = $field_errors;
    }
    echo json_encode(['ok' => false, 'error' => $err], JSON_UNESCAPED_UNICODE);
    exit;
}

function require_method($method) {
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        json_error('Method not allowed', 405, 'method_not_allowed');
    }
}

function read_json_body() {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_error('Invalid JSON body', 400, 'invalid_json');
    }
    return $data;
}