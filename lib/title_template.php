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
// Calibrated against the standard Lao land title form (Lao PDR standard):
//   - Image ~637x871 after PNG decode (the standard scanned form size)
//   - Labels sit at x ~0.71 (e.g., ແຂວງ at 453/637), values start at x ~0.77
//   - Vertical positions (from TSV word boxes on a real sample):
//     title_no       y=131/871=0.150  (serial at top right)
//     province       y=159/871=0.183  (ແຂວງ label + value)
//     district_name  y=175/871=0.201  (ເມືອງ label + value)
//     village_name   y=195/871=0.224  (ບ້ານ label + value)
//     owner_name     y=230/871=0.264  (approximate, not directly calibrated)
//     owner_id       y=270/871=0.310  (approximate)
//     area_sqm       y=422/871=0.485  (area value row)
//     parcel_no      y=135/871=0.155  (parcel serial at top)
//     issued_date    y=680/871=0.781  (approximate, bottom section)
//   If a new form revision circulates, re-run the calibration and adjust here.
// Keys mirror the parsed_fields names used by lib/title_parser.php.

function title_template_regions(): array {
    return [
        // Certificate serial (top right, e.g. "06.001.0017") — Lao label "ດືນ:" or "ທີ່ຕັ້ງ" prefix
        'title_no'      => ['x' => 0.68, 'y' => 0.150, 'w' => 0.30, 'h' => 0.035, 'pad' => 0.003, 'psm' => 7, 'lang' => 'lao'],
        // Issuing province line: "ແຂວງ : ຫຼວງພະບາງ"
        'province'      => ['x' => 0.68, 'y' => 0.182, 'w' => 0.30, 'h' => 0.032, 'pad' => 0.003, 'psm' => 7, 'lang' => 'lao'],
        // District line: "ເມືອງ : ນະຄອນ ຫຼວງພະບາງ"
        'district_name' => ['x' => 0.68, 'y' => 0.200, 'w' => 0.30, 'h' => 0.032, 'pad' => 0.003, 'psm' => 7, 'lang' => 'lao'],
        // Village line: "ບ້ານ : ຜານົມ"
        'village_name'  => ['x' => 0.68, 'y' => 0.222, 'w' => 0.30, 'h' => 0.032, 'pad' => 0.003, 'psm' => 7, 'lang' => 'lao'],
        // Owner name (approximate)
        'owner_name'    => ['x' => 0.05, 'y' => 0.265, 'w' => 0.70, 'h' => 0.040, 'pad' => 0.003, 'psm' => 7, 'lang' => 'lao'],
        // Owner ID (approximate)
        'owner_id_number' => ['x' => 0.55, 'y' => 0.310, 'w' => 0.40, 'h' => 0.035, 'pad' => 0.003, 'psm' => 7, 'lang' => 'lao'],
        // Area row: "ຕາແມັດ : 1700 ມາດຕາສວນ"
        'area_sqm'      => ['x' => 0.40, 'y' => 0.482, 'w' => 0.55, 'h' => 0.045, 'pad' => 0.003, 'psm' => 7, 'lang' => 'lao+eng'],
        // Parcel/plot number (top section, near serial)
        'parcel_no'     => ['x' => 0.55, 'y' => 0.155, 'w' => 0.40, 'h' => 0.035, 'pad' => 0.003, 'psm' => 7, 'lang' => 'lao+eng'],
        // Issue date (bottom section)
        'issued_date'   => ['x' => 0.55, 'y' => 0.780, 'w' => 0.40, 'h' => 0.040, 'pad' => 0.003, 'psm' => 7, 'lang' => 'lao+eng'],
    ];
}
