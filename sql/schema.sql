-- Land Fee Payment MVP — Savannakhet Province
-- Phase 0: Database Schema (PostgreSQL)

DROP SCHEMA IF EXISTS landfee CASCADE;
CREATE SCHEMA landfee;
SET search_path = landfee;

-- Districts
CREATE TABLE districts (
  id SERIAL PRIMARY KEY,
  code VARCHAR(20) UNIQUE NOT NULL,
  name_lo VARCHAR(100) NOT NULL,
  name_en VARCHAR(100)
);

-- Villages
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

-- Zone rates (flat: depends only on location)
CREATE TABLE zones_rates (
  id SERIAL PRIMARY KEY,
  district_id INT NOT NULL,
  road_category VARCHAR(10) NOT NULL CHECK (road_category IN ('main','branch','alley','none')),
  rate_per_sqm DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  valid_from DATE NOT NULL DEFAULT CURRENT_DATE,
  note TEXT,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_zones_rates_district
    FOREIGN KEY (district_id)
    REFERENCES districts(id)
    ON DELETE CASCADE
);

-- Owners
CREATE TABLE owners (
  id SERIAL PRIMARY KEY,
  citizen_id INT,
  full_name VARCHAR(200) NOT NULL,
  id_number VARCHAR(50),
  phone VARCHAR(20),
  address TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Parcels
CREATE TABLE parcels (
  id SERIAL PRIMARY KEY,
  parcel_no VARCHAR(50) NOT NULL,
  sub_parcel_no VARCHAR(20),
  village_id INT NOT NULL,
  district_id INT NOT NULL,
  area_sqm DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  road_category VARCHAR(10) CHECK (road_category IN ('main','branch','alley','none')),
  status VARCHAR(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','scanned','confirmed','active')),
  scanned_at TIMESTAMP,
  ocr_confidence INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_parcels_village
    FOREIGN KEY (village_id)
    REFERENCES villages(id),
  CONSTRAINT fk_parcels_district
    FOREIGN KEY (district_id)
    REFERENCES districts(id)
);

-- Title scans
CREATE TABLE title_scans (
  id SERIAL PRIMARY KEY,
  parcel_id INT,
  image_path VARCHAR(255) NOT NULL,
  ocr_raw JSONB,
  ocr_extracted JSONB,
  verified_by INT,
  verified_at TIMESTAMP,
  note TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_title_scans_parcel
    FOREIGN KEY (parcel_id)
    REFERENCES parcels(id)
    ON DELETE CASCADE
);

-- Users
CREATE TABLE users (
  id SERIAL PRIMARY KEY,
  role VARCHAR(20) NOT NULL DEFAULT 'citizen' CHECK (role IN ('admin','officer','citizen')),
  username VARCHAR(50) UNIQUE,
  phone VARCHAR(20) UNIQUE NOT NULL,
  password_hash VARCHAR(255),
  is_active BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Citizens (optional: extends owner)
CREATE TABLE citizens (
  id SERIAL PRIMARY KEY,
  owner_id INT NOT NULL,
  citizen_name VARCHAR(200) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  id_number VARCHAR(50),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_citizens_owner
    FOREIGN KEY (owner_id)
    REFERENCES owners(id)
    ON DELETE CASCADE
);

-- Payments
CREATE TABLE payments (
  id SERIAL PRIMARY KEY,
  parcel_id INT NOT NULL,
  citizen_id INT,
  transaction_type VARCHAR(20) CHECK (transaction_type IN ('transfer','inheritance','new_title')),
  district_id INT NOT NULL,
  area_sqm DECIMAL(12,2) NOT NULL,
  zone_rate DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  fee_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status VARCHAR(20) NOT NULL DEFAULT 'ISSUED' CHECK (status IN ('ISSUED','PAID','REJECTED')),
  slip_no VARCHAR(50) UNIQUE,
  slip_qr VARCHAR(255),
  slip_path VARCHAR(255),
  paid_at TIMESTAMP,
  verified_by INT,
  verify_note TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payments_parcel
    FOREIGN KEY (parcel_id)
    REFERENCES parcels(id),
  CONSTRAINT fk_payments_citizen
    FOREIGN KEY (citizen_id)
    REFERENCES citizens(id)
);

-- Audit log
CREATE TABLE audit_log (
  id SERIAL PRIMARY KEY,
  actor_id INT,
  action VARCHAR(50) NOT NULL,
  entity VARCHAR(50) NOT NULL,
  entity_id INT NOT NULL,
  detail JSONB,
  at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
