-- =========================================================================
-- Migration 003: staff user levels (district / province) + district scoping
-- Land Tax MVP — apply by hand on the server:
--     psql -U landfee_web -d landfee -f 003_user_levels_and_districts.sql
-- Idempotent: safe to re-run.
--
-- Model:
--   users.level       'district' | 'province'
--   users.district_id NULL for province-level staff
--   role gains 'manager' (read-only staff)
--
-- Permissions (enforced in api/v1, not here):
--   district admin    : CRUD records in own district; create officer+manager in own district
--   district officer  : CRUD records in own district; no account management
--   district manager  : view records in own district; no writes, no accounts
--   province admin    : view all province records; manage all accounts; no record CRUD
--   province officer/manager: view all province records; no writes, no accounts
-- =========================================================================

SET search_path = landfee;

BEGIN;

-- ------------------------------------------------------------------------
-- role CHECK: add 'manager'. The schema creates this as an unnamed inline
-- constraint; drop any existing one and re-add with manager included.
-- ------------------------------------------------------------------------
DO $$
DECLARE
    c RECORD;
BEGIN
    IF EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'landfee.users'::regclass AND contype = 'c') THEN
        FOR c IN
            SELECT conname FROM pg_constraint
            WHERE conrelid = 'landfee.users'::regclass AND contype = 'c'
        LOOP
            EXECUTE 'ALTER TABLE users DROP CONSTRAINT ' || quote_ident(c.conname);
        END LOOP;
    END IF;
END
$$;
ALTER TABLE users ADD CONSTRAINT users_role_check
    CHECK (role IN ('admin','officer','manager','citizen'));

-- ------------------------------------------------------------------------
-- level + district scoping
-- ------------------------------------------------------------------------
ALTER TABLE users ADD COLUMN IF NOT EXISTS level VARCHAR(10) NOT NULL DEFAULT 'district'
    CHECK (level IN ('district','province'));
ALTER TABLE users ADD COLUMN IF NOT EXISTS district_id INT REFERENCES districts(id);
CREATE INDEX IF NOT EXISTS idx_users_district ON users (district_id);

-- title_scans: the scan's own district so a district officer sees only their
-- queue even before the parcel is confirmed (parcel_id is NULL at parse time).
ALTER TABLE title_scans ADD COLUMN IF NOT EXISTS district_id INT REFERENCES districts(id);
CREATE INDEX IF NOT EXISTS idx_scans_district ON title_scans (district_id);

-- ------------------------------------------------------------------------
-- Backfill: keep the existing demo officer usable — scope it to district
-- 1301 (the town district) until it is retired. All other pre-existing
-- staff rows (citizens have no username, so they are untouched) become
-- province-level admins so nothing breaks the moment scoping turns on.
-- ------------------------------------------------------------------------
UPDATE users
SET level = 'district',
    district_id = (SELECT id FROM districts WHERE code = '1301')
WHERE username = 'sav_officer';

UPDATE users
SET level = 'province',
    district_id = NULL,
    role = 'admin'
WHERE username IS NOT NULL
  AND username <> 'sav_officer'
  AND role IN ('admin','officer');

UPDATE title_scans ts SET district_id = p.district_id
FROM parcels p
WHERE p.id = ts.parcel_id AND ts.district_id IS NULL;

COMMIT;

-- Verify: list staff accounts
-- SELECT id, username, role, level, district_id, is_active FROM users WHERE role <> 'citizen' ORDER BY username;
