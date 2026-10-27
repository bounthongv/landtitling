-- ===========================================================================
-- 009_citizen_payments.sql — attribute a payment to the citizen who made it
-- ===========================================================================
-- Additive + idempotent. Apply by hand on the server (CI only deploys code).
-- See docs/9-citizen-app-api-contract.md §0.
--
-- Why: a citizen may pay for a parcel that is not linked to their account —
-- they know the parcel number from their land title. The payment still has to
-- be attributed to the citizen who made it, or it never appears in their own
-- history. `payments.payer_owner_id` cannot do that: it points at owners.id,
-- not users.id.
--
-- Grant the web role after COMMIT (same pattern as 006/008):
--   GRANT SELECT, INSERT, UPDATE ON payments TO <web_role>;
--   GRANT USAGE, SELECT ON SEQUENCE payments_id_seq TO <web_role>;
-- ===========================================================================

SET search_path = landfee;
BEGIN;

ALTER TABLE payments ADD COLUMN IF NOT EXISTS citizen_user_id INT;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_payments_citizen_user') THEN
    ALTER TABLE payments ADD CONSTRAINT fk_payments_citizen_user
      FOREIGN KEY (citizen_user_id) REFERENCES users(id) ON DELETE SET NULL;
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_payments_citizen ON payments (citizen_user_id);

-- Backfill: where a payment was made by an owner who has since been linked to a
-- citizen account, adopt that link so existing history stays visible.
UPDATE payments pay
   SET citizen_user_id = o.citizen_user_id
  FROM owners o
 WHERE pay.citizen_user_id IS NULL
   AND pay.payer_owner_id = o.id
   AND o.citizen_user_id IS NOT NULL;

COMMIT;

-- Verify:
--   SELECT column_name FROM information_schema.columns
--    WHERE table_schema='landfee' AND table_name='payments'
--      AND column_name='citizen_user_id';
