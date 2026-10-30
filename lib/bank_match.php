<?php
// Bank statement import + matching engine (design §5).
// Matching is reference-driven; amounts only decide matched / overpaid / partial.
require_once __DIR__ . '/fee.php';

/**
 * Extract an LFE reference from free-form statement text.
 * Tolerates spaces, lowercase, and missing separators: "LFE 2026 000123", "lfe-2026-000123".
 * Returns the normalized "LFE-2026-000123" or null.
 */
function bank_extract_reference($text)
{
    if (!is_string($text) || $text === '') {
        return null;
    }
    $up = strtoupper($text);
    if (preg_match('/LFE[\s\-_]*([0-9]{4})[\s\-_]*([0-9]{4,8})/', $up, $m)) {
        return 'LFE-' . $m[1] . '-' . str_pad(substr($m[2], 0, 6), 6, '0', STR_PAD_LEFT);
    }
    return null;
}

/** Normalize a statement amount string ("1,250.50", "1250.50 kip", "(500)") to float. */
function bank_normalize_amount($value)
{
    if (is_numeric($value)) {
        return (float)$value;
    }
    $s = trim((string)$value);
    if ($s === '') {
        return null;
    }
    $neg = false;
    if (preg_match('/^\((.*)\)$/', $s, $m)) {          // accounting negative
        $neg = true;
        $s = $m[1];
    }
    $s = preg_replace('/[^0-9.\-]/u', '', $s);         // drop currency words/commas/spaces
    if ($s === '' || $s === '-' || !is_numeric($s)) {
        return null;
    }
    $n = (float)$s;
    return $neg ? -abs($n) : abs($n);
}

