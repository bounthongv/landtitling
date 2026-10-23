-- Migration 005 — full land-title certificate field set
-- Adds the complete field inventory of the Lao land use right certificate
-- (ໃບຕາດິນ) to the parcels + owners tables, based on the officer's marked-up
-- standard form. All new columns are nullable — nothing existing changes.
--
-- Field map (marked certificate number -> column):
--   parcels:
--     #2  Issued order no.            -> order_no
--     #30 Parcel location code        -> location_code   (e.g. 10.002.0027)
--     #6  Unit                        -> unit_no
--     #20 How the parcel was acquired -> acquisition_method  (transfer / inheritance / granted ...)
--     #21 Status of ownership         -> ownership_status    (e.g. original asset-husband/wife)
--     #23 Zone (descriptive)          -> zone_text           (e.g. plain land-country side)
--     #24 Registration book no.       -> book_no     (existing)
--     #25 Leaf no. in the book        -> page_no     (existing)
--     #26 Map reference no.           -> map_ref_no    (e.g. A250-038)
--     #27 Parcel no. on the map       -> map_parcel_no
--     #29 Map scale                   -> map_scale     (e.g. 1/8000)
--     footer Issued at (province)     -> issued_place
--   (existing: #1 parcel_no, #3 province, #4 district_id, #5 village_id,
--    #22 land_use_class, #28 area_sqm, issued_date)
--
--   owners (spouse #16-#19 is stored as a co-owner row with owner_type='spouse'):
--     #8/#17 date of birth           -> date_of_birth
--     #10/#18 nationality            -> nationality
--     #11/#19 occupation             -> occupation
--     #12 address village            -> address_village
--     #13 address unit               -> address_unit
--     #14 address district           -> address_district
--     #15 address province           -> address_province
--
-- APPLY MANUALLY on the server:  psql -f 005_land_title_fields.sql
-- Additive + idempotent: safe to re-run.

SET search_path = landfee;
BEGIN;

-- ---------------------------------------------------------------------------
-- 1. parcels: certificate fields
-- ---------------------------------------------------------------------------
ALTER TABLE parcels ADD COLUMN IF NOT EXISTS order_no           VARCHAR(50);
ALTER TABLE parcels ADD COLUMN IF NOT EXISTS location_code      VARCHAR(30);
ALTER TABLE parcels ADD COLUMN IF NOT EXISTS unit_no            VARCHAR(30);
ALTER TABLE parcels ADD COLUMN IF NOT EXISTS acquisition_method VARCHAR(80);
ALTER TABLE parcels ADD COLUMN IF NOT EXISTS ownership_status   VARCHAR(120);
ALTER TABLE parcels ADD COLUMN IF NOT EXISTS zone_text          VARCHAR(120);
ALTER TABLE parcels ADD COLUMN IF NOT EXISTS map_ref_no         VARCHAR(40);
ALTER TABLE parcels ADD COLUMN IF NOT EXISTS map_parcel_no      VARCHAR(40);
ALTER TABLE parcels ADD COLUMN IF NOT EXISTS map_scale          VARCHAR(20);
ALTER TABLE parcels ADD COLUMN IF NOT EXISTS issued_place       VARCHAR(120);

COMMENT ON COLUMN parcels.location_code      IS '#30 parcel location code, e.g. 10.002.0027';
COMMENT ON COLUMN parcels.order_no          IS '#2 issued order number';
COMMENT ON COLUMN parcels.acquisition_method IS '#20 how the parcel was acquired (transfer/inheritance/...)';
COMMENT ON COLUMN parcels.ownership_status   IS '#21 status of ownership (e.g. original asset-wife before marriage)';
COMMENT ON COLUMN parcels.zone_text         IS '#23 descriptive zone from the title (e.g. plain land-country side); distinct from zone_code (LLR7 valuation code)';
COMMENT ON COLUMN parcels.map_ref_no        IS '#26 map reference no., e.g. A250-038';
COMMENT ON COLUMN parcels.map_parcel_no     IS '#27 parcel number on the map';
COMMENT ON COLUMN parcels.map_scale         IS '#29 map scale, e.g. 1/8000';
COMMENT ON COLUMN parcels.issued_place      IS 'footer: province/office where the title was issued';

-- ---------------------------------------------------------------------------
-- 2. owners: identity + structured address (owner AND spouse rows)
-- ---------------------------------------------------------------------------
ALTER TABLE owners ADD COLUMN IF NOT EXISTS date_of_birth    DATE;
ALTER TABLE owners ADD COLUMN IF NOT EXISTS nationality      VARCHAR(60);
ALTER TABLE owners ADD COLUMN IF NOT EXISTS occupation       VARCHAR(100);
ALTER TABLE owners ADD COLUMN IF NOT EXISTS address_village  VARCHAR(100);
ALTER TABLE owners ADD COLUMN IF NOT EXISTS address_unit     VARCHAR(50);
ALTER TABLE owners ADD COLUMN IF NOT EXISTS address_district VARCHAR(100);
ALTER TABLE owners ADD COLUMN IF NOT EXISTS address_province VARCHAR(100);

-- Spouse on the certificate = a co-owner row marked owner_type='spouse'.
ALTER TABLE owners DROP CONSTRAINT IF EXISTS owners_owner_type_check;
ALTER TABLE owners ADD CONSTRAINT owners_owner_type_check
  CHECK (owner_type IN ('individual','organization','joint','spouse'));

COMMIT;
