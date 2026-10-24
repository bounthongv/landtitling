-- Land Fee Payment MVP — Savannakhet Province
-- Schema v2 (annual fee): full title-parcel model + annual obligations + settlements.
-- See docs/5-annual-land-fee-back-office-design.md.
--
-- NOTE: this file DROPS and recreates schema `landfee` (destructive).
-- For an existing populated DB use sql/migrations/001_annual_fee_v2.sql instead.

DROP SCHEMA IF EXISTS landfee CASCADE;
CREATE SCHEMA landfee;
SET search_path = landfee;

-- Fuzzy geography matching for the title parser (optional; parser falls back to PHP similarity).
DO $$
BEGIN
  CREATE EXTENSION IF NOT EXISTS pg_trgm;
EXCEPTION WHEN OTHERS THEN
  RAISE NOTICE 'pg_trgm unavailable; title parser will use the PHP similarity fallback';
END $$;

-- =========================================================================
-- Reference tables (replace hardcoded lists in code)
-- =========================================================================

CREATE TABLE road_categories (
  code VARCHAR(10) PRIMARY KEY,
  name_lo VARCHAR(50) NOT NULL,
  name_en VARCHAR(50) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0
);
INSERT INTO road_categories (code, name_lo, name_en, sort_order) VALUES
  ('main',   'ຖະໜົນຫຼັກ', 'Main road',   1),
  ('branch', 'ຖະໜົນສາຂາ', 'Branch road', 2),
  ('alley',  'ຊອຍ',        'Alley',       3),
  ('none',   'ບໍ່ມີ',       'None',        4);

-- Populated when the real title/land-use vocabularies are confirmed with officers.
CREATE TABLE title_types (
  code VARCHAR(30) PRIMARY KEY,
  name_lo VARCHAR(80) NOT NULL,
  name_en VARCHAR(80) NOT NULL
);

CREATE TABLE land_use_classes (
  code VARCHAR(30) PRIMARY KEY,
  name_lo VARCHAR(80) NOT NULL,
  name_en VARCHAR(80) NOT NULL
);

