<?php
// API v1: scans/confirm.php?id={id} — officer confirms/edits a scan, creating or updating the
// parcel (full title content) plus its owner(s). Nothing is auto-committed: this is the only
// write path from a scan to a parcel.
require_once __DIR__ . '/../../../lib/db.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/validate.php';
require_once __DIR__ . '/../../../lib/fee.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];
$me = require_auth(['officer', 'admin', 'manager']);
$scope = require_staff_write($me);   // only district admin/officer confirm scans

$id = (int)($_GET['id'] ?? '0');
if (!$id) { json_error('Scan id required', 400, 'missing_id'); }

$stmt = $pdo->prepare("SELECT ts.id, ts.parcel_id, ts.district_id, p.district_id AS parcel_district
                       FROM title_scans ts LEFT JOIN parcels p ON p.id = ts.parcel_id
                       WHERE ts.id = :id");
$stmt->execute([':id' => $id]);
$scan = $stmt->fetch();
if (!$scan) { json_error('Scan not found', 404, 'not_found'); }
// District staff may only confirm scans that belong to their own district.
$scanDistrict = $scan['district_id'] !== null ? (int)$scan['district_id']
    : ($scan['parcel_district'] !== null ? (int)$scan['parcel_district'] : null);
if ($scope['district_id'] !== null && $scanDistrict !== $scope['district_id']) {
    json_error('Scan outside your district', 403, 'out_of_scope');
}

if ($method !== 'POST') { json_error('Method not allowed', 405, 'method_not_allowed'); }

/** Only set a reference code if it actually exists in the lookup table (guards the FK). */
function ref_code_exists(PDO $pdo, string $table, $code): bool {
    if ($code === null || $code === '') return false;
    $allowed = ['title_types', 'land_use_classes', 'road_categories'];
    if (!in_array($table, $allowed, true)) return false;
    $st = $pdo->prepare("SELECT 1 FROM $table WHERE code = :c");
    $st->execute([':c' => $code]);
    return (bool)$st->fetchColumn();
}

$body = read_json_body();
$parcel = $body['parcel'] ?? [];
$owners = is_array($body['owners'] ?? null) ? $body['owners'] : [];

$parcelNo = trim((string)($parcel['parcel_no'] ?? ''));
$area     = $parcel['area_sqm'] ?? '';
$zone     = strtoupper(trim((string)($parcel['zone_code'] ?? '')));
$road     = strtolower(trim((string)($parcel['road_category'] ?? 'none')));
$village  = $parcel['village_id'] ?? '';
$district = $parcel['district_id'] ?? '';
$status   = $parcel['status'] ?? 'confirmed';
$subPar   = trim((string)($parcel['sub_parcel_no'] ?? '')) ?: null;

$errors = collect_errors([
    'parcel_no'     => validate_required($parcelNo, 'parcel no'),
    'area_sqm'      => validate_required($area, 'area') ?: validate_positive($area, 'area'),
    'zone_code'     => validate_required($zone, 'zone code'),
    'road_category' => validate_enum($road, ['main', 'branch', 'alley', 'none'], 'road access'),
    'village_id'    => validate_required($village, 'village') ?: validate_int($village, 'village'),
    'district_id'   => validate_required($district, 'district') ?: validate_int($district, 'district'),
    'status'        => validate_enum($status, ['draft', 'scanned', 'confirmed', 'active', 'archived'], 'status'),
]);
reject_on_errors($errors);

$districtId = (int)$district;
$villageId  = (int)$village;

// District staff may only place a confirmed parcel in their own district.
if ($scope['district_id'] !== null && $districtId !== $scope['district_id']) {
    json_error('You can only confirm parcels into your own district', 403, 'out_of_scope');
}

$st = $pdo->prepare("SELECT id FROM villages WHERE id = :id AND district_id = :did");
$st->execute([':id' => $villageId, ':did' => $districtId]);
if (!$st->fetch()) { json_error('Village does not belong to selected district', 422, 'bad_village'); }

$st = $pdo->prepare("SELECT district_id FROM zones WHERE code = :zc AND is_active = TRUE");
$st->execute([':zc' => $zone]);
$zoneRow = $st->fetch();
if (!$zoneRow) { json_error('Valuation zone not found or inactive: ' . $zone, 422, 'bad_zone'); }
if ($zoneRow['district_id'] !== null && (int)$zoneRow['district_id'] !== $districtId) {
    json_error('Valuation zone does not belong to the selected district', 422, 'zone_district_mismatch');
}

// Optional title metadata (blank -> NULL). Reference codes are FK-checked.
$province  = trim((string)($parcel['province'] ?? '')) ?: 'Savannakhet';
$titleNo   = trim((string)($parcel['title_no'] ?? '')) ?: null;
$titleType = ref_code_exists($pdo, 'title_types', trim((string)($parcel['title_type'] ?? '')))
    ? trim((string)$parcel['title_type']) : null;
$bookNo    = trim((string)($parcel['book_no'] ?? '')) ?: null;
$volumeNo  = trim((string)($parcel['volume_no'] ?? '')) ?: null;
$pageNo    = trim((string)($parcel['page_no'] ?? '')) ?: null;
$landUse   = ref_code_exists($pdo, 'land_use_classes', trim((string)($parcel['land_use_class'] ?? '')))
    ? trim((string)$parcel['land_use_class']) : null;
$issuedDate = null;
if (($d = trim((string)($parcel['issued_date'] ?? ''))) !== '') {
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    if ($dt && $dt->format('Y-m-d') === $d) $issuedDate = $d;
}

$cols = [
    ':pn'  => $parcelNo, ':spn' => $subPar, ':prov' => $province, ':vid' => $villageId,
    ':did' => $districtId, ':a' => (float)$area, ':zc' => $zone, ':rc' => $road, ':tn' => $titleNo,
    ':tt'  => $titleType, ':bn' => $bookNo, ':von' => $volumeNo, ':pg' => $pageNo,
    ':lu'  => $landUse, ':idate' => $issuedDate, ':st' => $status,
];

try {
    if (!empty($scan['parcel_id'])) {
        $cols[':id'] = (int)$scan['parcel_id'];
        $sql = "UPDATE parcels SET parcel_no=:pn, sub_parcel_no=:spn, province=:prov, village_id=:vid,
                district_id=:did, area_sqm=:a, zone_code=:zc, road_category=:rc, title_no=:tn, title_type=:tt,
                book_no=:bn, volume_no=:von, page_no=:pg, land_use_class=:lu, issued_date=:idate,
                status=:st, scanned_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP, ocr_confidence=0
                WHERE id=:id";
        $pdo->prepare($sql)->execute($cols);
        $parcelId = (int)$scan['parcel_id'];
    } else {
        $sql = "INSERT INTO parcels (parcel_no, sub_parcel_no, province, village_id, district_id, area_sqm,
                zone_code, road_category, title_no, title_type, book_no, volume_no, page_no, land_use_class, issued_date,
                status, scanned_at, ocr_confidence)
                VALUES (:pn, :spn, :prov, :vid, :did, :a, :zc, :rc, :tn, :tt, :bn, :von, :pg, :lu, :idate,
                :st, CURRENT_TIMESTAMP, 0) RETURNING id";
        $st = $pdo->prepare($sql);
        $st->execute($cols);
        $parcelId = (int)$st->fetch()['id'];
    }
} catch (PDOException $e) {
    if ($e->getCode() === '23505') {
        json_error('A parcel with this number already exists in this village', 409, 'duplicate_parcel');
    }
    json_error('Failed to save parcel: ' . $e->getMessage(), 500, 'parcel_failed');
}

