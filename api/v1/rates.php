<?php
// API v1: valuation zones + rate matrix (zone × road category).
// See docs/11-zone-setup.md and Decree 142/ລົງ.
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];
$me = require_auth(['officer', 'admin', 'manager']);

/** Read the 4 rate values from either `rates: {main:…}` or flat body keys. */
function rate_body_values(array $body): array
{
    $out = [];
    $src = is_array($body['rates'] ?? null) ? $body['rates'] : $body;
    foreach (['main', 'branch', 'alley', 'none'] as $rc) {
        if (array_key_exists($rc, $src) && $src[$rc] !== '' && $src[$rc] !== null) {
            $out[$rc] = (float)$src[$rc];
        }
    }
    return $out;
}

/** Validate that a lookup row exists; returns false when it does not. */
function rate_ref_exists(PDO $pdo, string $table, string $column, $value): bool
{
    $st = $pdo->prepare("SELECT 1 FROM $table WHERE $column = :v");
    $st->execute([':v' => $value]);
    return (bool)$st->fetchColumn();
}

/** Insert/refresh one tier row for a zone. */
function rate_save_tier(PDO $pdo, string $code, string $rc, float $rate, string $validFrom): void
{
    $pdo->prepare("INSERT INTO zone_rate_tiers
            (zone_code, road_category, rate_per_sqm, valid_from, updated_at)
        VALUES (:zc, :rc, :rate, :vf, CURRENT_TIMESTAMP)
        ON CONFLICT (zone_code, road_category, valid_from) DO UPDATE
            SET rate_per_sqm = EXCLUDED.rate_per_sqm,
                updated_at   = CURRENT_TIMESTAMP")
        ->execute([':zc' => $code, ':rc' => $rc, ':rate' => $rate, ':vf' => $validFrom]);
}

/** Assign the given villages to a zone (others are detached). */
function rate_assign_villages(PDO $pdo, string $code, array $villageIds): void
{
    $ids = array_values(array_filter(array_map('intval', $villageIds), fn($v) => $v > 0));
    $pdo->prepare("UPDATE villages SET zone_id = NULL WHERE zone_id = ?")->execute([$code]);
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE villages SET zone_id = ? WHERE id IN ($ph)")
            ->execute(array_merge([$code], $ids));
    }
}

