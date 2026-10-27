#!/usr/bin/env php
<?php
/**
 * Cron job: Generate fee obligations for active fee years.
 *
 * Usage:
 *   php cron_generate_obligations.php [--year=2026] [--district=1] [--dry-run]
 *
 * Run via cron (e.g., daily at 02:00):
 *   0 2 * * * /usr/bin/php /var/www/html/landtax/cron/cron_generate_obligations.php >> /var/log/landtax/obligations.log 2>&1
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/fee.php';

$argv = $_SERVER['argv'] ?? [];
$options = [
    'year'      => null,
    'district'  => 0,
    'dryRun'    => false,
];

foreach ($argv as $arg) {
    if (strpos($arg, '--year=') === 0)      $options['year']     = (int)substr($arg, 7);
    if (strpos($arg, '--district=') === 0)  $options['district'] = (int)substr($arg, 12);
    if ($arg === '--dry-run')               $options['dryRun']   = true;
}

$pdo = db_connect();

/**
 * Find fee years that should have obligations generated.
 * - If --year given, use that year only.
 * - Otherwise, find all fee_years where due_date >= today (current/future years)
 *   OR year >= current year (fallback if due_date not set).
 */
function getTargetFeeYears(PDO $pdo, ?int $year = null): array {
    if ($year) {
        $stmt = $pdo->prepare("SELECT year, due_date FROM fee_years WHERE year = :y AND status = 'open'");
        $stmt->execute([':y' => $year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $today = date('Y-m-d');
    $currYear = (int)date('Y');
    // Active years with due_date >= today, or year >= current year (for years without due_date)
    $stmt = $pdo->prepare("
        SELECT year, due_date
        FROM fee_years
        WHERE status = 'open'
          AND (due_date >= :today OR year >= :currYear)
        ORDER BY year
    ");
    $stmt->execute([':today' => $today, ':currYear' => $currYear]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Generate obligations for a fee year across parcels.
 * Returns [assessed, skipped, total_due].
 */
function generateForYear(PDO $pdo, int $year, int $districtId = 0, bool $dryRun = false): array {
    [$billableWhere, $params] = fee_billable_sql('parcels');
    $sql = 'SELECT id, district_id FROM parcels WHERE ' . $billableWhere;
    if ($districtId) { $sql .= ' AND district_id = :did'; $params[':did'] = $districtId; }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $assessed = 0;
    $skipped  = 0;
    $total    = 0.0;

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = (int)$row['id'];

        // Check if obligation already exists
        $chk = $pdo->prepare("SELECT 1 FROM fee_obligations WHERE parcel_id = :pid AND fee_year = :fy");
        $chk->execute([':pid' => $pid, ':fy' => $year]);
        if ($chk->fetch()) { $skipped++; continue; }

        if ($dryRun) { $assessed++; continue; }

        $ob = fee_upsert_obligation($pdo, $pid, $year);
        if (!$ob) { $skipped++; continue; }

        $assessed++;
        $total += (float)$ob['amount_due'];
    }
    return ['assessed' => $assessed, 'skipped' => $skipped, 'total_due' => $total];
}

// --- Main ---
$years = getTargetFeeYears($pdo, $options['year']);
if (!$years) {
    echo "No active fee years to process.\n";
    exit(0);
}

echo "=== Obligation Generation Cron ===\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "Mode: " . ($options['dryRun'] ? 'DRY RUN' : 'LIVE') . "\n";
if ($options['district']) echo "District filter: {$options['district']}\n";
echo "\n";

$grandAssessed = 0;
$grandSkipped  = 0;
$grandTotal    = 0.0;

foreach ($years as $fy) {
    $year = (int)$fy['year'];
    $due  = $fy['due_date'] ?? 'not set';
    echo "--- Fee Year $year (due: $due) ---\n";

    $res = generateForYear($pdo, $year, $options['district'], $options['dryRun']);
    $grandAssessed += $res['assessed'];
    $grandSkipped  += $res['skipped'];
    $grandTotal    += $res['total_due'];

    echo "  Assessed: {$res['assessed']}, Skipped (existing): {$res['skipped']}, Total due: " . number_format($res['total_due'], 2) . " kip\n";
}

echo "\n=== SUMMARY ===\n";
echo "Total assessed:  $grandAssessed\n";
echo "Total skipped:   $grandSkipped\n";
echo "Grand total due: " . number_format($grandTotal, 2) . " kip\n";
echo "Completed at: " . date('Y-m-d H:i:s') . "\n";