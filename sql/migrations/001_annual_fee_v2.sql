-- Migration 001 — Annual land fee schema v2
-- See docs/5-annual-land-fee-back-office-design.md (§2).
--
-- APPLY MANUALLY on the server (CI never runs migrations):  psql -f 001_annual_fee_v2.sql
--
-- ASSUMPTIONS / RISK:
--   * Target DB is `landfee` (schema `landfee`).
--   * `parcels`, `title_scans`, `payments` are assumed EMPTY (no production parcel/payment
--     data yet). They are DROPPED and recreated by this migration.
--       >>> If they contain real rows, STOP, back up, and adjust this file first. <<<
--   * Preserved: districts, villages, zones_rates, users, audit_log.
--   * Re-runnable: uses DROP ... IF EXISTS / CREATE IF NOT EXISTS.
--
-- Pre-migration backup:  pg_dump -n landfee landfee > landfee_pre_v2.sql

SET search_path = landfee;

BEGIN;

-- -------------------------------------------------------------------------
-- 1. Reference tables
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS road_categories (
  code VARCHAR(10) PRIMARY KEY,
  name_lo VARCHAR(50) NOT NULL,
  name_en VARCHAR(50) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0
);
INSERT INTO road_categories (code, name_lo, name_en, sort_order) VALUES
  ('main',   'ຖະໜົນຫຼັກ', 'Main road',   1),
  ('branch', 'ຖະໜົນສາຂາ', 'Branch road', 2),
  ('alley',  'ຊອຍ',        'Alley',       3),
  ('none',   'ບໍ່ມີ',       'None',        4)
ON CONFLICT (code) DO NOTHING;

CREATE TABLE IF NOT EXISTS title_types (
  code VARCHAR(30) PRIMARY KEY,
  name_lo VARCHAR(80) NOT NULL,
  name_en VARCHAR(80) NOT NULL
);

CREATE TABLE IF NOT EXISTS land_use_classes (
  code VARCHAR(30) PRIMARY KEY,
  name_lo VARCHAR(80) NOT NULL,
  name_en VARCHAR(80) NOT NULL
);

-- -------------------------------------------------------------------------
-- 2. Drop the v1 tables being replaced (order: children first)
-- -------------------------------------------------------------------------
DROP TABLE IF EXISTS bank_transactions CASCADE;
DROP TABLE IF EXISTS bank_imports CASCADE;
DROP TABLE IF EXISTS payments CASCADE;
DROP TABLE IF EXISTS fee_obligations CASCADE;
DROP TABLE IF EXISTS fee_years CASCADE;
DROP TABLE IF EXISTS title_scans CASCADE;
DROP TABLE IF EXISTS parcel_owners CASCADE;
DROP TABLE IF EXISTS parcels CASCADE;
DROP TABLE IF EXISTS citizens CASCADE;
DROP TABLE IF EXISTS owners CASCADE;

-- -------------------------------------------------------------------------
-- 3. Owners + co-ownership
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS owners (
  id SERIAL PRIMARY KEY,
  full_name VARCHAR(200) NOT NULL,
  id_number VARCHAR(50),
  id_type VARCHAR(30),
  phone VARCHAR(20),
  address TEXT,
  village_id INT,
  district_id INT,
  owner_type VARCHAR(20) NOT NULL DEFAULT 'individual'
    CHECK (owner_type IN ('individual','organization','joint')),
  citizen_user_id INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_owners_village  FOREIGN KEY (village_id) REFERENCES villages(id),
  CONSTRAINT fk_owners_district FOREIGN KEY (district_id) REFERENCES districts(id),
  CONSTRAINT fk_owners_user     FOREIGN KEY (citizen_user_id) REFERENCES users(id)
);

