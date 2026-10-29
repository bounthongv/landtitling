-- Migration 013 — parcel identity includes the map sheet (#26)
--
-- On the paper title a plot is identified by ແຜນທີ່ດິນແຜ່ນທີ (#26, map sheet)
-- plus ຕອນດິນເລກທີ (#27, parcel no. on that sheet); #1 ເລກທີ is the
-- certificate serial, not the parcel. A parcel number can repeat on another
-- sheet, so the duplicate guard must include map_ref_no.
--
--   old key: (district_id, village_id, parcel_no, sub_parcel_no)
--   new key: (district_id, village_id, map_ref_no, parcel_no, sub_parcel_no)
--
-- Rows with a blank sheet still collide on (village, parcel_no), so legacy data
-- behaves exactly as before until the officer records the sheet.
--
-- APPLY MANUALLY on the server:  psql -f 013_parcel_identity_map_sheet.sql
-- Idempotent. Runs in a transaction: if the new index cannot be built (existing
-- duplicate sheet+parcel rows), the DROP is rolled back and the old guard stays.

SET search_path = landfee;
BEGIN;

DROP INDEX IF EXISTS uq_parcels_identity;

CREATE UNIQUE INDEX uq_parcels_identity
  ON parcels (district_id, village_id, COALESCE(map_ref_no, ''), parcel_no,
              COALESCE(sub_parcel_no, ''));

COMMIT;