// ---- Owners: replace the parcel's owner set with the provided list ----
$ownerIds = [];
$pdo->prepare("DELETE FROM parcel_owners WHERE parcel_id = :pid")->execute([':pid' => $parcelId]);
foreach ($owners as $o) {
    $fullName = trim((string)($o['full_name'] ?? ''));
    if ($fullName === '') continue;
    $idNumber = trim((string)($o['id_number'] ?? '')) ?: null;
    $idType   = trim((string)($o['id_type'] ?? '')) ?: null;
    $phone    = trim((string)($o['phone'] ?? '')) ?: null;
    $address  = trim((string)($o['address'] ?? '')) ?: null;
    $oVillage = !empty($o['village_id']) ? (int)$o['village_id'] : $villageId;
    $oDistrict = !empty($o['district_id']) ? (int)$o['district_id'] : $districtId;
    $ownerType = in_array($o['owner_type'] ?? '', ['individual', 'organization', 'joint'], true)
        ? $o['owner_type'] : 'individual';
    $role = in_array($o['role'] ?? '', ['owner', 'co_owner', 'representative'], true)
        ? $o['role'] : 'owner';
    $share = (isset($o['share_pct']) && $o['share_pct'] !== '') ? (float)$o['share_pct'] : null;

    $existingId = !empty($o['id']) ? (int)$o['id'] : 0;
    if ($existingId) {
        $pdo->prepare("UPDATE owners SET full_name=:fn, id_number=:idn, id_type=:idt, phone=:ph,
            address=:ad, village_id=:vid, district_id=:did, owner_type=:ot, updated_at=CURRENT_TIMESTAMP
            WHERE id=:id")->execute([
            ':fn' => $fullName, ':idn' => $idNumber, ':idt' => $idType, ':ph' => $phone,
            ':ad' => $address, ':vid' => $oVillage, ':did' => $oDistrict, ':ot' => $ownerType,
            ':id' => $existingId,
        ]);
        $ownerId = $existingId;
    } else {
        $st = $pdo->prepare("INSERT INTO owners (full_name, id_number, id_type, phone, address, village_id, district_id, owner_type)
            VALUES (:fn, :idn, :idt, :ph, :ad, :vid, :did, :ot) RETURNING id");
        $st->execute([
            ':fn' => $fullName, ':idn' => $idNumber, ':idt' => $idType, ':ph' => $phone,
            ':ad' => $address, ':vid' => $oVillage, ':did' => $oDistrict, ':ot' => $ownerType,
        ]);
        $ownerId = (int)$st->fetch()['id'];
    }
    $pdo->prepare("INSERT INTO parcel_owners (parcel_id, owner_id, share_pct, role)
        VALUES (:pid, :oid, :sh, :role)
        ON CONFLICT (parcel_id, owner_id) DO UPDATE SET share_pct = EXCLUDED.share_pct, role = EXCLUDED.role")
        ->execute([':pid' => $parcelId, ':oid' => $ownerId, ':sh' => $share, ':role' => $role]);
    $ownerIds[] = $ownerId;
}

// ---- Link the scan to the parcel and mark it confirmed ----
$pdo->prepare("UPDATE title_scans SET parcel_id=:pid, status='confirmed', district_id=:did, verified_by=:v,
    verified_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=:id")
    ->execute([':pid' => $parcelId, ':did' => $districtId, ':v' => $me['sub'], ':id' => $id]);

// ---- Assess the current-year obligation so the fee is visible immediately ----
$obligationId = null;
if ($status === 'confirmed' || $status === 'active') {
    try {
        $ob = fee_upsert_obligation($pdo, $parcelId, (int)date('Y'));
        if ($ob) {
            $obligationId = (int)$ob['id'];
        }
    } catch (\Throwable $e) {
        // never fail the confirm because of the fee layer
    }
}

json_ok([
    'parcel_id'    => $parcelId,
    'owner_ids'    => $ownerIds,
    'obligation_id' => $obligationId,
    'status'       => 'confirmed',
], 200);
