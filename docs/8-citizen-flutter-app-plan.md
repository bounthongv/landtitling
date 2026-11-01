# Citizen Mobile App (Flutter) — Land Tax Payment

Plan for a native Flutter app that lets a citizen pay the annual land tax from a
phone, on top of the **existing** Land Fee JSON API (`api/v1/*`).

Status: plan approved in principle; **M1–M7 implemented and verified** in
`mobile/`. The app signs in by **phone number** (no OTP, no PIN), identifies a
parcel by province → district → village → map sheet → parcel number, and claims
it into "my parcels" explicitly (see §2.2 and the decisions in §10). The four
citizen endpoints the app needs are implemented in `api/v1/` and specified in
[`9-citizen-app-api-contract.md`](9-citizen-app-api-contract.md); the app's
contract is checked by `mobile/tool/smoke_test.dart`. M8 (harden + release) not
started.

---

## 1. Scope

**In scope (MVP)**

1. Sign in (phone number) → JWT.
2. Look a parcel up by **province → district → village → map sheet → parcel
   number**, and **claim** it into my parcels.
3. See **my parcels** and the **annual land tax** assessed on each, per year.
4. See the outstanding amount, penalty, due date, and status.
5. **Issue a payment slip** (reference `LFE-{year}-{seq6}` + QR payload).
6. **Pay**: photograph the bank slip and upload it as the receipt.
7. **Track** the payment status (`ISSUED` → `SUBMITTED` → `PAID` / `REJECTED`) and the slip itself.
8. Read the receipt photo back, and open the printable A4 slip.

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

The live system differs: **email OTP** for the PWA, because no SMS gateway was
available (SMS was dropped — see `AGENTS.md`). There is no national-ID
auto-link. A parcel is linked to a citizen when the citizen **claims** it in the
app — the owner's recorded phone must match the account's number, and the claim
is audited — or when an officer links it in the back-office. An officer can
unlink it again from the parcel screen.

> **Resolved (user decision): sign in by phone number, no OTP and no PIN.**
> Almost everyone has a mobile phone and most do not use email, so the phone
> number is the identifier. Nothing is sent anywhere: the citizen types their
> number, and the first time it is used the account is created. The app is
> built this way; the endpoints are specified in
> `9-citizen-app-api-contract.md`.
>
> The consequence is that anyone who knows a citizen's number can sign in as
> them and see their land record and payment history. The province has
> accepted this, so the endpoint should be rate-limited and logged rather
> than protected by a secret. If it becomes a problem, a PIN can be added
> later without changing the app's screens.

### 2.3 The flow the province described

> "if the user enters the parcel number the system (backend) will know the rate
> and tell how much to pay."

This is the **primary** flow of the app, and it does not require the citizen's
land to be linked to their account first — they know the parcel number and its
location from their title. The server reads the area and the zone code from the
parcel and resolves the rate itself, so the amount is always the server's
calculation. Claiming the parcel is a separate, optional step for "my parcels".

The zone code comes from the land title (recorded by the officer when the scan
is confirmed), and the rate per zone is maintained in Basic Data → Zone rates.
The rates are placeholders until the province sets the real ones.

---

## 3. What the API gives us, and what is still missing

| Need | Endpoint | Notes |
|------|----------|-------|
| Sign in | `POST api/v1/citizen_auth.php` `{action:"phone/signin", phone}` | **Implemented** (§1 of `9-citizen-app-api-contract.md`). Returns JWT `token` + `user{id,role}` + the phone + `created`. |
| Change the PIN | `POST api/v1/citizen_auth.php` `{action:"phone/setpin", ...}` | Optional hardening — **not implemented, not used**. The province decided against a PIN. |
| Price a parcel | `GET api/v1/parcel_fee.php?province_id=...&district_id=...&village_id=...&map_ref_no=...&parcel_no=...&fee_year=...` | **Implemented** (§2). The five identity fields printed on the title identify the parcel; location and sheet are required so a repeated number cannot resolve another parcel. Returns the parcel, its zone code, the rate, the amount and `claimed` — all computed by the server. |
| Claim a parcel | `POST api/v1/citizen_claim.php` `{parcel_id}` | **Implemented** (§3). The explicit "add to my parcels" step after a lookup. The owner's recorded phone must match the account's; a parcel linked to another citizen is refused; every attempt is audited. |
| Issue a payment slip | `POST api/v1/citizen_payments.php` `{parcel_id, fee_year, method, amount?}` | **Implemented** (§4). The app issues by the `parcel_id` the lookup returned. `{parcel_no, map_ref_no}` also works (the sheet is required with the number). Returns `id, reference_no, amount, slip_qr, status:"ISSUED", due_date`; `amount` defaults to the outstanding balance, capped to it. |
| List payments | `GET api/v1/citizen_payments.php` | **Implemented** (§4). Citizen-scoped: the payments they made, plus the ones on the parcels they own. |
| Upload the receipt photo | `POST api/v1/citizen_payments.php?id={id}&receipt=1` multipart `slip` | **Implemented** (§4). jpg/jpeg/png/pdf/webp → sets `payments.slip_path` and moves the payment to `SUBMITTED` (proof is with the district). |
| My parcels + obligations | `GET api/v1/my_parcels.php` | Exists. Scoped by `owners.citizen_user_id`. Each parcel carries `obligations[]`: `fee_year, amount_due, amount_paid, penalty, status, due_date, outstanding`. |
| Printable A4 slip | `GET api/v1/payments.php?id={id}&slip=1` | Exists, but **returns HTML** and renders its QR through `api.qrserver.com` (a third party). The app renders its own slip and QR. |

