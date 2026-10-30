-- Test data for the citizen app smoke test (`dart run tool/smoke_test.dart`).
--
-- Creates: a citizen user, an owner linked to them, a parcel, the parcel-owner
-- link, the fee years, an unpaid 2026 obligation and a settled 2025 one.
-- Safe to re-run: it removes the previous fixture first.
--
--   psql -d landfee_a2test -f tool/fixtures/seed_test_data.sql
--
\set ON_ERROR_STOP on
SET search_path TO landfee;

-- Remove the previous fixture (owners first: they reference the user).
DELETE FROM landfee.owners
  WHERE citizen_user_id IN (SELECT id FROM landfee.users WHERE email = 'citizen@test.la');
DELETE FROM landfee.users WHERE email IN ('citizen@test.la', 'stranger@test.la');
-- The parcel is unique on (district, village, parcel_no); drop the old one
-- (payments first: they reference the parcel).
DELETE FROM landfee.payments WHERE parcel_id IN (
  SELECT id FROM landfee.parcels WHERE parcel_no = '1301.001.001.0001');
DELETE FROM landfee.parcels WHERE parcel_no = '1301.001.001.0001';

INSERT INTO landfee.users (role, phone, email, is_active)
  VALUES ('citizen', '+85620999999', 'citizen@test.la', TRUE) RETURNING id \gset
\set citizen_id :id

INSERT INTO landfee.owners (full_name, phone, citizen_user_id, owner_type, district_id, village_id)
  VALUES ('ທົວງ ທົວງ', '+85620999999', :citizen_id, 'individual', 1, 1) RETURNING id \gset
\set owner_id :id

INSERT INTO landfee.parcels (parcel_no, map_ref_no, district_id, village_id, area_sqm, zone_code, status, title_no)
  VALUES ('1301.001.001.0001', 'A250-038', 1, 1, 1250.50, 'A7', 'confirmed', '0102/1301/00342') RETURNING id \gset
\set parcel_id :id

INSERT INTO landfee.parcel_owners (parcel_id, owner_id, share_pct, role)
  VALUES (:parcel_id, :owner_id, 100.00, 'owner');

INSERT INTO landfee.fee_years (year, due_date, status)
  VALUES (2025, DATE '2025-06-30', 'closed'), (2026, DATE '2026-06-30', 'open')
  ON CONFLICT (year) DO NOTHING;

-- 2026: unpaid. 2025: settled, so the app shows a mixed history.
INSERT INTO landfee.fee_obligations
  (parcel_id, fee_year, district_id, area_sqm, rate_per_sqm, amount_due, amount_paid, penalty, status, due_date, zone_code)
VALUES (:parcel_id, 2026, 1, 1250.50, 140000.00, 175070000.00, 0, 0, 'UNPAID', DATE '2026-06-30', 'A7');

INSERT INTO landfee.fee_obligations
  (parcel_id, fee_year, district_id, area_sqm, rate_per_sqm, amount_due, amount_paid, penalty, status, due_date, zone_code)
VALUES (:parcel_id, 2025, 1, 1250.50, 140000.00, 175070000.00, 175070000.00, 0, 'PAID', DATE '2025-06-30', 'A7');

SELECT :citizen_id AS citizen_user_id, :parcel_id AS parcel_id, :owner_id AS owner_id;
