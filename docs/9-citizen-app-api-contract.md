# Citizen App — API Contract

The citizen Flutter app (`mobile/`) talks to four citizen endpoints, all of
which are implemented in `api/v1/`:

| Endpoint | Purpose |
|----------|---------|
| `citizen_auth.php` | Sign in by phone number (first use creates the account). |
| `parcel_fee.php` | Price one parcel the citizen names by its full registry identity. |
| `citizen_claim.php` | Explicitly add a looked-up parcel to "my parcels". |
| `citizen_payments.php` | Issue a slip, upload the receipt, list the citizen's payments. |

This document is the contract: exact requests, exact responses, and the schema
changes needed. The endpoints are **new files** written so they do not touch
`api/v1/auth.php`, `api/v1/payments.php` or any other file that already has an
owner.

The design decisions behind them are in
[`8-citizen-flutter-app-plan.md`](8-citizen-flutter-app-plan.md).

---

## Why new endpoints

| Existing file | Why not reuse it |
|---------------|------------------|
| `api/v1/auth.php` | Citizen email-OTP sign-in and the officer password login. The app signs in by **phone number only**, and this file has another owner. |
| `api/v1/payments.php` | The officer queue and the web portal's endpoint. The app needs a citizen-scoped variant that also records **who made the payment**. |
| `api/v1/fee_obligations.php` | The officer's obligation list. The app needs to price **one parcel** that a citizen names. |
| `api/v1/parcels.php` | The citizen branch is fine, but the app wants the *price* with the parcel, in one call. |

---

## 0. Schema changes

```sql
-- sql/migrations/009_citizen_payments.sql
ALTER TABLE payments ADD COLUMN IF NOT EXISTS citizen_user_id INT
  REFERENCES users(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_payments_citizen ON payments(citizen_user_id);
```

The payment status path is **`ISSUED` → `SUBMITTED` → `PAID` / `REJECTED`**.
`SUBMITTED` (migration `015_payment_submitted_status.sql`) means the citizen
has uploaded the receipt and the payment is waiting for an officer: something
has been claimed, but nothing has been verified. It is not a settlement — the
obligation only settles when a payment becomes `PAID`.

**Why:** a citizen may pay for a parcel that is not linked to their account —
they know the parcel number from their land title. The payment has to be
attributed to the citizen who made it, or it never appears in their own
history. `payments.payer_owner_id` cannot do this: it points at `owners.id`,
not `users.id`.

Claiming needs no new table: `owners.citizen_user_id` already links an owner to
a citizen, and `my_parcels.php` filters on it (§3).

**Backfill** (so existing citizens keep their history):

```sql
UPDATE payments pay
   SET citizen_user_id = o.citizen_user_id
  FROM owners o
 WHERE pay.citizen_user_id IS NULL
   AND pay.payer_owner_id = o.id
   AND o.citizen_user_id IS NOT NULL;
```

---

## 1. `api/v1/citizen_auth.php` — sign in by phone number

The phone number is the identifier: nearly everyone has a mobile phone, and
most do not use email. **Nothing is sent anywhere and no code is typed** — the
citizen enters their number, and the first time it is used the account is
created. Sign-up and sign-in are the same call.

### `POST citizen_auth.php`

```json
{ "action": "phone/signin", "phone": "020 555 1234" }
```

| Field | Notes |
|-------|-------|
| `phone` | Any Lao format: `020 555 1234`, `0205551234`, `+856 20 555 1234`. Normalise to digits + country code, no leading zero: `856205551234`. |

**Behaviour**

- No user with that phone → create one: `role='citizen'`, `phone=<normalised>`,
  `email=NULL`. No password, no PIN.
- A user exists → issue a token for it.
- `users.email` stays NULL. The unique index on it is partial
  (`WHERE email IS NOT NULL`), so any number of NULLs is fine.
- `users.phone` is already `UNIQUE NOT NULL`, so no schema change is needed.

**Response**

```json
{ "ok": true, "data": {
  "token": "<jwt>",
  "user": { "id": 12, "role": "citizen" },
  "phone": "020 555 1234",
  "created": true
}}
```

`created` is `true` on the first sign-up, `false` afterwards.

**Errors**

| Code | HTTP | When |
|------|------|------|
| `invalid_phone` | 400 | The number is not a usable Lao number (not 10 digits, or not a 020/030/080 prefix). |
| `inactive` | 403 | The account is deactivated. |

