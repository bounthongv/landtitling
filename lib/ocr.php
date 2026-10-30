<?php
require_once __DIR__ . '/config.php';

/**
 * Pre-OCR preprocessing: decode, apply EXIF rotation, upscale toward 3000 px on the long
 * edge, grayscale. Returns [GD resource, null] or [null, error string].
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

    // Phone photos usually carry their rotation in EXIF, not in the pixels;
    // GD ignores EXIF, so a sideways title would crop and OCR in the wrong place.
    $img = ocr_apply_exif_orientation($img, $image_path);

    $w = imagesx($img); $h = imagesy($img);
    $long = max($w, $h);
    if ($long > 0 && $long < 3000) {
        $scale = min(3.0, 3000 / $long);
        $nw = (int)round($w * $scale); $nh = (int)round($h * $scale);
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $dst;
    }
    // Grayscale only. IMG_FILTER_CONTRAST with a negative arg REDUCES contrast in
    // GD; measured on real titles it cost ~8 points of mean confidence and two
    // ground-truth tokens versus grayscale alone. Don't "boost" it here.
    imagefilter($img, IMG_FILTER_GRAYSCALE);
    return [$img, null];
}

/**
 * Rotate a GD image according to its JPEG EXIF orientation (3/6/8 are the
 * orientations that occur in practice; mirrored variants are left alone).
 * Minimal APP1 parser so this works without the PHP exif extension.
 */
function ocr_apply_exif_orientation($img, $path) {
    $fh = @fopen($path, 'rb');
    if (!$fh) return $img;
    if (fread($fh, 2) !== "\xFF\xD8") { fclose($fh); return $img; }
    $orientation = 1; $guard = 0;
    while (!feof($fh) && $guard++ < 64) {
        $marker = fread($fh, 2);
        if (strlen($marker) < 2 || $marker[0] !== "\xFF") break;
        $code = ord($marker[1]);
        if ($code === 0xDA || $code === 0xD9) break;      // start of scan / end
        if ($code === 0xD8 || ($code >= 0xD0 && $code <= 0xD7)) continue;
        $lenRaw = fread($fh, 2);
        if (strlen($lenRaw) < 2) break;
        $len = unpack('n', $lenRaw)[1];
        if ($len < 2) break;
        if ($code !== 0xE1) { fseek($fh, $len - 2, SEEK_CUR); continue; }
        $data = fread($fh, $len - 2);
        if (substr($data, 0, 6) !== "Exif\0\0") break;
        $tiff = substr($data, 6);
        if (strlen($tiff) < 8) break;
        $le = substr($tiff, 0, 2) === 'II';
        $u16 = fn($o) => unpack($le ? 'v' : 'n', substr($tiff, $o, 2))[1] ?? 0;
        $u32 = fn($o) => unpack($le ? 'V' : 'N', substr($tiff, $o, 4))[1] ?? 0;
        $ifd = $u32(4);
        $count = $u16($ifd);
        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + $i * 12;
            if (strlen($tiff) < $entry + 10) break;
            if ($u16($entry) === 0x0112) { $orientation = $u16($entry + 8); break; }
        }
        break;
    }
    fclose($fh);
    if ($orientation === 3) return imagerotate($img, 180, 0);
    if ($orientation === 6) return imagerotate($img, -90, 0);
    if ($orientation === 8) return imagerotate($img, 90, 0);
    return $img;
}

/**
 * Run Tesseract over a title image and return the recognised text plus per-word boxes.
 *
 * @param string $image_path  decoded source image
 * @param string $tessdataDir optional dir of traineddata files (e.g. /opt/landfee-tessdata);
 *                            '' = use the system default. Lets us ship a better Lao model
 *                            without touching the system tessdata.
 *
 * @return array{text?:string,words?:array,mean_confidence?:int,error?:string}
 *   words[i] = ['text','left','top','width','height','conf'] (conf 0..100)
 */