// ---- GET: list zones with district, planning zone and current rates ----
if ($method === 'GET') {
    $districtId = (int)($_GET['district_id'] ?? 0);
    $provinceId = (int)($_GET['province_id'] ?? 0);
    $where = [];
    $params = [];
    if ($provinceId) {
        $where[] = 'd.province_id = :pid';
        $params[':pid'] = $provinceId;
    }
    if ($districtId) {
        $where[] = 'z.district_id = :did';
        $params[':did'] = $districtId;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = $pdo->prepare("SELECT z.code, z.name_lo, z.name_en, z.rate_per_sqm,
            z.valid_from, z.is_active, z.note, z.updated_at,
            z.district_id, d.name_lo AS district_name, d.code AS district_code,
            d.province_id,
            z.plan_zone, p.name_lo AS plan_name, p.sort_order AS plan_sort,
            z.sort_order
        FROM zones z
        LEFT JOIN districts d ON d.id = z.district_id
        LEFT JOIN zone_plans p ON p.code = z.plan_zone
        $whereSql
        ORDER BY z.is_active DESC, d.code NULLS LAST, z.sort_order, z.code");
    $stmt->execute($params);
    $zones = $stmt->fetchAll();

    $tierStmt = $pdo->query("SELECT DISTINCT ON (zone_code, road_category)
            zone_code, road_category, rate_per_sqm, valid_from
        FROM zone_rate_tiers
        WHERE valid_from <= CURRENT_DATE
        ORDER BY zone_code, road_category, valid_from DESC, updated_at DESC");
    $tiers = [];
    foreach ($tierStmt->fetchAll() as $t) {
        $tiers[$t['zone_code']][$t['road_category']] = [
            'rate_per_sqm' => (float)$t['rate_per_sqm'],
            'valid_from'   => $t['valid_from'],
        ];
    }
    foreach ($zones as &$z) {
            $z['rates'] = [];
            foreach (['main', 'branch', 'alley', 'none'] as $rc) {
                $z['rates'][$rc] = $tiers[$z['code']][$rc]['rate_per_sqm']
                    ?? (float)$z['rate_per_sqm'];
            }
            $z['tiers'] = $tiers[$z['code']] ?? [];
            unset($z['plan_sort']);
        }
        unset($z);

        // Villages mapped to each zone (one zone covers several villages).
        $vilStmt = $pdo->query("SELECT zone_id, name_lo FROM villages
            WHERE zone_id IS NOT NULL ORDER BY name_lo");
        $vilMap = [];
        foreach ($vilStmt->fetchAll() as $v) {
            $vilMap[$v['zone_id']][] = $v['name_lo'];
        }
        foreach ($zones as &$z) {
            $z['villages'] = $vilMap[$z['code']] ?? [];
        }
        unset($z);
        json_ok(['zones' => $zones]);
    }

// ---- POST (no ?code): create a zone with its 4 rates ----
if ($method === 'POST' && !isset($_GET['code'])) {
    $body    = read_json_body();
    $code    = strtoupper(trim((string)($body['code'] ?? '')));
    $nameLo  = trim((string)($body['name_lo'] ?? ''));
    $nameEn  = trim((string)($body['name_en'] ?? ''));
    $districtId = (int)($body['district_id'] ?? 0) ?: null;
    $planZone   = trim((string)($body['plan_zone'] ?? '')) ?: null;
    $sortOrder  = (int)($body['sort_order'] ?? 0);
    $validFrom  = trim((string)($body['valid_from'] ?? '')) ?: date('Y-m-d');
    $rates      = rate_body_values($body);
    if (!$rates && isset($body['rate_per_sqm'])) {
        $legacy = (float)$body['rate_per_sqm'];
        $rates = array_fill_keys(['main', 'branch', 'alley', 'none'], $legacy);
    }

    $err = collect_errors([
        'code'    => validate_required($code, 'zone code'),
        'name_lo' => validate_required($nameLo, 'Lao name'),
    ]);
    if (!$rates) {
        $err['rates'] = 'At least one road-access rate is required';
    }
    reject_on_errors($err);

    if ($districtId && !rate_ref_exists($pdo, 'districts', 'id', $districtId)) {
        json_error('District not found', 422, 'bad_district');
    }
    if ($planZone && !rate_ref_exists($pdo, 'zone_plans', 'code', $planZone)) {
        json_error('Valuation zone not found', 422, 'bad_plan');
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO zones
                (code, name_lo, name_en, rate_per_sqm, valid_from, note,
                 district_id, plan_zone, sort_order)
            VALUES (:code, :nlo, :nen, :rate, :vf, :note, :did, :pz, :so)
            RETURNING code, name_lo, name_en, rate_per_sqm, valid_from, is_active, note,
                      district_id, plan_zone, sort_order");
        $stmt->execute([
            ':code' => $code,
            ':nlo'  => $nameLo,
            ':nen'  => $nameEn ?: null,
            ':rate' => $rates['none'] ?? reset($rates),
            ':vf'   => $validFrom,
            ':note' => $body['note'] ?? null,
            ':did'  => $districtId,
            ':pz'   => $planZone,
            ':so'   => $sortOrder,
        ]);
        $row = $stmt->fetch();
                foreach ($rates as $rc => $rate) {
                    rate_save_tier($pdo, $code, $rc, $rate, $validFrom);
                }
                rate_assign_villages($pdo, $code, $body['village_ids'] ?? []);
                $pdo->commit();
                json_ok($row, 201);
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e->getCode() === '23505') {
            json_error('Zone ' . $code . ' already exists', 409, 'duplicate_code');
        }
        json_error('Create failed: ' . $e->getMessage(), 500, 'create_failed');
    }
}

// ---- PUT ?code=: update zone fields and/or its rates ----
if ($method === 'PUT') {
    $code = strtoupper(trim((string)($_GET['code'] ?? '')));
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
    if (array_key_exists('district_id', $body)) {
        $did = (int)$body['district_id'] ?: null;
        if ($did && !rate_ref_exists($pdo, 'districts', 'id', $did)) {
            json_error('District not found', 422, 'bad_district');
        }
        $sets[] = 'district_id = :did';
        $params[':did'] = $did;
    }
    if (array_key_exists('plan_zone', $body)) {
        $pz = trim((string)$body['plan_zone']) ?: null;
        if ($pz && !rate_ref_exists($pdo, 'zone_plans', 'code', $pz)) {
            json_error('Valuation zone not found', 422, 'bad_plan');
        }
        $sets[] = 'plan_zone = :pz';
        $params[':pz'] = $pz;
    }
    if (array_key_exists('sort_order', $body)) {
        $sets[] = 'sort_order = :so';
        $params[':so'] = (int)$body['sort_order'];
    }
    if (array_key_exists('is_active', $body)) {
        $sets[] = 'is_active = :act';
        $params[':act'] = $body['is_active'] ? true : false;
    }

    $rates = rate_body_values($body);
    if ($rates) {
        // Keep the legacy fallback column in sync with the lowest tier.
        $sets[] = 'rate_per_sqm = :fallback';
        $params[':fallback'] = $rates['none'] ?? reset($rates);
    }
    if (!$sets && !$rates) {
        json_error('Nothing to update', 400, 'nothing_to_update');
    }

    try {
        $pdo->beginTransaction();
        if ($sets) {
            $sets[] = 'updated_at = CURRENT_TIMESTAMP';
            $stmt = $pdo->prepare("UPDATE zones SET " . implode(', ', $sets) . "
                WHERE code = :code
                RETURNING code, name_lo, name_en, rate_per_sqm, is_active, note,
                          district_id, plan_zone, sort_order");
            $stmt->execute($params);
            if (!$stmt->fetch()) {
                $pdo->rollBack();
                json_error('Zone not found', 404, 'not_found');
            }
        }
        $validFrom = trim((string)($body['valid_from'] ?? '')) ?: date('Y-m-d');
                foreach ($rates as $rc => $rate) {
                    rate_save_tier($pdo, $code, $rc, $rate, $validFrom);
                }
                if (array_key_exists('village_ids', $body)) {
                    rate_assign_villages($pdo, $code, $body['village_ids']);
                }
                $pdo->commit();
                json_ok(['code' => $code, 'rates' => $rates]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_error('Update failed: ' . $e->getMessage(), 500, 'update_failed');
    }
}

// ---- POST ?code=: delete a zone ----
if ($method === 'POST' && isset($_GET['code'])) {
    $code = strtoupper(trim((string)$_GET['code']));
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
