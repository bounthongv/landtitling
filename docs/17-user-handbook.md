# Land Tax Collection System — User Handbook

**Savannakhet Province · ລະບົບເກັບເງິນພາສີທີ່ດິນ**

Status: written 2026-11-03 from the live screens in `public/admin/` and
`mobile/`, and from the guides in `docs/`. Every screen, tab and field named
below exists in the deployed system. Lao labels are quoted exactly as they
appear on screen, because the interface is Lao by default.

Audience: district officers, district and province administrators, managers,
and anyone supporting the citizen mobile app.

---

## How to read this handbook

The system has two front doors:

| You are… | You use… | Chapter |
|---|---|---|
| A district officer or administrator | the web back-office | Part 2 |
| A citizen on a phone | the mobile app (**ຊໍາລະຄ່າທີ່ດິນ**) | Part 3 |

Every back-office screen is Lao first. The **EN | ລາວ** toggle at the top of the
sidebar switches the labels; the data itself (names of villages, owners, reason
text) is always stored as typed, so it does not change with the toggle.

Two conventions used throughout:

- **Bold Lao in brackets** after an English name means: this is what the button
  or field is called on screen, e.g. *Print receipt (ພິມໃບຮັບເງິນ)*.
- **kip** is the currency everywhere. Areas are **m2** (square metres).

---

# Part 1 — The system in one page

## 1.1 What it does

The system collects the **annual land tax** on every parcel of land in the
province. It holds the land register, calculates what each parcel owes each
year, records payments, and produces the reports the province and the Ministry
of Finance need.

The whole tax calculation is one formula:

```
annual tax = rate per m2 × area in m2
```

- The **area** comes from the land title and is recorded once, when the parcel
  is registered.
- The **rate per m2** comes from the parcel's **valuation zone** and the
  **road access** of the land. It is maintained centrally in
  Basic Data → Zone rates, so a rate change applies to every parcel in that
  zone without anyone editing the parcels.

Nothing about the person, the transaction or the land use changes the amount.
Two parcels of the same size in the same zone with the same road access owe
exactly the same.

## 1.2 The annual cycle

```
 1. Register parcels        →  Parcel Entry / Parcels
 2. Open the tax year       →  Basic Data → Fee years
 3. Generate obligations    →  Obligations → Generate
 4. Citizens pay and prove  →  mobile app, or the office counter
 5. Officers verify         →  Payment Verify
 6. Report                  →  Reports
```

Steps 3 happens automatically every night at 02:00 for the open tax year, so a
parcel registered today is billed tonight without anyone clicking anything. The
**Generate** button exists for running it on demand.

## 1.3 Who does what

| Role | Level | Can do | Cannot do |
|---|---|---|---|
| **admin** | district | add/edit/delete parcels, record payments, verify, manage own district's officer and manager accounts | touch another district |
| **officer** | district | add/edit parcels, record payments, verify | manage user accounts |
| **manager** | district | read everything, reports | change any data |
| **admin** | province | manage staff province-wide, read all records | add/edit records (data entry belongs to the district) |
| **officer / manager** | province | read everything | change data or accounts |
| **super admin** | — | everything, every district | — |

Managers and province staff who sign in land on a read-only **Overview**
(*monitor.php*). Their sidebar has no data-entry screens at all, so a wrong role
is obvious immediately rather than at the first refused save.

## 1.4 Signing in

Open `https://landtax.apis.com.la/admin/` — the root URL redirects there.
Sign in with your username and password.

- Five failed officer logins are not rate-limited, but every sign-in is written
  to the **audit trail** with the username and time (Reports → Audit trail).
- If you are inactive (`is_active = false`) the login is refused; ask an
  administrator to reactivate you.
- The session lasts 24 hours. You do not need to sign in again during a working
  day.
- The back-office is **installable on a phone**: open it in Chrome on Android
  and choose "Add to Home screen". It adapts to a narrow screen, but the wide
  screens are designed for a desktop and are faster for bulk work.

---

# Part 2 — Officer back-office

Sidebar order: **Dashboard · Parcel Entry · Parcels · Obligations · Payment
Verify · Bank Import · Reports · Basic Data · Users**. Basic Data and Users
appear only for admin accounts.

---

## 2.1 Dashboard

The landing page after signing in. It answers "where do we stand today" for
your scope — your district, or the whole province if your account has no
district.

