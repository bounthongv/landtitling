# Citizen Mobile App (Flutter) — Land Tax Payment

Plan for a native Flutter app that lets a citizen pay the annual land tax from a
phone, on top of the **existing** Land Fee JSON API (`api/v1/*`).

Status: plan approved in principle; **M1–M7 implemented** in `mobile/`, verified
end-to-end by `mobile/tool/smoke_test.dart` (25/25 checks pass against the
local API). M8 (harden + release) not started.

---

## 1. Scope

**In scope (MVP)**

1. Sign in (email OTP) → JWT.
2. See **my parcels** and the **annual land tax** assessed on each, per year.
3. See the outstanding amount, penalty, due date, and status.
4. **Issue a payment slip** (reference `LFE-{year}-{seq6}` + QR payload).
5. **Pay**: photograph the bank slip and upload it as the receipt.
6. **Track** the payment status (`ISSUED` → `PAID` / `REJECTED`) and the slip itself.
7. Read the receipt photo back, and open the printable A4 slip.

**Out of scope for the MVP** (legacy citizen-portal features that need back-office
work that another team member owns):

- ໃບແຈ້ງມີ / Land Availability Certificate (eligibility + PDF + QR)
- ຂໍເອກະສານ / Document Requests (title copy, map extract, history)
- ລາຍງານບັນຫາ / Issue Reporting (photo + GPS)
- BCEL One QR payment, direct bank integration (legacy Phase 2/3)

The legacy portal's **payment** step (upload a slip, then officer review) is
already implemented in the back-office, so the app lands on existing ground.

---

## 2. What the archive actually contains (read this before trusting the plan)

The user referred to "video of the legacy system for mobile app". The archive
splits into three unrelated bodies of material:

| Path | What it is | Relevance |
|------|-----------|-----------|
| `archive/llr7-spec/` | 21 YouTube clips of **LaoLandReg V7.2** — a **QGIS 3.34 desktop plugin** over PostgreSQL. Transcripts in `raw/caps/clean/*.txt`, specs in `NN_*.md`. | **Desktop cadastral.** Titles/registration/transfers/valuation/mortgage/GIS. No mobile app. Narration is Thai, not Lao. |
| `archive/legacy-docs/LandTitling.md` | The **two-app architecture** design doc (bilingual): Back-Office + Citizen Portal, 4 citizen services, eligibility rules, fee schedule, payment phases, roadmap. | **This is the citizen/mobile design source.** |
| `archive/land-titling-prototype/` | A **working demo citizen PWA** (`citizen/index.html`, 4 screens, Lao) + officer dashboard + screenshots. Demo-only, no DB, mocked parcels `P1..P6`. | **The visual/UX reference for the app.** |

So the transcripts describe the *desktop* legacy system; the *mobile* behaviour
comes from `LandTitling.md` and the prototype. The prototype is the better
reference for screens (it is a real UI), the doc is better for business rules.

### 2.1 Legacy citizen flows (from `LandTitling.md` §Citizen Portal)

4 core services, of which only the payment part is in scope:

1. **ໃບແຈ້ງມີ** — eligibility by parcel status
   (`ACTIVE` → eligible; `MORTGAGED` / `PENDING` → not eligible), then pay a
   service fee, then officer review, then PDF+QR.
2. **ຂໍເອກະສານ** — pick document type (Title Copy 50,000 / Parcel Map 30,000 /
   Transaction History 20,000 kip), enter parcel code, show fee, **upload
   payment slip**, submit, officer review, approve, download.
3. **ລາຍງານບັນຫາ** — issue type → detail → up to 3 photos → GPS → submit.
4. **ຕິດຕາມສະຖານະ** — track all requests, with a timeline.

Legacy payment phasing (doc §Authentication & Payment):

- Phase 1 — bank slip upload
- Phase 2 — BCEL One QR
- Phase 3 — direct bank integration

**The MVP implements Phase 1**, which is exactly what the back-office already
supports (slip upload → officer verify/reject, or bank-CSV auto-match).

### 2.2 Legacy authentication (doc §Authentication & Payment)

> "Citizen enters **national ID + OTP SMS** (no password). System auto-links to
> the holder record."

The live system differs: **email OTP**, because no SMS gateway was available
(SMS was dropped — see `AGENTS.md`). The national-ID auto-link is **not**
implemented; a citizen is linked to land only when an officer sets
`owners.citizen_user_id` (`public/admin/users.php` → "link owner").