-- -------------------------------------------------------------------------
-- 4. Parcels (full title content)
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS parcels (
  id SERIAL PRIMARY KEY,
  parcel_no VARCHAR(50) NOT NULL,
  sub_parcel_no VARCHAR(20),
  province VARCHAR(100) NOT NULL DEFAULT 'Savannakhet',
  district_id INT NOT NULL,
  village_id INT NOT NULL,
  area_sqm DECIMAL(14,2) NOT NULL DEFAULT 0,
  road_category VARCHAR(10),
  title_no VARCHAR(50),
  title_type VARCHAR(30),
  book_no VARCHAR(30),
  volume_no VARCHAR(30),
  page_no VARCHAR(30),
  land_use_class VARCHAR(30),
  issued_date DATE,
  geo_lat DECIMAL(9,6),
  geo_lng DECIMAL(9,6),
  status VARCHAR(20) NOT NULL DEFAULT 'draft'
    CHECK (status IN ('draft','scanned','confirmed','active','archived')),
  scanned_at TIMESTAMP,
  ocr_confidence INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_parcels_district   FOREIGN KEY (district_id) REFERENCES districts(id),
  CONSTRAINT fk_parcels_village    FOREIGN KEY (village_id) REFERENCES villages(id),
  CONSTRAINT fk_parcels_road_cat   FOREIGN KEY (road_category) REFERENCES road_categories(code),
  CONSTRAINT fk_parcels_title_type FOREIGN KEY (title_type) REFERENCES title_types(code),
  CONSTRAINT fk_parcels_land_use   FOREIGN KEY (land_use_class) REFERENCES land_use_classes(code)
);
CREATE INDEX idx_parcels_parcel_no ON parcels (parcel_no);
CREATE INDEX idx_parcels_district  ON parcels (district_id);
CREATE UNIQUE INDEX uq_parcels_identity
  ON parcels (district_id, village_id, parcel_no, COALESCE(sub_parcel_no, ''));

CREATE TABLE IF NOT EXISTS parcel_owners (
  parcel_id INT NOT NULL,
  owner_id INT NOT NULL,
  share_pct DECIMAL(5,2),
  role VARCHAR(20) NOT NULL DEFAULT 'owner'
    CHECK (role IN ('owner','co_owner','representative')),
  PRIMARY KEY (parcel_id, owner_id),
  CONSTRAINT fk_parcel_owners_parcel FOREIGN KEY (parcel_id) REFERENCES parcels(id) ON DELETE CASCADE,
  CONSTRAINT fk_parcel_owners_owner  FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE CASCADE
);

-- -------------------------------------------------------------------------
-- 5. Title scans
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS title_scans (
  id SERIAL PRIMARY KEY,
  parcel_id INT,
  image_path VARCHAR(255) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'uploaded'
    CHECK (status IN ('uploaded','ocr_done','parsed','confirmed','rejected')),
  ocr_engine VARCHAR(30),
  ocr_raw TEXT,
  ocr_words JSONB,
  parsed_fields JSONB,
  field_confidence JSONB,
  overall_confidence INT,
  created_by INT,
  verified_by INT,
  verified_at TIMESTAMP,
  note TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_title_scans_parcel      FOREIGN KEY (parcel_id) REFERENCES parcels(id) ON DELETE SET NULL,
  CONSTRAINT fk_title_scans_created_by  FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_title_scans_verified_by FOREIGN KEY (verified_by) REFERENCES users(id)
);
CREATE INDEX idx_title_scans_status ON title_scans (status);