Cards show parcel counts, registered area, obligations raised this year, money
collected, and how many payments are waiting for an officer. Each card is a
link into the screen that deals with it.

Use it once each morning. Anything that is not zero and not money collected is
work waiting for you.

---

## 2.2 Parcel Entry — Manual tab

*Parcel Entry* has three tabs: **Manual** (default), **Scan**, **Import**. All
three save into the same register, so a parcel entered by hand and one read from
a scan are identical once saved.

The Manual tab is laid out like the **paper land title** (ໃບຕາດິນ), not like a
database form: one column, ministry heading, fields in the same order as the
certificate. Copy from the paper top to bottom.

**Required fields** (marked green) — the system cannot bill a parcel without
them:

| Field | Note |
|---|---|
| Title no | the number printed on the title |
| Province / District / Village | the land's location, three cascading dropdowns |
| Issued To | the owner's name as printed |
| Map sheet (ເລກແຜນທີ່) | e.g. `A250-038` — needed or citizens cannot find the parcel |
| Parcel No (ເລກຕອນດິນ) | e.g. `611` |
| Land Area | in m2 |

**Optional details** — title type, book/volume/page, land use, issue date,
owners' national ID, phone, address, spouse details.

**Two save buttons**, and the difference matters:

- **Save as Draft (ບັນທຶກເປັນສະບັບຮ່າງ)** — status `draft`. A draft is **not
  billed**, does not appear to citizens, and is not counted in reports as
  usable land. Use it when the record is incomplete.
- **Save and Confirm (ບັນທຶກ ແລະ ຢືນຢັນ)** — status `confirmed`. A confirmed
  parcel is billed from the next nightly run.

**The valuation zone and road access sit in a separate coloured block at the
bottom**, away from the certificate fields. They are not printed on the paper
title — they are what the office decides for tax purposes. Both are usually
filled automatically: choosing the village sets the zone, because each village
belongs to a valuation zone (Basic Data → Zone rates). Set them by hand only
when the parcel genuinely differs from its village.

> **If a parcel gets no tax at all**, the cause is almost always here: no zone,
> or an unmapped village. The Obligations page and the counter both say
> "no rate" rather than showing zero.

---

## 2.3 Parcel Entry — Scan tab

This is the **only** way a scanned title becomes a parcel. (There is no separate
"Scan Review" menu any more; `scans.php` redirects here.)

1. **Upload** a photo or scan of the title (jpg or png). Anything under 500 px
   on the long edge is rejected — too small to read.
2. The system runs OCR (Tesseract, Lao + English) and shows **every field it
   read, with a confidence score per field**. Faint, blurred or heavily
   compressed photos come back as noise; this is expected, not a fault.
3. **Review each field and correct it.** Handwritten fields — a title number or
   date added with a pen — are never read reliably. Type them.
4. Choose the village, which sets the zone, and pick the road access.
5. **Confirm.** The parcel is created and its current-year obligation is
   assessed immediately.

Two things worth knowing:

- The OCR is **label-anchored**: it looks for the printed field names
  ("ເລກຕອນດິນ", "ເລກແຜນທີ່", …) and reads the words beside them. It does not
  rely on the fields being in a fixed position, so both certificate layouts in
  circulation work.
- **Discard** permanently deletes the scan and its image. Scanning a title and
  never confirming leaves a `scanned` parcel that owes nothing — discard it
  rather than leaving it in the list.

---

## 2.4 Parcel Entry — Import tab

For entering many parcels at once from a spreadsheet.

1. **Download the template** — this gives you the exact column headers.
2. Fill it in Excel or LibreOffice. One row per parcel.
3. **Upload the CSV** and choose whether the first row is a header.
4. The result is reported **line by line**: which rows were created, which were
   updated, and for which rows nothing could be done, with the reason.

Two rules that catch people out:

- A row is matched to an existing parcel on **parcel number + village**. Same
  parcel number in a different village is a different parcel.
- If a row's village has no valuation zone, that row is **refused with an
  error** naming the village. It is deliberately not saved with a guessed rate
  — that would bill the wrong amount. Map the village first (Basic Data →
  Zone rates).

Leave the `zone_code` column empty unless you have a reason: the village
decides it, which is how the office wants it priced.

---

## 2.5 Parcels — the registry

The searchable list of every parcel. Use it to find a parcel, check what was
entered, or edit.