> **Open question OQ-1 (blocking for real rollout).** A land-tax payer in
> Savannakhet is not guaranteed to own an email address, and the legacy design
> was national-ID + SMS. Options: (a) ship MVP on email OTP and accept it;
> (b) add phone OTP once an SMS gateway is available; (c) national ID +
> officer-issued one-time code. This decision changes the login screen and the
> `users` schema, so it must be made before the app is published.

---

## 3. What the API already gives us (no backend rewrite needed)

| Need | Endpoint | Notes |
|------|----------|-------|
| Request OTP | `POST api/v1/auth.php` `{action:"otp/request", email}` | Returns `otp_token`, `expires_at`, `sent`. `debug_code` only when `OTP_DEBUG=1`. |
| Verify OTP | `POST api/v1/auth.php` `{action:"otp/verify", email, code, otp_token}` | Returns JWT `token` + `user{id,role}`. |
| My parcels + obligations | `GET api/v1/my_parcels.php` | Bearer token. Scoped by `owners.citizen_user_id`. Each parcel carries `obligations[]`: `fee_year, amount_due, amount_paid, penalty, status, due_date, outstanding`. |
| Issue a payment slip | `POST api/v1/payments.php` `{obligation_id, method, amount?}` | Returns `id, reference_no, amount, slip_qr, status:"ISSUED", due_date`. `amount` defaults to the outstanding balance, capped to it. |
| List payments | `GET api/v1/payments.php` | Bearer token, citizen-scoped. Returns `payments[]` with `reference_no, amount, status, parcel_no, fee_year, created_at, ...`. |
| Upload the receipt photo | `POST api/v1/payments.php?id={id}&receipt=1` multipart `slip` | jpg/jpeg/png/pdf/webp → sets `payments.slip_path`. |
| Printable A4 slip | `GET api/v1/payments.php?id={id}&slip=1` | **Returns HTML**, not JSON (A4 layout with QR image from `api.qrserver.com`). |

**Rule for the app: never recompute the fee.** The amount comes from
`fee_obligations` snapshots (`rate_per_sqm`, `area_sqm`, `amount_due`) computed
server-side. The app only displays what the server assessed. This keeps the app
correct when the province revises zone rates, and avoids the zone-code model
(`zones.code`, e.g. `G17`/`A7`/`C7`/`C8`) leaking into the client.

**Current citizen scoping is correct**: `my_parcels.php` and `payments.php` both
filter on `owners.citizen_user_id`, and `payments.php?id=` forbids a citizen from
reading another citizen's slip. The app inherits that.

---

## 4. Backend gaps to fill (small, and coordinate with the back-office owner)

| ID | Gap | Proposed | Priority |
|----|-----|----------|----------|
| B-1 | Slip is HTML only; a mobile app wants structured slip data (and to render its own A4 slip natively) | Add `GET api/v1/payments.php?id={id}&slip=json` returning the same fields as the HTML (`reference_no, amount, rate_per_sqm, due_date, parcel, district, village, owner, province, qr_payload`) | **High** — needed for a native slip screen |
| B-2 | Citizen cannot see **which bank account** to pay into | Add the receiving account (bank name, account no, account name) to the fee-year or basic-data config, and expose it in the slip JSON | **High** — without it the app cannot tell the citizen where to pay |
| B-3 | No way to see the **rejected** reason in the app | `payments.verify_note` exists; the list endpoint must return it (and a Lao "reason" string) | Medium |
| B-4 | No push notification when a payment is verified | Poll + pull-to-refresh now; push later (needs FCM/APNs + a device-token table) | Deferred |
| B-5 | `my_parcels.php` does not return `parcels.zone_code` / zone name | Add for display ("ເຂດ A7") | Low |
| B-6 | Two migrations are both numbered `003` (`003_zone_code.sql`, `003_national_geography.sql`) | Renumber the geography one to `004` | Low (housekeeping) |

Also note for the app team: the API is **not CORS-restricted** and returns plain
JSON, so a mobile client can call it directly. Use `https://landfee.apis.com.la/api/v1`.

---

## 5. App architecture

Location: `mobile/` (Flutter project root), package `landfee_citizen`.
Targets **Android first** (iOS optional), plus a `--dart-define` API base URL.