**Phone normalisation** (the part that is easy to get wrong):

```
"020 555 1234"      -> digits 0205551234    -> drop leading 0 -> 205551234 (9) -> 856205551234
"+856 20 555 1234"  -> digits 856205551234  -> drop 856      -> 205551234 (9) -> 856205551234
"0205551234"        -> 856205551234
```

**Two consequences of phone-only sign-in** (accepted by the province, so they
are stated here rather than hidden):

1. Anyone who knows a citizen's phone number can sign in as them and see
   their land record and payment history. The endpoint should therefore be
   **rate-limited** and **logged** (the audit log already exists), so a
   guessed-number sweep is visible.
2. There is no way to tell a citizen apart from someone holding their phone.
   If that becomes a problem, a PIN can be added later without changing the
   app's screens: the endpoint would take one more field, and
   `phone/setpin` below is already specified for it.

---

## 2. `api/v1/parcel_fee.php` — price one parcel

The citizen knows the parcel number from their land title. The server reads the
area and the zone code from the parcel and looks up the rate, so the amount is
always the server's calculation — the app never computes a fee and the client
cannot influence it.

### `GET parcel_fee.php?province_id=1&district_id=1&village_id=1&map_ref_no=A250-038&parcel_no=1301.001.001.0001&fee_year=2026`

Bearer token required (`citizen`, `officer` or `admin`).

**Rules**

- One parcel per call. No listing, no filters — the land registry cannot be
  walked.
- The parcel's identity is the one printed on the title: **province + district +
  village + map sheet + parcel number**. A number (and a sheet) can repeat in
  another village, so the location is required too. Give `parcel_id` alone only
  when the app already has an id (e.g. re-opening a parcel from "my parcels" or
  right after a lookup).
- Without the location with a number → `missing_location` (400). Without the map
  sheet → `missing_map_sheet` (400). Without a number and a `title_no` →
  `missing_parcel` (400).
- **Exact** parcel number match (the sheet is compared case-insensitively,
  trimmed). A substring match would let anyone page through the registry one
  character at a time.
- If the number still matches more than one parcel → `ambiguous_parcel` (400).
- No parcel → `parcel_not_found` (404).
- `fee_year` defaults to the current year.
- Looking a parcel up **links nothing**: `claimed` only reports whether the
  parcel is already linked to the signed-in citizen (see §3).

**Response**

```json
{ "ok": true, "data": {
  "parcel": {
    "parcel_id": 4, "parcel_no": "1301.001.001.0001", "sub_parcel_no": null,
    "map_ref_no": "A250-038",
    "area_sqm": 1250.50, "zone_code": "A7", "road_category": "branch",
    "title_no": "0102/1301/00342", "title_type": "titled", "issued_date": null,
    "status": "confirmed",
    "district_name": "ນະຄອນໄກສອນພົມວິຫານ", "village_name": "ທ່າຫົວຊ້າງ"
  },
  "owners": [ { "id": 4, "full_name": "ທົວງ ທົວງ", "id_number": null,
                "role": "owner", "share_pct": "100.00" } ],
  "claimed": false,
  "fee_year": 2026,
  "rate_per_sqm": 140000,
  "rate_found": true,
  "amount_due": 175070000,
  "obligation": { "id": 5, "fee_year": 2026, "amount_due": "175070000.00",
                  "amount_paid": "0.00", "penalty": "0.00", "status": "UNPAID",
                  "due_date": "2026-06-30", "outstanding": "175070000.00" },
  "history": [ { "fee_year": 2025, "status": "PAID", "outstanding": "0.00" } ]
}}
```

`claimed` is `true` when `owners.citizen_user_id` on one of the parcel's owners
is the signed-in user. It is what the app uses to show "already in my parcels"
instead of the claim button.

`obligation` is `null` when the parcel has not been assessed for that year
yet — the amount is still returned, from the zone rate.

`obligation.penalty` is the 10% late-payment surcharge (decree 385/2009 Art. 10).
It is derived live while the obligation is `UNPAID`/`PARTIAL` and frozen once it
is settled; `obligation.outstanding` always includes it. No client change is
needed — the amount to collect is still `obligation.outstanding`.

**Where the numbers come from** (the existing helpers do this already):

```php
$zoneCode = $parcel['zone_code'];                    // from the land title
$fee = fee_compute_amount($pdo, $zoneCode, $parcel['area_sqm']);
// rate from `zones` (newest row with valid_from <= today, is_active),
// amount = rate * area
```

