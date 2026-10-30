<?php
// Fee helpers: location-based flat rate (kip/m² × area) and annual obligation management.
require_once __DIR__ . '/db.php';

/**
 * Normalise a road-access code. Unknown/empty values price as "none" (the
 * lowest tier) so legacy parcels without a road category still get a fee.
 */
function fee_road_category($roadCategory)
{
    $rc = strtolower(trim((string)$roadCategory));
    return in_array($rc, ['main', 'branch', 'alley', 'none'], true) ? $rc : 'none';
}

/**
 * Current rate for a zone_code + road-access tier as of today.
 * Falls back to zones.rate_per_sqm for legacy zones that have no tier rows.
 * Returns ['rate_per_sqm'=>, 'valid_from'=>, 'road_category'=>, 'source'=>] or null.
 */
function zone_rate_for($pdo, $zoneCode, $roadCategory = null)
{
    $zoneCode = strtoupper(trim((string)$zoneCode));
    $rc = fee_road_category($roadCategory);

    $stmt = $pdo->prepare("SELECT t.rate_per_sqm, t.valid_from, t.road_category, 'tier' AS source
        FROM zone_rate_tiers t
        JOIN zones z ON z.code = t.zone_code
        WHERE t.zone_code = :zc AND t.road_category = :rc
          AND z.is_active = TRUE AND t.valid_from <= CURRENT_DATE
        ORDER BY t.valid_from DESC, t.updated_at DESC LIMIT 1");
    $stmt->execute([':zc' => $zoneCode, ':rc' => $rc]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    $stmt = $pdo->prepare("SELECT rate_per_sqm, valid_from, :rc AS road_category,
            'zone_default' AS source
        FROM zones
        WHERE code = :zc AND is_active = TRUE AND valid_from <= CURRENT_DATE
        ORDER BY valid_from DESC, updated_at DESC LIMIT 1");
    $stmt->execute([':zc' => $zoneCode, ':rc' => $rc]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Fee = tier rate × area, rounded to 2 dp. */
function fee_compute_amount($pdo, $zoneCode, $areaSqm, $roadCategory = null)
{
    $r = zone_rate_for($pdo, $zoneCode, $roadCategory);
    if (!$r) {
        return [
            'rate_per_sqm'  => 0.0,
            'amount'        => 0.0,
            'rate_found'    => false,
            'road_category' => fee_road_category($roadCategory),
        ];
    }
    return [
        'rate_per_sqm'  => (float)$r['rate_per_sqm'],
        'amount'        => round((float)$r['rate_per_sqm'] * (float)$areaSqm, 2),
        'rate_found'    => true,
        'road_category' => $r['road_category'],
    ];
}

/**
 * Late-payment surcharge rate — decree 385/2009 Art. 10: 10% of the year's
 * tax when it is paid after the due date. The decree's other Art. 10
 * surcharges are deliberately out of scope (docs/15-landtax-reg.md §5).
 */
function fee_penalty_rate(): float
{
    return 0.10;
}

/**
 * SQL for the effective surcharge of an obligation aliased $alias.
 *
 * Derived live while the obligation is still owed (UNPAID or PARTIAL — a part
 * payment made before the due date must not lock the surcharge at zero), and
 * frozen once the row reaches a terminal status. The frozen value is written by
 * fee_settle_obligation(). An officer's override (0 = waived) wins over the
 * automatic 10%.
 *
 * $asOf is a SQL expression: CURRENT_DATE normally, or ':asof' when the caller
 * binds a date (bank matching prices at the statement's transaction date).
 */
function fee_penalty_sql(string $alias = 'o', string $asOf = 'CURRENT_DATE'): string
{
    $a    = preg_replace('/[^A-Za-z0-9_.]/', '', $alias);
    $rate = number_format(fee_penalty_rate(), 4, '.', '');
    return "CASE WHEN {$a}.status IN ('PAID','OVERPAID','WAIVED','REFUNDED','DISPUTED')"
        . " THEN COALESCE({$a}.penalty, 0)"
        . " WHEN {$a}.penalty_override IS NOT NULL THEN {$a}.penalty_override"
        . " WHEN {$a}.due_date < {$asOf} THEN ROUND({$a}.amount_due * {$rate}, 2)"
        . " ELSE 0 END";
}

/**
 * PHP mirror of the automatic surcharge, used only to bound an officer's
 * override (waive/reduce, never inflate). Reads go through fee_penalty_sql().
 */
function fee_penalty_auto(float $amountDue, ?string $dueDate, ?string $asOf = null): float
{
    $asOf = $asOf ?: date('Y-m-d');
    if ($dueDate === null || $dueDate === '' || $dueDate >= $asOf) {
        return 0.0;
    }
    return round($amountDue * fee_penalty_rate(), 2);
}

/**
 * Resolve the valuation zone for a parcel write.
 *
 * An explicit zone supplied by the caller wins. When it is blank the parcel's
 * village decides it: villages.zone_id holds the zone CODE (migration 008), the
 * same default the parcel-entry UI shows. This keeps API clients (CSV import,
 * citizen app) from having to know the zone — and keeps them from silently
 * storing an empty zone, which fee_upsert_obligation() would price against its
 * 'A7' placeholder fallback.
 *
 * Returns the zone code (uppercased) or null when neither source has one.
 */
function parcel_zone_for(PDO $pdo, $villageId, $suppliedZone = null)
{
    $zone = strtoupper(trim((string)$suppliedZone));
    if ($zone !== '') {
        return $zone;
    }
    $vid = (int)$villageId;
    if ($vid <= 0) {
        return null;
    }
    $st = $pdo->prepare("SELECT zone_id FROM villages WHERE id = :id");
    $st->execute([':id' => $vid]);
    $zone = strtoupper(trim((string)($st->fetchColumn() ?: '')));
    return $zone !== '' ? $zone : null;
}

/**
 * Parcel statuses an annual fee may be assessed on.
 *
 * 'draft' is not yet a real registry entry, 'scanned' is mid-OCR, and 'archived'
 * is retired land — none of them owe an annual fee. 'confirmed' and 'active' both
 * describe live land, so both are billable. This is the single source of truth:
 * the obligation cron, the bulk generate, and the scan-confirm path all read it,
 * so a parcel can never be billed by one path and skipped by another.
 */
function fee_billable_statuses(): array
{
    return ['confirmed', 'active'];
}

/**
 * Build a "this parcel is billable" SQL predicate plus its bound parameters.
 *
 * Returns [$sql, $params] using NAMED placeholders, so the fragment merges into
 * the existing named-parameter queries — PDO refuses to mix named and positional
 * placeholders in one statement. Use it once per statement (the placeholder names
 * are fixed).
 *
 *   [$where, $params] = fee_billable_sql('p');
 *   // "p.status IN (:billable0,:billable1)", [':billable0' => 'confirmed', ...]
 */
function fee_billable_sql(string $alias = 'p'): array
{
    $params = [];
    $placeholders = [];
    foreach (fee_billable_statuses() as $i => $status) {
        $name = ':billable' . $i;
        $placeholders[] = $name;
        $params[$name] = $status;
    }
    return [$alias . '.status IN (' . implode(',', $placeholders) . ')', $params];
}

/** Ensure a fee_years row for $year (open, due_date = Dec 31). Returns the row. */
function fee_ensure_year($pdo, $year)
{
    $year = (int)$year;
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT year, due_date, status FROM fee_years WHERE year = :y");
        $stmt->execute([':y' => $year]);
        $row = $stmt->fetch();
        if (!$row) {
            $due = sprintf('%04d-12-31', $year);
            $pdo->prepare("INSERT INTO fee_years (year, due_date, status) VALUES (:y, :d, 'open')")
                 ->execute([':y' => $year, ':d' => $due]);
            $row = ['year' => $year, 'due_date' => $due, 'status' => 'open'];
        }
        $pdo->commit();
        return $row;
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Upsert an annual obligation for a parcel (idempotent on parcel_id + fee_year).
 * Recomputes amount from the current rate. If a payment already exists the obligation
 * is left untouched, so a paid/partial row is never downgraded.
 * Returns the obligation row, or null when the parcel does not exist.
 */
function fee_upsert_obligation($pdo, $parcelId, $year)
{
    $parcelId = (int)$parcelId;
    $stmt = $pdo->prepare("SELECT district_id, zone_code, road_category, area_sqm FROM parcels WHERE id = :id");
    $stmt->execute([':id' => $parcelId]);
    $parcel = $stmt->fetch();
    if (!$parcel) {
        return null;
    }
    $zoneCode = $parcel['zone_code'] ?: 'A7'; // fallback so a fee can still be computed
    $road     = fee_road_category($parcel['road_category'] ?? null);
    $fy   = fee_ensure_year($pdo, $year);
    $fee  = fee_compute_amount($pdo, $zoneCode, $parcel['area_sqm'], $road);

    $pdo->prepare("
        INSERT INTO fee_obligations (parcel_id, fee_year, district_id, zone_code, road_category, area_sqm,
            rate_per_sqm, amount_due, amount_paid, penalty, status, due_date, created_at, updated_at)
        VALUES (:pid, :fy, :did, :zc, :rc, :area, :rate, :amt, 0, 0, 'UNPAID', :due, now(), now())
        ON CONFLICT (parcel_id, fee_year) DO UPDATE
          SET district_id = EXCLUDED.district_id,
              zone_code   = EXCLUDED.zone_code,
              road_category = EXCLUDED.road_category,
              area_sqm    = EXCLUDED.area_sqm,
              rate_per_sqm = EXCLUDED.rate_per_sqm,
              amount_due  = EXCLUDED.amount_due,
              penalty_override = CASE
                  WHEN fee_obligations.penalty_override > EXCLUDED.amount_due
                       THEN EXCLUDED.amount_due
                  ELSE fee_obligations.penalty_override END,
              updated_at  = now()
          WHERE fee_obligations.amount_paid = 0
    ")->execute([
        ':pid'  => $parcelId,
        ':fy'   => (int)$year,
        ':did'  => (int)$parcel['district_id'],
        ':zc'   => $zoneCode,
        ':rc'   => $road,
        ':area' => (float)$parcel['area_sqm'],
        ':rate' => $fee['rate_found'] ? (float)$fee['rate_per_sqm'] : 0.0,
        ':amt'  => (float)$fee['amount'],
        ':due'  => $fy['due_date'],
    ]);

    $pen = fee_penalty_sql('o');
    $stmt = $pdo->prepare("SELECT o.id, o.amount_due, o.amount_paid, $pen AS penalty, o.status, o.due_date
        FROM fee_obligations o WHERE o.parcel_id = :pid AND o.fee_year = :fy");
    $stmt->execute([':pid' => $parcelId, ':fy' => (int)$year]);
    return $stmt->fetch();
}

/**
 * Settle an obligation from its payments: recompute amount_paid from PAID rows and
 * set the matching status. This is the ONLY place obligation status is derived, so
 * manual verification and statement auto-matching cannot drift apart.
 *
 * The late-payment surcharge is stamped here at the date the money arrived
 * ($paidOn, the bank statement date when matching; otherwise today). The stamp
 * is final once the status becomes terminal; while the row stays PARTIAL reads
 * keep deriving the surcharge (see fee_penalty_sql). A cleared override still
 * wins over the automatic 10%. Returns the updated obligation row.
 */
function fee_settle_obligation(PDO $pdo, $obligationId, ?string $paidOn = null)
{
    $asOf = 'CURRENT_DATE';
    $args = [':id' => (int)$obligationId];
    if ($paidOn !== null && $paidOn !== '') {
        $asOf = 'CAST(:asof AS date)';
        $args[':asof'] = $paidOn;
    }
    $pen = fee_penalty_sql('o', $asOf);
    $pdo->prepare("UPDATE fee_obligations o SET penalty = $pen, updated_at = now()
        WHERE o.id = :id AND o.status IN ('UNPAID','PARTIAL')")->execute($args);

    $stmt = $pdo->prepare("SELECT amount_due, amount_paid, penalty, status
        FROM fee_obligations WHERE id = :id");
    $stmt->execute([':id' => (int)$obligationId]);
    $ob = $stmt->fetch();
    if (!$ob) {
        return null;
    }

    // Payments still carrying a credit (PAID) count toward the obligation; rejected/refunded do not.
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS paid
        FROM payments WHERE obligation_id = :oid AND status = 'PAID'");
    $stmt->execute([':oid' => (int)$obligationId]);
    $paid = round((float)($stmt->fetch()['paid'] ?? 0), 2);

    $due   = (float)$ob['amount_due'];
    $penal = (float)($ob['penalty'] ?? 0);
    $total = $due + $penal;

    if (in_array($ob['status'], ['WAIVED', 'DISPUTED', 'REFUNDED'], true)) {
        $status = $ob['status'];            // officer decision wins until explicitly cleared
    } elseif ($paid <= 0) {
        $status = 'UNPAID';
    } elseif ($paid + 0.005 < $total) {
        $status = 'PARTIAL';
    } elseif ($paid > $total + 0.005) {
        $status = 'OVERPAID';
    } else {
        $status = 'PAID';
    }

    $pdo->prepare("UPDATE fee_obligations
        SET amount_paid = :paid, status = :st, updated_at = now()
        WHERE id = :id")
        ->execute([':paid' => $paid, ':st' => $status, ':id' => (int)$obligationId]);

    $stmt = $pdo->prepare("SELECT id, parcel_id, fee_year, amount_due, amount_paid, penalty, status, due_date
        FROM fee_obligations WHERE id = :id");
    $stmt->execute([':id' => (int)$obligationId]);
    return $stmt->fetch();
}

/** Audit helper — never throws. */
function fee_audit($pdo, $actorId, $action, $entity, $entityId, $detail = [])
{
    try {
        $pdo->prepare("INSERT INTO audit_log (actor_id, action, entity, entity_id, detail)
            VALUES (:a, :ac, :e, :eid, CAST(:d AS jsonb))")
            ->execute([
                ':a'   => $actorId ?: null,
                ':ac'  => $action,
                ':e'   => $entity,
                ':eid' => (int)$entityId,
                ':d'   => json_encode($detail),
            ]);
    } catch (\Throwable $e) {
        // auditing must never break the flow
    }
}

/**
 * Display label for a payments.method value. Single place the UI and the
 * printed slip/slip_receipt read, so labels never drift:
 *   counter -> Counter (ຈ່າຍຢູ່ຫ້ອງການ), cash -> Cash (ເງິນສົດ),
 *   bank_transfer -> Bank transfer (ໂອນທະນາຄານ), mobile -> Mobile app (ແອັບມືຖື),
 *   cheque -> Cheque (ເຊັກ).
 */
function payment_method_label(string $method): string
{
    require_once __DIR__ . '/i18n.php';   // t() — fee.php is also loaded by API/cron paths
    $labels = [
        'counter'       => 'Counter',
        'cash'          => 'Cash',
        'bank_transfer' => 'Bank transfer',
        'mobile'        => 'Mobile app',
        'cheque'        => 'Cheque',
    ];
    return t($labels[strtolower($method)] ?? ucfirst($method));
}