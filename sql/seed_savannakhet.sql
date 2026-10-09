-- Land Fee Payment MVP — Savannakhet Province
-- Phase 0: Seed Data (placeholders — replace with official PERN list)

USE landfee_savannakhet;

-- Districts (placeholder list — verify with PERN)
INSERT INTO districts (code, name_lo, name_en) VALUES
('SAV', 'ສະຫວັນນະເຂດ', 'Savannakhet'),
('KASE', 'ຄຳເຕີນ', 'Khamkeuth'),
('DOMP', 'ດອນ', 'Don'),
('PHONG', 'ພົງສະຫວັນ', 'Phong Savan'),
('KOUN', 'ຄູນ', 'Koune'),
('PHOU', 'ພູ', 'Phou'),
('LAK', 'ໄລຍ່າງ', 'Laik'),
('SEB', 'ເຊບັ້ງໄຟ', 'Sengxay'),
('PHINE', 'ພູນາບໍ່ເຄົ້າ', 'Phon'),
('ATAPON', 'ອັດທະບໍ່ແກ້ວ', 'Atsaban'),
('KHAM', 'ຄຳ', 'Kham'),
('HAT', 'ຫ້າມ', 'Hat'),
('KOK', 'ໂຄກ', 'Kok'),
('XEO', 'ເຊໂປນ', 'Xebangfai'),
('XEO', 'ເຊໂປນ', 'Xepian'),
('PHI', 'ພິນ', 'Phin'),
('MOOK', 'ມູກໄທດີນ', 'Mouk'),
('LAI', 'ໄລສະບຽງ', 'Laixabiang'),
('VET', 'ວັດຈັນ', 'Vadkan'),
('SE', 'ເຊບັ້ງໄຟ', 'Sengxay'),
('PHONG', 'ພົງສະຫວັນ', 'Phong Savan');

-- Sample zone rates (PLACEHOLDER — get official rates from PERN)
INSERT INTO zones_rates (district_id, road_category, rate_per_sqm, valid_from) 
SELECT d.id, 'main', 50000.00, CURRENT_DATE FROM districts d UNION ALL
SELECT d.id, 'branch', 40000.00, CURRENT_DATE FROM districts d UNION ALL
SELECT d.id, 'alley', 30000.00, CURRENT_DATE FROM districts d UNION ALL
SELECT d.id, 'none', 20000.00, CURRENT_DATE FROM districts d;

-- Sample user (officer — change password in real deployment)
INSERT INTO users (role, username, phone, password_hash, is_active) VALUES
('admin', 'sav_officer', '+8562012345678', '$2y$10$placeholderpasswordhash', 1);
