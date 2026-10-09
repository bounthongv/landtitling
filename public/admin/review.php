<?php session_start(); ?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <title>Land Fee MVP - Officer Review</title>
    <style>
        body { font-family: sans-serif; margin: 20px; }
        .scan-container { display: flex; gap: 20px; margin-bottom: 20px; }
        .scan-image { max-width: 400px; border: 1px solid #ccc; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select { width: 100%; padding: 8px; box-sizing: border-box; }
        button { background-color: #4CAF50; color: white; padding: 10px 15px; border: none; cursor: pointer; }
        button:hover { background-color: #45a049; }
        .btn-secondary { background-color: #2196F3; }
        .btn-secondary:hover { background-color: #0b7dda; }
        .btn-danger { background-color: #f44336; }
        .btn-danger:hover { background-color: #da190b; }
        .hidden { display: none; }
        .result { margin-top: 20px; padding: 15px; background-color: #f0f0f0; border-radius: 5px; }
        .upload-form { margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; background-color: #f9f9f9; }
    </style>
</head>
<body>
    <h1>Land Fee MVP - Officer Review</h1>

    <?php
    // Handle upload
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['image'])) {
        require_once __DIR__ . '/../../lib/config.php';
        require_once __DIR__ . '/../../lib/ocr.php';
        require_once __DIR__ . '/../../lib/db.php';

        $file = $_FILES['image'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
            $error = 'Invalid image type';
        } else {
            // Save upload
            $config = require __DIR__ . '/../../lib/config.php';
            $uploadDir = $config['uploads'];
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $filename = uniqid('scan_', true) . '.' . $ext;
            $destPath = $uploadDir . $filename;
            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                // Run OCR
                $ocrResult = ocr_extract_from_image($destPath);
                if (isset($ocrResult['error'])) {
                    $error = $ocrResult['error'];
                } else {
                    // Store scan data in session
                    $_SESSION['current_scan'] = [
                        'image_path' => $destPath,
                        'ocr_text' => $ocrResult['text'] ?? ''
                    ];
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                }
            } else {
                $error = 'Failed to save image';
            }
        }
    }

    // Handle form submission for parcel confirmation
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_parcel'])) {
        require_once __DIR__ . '/../../lib/db.php';
        $pdo = db_connect();

        $data = [];
        $data['parcel_no'] = $_POST['parcel_no'] ?? '';
        $data['sub_parcel_no'] = $_POST['sub_parcel_no'] ?? null;
        $data['area_sqm'] = $_POST['area_sqm'] ?? 0;
        $data['road_category'] = $_POST['road_category'] ?? null;
        $data['status'] = $_POST['status'] ?? 'confirmed';
        $data['village_id'] = $_POST['village_id'] ?? null;
        $data['district_id'] = $_POST['district_id'] ?? null;

        // Validate
        $errors = [];
        if (empty($data['parcel_no'])) $errors[] = 'Parcel number is required';
        if (empty($data['area_sqm']) || !is_numeric($data['area_sqm']) || $data['area_sqm'] <= 0) $errors[] = 'Area must be a positive number';
        if (empty($data['road_category'])) $errors[] = 'Road category is required';
        if (empty($data['village_id'])) $errors[] = 'Village is required';
        if (empty($data['district_id'])) $errors[] = 'District is required';

        if (empty($errors)) {
            $stmt = $pdo->prepare("
                INSERT INTO parcels (parcel_no, sub_parcel_no, village_id, district_id, area_sqm, road_category, status, scanned_at, ocr_confidence)
                VALUES (:parcel_no, :sub_parcel_no, :village_id, :district_id, :area_sqm, :road_category, :status, :scanned_at, :ocr_confidence)
            ");
            $result = $stmt->execute([
                'parcel_no' => $data['parcel_no'],
                'sub_parcel_no' => $data['sub_parcel_no'],
                'village_id' => $data['village_id'],
                'district_id' => $data['district_id'],
                'area_sqm' => $data['area_sqm'],
                'road_category' => $data['road_category'],
                'status' => $data['status'],
                'scanned_at' => null,
                'ocr_confidence' => null
            ]);

            if ($result) {
                $newId = $pdo->lastInsertId();
                unset($_SESSION['current_scan']);
                $success = "Parcel created successfully! ID: $newId";
            } else {
                $error = 'Failed to create parcel';
            }
        } else {
            $error = implode('<br>', $errors);
        }
    }
    ?>

    <?php if (!empty($error)): ?>
        <div style="color: red; margin-bottom: 20px;">
            <strong>Error:</strong> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div style="color: green; margin-bottom: 20px;">
            <strong>Success:</strong> <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if (empty($_SESSION['current_scan'])): ?>
        <div class="upload-form">
            <h2>Upload a Land Title Scan</h2>
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="image">Select a land title image (JPG, PNG, GIF)</label>
                    <input type="file" name="image" id="image" accept="image/*" required>
                </div>
                <button type="submit">Upload and Process</button>
            </form>
            <p>After uploading, the OCR text will be extracted and you can confirm or edit the parcel data.</p>
        </div>
    <?php else: ?>
        <?php $scan = $_SESSION['current_scan']; ?>
        <div class="scan-container">
            <div>
                <h2>Latest Scan</h2>
                <img id="scan-image" class="scan-image" src="<?= htmlspecialchars($scan['image_path']) ?>" alt="Scan image">
            </div>
            <div>
                <h2>OCR Extracted Text</h2>
                <pre id="ocr-text" style="background-color: #f9f9f9; padding: 10px; border: 1px solid #ddd; max-height: 200px; overflow-y: auto;">
                    <?= htmlspecialchars($scan['ocr_text']) ?>
                </pre>
            </div>
        </div>

        <div id="review-form">
            <h2>Confirm / Edit Parcel Data</h2>
            <form method="POST">
                <input type="hidden" name="confirm_parcel" value="1">
                <div class="form-group">
                    <label for="parcel_no">Parcel Number</label>
                    <input type="text" id="parcel_no" name="parcel_no" value="" required>
                </div>
                <div class="form-group">
                    <label for="sub_parcel_no">Sub Parcel Number (optional)</label>
                    <input type="text" id="sub_parcel_no" name="sub_parcel_no" value="">
                </div>
                <div class="form-group">
                    <label for="area_sqm">Area (sqm)</label>
                    <input type="number" id="area_sqm" name="area_sqm" step="0.01" value="" required>
                </div>
                <div class="form-group">
                    <label for="road_category">Road Category</label>
                    <select id="road_category" name="road_category" required>
                        <option value="">-- Select --</option>
                        <option value="main">Main Road</option>
                        <option value="branch">Branch Road</option>
                        <option value="alley">Alley</option>
                        <option value="none">None</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="district_id">District</label>
                    <select id="district_id" name="district_id" required>
                        <option value="">-- Select District --</option>
                        <!-- Options will be filled via JavaScript from a list of districts -->
                    </select>
                </div>
                <div class="form-group">
                    <label for="village_id">Village</label>
                    <select id="village_id" name="village_id" required>
                        <option value="">-- Select Village --</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" required>
                        <option value="confirmed">Confirmed</option>
                        <option value="active">Active</option>
                    </select>
                </div>
                <button type="submit" id="submit-btn">Confirm and Create Parcel</button>
                <button type="button" id="new-scan-btn" class="btn-secondary">New Scan</button>
            </form>
        </div>

        <script>
            // Fetch districts and villages from the API
            async function fetchDistricts() {
                try {
                    const response = await fetch('/api/districts.php'); // We need to create this endpoint
                    if (!response.ok) throw new Error('Failed to fetch districts');
                    return await response.json();
                } catch (error) {
                    console.error('Error fetching districts:', error);
                    return [];
                }
            }

            async function fetchVillages(districtId) {
                try {
                    const response = await fetch(`/api/villages.php?district_id=${districtId}`); // We need to create this endpoint
                    if (!response.ok) throw new Error('Failed to fetch villages');
                    return await response.json();
                } catch (error) {
                    console.error('Error fetching villages:', error);
                    return [];
                }
            }

            // Initialize the form
            document.addEventListener('DOMContentLoaded', async () => {
                const districtSelect = document.getElementById('district_id');
                const villageSelect = document.getElementById('village_id');
                const newScanBtn = document.getElementById('new-scan-btn');

                // Load districts
                const districts = await fetchDistricts();
                districts.forEach(d => {
                    const option = document.createElement('option');
                    option.value = d.id;
                    option.textContent = `${d.name_lo} (${d.name_en || ''})`;
                    districtSelect.appendChild(option);
                });

                // When district changes, load villages
                districtSelect.addEventListener('change', async () => {
                    const districtId = districtSelect.value;
                    villageSelect.innerHTML = '<option value="">-- Select Village --</option>';
                    if (districtId) {
                        const villages = await fetchVillages(districtId);
                        villages.forEach(v => {
                            const option = document.createElement('option');
                            option.value = v.id;
                            option.textContent = `${v.name_lo} (${v.name_en || ''})`;
                            villageSelect.appendChild(option);
                        });
                    }
                });

                // New scan button
                newScanBtn.addEventListener('click', () => {
                    // Remove current scan from session by reloading the page (which will show the upload form)
                    // We'll do a simple redirect to the same page, which will show the upload form because we unset the session when creating a parcel.
                    // But we want to keep the session until we confirm or cancel.
                    // Instead, we'll just remove the current scan from the session and show the upload form.
                    // We'll do a fetch to a endpoint that clears the scan, or we can just reload and rely on the fact that we don't have a scan stored.
                    // For simplicity, we'll just reload the page and hope that the session is cleared by the new scan upload.
                    // Actually, we'll just show the upload form by setting a flag in the session? Let's keep it simple and just reload.
                    // The server will see that there is no current_scan (because we haven't set one) and show the upload form.
                    // But we need to remove the current_scan from the session.
                    // We'll do a fetch to a script that unsets the session variable.
                    fetch('/api/clear-scan.php', {method: 'POST'}).then(() => {
                        location.reload();
                    });
                });
            });
        </script>
    <?php endif; ?>
</body>
</html>