**Filters:** Province, District, Village (cascading — pick the district first
and the village list narrows), Status, Parcel no, Map sheet, Title no, and
**Land Parcel Owner** (type any part of the name; it matches inside the name).

**Columns** include parcel number, owner, province/district/village, area,
valuation zone, road category, the rate applied, the resulting tax, status and
when it was last updated.

Every row opens the **parcel detail** page.

---

## 2.6 Parcel detail

Shows the parcel as stored — title details, location, area, zone, owners — plus
the actions:

| Action | What it does | Who |
|---|---|---|
| **Edit parcel** | reopens Parcel Entry with every field filled in | admin, officer |
| **Recompute** | recalculates the current-year obligation at today's rate | admin, officer |
| **Unlink citizen account** | detaches a citizen login from the owner record | admin, officer |
| **Delete parcel** | removes the parcel permanently | **admin only**, and only when it has no payment history |

**Recompute** is the right tool after correcting an area or a zone on a parcel
that is already billed. It changes the *current* year only; earlier years keep
the amount they were assessed at, which is what an audit expects.

**Delete** is refused when money has ever been recorded against the parcel.
That guard exists on purpose — a paid parcel must never disappear. If a parcel
really should not exist, waive the obligation instead (see 2.10), or archive
it.

**Unlink citizen account** is used when a citizen claimed a parcel they should
not have. It is written to the audit trail.

---

## 2.7 Obligations — what everyone owes

One row per **parcel per tax year**: the assessment that says "parcel X owes
N kip for year Y". This is the debt book.

**Filters:** Tax year, Province, District, Status, and a **View** switch —
**All** or **Arrears only** (unpaid and past the due date).

**Summary above the table:** Count, Total due, Total penalty, Total paid,
Outstanding, and Overdue (unpaid & past due).

**Columns:** Year, Parcel, District, Village, Rate, Area, Due, Paid, Penalty,
Status, Due date, Overdue days.

### Generate obligations

The panel on the right. Pick the **Tax year**, optionally narrow by province or
district, and press **Generate**.

- It creates the missing obligations only. Parcels that already have one for
  that year are skipped, so pressing it twice is safe.
- It bills **confirmed and active** parcels. `draft`, `scanned` and `archived`
  parcels owe nothing — this is by design, not a bug.
- The same job runs automatically at **02:00 every night** for every open tax
  year, which is why most days there is nothing to generate.

> **Do not create a fee year for next year and leave it open.** The nightly job
> has no date limit: an open future year starts billing the whole province
> immediately. Open the year when you intend to bill it.

### Adjust penalty

Each row can have its late-payment surcharge overridden — because the office
waived it, or because the automatic 10% is wrong for that case. The reason you
type is stored with your username and the before/after figures, and is
reviewable in Reports → Audit trail.

---

## 2.8 Payment Verify — Office counter tab (default)

This is the daily screen. It handles **every payment where the money is
physically at the office**: cash, cheque, and a citizen who paid at a bank
counter or by mobile money and walked in with the paper slip.

The form is **two steps**, so the officer always knows the debt before taking
money.

### Step 1 — Verify obligation (ກວດສອບພັນທະ)

1. **Parcel no** — the number from the land title.
2. **Tax year** — defaults to this year; every year back to 2016 is offered
   because a citizen may be clearing arrears.
3. **Method** — cash, cheque, bank transfer, or mobile money.
4. Press **Verify obligation**.

The result panel says one of four things:

| Result | Meaning |
|---|---|
| **Assessed** — due / paid / balance | there is an obligation; step 2 opens |
| **Not yet issued** — settles at X kip (zone Z, rate) | no obligation yet; it will be created at that rate |
| **Already settled** (green, "nothing to collect") | nothing to do — do not take money |
| **No zone rate** (red) | the parcel has no valuation zone; fix it in Parcel Entry |

### Step 2 — Record & settle (ບັນທຶກ ແລະ ປິດພັນທະ)

Appears only when step 1 found something collectable:

5. **Owner (payer)** — the parcel's owners are listed; pick who actually paid
   (a relative or a third party may pay).
6. **Amount received** — pre-filled with the balance. Leave it for the full
   amount, or type a partial amount. The system will not accept more than the
   outstanding balance.
7. **Cheque no** — appears only for cheque.
8. **Bank ref** — appears for bank/mobile; it is the reference on the slip and
   is used later by statement matching.
