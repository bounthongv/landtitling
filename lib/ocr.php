<?php
require_once __DIR__ . '/config.php';

/**
 * Pre-OCR preprocessing: decode, upscale small crops toward ~2000px on the long edge,
 * grayscale and lightly boost contrast. Returns [GD resource, null] or [null, error string].
 * (Low-contrast Lao titles gain more from this than from parser tweaks.)
 */
function ocr_prepare($image_path) {
    $info = @getimagesize($image_path);
    if (!$info) return [null, 'Unable to read image'];
    switch ($info[2]) {
        case IMAGETYPE_JPEG: $img = @imagecreatefromjpeg($image_path); break;
        case IMAGETYPE_PNG:  $img = @imagecreatefrompng($image_path);  break;
        case IMAGETYPE_GIF:  $img = @imagecreatefromgif($image_path);  break;
        default: return [null, 'Unsupported image type'];
    }
    if (!$img) return [null, 'Unable to decode image'];

    $w = imagesx($img); $h = imagesy($img);
    $long = max($w, $h);
    if ($long > 0 && $long < 2000) {
        $scale = min(3.0, 2000 / $long);
        $nw = (int)round($w * $scale); $nh = (int)round($h * $scale);
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $dst;
    }
    imagefilter($img, IMG_FILTER_GRAYSCALE);
    imagefilter($img, IMG_FILTER_CONTRAST, -10);
    return [$img, null];
}

/**
 * Run Tesseract over a title image and return the recognised text plus per-word boxes.
 *
 * @return array{text?:string,words?:array,mean_confidence?:int,error?:string}
 *   words[i] = ['text','left','top','width','height','conf'] (conf 0..100)
 */
function ocr_extract_from_image($image_path) {
    if (!file_exists($image_path)) {
        return ['error' => 'Image not found'];
    }

    [$img, $err] = ocr_prepare($image_path);
    if ($err) return ['error' => $err];

    $tmpdir = sys_get_temp_dir() . '/landfee_ocr_' . uniqid();
    mkdir($tmpdir, 0700, true);
    $png_path = $tmpdir . '/page.png';
    imagepng($img, $png_path);
    imagedestroy($img);

    $outbase = $tmpdir . '/out';
    $cmd = 'tesseract ' . escapeshellarg($png_path) . ' ' . escapeshellarg($outbase)
         . ' -l lao+eng --psm 6 -c tessedit_create_tsv=1';
    $proc = proc_open($cmd, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($proc)) {
        array_map('unlink', glob($tmpdir . '/*'));
        @rmdir($tmpdir);
        return ['error' => 'Failed to execute Tesseract'];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    proc_close($proc);

    $tsvFile = $outbase . '.tsv';
    $tsv = is_file($tsvFile) ? (string)file_get_contents($tsvFile) : '';
    array_map('unlink', glob($tmpdir . '/*'));
    @rmdir($tmpdir);

    // Preferred path: word boxes + reconstructed text from the TSV sidecar.
    if (trim($tsv) !== '') {
        return ocr_parse_tsv($tsv);
    }
    if (trim((string)$stdout) === '' && trim((string)$stderr) !== '') {
        return ['error' => 'OCR failed: ' . trim($stderr)];
    }
    // Fallback: use the plain-text stream if available (no word boxes).
    return ['text' => trim((string)$stdout), 'words' => [], 'mean_confidence' => 0];
}

/**
 * Parse Tesseract TSV (level/…/text) into words with boxes, reconstruct plain text,
 * and compute a mean word confidence. Kept separate so it is unit-testable.
 */
function ocr_parse_tsv($tsv) {
    $words = [];
    $text = '';
    $prevLine = null;
    $confSum = 0; $confN = 0;

    foreach (preg_split('/\r?\n/', (string)$tsv) as $i => $line) {
        if ($i === 0 || trim($line) === '') continue; // header / blank
        $col = explode("\t", $line, 12);              // text may contain tabs -> last col
        if (count($col) < 12) continue;
        [$level, $page, $block, $par, $lineNo, , $left, $top, $width, $height, $conf] = $col;
        $word = rtrim($col[11]);
        if ((int)$level !== 5 || $word === '') continue;
        $confVal = (float)$conf;
        if ($confVal < 0) continue;

        $words[] = [
            'text'   => $word,
            'left'   => (int)$left,
            'top'    => (int)$top,
            'width'  => (int)$width,
            'height' => (int)$height,
            'conf'   => (int)round($confVal),
        ];
        $confSum += $confVal; $confN++;

        $key = "$block:$par:$lineNo";
        $text .= ($prevLine === null ? '' : ($prevLine === $key ? ' ' : "\n")) . $word;
        $prevLine = $key;
    }

    return [
        'text'             => trim($text),
        'words'            => $words,
        'mean_confidence'  => $confN ? (int)round($confSum / $confN) : 0,
    ];
}
