-- Migration 006 — Zone rate matrix (district → planning zone → road tier)
-- See docs/11-zone-setup.md.
--
-- Target model (mirrors Decree 142/ລົງ, 26 Feb 2021):
--   fee = zone_rate(zone_code, road_category) × area_sqm
--
-- APPLY MANUALLY on the server:  psql -f 006_zone_rates_v2.sql
-- Additive and idempotent: safe to run on an existing database, and safe to
-- re-run. It never deletes zones or rates.
--
-- If this is applied as a role other than the web app's role (e.g. postgres
-- vs landfee_web), grant the web role after COMMIT:
--   GRANT SELECT, INSERT, UPDATE, DELETE
--     ON zone_plans, zone_rate_tiers TO <web_role>;
--   GRANT USAGE, SELECT ON SEQUENCE zone_rate_tiers_id_seq TO <web_role>;
-- Missing grants make rates/refdata/fee return 500 and the admin table empty.
--
SET search_path = landfee;
BEGIN;

-- ---------------------------------------------------------------------------
-- 1. Valuation-zone categories (ເຂດປະເມີນລາຄາທີ່ດິນ) — editable lookup.
--    Each zone code (A1, A2, …) belongs to one of these categories, which name
--    the grouping on the Decree 142 valuation table (column 1).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS zone_plans (
  code VARCHAR(10) PRIMARY KEY,
  name_lo VARCHAR(100) NOT NULL,
  name_en VARCHAR(100),
  sort_order INT NOT NULL DEFAULT 0
);

INSERT INTO zone_plans (code, name_lo, name_en, sort_order) VALUES
  ('1', 'ເຂດຕົວເມືອງ',        'Urban core',       1),
  ('2', 'ເຂດຊານເມືອງ',        'Peri-urban',       2),
  ('3', 'ເຂດກະສິກຳ',         'Agricultural',     3),
  ('4', 'ເຂດຊົນນະບົດ',        'Rural',            4),
  ('5', 'ເຂດເສດຖະກິດ',        'Commercial',       5),
  ('6', 'ເຂດອຸດສາຫະກຳ',       'Industrial',       6)
ON CONFLICT (code) DO NOTHING;

-- ---------------------------------------------------------------------------
-- 2. Districts get a zone-code letter (A–O for Savannakhet 1301–1315)
-- ---------------------------------------------------------------------------
ALTER TABLE districts ADD COLUMN IF NOT EXISTS zone_letter VARCHAR(5);

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'uq_districts_province_zone_letter'
  ) THEN
    ALTER TABLE districts
      ADD CONSTRAINT uq_districts_province_zone_letter UNIQUE (province_id, zone_letter);
  END IF;
END $$;

-- ---------------------------------------------------------------------------
-- 3. Zones get district + planning zone + display order
-- ---------------------------------------------------------------------------
ALTER TABLE zones ADD COLUMN IF NOT EXISTS district_id INT;
ALTER TABLE zones ADD COLUMN IF NOT EXISTS plan_zone VARCHAR(10);
ALTER TABLE zones ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_zones_district') THEN
    ALTER TABLE zones
      ADD CONSTRAINT fk_zones_district
      FOREIGN KEY (district_id) REFERENCES districts(id) ON DELETE RESTRICT;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_zones_plan') THEN
    ALTER TABLE zones
      ADD CONSTRAINT fk_zones_plan
      FOREIGN KEY (plan_zone) REFERENCES zone_plans(code) ON DELETE RESTRICT;
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_zones_district ON zones (district_id);

-- ---------------------------------------------------------------------------
-- 4. Rate matrix: one row per zone × road category × valid_from
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS zone_rate_tiers (
  id SERIAL PRIMARY KEY,
  zone_code VARCHAR(20) NOT NULL,
  road_category VARCHAR(10) NOT NULL,
  rate_per_sqm NUMERIC(14,2) NOT NULL CHECK (rate_per_sqm >= 0),
  valid_from DATE NOT NULL DEFAULT CURRENT_DATE,
  note TEXT,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_zrt_zone
    FOREIGN KEY (zone_code) REFERENCES zones(code) ON DELETE CASCADE,
  CONSTRAINT fk_zrt_road
    FOREIGN KEY (road_category) REFERENCES road_categories(code),
  CONSTRAINT uq_zrt_zone_road_from UNIQUE (zone_code, road_category, valid_from)
);

CREATE INDEX IF NOT EXISTS idx_zrt_lookup
  ON zone_rate_tiers (zone_code, road_category, valid_from DESC);

-- ---------------------------------------------------------------------------
-- 5. Backfill: every existing zone gets the same rate on all four tiers.
--    (Old model stored a single rate; this preserves today's fee amounts.)
-- ---------------------------------------------------------------------------
INSERT INTO zone_rate_tiers (zone_code, road_category, rate_per_sqm, valid_from)
SELECT z.code, rc.code, z.rate_per_sqm, z.valid_from
FROM zones z
CROSS JOIN road_categories rc
WHERE z.rate_per_sqm IS NOT NULL
ON CONFLICT (zone_code, road_category, valid_from) DO NOTHING;

-- Sort existing legacy zones after district-scoped ones.
UPDATE zones SET sort_order = 9000 WHERE district_id IS NULL AND sort_order = 0;

COMMIT;