9. **Evidence photo** (optional) — the label follows the method: receipt photo,
   photo of the cheque, photo of the bank slip, or photo of the mobile-money
   confirmation. jpg / png / webp / pdf.
10. **Note** (optional).
11. Press **Record & settle**.

Outcomes:

- **cash / cheque** — created and settled in one step. The payment is PAID, the
  obligation closes, and a **Print receipt** button appears. Hand it to the
  citizen; that is their proof.
- **bank / mobile** — created as ISSUED, the evidence is attached (which moves
  it to SUBMITTED), then verified so the obligation closes. If that last step
  fails, the payment waits in the Mobile app tab instead of being lost.

Changing the parcel number or tax year after verifying hides step 2 and clears
the result — verify again before recording. This is a guard, not a bug: it stops
one parcel's payment being recorded against another.

### Received

Below the form: the log of every in-office receipt, **newest first by receipt
date and time**, across all four methods. Each row has a **Receipt** button for
reprinting, and a running total of the rows shown. This is the working history
for reprints; the All payments tab and Reports cover deeper history.

---

## 2.9 Payment Verify — Mobile app tab

This queue is for payments **our own citizen app issued**: the citizen issued a
slip, paid it with their own banking app, and uploaded the receipt. It is also
where a bank statement auto-match lands.

A walk-in bank payment does **not** belong here — that is the Counter tab.

**Summary cards:** To review, Awaiting proof, Matched automatically today.
A **Tax year** filter narrows the queue; the list is paginated 50 at a time,
oldest first.

**Awaiting proof (ລໍຖ້າຫຼັກຖານ)** — a red box listing issued payments with
**no receipt photo and no bank reference**. The citizen claims to have paid but
nothing arrived: contact them.

**Each payment is a card** showing the reference (`LFE-2026-000123`), status
badge, parcel and location, area, amount, method, payer, bank reference, and a
thumbnail of the slip photo (click to enlarge). Buttons on the right:

| Button | Use |
|---|---|
| **Upload slip (ຂຶ້ນຮູບສະລິບ)** | shown only when no photo is filed — the citizen brought a paper slip; photograph it and it is attached immediately |
| **Verify (ກວດສອບ)** | opens a dialog: confirm the amount (editable, for partial or over), optional note → payment becomes PAID and the obligation closes |
| **Reject (ປະຕິເສດ)** | opens a dialog: enter the reason (e.g. "amount does not match the slip") → payment becomes REJECTED, nothing settles |

Both dialogs close with Cancel or Escape, and the page never reloads — so a
batch of twenty verifications is twenty clicks, not twenty page loads.

**Matched automatically today** — payments the bank statement import settled by
itself. The table is there so you can see what happened without being asked to
do anything.

> `SUBMITTED` means **proof received, not paid**. The obligation is still open
> until an officer verifies. A citizen who has uploaded a receipt has not
> settled anything yet.

---

## 2.10 Payment Verify — All payments tab

The full ledger across every channel: useful for history, for reprinting an old
receipt, and for waiving an obligation.

- **Status cards** across the top for Issued / Submitted / Paid / Rejected.
- **Filters:** Status, Method, Tax year.
- **Each payment** shows reference, parcel, year, location, area, amount,
  method, payer, obligation status, how it was verified, bank reference and the
  slip photo.
- **Print receipt** on any Paid payment; **Print slip** on an Issued one.
- **Waive (ຍົກເວັ້ນ)** on Paid payments — this marks the **obligation** as
  waived, not the payment. It is for an exemption the office has decided. Every
  waive is audited; use it deliberately.

---

## 2.11 Printing slips and receipts

| Document | When | Lao |
|---|---|---|
| **Order slip** | when the payment is issued — the citizen takes it to the bank | ພິມໃບຊໍາລະ |
| **Receipt** | after the money is verified | ພິມໃບຮັບເງິນ |

The slip carries the reference (`LFE-{year}-{6 digits}`) and a QR payload, and
says "please pay by {due date} and send proof". The receipt carries the PAID
mark, the paid date, amount, method and the receiving officer.

Both print **through your session**. Pasting the address into a private browser
window without being signed in is refused — that is deliberate.

Set the browser to A4 with default margins; the page is laid out for A4.

---

## 2.12 Bank Import

For reconciling the province's bank statement against the system.

1. **Upload statement (CSV)** — choose the file. Leave the column numbers blank
   and the system detects Date / Amount / Reference / Bank Ref from the header
   row; fill them in only if the guess is wrong.
