<?php
// API v1: parcels — GET list/search, POST create
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];
$me = require_auth(['citizen', 'officer', 'admin', 'manager']);
$staff = $me['role'] !== 'citizen';
$scope = staff_scope($me);

// ---- GET: list/search / fetch by id ----
if ($method === 'GET') {
    $district = $_GET['district_id'] ?? null;
    $parcel_no = $_GET['parcel_no'] ?? '';
    $status = $_GET['status'] ?? null;
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    // Citizens may look up a specific parcel they name, or their own, but must not be
    // able to enumerate the whole land registry.
    $mineOnly = !empty($_GET['mine']);
    if ($me['role'] === 'citizen' && !$id && $parcel_no === '' && !$mineOnly) {
        json_error('Specify a parcel number, or use mine=1 to list your own parcels', 403, 'listing_forbidden');
    }
    if ($mineOnly) {
        if ($me['role'] !== 'citizen') {
            json_error('mine=1 is only available to citizens', 400, 'bad_request');
        }
        $stmt = $pdo->prepare("SELECT p.*, d.name_lo AS district_name, v.name_lo AS village_name
            FROM parcels p
            JOIN districts d ON d.id = p.district_id
            JOIN villages v ON v.id = p.village_id
            WHERE p.id IN (SELECT po.parcel_id FROM parcel_owners po
                           JOIN owners o ON o.id = po.owner_id
                           WHERE o.citizen_user_id = :me)
            ORDER BY p.created_at DESC LIMIT 200");
        $stmt->execute([':me' => $me['sub']]);
        $rows = $stmt->fetchAll();
        json_ok(['parcels' => $rows, 'count' => count($rows)], 200);
    }

    if ($id) {
        $stmt = $pdo->prepare("SELECT p.*, d.name_lo AS district_name, v.name_lo AS village_name
            FROM parcels p JOIN districts d ON d.id = p.district_id JOIN villages v ON v.id = p.village_id
            WHERE p.id = :id");
        $stmt->execute([':id' => $id]);
        $rows = $stmt->fetchAll();
        if ($me['role'] === 'citizen') {
            $owns = (int)$pdo->query("SELECT COUNT(*) FROM parcel_owners po JOIN owners o ON o.id = po.owner_id
                WHERE o.citizen_user_id = " . (int)$me['sub'] . " AND po.parcel_id = " . $id)->fetchColumn();
            if (!$owns) {
                json_error('Forbidden', 403, 'forbidden');
            }
        }
        if ($staff && $scope['district_id'] !== null) {
            foreach ($rows as $row) {
                if ((int)$row['district_id'] !== $scope['district_id']) {
                    json_error('Parcel outside your district', 403, 'out_of_scope');
                }
            }
        }
        json_ok(['parcels' => $rows, 'count' => count($rows)], 200);
    }

    $where = [];
    $params = [];
    if ($district) { $where[] = 'p.district_id = :district'; $params[':district'] = (int)$district; }
    if ($parcel_no !== '') { $where[] = 'p.parcel_no ILIKE :parcel'; $params[':parcel'] = '%' . $parcel_no . '%'; }
    if ($status) { $where[] = 'p.status = :status'; $params[':status'] = $status; }
    if ($staff && $scope['district_id'] !== null) {
        $where[] = 'p.district_id = :scope_district';
        $params[':scope_district'] = $scope['district_id'];
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = $pdo->prepare("SELECT p.*, d.name_lo AS district_name, v.name_lo AS village_name
        FROM parcels p
        JOIN districts d ON d.id = p.district_id
        JOIN villages v ON v.id = p.village_id
        $whereSql
        ORDER BY p.updated_at DESC NULLS LAST, p.created_at DESC
        LIMIT 200");
    $stmt->execute($params);
    $parcels = $stmt->fetchAll();
    json_ok(['parcels' => $parcels, 'count' => count($parcels)], 200);
}

// ---- POST: create/update parcel with full data and owners ----
if ($method === 'POST') {
    require_staff_write($me);   // only district admin/officer create/update records
    $body = read_json_body();
    $parcelData = $body['parcel'] ?? [];
    $ownersData = $body['owners'] ?? [];
    
    // Extract fields
    $parcelNo = trim((string)($parcelData['parcel_no'] ?? ''));
    $subPar = trim((string)($parcelData['sub_parcel_no'] ?? '')) ?: null;
    $province = trim((string)($parcelData['province'] ?? '')) ?: 'Savannakhet';
    $area = $parcelData['area_sqm'] ?? '';
    $zone = trim((string)($parcelData['zone_code'] ?? '')) ?: null;
    $road = trim((string)($parcelData['road_category'] ?? '')) ?: null;
    $village = $parcelData['village_id'] ?? '';
    $district = $parcelData['district_id'] ?? '';
    $titleNo = trim((string)($parcelData['title_no'] ?? '')) ?: null;
    $titleType = trim((string)($parcelData['title_type'] ?? '')) ?: null;
    $bookNo = trim((string)($parcelData['book_no'] ?? '')) ?: null;
    $volumeNo = trim((string)($parcelData['volume_no'] ?? '')) ?: null;
    $pageNo = trim((string)($parcelData['page_no'] ?? '')) ?: null;
    $landUse = trim((string)($parcelData['land_use_class'] ?? '')) ?: null;
    // Full certificate field set (migration 005) — all optional.
    $orderNo = trim((string)($parcelData['order_no'] ?? '')) ?: null;
    $locationCode = trim((string)($parcelData['location_code'] ?? '')) ?: null;
    $unitNo = trim((string)($parcelData['unit_no'] ?? '')) ?: null;
    $acquisitionMethod = trim((string)($parcelData['acquisition_method'] ?? '')) ?: null;
    $ownershipStatus = trim((string)($parcelData['ownership_status'] ?? '')) ?: null;
    $zoneText = trim((string)($parcelData['zone_text'] ?? '')) ?: null;
    $mapRefNo = trim((string)($parcelData['map_ref_no'] ?? '')) ?: null;
    $mapParcelNo = trim((string)($parcelData['map_parcel_no'] ?? '')) ?: null;
    $mapScale = trim((string)($parcelData['map_scale'] ?? '')) ?: null;
    $issuedPlace = trim((string)($parcelData['issued_place'] ?? '')) ?: null;
    $issuedDate = null;
    if (($d = trim((string)($parcelData['issued_date'] ?? ''))) !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', $d);
        if ($dt && $dt->format('Y-m-d') === $d) $issuedDate = $d;
    }
    $status = trim((string)($parcelData['status'] ?? 'confirmed'));
    
    // Validation
    $errors = collect_errors([
        'parcel_no' => validate_required($parcelNo, 'parcel no'),
        'area_sqm' => validate_required($area, 'area') ?: validate_positive($area, 'area'),
        'district_id' => validate_required($district, 'district') ?: validate_int($district, 'district'),
        'village_id' => validate_required($village, 'village') ?: validate_int($village, 'village'),
    ]);
    reject_on_errors($errors);
    
    $districtId = (int)$district;
    $villageId = (int)$village;
    
    // Check district staff scope
    if ($scope['district_id'] !== null && $districtId !== $scope['district_id']) {
        json_error('You can only manage parcels in your own district', 403, 'out_of_scope');
    }
    
    // Check village belongs to district
    $st = $pdo->prepare("SELECT id FROM villages WHERE id = :id AND district_id = :did");
    $st->execute([':id' => $villageId, ':did' => $districtId]);
    if (!$st->fetch()) { json_error('Village does not belong to selected district', 422, 'bad_village'); }
    
    // Check if parcel exists (update mode)
    $existingId = (int)($parcelData['id'] ?? 0);
    
    // Build columns
    $cols = [
        ':pn' => $parcelNo, ':spn' => $subPar, ':prov' => $province, ':vid' => $villageId,
        ':did' => $districtId, ':a' => (float)$area, ':zc' => $zone, ':rc' => $road, 
        ':tn' => $titleNo, ':tt' => $titleType, ':bn' => $bookNo, ':von' => $volumeNo, 
        ':pg' => $pageNo, ':lu' => $landUse, ':idate' => $issuedDate, ':st' => $status,
        ':on' => $orderNo, ':lc' => $locationCode, ':un' => $unitNo,
        ':am' => $acquisitionMethod, ':os' => $ownershipStatus, ':zt' => $zoneText,
        ':mr' => $mapRefNo, ':mp' => $mapParcelNo, ':ms' => $mapScale, ':ip' => $issuedPlace,
    ];
    
    $parcelId = null;
    
    try {
        if ($existingId) {
            // Update existing
            $cols[':id'] = $existingId;
            $sql = "UPDATE parcels SET 
                parcel_no=:pn, sub_parcel_no=:spn, province=:prov, village_id=:vid, 
                district_id=:did, area_sqm=:a, zone_code=:zc, road_category=:rc, 
                title_no=:tn, title_type=:tt, book_no=:bn, volume_no=:von, page_no=:pg, 
                land_use_class=:lu, issued_date=:idate, status=:st,
                order_no=:on, location_code=:lc, unit_no=:un,
                acquisition_method=:am, ownership_status=:os, zone_text=:zt,
                map_ref_no=:mr, map_parcel_no=:mp, map_scale=:ms, issued_place=:ip,
                updated_at=CURRENT_TIMESTAMP 
                WHERE id=:id";
            $pdo->prepare($sql)->execute($cols);
            $parcelId = $existingId;
        } else {
            // Insert new
            $sql = "INSERT INTO parcels (
                parcel_no, sub_parcel_no, province, village_id, district_id, area_sqm, 
                zone_code, road_category, title_no, title_type, book_no, volume_no, page_no, 
                land_use_class, issued_date, status, created_at, updated_at,
                order_no, location_code, unit_no, acquisition_method, ownership_status,
                zone_text, map_ref_no, map_parcel_no, map_scale, issued_place
            ) VALUES (
                :pn, :spn, :prov, :vid, :did, :a, :zc, :rc, :tn, :tt, :bn, :von, :pg, 
                :lu, :idate, :st, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP,
                :on, :lc, :un, :am, :os, :zt, :mr, :mp, :ms, :ip
            ) RETURNING id";
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
    
    // Handle owners (replace existing owners with provided list)
    $ownerIds = [];
    $pdo->prepare("DELETE FROM parcel_owners WHERE parcel_id = :pid")->execute([':pid' => $parcelId]);
    
    foreach ($ownersData as $o) {
        $fullName = trim((string)($o['full_name'] ?? ''));
        if ($fullName === '') continue;
        
        $idNumber = trim((string)($o['id_number'] ?? '')) ?: null;
        $idType = trim((string)($o['id_type'] ?? '')) ?: null;
        $phone = trim((string)($o['phone'] ?? '')) ?: null;
        $address = trim((string)($o['address'] ?? '')) ?: null;
        $oVillage = !empty($o['village_id']) ? (int)$o['village_id'] : $villageId;
        $oDistrict = !empty($o['district_id']) ? (int)$o['district_id'] : $districtId;
        $ownerType = in_array($o['owner_type'] ?? '', ['individual', 'organization', 'joint', 'spouse'], true)
            ? $o['owner_type'] : 'individual';
        $role = in_array($o['role'] ?? '', ['owner', 'co_owner', 'representative'], true)
            ? $o['role'] : 'owner';
        $share = (isset($o['share_pct']) && $o['share_pct'] !== '') ? (float)$o['share_pct'] : null;
        // Certificate identity + structured address (migration 005) — all optional.
        $oDoB = null;
        if (($db = trim((string)($o['date_of_birth'] ?? ''))) !== '' &&
            ($dt = DateTime::createFromFormat('Y-m-d', $db)) && $dt->format('Y-m-d') === $db) {
            $oDoB = $db;
        }
        $oNat = trim((string)($o['nationality'] ?? '')) ?: null;
        $oOcc = trim((string)($o['occupation'] ?? '')) ?: null;
        $oAdV = trim((string)($o['address_village'] ?? '')) ?: null;
        $oAdU = trim((string)($o['address_unit'] ?? '')) ?: null;
        $oAdD = trim((string)($o['address_district'] ?? '')) ?: null;
        $oAdP = trim((string)($o['address_province'] ?? '')) ?: null;
        
        $existingOwnerId = !empty($o['id']) ? (int)$o['id'] : 0;
        
        if ($existingOwnerId) {
            $pdo->prepare("UPDATE owners SET full_name=:fn, id_number=:idn, id_type=:idt, phone=:ph,
                address=:ad, village_id=:vid, district_id=:did, owner_type=:ot,
                date_of_birth=:dob, nationality=:nat, occupation=:occ,
                address_village=:adv, address_unit=:adu, address_district=:add_, address_province=:adp,
                updated_at=CURRENT_TIMESTAMP
                WHERE id=:id")->execute([
                ':fn' => $fullName, ':idn' => $idNumber, ':idt' => $idType, ':ph' => $phone,
                ':ad' => $address, ':vid' => $oVillage, ':did' => $oDistrict, ':ot' => $ownerType,
                ':dob' => $oDoB, ':nat' => $oNat, ':occ' => $oOcc,
                ':adv' => $oAdV, ':adu' => $oAdU, ':add_' => $oAdD, ':adp' => $oAdP,
                ':id' => $existingOwnerId,
            ]);
            $ownerId = $existingOwnerId;
        } else {
            $st = $pdo->prepare("INSERT INTO owners (full_name, id_number, id_type, phone, address, village_id, district_id, owner_type,
                date_of_birth, nationality, occupation, address_village, address_unit, address_district, address_province)
                VALUES (:fn, :idn, :idt, :ph, :ad, :vid, :did, :ot,
                :dob, :nat, :occ, :adv, :adu, :add_, :adp) RETURNING id");
            $st->execute([
                ':fn' => $fullName, ':idn' => $idNumber, ':idt' => $idType, ':ph' => $phone,
                ':ad' => $address, ':vid' => $oVillage, ':did' => $oDistrict, ':ot' => $ownerType,
                ':dob' => $oDoB, ':nat' => $oNat, ':occ' => $oOcc,
                ':adv' => $oAdV, ':adu' => $oAdU, ':add_' => $oAdD, ':adp' => $oAdP,
            ]);
            $ownerId = (int)$st->fetch()['id'];
        }
        
        $pdo->prepare("INSERT INTO parcel_owners (parcel_id, owner_id, share_pct, role)
            VALUES (:pid, :oid, :sh, :role)
            ON CONFLICT (parcel_id, owner_id) DO UPDATE SET share_pct = EXCLUDED.share_pct, role = EXCLUDED.role")
            ->execute([':pid' => $parcelId, ':oid' => $ownerId, ':sh' => $share, ':role' => $role]);
        
        $ownerIds[] = $ownerId;
    }
    
    json_ok([
        'parcel_id' => $parcelId,
        'owner_ids' => $ownerIds,
        'status' => 'success'
    ]);
}
json_error('Method not allowed', 405, 'method_not_allowed');
