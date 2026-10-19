-- Migration 003 — Zone code (replaces district+road_category rate model)
-- See docs/5-annual-land-fee-back-office-design.md and the user decision:
--   * Rate depends on a ZONE CODE (e.g. G17, A7, C7, C8) — as used in the legacy
--     LLR7 valuation zones — NOT district + road_category.
--   * Zones are addable/editable by the admin in Basic Data > Zone rates.
--   * Rates start as placeholders (80k–180k kip/m²); the province sets the real ones.
--
-- APPLY MANUALLY on the server:  psql -f 003_zone_code.sql
-- Additive (non-destructive): preserves all existing tables/data.
--
SET search_path = landfee;
BEGIN;

-- ---------------------------------------------------------------------------
-- 1. Zone reference table
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS zones (
  code VARCHAR(20) PRIMARY KEY,
  name_lo VARCHAR(80),
  name_en VARCHAR(80),
  rate_per_sqm DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  valid_from DATE NOT NULL DEFAULT CURRENT_DATE,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  note TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Seed zones seen in the legacy LLR7 transcripts (G17, A7, C7, C8) with
-- placeholder rates in the 80k–180k band. The admin edits them in Basic Data.
INSERT INTO zones (code, name_lo, name_en, rate_per_sqm, valid_from) VALUES
  ('G17', 'ເຂດ G17', 'Zone G17', 180000.00, CURRENT_DATE),
  ('A7',  'ເຂດ A7',  'Zone A7',  140000.00, CURRENT_DATE),
  ('C7',  'ເຂດ C7',  'Zone C7',  110000.00, CURRENT_DATE),
  ('C8',  'ເຂດ C8',  'Zone C8',  85000.00,  CURRENT_DATE)
ON CONFLICT (code) DO NOTHING;

-- ---------------------------------------------------------------------------
-- 2. parcels: add zone_code (nullable — officers assign it; fee needs it)
-- ---------------------------------------------------------------------------
ALTER TABLE parcels ADD COLUMN IF NOT EXISTS zone_code VARCHAR(20);
-- Backfill existing parcels with a zone if none (map old road_category loosely).
UPDATE parcels SET zone_code = 'A7'
  WHERE zone_code IS NULL AND (road_category IS NULL OR road_category = 'none');
UPDATE parcels SET zone_code = 'C7'
  WHERE zone_code IS NULL AND road_category = 'alley';
UPDATE parcels SET zone_code = 'C8'
  WHERE zone_code IS NULL AND road_category = 'branch';
UPDATE parcels SET zone_code = 'G17'
  WHERE zone_code IS NULL AND road_category = 'main';

-- ---------------------------------------------------------------------------
-- 3. fee_obligations: add zone_code snapshot (nullable; backfill from parcels)
-- ---------------------------------------------------------------------------
ALTER TABLE fee_obligations ADD COLUMN IF NOT EXISTS zone_code VARCHAR(20);
UPDATE fee_obligations fo
  SET zone_code = p.zone_code
  FROM parcels p
  WHERE fo.parcel_id = p.id AND fo.zone_code IS NULL;

COMMIT;