-- -------------------------------------------------------------------------
-- 6. Annual fee: years + obligations
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS fee_years (
  year INT PRIMARY KEY,
  due_date DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open'
    CHECK (status IN ('draft','open','closed')),
  note TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS fee_obligations (
  id SERIAL PRIMARY KEY,
  parcel_id INT NOT NULL,
  fee_year INT NOT NULL,
  district_id INT NOT NULL,
  road_category VARCHAR(10),
  area_sqm DECIMAL(14,2) NOT NULL,
  rate_per_sqm DECIMAL(12,2) NOT NULL,
  amount_due DECIMAL(14,2) NOT NULL,
  amount_paid DECIMAL(14,2) NOT NULL DEFAULT 0,
  penalty DECIMAL(14,2) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'UNPAID'
    CHECK (status IN ('UNPAID','PARTIAL','PAID','OVERPAID','WAIVED','REFUNDED','DISPUTED')),
  due_date DATE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_obligations_parcel   FOREIGN KEY (parcel_id) REFERENCES parcels(id) ON DELETE CASCADE,
  CONSTRAINT fk_obligations_year     FOREIGN KEY (fee_year) REFERENCES fee_years(year),
  CONSTRAINT fk_obligations_district FOREIGN KEY (district_id) REFERENCES districts(id),
  CONSTRAINT fk_obligations_road_cat FOREIGN KEY (road_category) REFERENCES road_categories(code),
  CONSTRAINT uq_obligations_parcel_year UNIQUE (parcel_id, fee_year)
);
CREATE INDEX idx_obl_year_status ON fee_obligations (fee_year, status);

-- -------------------------------------------------------------------------
-- 7. Payments (settlements) + bank statement import
-- -------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bank_imports (
  id SERIAL PRIMARY KEY,
  filename VARCHAR(255) NOT NULL,
  imported_by INT,
  row_count INT NOT NULL DEFAULT 0,
  matched_count INT NOT NULL DEFAULT 0,
  imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_bank_imports_user FOREIGN KEY (imported_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS payments (
  id SERIAL PRIMARY KEY,
  obligation_id INT,
  parcel_id INT NOT NULL,
  fee_year INT NOT NULL,
  payer_owner_id INT,
  method VARCHAR(20) NOT NULL DEFAULT 'counter'
    CHECK (method IN ('counter','bank_transfer','mobile','cash')),
  amount DECIMAL(14,2) NOT NULL,
  reference_no VARCHAR(50) UNIQUE NOT NULL,
  bank_ref VARCHAR(100),
  slip_qr VARCHAR(255),
  slip_path VARCHAR(255),
  status VARCHAR(20) NOT NULL DEFAULT 'ISSUED'
    CHECK (status IN ('ISSUED','PAID','REJECTED','REFUNDED')),
  paid_at TIMESTAMP,
  verified_by INT,
  verify_method VARCHAR(20) CHECK (verify_method IN ('manual','auto_statement','auto_ocr')),
  verify_note TEXT,
  bank_import_id INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payments_obligation  FOREIGN KEY (obligation_id) REFERENCES fee_obligations(id),
  CONSTRAINT fk_payments_parcel      FOREIGN KEY (parcel_id) REFERENCES parcels(id),
  CONSTRAINT fk_payments_owner       FOREIGN KEY (payer_owner_id) REFERENCES owners(id),
  CONSTRAINT fk_payments_verified_by FOREIGN KEY (verified_by) REFERENCES users(id),
  CONSTRAINT fk_payments_bank_import FOREIGN KEY (bank_import_id) REFERENCES bank_imports(id)
);
CREATE INDEX idx_payments_status ON payments (status);
CREATE INDEX idx_payments_year   ON payments (fee_year);

CREATE TABLE IF NOT EXISTS bank_transactions (
  id SERIAL PRIMARY KEY,
  import_id INT NOT NULL,
  txn_date DATE,
  amount DECIMAL(14,2) NOT NULL,
  reference_no VARCHAR(50),
  bank_ref VARCHAR(100),
  raw_line TEXT,
  matched_payment_id INT,
  match_status VARCHAR(20) NOT NULL DEFAULT 'unmatched'
    CHECK (match_status IN ('unmatched','matched','duplicate','partial','ignored')),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_bank_txns_import  FOREIGN KEY (import_id) REFERENCES bank_imports(id) ON DELETE CASCADE,
  CONSTRAINT fk_bank_txns_payment FOREIGN KEY (matched_payment_id) REFERENCES payments(id)
);

-- -------------------------------------------------------------------------
-- 8. zones_rates.road_category: inline CHECK -> FK to road_categories
-- -------------------------------------------------------------------------
ALTER TABLE zones_rates DROP CONSTRAINT IF EXISTS zones_rates_road_category_check;
ALTER TABLE zones_rates DROP CONSTRAINT IF EXISTS fk_zones_rates_road_cat;
ALTER TABLE zones_rates
  ADD CONSTRAINT fk_zones_rates_road_cat
  FOREIGN KEY (road_category) REFERENCES road_categories(code);

-- -------------------------------------------------------------------------
-- 9. Title parser support: fuzzy geography (pg_trgm, optional) + starter vocab
-- -------------------------------------------------------------------------
DO $$
BEGIN
  CREATE EXTENSION IF NOT EXISTS pg_trgm;
EXCEPTION WHEN OTHERS THEN
  RAISE NOTICE 'pg_trgm unavailable; title parser will use the PHP similarity fallback';
END $$;

INSERT INTO title_types (code, name_lo, name_en) VALUES
  ('land_title',  'ໜັງສືທີ່ດິນ',        'Land title'),
  ('survey_cert', 'ໃບຢັ້ງຢືນການວັດແທກ', 'Survey certificate'),
  ('concession',  'ສຳປະທານ',            'Concession'),
  ('other',       'ອື່ນໆ',               'Other')
ON CONFLICT (code) DO NOTHING;

INSERT INTO land_use_classes (code, name_lo, name_en) VALUES
  ('residential',  'ທີ່ຢູ່ອາໄສ', 'Residential'),
  ('agricultural', 'ກະສິກຳ',      'Agricultural'),
  ('commercial',   'ການຄ້າ',      'Commercial'),
  ('other',        'ອື່ນໆ',        'Other')
ON CONFLICT (code) DO NOTHING;

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'pg_trgm') THEN
    CREATE INDEX IF NOT EXISTS idx_villages_name_trgm  ON villages  USING gin (name_lo gin_trgm_ops);
    CREATE INDEX IF NOT EXISTS idx_districts_name_trgm ON districts USING gin (name_lo gin_trgm_ops);
  END IF;
END $$;

COMMIT;