2. **Import & match.** Each statement line is matched to a payment by its
   `LFE-…` reference:
   - exact or over amount → **settles automatically**
   - partial, duplicate, or unknown reference → **stays for you**
3. **Transactions** for an import lists every line with its status. Rows that
   need attention offer **Apply** (attach it to the payment you choose) or
   **Ignore** (leave it out).
4. **Re-match** re-runs the matching on an existing import — useful after you
   fix a payment.

After importing, open Reports → **Bank reconciliation** to see whether the
statements agree with the ledger.

---

## 2.13 Reports

Grouped by what they are for. Every report can be **downloaded as CSV** — that
exports exactly the rows under the current filters, which makes it the file you
send to finance or the ministry.

| Group | Report | Answers |
|---|---|---|
| Money | **Collection** | how much came in, when, and from where |
| Money | **Arrears & aging** | who still owes, how much, how long overdue |
| Money | **MOF export** | the statutory assessed / collected / outstanding figures |
| Registry | **Data entry progress** | how far parcel and scan data entry has got |
| Registry | **Parcel & owner register** | the land register, line by line, with owners |
| Controls | **Bank reconciliation** | do statements agree with settled payments |
| Controls | **Rate schedule** | the zone × road rates in force |
| Controls | **Audit trail** | who changed what, and when |

Things worth knowing before you use them:

- **Your scope is enforced by the server.** A district officer sees their
  district whatever the address bar says; province staff see the province.
  This is not a filter you can switch off.
- **Collection is cash-basis**: grouped by the date the money arrived, not by
  tax year. Use the date range to close a period, then compare the total with
  the bank deposit for the same days.
- **Arrears** shows only open debt, sorted most overdue first. Penalty is the
  10% surcharge; while an obligation is unpaid it is recalculated live, and it
  freezes once settled. Use "Years behind" to find parcels that have skipped
  several years.
- **MOF export** is the submission: pick the fee year, leave district open for
  the province-wide figure or filter per district.
- **Audit trail** is how you answer "who changed this?". Search the officer's
  username and the date range, then read the Detail column — before/after
  values are stored there. It returns at most 1000 rows, so narrow by actor and
  dates rather than expecting to page through everything.

Detailed reference for each report: `docs/16-reports.md`.

---

## 2.14 Basic Data (administration)

Everything the rest of the system depends on. Tabs across the top:

| Tab | What it maintains | Warning |
|---|---|---|
| **Province** | the province record | single-province for now |
| **Districts** | the 15 districts and each one's **zone letter** | the letter prefixes every zone code (A–O) |
| **Villages** | villages and which district they belong to | 8,000+ nationally; filter by district before editing |
| **Zone rates (fee)** | the rate per m2 per valuation zone, per road access | **this is the tax tariff** |
| **Fee years** | the billing year and its due date | an open future year starts billing immediately |
| **Lookups** | road categories, title types, land-use classes | codes are fixed where parcels reference them |

Two habits that prevent most support calls:

- **Never delete a row that is still in use.** The screen refuses and tells you
  how many records reference it — read that number, it tells you what to fix
  first.
- **Zone rates are history-tracked.** Editing a rate adds a new dated row; the
  newest row on or before today is the one applied. Earlier obligations keep
  the rate they were assessed at, which is why old years do not change.

**Village → zone mapping** is the single most valuable column here. When a
village is mapped to a zone, every new parcel in it is priced correctly with no
decision by the officer. Unmapped villages are the usual reason a parcel quotes
"no rate".

---

## 2.15 Users (administration)

Admin only. Officers cannot open this page.

- **Create staff** — username, password, role, level (district or province),
  district.
- **Edit staff** — change role, level, district or username.
- **Reset password** — set a new one.
- **Activate / deactivate** — a deactivated account cannot sign in.
- **Link a citizen to an owner record** — this is the one that matters most for
  the app. A citizen only sees their land after an owner record is linked to
  their account. They can link it themselves by claiming the parcel in the app
  (3.6), or an officer can do it here by searching owner name, national ID,
  phone **or parcel number**.

Two guards are built in: you cannot deactivate your own account, and the last
remaining admin cannot be demoted or disabled.

---

## 2.16 Manager overview

Manager accounts land on a read-only page answering: how much has been
collected, what is still owed, which receipts are waiting for an officer, and
how much land the office covers. Every figure is scoped to the manager's
district, or the whole province for a province manager.

