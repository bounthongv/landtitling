<?php
// API v1: refdata.php — admin maintenance of the small lookup tables.
// One endpoint per table via ?table=, all admin-only for writes; GET is open so
// the citizen/offender screens can populate dropdowns.
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];

// Whitelisted tables and their editable columns. Keeping this explicit means the
// table name can never be interpolated straight from the request.
$tables = [
    'provinces' => [
        'label' => 'Province',
        'columns' => ['code', 'name_lo', 'name_en', 'note', 'is_active'],
        'required' => ['code', 'name_lo'],
        'order' => 'name_lo',
    ],
    'title_types' => [
        'label' => 'Title type',
        'columns' => ['code', 'name_lo', 'name_en'],
        'required' => ['code', 'name_lo', 'name_en'],
        'order' => 'code',
    ],
    'land_use_classes' => [
        'label' => 'Land use',
        'columns' => ['code', 'name_lo', 'name_en'],
        'required' => ['code', 'name_lo', 'name_en'],
        'order' => 'code',
    ],
];

// All codes are also a valid order-by list, so a caller can request one or all.
if ($method === 'GET') {
    $wanted = trim((string)($_GET['table'] ?? ''));
    $out = [];
    foreach ($tables as $name => $cfg) {
        if ($wanted !== '' && $wanted !== $name) {
            continue;
        }
        $stmt = $pdo->query("SELECT * FROM $name ORDER BY {$cfg['order']}");
        $out[$name] = $stmt->fetchAll();
    }
    if ($wanted !== '' && !isset($out[$wanted])) {
        json_error('Unknown table: ' . $wanted, 400, 'unknown_table');
    }
    json_ok($wanted === '' ? $out : $out[$wanted]);
}

// ---- Writes are admin-only ----
$me = require_auth(['admin']);

/**
 * Bind-safe value for a lookup column.
 * PDO binds everything from execute(array) as a string, so a PHP bool would reach
 * a boolean column as '' and fail — hence explicit 'true'/'false' literals.
 * NOT NULL numeric columns get a default instead of a null violation.
 */
function refdata_bind_value(string $column, array $body, bool $isCreate)
{
    if ($column === 'is_active') {
        if (!array_key_exists($column, $body)) {
            return $isCreate ? 'true' : null;
        }
        return empty($body[$column]) ? 'false' : 'true';
    }
    if ($column === 'sort_order') {
        $raw = $body[$column] ?? null;
        return ($raw === null || trim((string)$raw) === '') ? '0' : (string)(int)$raw;
    }
    $raw = $body[$column] ?? null;
    if (!is_scalar($raw)) {
        return null;
    }
    $v = trim((string)$raw);
    return $v === '' ? null : $v;
}

$table = trim((string)($_GET['table'] ?? ''));
if ($table === '') {
    $table = trim((string)(read_json_body()['table'] ?? ''));
}
if (!isset($tables[$table])) {
    json_error('Unknown table', 400, 'unknown_table');
}
$cfg = $tables[$table];
$cols = $cfg['columns'];

if ($method === 'POST' && !isset($_GET['code'])) {
    // Create
    $body = read_json_body();
    $missing = [];
    $vals = [];
    foreach ($cols as $c) {
        if (in_array($c, $cfg['required'], true) && (!isset($body[$c]) || trim((string)$body[$c]) === '')) {
            $missing[] = $c;
        }
        $vals[$c] = $body[$c] ?? null;
    }
    if ($missing) {
        json_error('Missing: ' . implode(', ', $missing), 400, 'missing_fields', $missing);
    }

$placeholders = [];
    $params = [];
    foreach ($cols as $c) {
        $placeholders[] = ':' . $c;
        $params[':' . $c] = refdata_bind_value($c, $body, true);
    }
    try {
        $st = $pdo->prepare("INSERT INTO $table (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ") RETURNING *");
        $st->execute($params);
        json_ok($st->fetch(), 201);
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') {
            json_error('That code already exists in ' . $cfg['label'], 409, 'duplicate_code');
        }
        json_error('Create failed: ' . $e->getMessage(), 500, 'create_failed');
    }
}

if ($method === 'PUT') {
    // Update by primary key ?id=
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        json_error('id is required', 400, 'missing_id');
    }
    $body = read_json_body();
    $sets = [];
    $params = [':id' => $id];
    foreach ($cols as $c) {
        if (!array_key_exists($c, $body)) {
            continue;
        }
        $sets[] = "$c = :$c";
        $params[':' . $c] = refdata_bind_value($c, $body, false);
    }
    if (!$sets) {
        json_error('Nothing to update', 400, 'nothing_to_update');
    }
    $sets[] = 'updated_at = now()';
    try {
        $st = $pdo->prepare("UPDATE $table SET " . implode(', ', $sets) . " WHERE id = :id RETURNING *");
        $st->execute($params);
        $row = $st->fetch();
        if (!$row) {
            json_error('Not found', 404, 'not_found');
        }
        json_ok($row);
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') {
            json_error('That code already exists in ' . $cfg['label'], 409, 'duplicate_code');
        }
        json_error('Update failed: ' . $e->getMessage(), 500, 'update_failed');
    }
}

if ($method === 'POST' && isset($_GET['code'])) {
    // Delete by code: ?table=road_categories&code=alley
    $code = trim((string)$_GET['code']);
    if ($code === '') {
        json_error('code is required', 400, 'missing_code');
    }
    try {
        $st = $pdo->prepare("DELETE FROM $table WHERE code = :code RETURNING *");
        $st->execute([':code' => $code]);
        $row = $st->fetch();
        if (!$row) {
            json_error('Not found', 404, 'not_found');
        }
        json_ok(['deleted' => $row]);
    } catch (PDOException $e) {
        // 23503 = still referenced (e.g. parcels.road_category). Explain rather than leak SQL.
        if ($e->getCode() === '23503') {
            json_error(
                'Cannot delete: this ' . strtolower($cfg['label']) . ' is still used by existing records.',
                409, 'in_use'
            );
        }
        json_error('Delete failed: ' . $e->getMessage(), 500, 'delete_failed');
    }
}

json_error('Method not allowed', 405, 'method_not_allowed');