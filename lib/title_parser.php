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

/** Case-insensitive similarity 0..1 (PHP fallback when pg_trgm is unavailable). */
function title_similarity($a, $b) {
    $a = mb_strtolower(trim((string)$a), 'UTF-8');
    $b = mb_strtolower(trim((string)$b), 'UTF-8');
    if ($a === '' || $b === '') return 0.0;
    if ($a === $b) return 1.0;
    similar_text($a, $b, $pct);
    return $pct / 100.0;
}

/**
 * Resolve a district name to [id, confidence]. Exact match -> 100; trgm/PHP fuzzy otherwise.
 * @return array{0:?int,1:int}
 */
function title_resolve_district($name, PDO $pdo) {
    $name = trim((string)$name);
    if ($name === '') return [null, 0];

    $st = $pdo->prepare("SELECT id FROM districts
        WHERE name_lo = :n OR lower(name_en) = lower(:n) LIMIT 1");
    $st->execute([':n' => $name]);
    if ($row = $st->fetch()) return [(int)$row['id'], 100];

    try {
        $st = $pdo->prepare("SELECT id, similarity(name_lo, :n) AS s FROM districts
            WHERE name_lo % :n ORDER BY s DESC LIMIT 1");
        $st->execute([':n' => $name]);
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
    $scope = $districtId ? " AND district_id = :did" : "";
    $params = [':n' => $name];
    if ($districtId) $params[':did'] = $districtId;

    $st = $pdo->prepare("SELECT id FROM villages
        WHERE (name_lo = :n OR lower(name_en) = lower(:n))$scope LIMIT 1");
    $st->execute($params);
    if ($row = $st->fetch()) return [(int)$row['id'], 100];

    try {
        $st = $pdo->prepare("SELECT id, similarity(name_lo, :n) AS s FROM villages
            WHERE name_lo % :n$scope ORDER BY s DESC LIMIT 1");
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
    $keys = ['parcel_no', 'area_sqm', 'district_id', 'village_id'];
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
    $s = preg_split('/(?:ທີ່ຢູ່|ອາຍຸ|ເລກ|ວັນທີ|ຊື່|ໜ້າ|ປຶ້ມ|ເຫຼັ້ມ|[0-9])/u', $s)[0] ?? $s;
    return trim($s);
}

/**
 * Main entry point.
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
    $first = function ($re, $subject) { // first capture group, or null
        return preg_match($re, $subject, $m) ? trim($m[1]) : null;
    };

    $t = title_normalize_digits((string)$text);
    $flat = preg_replace('/[ \t]+/u', ' ', $t);

    // --- Province ---
    if (preg_match('/ສະຫວັນນະເຂດ|Savannakhet/iu', $t)) {
        $set('province', 'Savannakhet', 90);
    } elseif ($p = $first('/ແຂວງ\s*([\x{0E80}-\x{0EFF}]{2,})/u', $flat)) {
        $set('province', title_trim_value($p), 55);
    }

    // --- District / village names ---
    $districtName = $first('/ເມືອງ\s*([\x{0E80}-\x{0EFF}]{2,})/u', $flat);
    $villageName  = $first('/ບ້ານ\s*([\x{0E80}-\x{0EFF}]{2,})/u', $flat);
    if ($districtName) $set('district_name', title_trim_value($districtName), 75);
    if ($villageName)  $set('village_name', title_trim_value($villageName), 75);

    // --- Area ---
    $areaRaw = $first('/([0-9][0-9.,]*)\s*(?:ຕາລາງ\s*ແມັດ|ຕລ\.?\s*ມ|m2|m²|sqm|sq\.?\s*m)/iu', $flat);
    $areaConf = 80;
    if ($areaRaw === null) {
        $areaRaw = $first('/ເນື້ອທີ່[^0-9]{0,12}([0-9][0-9.,]*)/u', $flat);
        $areaConf = 65;
    }
    if ($areaRaw !== null && ($area = title_normalize_number($areaRaw)) !== null && $area > 0) {
        $set('area_sqm', $area, $areaConf);
    }

    // --- Parcel no / title no ---
    if ($pn = $first('/ໂຉງດິນ\s*[:：]?\s*([0-9A-Za-z\/\-]{1,20})/u', $flat)) {
        if (preg_match('/\d/', $pn)) $set('parcel_no', $pn, 70);
    } elseif (preg_match_all('/ເລກທີ່\s*[:：]?\s*([0-9A-Za-z\/\-]{1,20})/u', $flat, $mm) && $mm[1]) {
        // Only digit-bearing tokens; prefer one that looks like a parcel number (has a separator).
        $cands = array_values(array_filter($mm[1], fn($c) => preg_match('/\d/', $c)));
        if ($cands) {
            $pick = $cands[0];
            foreach ($cands as $c) { if (preg_match('/[\/\-]/', $c)) { $pick = $c; break; } }
            $set('parcel_no', $pick, 55);
        }
    } elseif ($pn = $first('/(?:No|Number)\.?\s*[:：]?\s*(\d[0-9A-Za-z\/\-]{0,19})/iu', $flat)) {
        $set('parcel_no', $pn, 50);
    }
    if ($tn = $first('/ໜັງສືທີ່ດິນ\s*(?:ເລກທີ່)?\s*[:：]?\s*([0-9A-Za-z\/\-]{1,20})/u', $flat)) {
        if (preg_match('/\d/', $tn)) $set('title_no', $tn, 60);
    }

    // --- Book / volume / page (must start with a digit) ---
    if ($v = $first('/ປຶ້ມ(?:ເລກທີ່)?\s*[:：]?\s*(\d[0-9A-Za-z\/\-]{0,11})/u', $flat)) $set('book_no', $v, 60);
    if ($v = $first('/ເຫຼັ້ມ\s*[:：]?\s*(\d[0-9A-Za-z\/\-]{0,11})/u', $flat))          $set('volume_no', $v, 60);
    if ($v = $first('/ໜ້າ\s*[:：]?\s*(\d[0-9A-Za-z\/\-]{0,11})/u', $flat))            $set('page_no', $v, 60);

    // --- Issued date (dd/mm/yyyy or yyyy-mm-dd) ---
    if (preg_match('/(\d{4})-(\d{1,2})-(\d{1,2})/', $flat, $m)) {
        if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            $set('issued_date', sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]), 70);
        }
    } elseif (preg_match('/ວັນທີ[^0-9]{0,6}(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})/u', $flat, $m)) {
        if (checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
            $set('issued_date', sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]), 65);
        }
    }

    // --- Title type / land-use class (keyword maps) ---
    foreach ([
        'ໜັງສືທີ່ດິນ' => 'land_title',
        'ໂຉງດິນ'     => 'land_title',
        'ໃບຢັ້ງຢືນ'   => 'survey_cert',
        'ສຳປະທານ'    => 'concession',
    ] as $kw => $code) {
        if (mb_strpos($t, $kw) !== false) { $set('title_type', $code, 60); break; }
    }
    foreach ([
        'ທີ່ຢູ່ອາໄສ' => 'residential',
        'ກະສິກຳ'    => 'agricultural',
        'ການຄ້າ'    => 'commercial',
        'ພານິດ'     => 'commercial',
    ] as $kw => $code) {
        if (mb_strpos($t, $kw) !== false) { $set('land_use_class', $code, 55); break; }
    }

    // --- Owner block (best-effort) ---
    $ownerName = $first('/(?:ຊື່ເຈົ້າຂອງ|ຊື່)\s*[:：]?\s*([\x{0E80}-\x{0EFF} \t,]{2,60})/u', $flat);
    if ($ownerName) {
        $ownerName = title_trim_value($ownerName);
        $set('owner_name', $ownerName, 50);
        $names = array_values(array_filter(array_map('trim', preg_split('/\s*(?:ແລະ|,|\/)\s*/u', $ownerName))));
        if (count($names) > 1) $set('owner_names', $names, 45);
    }
    if ($idno = $first('/ເລກ\s*(?:ບັດປະຈຳຕົວ|ບັດ)\s*[:：]?\s*([0-9]{6,20})/u', $flat)) {
        $set('owner_id_number', $idno, 60);
    } elseif ($idno = $first('/\b(\d{13})\b/', $flat)) {
        $set('owner_id_number', $idno, 40);
    }
    if ($addr = $first('/ທີ່ຢູ່\s*[:：]?\s*([\x{0E80}-\x{0EFF}0-9 \t,.\/]{2,80})/u', $flat)) {
        $set('owner_address', trim(preg_replace('/\s+/u', ' ', $addr)), 50);
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
