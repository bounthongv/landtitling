<?php
// lib/title_parser.php — turn raw OCR text from a Lao land title into structured fields.
// See docs/5-annual-land-fee-back-office-design.md §3.
//
//   title_parse_text(string $text, PDO $pdo, array $ctx = []): array
//     => ['fields' => [...], 'confidence' => [field => 0..100], 'overall' => 0..100]
//
// Deterministic fields come from anchored Lao/EN labels + regex. District/village names are
// resolved to ids by fuzzy match against the seeded geography (pg_trgm when available, else a
// PHP similarity fallback). Nothing is auto-committed — the officer confirms in the review UI.

const TITLE_LAO_DIGITS = [
    '໐' => '0', '໑' => '1', '໒' => '2', '໓' => '3', '໔' => '4',
    '໕' => '5', '໖' => '6', '໗' => '7', '໘' => '8', '໙' => '9',
];

/** Map Lao digits to ASCII (strtr does longest-substring match, so multibyte keys are fine). */
function title_normalize_digits($s) {
    return strtr((string)$s, TITLE_LAO_DIGITS);
}

/**
 * Parse a numeric token that may use either thousands/decimal convention.
 * Returns float|null.
 */
function title_normalize_number($raw) {
    $s = title_normalize_digits(trim((string)$raw));
    $s = preg_replace('/[\s\x{00a0}]/u', '', $s);
    if ($s === '') return null;
    $dot = strrpos($s, '.');
    $comma = strrpos($s, ',');
    if ($dot !== false && $comma !== false) {
        if ($comma > $dot) {                 // 1.234,56 -> EU decimal comma
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {                             // 1,234.56 -> US decimal point
            $s = str_replace(',', '', $s);
        }
    } elseif ($comma !== false) {
        $s = preg_match('/,\d{3}$/', $s) ? str_replace(',', '', $s) : str_replace(',', '.', $s);
    } elseif ($dot !== false) {
        if (preg_match('/\.\d{3}$/', $s)) $s = str_replace('.', '', $s);
    }
    return is_numeric($s) ? (float)$s : null;
}

/**
 * Comparison key for a place name. OCR splits or joins Lao names arbitrarily and
 * swaps equivalent spellings (ນ້ຳ/ນໍ້າ, dropped tone marks), none of which make it
 * a different place. Lowercase, drop whitespace and tone marks, fold ຳ -> ໍາ.
 */
function title_name_key($s) {
    $s = mb_strtolower(trim((string)$s), 'UTF-8');
    $s = preg_replace('/\s+/u', '', $s);
    $s = preg_replace('/[\x{0EC8}-\x{0ECC}]/u', '', $s);
    return str_replace('ຳ', 'ໍາ', $s);
}

/** Similarity of two names on their comparison keys, 0..1 (PHP fallback without pg_trgm). */
function title_similarity($a, $b) {
    $a = title_name_key($a);
    $b = title_name_key($b);
    if ($a === '' || $b === '') return 0.0;
    if ($a === $b) return 1.0;
    similar_text($a, $b, $pct);
    return $pct / 100.0;
}

/** SQL form of title_name_key() over a name column. */
function title_name_key_sql($column) {
    return "replace(replace(translate(lower($column), '່້໊໋໌', ''), 'ຳ', 'ໍາ'), ' ', '')";
}

/**
 * Resolve a district name to [id, confidence]. Exact match -> 100; trgm/PHP fuzzy otherwise.
 * @return array{0:?int,1:int}
 */
function title_resolve_district($name, PDO $pdo) {
    $name = trim((string)$name);
    if ($name === '') return [null, 0];
    $key = title_name_key($name);

    // Spelling variants are not significant (see title_name_key).
    $st = $pdo->prepare("SELECT id FROM districts
        WHERE name_lo = :n OR lower(name_en) = lower(:n) OR "
        . title_name_key_sql('name_lo') . " = :key LIMIT 1");
    $st->execute([':n' => $name, ':key' => $key]);
    if ($row = $st->fetch()) return [(int)$row['id'], 100];

    $norm = title_name_key_sql('name_lo');
    try {
        $st = $pdo->prepare("SELECT id, similarity($norm, :key) AS s FROM districts
            WHERE $norm % :key ORDER BY s DESC LIMIT 1");
        $st->execute([':key' => $key]);
        $row = $st->fetch();
        if ($row && (float)$row['s'] >= 0.30) {
            return [(int)$row['id'], (int)round((float)$row['s'] * 100)];
        }
    } catch (Throwable $e) {
        // pg_trgm not installed -> PHP fallback below
    }

    $best = null; $bestScore = 0.0;
    foreach ($pdo->query("SELECT id, name_lo, name_en FROM districts")->fetchAll() as $r) {
        $sc = max(title_similarity($name, $r['name_lo']), title_similarity($name, $r['name_en'] ?? ''));
        if ($sc > $bestScore) { $bestScore = $sc; $best = $r; }
    }
    if ($best && $bestScore >= 0.55) return [(int)$best['id'], (int)round($bestScore * 100)];
    return [null, 0];
}

/**
 * Resolve a village name to [id, confidence], optionally scoped to a district.
 * @return array{0:?int,1:int}
 */
function title_resolve_village($name, ?int $districtId, PDO $pdo) {
    $name = trim((string)$name);
    if ($name === '') return [null, 0];
    $key = title_name_key($name);
    $scope = $districtId ? " AND district_id = :did" : "";
    $params = [':n' => $name, ':key' => $key];
    if ($districtId) $params[':did'] = $districtId;

    $norm = title_name_key_sql('name_lo');
    $st = $pdo->prepare("SELECT id FROM villages
        WHERE (name_lo = :n OR lower(name_en) = lower(:n) OR $norm = :key)$scope LIMIT 1");
    $st->execute($params);
    if ($row = $st->fetch()) return [(int)$row['id'], 100];

    try {
        $st = $pdo->prepare("SELECT id, similarity($norm, :key) AS s FROM villages
            WHERE $norm % :key$scope ORDER BY s DESC LIMIT 1");
        $st->execute($params);
        $row = $st->fetch();
        if ($row && (float)$row['s'] >= 0.30) {
            return [(int)$row['id'], (int)round((float)$row['s'] * 100)];
        }
    } catch (Throwable $e) {
        // fall through
    }

    $sql = "SELECT id, name_lo, name_en FROM villages" . ($districtId ? " WHERE district_id = :did" : "");
    $st = $pdo->prepare($sql);
    if ($districtId) $st->execute([':did' => $districtId]); else $st->execute();
    $best = null; $bestScore = 0.0;
    foreach ($st->fetchAll() as $r) {
        $sc = max(title_similarity($name, $r['name_lo']), title_similarity($name, $r['name_en'] ?? ''));
        if ($sc > $bestScore) { $bestScore = $sc; $best = $r; }
    }
    if ($best && $bestScore >= 0.55) return [(int)$best['id'], (int)round($bestScore * 100)];
    return [null, 0];
}

/** Average of the per-field confidences that carry weight for the fee (rounded). */
function title_overall_confidence(array $confidence) {
    $keys = ['parcel_no', 'area_sqm', 'title_no', 'district_id', 'village_id'];
    $vals = [];
    foreach ($keys as $k) { if (!empty($confidence[$k])) $vals[] = $confidence[$k]; }
    if (!$vals) {
        $vals = array_values(array_filter($confidence, fn($v) => $v > 0));
    }
    if (!$vals) return 0;
    return (int)round(array_sum($vals) / count($vals));
}

/** Trim a captured Lao text run at the next likely label / digit and normalise whitespace. */
function title_trim_value($s) {
    $s = preg_replace('/\s+/u', ' ', trim((string)$s));
    $s = preg_split('/(?:ທີ່ຢູ່|ອາຍຸ|ເລກ|ວັນທີ|ຊື່|ຫຼັ້ມທີ|ໃບທີ|[0-9])/u', $s)[0] ?? $s;
    return trim($s, " .-");
}

/** Printed label per field, used to anchor word-box extraction. */
function title_region_label($key) {
    static $labels = [
        'title_no' => 'ເລກທີ', 'province' => 'ແຂວງ', 'district_name' => 'ເມືອງ',
        'village_name' => 'ບ້ານ', 'owner_name' => 'ອອກໃຫ້ແກ່', 'owner_id_number' => 'ບັດ',
        'area_sqm' => 'ເນື້ອທີ່', 'parcel_no' => 'ຕອນດິນເລກທີ', 'issued_date' => 'ວັນທີ',
        'location_code' => 'ທີ່ຕັ້ງຕອນດິນ', 'order_no' => 'ອອກຄັ້ງທີ', 'unit_no' => 'ໜ່ວຍ',
        'date_of_birth' => 'ວັນເດືອນປີເກີດ', 'nationality' => 'ສັນຊາດ', 'occupation' => 'ອາຊີບ',
        'owner_address_village' => 'ບ້ານ', 'owner_address_unit' => 'ໜ່ວຍ',
        'owner_address_district' => 'ເມືອງ', 'owner_address_province' => 'ແຂວງ',
        'spouse_name' => 'ຊື່ຜົວຫຼືເມຍ', 'spouse_dob' => 'ວັນເດືອນປີເກີດ',
        'spouse_nationality' => 'ສັນຊາດ', 'spouse_occupation' => 'ອາຊີບ',
        'acquisition_method' => 'ການໄດ້ມາ', 'ownership_status' => 'ຖານະ',
        'land_use_class' => 'ປະເພດທີ່ດິນ', 'zone_text' => 'ເຂດ',
        'book_no' => 'ເຫຼັ້ມທີ', 'page_no' => 'ໃບທີ', 'map_ref_no' => 'ແຜນທີ່',
        'map_parcel_no' => 'ຕອນດິນເລກທີ', 'map_scale' => 'ມາດຕາສ່ວນ',
        'issued_place' => 'ອອກໃຫ້ທີ່',
    ];
    return $labels[$key] ?? '';
}

/** Parse a printed date: yyyy-mm-dd, dd/mm/yyyy, or dd MMM yyyy (e.g. 17 JUL 2026). */
function title_parse_date($s) {
    if (preg_match('/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/u', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
    if (preg_match('/\b(\d{1,2})\s*[\/\-.]\s*(\d{1,2})\s*[\/\-.]\s*(\d{4})\b/u', $s, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    $months = ['JAN'=>1,'FEB'=>2,'MAR'=>3,'APR'=>4,'MAY'=>5,'JUN'=>6,
               'JUL'=>7,'AUG'=>8,'SEP'=>9,'OCT'=>10,'NOV'=>11,'DEC'=>12];
    if (preg_match('/\b(\d{1,2})\s*(JAN|FEB|MAR|APR|MAY|JUN|JUL|AUG|SEP|OCT|NOV|DEC)[A-Z]*\s*(\d{4})\b/i', $s, $m)) {
        $mo = $months[strtoupper($m[2])];
        if (checkdate($mo, (int)$m[1], (int)$m[3])) return sprintf('%04d-%02d-%02d', $m[3], $mo, $m[1]);
    }
    return null;
}

/**
 * Extract a clean field VALUE from a raw OCR'd "label: value" line.
 * Works on a full line or on the value alone; returns ['value','ok'].
 */
function roi_extract_value(string $key, $rawLine): array {
    $t = title_normalize_digits(preg_replace('/\s+/u', ' ', trim((string) $rawLine)));
    $t = trim($t, " \t");
    if ($t === '') return ['value' => null, 'ok' => false];

    // OCR scatters spaces around separators ("06. 001 . 0017"); join them so the
    // patterns below see the value the way it is printed.
    $t = preg_replace('/\s*([.:\/])\s*/u', '$1', $t);
    // Dotted fill lines come through as long runs of dots/dashes.
    $t = preg_replace('/[.\-_]{3,}/u', ' ', $t);

    switch ($key) {
        case 'area_sqm':
            // The area row also carries the map scale ("1/400"), so the unit wins:
            // take the number printed before ຕາແມັດ/ຕາລາງ. Only fall back to the
            // largest bare number when OCR lost the unit.
            if (preg_match('/(\d[\d.,]*)\s*(?:ຕາແມັດ|ຕາລາງ|ຕລ)/u', $t, $m)) {
                $v = title_normalize_number($m[1]);
                if ($v !== null && $v > 0) return ['value' => (string) $v, 'ok' => true];
            }
            if (preg_match_all('/(?<![\d.\/])(\d[\d,]*(?:\.\d+)?)(?![\d\/])/u', $t, $m)) {
                $best = null;
                foreach ($m[1] as $n) {
                    $v = title_normalize_number($n);
                    if ($v !== null && $v > 0 && ($best === null || $v > $best)) $best = $v;
                }
                if ($best !== null) return ['value' => (string) $best, 'ok' => true];
            }
            return ['value' => null, 'ok' => false];

        case 'title_no':
            // Certificate serial, e.g. 06.001.0017 (2-4 dotted groups).
            if (preg_match('/(\d{1,4}(?:\.\d{1,4}){1,3})/u', $t, $m)) return ['value' => $m[1], 'ok' => true];
            if (preg_match('/(\d{3,})\s*$/u', $t, $m)) return ['value' => $m[1], 'ok' => true];
            return ['value' => null, 'ok' => false];

        case 'location_code':
            // Land location code: 10.002.0027 / 06.001.0017.
            if (preg_match('/(\d{1,4}(?:[.\/-]\d{1,4}){1,4})/u', $t, $m)) return ['value' => $m[1], 'ok' => true];
            return ['value' => null, 'ok' => false];

        case 'parcel_no':
            // Plot number after the label; legacy sheets prefix a letter (A250-016).
            if (preg_match('/[:：]\s*([A-Za-z]{0,2}\d{1,6})/u', $t, $m)) return ['value' => $m[1], 'ok' => true];
            if (preg_match('/(?<![A-Za-z0-9])([A-Za-z]{0,2}\d{1,6})(?![0-9])/u', $t, $m)) return ['value' => $m[1], 'ok' => true];
            return ['value' => null, 'ok' => false];

        case 'map_ref_no':
            // "ແຜນທີ່ດິນເລກທີ : A250-038" and "8 252-980/03" both occur: sheet,
            // map number and optional suffix. OCR often prints the leading A as ດ.
            if (preg_match('/(?<![0-9A-Za-z])([0-9A-Za-zດກ][0-9A-Za-zດກ \/.\-]{1,22})/u', $t, $m)) {
                $v = strtoupper(str_replace(['ດ', 'ກ'], 'A', preg_replace('/\s+/u', ' ', trim($m[1]))));
                $v = rtrim($v, ' .-/');
                if (preg_match('/\d{2,}/', $v)) return ['value' => $v, 'ok' => true];
            }
            return ['value' => null, 'ok' => false];

        case 'book_no':
        case 'page_no':
        case 'unit_no':
            if (preg_match('/[:：]\s*(\d{1,6})/u', $t, $m)) return ['value' => $m[1], 'ok' => true];
            if (preg_match('/(?<!\d)(\d{1,6})(?!\d)/u', $t, $m)) return ['value' => $m[1], 'ok' => true];
            return ['value' => null, 'ok' => false];

        case 'map_scale':
            if (preg_match('/(1\s*[\/.]\s*(\d{2,6}))/u', $t, $m)) return ['value' => '1/' . $m[2], 'ok' => true];
            return ['value' => null, 'ok' => false];

        case 'issued_date':
            $d = title_parse_date($t);
            return $d === null ? ['value' => null, 'ok' => false] : ['value' => $d, 'ok' => true];

        case 'owner_id_number':
            if (preg_match('/\b(\d{6,13})\b/u', $t, $m)) return ['value' => $m[1], 'ok' => true];
            return ['value' => null, 'ok' => false];

        default:
            // Lao text fields (province, district_name, village_name, owner_name,
            // nationality, occupation, ...): value = text after the last colon.
            $v = $t;
            if (preg_match('/^.*[:：]\s*(.+)$/u', $v, $m)) $v = $m[1];
            $v = trim(preg_replace('/[_.]{2,}/u', ' ', $v));
            $v = trim($v, " .-–—:：|,;");
            if (!preg_match('/[\p{L}\p{N}]/u', $v)) return ['value' => null, 'ok' => false];
            return ['value' => $v, 'ok' => true];
    }
}

/**
 * Layout-independent field extraction from the full-page TSV word boxes.
 *
 * The printed certificate has two column layouts in circulation and the old
 * fixed positional template only fitted one of them. Here we find each field's
 * printed label among the recognised words, then read the words to its right on
 * the same line, stopping at the next known label. Works for any layout and
 * needs no second OCR pass.
 *
 * @param array $words  [['text','left','top','width','height','conf'], ...]
 * @return array key => ['text' => string, 'confidence' => int]
 */
function title_extract_word_fields(array $words, array $labels) {
    if (!$words) return [];
    $cores = [];
    foreach ($labels as $key => $label) {
        $c = function_exists('ocr_label_core') ? ocr_label_core($label) : $label;
        if ($c !== '') $cores[$key] = $c;
    }
    if (!$cores) return [];

    // Group words into text lines. Prefer Tesseract's own line id: word boxes on
    // one printed row can differ in "top" by more than a row pitch (typed labels
    // sit high, handwritten values sit low), which a position cluster splits.
    $lines = [];
    $byId = [];
    foreach ($words as $w) {
        if (!empty($w['line'])) {
            $id = (string)$w['line'];
            if (!isset($byId[$id])) $byId[$id] = ['top' => $w['top'], 'words' => []];
            $byId[$id]['words'][] = $w;
            $byId[$id]['top'] = min($byId[$id]['top'], $w['top']);
        }
    }
    if ($byId) {
        $lines = array_values($byId);
    } else {
        usort($words, fn($a, $b) => [$a['top'], $a['left']] <=> [$b['top'], $b['left']]);
        foreach ($words as $w) {
            $placed = false;
            foreach ($lines as &$line) {
                if (abs($w['top'] - $line['top']) <= max(8, (int)($w['height'] * 0.6))) {
                    $line['words'][] = $w;
                    $line['top'] = min($line['top'], $w['top']);
                    $placed = true;
                    break;
                }
            }
            unset($line);
            if (!$placed) $lines[] = ['top' => $w['top'], 'words' => [$w]];
        }
    }
    usort($lines, fn($a, $b) => $a['top'] <=> $b['top']);
    $is_label = function ($text) use ($cores, $labels) {
        $c = function_exists('ocr_label_core') ? ocr_label_core($text) : $text;
        if ($c === '') return false;
        foreach ($labels as $label) {
            $lc = function_exists('ocr_label_core') ? ocr_label_core($label) : $label;
            if ($lc !== '' && (mb_strpos($c, $lc) !== false || mb_strpos($lc, $c) !== false)) return true;
        }
        return false;
    };

    $out = [];
    foreach ($lines as $line) {
        $ws = $line['words'];
        usort($ws, fn($a, $b) => $a['left'] <=> $b['left']);
        foreach ($ws as $i => $w) {
            $wc = function_exists('ocr_label_core') ? ocr_label_core($w['text']) : $w['text'];
            if ($wc === '') continue;
            // Rank candidate labels: the most specific (longest) label wins, so
            // the parcel label ຕອນດິນເລກທີ beats ເລກທີ and the map label
            // ແຜນທີ່ດິນເລກທີ beats it too — reading a parcel or map serial as
            // the title number is a wrong-identity error, not a cosmetic one.
            // On a tie, the label printed earlier in the word wins.
            $key = null; $bestLen = 0; $bestPos = PHP_INT_MAX; $exact = false;
            foreach ($cores as $k => $core) {
                if (isset($out[$k])) continue;
                $len = mb_strlen($core);
                $strong = true;
                $pos = mb_strpos($wc, $core);
                if ($pos === false && mb_strlen($wc) >= 2 && mb_strpos($core, $wc) !== false) {
                    $pos = 0; $strong = false;          // label truncated by OCR
                }
                if ($pos === false && $len < 5) {
                    // Short labels OCR cleanly; allow an exact leading fragment.
                    $probe = mb_substr($core, 0, min(4, $len));
                    if ($probe !== '' && mb_strpos($wc, $probe) === 0) { $pos = 0; $strong = false; }
                }
                if ($pos === false && $len >= 5) {
                    // Long labels survive one wrong character in their first five:
                    // ທີ່ຕັ້ງຕອນດິນ is routinely read as ທິ່ຄົ້ງຕອນດິນ.
                    $pl = mb_substr($core, 0, 5);
                    $pw = mb_substr($wc, 0, 5);
                    if (mb_strlen($pw) >= 5) {
                        $miss = 0;
                        for ($c = 0; $c < 5; $c++) {
                            if (mb_substr($pl, $c, 1) !== mb_substr($pw, $c, 1)) $miss++;
                        }
                        if ($miss <= 1) { $pos = 0; $strong = false; }
                    }
                }
                if ($pos === false) continue;
                if ($len > $bestLen || ($len === $bestLen && $pos < $bestPos)) {
                    $bestLen = $len; $bestPos = $pos; $key = $k; $exact = $strong;
                }
            }
            if ($key !== null) {
                $val = []; $conf = [];
                $prevRight = $w['left'] + $w['width'];
                for ($j = $i + 1; $j < count($ws); $j++) {
                    $t2 = $ws[$j];
                    if ($is_label($t2['text'])) break;
                    // A wide blank gap means the next column, not this value.
                    if ($t2['left'] - $prevRight > max(40, $t2['height'] * 3)) break;
                    $prevRight = $t2['left'] + $t2['width'];
                    // Standalone ":" / "." / "|" words sit between label and value;
                    // they are not the value.
                    if (preg_match('/^[.:：|,;·•*_=\-–—\/\\\\]+$/u', $t2['text'])) continue;
                    $val[] = $t2['text'];
                    $conf[] = (int)$t2['conf'];
                }
                if ($val) {
                    $out[$key] = [
                        'text' => implode(' ', $val),
                        'confidence' => $conf ? (int)round(array_sum($conf) / count($conf)) : 0,
                        // An exact label is trusted; a truncated or drifted one only
                        // when the value itself is clearly read (callers gate on this).
                        'label_exact' => $exact,
                    ];
                }
            }
        }
    }
    return $out;
}

/**
 * Printed labels for title_extract_word_fields(), one per row that carries its
 * value to the right of the label. Duplicated printed labels (ບ້ານ, ເມືອງ,
 * ແຂວງ appear in both the land block and the owner-address block) map to the
 * first key listed, which is the land block.
 */
function title_scan_labels() {
    return [
        'title_no'        => 'ເລກທີ',
        'location_code'   => 'ທີ່ຕັ້ງຕອນດິນ',
        'order_no'        => 'ອອກຄັ້ງທີ',
        'province'        => 'ແຂວງ',
        'district_name'   => 'ເມືອງ',
        'village_name'    => 'ບ້ານ',
        'owner_name'      => 'ອອກໃຫ້ແກ່',
        'date_of_birth'   => 'ວັນເດືອນປີເກີດ',
        'nationality'     => 'ສັນຊາດ',
        'occupation'      => 'ອາຊີບ',
        'area_sqm'        => 'ເນື້ອທີ່',
        'land_use_class'  => 'ປະເພດທີ່ດິນ',
        'zone_text'       => 'ເຂດ',
        'book_no'         => 'ເຫຼັ້ມທີ',
        'page_no'         => 'ໃບທີ',
        'map_ref_no'      => 'ແຜນທີ່',
        'parcel_no'       => 'ຕອນດິນເລກທີ',
        'map_scale'       => 'ມາດຕາສ່ວນ',
        'issued_place'    => 'ອອກໃຫ້ທີ່',
        'issued_date'     => 'ວັນທີ',
    ];
}

/**
 * Main entry point: parse the full OCR text into certificate fields.
 * @param array $ctx optional hints: ['district_id'=>int]
 * @return array{fields:array,confidence:array,overall:int}
 */
function title_parse_text($text, PDO $pdo, array $ctx = []) {
    $fields = [];
    $conf = [];
    $set = function ($key, $val, $c) use (&$fields, &$conf) {
        if ($val === null || $val === '' || $val === []) return;
        $fields[$key] = $val;
        $conf[$key] = max(0, min(100, (int)$c));
    };
    $first = function ($re, $subject) {
        return preg_match($re, $subject, $m) ? trim($m[1]) : null;
    };

    $t = title_normalize_digits((string)$text);
    // OCR scatters spaces around separators; join them so printed numbers survive.
    $flat = preg_replace('/\s*([.:\/])\s*/u', '$1', $t);
    $flat = preg_replace('/[ \t]+/u', ' ', $flat);
    // Tesseract often renders the long vowel ີ as the cancellation mark ໌
    // (ແຜນທິຕາດິນເລກທ໌); restoring it keeps the printed labels matchable.
    $flat = str_replace('໌', 'ີ', $flat);

    // --- #3 land location: "ທີ່ຕັ້ງຕອນດິນ : 10.002.0027" (not the title number) ---
    if ($v = $first('/ທີ[່]?\s*ຕັ[້]?\s*ງ\s*ຕອນດ[ິີ]ນ\s*[:：]?\s*([0-9][0-9.\/-]{3,})/u', $flat)) {
        $set('location_code', $v, 70);
    }

    // --- #1 title no, #2 order no ---
    // Blank the longer labels first: "ຕອນດິນເລກທີ" and "ແຜນທີ່ດິນເລກທີ" also contain
    // "ເລກທີ", and reading a parcel number as the title number is a wrong-identity
    // error, not a cosmetic one. The [ີິ]? classes cover the vowel drift OCR
    // shows on real scans (ຕອນດິນເລກທິ, ເຫຼົ້ມທີ).
    $noTitle = preg_replace('/(?:ດິນເລກທ[ີິ]?|ເຫຼ[ັ້ົ]{0,2}ມທ[ີິ]?|ໃບທ[ີິ]?)/u', ' ', $flat);
    if ($v = $first('/ເລກທ[ີິ]?\s*[:：]?\s*(\d[\d.\/-]{0,15})/u', $noTitle)) {
        $set('title_no', rtrim($v, '.-/'), 60);
    }
    if ($v = $first('/ອອກຄັ້ງທີ\s*[:：]?\s*(\d{1,6})/u', $flat)) $set('order_no', $v, 55);

    // --- #4-#6 province / district / village ---
    if (preg_match('/ສະຫວັນນະເຂດ|Savannakhet/iu', $t)) {
        $set('province', 'Savannakhet', 90);
    } elseif ($p = $first('/ແຂວງ\s*[:：]?\s*([\x{0E80}-\x{0EFF}]{2,})/u', $flat)) {
        $set('province', title_trim_value($p), 55);
    }
    $districtName = $first('/ເມືອງ\s*[:：]?\s*([\x{0E80}-\x{0EFF}]{2,})/u', $flat);
    $villageName  = $first('/ບ້ານ\s*[:：]?\s*([\x{0E80}-\x{0EFF}]{2,})/u', $flat);
    if ($districtName) $set('district_name', title_trim_value($districtName), 75);
    if ($villageName)  $set('village_name', title_trim_value($villageName), 75);

    // --- #28 area ---
    $areaRaw = $first('/ເນື້ອທີ[່]?[^\d]{0,15}(\d[\d.,]*)\s*(?:ຕາແມັດ|ຕາລາງ|ຕລ)/u', $flat);
    $areaConf = 80;
    if ($areaRaw === null) {
        $areaRaw = $first('/ເນື້ອທີ[່]?[^\d]{0,15}(\d[\d.,]*)/u', $flat);
        $areaConf = 65;
    }
    if ($areaRaw === null) {
        $areaRaw = $first('/(\d[\d.,]*)\s*(?:ຕາແມັດ|ຕາລາງ\s*ແມັດ|ຕລ\.?\s*ມ|m2|m²|sqm|sq\.?\s*m)/iu', $flat);
        $areaConf = 70;
    }
    if ($areaRaw !== null && ($area = title_normalize_number($areaRaw)) !== null && $area > 0) {
        $set('area_sqm', $area, $areaConf);
    }

    // --- #27 parcel no (never from the bare ເລກທີ label) ---
    if ($v = $first('/ຕອນດ[ິີ]ນເລກທ[ີິ]?\s*[:：]?\s*([0-9A-Za-z\/-]{1,20})/u', $flat)) {
        if (preg_match('/\d/', $v)) $set('parcel_no', $v, 75);
    } elseif ($v = $first('/(?:No|Number)\.?\s*[:：]?\s*(\d[0-9A-Za-z\/-]{0,19})/iu', $flat)) {
        $set('parcel_no', $v, 50);
    }

    // --- #24 book, #25 leaf, #26 map sheet, #29 scale ---
    if ($v = $first('/ເຫຼ[ັ້ົ]{0,2}ມທ[ີິ]?\s*[:：]?\s*(\d{1,6})/u', $flat)) $set('book_no', $v, 60);
    if ($v = $first('/ໃບທ[ີິ]?\s*[:：]?\s*(\d{1,6})/u', $flat))    $set('page_no', $v, 60);
    if ($v = $first('/ແຜນທ[ິີ່]?[^\n]{0,4}ດິນເລກທ[ີິ]?\s*[:：]?\s*([0-9A-Za-zດກ][0-9A-Za-zດກ\/.\- ]{2,23})/u', $flat)) {
        // Some sheets print the ref with a leading sheet no: "8 252-980/03".
        $v = rtrim(str_replace(['ດ', 'ກ'], 'A', preg_replace('/\s+/u', ' ', $v)), ' .-/');
        $v = strtoupper($v);
        if (preg_match('/\d{2,}/', $v)) $set('map_ref_no', $v, 65);
    }
    if ($v = $first('/ມາດຕາສ່ວນ\s*[:：]?\s*1\s*[\/.]\s*(\d{2,6})/u', $flat)) $set('map_scale', '1/' . $v, 50);

    // --- #30-#33 issued at / date ---
    if ($v = $first('/ອອກໃຫ້ທີ່\s*[:：]?\s*([\x{0E80}-\x{0EFF} ]{2,30})/u', $flat)) {
        $set('issued_place', title_trim_value($v), 45);
    }
    if (preg_match('/ວັນທີ\s*[:：]?\s*(\d{1,2})\s*ເດືອນ\s*(\d{1,2})\s*ປີ\s*(\d{4})/u', $flat, $m)) {
        if (checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
            $set('issued_date', sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]), 75);
        }
    }

    // --- title type / land-use class (keyword maps) ---
    foreach ([
        'ໃບຕາດິນ'   => 'land_title',
        'ຕາດິນ'     => 'land_title',
        'ໜັງສືທີ່ດິນ' => 'land_title',
        'ໃບຢັ້ງຢືນ'  => 'survey_cert',
        'ສຳປະທານ'   => 'concession',
    ] as $kw => $code) {
        if (mb_strpos($t, $kw) !== false) { $set('title_type', $code, 60); break; }
    }
    foreach ([
        'ກະສິກຳ'   => 'agricultural',
        'ລ້ຽງສັດ'  => 'agricultural',
        'ທີ່ຢູ່ອາໄສ' => 'residential',
        'ອາໄສ'     => 'residential',
        'ການຄ້າ'   => 'commercial',
        'ພານິດ'    => 'commercial',
    ] as $kw => $code) {
        if (mb_strpos($t, $kw) !== false) { $set('land_use_class', $code, 55); break; }
    }

    // --- owner block (#8-#11) ---
    $ownerName = $first('/(?:ອອກໃຫ້ແກ່|ອອກໃຫ້ແກ|ຊື່ເຈົ້າຂອງ)\s*[:：]?\s*([\x{0E80}-\x{0EFF}][\x{0E80}-\x{0EFF} \t,.]{1,80})/u', $flat);
    if ($ownerName) {
        $ownerName = trim(preg_replace('/[.\-]{2,}/u', ' ', title_trim_value($ownerName)));
        if ($ownerName !== '') {
            $set('owner_name', $ownerName, 50);
            $names = array_values(array_filter(array_map('trim', preg_split('/\s*(?:ແລະ|,|\/)\s*/u', $ownerName))));
            if (count($names) > 1) $set('owner_names', $names, 45);
        }
    }
    if ($idno = $first('/ເລກ\s*(?:ບັດປະຈຳຕົວ|ບັດ)\s*[:：]?\s*([0-9]{6,20})/u', $flat)) {
        $set('owner_id_number', $idno, 60);
    } elseif ($idno = $first('/\b(\d{13})\b/u', $flat)) {
        $set('owner_id_number', $idno, 40);
    }
    if ($addr = $first('/(?:ທີ່ຢູ່ປັດຈຸບັນ|ທີ່ຢູ່)\s*[:：]?\s*([\x{0E80}-\x{0EFF}0-9 \t,.\/]{2,80})/u', $flat)) {
        $set('owner_address', trim(preg_replace('/\s+/u', ' ', $addr)), 50);
        // Structured address segment (#12-#15): "ບ້ານ X ໜ່ວຍ Y ເມືອງ Z ແຂວງ W"
        if ($av = $first('/ບ້ານ\s*[:：]?\s*([\x{0E80}-\x{0EFF}]{2,20})/u', $addr)) $set('owner_address_village', $av, 40);
        if ($au = $first('/ໜ່ວຍ\s*[:：]?\s*(\d{1,4})/u', $addr))               $set('owner_address_unit', $au, 55);
        if ($ad = $first('/ເມືອງ\s*[:：]?\s*([\x{0E80}-\x{0EFF}]{2,20})/u', $addr)) $set('owner_address_district', $ad, 40);
        if ($ap = $first('/ແຂວງ\s*[:：]?\s*([\x{0E80}-\x{0EFF}]{2,20})/u', $addr)) $set('owner_address_province', $ap, 40);
    }

    // --- #16 spouse name ---
    if ($v = $first('/ຊື່ຜົວຫຼືເມຍ\s*[:：]?\s*([\x{0E80}-\x{0EFF} .,]{2,60})/u', $flat)) {
        $set('spouse_name', title_trim_value($v), 40);
    }

    // --- dates: the first printed date is the owner's DOB, the second the issue date ---
    if (preg_match_all('/(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})/', $flat, $dm, PREG_SET_ORDER)) {
        $valid = [];
        foreach ($dm as $d) {
            if (checkdate((int)$d[2], (int)$d[1], (int)$d[3])) {
                $valid[] = sprintf('%04d-%02d-%02d', $d[3], $d[2], $d[1]);
            }
        }
        if (count($valid) >= 2) {
            $set('date_of_birth', $valid[0], 45);
            if (empty($fields['issued_date']) && $valid[1] !== $valid[0]) $set('issued_date', $valid[1], 60);
        } elseif (count($valid) === 1 && empty($fields['issued_date'])) {
            $set('issued_date', $valid[0], 55);
        }
    }
    if ($nat = $first('/(?:ເຊື້ອເຜົາ|ສັນຊາດ|ສັນຊາຕາ)\s*[:：]?\s*([\x{0E80}-\x{0EFF}]{2,15})/u', $flat)) {
        $set('nationality', $nat, 45);
    }
    if ($occ = $first('/ອາຊີບ\s*[:：]?\s*([\x{0E80}-\x{0EFF}]{2,25})/u', $flat)) {
        $set('occupation', $occ, 45);
    }

    // --- #20 acquisition / #21 ownership status / #23 descriptive zone ---
    if ($am = $first('/ການ\s*(ໂອນ|ຖອນ|ຊື້|ຂາຍ|ຫຼັກ)\s*/u', $flat)) {
        $set('acquisition_method', 'ການ' . $am, 40);
    }
    if ($os = $first('/ຖານະ[^:：]{0,30}[:：]\s*([\x{0E80}-\x{0EFF} -]{2,40})/u', $flat)) {
        $set('ownership_status', title_trim_value($os), 40);
    }
    if ($zt = $first('/ເຂດ\s*[:：]?\s*([\x{0E80}-\x{0EFF}\w .\-]{2,40})/u', $flat)) {
        $set('zone_text', title_trim_value($zt), 45);
    }

    // --- map reference fallback (latin pattern with no readable label) ---
    if (empty($fields['map_ref_no']) && ($mr = $first('/([A-Z]{1,2}\d{2,4}[-\/.:]\d{1,4})/ui', $flat))) {
        $set('map_ref_no', strtoupper($mr), 55);
    }

    // --- Resolve geography to ids ---
    $districtId = null;
    if (!empty($fields['district_name'])) {
        [$districtId, $dc] = title_resolve_district($fields['district_name'], $pdo);
        if ($districtId) $set('district_id', $districtId, $dc);
    } elseif (!empty($ctx['district_id'])) {
        $districtId = (int)$ctx['district_id'];
    }
    if (!empty($fields['village_name'])) {
        [$villageId, $vc] = title_resolve_village($fields['village_name'], $districtId, $pdo);
        if ($villageId) $set('village_id', $villageId, $vc);
    }

    return ['fields' => $fields, 'confidence' => $conf, 'overall' => title_overall_confidence($conf)];
}
