<?php
// Fee helpers: location-based flat rate (kip/m² × area) and annual obligation management.
require_once __DIR__ . '/db.php';

/**
 * Current zone rate for a district + road_category as of today.
 * Returns ['rate_per_sqm'=>, 'valid_from'=>] or null.
 */
function zone_rate_for($pdo, $districtId, $roadCategory)
{
    $stmt = $pdo->prepare("SELECT rate_per_sqm, valid_from
        FROM zones_rates
        WHERE district_id = :d AND road_category = :rc AND valid_from <= CURRENT_DATE
        ORDER BY valid_from DESC, id DESC LIMIT 1");
    $stmt->execute([':d' => (int)$districtId, ':rc' => $roadCategory]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Fee = rate × area, rounded to 2 dp. */
function fee_compute_amount($pdo, $districtId, $roadCategory, $areaSqm)
{
    $r = zone_rate_for($pdo, $districtId, $roadCategory);
    if (!$r) {
        return ['rate_per_sqm' => 0.0, 'amount' => 0.0, 'rate_found' => false];
    }
    return [
        'rate_per_sqm' => (float)$r['rate_per_sqm'],
        'amount'       => round((float)$r['rate_per_sqm'] * (float)$areaSqm, 2),
        'rate_found'   => true,
    ];
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
    $stmt = $pdo->prepare("SELECT district_id, road_category, area_sqm FROM parcels WHERE id = :id");
    $stmt->execute([':id' => $parcelId]);
    $parcel = $stmt->fetch();
    if (!$parcel) {
        return null;
    }
    $fy   = fee_ensure_year($pdo, $year);
    $fee  = fee_compute_amount($pdo, $parcel['district_id'], $parcel['road_category'], $parcel['area_sqm']);

    $pdo->prepare("
        INSERT INTO fee_obligations (parcel_id, fee_year, district_id, road_category, area_sqm,
            rate_per_sqm, amount_due, amount_paid, penalty, status, due_date, created_at, updated_at)
        VALUES (:pid, :fy, :did, :rc, :area, :rate, :amt, 0, 0, 'UNPAID', :due, now(), now())
        ON CONFLICT (parcel_id, fee_year) DO UPDATE
          SET district_id   = EXCLUDED.district_id,
              road_category = EXCLUDED.road_category,
              area_sqm      = EXCLUDED.area_sqm,
              rate_per_sqm  = EXCLUDED.rate_per_sqm,
              amount_due    = EXCLUDED.amount_due,
              updated_at    = now()
          WHERE fee_obligations.amount_paid = 0
    ")->execute([
        ':pid'  => $parcelId,
        ':fy'   => (int)$year,
        ':did'  => (int)$parcel['district_id'],
        ':rc'   => $parcel['road_category'] ?: 'none',
        ':area' => (float)$parcel['area_sqm'],
        ':rate' => $fee['rate_found'] ? (float)$fee['rate_per_sqm'] : 0.0,
        ':amt'  => (float)$fee['amount'],
        ':due'  => $fy['due_date'],
    ]);

    $stmt = $pdo->prepare("SELECT id, amount_due, amount_paid, penalty, status, due_date
        FROM fee_obligations WHERE parcel_id = :pid AND fee_year = :fy");
    $stmt->execute([':pid' => $parcelId, ':fy' => (int)$year]);
    return $stmt->fetch();
}

/**
 * Settle an obligation from its payments: recompute amount_paid from PAID rows and
 * set the matching status. This is the ONLY place obligation status is derived, so
 * manual verification and statement auto-matching cannot drift apart.
 * Returns the updated obligation row.
 */
function fee_settle_obligation(PDO $pdo, $obligationId)
{
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

    if ($ob['status'] === 'WAIVED' || $ob['status'] === 'DISPUTED') {
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