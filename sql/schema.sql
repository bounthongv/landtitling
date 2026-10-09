-- Land Fee Payment MVP — Savannakhet Province
-- Phase 0: Database Schema

CREATE DATABASE IF NOT EXISTS landfee_savannakhet CHARACTER SET utf8mb3 COLLATE utf8mb3_bin;
USE landfee_savannakhet;

-- Districts
CREATE TABLE districts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) UNIQUE NOT NULL,
  name_lo VARCHAR(100) NOT NULL,
  name_en VARCHAR(100) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- Villages
CREATE TABLE villages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  district_id INT NOT NULL,
  code VARCHAR(20) NOT NULL,
  name_lo VARCHAR(100) NOT NULL,
  name_en VARCHAR(100) NULL,
  INDEX idx_district (district_id),
  INDEX idx_code (code),
  FOREIGN KEY (district_id) REFERENCES districts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- Zone rates (flat: depends only on location)
CREATE TABLE zones_rates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  district_id INT NOT NULL,
  road_category ENUM('main','branch','alley','none') NOT NULL,
  rate_per_sqm DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  valid_from DATE NOT NULL DEFAULT (CURRENT_DATE),
  note TEXT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_zone (district_id, road_category, valid_from),
  FOREIGN KEY (district_id) REFERENCES districts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- Owners
CREATE TABLE owners (
  id INT AUTO_INCREMENT PRIMARY KEY,
  citizen_id INT NULL,
  full_name VARCHAR(200) NOT NULL,
  id_number VARCHAR(50) NULL,
  phone VARCHAR(20) NULL,
  address TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- Parcels
CREATE TABLE parcels (
  id INT AUTO_INCREMENT PRIMARY KEY,
  parcel_no VARCHAR(50) NOT NULL,
  sub_parcel_no VARCHAR(20) NULL,
  village_id INT NOT NULL,
  district_id INT NOT NULL,
  area_sqm DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  road_category ENUM('main','branch','alley','none') NULL,
  status ENUM('draft','scanned','confirmed','active') DEFAULT 'draft',
  scanned_at TIMESTAMP NULL,
  ocr_confidence INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_parcel (parcel_no),
  INDEX idx_district_village (district_id, village_id),
  FOREIGN KEY (village_id) REFERENCES villages(id),
  FOREIGN KEY (district_id) REFERENCES districts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- Title scans
CREATE TABLE title_scans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  parcel_id INT NULL,
  image_path VARCHAR(255) NOT NULL,
  ocr_raw JSON NULL,
  ocr_extracted JSON NULL,
  verified_by INT NULL,
  verified_at TIMESTAMP NULL,
  note TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_parcel (parcel_id),
  FOREIGN KEY (parcel_id) REFERENCES parcels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- Users
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role ENUM('admin','officer','citizen') NOT NULL DEFAULT 'citizen',
  username VARCHAR(50) UNIQUE NULL,
  phone VARCHAR(20) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- Citizens (optional: extends owner)
CREATE TABLE citizens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  owner_id INT NOT NULL,
  citizen_name VARCHAR(200) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  id_number VARCHAR(50) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- Payments
CREATE TABLE payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  parcel_id INT NOT NULL,
  citizen_id INT NULL,
  transaction_type ENUM('transfer','inheritance','new_title') NULL,
  district_id INT NOT NULL,
  area_sqm DECIMAL(12,2) NOT NULL,
  zone_rate DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  fee_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status ENUM('ISSUED','PAID','REJECTED') DEFAULT 'ISSUED',
  slip_no VARCHAR(50) UNIQUE NULL,
  slip_qr VARCHAR(255) NULL,
  slip_path VARCHAR(255) NULL,
  paid_at TIMESTAMP NULL,
  verified_by INT NULL,
  verify_note TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_status (status),
  INDEX idx_district (district_id),
  FOREIGN KEY (parcel_id) REFERENCES parcels(id),
  FOREIGN KEY (citizen_id) REFERENCES citizens(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- Audit log
CREATE TABLE audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  actor_id INT NULL,
  action VARCHAR(50) NOT NULL,
  entity VARCHAR(50) NOT NULL,
  entity_id INT NOT NULL,
  detail JSON NULL,
  at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
