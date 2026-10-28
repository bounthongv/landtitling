-- seed_zone_rates_savannakhet.sql — decree-385/2009 zone-rate skeleton for Savannakhet
-- See docs/11-zone-setup.md and docs/15-landtax-reg.md (rate source).
--
-- Rates below come from Presidential Decree 385/ປປ on land tax (ພາສີທີດິນ,
-- 2009), article 4: construction land is priced 5-300 kip/m2/year by
-- settlement class and zone; agricultural land is priced per hectare.
-- This band replaces the earlier placeholder band (85k-180k kip/m2/yr, which
-- matched one-off transfer fees, not the annual tax). If the province runs a
-- newer table, overwrite it in Basic Data > Zone rates (or re-run this seed
-- after adjusting the numbers) — the admin UI is the supported path.
--
-- Mapping assumptions (flagged in docs/15-landtax-reg.md):
--   * 1301 (provincial capital) prices its town rows from decree class II
--     (towns within a province); districts 1302-1315 (district seats) from
--     class III; rural from class IV plains rows.
--   * The decree's 4 rate columns are not fully legible on the scan; we
--     assume they rise with land value and map road tiers onto them:
--     main->col4, branch->col3, alley->col2, none->col1.
--   * Agricultural plan: paddy (plain) 35,000 kip/ha = 3.5 kip/m2, no road
--     differentiation (the decree has none for farmland).
--   * Commercial/industrial plans (capital only): flat rates from the
--     decree's higher-value suburban/extension columns (180 / 80).
--
-- Run AFTER sql/migrations/006_zone_rates_v2.sql.
-- Idempotent and non-destructive: ON CONFLICT DO NOTHING everywhere, so a
-- re-run never overwrites rates the province has already edited and never
-- touches existing (legacy) zones.
--
-- Creates:
--   * district letters A-O for districts 1301-1315 (official order)
--   * 4 planning zones per district, plus plans 5-6 for 1301 (capital) = 62
--   * 4 road tiers per zone = 248 decree-385 rates (3.5-250 kip/m2/yr)
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
  CASE d.code WHEN '1301' THEN CASE n
    WHEN 1 THEN 250      -- decree class II town-centre, highest value column
    WHEN 2 THEN 180     -- class II suburban, higher columns
    WHEN 3 THEN 3.5     -- paddy plain 35,000 kip/ha (decree agri table)
    WHEN 4 THEN 45      -- class IV rural plains, highest column
    WHEN 5 THEN 180     -- commercial: decree higher-value suburban column
    ELSE 80             -- industrial: decree extension/outer column
  END ELSE CASE n
    WHEN 1 THEN 120     -- decree class III district-seat town, higher columns
    WHEN 2 THEN 60      -- class III extension, higher columns
    WHEN 3 THEN 3.5     -- paddy plain 35,000 kip/ha (decree agri table)
    ELSE 45             -- class IV rural plains, highest column
  END END,
  CURRENT_DATE,
  TRUE,
  d.name_lo || ' — ' || zpl.name_lo || ' (decree 385/2009)',
  d.id,
  n::text,
  n
FROM districts d
JOIN LATERAL generate_series(1, CASE WHEN d.code = '1301' THEN 6 ELSE 4 END) AS n ON TRUE
JOIN zone_plans zpl ON zpl.code = n::text
WHERE d.code IN ('1301','1302','1303','1304','1305','1306','1307','1308',
                 '1309','1310','1311','1312','1313','1314','1315')
  AND d.zone_letter IS NOT NULL
ON CONFLICT (code) DO NOTHING;

-- ---------------------------------------------------------------------------
-- 4. Tier rates — decree 385/2009 band (3.5-250 kip/m2/yr)
--    main->col4, branch->col3, alley->col2, none->col1 (see header)
-- ---------------------------------------------------------------------------
INSERT INTO zone_rate_tiers (zone_code, road_category, rate_per_sqm, valid_from)
SELECT z.code, r.road_category, r.rate, z.valid_from
FROM zones z
JOIN (VALUES
  ('1','1301','main',   250::numeric), ('1','1301','branch', 250::numeric),
  ('1','1301','alley',  160::numeric), ('1','1301','none',    60::numeric),
  ('2','1301','main',   180::numeric), ('2','1301','branch', 180::numeric),
  ('2','1301','alley',   60::numeric), ('2','1301','none',    30::numeric),
  ('3','1301','main',     3.5::numeric), ('3','1301','branch', 3.5::numeric),
  ('3','1301','alley',    3.5::numeric), ('3','1301','none',   3.5::numeric),
  ('4','1301','main',    45::numeric), ('4','1301','branch',  30::numeric),
  ('4','1301','alley',    20::numeric), ('4','1301','none',   15::numeric),
  ('5','1301','main',   180::numeric), ('5','1301','branch', 180::numeric),
  ('5','1301','alley',   180::numeric), ('5','1301','none',   180::numeric),
  ('6','1301','main',    80::numeric), ('6','1301','branch',  80::numeric),
  ('6','1301','alley',    80::numeric), ('6','1301','none',   80::numeric),
  ('1','13xx','main',   120::numeric), ('1','13xx','branch', 120::numeric),
  ('1','13xx','alley',   60::numeric), ('1','13xx','none',    25::numeric),
  ('2','13xx','main',    60::numeric), ('2','13xx','branch',  60::numeric),
  ('2','13xx','alley',    30::numeric), ('2','13xx','none',   20::numeric),
  ('3','13xx','main',     3.5::numeric), ('3','13xx','branch', 3.5::numeric),
  ('3','13xx','alley',    3.5::numeric), ('3','13xx','none',   3.5::numeric),
  ('4','13xx','main',    45::numeric), ('4','13xx','branch',  30::numeric),
  ('4','13xx','alley',    20::numeric), ('4','13xx','none',   15::numeric)
) AS r(plan_zone, district, road_category, rate)
  ON r.plan_zone = z.plan_zone
 AND r.district  = CASE WHEN z.district_id IN (
   SELECT id FROM districts WHERE code = '1301') THEN '1301' ELSE '13xx' END
WHERE z.district_id IN (
  SELECT id FROM districts
   WHERE code IN ('1301','1302','1303','1304','1305','1306','1307','1308',
                  '1309','1310','1311','1312','1313','1314','1315')
)
ON CONFLICT (zone_code, road_category, valid_from) DO NOTHING;

COMMIT;