`fee_compute_amount()` is in `lib/fee.php` and already implements exactly this.
`fee_upsert_obligation($pdo, $parcelId, $year)` persists the same assessment
and is idempotent on (parcel_id, fee_year).

---

## 3. `api/v1/citizen_claim.php` — add a parcel to my parcels

Looking a parcel up does not link it to the account (a citizen may price and pay
for any parcel, see §4). This endpoint is the explicit "add to my parcels"
step: the app offers it after a lookup when `claimed` is `false`.

### `POST citizen_claim.php`

```json
{ "parcel_id": 4 }
```

Bearer token required (`citizen`). Only `parcel_id` is accepted — the parcel
must have been looked up first, so a claim can never be made against a number
typed straight into this endpoint.

**Behaviour**

1. Lock the parcel's owner links (`FOR UPDATE OF o`), so two sign-ins cannot
   claim at once.
2. No owner recorded → `no_owner` (409).
3. Any owner already linked to *this* citizen → success (`claimed: true`); the
   call is idempotent.
4. Any owner linked to *another* citizen → `claimed_by_other` (409).
5. Prefer an owner whose recorded phone normalises to the citizen's own number
   (`lib/phone.php`). If owners carry phones and none matches → `phone_mismatch`
   (409), so knowing the certificate alone does not take over a record.
6. If no owner has a phone recorded, link the principal owner (role `owner` with
   the largest share).
7. Link by setting `owners.citizen_user_id` — the parcel row is untouched, and
   `my_parcels.php` filters on that column. Every new link and every refusal is
   audited.

**Response** (201 on a new link, 200 when it was already linked)

```json
{ "ok": true, "data": { "parcel_id": 4, "owner_id": 4, "claimed": true } }
```

| Code | HTTP | When |
|------|------|------|
| `missing_parcel` | 400 | `parcel_id` is absent or 0. |
| `no_owner` | 409 | The parcel has no owner recorded. |
| `claimed_by_other` | 409 | An owner is already linked to a different citizen. |
| `phone_mismatch` | 409 | Every owner has a phone, and none is the citizen's. |

**Unlinking** is an officer action, not a citizen one: `public/admin/parcel.php`
offers it (`owners.php action=unlink_citizen`, audited). That is why the
`phone_mismatch` message tells the citizen to contact the district land office.

---

## 4. `api/v1/citizen_payments.php` — the citizen's own payments

### `POST citizen_payments.php` — issue a payment slip

```json
{ "parcel_id": 5, "fee_year": 2026,
  "method": "bank_transfer", "amount": 175070000 }
```

| Field | Notes |
|-------|-------|
| `parcel_id`, or `parcel_no` + `map_ref_no` | Required. The app sends the `parcel_id` from the lookup, so a number that repeats on another sheet cannot settle the wrong parcel. Sending `parcel_no` alone is refused (`missing_map_sheet`). |
| `fee_year` | Defaults to the current year. |
| `method` | `bank_transfer` \| `mobile` \| `counter` \| `cash`. |
| `amount` | Optional. Defaults to the full outstanding balance, and is capped at it (partial payments are allowed). |

**Behaviour**

1. Price the parcel and upsert the obligation (`fee_upsert_obligation`) — the
   rate comes from the zone code, never from the client.
2. Refuse if nothing is outstanding → `already_settled` (409).
3. `reference_no = slip_generate_no($pdo)` → `LFE-{year}-{seq6}`.
4. Insert the payment with `status='ISSUED'`, `citizen_user_id` = the signed-in
   citizen, and the QR payload.
5. Audit it (`fee_audit(..., 'payment_issue', ...)`).

**Anyone may pay for any parcel.** A citizen does not have to own the parcel,
and the parcel does not have to be linked to their account: they know the
parcel number from the land title, and paying the tax on it is the point. So
this endpoint must not require ownership, and must not require the parcel to
be linked. What it does need is the `citizen_user_id`, so the payment shows up
in the payer's own history.

**Response**

```json
{ "ok": true, "data": {
  "id": 4, "reference_no": "LFE-2026-000004", "amount": 88000000,
  "slip_qr": "SLIP:LFE-2026-000004|AMT:88000000.00|PARCEL:1301.001.001.0001",
  "status": "ISSUED", "due_date": "2026-06-30",
  "parcel_id": 5, "fee_year": 2026, "outstanding": 88000000
}}
```

