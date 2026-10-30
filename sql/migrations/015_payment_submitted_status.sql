-- Migration 015 — payment status SUBMITTED
--
-- A citizen who issues a slip in the app pays it at the bank, photographs the
-- receipt and sends it to the district. At that moment the payment is no
-- longer merely ISSUED (nothing has arrived) and it is not PAID (no officer
-- has verified it): it is SUBMITTED — proof is in, decision is pending.
--
-- APPLY MANUALLY on the server as the schema owner (postgres), BEFORE deploying
-- code that writes 'SUBMITTED':
--   psql -U postgres -d landfee -f 015_payment_submitted_status.sql
-- Idempotent: the old status CHECK is found by definition, not by name.
-- Recreates the constraint as payments_status_check owned by the table owner;
-- the web role needs no new grant (it does not own the constraint).

SET search_path = landfee;
BEGIN;

DO $$
DECLARE
  c record;
BEGIN
  FOR c IN
    SELECT conname
      FROM pg_constraint
     WHERE conrelid = 'payments'::regclass
       AND contype  = 'c'
       AND pg_get_constraintdef(oid) LIKE '%status%'
       AND pg_get_constraintdef(oid) LIKE '%ISSUED%'
  LOOP
    EXECUTE format('ALTER TABLE payments DROP CONSTRAINT %I', c.conname);
  END LOOP;
END $$;

ALTER TABLE payments ADD CONSTRAINT payments_status_check
  CHECK (status IN ('ISSUED','SUBMITTED','PAID','REJECTED','REFUNDED'));

COMMIT;
