-- =========================================================================
-- Seed: staff accounts for Savannakhet (Land Tax MVP)
--  - one district ADMIN per Savannakhet district (codes 1301-1315)
--  - one province ADMIN / MANAGER / OFFICER
--
-- Password for ALL accounts: demo1234  (change immediately after first use)
--
-- Idempotent: ON CONFLICT (username) DO NOTHING — re-running never duplicates.
-- Requires migration 003 first (users.level, users.district_id).
--
-- Run on the server:
--     psql -U landfee_web -d landfee -f 003_seed_staff_accounts.sql
--
-- Username convention: <first word of district english name>_admin
--   1301 nakhone_admin            1308 songkhone_admin
--   1302 outoumphone_admin        1309 champhone_admin
--   1303 atsaphangthong_admin     1310 xonnabouly_admin
--   1304 phine_admin              1311 xaybouly_admin
--   1305 seponh_admin             1312 vilabouly_admin
--   1306 nong_admin               1313 atsaphone_admin
--   1307 thapangthong_admin       1314 xayphouthong_admin
--                             1315 phalanxay_admin
-- Province level: snnk_admin / snnk_manager / snnk_officer
-- =========================================================================

SET search_path = landfee;
BEGIN;

-- demo1234 (bcrypt, PHP PASSWORD_BCRYPT)
-- All seeded accounts share this placeholder password.
INSERT INTO users (role, username, phone, password_hash, is_active, level, district_id)
SELECT 'admin',
       lower(split_part(d.name_en, ' ', 1)) || '_admin',
       '+8569' || d.code || '000',
       '$2y$12$EdOGnUmaNhaBcpvgn9IMXuxrajOMVt4/GjzKsLd.nKwusZXwvGBwe',
       TRUE,
       'district',
       d.id
FROM districts d
WHERE left(d.code, 2) = '13'
ON CONFLICT (username) DO NOTHING;

INSERT INTO users (role, username, phone, password_hash, is_active, level, district_id)
VALUES
  ('admin',    'snnk_admin',    '+8562100001', '$2y$12$EdOGnUmaNhaBcpvgn9IMXuxrajOMVt4/GjzKsLd.nKwusZXwvGBwe', TRUE, 'province', NULL),
  ('manager',  'snnk_manager',  '+8562100002', '$2y$12$EdOGnUmaNhaBcpvgn9IMXuxrajOMVt4/GjzKsLd.nKwusZXwvGBwe', TRUE, 'province', NULL),
  ('officer',  'snnk_officer',  '+8562100003', '$2y$12$EdOGnUmaNhaBcpvgn9IMXuxrajOMVt4/GjzKsLd.nKwusZXwvGBwe', TRUE, 'province', NULL)
ON CONFLICT (username) DO NOTHING;

COMMIT;

-- Verify:
-- SELECT u.username, u.role, u.level, d.code, d.name_en
--   FROM users u LEFT JOIN districts d ON d.id = u.district_id
--  WHERE u.role <> 'citizen' ORDER BY u.level, u.username;
