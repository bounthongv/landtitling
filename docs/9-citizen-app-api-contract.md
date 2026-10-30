# Citizen App — API Contract (for the backend owner)

The citizen Flutter app (`mobile/`) needs three endpoints that do not exist
yet. This document is the contract: exact requests, exact responses, and the
one schema change needed. It is written so it can be implemented without
touching `api/v1/auth.php`, `api/v1/payments.php` or any other file that
already has an owner — the three endpoints below are **new files**.

The design decisions behind them are in
[`8-citizen-flutter-app-plan.md`](8-citizen-flutter-app-plan.md).

---

## Why new endpoints

| Existing file | Why not reuse it |
|---------------|------------------|
| `api/v1/auth.php` | Citizen email-OTP sign-in and the officer password login. The app signs in by **phone + PIN** now, and this file has another owner. |
| `api/v1/payments.php` | The officer queue and the web portal's endpoint. The app needs a citizen-scoped variant that also records **who made the payment**. |
| `api/v1/fee_obligations.php` | The officer's obligation list. The app needs to price **one parcel** that a citizen names. |
| `api/v1/parcels.php` | The citizen branch is fine, but the app wants the *price* with the parcel, in one call. |

---

## 0. Schema change (one column)

```sql
-- sql/migrations/004_citizen_payments.sql
ALTER TABLE payments ADD COLUMN IF NOT EXISTS citizen_user_id INT
  REFERENCES users(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_payments_citizen ON payments(citizen_user_id);
```

**Why:** a citizen may pay for a parcel that is not linked to their account —
they know the parcel number from their land title. The payment has to be
attributed to the citizen who made it, or it never appears in their own
history. `payments.payer_owner_id` cannot do this: it points at `owners.id`,
not `users.id`.

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

### `GET parcel_fee.php?parcel_no=1301.001.001.0001&fee_year=2026`

Bearer token required (`citizen`, `officer` or `admin`).

**Rules**

- One parcel per call. No listing, no filters — the land registry cannot be
  walked.
- **Exact** parcel number match. A substring match would let anyone page
  through the registry one character at a time.
- If the number matches more than one parcel → `ambiguous_parcel` (400).
- No parcel → `parcel_not_found` (404).
- `fee_year` defaults to the current year.

**Response**

```json
{ "ok": true, "data": {
  "parcel": {
    "parcel_id": 4, "parcel_no": "1301.001.001.0001", "sub_parcel_no": null,
    "area_sqm": 1250.50, "zone_code": "A7", "title_no": "0102/1301/00342",
    "status": "confirmed",
    "district_name": "ນະຄອນໄກສອນພົມວິຫານ", "village_name": "ທ່າຫົວຊ້າງ"
  },
  "owners": [ { "id": 4, "full_name": "ທົວງ ທົວງ", "id_number": null,
                "role": "owner", "share_pct": "100.00" } ],
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

## 3. `api/v1/citizen_payments.php` — the citizen's own payments

### `POST citizen_payments.php` — issue a payment slip

```json
{ "parcel_no": "1301.001.001.0001", "fee_year": 2026,
  "method": "bank_transfer", "amount": 175070000 }
```

| Field | Notes |
|-------|-------|
| `parcel_no` or `parcel_id` | Required. Exact match on the number. |
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
district_name, village_name, created_at, paid_at, slip_path, verify_note,
obligation_status`.

### `POST citizen_payments.php?id={id}&receipt=1` — upload the receipt

Multipart, field name `slip` (jpg/jpeg/png/pdf/webp). Saves to the uploads
directory and sets `payments.slip_path`.

**Authorisation matters here:** a citizen may only attach a receipt to a
payment they made, or one on a parcel they own. Check both:

```php
$owns = /* the citizen owns the parcel */;
$made = ((int)$payment['citizen_user_id'] === (int)$me['sub']);
if (!$owns && !$made) { json_error('Forbidden', 403, 'forbidden'); }
```

The "owns" part is only about *whose* payment record is being touched — it
does not restrict who may **pay**: anyone may pay for any parcel (see §3).

`api/v1/payments.php` has no check like this on its receipt branch — worth
adding there too.

---

## 4. The bank QR

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

## 5. What the app does with all this

```
Sign in (phone + PIN)
   └─ Home
       ├─ "ຄົ້ນຫາຕອນດິນ"  enter the parcel number  → parcel_fee.php  → the fee
       │                                              → pay  → citizen_payments.php
       │                                              → slip (reference + QR)
       │                                              → photograph the bank slip
       │                                              → citizen_payments.php?receipt=1
       ├─ "ຕອນດິນຂອງຂ້ອຍ"    the parcels an officer has linked to the account
       └─ "ການຊຳລະຂອງຂ້ອຍ"  the payments made, and their status
```

The app never computes a fee: every amount comes from the server.