### `GET citizen_payments.php` — the citizen's payments

Returns the payments the citizen **made** plus the ones on the parcels they
own:

```sql
WHERE pay.citizen_user_id = :me
   OR pay.parcel_id IN (SELECT po.parcel_id FROM parcel_owners po
                        JOIN owners o ON o.id = po.owner_id
                        WHERE o.citizen_user_id = :me)
```

Each row carries `reference_no, amount, status, method, fee_year, parcel_no,
map_ref_no, district_name, village_name, created_at, paid_at, slip_path,
verify_note, obligation_status`.

### `POST citizen_payments.php?id={id}&receipt=1` — upload the receipt

Multipart, field name `slip` (jpg/jpeg/png/pdf/webp). Saves to the uploads
directory, sets `payments.slip_path` **and moves the payment to
`status='SUBMITTED'`** — this is the "send it to the district" step. A payment
that is already `PAID`, `REJECTED` or `REFUNDED` is refused (`bad_state`, 409);
re-uploading while `SUBMITTED` replaces the photo. The response carries
`{id, slip_path, status:"SUBMITTED"}`.

**Authorisation matters here:** a citizen may only attach a receipt to a
payment they made, or one on a parcel they own. Check both:

```php
$owns = /* the citizen owns the parcel */;
$made = ((int)$payment['citizen_user_id'] === (int)$me['sub']);
if (!$owns && !$made) { json_error('Forbidden', 403, 'forbidden'); }
```

The "owns" part is only about *whose* payment record is being touched — it
does not restrict who may **pay**: anyone may pay for any parcel (see §4).

`api/v1/payments.php` has no check like this on its receipt branch — worth
adding there too.

---

## 5. The bank QR

The citizen is meant to scan the QR **with their banking app** (BCEL One or
any other Lao bank app; the banks are interlinked, so a payment made in one
app settles at the receiving bank). For that to work the QR has to be one the
bank understands, which needs two things the province has not supplied yet:

1. **The receiving account** — the land office's bank account, merchant id and
   bank name.
2. **The bank's QR format** — BCEL One has its own payload format; it is not
   necessarily EMVCo.

Until then the slip shows a plain reference line and the app displays the
reference in large type with a copy button, so the citizen can type it into
their banking app:

```
SLIP:LFE-2026-000004|AMT:88000000.00|PARCEL:1301.001.001.0001
```

The payload builder should be config-driven so the format can be switched
without touching the flow:

| Env | Meaning |
|-----|---------|
| `BANK_QR_FORMAT=text` (default) | the plain reference line above |
| `BANK_QR_FORMAT=emvco` | an EMVCo payload: merchant account (tag 26), amount (54), currency 360 (LAK), country LK (58), and the reference in tag 62, with a CRC-16/CCITT-FALSE checksum (tag 63) |
| `BANK_QR_FORMAT=url` | a deep link to the bank's payment page, with `ref` and `amt` |
| `BANK_QR_MERCHANT_ID` | the merchant account for the EMVCo/url formats |
| `BANK_QR_ACCOUNT`, `BANK_QR_BANK` | the account and bank name, shown on the slip |
| `BANK_QR_URL_TEMPLATE` | the deep-link template |

**The reference must be carried in the payload**, because that is what the bank
statement contains — it is how the payment is matched to the obligation
automatically (see `lib/bank_match.php`).

**One thing to fix while you are in there:** the printable A4 slip renders its
QR through `api.qrserver.com`, a third-party service that receives the payload
and will not work offline. The QR image should be generated locally.

---

## 6. What the app does with all this

```
Sign in (phone number)
   └─ Home
       ├─ "ຄົ້ນຫາຕອນດິນ"  province → district → village → map sheet → parcel no
       │                                              → parcel_fee.php → the fee
       │                                              → "add to my parcels"
       │                                                → citizen_claim.php
       │                                              → pay  → citizen_payments.php
       │                                              → slip (reference + QR)
       │                                              → photograph the bank slip
       │                                              → citizen_payments.php?receipt=1
       │                                                → SUBMITTED (with the district)
       ├─ "ຕອນດິນຂອງຂ້ອຍ"    the parcels the citizen has claimed
       └─ "ການຊຳລະຂອງຂ້ອຍ"  the payments made, and their status
```

The app never computes a fee: every amount comes from the server.
