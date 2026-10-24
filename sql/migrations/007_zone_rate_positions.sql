-- Migration 007 — Decree-142 zone position tiers (7-column regulation model)
-- Mirrors the columns of Decree 142/ລົງ (26 Feb 2021) valuation table:
--   (1) ເຂດແຜນມາດຕະຖານ plan      (2) ລະຫັດເຂດ code
--   (3) ບ້ານ ແລະ ສິ່ງກຸມມີນ  (4) ກຸມມີນສະເໝີຕົ້ນຕາ center
--   (5) ກຸມມີນສະເໝີ ເຄີ່ອນທີ sub-center  (6) ກຸມມີນສະເໝີ ແນວທີ lane
--   (7) ຫາງກີບ/ບ້ານ ມີທາງ road-side
--
-- This ADDS the decree's 4 land-value positions alongside the existing
-- road-access tiers. It is additive and idempotent; existing fee logic and
-- parcel.road_category are untouched. fee_obligations.rate_per_sqm keeps
-- serving the road-tier rate; the position rates are available for the
-- officer-facing valuation schedule and future parcel position pricing.
--
-- APPLY MANUALLY on the server:
--   sudo -u postgres psql -d landfee -f 007_zone_rate_positions.sql
-- Grant the web role after COMMIT (same pattern as migration 006):
--   GRANT SELECT, INSERT, UPDATE, DELETE ON zone_rate_positions TO <web_role>;
--   GRANT USAGE, SELECT ON SEQUENCE zone_rate_positions_id_seq TO <web_role>;
--
SET search_path = landfee;
BEGIN;

-- ---------------------------------------------------------------------------
-- 1. Decree position tiers: one row per zone × position × valid_from.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS zone_rate_positions (
  id SERIAL PRIMARY KEY,
  zone_code VARCHAR(20) NOT NULL,
  position VARCHAR(20) NOT NULL,          -- center | sub_center | lane | road_side
  rate_per_sqm NUMERIC(14,2) NOT NULL CHECK (rate_per_sqm >= 0),
  valid_from DATE NOT NULL DEFAULT CURRENT_DATE,
  note TEXT,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_zrp_zone FOREIGN KEY (zone_code) REFERENCES zones(code) ON DELETE CASCADE,
  CONSTRAINT chk_zrp_position CHECK (position IN ('center','sub_center','lane','road_side')),
  CONSTRAINT uq_zrp_zone_pos_from UNIQUE (zone_code, position, valid_from)
);
CREATE INDEX IF NOT EXISTS idx_zrp_lookup
  ON zone_rate_positions (zone_code, position, valid_from DESC);

-- ---------------------------------------------------------------------------
-- 2. Backfill: every active zone gets the decree's 4 position rates.
--    Default = the zone's fallback rate_per_sqm until the province supplies
--    the real per-position figures (decree columns 4-7).
-- ---------------------------------------------------------------------------
INSERT INTO zone_rate_positions (zone_code, position, rate_per_sqm, valid_from)
SELECT z.code, p.position, z.rate_per_sqm, z.valid_from
FROM zones z
CROSS JOIN (VALUES
  ('center'), ('sub_center'), ('lane'), ('road_side')
) AS p(position)
WHERE z.rate_per_sqm IS NOT NULL
ON CONFLICT (zone_code, position, valid_from) DO NOTHING;

COMMIT;