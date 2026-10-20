# Land Tax Payment — citizen app (Flutter)

The citizen-facing mobile app for paying the annual land tax in Savannakhet
Province. It is a **client of the existing Land Fee JSON API** (`api/v1/*`) —
the back-office and the API already do the work; this app makes the citizen
side usable on a phone.

Plan and scope: [`../docs/8-citizen-flutter-app-plan.md`](../docs/8-citizen-flutter-app-plan.md).

## What it does (MVP)

1. Sign in with an email + one-time code.
2. See your parcels and the land tax assessed on each, per year.
3. Choose how much to pay and how, and issue a payment slip
   (reference `LFE-{year}-{seq6}` + QR).
4. Photograph the paid bank slip and upload it.
5. Track the payment until an officer verifies it.

## Layout

```
lib/
  main.dart              app entry, providers, auth gate
  core/                  config, theme, API client, secure token store, strings
  models/                Parcel, Obligation, Payment, IssuedSlip, session
  state/                 AuthController, ParcelsController, PaymentsController
  ui/screens/            login, home shell, parcels, parcel detail, pay, slip,
                         receipt, payments, payment detail, account
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
physical phone use the machine's LAN address; on iOS simulator use
`localhost`.

Defaults to the live server: `https://landfee.apis.com.la/api/v1`.

## The API contract the app relies on

| Call | Endpoint |
|------|----------|
| Request the login code | `POST auth.php` `{action:"otp/request", email}` |
| Verify the code | `POST auth.php` `{action:"otp/verify", email, code, otp_token}` |
| My parcels + obligations | `GET my_parcels.php` |
| Issue a payment slip | `POST payments.php` `{obligation_id, method, amount?}` |
| My payments | `GET payments.php` |
| Upload the receipt | `POST payments.php?id={id}&receipt=1` (multipart `slip`) |
| Printable A4 slip | `GET payments.php?id={id}&slip=1` (HTML) |

**The app never computes a fee.** Every amount shown comes from the server's
`fee_obligations` snapshot, so a change to the zone rates is reflected without
an app release.

## Smoke test

The smoke test drives the app's own `ApiClient` against a running API and
checks the whole loop, including that a second citizen cannot see the first
citizen's land or payments.

```bash
# 1. test data
psql -d landfee_a2test -f tool/fixtures/seed_test_data.sql

# 2. the API, with OTP_DEBUG=1 so the code comes back in the response
PGHOST=localhost PGDATABASE=landfee_a2test PGUSER=postgres PGPASSWORD=test1234 \
PG_SCHEMA=landfee OTP_DEBUG=1 php -S localhost:8091

# 3. the test
dart run tool/smoke_test.dart http://localhost:8091/api/v1
```

`OTP_DEBUG=1` must never be set on the production server.

## Current status

| Milestone | State |
|-----------|-------|
| M1 scaffold + theme | done |
| M2 core (client, models, token store) | done |
| M3 email OTP login | done |
| M4 parcels + obligations | done |
| M5 pay → slip → receipt upload | done |
| M6 history + status | done (rejection reason needs a small API change) |
| M7 end-to-end with the back-office | done — verified by the smoke test |
| M8 harden + release | **not started** — no Android SDK on the dev machine, so no APK has been built yet |

## Known gaps

- **The receiving bank account is not in the system yet**, so the app cannot
  yet tell the citizen where to pay. It needs a basic-data setting and a new
  field on the slip (see the plan, B-2).
- **Login is by email.** The legacy design was national ID + SMS; no SMS
  gateway is available (see the plan, OQ-1).
- **A citizen with no linked land sees an empty home screen.** Linking is an
  officer action in the back-office.
- **No push notifications** — the app refreshes on demand.
- **No APK has been built** (no Android SDK on this machine) and the app has
  not yet been run on a real device.
