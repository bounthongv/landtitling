# Payment Verify — How to Use

Status: implemented (three-tab layout — commits b3acb00, 4342d55, cfe5b7e,
641bfc8, 44b7947; migration 010 applied on the live DB). Page:
https://landtax.apis.com.la/admin/payments.php

## 1. What this page does

The Payment Verify page handles every settlement of an annual land-tax
obligation. It is organised by **payment channel — one tab per channel** —
because the officer's work differs per channel:

1. **Office counter (ຮັບເງິນຢູ່ຫ້ອງການ)** — the citizen pays cash or
   cheque at the district office. Money is in hand: record it in one step,
   print the receipt. This is the **default tab**.
2. **Bank & mobile proofs (ກວດສະລິບທະນາຄານ)** — the citizen pays at the
   bank (or via the app) and sends the slip as proof, or the bank statement
   is matched automatically. The officer reviews the evidence.
3. **All payments (ທັງຫມົດ)** — the full ledger across every channel, for
   inspection and reporting.

Method labels: ເງິນສົດ (cash), ເຊັກ (cheque), ໂອນທະນາຄານ (bank
transfer), ແອັບມືຖື (mobile app), ຈ່າຍຢູ່ຫ້ອງການ (counter).

## 2. Tab: Office counter (default)

### 2.1 Record counter payment — the one-step form

For a citizen paying at the counter, do NOT create + verify in two steps.
Use the gold **Record counter payment (ບັນທຶກການຊໍາລະຢູ່ຫ້ອງການ)** box:

1. **Parcel no** — the parcel number from the land title
   (e.g. `PILOT-AST-0002`). When the parcel is recognised, the **Owner**
   (payer) dropdown fills in automatically — pick who actually paid.
2. **Tax year** — defaults to the current year.
3. **Method** — ເງິນສົດ (cash) or ເຊັກ (cheque). Choosing cheque reveals a
   **Cheque no** field — record the cheque number.
4. **Amount received** — leave blank for the full balance, or type a
   partial amount.
5. **Note** (optional).
6. Click **Receive & settle (ຮັບເງິນ ແລະ ປິດພັນທະ)**.

The payment is created **already PAID** (verify method = counter, the
logged-in officer recorded as the receiver), the obligation is settled
immediately, and a **Print receipt** button appears in the confirmation
message. Hand the printed receipt to the citizen — that is their proof of
payment.

### 2.2 Received today

Below the form: today's counter receipts (cash + cheque) as a table —
reference, parcel, owner, method, amount, time, and a **Receipt** button
per row — with a running total ("N payment(s), X kip today") for
end-of-day reconciliation.

## 3. Tab: Bank & mobile proofs

Summary cards at the top: **To review**, **Awaiting proof**,
**Matched automatically today**.

**Awaiting proof (ລໍຖ້າຫຼັກຖານ)** — a red box listing Issued bank/mobile
payments that have **no slip photo and no bank reference**. The citizen paid
(or claims to) but no proof arrived: contact them. Each line shows the
reference no, parcel, owner (or "no owner recorded"), and the issue date.

**Review queue** — every Issued bank/mobile payment, oldest first. For each:

1. **Look at the slip photo** (click it to zoom). It should show the bank's
   reference, date, and amount.
2. **Check the amount received** — the box next to Verify is pre-filled
   with the slip amount. If the citizen actually paid less or more,
   correct it to the real figure; the obligation then shows as
   ບາງສ່ວນ (PARTIAL) or ຈ່າຍເກີນ (OVERPAID) automatically.
3. **Add a note** if useful (optional).
4. Click **Verify (ກວດສອບ)** → the payment becomes Paid (ຈ່າຍແລ້ວ), the
   obligation is settled, and a **receipt** becomes printable.
5. If the proof is wrong or missing, optionally enter the **Reason**
   (ເຫດຜົນ) — e.g. "slip amount does not match" — then click
   **Reject (ປະຕິເສດ)** — the payment becomes Rejected (ປະຕິເສດແລ້ວ)
   and nothing is settled. The reason is recorded on the payment, so the
   citizen can see why; leaving it blank records the default
   "Rejected by officer".

**Matched automatically today** — payments settled directly from bank
statement matching (Paid, verify method = auto_statement), shown in a
table for transparency. Nothing to do; a receipt can still be printed.

## 4. Tab: All payments

- **Status cards**: Issued (to verify) / Paid / Rejected — across all
  channels and methods.
- **Filters**: Status (ອອກພັນທະແລ້ວ / ຈ່າຍແລ້ວ / ປະຕິເສດແລ້ວ /
  ຄືນເງິນແລ້ວ), Method, Tax year.
- **The ledger** — one card per payment: reference no (LFE-…), parcel,
  year, district/village, area, amount, method, payer, obligation status,
  verified via, bank ref, slip photo, and the action buttons.
- **Waive (ຍົກເວັ້ນ)** — shown on Paid payments: marks the obligation
  itself as waived (e.g. an exemption decided by the office). Use
  sparingly; every action is written to the audit log.
- **Print receipt** on every Paid payment; **Print slip** on Issued ones.

## 5. Printing slips and receipts

- The **order slip** (ພິມໃບຊໍາລະ) is the pre-payment document with the QR
  reference — printed when a payment is issued, for the citizen to take to
  the bank. It says "please pay by {due date} and send proof".
- The **receipt** (ພິມໃບຮັບເງິນ) is the post-payment document: PAID mark,
  paid date, amount, method, and the receiving officer. Available after a
  verified bank payment, a counter settlement, or an auto-match.

Both print through the officer's session (a `slip_print.php` proxy opened
in a browser tab); the URL alone, without the session, is refused.

## 6. Rules worth knowing

- Citizens cannot use cheque or cash through the app/API — those are
  district-office channels only (the API refuses them).
- The verified amount drives the obligation status: partial payments →
  PARTIAL (ບາງສ່ວນ), overpayments → OVERPAID (ຈ່າຍເກີນ). No special
  handling; the status is computed from the sum of Paid payments.
- The mobile-app channel (ແອັບມືຖື) is reserved — when the app ships, its
  payments enter this same Bank & mobile proofs queue.
- A rejected payment stays Rejected; the citizen resubmits. The officer
  can record a reason when rejecting (otherwise the default "Rejected by
  officer" is stored); the citizen can see the reason on the payment.
