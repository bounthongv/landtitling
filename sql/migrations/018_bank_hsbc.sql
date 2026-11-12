-- Migration 018 — add HSBC to the payment bank list
--
-- 017 seeded the Lao banks plus the foreign banks citizens most often pay
-- through (ANZ, BOC, ICBC, Maybank, ...) but left HSBC out. Citizens do pay
-- through it, so add it here rather than editing 017 (already applied).
--
-- APPLY MANUALLY on the server as the schema owner (postgres), BEFORE
-- deploying code that expects the row:
--   psql -U postgres -d landfee -f 018_bank_hsbc.sql
--
-- Idempotent: safe to re-run.

SET search_path = landfee;

INSERT INTO banks (code, name_lo, name_en, sort_order) VALUES
  ('HSBC', 'ທະນາຄານ HSBC', 'HSBC', 375)
ON CONFLICT (code) DO NOTHING;