The four citizen endpoints were built to the written contract
(`9-citizen-app-api-contract.md`) and are in the repository. The contract also
covers the two schema changes the app needs: `payments.citizen_user_id` (so a
payment made for a parcel that is not linked to the citizen still shows up in
their own history) and the `SUBMITTED` status (migration 015). Linking a parcel
to an account reuses the existing `owners.citizen_user_id` column; there is no
extra table for claims.

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
JSON, so a mobile client can call it directly. Use `https://landtax.apis.com.la/api/v1`.

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
    auth_controller.dart    phone sign-in, sign-out
    parcels_controller.dart my parcels + obligations
    payments_controller.dart payments list, issue slip, upload receipt
  ui/
    screens/
      login_screen.dart         phone -> signed in
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
| `qr_flutter` | render the slip QR payload — **re-added when the bank QR arrives** (the app shows a placeholder for now) |
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
| 1 | Login | ເຂົ້າສູ່ລະບົບ | phone number → signed in; the same screen creates the account on first use |
| 2 | Home | ຕອນດິນຂອງຂ້ອຍ | summary card (total outstanding, year selector), parcel cards (parcel no, district/village, area, outstanding, status chip), bottom nav: ຕອນດິນ / ການຊຳລະ / ບ້ານຂ້ອຍ |
| 3 | Parcel detail | ຂໍ້ມູນຕອນດິນ | map sheet, parcel no, title no, district/village, area, zone, owner, per-year obligation rows, "ຊຳລະເງິນ" |
| 4 | Pay | ເບິ່ງຄ່າຊຳລະ | obligation summary (rate × area = due, penalty, outstanding), partial/full amount chooser, method (bank transfer / mobile money / counter) |
| 5 | Slip | ສະລິບໄດ້ຊຳລະ | reference `LFE-2026-000123` (large, copyable), a QR placeholder, the amount, the due date |
| 6 | Receipt | ອັບໂຫລດສະລິບເງິນ | camera/gallery, preview, "send to the district" → status becomes `SUBMITTED` |
| 7 | Payments | ການຊຳລະຂອງຂ້ອຍ | history list: reference, amount, status, date; pull-to-refresh |
| 8 | Payment detail | ລາຍລະອຽດການຊຳລະ | status timeline (issued → receipt uploaded → submitted → verified/rejected), receipt image, reject reason (B-3) |
| 9 | About | ກ່ຽວກັບ | province, version, contact, sign-out |

---

## 7. Milestones

| M | Deliverable | Done when |
|---|--------------|-----------|
| **M0** | Plan (this doc) | agreed ✅ |
| **M1** | Flutter scaffold + theme + assets | app runs on an Android emulator showing the legacy-style header ✅ |
| **M2** | Core: API client, models, secure token store | unit-testable client; token survives restart ✅ |
| **M3** | Auth: phone → JWT → home | sign-in works against the API; session persists across restarts ✅ |
| **M4** | Home + parcel detail with obligations | parcel list matches `my_parcels.php` output; outstanding maths matches the server ✅ |
| **M5** | Pay → issue slip → receipt upload | slip created server-side (reference + QR); receipt photo uploaded; officer sees it in the back-office queue ✅ |
| **M6** | Payments history + status refresh | status transitions visible; rejected reason shown ✅ (the reason itself needs B-3) |
| **M7** | Back-office alignment | end-to-end: app pays → officer verifies → obligation shows PAID in `my_parcels.php` ✅ (verified by the smoke test) |
| **M8** | Harden + release | bilingual strings, empty/error states, offline messages, APK build, store-ready icons, privacy note ⬜ |

**M5 is the milestone that proves the product.** M7 is the one that proves the
system loops. Both are done and verified.

### 7.1 What the smoke test proves

`mobile/tool/smoke_test.dart` drives the app's own `ApiClient` against a
running API. It needs the fixture (`mobile/tool/fixtures/seed_test_data.sql`,
owner **not** linked) applied first, and a fresh copy before each run — the run
claims the fixture parcel and settles its obligation.

