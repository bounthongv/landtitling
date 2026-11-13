-- Migration 019 — allow users.level = 'national' (ministry managers)
--
-- A 'national' manager is read-only like every manager, but staff_province_id()
-- returns 0 for it, so the dashboard and the staff read endpoints are no longer
-- pinned to Savannakhet: the account sees every province.
--
-- APPLY MANUALLY on the server as the schema owner (postgres), BEFORE creating
-- the first national account:
--   psql -U postgres -d landfee -f 019_national_manager_level.sql
--
-- Idempotent: safe to re-run.

SET search_path = landfee;

ALTER TABLE users DROP CONSTRAINT IF EXISTS users_level_check;
ALTER TABLE users ADD CONSTRAINT users_level_check
  CHECK (level IS NULL OR level IN ('district', 'province', 'national'));