/** Normalize a statement date ("2026-03-31", "31/03/2026", "31-03-2026") to Y-m-d, or null. */
function bank_normalize_date($value)
{
    $s = trim((string)$value);
    if ($s === '') {
        return null;
    }
    if (preg_match('/(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})/', $s, $m)) {
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
    if (preg_match('/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $s, $m)) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);   // day/month/year
    }
    return null;
}

/**
 * Parse a CSV statement into normalized rows.
 * $map: ['date'=>int|null,'amount'=>int,'reference'=>int|null,'bank_ref'=>int|null]
 * Column indices are 0-based and resolved from the header when a header row is present.
 */
function bank_parse_csv($csv, array $map, bool $hasHeader = true)
{
    $lines = preg_split('/\r\n|\r|\n/', trim($csv));
    $rows = [];
    if (!$lines) {
        return $rows;
    }
    $header = null;
    if ($hasHeader) {
        $header = str_getcsv(array_shift($lines));
        // Normalize: "Bank Ref", "BankRef", "AMOUNT" all collapse to a comparable token.
        $header = array_map(
            fn($h) => preg_replace('/[^a-z0-9]/', '', strtolower((string)$h)),
            $header
        );
    }
    // Column aliases, so unknown bank exports still map sensibly.
    $aliases = [
        'date'      => ['date', 'txndate', 'transactiondate', 'valuedate', 'postdate'],
        'amount'    => ['amount', 'amt', 'credit', 'deposit', 'value', 'total'],
        'reference' => ['reference', 'ref', 'refno', 'referenceno', 'paymentref', 'narration'],
        'bank_ref'  => ['bankref', 'bankno', 'bankreference', 'transactionref', 'txnref', 'transactionno'],
    ];
    $resolve = function ($key, $default) use ($header, $map, $aliases) {
        $i = $map[$key] ?? null;
        if ($i === null && $header) {
            foreach ($header as $n => $h) {
                if ($h === '') { continue; }
                foreach ($aliases[$key] as $a) {
                    if ($h === $a || strpos($h, $a) !== false) { $i = $n; break 2; }
                }
            }
        }
        return $i === null ? $default : (int)$i;
    };
    $iDate = $resolve('date', 0);
    $iAmt  = $resolve('amount', 1);
    $iRef  = $resolve('reference', -1);
    $iBank = $resolve('bank_ref', -1);

    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $cells = str_getcsv($line);
        $raw = implode(',', $cells);
        $amount = bank_normalize_amount($cells[$iAmt] ?? '');
        if ($amount === null) {
            continue;
        }
        $refTxt = $iRef >= 0 ? trim((string)($cells[$iRef] ?? '')) : '';
        $bankRef = $iBank >= 0 ? trim((string)($cells[$iBank] ?? '')) : '';
        $rows[] = [
            'txn_date'     => bank_normalize_date($cells[$iDate] ?? ''),
            'amount'       => $amount,
            'reference_no' => bank_extract_reference($refTxt),
            'ref_raw'      => $refTxt,
            'bank_ref'     => $bankRef ?: null,
            'raw_line'     => $raw,
        ];
    }
    return $rows;
}

/**
 * Run the matching engine over an import's transactions.
 * Per design §5:
 *   exact ref + amount == due -> matched (auto-apply)
 *   exact ref + amount >  due -> matched, obligation OVERPAID
 *   exact ref + amount <  due -> partial (officer queue)
 *   ref unknown               -> unmatched; no ref but unique amount+date -> candidate hint
 *   ref already consumed      -> duplicate (never auto-applied)
 * Idempotent: transactions already matched/ignored are skipped.
 * $districtId limits matching to that district's parcels (null = all).
 * Returns a summary array.
 */
function bank_match_run(PDO $pdo, int $importId, $actorId = null, ?int $districtId = null)
{
    $stmt = $pdo->prepare("SELECT * FROM bank_transactions
        WHERE import_id = :i AND match_status IN ('unmatched','partial')
        ORDER BY id");
    $stmt->execute([':i' => $importId]);
    $txns = $stmt->fetchAll();

    $summary = ['matched' => 0, 'overpaid' => 0, 'partial' => 0, 'duplicate' => 0,
        'unmatched' => 0, 'candidate' => 0];

    foreach ($txns as $t) {
        $ref = $t['reference_no'] ?: bank_extract_reference((string)$t['raw_line']);
        $amount = (float)$t['amount'];

        if (!$ref) {
            // No reference: hint a candidate when exactly one open payment has this amount.
            $cand = null;
            $candSql = "SELECT p.id FROM payments p";
            $candArgs = [':a' => $amount];
            if ($districtId !== null) {
                $candSql .= " JOIN parcels par ON par.id = p.parcel_id WHERE par.district_id = :did";
                $candArgs[':did'] = $districtId;
                $candSql .= " AND p.status IN ('ISSUED','SUBMITTED') AND p.amount = :a";
            } else {
                $candSql .= " WHERE p.status IN ('ISSUED','SUBMITTED') AND p.amount = :a";
            }
            $st = $pdo->prepare($candSql . " ORDER BY p.created_at");
            $st->execute($candArgs);
            $ids = $st->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) === 1) {
                $cand = (int)$ids[0];
                $summary['candidate']++;
            } else {
                $summary['unmatched']++;
            }
            $pdo->prepare("UPDATE bank_transactions
                SET reference_no = :ref, matched_payment_id = :pid, match_status = 'unmatched'
                WHERE id = :id")
                ->execute([':ref' => $ref, ':pid' => $cand, ':id' => $t['id']]);
            continue;
        }

        // Find the payment for this reference (within the actor's district, if scoped).
        $paySql = "SELECT pay.* FROM payments pay";
        $payArgs = [':ref' => $ref];
        if ($districtId !== null) {
            $paySql .= " JOIN parcels par ON par.id = pay.parcel_id";
        }
        $paySql .= " WHERE pay.reference_no = :ref";
        if ($districtId !== null) {
            $paySql .= " AND par.district_id = :did";
            $payArgs[':did'] = $districtId;
        }
        $st = $pdo->prepare($paySql);
        $st->execute($payArgs);
        $pay = $st->fetch();
        if (!$pay) {
            $summary['unmatched']++;
            $pdo->prepare("UPDATE bank_transactions SET reference_no = :ref, match_status = 'unmatched' WHERE id = :id")
                ->execute([':ref' => $ref, ':id' => $t['id']]);
            continue;
        }

        // Already consumed by another statement row -> duplicate, never auto-apply.
        $st = $pdo->prepare("SELECT 1 FROM bank_transactions
            WHERE matched_payment_id = :pid AND id <> :id AND match_status = 'matched' LIMIT 1");
        $st->execute([':pid' => (int)$pay['id'], ':id' => (int)$t['id']]);
        if ($st->fetchColumn() || $pay['status'] === 'PAID') {
            $summary['duplicate']++;
            $pdo->prepare("UPDATE bank_transactions SET reference_no = :ref, matched_payment_id = :pid,
                match_status = 'duplicate' WHERE id = :id")
                ->execute([':ref' => $ref, ':pid' => (int)$pay['id'], ':id' => $t['id']]);
            continue;
        }

        // Due amount for the payment's obligation, priced at the statement date so
        // a payment made before the due date is not surcharged for a late import.
        $due = (float)$pay['amount'];
        if (!empty($pay['obligation_id'])) {
            $pen = fee_penalty_sql('o', 'CAST(:asof AS date)');
            $st = $pdo->prepare("SELECT o.amount_due, $pen AS penalty
                FROM fee_obligations o WHERE o.id = :id");
            $st->execute([
                ':asof' => $t['txn_date'] ?: date('Y-m-d'),
                ':id'   => (int)$pay['obligation_id'],
            ]);
            $ob = $st->fetch();
            if ($ob) { $due = (float)$ob['amount_due'] + (float)$ob['penalty']; }
        }

        if ($amount + 0.005 < $due) {
            // Underpayment: queue for officer, do not auto-apply.
            $summary['partial']++;
            $pdo->prepare("UPDATE bank_transactions SET reference_no = :ref, matched_payment_id = :pid,
                match_status = 'partial' WHERE id = :id")
                ->execute([':ref' => $ref, ':pid' => (int)$pay['id'], ':id' => $t['id']]);
            continue;
        }

        // Exact or overpayment -> auto-apply. Record the amount the bank actually
        // credited (may exceed the issued amount, which is what makes it OVERPAID).
        $pdo->prepare("UPDATE payments SET status='PAID', amount = :amt, paid_at=:at,
            verify_method='auto_statement', bank_import_id=:imp, bank_ref=:bref WHERE id=:id")
            ->execute([
                ':amt'  => $amount,
                ':at'   => $t['txn_date'] ?: date('Y-m-d') . ' 00:00:00',
                ':imp'  => $importId,
                ':bref' => $t['bank_ref'] ?: null,
                ':id'   => (int)$pay['id'],
            ]);
        $obligation = !empty($pay['obligation_id'])
            ? fee_settle_obligation($pdo, (int)$pay['obligation_id'], $t['txn_date'] ?: null)
            : null;

        $isOver = $amount > $due + 0.005;
        $pdo->prepare("UPDATE bank_transactions SET reference_no = :ref, matched_payment_id = :pid,
            match_status = 'matched' WHERE id = :id")
            ->execute([':ref' => $ref, ':pid' => (int)$pay['id'], ':id' => $t['id']]);

        if ($isOver) { $summary['overpaid']++; } else { $summary['matched']++; }
        fee_audit($pdo, $actorId, 'auto_match', 'payment', (int)$pay['id'], [
            'import_id' => $importId, 'reference_no' => $ref, 'txn_amount' => $amount, 'due' => $due,
            'overpaid' => $isOver, 'obligation_status' => $obligation['status'] ?? null,
        ]);
    }
    return $summary;
}