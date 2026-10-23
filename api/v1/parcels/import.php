<?php
// api/v1/parcels/import.php — CSV import and template download
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/auth.php';
require_once __DIR__ . '/../../lib/validate.php';

$pdo = db_connect();
$method = $_SERVER['REQUEST_METHOD'];
$me = require_auth(['officer', 'admin', 'manager']);
$scope = require_staff_write($me);

// ---- GET: Download import template ----
if ($method === 'GET' && isset($_GET['template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="parcel_import_template.csv"');
    header('Cache-Control: no-cache, no-store');
    
    // CSV header with column descriptions
    echo "parcel_no,sub_parcel_no,province,district_id,village_id,area_sqm,zone_code,road_category,title_no,title_type,book_no,volume_no,page_no,land_use_class,issued_date,full_name,id_number,id_type,phone,address,owner_type,share_pct,role\n";
    echo "# Description: parcel_no=required, village_id=required, area_sqm=required\n";
    echo "# district_id,village_id must be valid IDs from the districts/villages tables\n";
    echo "# road_category: main, branch, alley, none\n";
    echo "# title_type: land_title, survey_cert, concession, other\n";
    echo "# land_use_class: residential, agricultural, commercial, other\n";
    echo "# owner_type: individual, organization, joint\n";
    echo "# role: owner, co_owner, representative\n";
    echo "# issued_date: YYYY-MM-DD format\n";
    exit;
}

// ---- POST: Import CSV ----
if ($method === 'POST') {
    $body = read_json_body();
    $csv = $body['csv'] ?? '';
    $hasHeader = ($body['has_header'] ?? true) === true;
    
    if ($csv === '') {
        json_error('No CSV data provided', 400, 'no_csv');
    }
    
    // Parse CSV
    $lines = preg_split('/\r\n|\n|\r/', trim($csv));
    $lines = array_filter($lines);
    
    if (empty($lines)) {
        json_error('Empty CSV data', 400, 'empty_csv');
    }
    
    $header = null;
    $rows = [];
    
    $lineNum = 0;
    foreach ($lines as $line) {
        $lineNum++;
        
        // Parse CSV line (simple implementation - no quoted fields with commas)
        $fields = str_getcsv($line);
        
        if ($hasHeader && $header === null) {
            $header = array_map('trim', $fields);
            continue;
        }
        
        if (!$header) {
            // Create default header if no header row
            $header = [
                'parcel_no', 'sub_parcel_no', 'province', 'district_id', 'village_id',
                'area_sqm', 'zone_code', 'road_category', 'title_no', 'title_type',
                'book_no', 'volume_no', 'page_no', 'land_use_class', 'issued_date',
                'full_name', 'id_number', 'id_type', 'phone', 'address',
                'owner_type', 'share_pct', 'role'
            ];
        }
        
        // Create associative array
        $row = [];
        foreach ($header as $idx => $col) {
            $row[$col] = $fields[$idx] ?? '';
        }
        $rows[] = $row;
    }
    
    if (empty($rows)) {
        json_error('No data rows in CSV', 400, 'no_data');
    }
    
    // Process each row
    $successCount = 0;
    $errorCount = 0;
    $results = [];
    
    foreach ($rows as $row) {
        $lineNum++;
        
        // Basic validation
        $parcelNo = trim($row['parcel_no'] ?? '');
        $area = trim($row['area_sqm'] ?? '');
        $village = trim($row['village_id'] ?? '');
        $district = trim($row['district_id'] ?? '');
        
        if (!$parcelNo || !$area || !$village || !$district) {
            $errorCount++;
            $results[] = [
                'line' => $lineNum,
                'status' => 'error',
                'message' => 'Missing required fields: parcel_no, area_sqm, district_id, or village_id'
            ];
            continue;
        }
        
        // Validate numeric fields
        if (!is_numeric($area)) {
            $errorCount++;
            $results[] = [
                'line' => $lineNum,
                'status' => 'error',
                'message' => 'Invalid area_sqm: must be numeric'
            ];
            continue;
        }
        
        // Check village belongs to district
        $st = $pdo->prepare("SELECT id FROM villages WHERE id = :id AND district_id = :did");
        $st->execute([':id' => (int)$village, ':did' => (int)$district]);
        if (!$st->fetch()) {
            $errorCount++;
            $results[] = [
                'line' => $lineNum,
                'status' => 'error',
                'message' => 'Village does not belong to selected district'
            ];
            continue;
        }
        
        // Check district scope
        if ($scope['district_id'] !== null && (int)$district !== $scope['district_id']) {
            $errorCount++;
            $results[] = [
                'line' => $lineNum,
                'status' => 'error',
                'message' => 'You can only import parcels in your own district'
            ];
            continue;
        }
        
        // Build parcel data (similar to main parcels API)
        $parcelData = [
            'parcel_no' => $parcelNo,
            'sub_parcel_no' => $row['sub_parcel_no'] ?? '',
            'province' => trim($row['province'] ?? '') ?: 'Savannakhet',
            'district_id' => (int)$district,
            'village_id' => (int)$village,
            'area_sqm' => (float)$area,
            'zone_code' => $row['zone_code'] ?? '',
            'road_category' => $row['road_category'] ?? '',
            'title_no' => $row['title_no'] ?? '',
            'title_type' => $row['title_type'] ?? '',
            'book_no' => $row['book_no'] ?? '',
            'volume_no' => $row['volume_no'] ?? '',
            'page_no' => $row['page_no'] ?? '',
            'land_use_class' => $row['land_use_class'] ?? '',
            'issued_date' => $row['issued_date'] ?? ''
        ];
        
        // Check if parcel exists
        $existingId = 0;
        $st = $pdo->prepare("SELECT id FROM parcels WHERE parcel_no = :pn AND village_id = :vid");
        $st->execute([':pn' => $parcelNo, ':vid' => (int)$village]);
        if ($existing = $st->fetch()) {
            $existingId = (int)$existing['id'];
        }
        
        try {
            if ($existingId) {
                // Update existing
                $sql = "UPDATE parcels SET 
                    parcel_no=:pn, sub_parcel_no=:spn, province=:prov, village_id=:vid, 
                    district_id=:did, area_sqm=:a, zone_code=:zc, road_category=:rc, 
                    title_no=:tn, title_type=:tt, book_no=:bn, volume_no=:von, page_no=:pg, 
                    land_use_class=:lu, issued_date=:idate, updated_at=CURRENT_TIMESTAMP 
                    WHERE id=:id";
                $pdo->prepare($sql)->execute([
                    ':pn' => $parcelData['parcel_no'], ':spn' => $parcelData['sub_parcel_no'],
                    ':prov' => $parcelData['province'], ':vid' => $parcelData['village_id'],
                    ':did' => $parcelData['district_id'], ':a' => $parcelData['area_sqm'],
                    ':zc' => $parcelData['zone_code'] ?: null, ':rc' => $parcelData['road_category'] ?: null,
                    ':tn' => $parcelData['title_no'] ?: null, ':tt' => $parcelData['title_type'] ?: null,
                    ':bn' => $parcelData['book_no'] ?: null, ':von' => $parcelData['volume_no'] ?: null,
                    ':pg' => $parcelData['page_no'] ?: null, ':lu' => $parcelData['land_use_class'] ?: null,
                    ':idate' => $parcelData['issued_date'] ?: null,
                    ':id' => $existingId
                ]);
                $parcelId = $existingId;
                $action = 'updated';
            } else {
                // Insert new
                $sql = "INSERT INTO parcels (
                    parcel_no, sub_parcel_no, province, village_id, district_id, area_sqm, 
                    zone_code, road_category, title_no, title_type, book_no, volume_no, page_no, 
                    land_use_class, issued_date, status, created_at, updated_at
                ) VALUES (
                    :pn, :spn, :prov, :vid, :did, :a, :zc, :rc, :tn, :tt, :bn, :von, :pg, 
                    :lu, :idate, 'confirmed', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                ) RETURNING id";
                $st = $pdo->prepare($sql);
                $st->execute([
                    ':pn' => $parcelData['parcel_no'], ':spn' => $parcelData['sub_parcel_no'],
                    ':prov' => $parcelData['province'], ':vid' => $parcelData['village_id'],
                    ':did' => $parcelData['district_id'], ':a' => $parcelData['area_sqm'],
                    ':zc' => $parcelData['zone_code'] ?: null, ':rc' => $parcelData['road_category'] ?: null,
                    ':tn' => $parcelData['title_no'] ?: null, ':tt' => $parcelData['title_type'] ?: null,
                    ':bn' => $parcelData['book_no'] ?: null, ':von' => $parcelData['volume_no'] ?: null,
                    ':pg' => $parcelData['page_no'] ?: null, ':lu' => $parcelData['land_use_class'] ?: null,
                    ':idate' => $parcelData['issued_date'] ?: null,
                ]);
                $parcelId = (int)$st->fetch()['id'];
                $action = 'created';
            }
            
            $successCount++;
            $results[] = [
                'line' => $lineNum,
                'status' => 'success',
                'parcel_id' => $parcelId,
                'parcel_no' => $parcelNo,
                'action' => $action
            ];
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {
                $errorCount++;
                $results[] = [
                    'line' => $lineNum,
                    'status' => 'error',
                    'message' => 'Parcel with this number already exists in this village'
                ];
            } else {
                $errorCount++;
                $results[] = [
                    'line' => $lineNum,
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
        }
    }
    
    json_ok([
        'total' => count($rows),
        'created' => $successCount,
        'updated' => $successCount, // For simplicity, count all successes as created/updated
        'errors' => $errorCount,
        'results' => $results
    ]);
}

json_error('Method not allowed', 405, 'method_not_allowed');