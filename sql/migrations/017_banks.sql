-- Migration 017 — Lao bank list for payment receipts
--
-- Citizens pay the issued slip through their own bank (counter, mobile app,
-- cheque). We record which bank they used so officers can match/verify the
-- receipt against the right institution. Logos are stored as file paths and
-- filled in later; for now the list is text-only (code + names).
--
-- APPLY MANUALLY on the server as the schema owner (postgres), BEFORE deploying
-- code that reads it:
--   psql -U postgres -d landfee -f 017_banks.sql
--
-- Idempotent: safe to re-run.

SET search_path = landfee;

-- ---------------------------------------------------------------------------
-- Banks (reference data, admin-maintained)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS banks (
  code       VARCHAR(20) PRIMARY KEY,
  name_lo    VARCHAR(120) NOT NULL,
  name_en    VARCHAR(120) NOT NULL,
  logo_path  VARCHAR(255),
  is_active  BOOLEAN NOT NULL DEFAULT TRUE,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

COMMENT ON TABLE banks IS
  'Lao banks citizens may pay through. code is the official abbreviation (BCEL, LDB, ...).';

-- ---------------------------------------------------------------------------
-- payments: which bank the citizen paid through (nullable)
-- ---------------------------------------------------------------------------
ALTER TABLE payments ADD COLUMN IF NOT EXISTS bank_code VARCHAR(20);
DO $$ BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'fk_payments_bank'
  ) THEN
    ALTER TABLE payments
      ADD CONSTRAINT fk_payments_bank FOREIGN KEY (bank_code) REFERENCES banks(code);
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_payments_bank_code ON payments (bank_code);

-- ---------------------------------------------------------------------------
-- Seed the common Lao banks (idempotent)
-- ---------------------------------------------------------------------------
INSERT INTO banks (code, name_lo, name_en, sort_order) VALUES
  ('BCEL',   'ທະນາຄານການຄ້າຕ່າງປະເທດລາວ',          'Banque pour le Commerce Exterieur Lao Public', 10),
  ('LDB',    'ທະນາຄານພັດທະນາລາວ',                      'Lao Development Bank',                       20),
  ('APB',    'ທະນາຄານກະສິກໍາສົງເລິມ',                 'Agricultural Promotion Bank',                30),
  ('NBB',    'ທະນາຄານນາໂຍບາຍ',                         'Nayoby Bank',                                40),
  ('LVB',    'ທະນາຄານລາວ-ຫວຽດ',                        'Lao-Viet Bank',                              50),
  ('BDL',    'ທະນາຄານຝຣັ່ງ-ລາວ',                       'Banque Franco-Lao',                          60),
  ('LCNB',   'ທະນາຄານລາວ-ຈີນ',                         'Lao China Bank',                             70),
  ('JDB',    'ທະນາຄານຮ່ວມພັດທະນາ',                     'Joint Development Bank',                     80),
  ('PSVB',   'ທະນາຄານພົງສະຫວັນ',                      'Phongsavanh Bank',                           90),
  ('ICB',    'ທະນາຄານອິນໂດຈີນ',                        'Indochina Bank',                             100),
  ('BYB',    'ທະນາຄານບູຢັງລາວ',                        'Booyoung Lao Bank',                          110),
  ('MJB',    'ທະນາຄານມາຣູຮັນ ຍີ່ປຸ່ນ ລາວ',            'Maruhan Japan Bank Lao',                     120),
  ('BIC',    'ທະນາຄານການລົງທຶນ ແລະ ການຄ້າ',           'Bank for Investment and Commerce',           130),
  ('STB',    'ທະນາຄານ ST',                              'ST Bank',                                    140),
  ('ACLEDA', 'ທະນາຄານອາເຄລດາ ລາວ',                     'ACLEDA Bank Lao',                            150),
  ('RHB',    'ທະນາຄານ RHB ລາວ',                        'RHB Bank Lao',                               160),
  ('KBank',  'ທະນາຄານກະສິກອນໄທ ລາວ',                  'Kasikornthaibank Lao',                      170),
  ('SAC',    'ທະນາຄານຊາຄົມບັງ ລາວ',                   'Saigon Thuong Tin Bank Lao',                 180),
  ('VTB',    'ທະນາຄານຫວຽດຕິນ ລາວ',                    'VietinBank Lao',                             190),
  ('VCB',    'ທະນາຄານຫວຽດຄອມ ລາວ',                    'Vietcombank Lao',                            200),
  ('CNDB',   'ທະນາຄານການາດຽ ລາວ',                     'Canadia Bank Lao',                           210),
  ('SHB',    'ທະນາຄານຊາຍງ່ອນ-ຮາໂນ້ຍ ລາວ',             'Saigon-Hanoi Bank Lao',                      220),
  ('LCB',    'ທະນາຄານກໍ່ສ້າງລາວ (ປິດກິດຈະການ)',     'Lao Construction Bank (Defunct)',             230),
  ('BBL',    'ທະນາຄານບາງກອກ',                          'Bangkok Bank',                               240),
  ('KTB',    'ທະນາຄານກຸງໄທ',                           'Krungthai Bank',                             250),
  ('KRUNGSRI','ທະນາຄານອາຢຸດທະຍາ',                      'Bank of Ayudhya',                            260),
  ('TTB',    'ທະນາຄານ TMBThanachart',                   'TMBThanachart Bank',                         270),
  ('SCB',    'ທະນາຄານການຄ້າສະຫຍາມ',                   'Siam Commercial Bank',                       280),
  ('PB',     'ທະນາຄານສາທາລະນະ',                        'Public Bank',                                290),
  ('MB',     'ທະນາຄານການທະຫານ',                        'Military CJS Bank',                          300),
  ('ICBC',   'ທະນາຄານການຄ້າ ແລະ ອຸດສາຫະກໍາຂອງຈີນ', 'Industrial and Commercial Bank of China',     310),
  ('CIMB',   'ທະນາຄານ CIMB ໄທ',                        'CIMB Thai',                                  320),
  ('CUBC',   'ທະນາຄານ Cathay United',                  'Cathay United Bank',                         330),
  ('BOC',    'ທະນາຄານຂອງຈີນ',                          'Bank of China',                              340),
  ('FCB',    'ທະນາຄານການຄ້າທໍາອິດ',                   'First Commercial Bank',                      350),
  ('MAYBANK','ທະນາຄານ Maybank',                         'Maybank',                                    360),
  ('ANZ',    'ທະນາຄານ ANZ',                             'Australia and New Zealand Banking Group',    370),
  ('TCB',    'ທະນາຄານຮ່ວມມືໄທວັນ',                    'Taiwan Cooperative Bank',                    380)
ON CONFLICT (code) DO NOTHING;

-- ---------------------------------------------------------------------------
-- Grants for the web role
-- ---------------------------------------------------------------------------
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'landfee_web') THEN
    EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON banks TO landfee_web';
  END IF;
END $$;