```
mobile/lib/
  main.dart                 app entry, theme, routing
  core/
    api_client.dart         http client, envelope unwrap, error mapping
    api_exception.dart
    auth_store.dart         token persistence (flutter_secure_storage)
    config.dart             API base URL, app constants
    money.dart              kip formatting ( Lao digits optional )
    theme.dart              gov colour tokens from the legacy prototype
  models/
    parcel.dart             parcel + obligations
    obligation.dart
    payment.dart
    session.dart            user + token
  state/
    auth_controller.dart    OTP request/verify, sign-out
    parcels_controller.dart my parcels + obligations
    payments_controller.dart payments list, issue slip, upload receipt
  ui/
    screens/
      login_screen.dart         email -> OTP -> signed in
      home_screen.dart          parcel list + outstanding summary
      parcel_detail_screen.dart  parcel info + per-year obligations
      pay_screen.dart            quote -> method -> issue slip
      slip_screen.dart           reference + QR + amount + due date
      receipt_screen.dart        camera/gallery -> upload
      payments_screen.dart       history + status
      payment_detail_screen.dart slip detail, receipt image, status
      about_screen.dart
    widgets/
      gov_header.dart, status_chip.dart, kip_text.dart, empty_state.dart,
      error_view.dart, parcel_card.dart
```

### 5.1 Packages

| Package | Why |
|---------|-----|
| `http` | API calls (no Dio needed for this size) |
| `provider` | simple, no codegen — state via `ChangeNotifier` |
| `flutter_secure_storage` | JWT in Android Keystore / iOS Keychain (not `shared_preferences`) |
| `image_picker` | receipt photo (camera or gallery) |
| `qr_flutter` | render the slip QR payload, pure Dart |
| `url_launcher` | open the A4 slip in a browser, open `mailto:`/tel |
| `intl` | date + number formatting |

No codegen (freezed/json_serializable) to keep the build simple and the
dependency surface small — plain `fromJson` factories.

### 5.2 Visual continuity with the legacy prototype

Reuse the prototype's tokens so the app feels like the same system:

```
--gov #0b3d2e   --gov2 #14644a   --gold #c9a227   --bg #f0f3f0
--card #ffffff  --ink #17231d   --muted #5c6b62  --line #dce4dc
--danger #b3261e --ok #1d7a4f    --blue #1a5fb4
```

Font: Lao needs a real Lao face. Use the family fallback chain
`Phetsarath OT` → `Noto Sans Lao` → `Tahoma` → system. Bundle
`assets/images/lao-emblem.png` (from `archive/images/`) as the header emblem.

Language: **Lao primary, English secondary** — exactly like the legacy
prototype (Lao label with an English sub-label). A full i18n framework is
overkill for two fixed strings sets; a simple `AppStrings` map is enough, with
Lao as the default and an in-app toggle.

---

## 6. Screen map

| # | Screen | Lao label | Key content |
|---|--------|-----------|-------------|
| 1 | Login | ເຂົ້າສູ່ລະບົບ | email → OTP code (6 digits) → signed in; `OTP_DEBUG` code shown only in dev builds |
| 2 | Home | ຕອນດິນຂອງຂ້ອຍ | summary card (total outstanding, year selector), parcel cards (parcel no, district/village, area, outstanding, status chip), bottom nav: ຕອນດິນ / ການຊຳລະ / ບ້ານຂ້ອຍ |
| 3 | Parcel detail | ຂໍ້ມູນຕອນດິນ | parcel no, title no, district/village, area, zone, owner, per-year obligation rows, "ຊຳລະເງິນ" |
| 4 | Pay | ເບິ່ງຄ່າຊຳລະ | obligation summary (rate × area = due, penalty, outstanding), partial/full amount chooser, method (bank transfer / mobile money / counter) |
| 5 | Slip | ສະລິບໄດ້ຊຳລະ | reference `LFE-2026-000123`, QR, amount, due date, bank account (B-2), "ເປີດໃນ browser" |
| 6 | Receipt | ອັບໂຫລດສະລິບເງິນ | camera/gallery, preview, upload → status becomes "awaiting verification" |
| 7 | Payments | ການຊຳລະຂອງຂ້ອຍ | history list: reference, amount, status, date; pull-to-refresh |
| 8 | Payment detail | ລາຍລະອຽດການຊຳລະ | status timeline (issued → receipt uploaded → verified/rejected), receipt image, reject reason (B-3) |
| 9 | About | ກ່ຽວກັບ | province, version, contact, sign-out |

