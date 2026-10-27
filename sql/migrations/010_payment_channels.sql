-- ===========================================================================
-- 010_payment_channels.sql — add 'cheque' to payments.method
-- ===========================================================================
-- Additive + idempotent. Apply by hand on the server (CI only deploys code).
--
-- Why: the district office receives direct payments in cash OR by cheque
-- (per the payment-channels discussion). The DB only allowed
-- counter/bank_transfer/mobile/cash, so a cheque could not be recorded.
--
-- The web role needs no new grant (ALTER TABLE runs as superuser, and the
-- CHECK constraint is schema metadata, not a table privilege).
-- ===========================================================================

SET search_path = landfee;
BEGIN;

-- Rebuild the method CHECK to include 'cheque'. The constraint name is
-- auto-generated (payments_method_check); drop-if-exists makes this
-- idempotent across re-runs.
ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_method_check;
ALTER TABLE payments ADD CONSTRAINT payments_method_check
  CHECK (method IN ('counter','bank_transfer','mobile','cash','cheque'));

-- Counter settlements (cash/cheque received at the office) record
-- verify_method='counter' — a distinct channel from manual slip review.
ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_verify_method_check;
ALTER TABLE payments ADD CONSTRAINT payments_verify_method_check
  CHECK (verify_method IN ('manual','auto_statement','auto_ocr','counter'));

COMMIT;
