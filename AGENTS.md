# Land Fee Payment MVP — AGENTS.md

## What is this?
Land Fee Payment MVP for Savannakhet Province (Lao). Three front-ends over one PHP JSON API and a PostgreSQL DB:
- **Citizen PWA** (`public/citizen/`) — mobile-first, installable: phone-OTP login, fee quote, payment slip, tracking
- **Citizen Flutter app** (`mobile/`) — the native Android/iOS client for paying the land tax (same JSON API; plan in `docs/8-citizen-flutter-app-plan.md`)
- **Officer Back-Office** (`public/admin/`) — scan-upload + OCR review, parcel registry, zone-rate editor, verify/reject payments, MOF reports

## Key Files
| File | Purpose |
|------|---------|
| `sql/schema.sql` | PostgreSQL schema (`DROP SCHEMA … CASCADE` + `CREATE SCHEMA landfee`) |
| `sql/migrations/001_annual_fee_v2.sql` | Additive v1→v2 upgrade (idempotent; apply by hand on the server) |
| `sql/migrations/002_basic_data_and_email_otp.sql` | Adds `provinces`, `districts.province_id`, `users.email` (apply **after** 001) |
| `sql/migrations/006_zone_rates_v2.sql` | Zone×road rate matrix: adds `zone_plans`, `districts.zone_letter`, zone district/plan columns, `zone_rate_tiers`; backfills tiers from `zones.rate_per_sqm` (idempotent) |
| `sql/seed_savannakhet.sql` | 60 legacy `zones_rates` reference rows (decree band). **No login users.** Geography is no longer seeded here — that moved to `sql/seed_geography_national.sql`. |
| `sql/seed_zone_rates_savannakhet.sql` | 62 decree-385 valuation zones (A1–O4, +A5/A6 for 1301) + 248 tier rates (3.5–250 kip/m²/yr), one letter per district; `ON CONFLICT DO NOTHING`, safe to re-run |
| `sql/migrations/011_decree385_zone_rates.sql` | Re-bases existing zone tier rows + `zones.rate_per_sqm` fallbacks onto the decree 385/2009 band (UPDATE-only, idempotent; apply by hand on the server, see `docs/15-landtax-reg.md`) |
| `sql/migrations/014_late_payment_penalty.sql` | Adds `fee_obligations.penalty_override/penalty_note/penalty_set_by/penalty_set_at` for the audited 10% late-payment surcharge (idempotent; apply by hand **before** deploying the code that reads them) |
| `sql/migrations/015_payment_submitted_status.sql` | Adds `SUBMITTED` to the `payments.status` CHECK: the citizen uploaded the receipt and the payment waits for an officer (idempotent; apply by hand **before** deploying the code that writes it) |
| `lib/db.php` | PDO connect (`db_connect`) + envelope helpers `json_ok` / `json_error` |
| `lib/config.php` | DB/JWT config; loads repo-root `.env` (gitignored), env vars win |
| `lib/auth.php` | HS256 JWT (no deps); reads `Authorization: Bearer` header only |
| `lib/otp.php` | Stateless signed OTP tokens + the `otp_send_email()` seam |
| `lib/mail.php` | Outbound email via PHP `mail()` (needs a system MTA + `MAIL_FROM`) |
| `lib/validate.php` | `collect_errors` / `validate_*` / `reject_on_errors` |
| `lib/slip.php` | `LFE-{year}-{seq6}` reference generator (sequence-backed) + A4 slip HTML |
| `lib/fee.php` | Zone rate lookup, `fee_upsert_obligation`, `fee_settle_obligation`, `fee_audit` |
| `lib/ocr.php` | Tesseract OCR (`lao+eng --psm 3 -c tessedit_create_tsv=1`, via GD + `proc_open`) |
| `lib/title_parser.php` | Lao/EN title field parser + district/village fuzzy resolution |
| `lib/bank_match.php` | Bank CSV parsing + the §5 matching engine |
| `api/v1/*.php` | **The live** versioned JSON API (auth, fee, payments, districts, villages, rates, parcels, scans, reports) |
| `public/admin/*.php` | Officer back-office screens |
| `public/citizen/*.php` | Citizen PWA screens + manifest + service worker |
| `mobile/` | Citizen Flutter app (`lib/` app code, `tool/smoke_test.dart` API contract check) |
| `.github/workflows/deploy.yml` | CI/CD → apis.com.la on push to main (code only) |
| `docs/` | Current plan/SRS/architecture. `archive/` = legacy, **not live** |

