# Payment Verify — How to Use

Status: implemented (commits b3acb00 + 4342d55, migration 010 applied on the
live DB). Page: https://landtax.apis.com.la/admin/payments.php

## 1. What this page does

The Payment Verify queue is where every settlement of an annual land-tax
obligation is handled. It supports the three real payment channels:

1. **Bank counter** — the citizen pays at the bank, then sends the bank slip
   (photo or paper) to the district office as proof.
2. **Mobile app** (planned) — the citizen pays through the app; the payment
   enters this same queue and is matched against the bank statement.
3. **District office, direct** — the citizen pays **cash or cheque** at the
   counter, with the officer receiving the money on the spot.

Channel 3 does NOT need the review queue — money is already in hand. It has
its own one-step form (section 4).

## 2. Page layout

- **Status cards** at the top: Issued (to verify), Paid, Rejected.
- **Filters**: Status, Method (ເງິນສົດ / ໂອນທະນາຄານ / ແອັບມືຖື / ເຊັກ /
  ຈ່າຍຢູ່ຫ້ອງການ), Tax year.
- **Record counter payment** box (gold border) — the direct cash/cheque form.
- **The queue** — one card per payment: reference no (LFE-…), parcel, year,
  district/village, area, amount, method, payer, obligation status, bank ref,
  slip photo, and the action buttons.

## 3. Bank / mobile payments — review the proof (the default queue)

A bank payment arrives here as **Issued (ອອກແລ້ວ)**. Bank-statement
auto-matches arrive already **Paid** and need nothing.

For each Issued payment the officer must check the evidence:

1. **Look at the slip photo** (click it to zoom). It should show the bank's
   reference, date, and amount.
2. **Check the amount received** — the field next to Verify is pre-filled
   with the slip amount. If the citizen actually paid less or more at the
   bank, correct it to the real figure. The obligation then shows as
   ບາງສ່ວນ (partial) or ຈ່າຍເກີນ (overpaid) automatically.
3. **Add a note** if useful (optional).
4. Click **Verify (ກວດສອບ)** → payment becomes Paid, the obligation is
   settled, and a **receipt** becomes printable for the citizen.
5. If the proof is wrong or missing, click **Reject (ປະຕິເສດ)** — the
   payment is returned as Rejected and nothing is settled. (A fixed default
   reason is recorded; contact the citizen about what went wrong.)

**Awaiting proof (ລໍຖ້າຫຼັກຖານ)** — a red tag on an Issued bank/mobile
payment that has **no slip photo and no bank reference**. That citizen paid
(or claims to) but no proof arrived; the officer should contact them.

## 4. Cash / cheque at the district office — the one-step form

For a citizen paying **at the counter**, do not create + verify in two steps.
Use the gold **Record counter payment** box:

1. **Parcel no** — the parcel number from the land title (e.g. `PILOT-AST-0002`).
2. **Tax year** — default is the current year.
3. **Method** — ເງິນສົດ (cash) or ເຊັກ (cheque). Choosing cheque reveals a
   **Cheque no** field — record the cheque number.
4. **Amount received** — leave blank to take the full balance, or type a
   partial amount.
5. **Note** (optional).
6. Click **Receive & settle (ຮັບເງິນ ແລະ ປິດພັນທະ)**.

The payment is created **already Paid** (verify method = counter, officer
recorded as receiver), the obligation is settled immediately, and a
**Print receipt** button appears in the confirmation message. Hand the
printed receipt to the citizen — that is their proof of payment.

## 5. Printing slips and receipts

- The **order slip** (ພິມໃບຊໍາລະ) is the pre-payment document with the QR
  reference — printed when a payment is issued, for the citizen to take to
  the bank. It says "please pay by {due date} and send proof".
- The **receipt** (ພິມໃບຮັບເງິນ) is the post-payment document: PAID mark,
  paid date, amount, method, and the receiving officer. Available on every
  Paid payment — print it after verifying a bank payment or after a counter
  settlement.

Both print through the officer session (a browser tab); they cannot be
opened by URL directly.

## 6. Other actions

- **Waive (ຍົກເວັ້ນ)** — shown on Paid payments: marks the obligation
  itself as waived (e.g. exemption decided by the office). Use sparingly;
  every action is written to the audit log.
- **Method filter** — e.g. "show me only bank transfers" to review one
  channel at a time.
- **Refunded / Disputed** obligations appear in the Status filter as the
  business needs them.

## 7. Rules worth knowing

- Citizens cannot use cheque or cash through the app/API — those are
  district-office channels only (the API refuses them).
- The verified amount drives the obligation status: partial payments →
  PARTIAL (ບາງສ່ວນ), overpayments → OVERPAID (ຈ່າຍເກີນ). No special handling
  is needed; the status is computed from the sum of Paid payments.
- The mobile-app channel is reserved (method ແອັບມືຖື) and will reuse this
  queue when the app ships.
