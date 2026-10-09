-- Land Fee Payment MVP — Savannakhet Province
-- Phase 0: Seed Data
-- Districts: official Savannakhet list (15 districts; Kaysone Phomvihane is the capital)
-- Zone rates: PLACEHOLDER values pending the official MOF/central-office rate schedule
--   (legacy transcripts EP6/EP20 confirm rates are issued by the central office,
--    not hard-coded; replace these placeholders when PERN supplies the real schedule)

SET search_path = landfee;

-- Districts (official Savannakhet list)
INSERT INTO districts (code, name_lo, name_en) VALUES
('KAY', 'ໄກສອນ ພົມວິຫານ', 'Kaysone Phomvihane'),
('OUT', 'ອຸທຸມພອນ', 'Outhoumphone'),
('ATS', 'ອາດສະພອນທອງ', 'Atsaphangthong'),
('PHI', 'ພີນ', 'Phine'),
('XEP', 'ເຊໂປນ', 'Sepon'),
('NON', 'ນອງ', 'Nong'),
('THA', 'ທ່າພັນທອງ', 'Thapangthong'),
('SON', 'ຊົນຄອນ', 'Songkhone'),
('CHA', 'ຈຳພອນ', 'Champhone'),
('XON', 'ຊົນບຸລີ', 'Xonbuly'),
('XAY', 'ໄຊບຸລີ', 'Xaybuly'),
('VIL', 'ວີລະບຸລີ', 'Vilabuly'),
('ATP', 'ອາດສະພອນ', 'Atsaphone'),
('XPH', 'ໄຊພູທອງ', 'Xayphouthong'),
('PHL', 'ຜະລັນໄຊ', 'Phalanxay');

-- Sample zone rates (PLACEHOLDER — replace with official MOF/central-office schedule)
INSERT INTO zones_rates (district_id, road_category, rate_per_sqm, valid_from)
SELECT d.id, rc.rc, rc.rate, CURRENT_DATE
FROM districts d
CROSS JOIN (
  SELECT 'main' AS rc, 50000.00 AS rate UNION ALL
  SELECT 'branch', 40000.00 UNION ALL
  SELECT 'alley', 30000.00 UNION ALL
  SELECT 'none', 20000.00
) rc;

-- Sample user (officer — set a real bcrypt hash in deployment)
INSERT INTO users (role, username, phone, password_hash, is_active) VALUES
('admin', 'sav_officer', '+8562012345678', '$2y$10$placeholderpasswordhash', 1);