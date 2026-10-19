<?php
// API v1: zone rates (flat, location-based) — GET list, PUT update
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';
require_once __DIR__ . '/../../lib/slip.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];
$me = require_auth(['officer', 'admin']);

// ---- GET: list zones (active first) ----
if ($method === 'GET') {
    $stmt = $pdo->query("SELECT code, name_lo, name_en, rate_per_sqm, valid_from,
            is_active, note, updated_at
        FROM zones
        ORDER BY is_active DESC, code");
    $zones = $stmt->fetchAll();
    json_ok(['zones' => $zones]);
}

// ---- POST (no ?code): create a zone ----
if ($method === 'POST' && !isset($_GET['code'])) {
    $body = read_json_body();
    $code   = trim((string)($body['code'] ?? ''));
    $nameLo = trim((string)($body['name_lo'] ?? ''));
    $nameEn = trim((string)($body['name_en'] ?? ''));
    $rate   = $body['rate_per_sqm'] ?? null;

    $err = collect_errors([
        'code'         => validate_required($code, 'zone code'),
        'name_lo'      => validate_required($nameLo, 'Lao name'),
        'rate_per_sqm' => validate_required($rate, 'rate') ?: validate_positive($rate, 'rate'),
    ]);
    reject_on_errors($err);

    try {
        $stmt = $pdo->prepare("INSERT INTO zones (code, name_lo, name_en, rate_per_sqm, note, valid_from)
            VALUES (:code, :nlo, :nen, :rate, :note, CURRENT_DATE)
            RETURNING code, name_lo, name_en, rate_per_sqm");
        $stmt->execute([
            ':code' => $code,
            ':nlo'  => $nameLo,
            ':nen'  => $nameEn ?: null,
            ':rate' => (float)$rate,
            ':note' => $body['note'] ?? null,
        ]);
        json_ok($stmt->fetch(), 201);
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') {
            json_error('Zone ' . $code . ' already exists', 409, 'duplicate_code');
        }
        json_error('Create failed: ' . $e->getMessage(), 500, 'create_failed');
    }
}

// ---- PUT ?code=: update a zone's rate / names / active ----
if ($method === 'PUT') {
    $code = trim((string)($_GET['code'] ?? ''));
    if ($code === '') {
        json_error('zone code is required', 400, 'missing_code');
    }
    $body = read_json_body();
    $sets = [];
    $params = [':code' => $code];
    foreach (['name_lo' => 'nlo', 'name_en' => 'nen', 'note' => 'note'] as $f => $p) {
        if (array_key_exists($f, $body)) {
            $sets[] = "$f = :$p";
            $params[':' . $p] = trim((string)$body[$f]) ?: null;
        }
    }
    if (array_key_exists('rate_per_sqm', $body)) {
        $sets[] = 'rate_per_sqm = :rate';
        $params[':rate'] = (float)$body['rate_per_sqm'];
    }
    if (array_key_exists('is_active', $body)) {
        $sets[] = 'is_active = :act';
        $params[':act'] = $body['is_active'] ? true : false;
    }
    if (!$sets) {
        json_error('Nothing to update', 400, 'nothing_to_update');
    }
    $sets[] = 'updated_at = CURRENT_TIMESTAMP';
    try {
        $stmt = $pdo->prepare("UPDATE zones SET " . implode(', ', $sets) . " WHERE code = :code
            RETURNING code, name_lo, name_en, rate_per_sqm, is_active, note");
        $stmt->execute($params);
        $row = $stmt->fetch();
        if (!$row) {
            json_error('Zone not found', 404, 'not_found');
        }
        json_ok($row);
    } catch (PDOException $e) {
        json_error('Update failed: ' . $e->getMessage(), 500, 'update_failed');
    }
}

// ---- POST ?code=: delete a zone ----
if ($method === 'POST' && isset($_GET['code'])) {
    $code = trim((string)$_GET['code']);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM parcels WHERE zone_code = :c");
    $stmt->execute([':c' => $code]);
    $parcels = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fee_obligations WHERE zone_code = :c");
    $stmt->execute([':c' => $code]);
    $obls = (int)$stmt->fetchColumn();
    if ($parcels > 0 || $obls > 0) {
        json_error(
            'Cannot delete zone ' . $code . ': still used by ' . $parcels . ' parcel(s) and '
                . $obls . ' obligation(s).',
            409, 'in_use', ['parcels' => $parcels, 'obligations' => $obls]
        );
    }
    $pdo->prepare("DELETE FROM zones WHERE code = :code")->execute([':code' => $code]);
    json_ok(['deleted' => $code]);
}

json_error('Method not allowed', 405, 'method_not_allowed');
