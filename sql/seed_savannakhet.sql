-- Land Fee Payment MVP — Savannakhet Province
-- Phase 0: Seed Data
-- Districts: official Savannakhet list (15 districts; Kaysone Phomvihane is the capital)
-- Zone rates: user's web check shows 80-180 kip per m² (80,000-180,000 kip per m²);
--   seeded inside that band by road category. Refine with the official MOF/central-office
--   schedule when available (legacy EP6/EP20 confirm the central office sets the figures).

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

-- Sample zone rates (from user's web check: 80-180 kip per m², i.e. 80,000-180,000 kip per m²)
-- Road category influences where in the band: main=180k, branch=140k, alley=110k, none=85k.
-- Adjust per road category only; per-district variation + official MOF schedule can refine later.
INSERT INTO zones_rates (district_id, road_category, rate_per_sqm, valid_from)
SELECT d.id, rc.rc, rc.rate, CURRENT_DATE
FROM districts d
CROSS JOIN (
  SELECT 'main' AS rc, 180000.00 AS rate UNION ALL
  SELECT 'branch', 140000.00 UNION ALL
  SELECT 'alley', 110000.00 UNION ALL
  SELECT 'none', 85000.00
) rc;

-- Sample user (officer — set a real bcrypt hash in deployment)
INSERT INTO users (role, username, phone, password_hash, is_active) VALUES
('admin', 'sav_officer', '+8562012345678', '$2y$10$placeholderpasswordhash', 1);