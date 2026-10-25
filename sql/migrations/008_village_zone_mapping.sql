-- Migration 008 — village→zone mapping + decree road-type labels
--
-- Corrects the zone-rate model after OCR of the original Decree 142/ລົງ PDF.
-- The decree's 4 rate columns per zone are ROAD TYPES, not land positions:
--     (4) ຖະຫົນສາຍຫຼັກ      main road         (blue)
--     (5) ຖະຫົນສາຍເຊືອມຕໍ່   connector road    (blue)
--     (6) ຖະຫົນສາຍແຍກ      branch road       (green)
--     (7) ທາງທຽວດັງເດີມ      original track    (no colour)
--
-- A zone groups several villages, so each village now points to its zone.
-- The zone-rate page shows: zone code | district | villages | 4 road rates.
--
-- Additive + idempotent. APPLY MANUALLY on the server.
-- Grant the web role after COMMIT (same pattern as 006):
--   GRANT SELECT, INSERT, UPDATE ON villages TO <web_role>;  -- already granted
--
SET search_path = landfee;
BEGIN;

-- ---------------------------------------------------------------------------
-- 1. Decree road-type display labels (internal codes unchanged).
--    main/branch/alley/none keep working everywhere; only the visible Lao/EN
--    names follow the decree. name_en kept in English for the officer view.
-- ---------------------------------------------------------------------------
UPDATE road_categories SET
    name_lo = 'ຖະຫົນສາຍຫຼັກ',      name_en = 'Main road',
    sort_order = 1 WHERE code = 'main';
UPDATE road_categories SET
    name_lo = 'ຖະຫົນສາຍເຊືອມຕໍ່',   name_en = 'Connector road',
    sort_order = 2 WHERE code = 'branch';
UPDATE road_categories SET
    name_lo = 'ຖະຫົນສາຍແຍກ',      name_en = 'Branch road',
    sort_order = 3 WHERE code = 'alley';
UPDATE road_categories SET
    name_lo = 'ທາງດັງເດີມ / ບໍ່ມີທາງ', name_en = 'Original track / no road',
    sort_order = 4 WHERE code = 'none';

-- ---------------------------------------------------------------------------
-- 2. Village → zone. A zone covers several villages; each village belongs to
--    exactly one zone (NULL until assigned). A single zone_id column already
--    enforces one zone per village, so NO unique constraint on zone_id (that
--    would wrongly limit a zone to one village).
-- ---------------------------------------------------------------------------
ALTER TABLE villages ADD COLUMN IF NOT EXISTS zone_id VARCHAR(20);

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_villages_zone') THEN
    ALTER TABLE villages ADD CONSTRAINT fk_villages_zone
      FOREIGN KEY (zone_id) REFERENCES zones(code) ON DELETE SET NULL;
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_villages_zone ON villages (zone_id);

COMMIT;