function ocr_extract_from_image($image_path, $tessdataDir = '') {
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
    // psm 3 = fully automatic page segmentation — best for a filled form.
    // Both languages: the certificate mixes Lao labels with Latin title/map
    // serials (A-202-200, 17 JUL 2026, 1767). Measured on three real titles,
    // lao+eng raised mean confidence by 2-5 points over lao alone and recovered
    // Latin tokens lao missed, with no Lao word recall loss.
    $tess = ' -l lao+eng --psm 3 -c tessedit_create_tsv=1';
    if ($tessdataDir !== '' && is_dir($tessdataDir)) {
        $tess .= ' --tessdata-dir ' . escapeshellarg($tessdataDir);
    }
    $cmd = 'tesseract ' . escapeshellarg($png_path) . ' ' . escapeshellarg($outbase) . $tess;
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
            // Tesseract's own line id. Word boxes on one visual row routinely
            // differ in "top" by more than a row pitch, so clustering by
            // position splits a form row; this keeps it together.
            'line'   => "$block:$par:$lineNo",
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

/** Drop Lao combining vowels/tone marks so a crop that lost a mai-ek still matches its label. */
function ocr_label_core($s) {
    return preg_replace('/[\x{0EB0}-\x{0EBD}\x{0EC8}-\x{0ECC}]/u', '', (string)$s);
}

/**
 * Run OCR via Google Gemini 2.5 Flash API.
 * Returns ['text'=>string, 'words'=>[], 'mean_confidence'=>int] or ['error'=>string].
 */
function ocr_gemini(string $imagePath, array $cfg): array {
    $apiKey = $cfg['gemini_api_key'] ?? getenv('GEMINI_API_KEY') ?? '';
    if ($apiKey === '') {
        return ['error' => 'Gemini API key not configured. Set GEMINI_API_KEY in config or environment.'];
    }

    $mimeType = mime_content_type($imagePath) ?: 'image/jpeg';
    $imageData = base64_encode(file_get_contents($imagePath));

    $prompt = "You are an expert at reading Lao land title documents (ໃບຕາດິນ). 
Extract the following fields from the image. Return ONLY valid JSON with these exact keys:

{
  \"title_no\": \"certificate serial number (e.g. 06.001.0017)\",
  \"province\": \"issuing province name in Lao (e.g. ຫຼວງພະບາງ)\",
  \"district\": \"district name in Lao (e.g. ນະຄອນ ຫຼວງພະບາງ)\",
  \"village\": \"village name in Lao (e.g. ຜານົມ)\",
  \"owner_name\": \"owner full name\",
  \"owner_id\": \"owner ID/citizen number\",
  \"parcel_no\": \"plot/parcel number (e.g. 5557)\",
  \"area_sqm\": \"area in square meters (number only)\",
  \"area_ha\": \"area in hectares/thans if shown\",
  \"land_use\": \"land use type/class\",
  \"issued_date\": \"issue date\",
  \"raw_text\": \"full raw text from the document for reference\"
}

If a field is not visible, use null. Be precise - extract exactly what you see.";

    $payload = [
        'contents' => [[
            'role' => 'user',
            'parts' => [
                ['text' => $prompt],
                [
                    'inline_data' => [
                        'mime_type' => $mimeType,
                        'data' => $imageData
                    ]
                ]
            ]
        ]],
        'generationConfig' => [
            'temperature' => 0.0,
            'maxOutputTokens' => 8192,
            'thinkingConfig' => ['thinkingBudget' => 0],  // no reasoning needed for OCR extraction; saves output budget
        ]
    ];

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $apiKey;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 60,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return ['error' => 'Gemini API error: HTTP ' . $httpCode . ' - ' . $response];
    }

    $result = json_decode($response, true);
    if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        return ['error' => 'Invalid Gemini response format'];
    }

    $text = trim($result['candidates'][0]['content']['parts'][0]['text']);
    // Strip code fences if present
    $text = preg_replace('/^```json\s*/', '', $text);
    $text = preg_replace('/\s*```$/', '', $text);
    $text = trim($text);

    // Try to parse as JSON
    $parsed = @json_decode($text, true);
    if (!$parsed) {
        // If JSON parse fails, treat entire response as raw text
        return [
            'text' => $text,
            'words' => [],
            'mean_confidence' => 95,
            'structured_fields' => null,
        ];
    }

    // Map Gemini's field names to our internal names
    $mappedText = '';
    $structuredFields = [];
    if (isset($parsed['raw_text'])) {
        $mappedText = $parsed['raw_text'];
    } else {
        // Build a text representation from extracted fields
        $parts = [];
        foreach ($parsed as $k => $v) {
            if ($v !== null && $v !== '') {
                $parts[] = "$k: $v";
            }
        }
        $mappedText = implode("\n", $parts);
    }

    // Map Gemini field names to our canonical internal names. Only the NAME fields
    // are set here; the geography gate in scans.php resolves district_id/village_id
    // from these names the same way it does for Tesseract, so both engines follow
    // identical logic.
    $fieldMap = [
        'title_no'    => 'title_no',
        'province'    => 'province',
        'district'    => 'district_name',
        'village'     => 'village_name',
        'owner_name'  => 'owner_name',
        'owner_id'    => 'owner_id_number',
        'parcel_no'   => 'parcel_no',
        'area_sqm'    => 'area_sqm',
        'area_ha'     => 'area_lan',
        'land_use'    => 'land_use_class',
        'issued_date' => 'issued_date',
    ];
    foreach ($fieldMap as $geminiKey => $internalKey) {
        if (isset($parsed[$geminiKey]) && $parsed[$geminiKey] !== null && $parsed[$geminiKey] !== '') {
            $structuredFields[$internalKey] = $parsed[$geminiKey];
        }
    }

    return [
        'text' => $mappedText,
        'words' => [],
        'mean_confidence' => 95,
        'structured_fields' => $structuredFields,
    ];
}
