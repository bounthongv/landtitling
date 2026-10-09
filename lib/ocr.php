<?php
require_once __DIR__ . '/config.php';

function ocr_extract_from_image($image_path) {
    // Ensure Tesseract is available
    if (!file_exists($image_path)) {
        return ['error' => 'Image not found'];
    }
    // Create temp directory
    $tmpdir = sys_get_temp_dir() . '/landfee_ocr_' . uniqid();
    mkdir($tmpdir, 0700, true);
    $png_path = $tmpdir . '/page.png';
    // Convert to PNG at 300 DPI using ImageMagick if available, else fallback to GD?
    // For simplicity, we assume the image is already PNG or we can use PHP GD to load and save.
    // Better: use imagick or exec('convert'). We'll use PHP GD for now (limited to JPEG/PNG/GIF).
    $info = getimagesize($image_path);
    if (!$info) {
        return ['error' => 'Unable to read image'];
    }
    switch ($info[2]) {
        case IMAGETYPE_JPEG: $img = imagecreatefromjpeg($image_path); break;
        case IMAGETYPE_PNG:  $img = imagecreatefrompng($image_path); break;
        case IMAGETYPE_GIF:  $img = imagecreatefromgif($image_path); break;
        default: return ['error' => 'Unsupported image type'];
    }
    // Resize to 300 DPI? We'll keep original size; Tesseract works better at 300 DPI.
    // We'll save at 300 DPI by scaling if needed. For simplicity, we assume input is already ~300 DPI.
    imagepng($img, $png_path);
    imagedestroy($img);
    // Run Tesseract
    $descriptorSpec = [
        0 => ["pipe", "r"],  // stdin
        1 => ["pipe", "w"],  // stdout
        2 => ["pipe", "w"]   // stderr
    ];
    $proc = proc_open('tesseract ' . escapeshellarg($png_path) . ' stdout -l lao+eng --psm 6', $descriptorSpec, $pipes);
    if (is_resource($proc)) {
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        proc_close($proc);
        // Clean up
        array_map('unlink', glob($tmpdir . '/*'));
        rmdir($tmpdir);
        if ($stderr) {
            // Log error but continue
            error_log("Tesseract stderr: $stderr");
        }
        // Return raw text
        return ['text' => trim($stdout)];
    } else {
        array_map('unlink', glob($tmpdir . '/*'));
        rmdir($tmpdir);
        return ['error' => 'Failed to execute Tesseract'];
    }
}
