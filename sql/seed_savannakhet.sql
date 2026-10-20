-- Land Fee MVP — Savannakhet demo ZONE RATES (legacy district x road_category model)
--
-- NOTE: geography (province/district/village) is NO LONGER seeded here. It now
-- comes from sql/seed_geography_national.sql (all 18 provinces / 148 districts /
-- 8,828 villages, official codes). Run migration 003 + that seed instead.
--
-- This file only loads the legacy `zones_rates` demo rows. Fee logic does NOT
-- read this table — lib/fee.php prices from the `zones` table by zone_code
-- (LLR7 model, see Basic Data > Zone rates). The rows are kept for reference.
--
-- It deliberately does NOT truncate districts/villages any more: that TRUNCATE
-- ... CASCADE would delete real parcels and the national geography.

SET search_path = landfee;

-- Safe to re-run: replace the demo rows only.
DELETE FROM zones_rates;

INSERT INTO zones_rates (district_id, road_category, rate_per_sqm, valid_from)
SELECT d.id, rc.rc, rc.rate, CURRENT_DATE
FROM districts d CROSS JOIN (
  SELECT 'main' AS rc, 180000.00 AS rate UNION ALL
  SELECT 'branch', 140000.00 UNION ALL
  SELECT 'alley', 110000.00 UNION ALL
  SELECT 'none', 85000.00) rc
WHERE left(d.code, 2) = '13';   -- Savannakhet districts only (60 rows, as before)