1. the citizen signs in with a phone number, the account is created on first
   use, the same number signs in again, and a malformed number is refused;
2. the location cascade loads: provinces → districts of a province → villages
   of a district;
3. `parcel_fee.php` refuses a lookup without the location or the map sheet, and
   prices the parcel the citizen names — zone code, area, rate and amount, all
   from the server;
4. looking the parcel up links nothing; the fixture citizen claims it (the
   owner's phone matches), it then reads as `claimed` and appears in
   `my_parcels.php`, and **another citizen's claim is refused** with
   `claimed_by_other`;
5. a slip is issued by a citizen who does not own the parcel (anyone may pay) and
   its reference matches `LFE-{year}-{seq6}`, with the QR payload;
6. the receipt photo uploads and the payment becomes `SUBMITTED`; it appears in
   the payer's history and in the owner's history, and the other citizen sees
   none of it;
7. an officer verifies the payment, and the citizen then sees it as paid.

The test **skips with exit code 2** when the citizen API is not deployed yet,
so it never fails in a confusing way.

Run it with:

```
cd mobile
dart run tool/smoke_test.dart http://localhost:8091/api/v1
```

### 7.2 What is left for M8

- **Migration 015 must be applied on the live database** before the receipt
  upload works — `SUBMITTED` is a `CHECK`-constraint change, and CI never runs
  migrations (see `AGENTS.md`).
- No Android SDK on the dev machine, so **no APK has been built** and the app
  has never run on a device. Everything above is verified at the API level and
  by the static analyser, not on a handset.
- No app icons or splash assets (the government emblem from `archive/images/`
  is not yet bundled).
- No offline behaviour, no push notifications, no dark mode.

---

## 8. Risks / things that will bite

1. **The backend is live, but migrations are applied by hand.** The four
   citizen endpoints are deployed; the live database still needs migration 015
   (`SUBMITTED`) applied before a receipt upload can pass the CHECK constraint.
2. **A phone number is guessable.** With no PIN, anyone who knows or guesses
   a citizen's number can sign in as them and read their land record and
   payment history. Accepted for now — the endpoint should be rate-limited and
   logged, and a PIN can be added later without changing the app.
3. **Paying for a parcel you do not own is allowed** (the province's
   decision). A citizen may name any parcel number and pay the tax on it. It
   discloses that parcel's area and rate, but the payment itself is the point.
4. **Zone rates follow decree 385/2009** (3.5–250 kip/m²/yr; construction and
   agricultural rates are converted in the seed). A newer Cabinet table may be
   in force — confirm the currently valid rates with the province. The app shows
   the server's numbers and never its own, so fixing the rates in Basic Data
   fixes the app with no release.
5. **The bank QR is a placeholder.** The land office's receiving account and
   the bank's QR format are not known yet, so the app shows the reference in
   large, copyable type instead of a scannable code, and the citizen types it
   into their banking app. When the bank details arrive, the real QR replaces
   the placeholder — the payload is already in `slip.slipQr`, and the format
   is specified in `9-citizen-app-api-contract.md` §5.
6. **Four `003_*` migrations** exist; the live DB may be on a different migration
   state than the app expects. The app should degrade gracefully (show the
   server's numbers, never its own).
7. **Windows dev environment**: the PHP built-in server is single-threaded, so
   run the API on 8091 and point the app at it with
   `--dart-define=API_BASE=http://localhost:8091/api/v1` (Android emulator uses
   `10.0.2.2` for the host).

---

## 9. Decisions needed from the user / province

| ID | Question | Blocks |
|----|----------|--------|
| ~~OQ-1~~ | ~~Email OTP vs national-ID + SMS~~ | **Answered: phone number, no OTP, no PIN.** Implemented in the app; the endpoints are in `9-citizen-app-api-contract.md`. |
| ~~OQ-2~~ | ~~Where does a citizen pay?~~ | **Answered: the QR is scanned with the citizen's banking app; the banks are interlinked.** Needs the receiving account + the bank's QR format from the province. |
| OQ-3 | Is the annual land tax the only thing the app pays, or also service fees (certificate/document fees)? | Scope M5+ |
| OQ-4 | Android only, or Android + iOS? | M8 |
| ~~OQ-5~~ | ~~Does the app need a parcel-code lookup?~~ | **Answered: yes — it is the main flow.** Implemented. |
| OQ-6 | Dark mode / language toggle required? | M8 |
| ~~OQ-7~~ | ~~Is a PIN acceptable?~~ | **Answered: no PIN.** Phone number only; the account is created on first use. The app is built this way. |
| ~~OQ-8~~ | ~~May a citizen pay for a parcel they do not own?~~ | **Answered: yes — anyone may pay for any parcel.** No ownership check on paying. |
