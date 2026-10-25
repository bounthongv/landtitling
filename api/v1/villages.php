<?php
// API v1: villages — public GET by district + admin-only create/update/delete.
// Villages are the fuzziest reference data (1,029 seeded), so the UI needs search
// as well as create/edit, hence the q/district_id filters.
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];

// ---- GET: list (public) ----
if ($method === 'GET') {
    $district_id  = isset($_GET['district_id']) ? (int)$_GET['district_id'] : 0;
    $province_id  = isset($_GET['province_id']) ? (int)$_GET['province_id'] : 0;
    $q = trim((string)($_GET['q'] ?? ''));
    $limit = min(500, max(1, (int)($_GET['limit'] ?? 500)));

    $where = [];
    $params = [];
    if ($province_id) {
        // Narrow by province: districts belong to a province (districts.province_id).
        $where[] = 'd.province_id = :pid';
        $params[':pid'] = $province_id;
    }
    // District staff see only their own district's villages.
    if ($scope['district_id'] !== null) {
        $where[] = 'v.district_id = :scope_did';
        $params[':scope_did'] = $scope['district_id'];
    } elseif ($district_id) {
        $where[] = 'v.district_id = :did';
        $params[':did'] = $district_id;
    }
    if ($q !== '') {
        $where[] = '(v.name_lo ILIKE :q OR v.name_en ILIKE :q OR v.code ILIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Sortable columns, whitelisted to a fixed expression map (the request key is
    // only ever used as a lookup, never interpolated). With no ?sort= the ordering
    // is unchanged (district, then village). v.code is a stable tiebreaker.
    $sortMap = [
        'code'     => 'v.code',
        'name_lo'  => 'v.name_lo',
        'name_en'  => 'v.name_en',
        'province' => 'p.name_lo',
        'district' => 'd.name_lo',
        'parcels'  => 'parcel_count',
    ];
    $sortKey = trim((string)($_GET['sort'] ?? ''));
    if (isset($sortMap[$sortKey])) {
        $dir = strtolower(trim((string)($_GET['dir'] ?? 'asc'))) === 'desc' ? 'DESC' : 'ASC';
        $orderBy = $sortMap[$sortKey] . ' ' . $dir . ' NULLS LAST, v.code ASC';
    } else {
        $orderBy = 'd.name_lo, v.name_lo';
    }

    $st = $pdo->prepare("SELECT v.id, v.district_id, v.code, v.name_lo, v.name_en, v.zone_id,
            d.name_lo AS district_name, d.province_id AS province_id,
            p.name_lo AS province_name,
            (SELECT COUNT(*) FROM parcels p WHERE p.village_id = v.id) AS parcel_count
        FROM villages v JOIN districts d ON d.id = v.district_id
        LEFT JOIN provinces p ON p.id = d.province_id
        $w ORDER BY $orderBy LIMIT $limit");
    $st->execute($params);
    json_ok($st->fetchAll());
}

// ---- Writes: admin only (province level) ---
$me = require_auth(['admin', 'officer', 'manager']);
$scope = staff_scope($me);

// District staff cannot manage villages — that's a province-level function.
if ($scope['district_id'] !== null && $method !== 'GET') {
    json_error('Village management is a province-level function', 403, 'read_only');
}

if ($method === 'POST' && !isset($_GET['id'])) {
    $body = read_json_body();
    $code       = trim((string)($body['code'] ?? ''));
    $nameLo     = trim((string)($body['name_lo'] ?? ''));
    $nameEn     = trim((string)($body['name_en'] ?? ''));
    $districtId = (int)($body['district_id'] ?? 0);

    $err = collect_errors([
        'code'        => validate_required($code, 'code'),
        'name_lo'     => validate_required($nameLo, 'Lao name'),
        'district_id' => validate_required($districtId, 'district') ?: validate_int($districtId, 'district'),
    ]);
    reject_on_errors($err);

    $st = $pdo->prepare("SELECT 1 FROM districts WHERE id = :id");
    $st->execute([':id' => $districtId]);
    if (!$st->fetch()) {
        json_error('District not found', 422, 'bad_district');
    }

    try {
        $st = $pdo->prepare("INSERT INTO villages (district_id, code, name_lo, name_en)
            VALUES (:did, :code, :nlo, :nen) RETURNING id, district_id, code, name_lo, name_en");
        $st->execute([':did' => $districtId, ':code' => $code, ':nlo' => $nameLo, ':nen' => $nameEn ?: null]);
        json_ok($st->fetch(), 201);
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') {
            json_error('Village code ' . $code . ' already exists', 409, 'duplicate_code');
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
    if (array_key_exists('district_id', $body) && (int)$body['district_id'] > 0) {
        $sets[] = 'district_id = :did';
        $params[':did'] = (int)$body['district_id'];
    }
    if (!$sets) {
        json_error('Nothing to update', 400, 'nothing_to_update');
    }
    try {
        $st = $pdo->prepare("UPDATE villages SET " . implode(', ', $sets) . " WHERE id = :id
            RETURNING id, district_id, code, name_lo, name_en");
        $st->execute($params);
        $row = $st->fetch();
        if (!$row) {
            json_error('Village not found', 404, 'not_found');
        }
        json_ok($row);
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') {
            json_error('Village code already exists', 409, 'duplicate_code');
        }
        json_error('Update failed: ' . $e->getMessage(), 500, 'update_failed');
    }
}

if ($method === 'POST' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $st = $pdo->prepare("SELECT id, name_lo FROM villages WHERE id = :id");
    $st->execute([':id' => $id]);
    $village = $st->fetch();
    if (!$village) {
        json_error('Village not found', 404, 'not_found');
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM parcels WHERE village_id = :id");
    $st->execute([':id' => $id]);
    $parcels = (int)$st->fetchColumn();
    if ($parcels > 0) {
        json_error(
            'Cannot delete ' . $village['name_lo'] . ': still used by ' . $parcels . ' parcel(s).',
            409, 'in_use', ['parcels' => $parcels]
        );
    }
    $pdo->prepare("DELETE FROM villages WHERE id = :id")->execute([':id' => $id]);
    json_ok(['deleted' => $village]);
}

json_error('Method not allowed', 405, 'method_not_allowed');