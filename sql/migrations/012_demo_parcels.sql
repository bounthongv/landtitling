-- 012_demo_parcels.sql — replace the PILOT-AST / TEST-ZONE parcel records
-- with a clean 15-parcel demo set priced on the decree 385/2009 band.
--
-- Replaces: TEST-ZONE-001 + PILOT-AST-0001..0038 (39 rows) with
-- DEMO-2026-001..015 across districts 1301 (A), 1303 (C), 1307 (G),
-- 1311 (K): 5 urban-core (plan 1), 3 peri-urban (plan 2), 4 agricultural
-- (plan 3), 3 rural (plan 4). Each parcel gets one demo owner and one
-- fee obligation for fee year 2026 (status UNPAID), priced from the
-- current zone_rate_tiers (decree band — see docs/15-landtax-reg.md §6).
--
-- Safety: everything runs in one transaction; before deleting, the current
-- state of the five affected tables is snapshotted into *_bak_pilot tables
-- (created only if absent, so the script is re-runnable). Rollback =
-- restore from those tables (or simply revert the transaction pre-commit).
--
-- Applies to: production (apis.com.la). Local dev is safe too: the delete
-- patterns match no local test data, and the inserts are ON CONFLICT-guarded.
-- Apply by hand:  psql -d landfee -f sql/migrations/012_demo_parcels.sql
--
SET search_path = landfee;
BEGIN;

-- ---------------------------------------------------------------------------
-- 0. Backup snapshots (once)
-- ---------------------------------------------------------------------------
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_class WHERE relname = 'parcels_bak_pilot') THEN
    CREATE TABLE parcels_bak_pilot AS SELECT * FROM parcels;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_class WHERE relname = 'owners_bak_pilot') THEN
    CREATE TABLE owners_bak_pilot AS SELECT * FROM owners;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_class WHERE relname = 'parcel_owners_bak_pilot') THEN
    CREATE TABLE parcel_owners_bak_pilot AS SELECT * FROM parcel_owners;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_class WHERE relname = 'fee_obligations_bak_pilot') THEN
    CREATE TABLE fee_obligations_bak_pilot AS SELECT * FROM fee_obligations;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_class WHERE relname = 'payments_bak_pilot') THEN
    CREATE TABLE payments_bak_pilot AS SELECT * FROM payments;
  END IF;
END $$;

-- ---------------------------------------------------------------------------
-- 1. Delete the old pilot/test set and everything linked to it
-- ---------------------------------------------------------------------------
DELETE FROM payments
 WHERE parcel_id IN (SELECT id FROM parcels_bak_pilot
                      WHERE parcel_no LIKE 'PILOT-AST-%' OR parcel_no = 'TEST-ZONE-001');

DELETE FROM fee_obligations
 WHERE parcel_id IN (SELECT id FROM parcels_bak_pilot
                      WHERE parcel_no LIKE 'PILOT-AST-%' OR parcel_no = 'TEST-ZONE-001');

DELETE FROM parcel_owners
 WHERE parcel_id IN (SELECT id FROM parcels_bak_pilot
                      WHERE parcel_no LIKE 'PILOT-AST-%' OR parcel_no = 'TEST-ZONE-001');

DELETE FROM owners
 WHERE id IN (SELECT DISTINCT owner_id FROM parcel_owners_bak_pilot
              WHERE parcel_id IN (SELECT id FROM parcels_bak_pilot
                                   WHERE parcel_no LIKE 'PILOT-AST-%' OR parcel_no = 'TEST-ZONE-001'));

DELETE FROM parcels
 WHERE parcel_no LIKE 'PILOT-AST-%' OR parcel_no = 'TEST-ZONE-001';

-- Remove a previous DEMO-2026 set if the script is re-run
DELETE FROM fee_obligations WHERE parcel_id IN (SELECT id FROM parcels WHERE parcel_no LIKE 'DEMO-2026-%');
DELETE FROM parcel_owners WHERE parcel_id IN (SELECT id FROM parcels WHERE parcel_no LIKE 'DEMO-2026-%');
DELETE FROM payments WHERE parcel_id IN (SELECT id FROM parcels WHERE parcel_no LIKE 'DEMO-2026-%');
DELETE FROM owners WHERE id_number LIKE 'ID-DEMO-%';
DELETE FROM parcels WHERE parcel_no LIKE 'DEMO-2026-%';

-- ---------------------------------------------------------------------------
-- 2. New demo parcels (codes resolved by official code — environment-agnostic)
--    plan 1 urban-core / 2 peri-urban / 3 agricultural / 4 rural
-- ---------------------------------------------------------------------------
INSERT INTO parcels (parcel_no, province, district_id, village_id, area_sqm, road_category,
                     title_no, title_type, book_no, page_no, land_use_class, issued_date,
                     status, zone_code)
SELECT 'DEMO-2026-' || n.suffix, 'Savannakhet', ref.did, ref.vill, n.area, n.rc,
       'LT-DEMO-' || n.suffix, 'land_title', 'B01', n.suffix, n.lu, '2026-06-15',
       'confirmed', n.zone