Managers are redirected away from data-entry screens, so there is no risk of a
manager half-entering a parcel.

---

# Part 3 — Citizen mobile app

App name on the phone: **ຊໍາລະຄ່າທີ່ດິນ** (Land Tax Payment).

The app is a viewer and payer, never a calculator. **Every amount shown comes
from the office's own assessment.** If a rate changes, the app is right the next
time it loads — nothing to update.

---

## 3.1 Installing and signing in

The office installs the app on the phone, or sends the file to the citizen.

**Sign in (ເຂົ້າສູ່ລະບົບ)** takes only a **mobile phone number** (ເບີໂທ).

- Type the number and press sign in. **Nothing is sent anywhere** — there is no
  code to wait for and no password to remember.
- The **first** time a number is used, the account is created. Signing up and
  signing in are the same action.
- The number may be typed in any of the usual forms (`020 5555 1234`,
  `+856 20 5555 1234`, `02055551234`) — they are all understood as the same
  number.
- The session lasts 24 hours; after that, sign in again.

> **What this means, and why the province accepted it:** anyone who knows a
> citizen's number can sign in as them and see their land record. This was a
> deliberate choice — almost everyone has a phone and few use email. What
> protects the citizen is that **paying does not depend on the app**: the money
> only moves when the citizen actually pays at a bank, and an officer verifies
> the receipt. Signing in cannot move money.
>
> Repeated sign-in attempts from one number or one network are now rate-limited
> (10 per number per 10 minutes) to stop bulk account creation.

---

## 3.2 Finding a parcel

Tap **Find parcel (ຄົ້ນຫາຕອນດິນ)**. Five values are needed, all of them printed
on the land title:

1. **Province (ແຂວງ)** — Savannakhet
2. **District (ເມືອງ)**
3. **Village (ບ້ານ)**
4. **Map sheet (ເລກແຜນທີ່)** — e.g. `A250-038`
5. **Parcel number (ເລກຕອນດິນ)** — e.g. `611`

All five are required. A parcel number on its own is not enough, because the
same number can exist in another village, and a map sheet repeats across
villages. If any one is missing the app says
*ກະລຸນາເລືອກແຂວງ, ເມືອງ, ບ້ານ ແລະ ປ້ອນເລກແຜນທີ່ ແລະ ເລກຕອນດິນ*.

**Looking a parcel up does not add it to the account.** That is the next step,
and it is optional.

If the parcel is not found: check the map sheet and parcel number against the
paper title character by character. That is the usual cause, and it is what the
app's message tells the citizen:

> *ບໍ່ພົບຕອນດິນ — ກະລຸນາກວດເບິ່ງ ເລກແຜນທີ່ ແລະ ເລກຕອນດິນ ໃຫ້ກົງກັບໃບຕາດິນ*

If the app says the number matches **more than one parcel**
(*ຕອນດິນເລກນີ້ ມີຫຼາຍກວ່າຫນຶ່ງຕອນ*), the register holds two parcels with the
same identity — an officer must correct the register or merge them.

---

## 3.3 Reading the assessment

After a successful lookup the parcel screen shows:

| Shown | Meaning |
|---|---|
| Owner (ເຈົ້າຂອງທີ່ດິນ), title no | confirms it is the right parcel |
| Area (ເນື້ອທີ່) | the registered area |
| Zone (ເຂດ) | the valuation zone — the basis of the rate |
| **Amount due (ຄ່າທີ່ຕ້ອງຊໍາລະ)** | the tax for the year |
| **Penalty (ຄ່າປັບ)** | 10% surcharge, only once the due date has passed |
| **Outstanding (ຄ້າງຊໍາລະ)** | what is actually still to pay |
| Due date (ກໍານົດຊໍາລະ) | pay before this to avoid the surcharge |
| Status (ສະຖານະ) | ຍັງບໍ່ໄດ້ຊໍາລະ / ຊໍາລະບາງສ່ວນ / ຊໍາລະແລ້ວ / ຍົກເວັ້ນ |

If several years are unpaid, the app lists them. **Outstanding** is the total.

If it shows no amount at all, the parcel has no valuation zone in the register
— tell the district office; the citizen cannot fix this from the app.

---

## 3.4 Paying

The flow follows how money actually moves here — the system never takes a card
or connects to a bank.

