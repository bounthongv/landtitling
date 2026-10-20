<?php
// lib/title_template.php — field map for the standard Lao land title (LURC) form.
//
// The national land use right certificate is a standard physical form: every
// value sits in the same place on every sheet, so we can read fields by
// position (region-of-interest OCR) instead of fuzzy-parsing the whole page.
//
// Each entry is a ROW crop: it spans the whole "label: value" line for a field
// (x from the left label to the right edge, y centred on the line, h ~ one line).
// We OCR the row (psm 7 = single line) and then extract just the value with
// lib/title_parser.php::roi_extract_value(). Row crops are far more robust than
// value-only cells, which are thin text over the certificate's decorative border.
//
// Each entry: fractions of the full page (top-left origin):
//   x, y  — top-left of the crop
//   w, h  — crop width / height (h ≈ one text line, keep it tight)
//   pad   — fraction padding added around the crop
//   psm   — Tesseract page mode: 7 = one text line
//   lang  — tesseract language(s) for this crop (number rows use lao+eng)
//
// Calibrated against a real Vientiane sample (see docs/ocr-roi-calibration.md).
// If a new form revision circulates, re-run the calibration and adjust here.
// Keys mirror the parsed_fields names used by lib/title_parser.php.

function title_template_regions(): array {
    return [
        // Certificate serial line (e.g. "…: 0105.003.17 033.0633")
        'title_no'      => ['x' => 0.55, 'y' => 0.1315, 'w' => 0.40, 'h' => 0.034, 'pad' => 0.003, 'psm' => 7, 'lang' => 'lao'],
        // Issuing capital/province line (e.g. "ລະບົບ: ນະຄອນຫຼວງວຽງຈັນ")
        'province'      => ['x' => 0.40, 'y' => 0.156,  'w' => 0.55, 'h' => 0.032, 'pad' => 0.003, 'psm' => 7, 'lang' => 'lao'],
        // District line (label ເມືອງ → value)
        'district_name' => ['x' => 0.30, 'y' => 0.175,  'w' => 0.65, 'h' => 0.032, 'pad' => 0.003, 'psm' => 7, 'lang' => 'lao'],
        // Village line (label ບ້ານ → value)
        'village_name'  => ['x' => 0.30, 'y' => 0.199,  'w' => 0.65, 'h' => 0.030, 'pad' => 0.003, 'psm' => 7, 'lang' => 'lao'],
        // Owner name line
        'owner_name'    => ['x' => 0.04, 'y' => 0.243,  'w' => 0.66, 'h' => 0.036, 'pad' => 0.003, 'psm' => 7, 'lang' => 'lao'],
        // Citizen ID line (value sits right of the label)
        'owner_id_number' => ['x' => 0.55, 'y' => 0.216, 'w' => 0.40, 'h' => 0.030, 'pad' => 0.003, 'psm' => 7, 'lang' => 'lao'],
        // Area line — square-metres value (the largest number on the line)
        'area_sqm'      => ['x' => 0.30, 'y' => 0.423,  'w' => 0.55, 'h' => 0.045, 'pad' => 0.003, 'psm' => 7, 'lang' => 'lao+eng'],
        // Parcel / plot number line (e.g. K260-014)
        'parcel_no'     => ['x' => 0.25, 'y' => 0.5255, 'w' => 0.35, 'h' => 0.050, 'pad' => 0.003, 'psm' => 7, 'lang' => 'lao+eng'],
        // Issue date line
        'issued_date'   => ['x' => 0.30, 'y' => 0.7675, 'w' => 0.42, 'h' => 0.045, 'pad' => 0.003, 'psm' => 7, 'lang' => 'lao+eng'],
    ];
}
