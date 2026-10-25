-- seed_zone_rates_savannakhet.sql — placeholder zone-rate skeleton for Savannakhet
-- See docs/11-zone-setup.md.
--
-- Run AFTER sql/migrations/006_zone_rates_v2.sql.
-- Idempotent and non-destructive: ON CONFLICT DO NOTHING everywhere, so a
-- re-run never overwrites rates the province has already edited and never
-- touches existing (legacy) zones.
--
-- Creates:
--   * district letters A–O for districts 1301–1315 (official order)
--   * 4 planning zones per district, plus plans 5–6 for 1301 (capital) = 62
--   * 4 road tiers per zone = 248 placeholder rates (85k–180k kip/m²)
--
SET search_path = landfee;
BEGIN;

-- ---------------------------------------------------------------------------
-- 1. Lookup rows (no-op if migration 006 already inserted them)
-- ---------------------------------------------------------------------------
INSERT INTO zone_plans (code, name_lo, name_en, sort_order) VALUES
  ('1', 'ເຂດຕົວເມືອງ',  'Urban core',   1),
  ('2', 'ເຂດຊານເມືອງ',  'Peri-urban',   2),
  ('3', 'ເຂດກະສິກຳ',   'Agricultural', 3),
  ('4', 'ເຂດຊົນນະບົດ',  'Rural',        4),
  ('5', 'ເຂດເສດຖະກິດ',  'Commercial',   5),
  ('6', 'ເຂດອຸດສາຫະກຳ', 'Industrial',   6)
ON CONFLICT (code) DO NOTHING;

-- ---------------------------------------------------------------------------
-- 2. District letters (province 13, official district code order)
-- ---------------------------------------------------------------------------
UPDATE districts d
   SET zone_letter = v.letter
  FROM (VALUES
    ('1301','A'), ('1302','B'), ('1303','C'), ('1304','D'), ('1305','E'),
    ('1306','F'), ('1307','G'), ('1308','H'), ('1309','I'), ('1310','J'),
    ('1311','K'), ('1312','L'), ('1313','M'), ('1314','N'), ('1315','O')
  ) AS v(code, letter)
 WHERE d.code = v.code
   AND d.zone_letter IS DISTINCT FROM v.letter;

-- ---------------------------------------------------------------------------
-- 3. Planning zones: 4 per district, 6 for the capital district
-- ---------------------------------------------------------------------------
INSERT INTO zones (code, name_lo, name_en, rate_per_sqm, valid_from, is_active,
                   note, district_id, plan_zone, sort_order)
SELECT
  d.zone_letter || n::text,
  zpl.name_lo,
  zpl.name_en,
  CASE n
    WHEN 1 THEN 180000
    WHEN 2 THEN 160000
    WHEN 3 THEN 140000
    WHEN 4 THEN 120000
    WHEN 5 THEN 180000
    ELSE 180000
  END,
  CURRENT_DATE,
  TRUE,
  d.name_lo || ' — ' || zpl.name_lo || ' (placeholder)',
  d.id,
  n::text,
  n
FROM districts d
JOIN zone_plans zpl ON zpl.code = n::text
JOIN LATERAL generate_series(1, CASE WHEN d.code = '1301' THEN 6 ELSE 4 END) AS n ON TRUE
WHERE d.code IN ('1301','1302','1303','1304','1305','1306','1307','1308',
                 '1309','1310','1311','1312','1313','1314','1315')
  AND d.zone_letter IS NOT NULL
ON CONFLICT (code) DO NOTHING;

-- ---------------------------------------------------------------------------
-- 4. Tier rates (placeholder band; the province overwrites these)
-- ---------------------------------------------------------------------------
INSERT INTO zone_rate_tiers (zone_code, road_category, rate_per_sqm, valid_from)
SELECT z.code, r.road_category, r.rate, z.valid_from
FROM zones z
JOIN (VALUES
  ('1', 'main',   180000::numeric), ('1', 'branch', 140000::numeric),
  ('1', 'alley',  110000::numeric), ('1', 'none',    85000::numeric),
  ('2', 'main',   160000::numeric), ('2', 'branch', 130000::numeric),
  ('2', 'alley',  100000::numeric), ('2', 'none',    85000::numeric),
  ('3', 'main',   140000::numeric), ('3', 'branch', 115000::numeric),
  ('3', 'alley',   95000::numeric), ('3', 'none',    85000::numeric),
  ('4', 'main',   120000::numeric), ('4', 'branch', 105000::numeric),
  ('4', 'alley',   90000::numeric), ('4', 'none',    85000::numeric),
  ('5', 'main',   180000::numeric), ('5', 'branch', 140000::numeric),
  ('5', 'alley',  110000::numeric), ('5', 'none',    85000::numeric),
  ('6', 'main',   180000::numeric), ('6', 'branch', 140000::numeric),
  ('6', 'alley',  110000::numeric), ('6', 'none',    85000::numeric)
) AS r(plan_zone, road_category, rate)
  ON r.plan_zone = z.plan_zone
WHERE z.district_id IN (
  SELECT id FROM districts
   WHERE code IN ('1301','1302','1303','1304','1305','1306','1307','1308',
                  '1309','1310','1311','1312','1313','1314','1315')
)
ON CONFLICT (zone_code, road_category, valid_from) DO NOTHING;

COMMIT;
