-- Migration 020 — Agent Cash Payment Feature
--
-- Enables community agents (village heads, secretaries) to collect cash
-- payments on behalf of citizens and later hand over to district office.
--
-- APPLY MANUALLY on the server as the schema owner (postgres):
--   psql -U postgres -d landfee -f 020_agent_cash_payment.sql
--
-- Idempotent: safe to re-run.

SET search_path = landfee;

-- =========================================================================
-- 1. Extend payments table for cash-agent collections
-- =========================================================================

-- The column must carry a DEFAULT: every pre-existing payment insert path
-- (citizen_payments.php, payments.php, bank matching, admin counter) does not
-- name this column. NOT NULL without a default would make all of them fail.
ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS payment_method VARCHAR(20) NOT NULL DEFAULT 'bank_transfer'
    CHECK (payment_method IN ('bank_transfer', 'cash_agent'));

-- Backfill any row created while the column briefly existed without a default.
UPDATE payments SET payment_method = 'bank_transfer' WHERE payment_method IS NULL;

ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS agent_id INT,
  ADD COLUMN IF NOT EXISTS agent_role VARCHAR(30),
  ADD COLUMN IF NOT EXISTS payer_name VARCHAR(200),
  ADD COLUMN IF NOT EXISTS payer_phone VARCHAR(20),
  ADD COLUMN IF NOT EXISTS payer_id_card VARCHAR(50),
  ADD COLUMN IF NOT EXISTS collected_at TIMESTAMP WITH TIME ZONE,
  ADD COLUMN IF NOT EXISTS handed_over_at TIMESTAMP WITH TIME ZONE,
  ADD COLUMN IF NOT EXISTS handover_officer_id INT,
  ADD COLUMN IF NOT EXISTS handover_id BIGINT;

-- FKs (idempotent)
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.table_constraints
    WHERE constraint_name = 'fk_payments_agent' AND table_name = 'payments'
  ) THEN
    ALTER TABLE payments ADD CONSTRAINT fk_payments_agent
      FOREIGN KEY (agent_id) REFERENCES users(id);
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.table_constraints
    WHERE constraint_name = 'fk_payments_handover_officer' AND table_name = 'payments'
  ) THEN
    ALTER TABLE payments ADD CONSTRAINT fk_payments_handover_officer
      FOREIGN KEY (handover_officer_id) REFERENCES users(id);
  END IF;
  -- NOTE: fk_payments_handover is added after agent_handovers is created (section 3).
END $$;

-- Prevent double-collection: at most one OPEN cash_agent payment per
-- obligation, whichever agent takes it (a settled/rejected slip releases it).
-- The older (obligation_id, agent_id) index allowed a second agent to collect
-- the same obligation while the first collection was still pending handover.
DROP INDEX IF EXISTS uq_cash_agent_obligation;
CREATE UNIQUE INDEX IF NOT EXISTS uq_cash_agent_obligation_open
  ON payments (obligation_id)
  WHERE payment_method = 'cash_agent'
    AND obligation_id IS NOT NULL
    AND status IN ('ISSUED', 'SUBMITTED');

-- Indexes for agent queries
CREATE INDEX IF NOT EXISTS idx_payments_agent ON payments (agent_id);
CREATE INDEX IF NOT EXISTS idx_payments_method ON payments (payment_method);
CREATE INDEX IF NOT EXISTS idx_payments_collected ON payments (collected_at);
CREATE INDEX IF NOT EXISTS idx_payments_handover ON payments (handed_over_at);
CREATE INDEX IF NOT EXISTS idx_payments_handover_id ON payments (handover_id);

-- =========================================================================
-- 2. Extend users table for agent flag + scope
-- =========================================================================

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS is_agent BOOLEAN NOT NULL DEFAULT FALSE,
  ADD COLUMN IF NOT EXISTS agent_village_id INT,
  ADD COLUMN IF NOT EXISTS agent_district_id INT,
  ADD COLUMN IF NOT EXISTS agent_role VARCHAR(30),
  ADD COLUMN IF NOT EXISTS agent_license_no VARCHAR(50);

-- FKs for agent scope
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.table_constraints
    WHERE constraint_name = 'fk_users_agent_village' AND table_name = 'users'
  ) THEN
    ALTER TABLE users ADD CONSTRAINT fk_users_agent_village
      FOREIGN KEY (agent_village_id) REFERENCES villages(id);
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.table_constraints
    WHERE constraint_name = 'fk_users_agent_district' AND table_name = 'users'
  ) THEN
    ALTER TABLE users ADD CONSTRAINT fk_users_agent_district
      FOREIGN KEY (agent_district_id) REFERENCES districts(id);
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_users_is_agent ON users (is_agent) WHERE is_agent = TRUE;
CREATE INDEX IF NOT EXISTS idx_users_agent_village ON users (agent_village_id);

-- =========================================================================
-- 3. Create agent_handovers table (batch handover to district)
-- =========================================================================

