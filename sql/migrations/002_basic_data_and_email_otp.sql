-- =========================================================================
-- 002 — Basic data + email OTP
--   * provinces table (single configurable province, FK from districts)
--   * districts.province_id
--   * users.email for OTP login (replaces the SMS channel)
-- Additive and idempotent: safe to run on an existing v2 database.
-- =========================================================================

SET search_path = landfee;

BEGIN;

-- -------------------------------------------------------------------------
-- Province
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS provinces (
  id SERIAL PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name_lo VARCHAR(100) NOT NULL,
  name_en VARCHAR(100),
  note TEXT,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO provinces (code, name_lo, name_en) VALUES ('SVK', 'ສະຫວັນນະເຂດ', 'Savannakhet')
ON CONFLICT (code) DO NOTHING;

-- -------------------------------------------------------------------------
-- districts.province_id
-- -------------------------------------------------------------------------
ALTER TABLE districts ADD COLUMN IF NOT EXISTS province_id INT;

-- Backfill every existing district to the first province before enforcing NOT NULL.
UPDATE districts
   SET province_id = (SELECT MIN(id) FROM provinces)
 WHERE province_id IS NULL;

DO $$
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
     WHERE table_name = 'districts' AND column_name = 'province_id' AND is_nullable = 'YES'
  ) THEN
    ALTER TABLE districts ALTER COLUMN province_id SET NOT NULL;
  END IF;
END $$;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_districts_province') THEN
    ALTER TABLE districts
      ADD CONSTRAINT fk_districts_province FOREIGN KEY (province_id) REFERENCES provinces(id);
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_districts_province ON districts (province_id);

-- -------------------------------------------------------------------------
-- users.email — OTP login channel (SMS skipped: no gateway)
-- -------------------------------------------------------------------------
ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(190);

-- Case-insensitive uniqueness, and only for rows that actually have an address.
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email_unique
  ON users (lower(email)) WHERE email IS NOT NULL;

COMMIT;