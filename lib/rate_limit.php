<?php
// lib/rate_limit.php — a small fixed-window attempt throttle.
//
// Used by api/v1/citizen_auth.php: phone sign-in has no secret, so the only
// available ceiling is on the number of attempts. Buckets are namespaced
// strings ('phone:<digits>', 'ip:<addr>', 'signup:global') so unrelated limits
// never share a counter.
//
// The table is created by sql/migrations/016_auth_rate_limit.sql.
//
// Fail-open is deliberate: if the throttle table is missing or the statement
// fails for any reason, sign-in proceeds and the fault is written to the error
// log. A broken throttle must never lock every citizen out of the app.

require_once __DIR__ . '/db.php';

/**
 * Count one attempt against $bucket and report whether it is now over $max.
 *
 * Returns null when the caller may proceed, or the whole number of seconds to
 * wait when the bucket is over its limit (for a Retry-After header).
 *
 * The counter rolls over by itself once the window has elapsed, so an idle
 * bucket needs no cleanup.
 */
function rate_limit_hit(PDO $pdo, string $bucket, int $max, int $windowSeconds): ?int
{
    // pdo_pgsql does not tolerate one named placeholder used more than once, so
    // the window length is bound under three distinct names.
    $sql = "INSERT INTO auth_attempts (bucket, attempts, window_start)
            VALUES (:b, 1, now())
            ON CONFLICT (bucket) DO UPDATE SET
              attempts = CASE
                  WHEN auth_attempts.window_start <= now() - (CAST(:w1 AS int) * interval '1 second')
                  THEN 1 ELSE auth_attempts.attempts + 1 END,
              window_start = CASE
                  WHEN auth_attempts.window_start <= now() - (CAST(:w2 AS int) * interval '1 second')
                  THEN now() ELSE auth_attempts.window_start END
            RETURNING attempts,
              GREATEST(0, CEIL(EXTRACT(EPOCH FROM
                (window_start + (CAST(:w3 AS int) * interval '1 second') - now()))))::int AS retry_after";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':b'  => $bucket,
            ':w1' => $windowSeconds,
            ':w2' => $windowSeconds,
            ':w3' => $windowSeconds,
        ]);
        $row = $stmt->fetch();
    } catch (\Throwable $e) {
        // Throttle unavailable — let the request through rather than block it.
        error_log('rate_limit_hit failed for bucket ' . $bucket . ': ' . $e->getMessage());
        return null;
    }

    if (!$row) {
        return null;
    }
    if ((int)$row['attempts'] > $max) {
        return max(1, (int)$row['retry_after']);
    }
    return null;
}

/**
 * Drop buckets that have not been touched for a day. The sign-in endpoint calls
 * this on a small random sample of requests, so it never needs its own cron.
 */
function rate_limit_prune(PDO $pdo): void
{
    try {
        $pdo->exec("DELETE FROM auth_attempts WHERE window_start < now() - interval '1 day'");
    } catch (\Throwable $e) {
        // Housekeeping only; never surface this to the caller.
    }
}

/**
 * Refuse the request with 429 + Retry-After. The app already renders
 * error.message, so the wording here is what the citizen reads.
 */
function rate_limit_refuse(int $retryAfter): void
{
    header('Retry-After: ' . $retryAfter);
    $minutes = (int)ceil($retryAfter / 60);
    $when = $minutes <= 1 ? 'a minute' : $minutes . ' minutes';
    json_error(
        'Too many sign-in attempts. Please wait ' . $when . ' and try again.',
        429,
        'too_many_attempts'
    );
}
