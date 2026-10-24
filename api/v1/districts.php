<?php
// API v1: districts — public GET (dropdowns) + admin-only create/update/delete.
// Fees and parcels depend on districts, so deletes are guarded: rather than relying
// on the FK error we check usage and report counts, which an officer can act on.
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];

// Auth: all staff can read; writes are province-level only
$me = require_auth(['admin', 'officer', 'manager']);
$scope = staff_scope($me);

// ---- GET: list (public) ----
if ($method === 'GET') {
    $province_id = isset($_GET['province_id']) ? (int)$_GET['province_id'] : 0;
    $where = '';
    $params = [];
    if ($province_id) {
        $where = 'WHERE d.province_id = :pid';
        $params[':pid'] = $province_id;
    }
    // District staff see only their own district.
    if ($scope['district_id'] !== null) {
        $where = ($where ? $where . ' AND ' : 'WHERE ') . 'd.id = :scope_did';
        $params[':scope_did'] = $scope['district_id'];
    }

    // Sortable columns, whitelisted to a fixed expression map — the key is never
    // interpolated into SQL, only the mapped expression is. Default stays name_lo,
    // matching the previous ordering. `code` is a stable tiebreaker.
    $sortMap = [
        'code'     => 'd.code',
        'name_lo'  => 'd.name_lo',
        'name_en'  => 'd.name_en',
        'province' => 'pr.name_lo',
        'villages' => 'village_count',
        'parcels'  => 'parcel_count',
    ];
    $sortCol = $sortMap[trim((string)($_GET['sort'] ?? ''))] ?? 'd.name_lo';
    $dir     = strtolower(trim((string)($_GET['dir'] ?? 'asc'))) === 'desc' ? 'DESC' : 'ASC';

    $stmt = $pdo->prepare("SELECT d.id, d.code, d.name_lo, d.name_en, d.province_id,
            d.zone_letter,
            pr.name_lo AS province_name,
            (SELECT COUNT(*) FROM villages v WHERE v.district_id = d.id) AS village_count,
            (SELECT COUNT(*) FROM parcels p WHERE p.district_id = d.id)  AS parcel_count
        FROM districts d
        LEFT JOIN provinces pr ON pr.id = d.province_id
        $where
        ORDER BY $sortCol $dir NULLS LAST, d.code ASC");
    $stmt->execute($params);
    json_ok($stmt->fetchAll());
}

// ---- Writes: admin only (province level) ---
// District staff cannot manage districts — that's a province-level function.
if ($scope['district_id'] !== null) {
    // They can only see their own district (handled in GET above).
    // Any write attempt by district staff is rejected.
    if ($method !== 'GET') {
        json_error('District management is a province-level function', 403, 'read_only');
    }
}

/** Usage counts, so a refused delete explains itself. */
function district_usage(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT
        (SELECT COUNT(*) FROM villages v WHERE v.district_id = :a) AS villages,
        (SELECT COUNT(*) FROM parcels p WHERE p.district_id = :a) AS parcels,
        (SELECT COUNT(DISTINCT zone_code) FROM parcels p WHERE p.district_id = :a AND zone_code IS NOT NULL) AS zones");
    $st->execute([':a' => $id]);
    return $st->fetch() ?: ['villages' => 0, 'parcels' => 0, 'rates' => 0];
}

if ($method === 'POST' && !isset($_GET['id'])) {
    $body = read_json_body();
    $code     = trim((string)($body['code'] ?? ''));
    $nameLo   = trim((string)($body['name_lo'] ?? ''));
    $nameEn   = trim((string)($body['name_en'] ?? ''));
    $provinceId = !empty($body['province_id']) ? (int)$body['province_id'] : 0;
    $zoneLetter = strtoupper(trim((string)($body['zone_letter'] ?? ''))) ?: null;
    if ($zoneLetter !== null && !preg_match('/^[A-Z]{1,5}$/', $zoneLetter)) {
        json_error('Zone letter must be 1–5 Latin letters', 422, 'bad_zone_letter');
    }

    $err = collect_errors([
        'code'        => validate_required($code, 'code'),
        'name_lo'     => validate_required($nameLo, 'Lao name'),
        'province_id' => validate_required($provinceId, 'province') ?: validate_int($provinceId, 'province'),
    ]);
    reject_on_errors($err);

    $st = $pdo->prepare("SELECT 1 FROM provinces WHERE id = :id");
    $st->execute([':id' => $provinceId]);
    if (!$st->fetch()) {
        json_error('Province not found', 422, 'bad_province');
    }

    try {
        $st = $pdo->prepare("INSERT INTO districts (province_id, code, name_lo, name_en, zone_letter)
            VALUES (:pid, :code, :nlo, :nen, :zl)
            RETURNING id, code, name_lo, name_en, province_id, zone_letter");
        $st->execute([':pid' => $provinceId, ':code' => $code, ':nlo' => $nameLo,
            ':nen' => $nameEn ?: null, ':zl' => $zoneLetter]);
        json_ok($st->fetch(), 201);
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') {
            if (str_contains($e->getMessage(), 'uq_districts_province_zone_letter')) {
                json_error('Zone letter ' . $zoneLetter . ' is already used in this province', 409, 'duplicate_zone_letter');
            }
            json_error('District code ' . $code . ' already exists', 409, 'duplicate_code');
        }
        json_error('Create failed: ' . $e->getMessage(), 500, 'create_failed');
    }
}

if ($method === 'PUT') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        json_error('id is required', 400, 'missing_id');
    }
    $body = read_json_body();
    $sets = [];
    $params = [':id' => $id];
    foreach (['code' => 'code', 'name_lo' => 'nlo', 'name_en' => 'nen'] as $field => $p) {
        if (array_key_exists($field, $body)) {
            $sets[] = "$field = :$p";
            $params[':' . $p] = trim((string)$body[$field]) ?: null;
        }
    }
    if (array_key_exists('province_id', $body) && (int)$body['province_id'] > 0) {
        $sets[] = 'province_id = :pid';
        $params[':pid'] = (int)$body['province_id'];
    }
    if (array_key_exists('zone_letter', $body)) {
        $zl = strtoupper(trim((string)$body['zone_letter'])) ?: null;
        if ($zl !== null && !preg_match('/^[A-Z]{1,5}$/', $zl)) {
            json_error('Zone letter must be 1–5 Latin letters', 422, 'bad_zone_letter');
        }
        $sets[] = 'zone_letter = :zl';
        $params[':zl'] = $zl;
    }
    if (!$sets) {
        json_error('Nothing to update', 400, 'nothing_to_update');
    }
    try {
        $st = $pdo->prepare("UPDATE districts SET " . implode(', ', $sets) . " WHERE id = :id
            RETURNING id, code, name_lo, name_en, province_id, zone_letter");
        $st->execute($params);
        $row = $st->fetch();
        if (!$row) {
            json_error('District not found', 404, 'not_found');
        }
        json_ok($row);
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') {
            if (str_contains($e->getMessage(), 'uq_districts_province_zone_letter')) {
                json_error('Zone letter is already used in this province', 409, 'duplicate_zone_letter');
            }
            json_error('District code already exists', 409, 'duplicate_code');
        }
        json_error('Update failed: ' . $e->getMessage(), 500, 'update_failed');
    }
}

if ($method === 'POST' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $st = $pdo->prepare("SELECT id, name_lo FROM districts WHERE id = :id");
    $st->execute([':id' => $id]);
    $district = $st->fetch();
    if (!$district) {
        json_error('District not found', 404, 'not_found');
    }
    $usage = district_usage($pdo, $id);
    $blockers = array_filter($usage, fn($n) => (int)$n > 0);
    if ($blockers) {
        $parts = [];
        foreach ($blockers as $what => $n) {
            $parts[] = $n . ' ' . $what;
        }
        json_error(
            'Cannot delete ' . $district['name_lo'] . ': still used by ' . implode(', ', $parts) . '.',
            409, 'in_use', $usage
        );
    }
    $pdo->prepare("DELETE FROM districts WHERE id = :id")->execute([':id' => $id]);
    json_ok(['deleted' => $district]);
}

json_error('Method not allowed', 405, 'method_not_allowed');