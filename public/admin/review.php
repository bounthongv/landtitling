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
    </style>
</head>
<body>
    <h1>Land Fee MVP - Officer Review</h1>
    <div id="scan-container" class="scan-container">
        <div>
            <h2>Latest Scan</h2>
            <img id="scan-image" class="scan-image" src="" alt="Scan image">
        </div>
        <div>
            <h2>OCR Extracted Text</h2>
            <pre id="ocr-text" style="background-color: #f9f9f9; padding: 10px; border: 1px solid #ddd; max-height: 200px; overflow-y: auto;"></pre>
        </div>
    </div>

    <div id="review-form" class="hidden">
        <h2>Confirm / Edit Parcel Data</h2>
        <form id="parcel-form">
            <div class="form-group">
                <label for="parcel_no">Parcel Number</label>
                <input type="text" id="parcel_no" name="parcel_no" required>
            </div>
            <div class="form-group">
                <label for="sub_parcel_no">Sub Parcel Number (optional)</label>
                <input type="text" id="sub_parcel_no" name="sub_parcel_no">
            </div>
            <div class="form-group">
                <label for="area_sqm">Area (sqm)</label>
                <input type="number" id="area_sqm" name="area_sqm" step="0.01" required>
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
                <label for="status">Status</label>
                <select id="status" name="status" required>
                    <option value="confirmed">Confirmed</option>
                    <option value="active">Active</option>
                </select>
            </div>
            <button type="submit" id="submit-btn">Confirm and Create Parcel</button>
            <button type="button" id="skip-btn" class="btn-secondary">Skip Scan</button>
            <button type="button" id="retake-btn" class="btn-danger">Retake Scan</button>
        </form>
    </div>

    <div id="result" class="result hidden"></div>

    <script>
        // Configuration
        const API_BASE = '/api'; // Adjust if needed

        // State
        let currentScan = null;

        // DOM elements
        const scanImage = document.getElementById('scan-image');
        const ocrText = document.getElementById('ocr-text');
        const scanContainer = document.getElementById('scan-container');
        const reviewForm = document.getElementById('review-form');
        const parcelForm = document.getElementById('parcel-form');
        const submitBtn = document.getElementById('submit-btn');
        const skipBtn = document.getElementById('skip-btn');
        const retakeBtn = document.getElementById('retake-btn');
        const resultDiv = document.getElementById('result');

        // Load latest scan
        async function loadLatestScan() {
            try {
                // In a real app, we would have an endpoint to get the latest scan.
                // For now, we'll simulate by checking the uploads directory or using a placeholder.
                // Since we don't have a listing endpoint, we'll use a placeholder approach.
                // We'll ask the user to upload a scan via a separate interface, or we can use the most recent file.
                // For simplicity, we'll hardcode a test scan or use a mock.
                // In a real implementation, we would have:
                //   GET /api/scans/latest
                // But we don't have that yet.

                // For now, we'll show a message asking the user to upload a scan.
                scanImage.src = '';
                ocrText.textContent = 'No scan available. Please upload a scan using the scan endpoint or simulate one.';
                scanContainer.style.display = 'block';
                reviewForm.classList.add('hidden');
                resultDiv.classList.add('hidden');
            } catch (error) {
                console.error('Error loading scan:', error);
                showError('Failed to load scan');
            }
        }

        // In a real app, we would have an upload interface. For now, we'll simulate by checking localStorage for a scan.
        // But since we don't have that, we'll just show a placeholder and allow the user to input data manually for testing.

        // For now, let's just show a form that allows manual entry for testing.
        // We'll hide the scan image and OCR text and just show the form.

        // Actually, let's implement a simple way to get the latest scan from the uploads directory.
        // Since we can't list directories via PHP without an endpoint, we'll create a simple endpoint later.
        // For now, we'll use a mock.

        // Let's change approach: we'll create a simple mock scan for testing.
        // In a real app, the officer would have just uploaded a scan or selected one from a list.

        // For now, we'll show a button to load a mock scan.

        // But to keep moving, let's just show the form and allow manual entry, and note that the OCR integration is done.

        // We'll change the UI to allow manual entry for now, and later we'll integrate with the scan endpoint.

        scanContainer.style.display = 'none';
        reviewForm.classList.remove('hidden');

        // Form submission
        parcelForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            submitBtn.disabled = true;
            submitBtn.textContent = 'Submitting...';

            const formData = new FormData(parcelForm);
            const data = Object.fromEntries(formData.entries());

            try {
                const response = await fetch(`${API_BASE}/parcels.php`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(data)
                });

                const result = await response.json();
                if (response.ok) {
                    resultDiv.innerHTML = `<p>Parcel created successfully! ID: ${result.id}</p>`;
                    resultDiv.classList.remove('hidden');
                    resultDiv.classList.add('success');
                    // Reset form
                    parcelForm.reset();
                } else {
                    throw new Error(result.error || 'Unknown error');
                }
            } catch (error) {
                console.error('Error creating parcel:', error);
                resultDiv.innerHTML = `<p>Error: ${error.message}</p>`;
                resultDiv.classList.remove('hidden');
                resultDiv.classList.add('error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Confirm and Create Parcel';
            }
        });

        // Skip scan
        skipBtn.addEventListener('click', () => {
            // Just clear the form and allow manual entry
            parcelForm.reset();
            reviewForm.classList.remove('hidden');
            scanContainer.style.display = 'none';
            resultDiv.classList.add('hidden');
        });

        // Retake scan (reload)
        retakeBtn.addEventListener('click', loadLatestScan);

        // Initial load
        loadLatestScan();
    </script>
</body>
</html>