CREATE TABLE IF NOT EXISTS agent_handovers (
  id BIGSERIAL PRIMARY KEY,
  agent_id INT NOT NULL,
  agent_village_id INT,
  district_officer_id INT,
  amount_total NUMERIC(14,2) NOT NULL DEFAULT 0 CHECK (amount_total >= 0),
  payment_count INT NOT NULL DEFAULT 0 CHECK (payment_count >= 0),
  handed_over_at TIMESTAMP WITH TIME ZONE,
  verified_at TIMESTAMP WITH TIME ZONE,
  verified_by INT,
  status VARCHAR(20) NOT NULL DEFAULT 'pending'
    CHECK (status IN ('pending', 'verified', 'rejected')),
  notes TEXT,
  batch_reference VARCHAR(50),
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ah_agent
    FOREIGN KEY (agent_id) REFERENCES users(id),
  CONSTRAINT fk_ah_village
    FOREIGN KEY (agent_village_id) REFERENCES villages(id),
  CONSTRAINT fk_ah_officer
    FOREIGN KEY (district_officer_id) REFERENCES users(id),
  CONSTRAINT fk_ah_verified_by
    FOREIGN KEY (verified_by) REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_ah_agent_status ON agent_handovers (agent_id, status);
CREATE INDEX IF NOT EXISTS idx_ah_officer ON agent_handovers (district_officer_id);
CREATE INDEX IF NOT EXISTS idx_ah_created ON agent_handovers (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_ah_village ON agent_handovers (agent_village_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_ah_batch_ref ON agent_handovers (batch_reference) WHERE batch_reference IS NOT NULL;

-- Batch reference sequence (AH-{year}-{seq6}). Created here so the web role
-- does not need CREATE privilege at runtime; the endpoint still ensures it for
-- databases where this migration ran before the column existed.
CREATE SEQUENCE IF NOT EXISTS agent_handover_ref_seq;

-- Now that the table exists, wire payments.handover_id to it.
DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.table_constraints
    WHERE constraint_name = 'fk_payments_handover' AND table_name = 'payments'
  ) THEN
    ALTER TABLE payments ADD CONSTRAINT fk_payments_handover
      FOREIGN KEY (handover_id) REFERENCES agent_handovers(id);
  END IF;
END $$;

-- =========================================================================
-- 4. Create payment_receipts table (immutable receipt storage)
-- =========================================================================

CREATE TABLE IF NOT EXISTS payment_receipts (
  id BIGSERIAL PRIMARY KEY,
  payment_id INT NOT NULL UNIQUE,
  receipt_html TEXT NOT NULL,
  receipt_pdf BYTEA,
  created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pr_payment
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_pr_payment ON payment_receipts (payment_id);

-- =========================================================================
-- 5. Grant permissions to web role (landfee_web)
-- =========================================================================

-- Applied as the schema owner, so the new tables/views are owned by postgres.
-- The web role needs explicit grants or the endpoints 500 with an empty body.
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'landfee_web') THEN
    GRANT SELECT, INSERT, UPDATE, DELETE ON agent_handovers, payment_receipts TO landfee_web;
    GRANT SELECT, INSERT, UPDATE ON payments TO landfee_web;
    GRANT USAGE, SELECT ON SEQUENCE agent_handovers_id_seq, payment_receipts_id_seq, agent_handover_ref_seq TO landfee_web;
    GRANT UPDATE ON SEQUENCE agent_handover_ref_seq TO landfee_web;  -- setval() in the endpoint
  END IF;
END $$;

-- =========================================================================
-- 6. Helpful views for agent/officer dashboards
-- =========================================================================

-- Agent's pending cash collections (not yet handed over).
-- Dropped first: CREATE OR REPLACE VIEW cannot add/reorder columns, and an
-- earlier revision of this migration may already have created the view.
DROP VIEW IF EXISTS agent_pending_collections;
CREATE VIEW agent_pending_collections AS
SELECT
  p.id AS payment_id,
  p.reference_no,
  p.parcel_id,
  par.parcel_no,
  par.village_id,
  v.name_lo AS village_name,
  own.full_name AS citizen_name,
  p.payer_name,
  p.amount,
  p.collected_at,
  p.handed_over_at,
  p.agent_id,
  u.username AS agent_username,
  u.agent_role
FROM payments p
JOIN parcels par ON par.id = p.parcel_id
JOIN villages v ON v.id = par.village_id
LEFT JOIN LATERAL (
  SELECT o.full_name
    FROM parcel_owners po
    JOIN owners o ON o.id = po.owner_id
   WHERE po.parcel_id = par.id AND po.role = 'owner'
   ORDER BY o.id
   LIMIT 1
) own ON TRUE
LEFT JOIN users u ON u.id = p.agent_id
WHERE p.payment_method = 'cash_agent'
  AND p.handed_over_at IS NULL
  AND p.status IN ('ISSUED', 'SUBMITTED');

-- Officer view: agent handovers awaiting verification
DROP VIEW IF EXISTS officer_agent_handovers_pending;
CREATE VIEW officer_agent_handovers_pending AS
SELECT
  ah.id AS handover_id,
  ah.agent_id,
  u.username AS agent_username,
  u.agent_village_id,
  v.name_lo AS village_name,
  ah.amount_total,
  ah.payment_count,
  ah.handed_over_at,
  ah.status,
  ah.created_at
FROM agent_handovers ah
JOIN users u ON u.id = ah.agent_id
LEFT JOIN villages v ON v.id = ah.agent_village_id
WHERE ah.status = 'pending'
  AND ah.district_officer_id IS NULL;   -- nobody has taken the batch yet

-- View grants (views exist only after the block above).
DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'landfee_web') THEN
    GRANT SELECT ON agent_pending_collections, officer_agent_handovers_pending TO landfee_web;
  END IF;
END $$;

-- =========================================================================
-- Done.
-- =========================================================================