-- Migration 014 — 10% late-payment surcharge (decree 385/2009, Art. 10)
--
-- The surcharge itself is derived from amount_due + due_date for UNPAID/PARTIAL
-- obligations, then frozen into fee_obligations.penalty when the obligation is
-- settled. These columns hold an officer's waive/reduce decision, which wins
-- over the automatic 10% while the obligation is still unpaid.
--
-- APPLY MANUALLY on the server as the schema owner (postgres), BEFORE deploying
-- code that reads the new columns:
--   psql -U postgres -d landfee -f 014_late_payment_penalty.sql
-- Idempotent.

SET search_path = landfee;
BEGIN;

ALTER TABLE fee_obligations
  ADD COLUMN IF NOT EXISTS penalty_override DECIMAL(14,2),
  ADD COLUMN IF NOT EXISTS penalty_note     TEXT,
  ADD COLUMN IF NOT EXISTS penalty_set_by   INT,
  ADD COLUMN IF NOT EXISTS penalty_set_at   TIMESTAMP;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint
                 WHERE conrelid = 'fee_obligations'::regclass
                   AND conname  = 'fee_obligations_penalty_override_nonneg') THEN
    ALTER TABLE fee_obligations ADD CONSTRAINT fee_obligations_penalty_override_nonneg
      CHECK (penalty_override IS NULL OR penalty_override >= 0);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_constraint
                 WHERE conrelid = 'fee_obligations'::regclass
                   AND conname  = 'fee_obligations_penalty_set_by_fkey') THEN
    ALTER TABLE fee_obligations ADD CONSTRAINT fee_obligations_penalty_set_by_fkey
      FOREIGN KEY (penalty_set_by) REFERENCES users(id);
  END IF;
END $$;

COMMIT;