FROM (VALUES
  ('001','1301','1301001',  120.00,'main','A1','residential'),
  ('002','1301','1301001',  250.00,'main','A1','residential'),
  ('003','1303','1303002',  180.00,'main','C1','residential'),
  ('004','1307','1307004',  300.00,'main','G1','residential'),
  ('005','1311','1311001',  150.00,'main','K1','residential'),
  ('006','1301','1301001',  400.00,'main','A2','residential'),
  ('007','1303','1303002',  500.00,'main','C2','residential'),
  ('008','1311','1311001',  350.00,'main','K2','residential'),
  ('009','1301','1301001', 1500.00,'none','A3','agricultural'),
  ('010','1303','1303002', 2000.00,'none','C3','agricultural'),
  ('011','1307','1307004', 2500.00,'none','G3','agricultural'),
  ('012','1311','1311001', 1200.00,'none','K3','agricultural'),
  ('013','1301','1301001',  800.00,'main','A4','residential'),
  ('014','1303','1303002', 1000.00,'main','C4','residential'),
  ('015','1311','1311001',  600.00,'main','K4','residential')
) AS n(suffix, dcode, vcode, area, rc, zone, lu)
JOIN (SELECT d.id AS did, v.id AS vill, x.dcode
      FROM (VALUES ('1301','1301001'),('1303','1303002'),('1307','1307004'),('1311','1311001'))
       AS x(dcode, vcode)
      JOIN districts d ON d.code = x.dcode
      JOIN villages  v ON v.code = x.vcode) ref
  ON ref.dcode = n.dcode
WHERE NOT EXISTS (SELECT 1 FROM parcels p WHERE p.parcel_no = 'DEMO-2026-' || n.suffix);

-- ---------------------------------------------------------------------------
-- 3. One demo owner per parcel + link
-- ---------------------------------------------------------------------------
INSERT INTO owners (full_name, id_number, phone, village_id, district_id, owner_type)
SELECT 'DEMO OWNER ' || right(s.suffix, 2),
       'ID-DEMO-' || s.suffix,
       '02055590' || s.suffix,
       v.id, d.id, 'individual'
FROM (VALUES
  ('001','1301','1301001'),('002','1301','1301001'),('003','1303','1303002'),
  ('004','1307','1307004'),('005','1311','1311001'),('006','1301','1301001'),
  ('007','1303','1303002'),('008','1311','1311001'),('009','1301','1301001'),
  ('010','1303','1303002'),('011','1307','1307004'),('012','1311','1311001'),
  ('013','1301','1301001'),('014','1303','1303002'),('015','1311','1311001')
) AS s(suffix, dcode, vcode)
JOIN districts d ON d.code = s.dcode
JOIN villages  v ON v.code = s.vcode
WHERE NOT EXISTS (SELECT 1 FROM owners o WHERE o.id_number = 'ID-DEMO-' || s.suffix);

INSERT INTO parcel_owners (parcel_id, owner_id, role)
SELECT p.id, o.id, 'owner'
FROM parcels p
JOIN owners o ON o.id_number = 'ID-DEMO-' || right(p.parcel_no, 3)
WHERE p.parcel_no LIKE 'DEMO-2026-%'
  AND NOT EXISTS (SELECT 1 FROM parcel_owners po
                   WHERE po.parcel_id = p.id AND po.owner_id = o.id);

-- ---------------------------------------------------------------------------
-- 4. One UNPAID obligation per parcel for fee year 2026, priced from the
--    current (newest) zone_rate_tiers — the decree band
-- ---------------------------------------------------------------------------
WITH cur AS (
  SELECT t.zone_code, t.road_category, t.rate_per_sqm
  FROM zone_rate_tiers t
  WHERE t.valid_from = (
    SELECT max(t2.valid_from) FROM zone_rate_tiers t2
     WHERE t2.zone_code = t.zone_code AND t2.road_category = t.road_category)
)
INSERT INTO fee_obligations (parcel_id, fee_year, district_id, road_category, area_sqm,
                             rate_per_sqm, amount_due, amount_paid, penalty, status,
                             due_date, zone_code)
SELECT p.id, 2026, p.district_id, p.road_category, p.area_sqm,
       c.rate_per_sqm, round(c.rate_per_sqm * p.area_sqm, 2), 0, 0,
       'UNPAID', '2026-12-31', p.zone_code
FROM parcels p
JOIN cur c ON c.zone_code = p.zone_code AND c.road_category = p.road_category
WHERE p.parcel_no LIKE 'DEMO-2026-%'
ON CONFLICT DO NOTHING;

COMMIT;

-- ---------------------------------------------------------------------------
-- Verification (read-only, post-commit)
-- ---------------------------------------------------------------------------
SELECT 'parcels_total' AS k, count(*)::text AS v FROM parcels
UNION ALL SELECT 'demo_parcels', count(*)::text FROM parcels WHERE parcel_no LIKE 'DEMO-2026-%'
UNION ALL SELECT 'demo_owners', count(*)::text FROM owners o WHERE o.id_number LIKE 'ID-DEMO-%'
UNION ALL SELECT 'demo_obligations', count(*)::text FROM fee_obligations fo WHERE fo.parcel_id IN (SELECT id FROM parcels WHERE parcel_no LIKE 'DEMO-2026-%')
UNION ALL SELECT 'demo_obligation_kip', coalesce(sum(fo.amount_due),0)::text FROM fee_obligations fo WHERE fo.parcel_id IN (SELECT id FROM parcels WHERE parcel_no LIKE 'DEMO-2026-%');

SELECT p.parcel_no, p.zone_code, p.road_category, p.area_sqm, o.full_name,
       fo.rate_per_sqm, fo.amount_due, fo.status
FROM parcels p
LEFT JOIN parcel_owners po ON po.parcel_id = p.id
LEFT JOIN owners o ON o.id = po.owner_id
LEFT JOIN fee_obligations fo ON fo.parcel_id = p.id AND fo.fee_year = 2026
WHERE p.parcel_no LIKE 'DEMO-2026-%'
ORDER BY p.parcel_no;
