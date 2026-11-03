-- Migration 016 — sign-in throttle for the citizen phone endpoint
--
-- phone/signin carries no secret: the phone number IS the credential. That
-- means a script can create accounts at line speed (every attempt also appends
-- an audit_log row) and sweep the number space to learn which numbers are
-- registered. This table is the ceiling on that, checked by
-- api/v1/citizen_auth.php through lib/rate_limit.php.
--
-- APPLY MANUALLY on the server as the schema owner (postgres), BEFORE deploying
-- code that reads it:
--   psql -U postgres -d landfee -f 016_auth_rate_limit.sql
--
-- Shape: one row per bucket, fixed window (NOT rolling). A fixed window costs a
-- single atomic statement with no read-modify-write race, and is accurate enough
-- for a flood guard. Bucket keys are namespaced so unrelated limits never share a
-- counter: 'phone:<digits>', 'ip:<addr>', 'signup:global'. Rows roll over by
-- themselves when the window elapses; the endpoint prunes anything untouched for
-- a day, so no cron entry is needed.
--
-- Idempotent: safe to re-run.

SET search_path = landfee;

CREATE TABLE IF NOT EXISTS auth_attempts (
  bucket       TEXT PRIMARY KEY,
  attempts     INT NOT NULL DEFAULT 0,
  window_start TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- The endpoint's prune (DELETE ... WHERE window_start < now() - '1 day').
CREATE INDEX IF NOT EXISTS idx_auth_attempts_window ON auth_attempts (window_start);

COMMENT ON TABLE auth_attempts IS
  'Fixed-window sign-in throttle for the citizen phone sign-in endpoint. Pruned inline by lib/rate_limit.php.';

-- Migrations run as postgres, so the table would be owned by postgres and the
-- web role could not touch it (the usual cause of an empty table plus an
-- empty-body 500). Grant explicitly, but only where the role actually exists,
-- so the same file also loads on a local DB that has no landfee_web.
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'landfee_web') THEN
    EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON auth_attempts TO landfee_web';
  END IF;
END $$;
