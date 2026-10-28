-- 011_decree385_zone_rates.sql — re-base zone rates on Presidential Decree
-- 385/ປປ on land tax (ພາສີທີດິນ, 2009), article 4.
--
-- The earlier placeholder band (85k-180k kip/m2/yr) matched one-off
-- transfer/transaction fees, not the annual land tax. Decree construction
-- land is priced 5-300 kip/m2/yr; agricultural 1-45 kip/ha/yr. See
-- docs/15-landtax-reg.md for the transcription and the mapping assumptions
-- (settlement class per district, road tiers mapped onto the decree's value
-- columns main->col4 ... none->col1, paddy plain 3.5 kip/m2 for farmland).
--
-- What this migration does (idempotent, UPDATE only — it never deletes rows
-- and never touches parcel/payment data):
--   1. Re-bases the tier rows of the 62 seed planning zones (A1..O4 + A5/A6)
--   2. Re-bases the `zones.rate_per_sqm` fallback of the same zones
--   3. Re-bases legacy unassigned zones (G17/A7/C7/C8, district_id IS NULL)
--      and any of their tier rows to a flat decree-band value (60 kip/m2/yr,
--      the district-seat mid rate)
--
-- Apply manually on the server as postgres (never auto-run by CI):
--   psql -d landfee -f sql/migrations/011_decree385_zone_rates.sql
-- After running on a server where the province has already entered its own
-- rates via Basic Data > Zone rates, those values are overwritten by the
-- decree band — re-enter the province's figures afterwards if they differ.
--
SET search_path = landfee;
BEGIN;

-- ---------------------------------------------------------------------------
-- 1 + 2. Seed planning zones: tiers + fallback, decree 385/2009 band
-- ---------------------------------------------------------------------------
UPDATE zone_rate_tiers t
   SET rate_per_sqm = v.rate
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
  ) AS v(plan_zone, district, road_category, rate)
    ON v.plan_zone = z.plan_zone
   AND v.district = CASE WHEN z.district_id IN (
         SELECT id FROM districts WHERE code = '1301')
        THEN '1301' ELSE '13xx' END
 WHERE t.zone_code = z.code
   AND t.road_category = v.road_category
   AND z.plan_zone IS NOT NULL
   AND z.district_id IN (
     SELECT id FROM districts
      WHERE code IN ('1301','1302','1303','1304','1305','1306','1307','1308',
                    '1309','1310','1311','1312','1313','1314','1315'));

UPDATE zones z
   SET rate_per_sqm = CASE
         WHEN d.code = '1301' THEN CASE z.plan_zone
           WHEN '1' THEN 250 WHEN '2' THEN 180 WHEN '3' THEN 3.5
           WHEN '4' THEN 45  WHEN '5' THEN 180 ELSE 80 END
         ELSE CASE z.plan_zone
           WHEN '1' THEN 120 WHEN '2' THEN 60 WHEN '3' THEN 3.5 ELSE 45 END
       END
  FROM districts d
 WHERE d.id = z.district_id
   AND z.plan_zone IS NOT NULL
   AND left(d.code, 2) = '13'
   AND d.code BETWEEN '1301' AND '1315';

-- ---------------------------------------------------------------------------
-- 3. Legacy unassigned zones (no district, no plan): flat decree-band value
-- ---------------------------------------------------------------------------
UPDATE zone_rate_tiers
   SET rate_per_sqm = 60
 WHERE zone_code IN (SELECT code FROM zones
                      WHERE district_id IS NULL AND plan_zone IS NULL);

UPDATE zones
   SET rate_per_sqm = 60
 WHERE district_id IS NULL AND plan_zone IS NULL;

COMMIT;

-- Verification:
--   SELECT z.plan_zone, t.road_category, t.rate_per_sqm
--   FROM zone_rate_tiers t JOIN zones z ON z.code = t.zone_code
--   WHERE z.code IN ('A1','A5','B1','O4') ORDER BY 1,2;
