<?php
// API: upload title photo and run OCR
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/ocr.php';
require_method('POST');

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    json_output(['error' => 'No image uploaded'], 400);
}

$file = $_FILES['image'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
    json_output(['error' => 'Invalid image type'], 400);
}

// Save upload
$config = require __DIR__ . '/../lib/config.php';
$uploadDir = $config['uploads'];
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
$filename = uniqid('scan_', true) . '.' . $ext;
$destPath = $uploadDir . $filename;
if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    json_output(['error' => 'Failed to save image'], 500);
}

// Run OCR
$ocrResult = ocr_extract_from_image($destPath);
if (isset($ocrResult['error'])) {
    // Keep the image for review, but return error
    json_output(['error' => $ocrResult['error'], 'image_path' => $destPath], 500);
}

// Extract fields from OCR text (very basic placeholder)
// In reality, we'd parse the text for title no., district, village, area, etc.
// For now, we return raw text and let the officer review UI handle extraction.
$text = $ocrResult['text'] ?? '';

json_output([
    'image_path' => $destPath,
    'ocr_text' => $text,
    // TODO: parse structured fields from $text
]);
