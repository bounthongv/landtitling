# Payment Verify — How to Use

Status: implemented (three-tab layout — commits b3acb00, 4342d55, cfe5b7e,
641bfc8, 44b7947; migration 010 applied on the live DB). Page:
https://landtax.apis.com.la/admin/payments.php

## 1. What this page does

The Payment Verify page handles every settlement of an annual land-tax
obligation. It is organised by **payment channel — one tab per channel** —
because the officer's work differs per channel:

1. **Office counter (ຮັບເງິນຢູ່ຫ້ອງການ)** — the citizen pays cash or
   cheque at the district office. Money is in hand: verify the obligation,
   then record it and print the receipt. This is the **default tab**.
2. **Bank & mobile proofs (ກວດສະລິບທະນາຄານ)** — the citizen pays at the
   bank (or via the app) and sends the slip as proof, or the bank statement
   is matched automatically. The officer reviews the evidence.
3. **All payments (ທັງຫມົດ)** — the full ledger across every channel, for
   inspection and reporting.

Method labels: ເງິນສົດ (cash), ເຊັກ (cheque), ໂອນທະນາຄານ (bank
transfer), ແອັບມືຖື (mobile app), ຈ່າຍຢູ່ຫ້ອງການ (counter).

## 2. Tab: Office counter (default)

### 2.1 Record counter payment — two steps

For a citizen paying at the counter, use the gold **Record counter
payment (ບັນທຶກການຊໍາລະຢູ່ຫ້ອງການ)** box. It is two steps so the
officer always knows how much the parcel owes *before* collecting money.

This is also the channel for **walk-in bank / mobile payments**: a citizen
pays at a bank counter (or mobile money) and brings the paper slip to the
office. No system record exists yet, so the officer records it here with
method = bank/mobile — the payment is created ISSUED, the slip is
attached, and it is verified so the obligation settles. Only pre-issued
records (mobile app / bank statement matching) go to the Bank tab.

**Step 1 — Verify obligation (ກວດສອບພັນທະ).**

1. **Parcel no** — the parcel number from the land title
   (e.g. `PILOT-AST-0002`).
2. **Tax year** — defaults to the current year.
3. **Method** — cash, cheque, bank transfer, or mobile money.
4. Click **Verify obligation**. The result panel below the form shows the
   parcel's obligation for that year:

   - **Assessed** — "Obligation for {year} ({status}) — due / paid /
     balance" (Lao: ພັນທະປີ …).
   - **Not yet issued** — "No obligation for {year} yet — settles at X kip
     (zone Z, rate kip/m²)": the obligation will be created at the zone
     rate when recorded.
   - **Already settled** — green "nothing to collect": step 2 stays
     hidden, there is nothing to record.
   - **No zone rate** — red pointer: assign a valuation zone in Basic
     Data first.

**Step 2 — Record & settle (ບັນທຶກ ແລະ ປິດພັນທະ).** Appears only when
step 1 found something to collect:

5. **Owner (payer)** — the parcel's owner(s) are pre-listed; pick who
   actually paid (a third party may pay).
6. **Amount received** — pre-filled with the balance (or the fresh
   assessment); leave blank for the full balance, or type a partial
   amount (the API caps it at the outstanding balance).
7. **Cheque no** — shown only when method = cheque.
8. **Bank ref** — shown only for bank/mobile (the reference printed on the
   slip; used later by bank statement matching).
9. **Evidence photo** (optional) — a file pick whose label follows the
   method: receipt photo (cash), photo of cheque, photo of bank slip, or
   photo of the mobile money confirmation message. The officer photographs
   the paper evidence with a phone or scans it; jpg/png/webp/pdf.
10. **Note** (optional).
11. Click **Record & settle**.

   - **cash / cheque** — created and settled in one API call
     (verify method = counter); the evidence photo, if any, is attached
     to the payment afterwards.
   - **bank / mobile** — created as ISSUED, evidence attached, then
     verified (verify method = manual) so the obligation settles. If the
     verify step fails for any reason, the payment stays ISSUED and
     shows up in the Bank tab's review queue instead.

Changing the parcel number or tax year after a verify hides step 2 and
clears the result — re-verify before recording.

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

This tab reviews **pre-issued** bank/mobile payments only — records
created by the mobile app or matched from bank statements. A citizen who
pays at a bank counter / mobile money and walks into the office with the
paper slip is recorded in the **Counter** tab instead (method =
bank/mobile, slip attached, verified there); it never lands in this
queue unless its verify step failed.

Summary cards at the top: **To review**, **Awaiting proof**,
**Matched automatically today**. A **Tax year** filter narrows the queue,
and the review list is paginated 50 at a time (oldest first, with
Previous/Next) so a big backlog stays workable.

**Awaiting proof (ລໍຖ້າຫຼັກຖານ)** — a red box listing Issued bank/mobile
payments that have **no slip photo and no bank reference**. The citizen paid
(or claims to) but no proof arrived: contact them. Each line shows the
reference no, parcel, owner (or "no owner recorded"), and the issue date.

**Review queue** — each payment is a standalone card (orange left border),
oldest first. The card shows:

- Reference no + status badge (ISSUED / PAID / REJECTED)
- "Awaiting proof" red badge when slip and bank ref are both empty
- Parcel link, tax year, district/village, area
- Amount + method + payer + bank ref
- Slip photo thumbnail (click to zoom)
- Verified info (method + date) if already settled

Each ISSUED card has action buttons on the right:

- **Upload slip (ຂຶ້ນຮູບສະລິບ)** — shown only when no slip photo has been
  filed yet. The citizen paid at a bank counter and brings the paper slip
  to the office: the officer takes/scan a photo of it and clicks the
  button. The photo is picked from the device and uploaded (jpg, png,
  webp, pdf), then the card immediately shows the photo, the "Awaiting
  proof" badge disappears, and the button is removed. (The mobile app will
  do this same upload automatically once it is live — same API endpoint.)
- **Verify (ກວດສອບ)** — opens a modal dialog; confirm the amount
  (pre-filled from the slip, editable for partial/overpaid) and add an
  optional note. Submit → payment becomes PAID, obligation settles, card
  fades out and a green flash appears.
- **Reject (ປະຕິເສດ)** — opens a modal dialog; optionally enter a reason
  (ເຫດຜົນ), e.g. "slip amount does not match". Submit → payment becomes
  REJECTED, nothing is settled, card fades out and a red flash appears.
  Leaving the reason blank records the default "Rejected by officer".

Both modals can be closed with the Cancel button or Escape key. The page
never reloads during verify/reject — smooth batch processing.

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