## Gotchas (things an agent gets wrong)
- **Root `api/*.php` stubs were deleted** (they called the removed `json_output()`); the live JSON API is `api/v1/*.php`. `public/index.html` is a stale "Phase 0 skeleton" page — ignore it.
- **`zones_rates`** is the legacy district×road table name (not `zone_rates`); live fees use `zones` + `zone_rate_tiers`. The whole app runs under PostgreSQL schema `landfee` set via `search_path`.
- **The seed creates no users.** `sav_officer` / `demo1234` exists only in the live DB; the local Docker DB has no officer (and historically a bad hash). Insert a user manually if you need local login.
- **Same-origin URLs are path-based:** API base = `/api/v1`, officer UI = `/public/admin`, citizen UI = `/public/citizen`. `lib/config.php` derives the host from the request, so never hardcode `localhost` or the domain. Env overrides `API_BASE` and `UPLOADS` exist for local dev/testing (defaults unchanged in prod).
- **`lib/auth.php` only reads the `Authorization` header.** Admin server pages decode the session JWT directly (see `admin/common.php::admin_user()`); the fallback header path is for CLI/cookie-less access.
- **DB changes are manual on the server.** CI pull-only deploys code (git pull + chown + Apache restart); it never runs migrations/schema/seed. Migrations applied as `postgres` create tables/sequences owned by `postgres`; the web role (`landfee_web`) then needs explicit grants. Missing grants show up as empty admin tables plus empty-body 500s from the affected API endpoints.
- **`.env` at repo root** is gitignored and loaded by `lib/config.php` for prod secrets. Don't commit credentials; don't hardcode them in `config.php`.
- **`OTP_DEBUG=1` is required for local citizen login and MUST be unset in production.** The OTP is now a stateless signed token (`lib/otp.php`) carried through `otp/request` → `otp/verify`; the old session-based code could never verify because the citizen pages call the API server-side with no cookie. With `OTP_DEBUG=1` the code is echoed in `data.debug_code` so the login screen can show it — without it, any caller could read the code.
- **SMS was dropped; OTPs go by email.** Citizen login is email-based (`users.email`, nullable + unique-indexed). Delivery goes through `lib/mail.php` → PHP `mail()`, which needs a working MTA on the host **and** `MAIL_FROM` in `.env`; without them `otp/request` still succeeds but nothing is sent (`data.sent === false`).
- **Basic data is admin-maintained** via `public/admin/basic_data.php` (province, districts, villages, lookups, zone rates, fee years) and `public/admin/users.php`. Both are **admin-role only**. Deleting a row that is still referenced is refused with a message naming the counts, rather than failing on an FK.
- **Fee values follow the land-tax decree.** `seed_zone_rates_savannakhet.sql` now ships decree 385/ປປ (2009) tier rates (3.5–250 kip/m²/yr; construction 5–300, agricultural per-hectare converted to kip/m²) — the original placeholder band (85k–180k kip/m²/yr) matched one-off transfer fees, not the annual tax. Migration `011_decree385_zone_rates.sql` re-bases live DBs. The decree is from 2009 and a newer Cabinet table may be in force: **confirm the currently valid rates with the province**, then overwrite via **Basic Data > Zone rates** (or an updated seed). Mapping assumptions (settlement class per district, road-tier→column mapping, `[verify]` cells) are documented in `docs/15-landtax-reg.md` §6.
- **Late-payment surcharge is derived, then frozen.** While an obligation is `UNPAID`/`PARTIAL` the 10% (decree 385/2009 Art. 10) is computed by `fee_penalty_sql()` in `lib/fee.php` from `amount_due` and `due_date`; `fee_settle_obligation()` stamps `fee_obligations.penalty` once the status is terminal. Officers waive/reduce it through `fee_obligations.php` `action=penalty` (columns `penalty_override`/`penalty_note`, migration 014). **Never read the raw `penalty` column for an unpaid row** — use the helper, or the figure is 0.
- **Zone rates are history-tracked** (`zone_rate_tiers.valid_from`); "current" is the newest tier per (zone_code, road_category). `zones.rate_per_sqm` survives only as a fallback for legacy zones with no tier rows.
- **Zone codes are district-lettered.** `districts.zone_letter` (A–O for Savannakhet) prefixes each zone code (`A1`, `O4`). Legacy zones `G17/A7/C7/C8` stay active with `district_id = NULL` ("unassigned").
- **Citizens are scoped by `owners.citizen_user_id`** (not `payments.payer_owner_id`, which is an `owners.id`). A parcel links a citizen only when that column is set — officer scan-confirm never sets it, so new parcels are invisible to citizens until linked.
- **Windows PHP built-in server is single-threaded** (`PHP_CLI_SERVER_WORKERS` is Unix-only). Admin pages that call the API server-side deadlock if both share one process. For testing run the API on one port and the UI on another with `API_BASE` pointing at the API.
- **Production PHP runs under mod_php, not FPM.** The shared `php8.3-fpm` pool has `disable_functions` including `proc_open`/`exec`, which breaks `lib/ocr.php` (Tesseract is launched via `proc_open`). The `landtax.apis.com.la` vhost therefore serves `.php` via `SetHandler "application/x-httpd-php"` (mod_php) so OCR works; the server-wide FPM ini must NOT be edited to un-disable `proc_open` (other apps depend on it). If scan uploads 500 with an empty body, first confirm the landtax vhost is still on mod_php.
- **The Flutter app (`mobile/`) is a client of `api/v1/*` — it never computes a fee.** Every amount comes from the server's `fee_obligations` snapshot, so zone-rate changes need no app release. Its API base is injected with `--dart-define=API_BASE=...` (default: the live server); on the Android emulator the host machine is `10.0.2.2`, not `localhost`. `mobile/tool/smoke_test.dart` drives the app's own API client against a running API and is the contract check to run after any change to the citizen endpoints.
- **The citizen app's three endpoints (`citizen_auth.php`, `parcel_fee.php`, `citizen_payments.php`) follow `docs/9-citizen-app-api-contract.md`.** The app signs in by **phone number** (not email OTP, no PIN) and identifies a parcel by **map sheet + parcel number**, the same identity as `uq_parcels_identity`; the rate comes from the parcel's zone code. After the lookup the app issues the slip by `parcel_id`, so a number repeated on another sheet cannot settle the wrong parcel (`citizen_payments.php` refuses `parcel_no` without `map_ref_no`).
- **`SUBMITTED` is proof received, not paid.** A receipt upload (from the app, the PWA or an officer's "upload slip") moves an `ISSUED` payment to `SUBMITTED`; the obligation stays open until an officer verifies it to `PAID`. Verify rejects it from either state; the bank-review queue and `lib/bank_match.php` candidate search treat `ISSUED` and `SUBMITTED` alike. Terminal states (`PAID`/`REJECTED`/`REFUNDED`) refuse further uploads.

## Local Dev
- PHP 8.3+ built-in server from repo root: `php -S localhost:8080` with `PGHOST=localhost PGPORT=5432 PGDATABASE=landfee PGUSER=postgres PGPASSWORD=test1234 PG_SCHEMA=landfee`.
- DB: Docker `landfee-test-postgres` (postgres:16-alpine) on localhost:5432. Load `sql/schema.sql` then `sql/seed_savannakhet.sql` (and `sql/seed_zone_rates_savannakhet.sql` for the A1–O4 zone skeleton).
- OCR needs the `tesseract` binary with `lao` + `eng` language data and PHP GD; uploads land in `public/uploads` (gitignored).
- **Title OCR is label-anchored, not positional.** `scans.php` stores the full page text in `ocr_raw` and the TSV word boxes in `ocr_words`; `title_parse_text()` (labels + regex over the full text) and `title_extract_word_fields()` (reads the words to the right of each printed label on a Tesseract line) are merged by per-field confidence. There is no fixed crop template — two certificate layouts are in circulation and the old 33-cell template only fit one, which is why it was deleted. Don't reintroduce position-based crops; measure against `sample land/` instead.
- **OCR needs a real capture, and handwritten fields are never expected.** Field rows below ~1000 px on the long edge, red pen markup, or heavily compressed photos come back as noise (a 967×742 annotated screenshot yields almost nothing). Uploads under 500 px are rejected with `image_too_small`; everything else is stored and the officer sees the low `overall_confidence` and types it. A title no / book no / date filled in by pen is not readable by Tesseract, so don't treat it as a parser bug.
- **Place names are matched on a normalised key.** `title_name_key()` (and its SQL twin `title_name_key_sql()`) drops whitespace and Lao tone marks and folds `ຳ`→`ໍາ` before exact/trigram comparison, because OCR re-spaces and re-spells names. It only affects matching — never rewrite the officer-visible name with it.
- Flutter app: `cd mobile && flutter pub get && flutter run --dart-define=API_BASE=http://10.0.2.2:8091/api/v1`. `flutter analyze` must be clean. There is **no Android SDK on this dev machine**, so no APK can be built here — the app has never been run on a device.

## Demo Credentials
- **Officer:** `sav_officer` / `demo1234` (live DB only)
- **Citizen:** email OTP — set `OTP_DEBUG=1` locally; the code is echoed in `data.debug_code`. `otp_send_email()` in `lib/otp.php` is the (currently MTA-dependent) email seam, so **no real email is sent without a mail server**.

## Verification (no automated tests exist)
No test suite, composer, linter, or CI checks — verify manually: start the built-in server + Docker Postgres, exercise the API with curl/JSON, and click through `/public/admin/` and `/public/citizen/`. Phases/known open items are tracked in `docs/4-plan-land-fee-mvp.md` (§14).
The one automated check is `mobile/tool/smoke_test.dart` (Dart) — it drives the Flutter app's own API client against a running API and asserts the citizen contract: map-sheet lookup, `ISSUED` → `SUBMITTED` after the receipt upload, cross-citizen isolation, and the officer verifying the submitted payment to `PAID`. It needs the citizen endpoints deployed (see `docs/9-citizen-app-api-contract.md`), migration 015 applied for `SUBMITTED`, and the fixture from `mobile/tool/fixtures/seed_test_data.sql`; it skips with exit code 2 when the endpoints are absent.

## Production
- **Site:** `https://landtax.apis.com.la/public/admin/` (Apache, DocumentRoot `/var/www/html/landtax`, git checkout)
- **DB:** PostgreSQL `landfee` on apis.com.la, schema `landfee`
- **Deploy:** GitHub Actions `deploy.yml` on push to `main` (needs `VPS_SSH_KEY`); restarts Apache to clear OPcache

## Fee Model (user-confirmed)
Flat, location-only: `fee = zone_rate(zone_code, road_category) × area_sqm`.
Zone rates: main=180k, branch=140k, alley=110k, none=85k kip/m² (placeholder band; official figures come from the province). `transaction_type` and land-use are descriptive labels for reporting only — never fee multipliers.