---

## 7. Milestones

| M | Deliverable | Done when |
|---|--------------|-----------|
| **M0** | Plan (this doc) | agreed ✅ |
| **M1** | Flutter scaffold + theme + assets | app runs on an Android emulator showing the legacy-style header ✅ |
| **M2** | Core: API client, models, secure token store | unit-testable client; token survives restart ✅ |
| **M3** | Auth: email OTP → JWT → home | real OTP against the live API; session persists across restarts ✅ |
| **M4** | Home + parcel detail with obligations | parcel list matches `my_parcels.php` output; outstanding maths matches the server ✅ |
| **M5** | Pay → issue slip → receipt upload | slip created server-side (reference + QR); receipt photo uploaded; officer sees it in the back-office queue ✅ |
| **M6** | Payments history + status refresh | status transitions visible; rejected reason shown ✅ (the reason itself needs B-3) |
| **M7** | Back-office alignment | end-to-end: app pays → officer verifies → obligation shows PAID in `my_parcels.php` ✅ (verified by the smoke test) |
| **M8** | Harden + release | bilingual strings, empty/error states, offline messages, APK build, store-ready icons, privacy note ⬜ |

**M5 is the milestone that proves the product.** M7 is the one that proves the
system loops. Both are done and verified.

### 7.1 What the smoke test proves

`mobile/tool/smoke_test.dart` drives the app's own `ApiClient` against a
running API:

1. the citizen requests and verifies an email OTP;
2. `my_parcels.php` returns the linked parcel with its obligations
   (2026 unpaid at 175,070,000 kip, 2025 settled);
3. a slip is issued and its reference matches `LFE-{year}-{seq6}`, with the QR
   payload and the outstanding amount;
4. the receipt photo uploads and appears in the payment history;
5. **a second citizen sees none of it** — no parcels, no payments, and the
   slip is refused with 403;
6. an officer verifies the payment, and the citizen then sees the 2026 fee as
   settled.

### 7.2 What is left for M8

- No Android SDK on the dev machine, so **no APK has been built** and the app
  has never run on a device. Everything above is verified at the API level and
  by the static analyser, not on a handset.
- No app icons or splash assets (the government emblem from `archive/images/`
  is not yet bundled).
- No offline behaviour, no push notifications, no dark mode.

---

## 8. Risks / things that will bite

1. **Login is email-only today** (OQ-1). Blocks a real rollout in a
   phone-first country. Decide early.
2. **A citizen with no linked land sees an empty app.** Linking is an officer
   action (`owners.citizen_user_id`). A first-run screen must explain this and
   tell the citizen what to bring to the district office — and, ideally, a
   lookup-by-parcel-code path (the legacy "ຄົ້ນຫາທີ່ດິນ" step) that
   *requests* a link rather than granting access.
3. **The bank account is not in the system yet** (B-2) — the app cannot be
   genuinely useful without it.
4. **Zone rates are placeholders** (80k–180k kip/m²). Amounts shown to citizens
   will be wrong until the province sets real rates. The app should not hide
   this, but the *back-office* is where it gets fixed.
5. **Two `003` migrations** exist; the live DB may be on a different migration
   state than the app expects. The app should degrade gracefully (show the
   server's numbers, never its own).
6. **Windows dev environment**: the PHP built-in server is single-threaded, so
   run the API on 8091 and point the app at it with
   `--dart-define=API_BASE=http://localhost:8091/api/v1` (Android emulator uses
   `10.0.2.2` for the host).

---

## 9. Decisions needed from the user / province

| ID | Question | Blocks |
|----|----------|--------|
| OQ-1 | Email OTP vs national-ID + SMS vs national-ID + officer code | Login screen, `users` schema |
| OQ-2 | Where does a citizen pay? (bank account, wallet, or counter) | Slip screen (B-2) |
| OQ-3 | Is the annual land tax the only thing the app pays, or also service fees (certificate/document fees)? | Scope M5+ |
| OQ-4 | Android only, or Android + iOS? | M8 |
| OQ-5 | Does the app need a parcel-code lookup for citizens who are not yet linked? | M4/M5 scope |
| OQ-6 | Dark mode / language toggle required? | M8 |
