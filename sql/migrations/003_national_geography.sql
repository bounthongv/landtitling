-- =========================================================================
-- 003_national_geography.sql — national province/district/village reference data
-- =========================================================================
-- Additive + idempotent. Safe to run on a live DB: it NEVER truncates geography
-- (parcels/villages FKs would cascade-delete real rows).
--
-- Run this BEFORE sql/seed_geography_national.sql, then load that seed.
-- Apply by hand on the server (CI only deploys code; it never runs migrations).
--
-- What it does:
--   1. Gives villages.code a UNIQUE index so the national seed can UPSERT by code
--      (districts.code / provinces.code already have one).
--   2. Migrates the legacy Savannakhet province code 'SVK' -> the official '13',
--      keeping the same row id so every existing FK survives.
-- -------------------------------------------------------------------------

SET search_path = landfee;

-- 1. UNIQUE(villages.code). Guarded: refuse loudly if duplicates already exist.
DO $$
DECLARE dup int;
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
    WHERE c.relname = 'villages_code_key' AND n.nspname = 'landfee'
  ) THEN
    SELECT count(*) INTO dup FROM (
      SELECT code FROM villages GROUP BY code HAVING count(*) > 1
    ) x;
    IF dup > 0 THEN
      RAISE EXCEPTION 'Cannot add UNIQUE(villages.code): % duplicate code(s) exist. Resolve them first.', dup;
    END IF;
    CREATE UNIQUE INDEX villages_code_key ON villages (code);
  END IF;
END $$;

-- 2. Legacy province code -> official code (keeps the row id, so FKs survive).
UPDATE provinces SET code = '13'
 WHERE code = 'SVK'
   AND NOT EXISTS (SELECT 1 FROM provinces WHERE code = '13');

-- Verify
SELECT (SELECT count(*) FROM provinces) AS provinces,
       (SELECT count(*) FROM districts) AS districts,
       (SELECT count(*) FROM villages)  AS villages;
