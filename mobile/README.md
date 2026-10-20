# Land Tax Payment — citizen app (Flutter)

The citizen-facing mobile app for paying the annual land tax in Savannakhet
Province.

- Plan: [`../docs/8-citizen-flutter-app-plan.md`](../docs/8-citizen-flutter-app-plan.md)
- API contract the app needs: [`../docs/9-citizen-app-api-contract.md`](../docs/9-citizen-app-api-contract.md)

## What it does (MVP)

1. Sign in with a **phone number** (no email, no OTP, no PIN, nothing sent).
   The first time a number is used the account is created.
2. **Look up a parcel by its number** — from the land title — and see the land
   tax assessed on it: the zone code, the rate, the area and the amount.
3. Choose how much to pay and how, and issue a payment slip
   (reference `LFE-{year}-{seq6}` + QR).
4. Photograph the paid bank slip and upload it.
5. Track the payment until an officer verifies it.

The rate comes from the **zone code on the land title**, so the app never
computes a fee: it shows what the server assessed. When the province revises
the zone rates, the app is correct without a release.

## Layout

```
lib/
  main.dart              app entry, providers, auth gate
  core/                  config, theme, API client, secure token store, strings
  models/                Parcel, Obligation, Payment, IssuedSlip, ParcelQuote
  state/                 AuthController, ParcelsController, PaymentsController
  ui/screens/            login, home shell, parcels, parcel detail,
                         find parcel, quote, slip, receipt, payments,
                         payment detail, account
  ui/widgets/            government header, status chip, shared rows/cards
tool/
  smoke_test.dart        end-to-end check of the app's API contract
  fixtures/              test data + a sample receipt image
```

## Running it

```bash
cd mobile
flutter pub get
flutter run --dart-define=API_BASE=http://10.0.2.2:8091/api/v1
```

`10.0.2.2` is the host machine as seen from the Android emulator. On a
physical phone use the machine's LAN address; on the iOS simulator use
`localhost`.

Defaults to the live server: `https://landfee.apis.com.la/api/v1`.

## The API the app calls

| Call | Endpoint | State |
|------|----------|-------|
| Sign in | `POST api/v1/citizen_auth.php` | **to be added** |
| Price a parcel | `GET api/v1/parcel_fee.php` | **to be added** |
| Issue a slip | `POST api/v1/citizen_payments.php` | **to be added** |
| My payments | `GET api/v1/citizen_payments.php` | **to be added** |
| Upload the receipt | `POST api/v1/citizen_payments.php?id={id}&receipt=1` | **to be added** |
| My linked parcels | `GET api/v1/my_parcels.php` | exists |

The first five are specified in the contract document and are **not in the
repository yet** — the backend is being worked on by someone else, so the app
is built against the contract and the endpoints are added separately. Until
they are, the app cannot talk to a server.

## Smoke test

The smoke test drives the app's own `ApiClient` against a running API and
checks the whole loop, including that a second citizen cannot see the first
citizen's payments.

```bash
# 1. test data
psql -d landfee_a2test -f tool/fixtures/seed_test_data.sql

# 2. the API
PGHOST=localhost PGDATABASE=landfee_a2test PGUSER=postgres PGPASSWORD=test1234 \
PG_SCHEMA=landfee php -S localhost:8091

# 3. the test
dart run tool/smoke_test.dart http://localhost:8091/api/v1
```

It **skips with exit code 2** when the citizen API is not deployed yet, so it
never fails in a confusing way.

## Current status

| Milestone | State |
|-----------|-------|
| M1 scaffold + theme | done |
| M2 core (client, models, token store) | done |
| M3 sign-in (phone number) | done in the app; the endpoint is specified, not deployed |
| M4 find a parcel + its fee | done in the app; the endpoint is specified, not deployed |
| M5 pay → slip → receipt upload | done in the app; the endpoint is specified, not deployed |
| M6 history + status | done in the app; the endpoint is specified, not deployed |
| M7 end-to-end with the back-office | proven against a reference implementation of the contract; not proven against the deployed API |
| M8 harden + release | **not started** — no Android SDK on the dev machine, so no APK has been built yet |

## Known gaps

- **The backend endpoints are not written yet** (see the contract document).
- **The QR is a placeholder.** The land office's receiving account and the
  bank's QR format are not known yet, so the slip shows the reference in large,
  copyable type and the citizen types it into their banking app. The real QR
  replaces the placeholder when the bank details arrive (the payload is
  already in `slip.slipQr`; the format is in the contract document §4).
- **The receiving bank account is not in the system**, so the app cannot yet
  show where to pay.
- **A phone number is guessable.** With no PIN, anyone who knows a citizen's
  number can sign in as them and read their land record and payment history.
  The province accepted this: the endpoint should be rate-limited and logged,
  and a PIN can be added later without changing the app.
- **No push notifications** — the app refreshes on demand.
- **No APK has been built** (no Android SDK on this machine) and the app has
  not yet been run on a real device.