```
 1. Tap Pay (ຊໍາລະເງິນ)
 2. Choose full (ຊໍາລະເຕັມ) or partial (ຊໍາລະບາງສ່ວນ)
 3. Choose method: bank transfer / mobile banking / at the office
 4. A slip is issued — reference LFE-2026-000123 + QR
 5. Pay that reference with the bank's own app, or at the counter
 6. Photograph the receipt and upload it (ອັບໂຫລດສະລິບເງິນ)
 7. Wait for the district to verify
```

**Step 6 is the one citizens forget.** Issuing a slip is not paying. Until the
receipt photo is uploaded, the office has no evidence and the payment shows as
awaiting proof.

Photo tips that avoid a rejection: photograph the whole receipt on a flat
surface, in good light, with the reference number legible. The app offers
**Take photo (ຖ່າຍຮູບ)** or **Choose from gallery (ເລືອກຈາກຄັງຮູບ)**.

If the camera will not open, the phone has not granted permission:
*ບໍ່ສາມາດເປີດກ້ອງ ຫຼື ຮູບພາບໄດ້. ກະລຸນາອະນຸຍາດໃຫ້ແອັບເຂົ້າເຖິງກ້ອງ ແລ້ວລອງໃຫມ່.*

---

## 3.5 Tracking the payment

**My payments (ການຊໍາລະຂອງຂ້ອຍ)** lists every payment with its status:

| Status | Lao | Meaning | Is it done? |
|---|---|---|---|
| Issued | ລໍຖ້າຊໍາລະ | slip issued, nothing received | no — pay, then upload the receipt |
| Submitted | ສົ່ງຫຼັກຖານແລ້ວ | receipt uploaded, waiting for an officer | no — the office must verify |
| Paid | ຊໍາລະແລ້ວ | verified by an officer | **yes** |
| Rejected | ຖືກປະຕິເສດ | the office could not accept it | no — read the reason, pay again |

**Submitted is not paid.** A citizen who has uploaded a photo has proved
nothing yet; an officer still has to check it against the bank. If a payment is
rejected the reason is shown, and the citizen issues a new slip.

---

## 3.6 My parcels — claiming a parcel

**My parcels (ຕອນດິນຂອງຂ້ອຍ)** is the shortlist of parcels the citizen keeps.
A parcel appears there only when it has been **claimed**, either:

- by the citizen: look the parcel up, then tap
  **Add to my parcels (ເພີ່ມໃສ່ຕອນດິນຂອງຂ້ອຍ)**, or
- by an officer, in Back-office → Users (2.15).

Claiming is guarded by the **phone number recorded on the owner record**. If the
numbers do not match, the claim is refused:

> *ເບີໂທທີ່ບັນທຶກໄວ້ໃນຕອນດິນນີ້ ບໍ່ກົງກັບເບີໂທຂອງທ່ານ — ກະລຸນາຕິດຕໍ່ຫ້ອງການທີ່ດິນເມືອງ.*

Other refusals and what they mean:

| Message | What to do |
|---|---|
| *…ເຊື່ອມກັບບັນຊີອື່ນແລ້ວ…* — already linked to another account | office must unlink it first |
| *…ຍັງບໍ່ມີຊື່ເຈົ້າຂອງໃນລະບົບ…* — no owner recorded | office must record the owner |

**Claiming is not required to pay.** Anyone who knows a parcel's identity can
look it up and pay the tax on it — that is deliberate, because a relative or a
buyer often pays. Claiming only decides what appears in *My parcels*.

---

## 3.7 Notes for officers supporting the app

- **The app cannot change data.** An officer fixes a wrong area, zone or owner
  in the back-office; the citizen sees the correction next time the parcel
  loads.
- **"No amount" on a parcel** is a data-entry gap (no valuation zone), not an
  app fault.
- **"Parcel not found"** is almost always a mistyped map sheet or parcel
  number. Compare with the paper title before investigating.
- **A payment stuck on Submitted** means the receipt is waiting in
  Payment Verify → Mobile app. Verify it and the citizen's screen updates.
- **A payment stuck on Issued** with no receipt after several days appears in
  the **Awaiting proof** box — the citizen says they paid but no evidence
  arrived. Contact them.

---

# Part 4 — Reference

## 4.1 Statuses

**Obligation** (the debt):

