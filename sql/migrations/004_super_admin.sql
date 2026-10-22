-- =========================================================================
-- Migration 004: super-admin flag
-- Land Tax MVP — apply by hand on the server:
--     psql -U landfee_web -d landfee -f 004_super_admin.sql
-- Idempotent: safe to re-run.
--
-- A super admin (users.is_super = TRUE) sees and edits records in EVERY
-- district and manages all accounts — no district scoping at all.
-- The demo officer sav_officer is promoted to super admin here.
-- =========================================================================

SET search_path = landfee;
BEGIN;

ALTER TABLE users ADD COLUMN IF NOT EXISTS is_super BOOLEAN NOT NULL DEFAULT FALSE;

UPDATE users SET is_super = TRUE WHERE username = 'sav_officer';

COMMIT;

-- Verify:
-- SELECT username, role, level, is_super FROM users WHERE is_super;