-- Provisional starter vocabulary (verify wording with officers — design §3.2 / open item #1).
INSERT INTO title_types (code, name_lo, name_en) VALUES
  ('land_title',  'ໜັງສືທີ່ດິນ',        'Land title'),
  ('survey_cert', 'ໃບຢັ້ງຢືນການວັດແທກ', 'Survey certificate'),
  ('concession',  'ສຳປະທານ',            'Concession'),
  ('other',       'ອື່ນໆ',               'Other');

INSERT INTO land_use_classes (code, name_lo, name_en) VALUES
  ('residential',  'ທີ່ຢູ່ອາໄສ', 'Residential'),
  ('agricultural', 'ກະສິກຳ',      'Agricultural'),
  ('commercial',   'ການຄ້າ',      'Commercial'),
  ('other',        'ອື່ນໆ',        'Other');

-- =========================================================================
-- Reference geography
-- =========================================================================

CREATE TABLE provinces (
  id SERIAL PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name_lo VARCHAR(100) NOT NULL,
  name_en VARCHAR(100),
  note TEXT,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE districts (
  id SERIAL PRIMARY KEY,
  province_id INT NOT NULL REFERENCES provinces(id),
  code VARCHAR(20) UNIQUE NOT NULL,
  name_lo VARCHAR(100) NOT NULL,
  name_en VARCHAR(100),
  zone_letter VARCHAR(5),                      -- prefix for valuation zone codes (A, B, …)
  CONSTRAINT uq_districts_province_zone_letter UNIQUE (province_id, zone_letter)
);
CREATE INDEX idx_districts_province ON districts (province_id);

CREATE TABLE villages (
  id SERIAL PRIMARY KEY,
  district_id INT NOT NULL,
  code VARCHAR(20) NOT NULL,
  name_lo VARCHAR(100) NOT NULL,
  name_en VARCHAR(100),
  CONSTRAINT fk_villages_district
    FOREIGN KEY (district_id)
    REFERENCES districts(id)
    ON DELETE CASCADE
);

-- Trigram indexes speed up fuzzy name resolution (no-op if pg_trgm is absent).
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'pg_trgm') THEN
    CREATE INDEX idx_villages_name_trgm  ON villages  USING gin (name_lo gin_trgm_ops);
    CREATE INDEX idx_districts_name_trgm ON districts USING gin (name_lo gin_trgm_ops);
  END IF;
END $$;

-- Legacy flat zone rates (district × road category) — reference only; the fee
-- logic uses zone codes below.
CREATE TABLE zones_rates (
  id SERIAL PRIMARY KEY,
  district_id INT NOT NULL,
  road_category VARCHAR(10) NOT NULL,
  rate_per_sqm DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  valid_from DATE NOT NULL DEFAULT CURRENT_DATE,
  note TEXT,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_zones_rates_district
    FOREIGN KEY (district_id)
    REFERENCES districts(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_zones_rates_road_cat
    FOREIGN KEY (road_category)
    REFERENCES road_categories(code)
);

-- =========================================================================
-- Valuation zones (Decree 142/ລົງ model)
--   district → standard planning zone → zone code → road-access tier
-- =========================================================================

CREATE TABLE zone_plans (
  code VARCHAR(10) PRIMARY KEY,
  name_lo VARCHAR(100) NOT NULL,
  name_en VARCHAR(100),
  sort_order INT NOT NULL DEFAULT 0
);
INSERT INTO zone_plans (code, name_lo, name_en, sort_order) VALUES
  ('1', 'ເຂດຕົວເມືອງ',  'Urban core',   1),
  ('2', 'ເຂດຊານເມືອງ',  'Peri-urban',   2),
  ('3', 'ເຂດກະສິກຳ',   'Agricultural', 3),
  ('4', 'ເຂດຊົນນະບົດ',  'Rural',        4),
  ('5', 'ເຂດເສດຖະກິດ',  'Commercial',   5),
  ('6', 'ເຂດອຸດສາຫະກຳ', 'Industrial',   6);

CREATE TABLE zones (
  code VARCHAR(20) PRIMARY KEY,
  name_lo VARCHAR(80),
  name_en VARCHAR(80),
  rate_per_sqm DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- fallback/default tier
  valid_from DATE NOT NULL DEFAULT CURRENT_DATE,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  note TEXT,
  district_id INT,
  plan_zone VARCHAR(10),
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_zones_district
    FOREIGN KEY (district_id) REFERENCES districts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_zones_plan
    FOREIGN KEY (plan_zone) REFERENCES zone_plans(code) ON DELETE RESTRICT
);
CREATE INDEX idx_zones_district ON zones (district_id);

-- One row per zone × road category; a rate change adds a new valid_from row.
CREATE TABLE zone_rate_tiers (
  id SERIAL PRIMARY KEY,
  zone_code VARCHAR(20) NOT NULL,
  road_category VARCHAR(10) NOT NULL,
  rate_per_sqm NUMERIC(14,2) NOT NULL CHECK (rate_per_sqm >= 0),
  valid_from DATE NOT NULL DEFAULT CURRENT_DATE,
  note TEXT,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_zrt_zone
    FOREIGN KEY (zone_code) REFERENCES zones(code) ON DELETE CASCADE,
  CONSTRAINT fk_zrt_road
    FOREIGN KEY (road_category) REFERENCES road_categories(code),
  CONSTRAINT uq_zrt_zone_road_from UNIQUE (zone_code, road_category, valid_from)
);
CREATE INDEX idx_zrt_lookup ON zone_rate_tiers (zone_code, road_category, valid_from DESC);

-- Decree-142 position tiers (7-column regulation model): one row per zone ×
-- position (center / sub_center / lane / road_side). Mirrors Decree 142/ລົງ
-- columns (4)-(7). Additive — the road-access tiers above stay the fee source;
-- these position rates are for the officer valuation schedule and future
-- parcel-position pricing.
CREATE TABLE zone_rate_positions (
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
CREATE INDEX idx_zrp_lookup ON zone_rate_positions (zone_code, position, valid_from DESC);
INSERT INTO zone_rate_positions (zone_code, position, rate_per_sqm, valid_from)
SELECT z.code, p.position, z.rate_per_sqm, z.valid_from
FROM zones z
CROSS JOIN (VALUES ('center'), ('sub_center'), ('lane'), ('road_side')) AS p(position)
WHERE z.rate_per_sqm IS NOT NULL
ON CONFLICT (zone_code, position, valid_from) DO NOTHING;

-- Legacy zone codes seen in the previous system (kept unassigned on purpose).
INSERT INTO zones (code, name_lo, name_en, rate_per_sqm, valid_from) VALUES
  ('G17', 'ເຂດ G17', 'Zone G17', 180000.00, CURRENT_DATE),
  ('A7',  'ເຂດ A7',  'Zone A7',  140000.00, CURRENT_DATE),
  ('C7',  'ເຂດ C7',  'Zone C7',  110000.00, CURRENT_DATE),
  ('C8',  'ເຂດ C8',  'Zone C8',  85000.00,  CURRENT_DATE)
ON CONFLICT (code) DO NOTHING;
INSERT INTO zone_rate_tiers (zone_code, road_category, rate_per_sqm, valid_from)
SELECT z.code, rc.code, z.rate_per_sqm, z.valid_from
FROM zones z CROSS JOIN road_categories rc
ON CONFLICT (zone_code, road_category, valid_from) DO NOTHING;

-- =========================================================================
-- Users (unchanged)
-- =========================================================================

CREATE TABLE users (
  id SERIAL PRIMARY KEY,
  role VARCHAR(20) NOT NULL DEFAULT 'citizen' CHECK (role IN ('admin','officer','citizen')),
  username VARCHAR(50) UNIQUE,
  phone VARCHAR(20) UNIQUE NOT NULL,
  email VARCHAR(190),                          -- OTP login channel (no SMS gateway)
  password_hash VARCHAR(255),
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX idx_users_email_unique ON users (lower(email)) WHERE email IS NOT NULL;

-- =========================================================================
-- Owners (residence + identity), linked to parcels and citizen logins
-- =========================================================================

CREATE TABLE owners (
  id SERIAL PRIMARY KEY,
  full_name VARCHAR(200) NOT NULL,
  id_number VARCHAR(50),
  id_type VARCHAR(30),                       -- national_id / passport / family_book
  phone VARCHAR(20),
  address TEXT,                              -- residence (from the title)
  date_of_birth DATE,                        -- #8/#17
  nationality VARCHAR(60),                   -- #10/#18
  occupation VARCHAR(100),                   -- #11/#19
  address_village VARCHAR(100),              -- #12
  address_unit VARCHAR(50),                  -- #13
  address_district VARCHAR(100),             -- #14
  address_province VARCHAR(100),             -- #15
  village_id INT,
  district_id INT,
  owner_type VARCHAR(20) NOT NULL DEFAULT 'individual'
    CHECK (owner_type IN ('individual','organization','joint','spouse')),  -- spouse = co-owner row (#16-#19)
  citizen_user_id INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_owners_village
    FOREIGN KEY (village_id) REFERENCES villages(id),
  CONSTRAINT fk_owners_district
    FOREIGN KEY (district_id) REFERENCES districts(id),
  CONSTRAINT fk_owners_user
    FOREIGN KEY (citizen_user_id) REFERENCES users(id)
);

-- =========================================================================
-- Parcels — full title content
-- =========================================================================

CREATE TABLE parcels (
  id SERIAL PRIMARY KEY,
  parcel_no VARCHAR(50) NOT NULL,
  sub_parcel_no VARCHAR(20),
  province VARCHAR(100) NOT NULL DEFAULT 'Savannakhet',
  district_id INT NOT NULL,
  village_id INT NOT NULL,
  area_sqm DECIMAL(14,2) NOT NULL DEFAULT 0,
  zone_code VARCHAR(20),                    -- valuation zone (fee)
  road_category VARCHAR(10),                -- road-access tier (fee)
  -- title metadata parsed from the scan
  title_no VARCHAR(50),
  title_type VARCHAR(30),
  book_no VARCHAR(30),
  volume_no VARCHAR(30),
  page_no VARCHAR(30),
  land_use_class VARCHAR(30),
  issued_date DATE,
  -- full certificate field set (see sql/migrations/005_land_title_fields.sql)
  order_no VARCHAR(50),                     -- #2 issued order no.
  location_code VARCHAR(30),                -- #30 e.g. 10.002.0027
  unit_no VARCHAR(30),                      -- #6
  acquisition_method VARCHAR(80),           -- #20 how acquired
  ownership_status VARCHAR(120),            -- #21 e.g. original asset-wife
  zone_text VARCHAR(120),                   -- #23 descriptive zone (distinct from zone_code)
  map_ref_no VARCHAR(40),                  -- #26 e.g. A250-038
  map_parcel_no VARCHAR(40),                -- #27
  map_scale VARCHAR(20),                   -- #29 e.g. 1/8000
  issued_place VARCHAR(120),                -- footer: issued at (province)
  geo_lat DECIMAL(9,6),
  geo_lng DECIMAL(9,6),
  status VARCHAR(20) NOT NULL DEFAULT 'draft'
    CHECK (status IN ('draft','scanned','confirmed','active','archived')),
  scanned_at TIMESTAMP,
  ocr_confidence INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_parcels_district
    FOREIGN KEY (district_id) REFERENCES districts(id),
  CONSTRAINT fk_parcels_village
    FOREIGN KEY (village_id) REFERENCES villages(id),
  CONSTRAINT fk_parcels_road_cat
    FOREIGN KEY (road_category) REFERENCES road_categories(code),
  CONSTRAINT fk_parcels_title_type
    FOREIGN KEY (title_type) REFERENCES title_types(code),
  CONSTRAINT fk_parcels_land_use
    FOREIGN KEY (land_use_class) REFERENCES land_use_classes(code)
);

CREATE INDEX idx_parcels_parcel_no ON parcels (parcel_no);
CREATE INDEX idx_parcels_district ON parcels (district_id);
-- Duplicate guard (COALESCE keeps NULL sub-parcel numbers comparable)
CREATE UNIQUE INDEX uq_parcels_identity
  ON parcels (district_id, village_id, parcel_no, COALESCE(sub_parcel_no, ''));

-- Co-ownership: a parcel may have several owners
CREATE TABLE parcel_owners (
  parcel_id INT NOT NULL,
  owner_id INT NOT NULL,
  share_pct DECIMAL(5,2),
  role VARCHAR(20) NOT NULL DEFAULT 'owner'
    CHECK (role IN ('owner','co_owner','representative')),
  PRIMARY KEY (parcel_id, owner_id),
  CONSTRAINT fk_parcel_owners_parcel
    FOREIGN KEY (parcel_id) REFERENCES parcels(id) ON DELETE CASCADE,
  CONSTRAINT fk_parcel_owners_owner
    FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE CASCADE
);

-- =========================================================================
-- Title scans — first-class OCR job
-- =========================================================================

CREATE TABLE title_scans (
  id SERIAL PRIMARY KEY,
  parcel_id INT,
  image_path VARCHAR(255) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'uploaded'
    CHECK (status IN ('uploaded','ocr_done','parsed','confirmed','rejected')),
  ocr_engine VARCHAR(30),                    -- 'tesseract 5.x lao+eng'
  ocr_raw TEXT,                              -- full OCR text
  ocr_words JSONB,                           -- word boxes for the image viewer
  parsed_fields JSONB,                       -- structured extraction
  field_confidence JSONB,                    -- per-field confidence
  overall_confidence INT,
  created_by INT,
  verified_by INT,
  verified_at TIMESTAMP,
  note TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_title_scans_parcel
    FOREIGN KEY (parcel_id) REFERENCES parcels(id) ON DELETE SET NULL,
  CONSTRAINT fk_title_scans_created_by
    FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_title_scans_verified_by
    FOREIGN KEY (verified_by) REFERENCES users(id)
);

CREATE INDEX idx_title_scans_status ON title_scans (status);

-- =========================================================================
-- Annual fee — obligation layer
-- =========================================================================

CREATE TABLE fee_years (
  year INT PRIMARY KEY,
  due_date DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open'
    CHECK (status IN ('draft','open','closed')),
  note TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE fee_obligations (
  id SERIAL PRIMARY KEY,
  parcel_id INT NOT NULL,
  fee_year INT NOT NULL,
  district_id INT NOT NULL,
  zone_code VARCHAR(20),                 -- snapshot
  road_category VARCHAR(10),
  area_sqm DECIMAL(14,2) NOT NULL,       -- snapshot
  rate_per_sqm DECIMAL(12,2) NOT NULL,   -- snapshot
  amount_due DECIMAL(14,2) NOT NULL,     -- snapshot = rate_per_sqm * area_sqm
  amount_paid DECIMAL(14,2) NOT NULL DEFAULT 0,
  penalty DECIMAL(14,2) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'UNPAID'
    CHECK (status IN ('UNPAID','PARTIAL','PAID','OVERPAID','WAIVED','REFUNDED','DISPUTED')),
  due_date DATE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_obligations_parcel
    FOREIGN KEY (parcel_id) REFERENCES parcels(id) ON DELETE CASCADE,
  CONSTRAINT fk_obligations_year
    FOREIGN KEY (fee_year) REFERENCES fee_years(year),
  CONSTRAINT fk_obligations_district
    FOREIGN KEY (district_id) REFERENCES districts(id),
  CONSTRAINT fk_obligations_road_cat
    FOREIGN KEY (road_category) REFERENCES road_categories(code),
  CONSTRAINT uq_obligations_parcel_year UNIQUE (parcel_id, fee_year)
);

CREATE INDEX idx_obl_year_status ON fee_obligations (fee_year, status);

-- =========================================================================
-- Payments — settlements (bank statement reconciliation)
-- =========================================================================

CREATE TABLE bank_imports (
  id SERIAL PRIMARY KEY,
  filename VARCHAR(255) NOT NULL,
  imported_by INT,
  row_count INT NOT NULL DEFAULT 0,
  matched_count INT NOT NULL DEFAULT 0,
  imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_bank_imports_user
    FOREIGN KEY (imported_by) REFERENCES users(id)
);

CREATE TABLE payments (
  id SERIAL PRIMARY KEY,
  obligation_id INT,
  parcel_id INT NOT NULL,
  fee_year INT NOT NULL,
  payer_owner_id INT,
  method VARCHAR(20) NOT NULL DEFAULT 'counter'
    CHECK (method IN ('counter','bank_transfer','mobile','cash')),
  amount DECIMAL(14,2) NOT NULL,
  reference_no VARCHAR(50) UNIQUE NOT NULL,  -- printed on the slip, used for matching
  bank_ref VARCHAR(100),                     -- reference seen on the bank statement
  slip_qr VARCHAR(255),
  slip_path VARCHAR(255),                    -- uploaded slip photo
  status VARCHAR(20) NOT NULL DEFAULT 'ISSUED'
    CHECK (status IN ('ISSUED','PAID','REJECTED','REFUNDED')),
  paid_at TIMESTAMP,
  verified_by INT,
  verify_method VARCHAR(20)
    CHECK (verify_method IN ('manual','auto_statement','auto_ocr')),
  verify_note TEXT,
  bank_import_id INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payments_obligation
    FOREIGN KEY (obligation_id) REFERENCES fee_obligations(id),
  CONSTRAINT fk_payments_parcel
    FOREIGN KEY (parcel_id) REFERENCES parcels(id),
  CONSTRAINT fk_payments_owner
    FOREIGN KEY (payer_owner_id) REFERENCES owners(id),
  CONSTRAINT fk_payments_verified_by
    FOREIGN KEY (verified_by) REFERENCES users(id),
  CONSTRAINT fk_payments_bank_import
    FOREIGN KEY (bank_import_id) REFERENCES bank_imports(id)
);

CREATE INDEX idx_payments_status ON payments (status);
CREATE INDEX idx_payments_year ON payments (fee_year);

CREATE TABLE bank_transactions (
  id SERIAL PRIMARY KEY,
  import_id INT NOT NULL,
  txn_date DATE,
  amount DECIMAL(14,2) NOT NULL,
  reference_no VARCHAR(50),                  -- parsed from statement
  bank_ref VARCHAR(100),
  raw_line TEXT,
  matched_payment_id INT,
  match_status VARCHAR(20) NOT NULL DEFAULT 'unmatched'
    CHECK (match_status IN ('unmatched','matched','duplicate','partial','ignored')),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_bank_txns_import
    FOREIGN KEY (import_id) REFERENCES bank_imports(id) ON DELETE CASCADE,
  CONSTRAINT fk_bank_txns_payment
    FOREIGN KEY (matched_payment_id) REFERENCES payments(id)
);

-- =========================================================================
-- Audit log (unchanged)
-- =========================================================================

CREATE TABLE audit_log (
  id SERIAL PRIMARY KEY,
  actor_id INT,
  action VARCHAR(50) NOT NULL,
  entity VARCHAR(50) NOT NULL,
  entity_id INT NOT NULL,
  detail JSONB,
  at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