| Status | Meaning |
|---|---|
| `UNPAID` | assessed, nothing received |
| `PARTIAL` | some money received, balance outstanding |
| `PAID` | settled in full |
| `OVERPAID` | more received than owed |
| `WAIVED` | exempted by the office |
| `DISPUTED` | under dispute |
| `REFUNDED` | money returned |

**Payment** (the money):

`ISSUED` → `SUBMITTED` → `PAID` or `REJECTED`, plus `REFUNDED`.

Only `PAID` settles an obligation. Partial payments produce `PARTIAL`; the
status is computed from the sum of paid payments, never typed by hand.

## 4.2 Fee and surcharge

```
annual tax  = zone rate (kip/m2) × area (m2)
surcharge   = 10% of the unpaid amount, once the due date has passed
outstanding = amount due + surcharge − amount already paid
```

- The 10% follows decree 385/ປປ (2009), article 10.
- While an obligation is unpaid the surcharge is recalculated every time it is
  read; once settled it is frozen at that figure.
- An officer can override it on the Obligations page (Adjust penalty) with a
  reason, which is audited.

## 4.3 Where the numbers come from

| Number | Source | Changed by |
|---|---|---|
| Area (m2) | the land title | correcting the parcel |
| Valuation zone | the village, or set on the parcel | Basic Data → Zone rates, or the parcel |
| Road access | the parcel | correcting the parcel |
| Rate (kip/m2) | Basic Data → Zone rates | the province's tariff |
| Due date | the fee year | Basic Data → Fee years |

A rate change never edits a parcel and never rewrites history: existing
obligations keep the amount they were assessed at, and new assessments use the
new rate.

## 4.4 Lao label glossary

| English | Lao |
|---|---|
| Parcel / parcel no | ຕອນດິນ / ເລກຕອນດິນ |
| Map sheet | ເລກແຜນທີ່ |
| Land title | ໃບຕາດິນ |
| Province / district / village | ແຂວງ / ເມືອງ / ບ້ານ |
| Area | ເນື້ອທີ່ |
| Valuation zone | ເຂດປະເມີນລາຄາທີ່ດິນ |
| Rate per m2 | ອັດຕາພາສີຕາມເຂດ |
| Land tax | ພາສີທີ່ດິນ |
| Obligation (debt) | ພັນທະ |
| Tax year | ປີພັນທະ |
| Amount due / paid | ຄ່າທີ່ຕ້ອງຊໍາລະ / ຊໍາລະແລ້ວ |
| Outstanding | ຄ້າງຊໍາລະ |
| Late-payment surcharge | ຄ່າປັບ |
| Due date | ກໍານົດຊໍາລະ |
| Receipt / slip | ໃບຮັບເງິນ / ໃບຊໍາລະ |
| Owner | ເຈົ້າຂອງທີ່ດິນ |
| Waive | ຍົກເວັ້ນ |
| Road: main / branch / alley / none | ທາງຫຼັກ / ທາງສາຍແຍກ / ທາງສາຍແຍກ / ບໍ່ມີທາງ |

## 4.5 Common problems

| Symptom | Usual cause | Fix |
|---|---|---|
| A parcel owes 0 or no obligation appears | parcel is `draft`/`scanned`, or has no valuation zone | confirm the parcel; assign a zone |
| "No zone rate" at the counter | parcel or village has no zone | Parcel Entry, or Basic Data → Zone rates |
| A rate change did not change an old bill | by design — history is preserved | Recompute the current year on the parcel |
| A whole district has no bills | no open fee year | Basic Data → Fee years |
| The nightly billing did nothing | no open year with a due date in the future | check Fee years |
| Citizen sees no parcels | their account is linked to no owner record | claim in the app, or link in Users |
| Citizen cannot claim | phone on the owner record differs | correct the owner's phone, or link in Users |
| A report total differs from last month | Collection is cash-basis | use the same date range both times |
| A receipt will not print | you are not signed in in that tab | print from a signed-in tab |

## 4.6 Where to read more

| Document | Covers |
|---|---|
| `docs/14-payment-verify.md` | the payment screen in depth |
| `docs/16-reports.md` | every report, column by column |
| `docs/11-zone-setup.md` | how zones and rates are structured |
| `docs/15-landtax-reg.md` | the land-tax decree and the rate table |
| `docs/9-citizen-app-api-contract.md` | the app's API contract |
| `AGENTS.md` | technical notes for maintainers |

---

*End of handbook. Keep this file with the system documentation and revise it in
the same change whenever a screen, a field or a status changes.*