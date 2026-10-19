# Land Fee Payment MVP — AGENTS.md

## What is this?
Land Fee Payment MVP for Savannakhet Province (Lao). Two front-ends over one PHP JSON API and a PostgreSQL DB:
- **Citizen PWA** (`public/citizen/`) — mobile-first, installable: phone-OTP login, fee quote, payment slip, tracking
- **Officer Back-Office** (`public/admin/`) — scan-upload + OCR review, parcel registry, zone-rate editor, verify/reject payments, MOF reports

## Key Files
| File | Purpose |
|------|---------|
| `sql/schema.sql` | PostgreSQL schema (`DROP SCHEMA … CASCADE` + `CREATE SCHEMA landfee`) |
| `sql/migrations/001_annual_fee_v2.sql` | Additive v1→v2 upgrade (idempotent; apply by hand on the server) |
| `sql/migrations/002_basic_data_and_email_otp.sql` | Adds `provinces`, `districts.province_id`, `users.email` (apply **after** 001) |
| `sql/seed_savannakhet.sql` | Province + 15 districts, 1,029 villages, 60 zone rates. **No login users.** Re-seeding truncates and reloads districts/villages; the province row is `DO NOTHING` so admin renames survive. |
| `lib/db.php` | PDO connect (`db_connect`) + envelope helpers `json_ok` / `json_error` |
| `lib/config.php` | DB/JWT config; loads repo-root `.env` (gitignored), env vars win |
| `lib/auth.php` | HS256 JWT (no deps); reads `Authorization: Bearer` header only |
| `lib/otp.php` | Stateless signed OTP tokens + the `otp_send_email()` seam |
| `lib/mail.php` | Outbound email via PHP `mail()` (needs a system MTA + `MAIL_FROM`) |
| `lib/validate.php` | `collect_errors` / `validate_*` / `reject_on_errors` |
| `lib/slip.php` | `LFE-{year}-{seq6}` reference generator (sequence-backed) + A4 slip HTML |
| `lib/fee.php` | Zone rate lookup, `fee_upsert_obligation`, `fee_settle_obligation`, `fee_audit` |
| `lib/ocr.php` | Tesseract OCR (`lao+eng --psm 6 -c tessedit_create_tsv=1`, via GD + `proc_open`) |
| `lib/title_parser.php` | Lao/EN title field parser + district/village fuzzy resolution |
| `lib/bank_match.php` | Bank CSV parsing + the §5 matching engine |
| `api/v1/*.php` | **The live** versioned JSON API (auth, fee, payments, districts, villages, rates, parcels, scans, reports) |
| `public/admin/*.php` | Officer back-office screens |
| `public/citizen/*.php` | Citizen PWA screens + manifest + service worker |
| `.github/workflows/deploy.yml` | CI/CD → apis.com.la on push to main (code only) |
| `docs/` | Current plan/SRS/architecture. `archive/` = legacy, **not live** |

## Gotchas (things an agent gets wrong)
- **Root `api/*.php` stubs were deleted** (they called the removed `json_output()`); the live JSON API is `api/v1/*.php`. `public/index.html` is a stale "Phase 0 skeleton" page — ignore it.
- **`zones_rates`** is the table name (not `zone_rates`); the whole app runs under PostgreSQL schema `landfee` set via `search_path`.
- **The seed creates no users.** `sav_officer` / `demo1234` exists only in the live DB; the local Docker DB has no officer (and historically a bad hash). Insert a user manually if you need local login.
- **Same-origin URLs are path-based:** API base = `/api/v1`, officer UI = `/public/admin`, citizen UI = `/public/citizen`. `lib/config.php` derives the host from the request, so never hardcode `localhost` or the domain. Env overrides `API_BASE` and `UPLOADS` exist for local dev/testing (defaults unchanged in prod).
- **`lib/auth.php` only reads the `Authorization` header.** Admin server pages decode the session JWT directly (see `admin/common.php::admin_user()`); the fallback header path is for CLI/cookie-less access.
- **DB changes are manual on the server.** CI pull-only deploys code (git pull + chown + Apache restart); it never runs migrations/schema/seed.
- **`.env` at repo root** is gitignored and loaded by `lib/config.php` for prod secrets. Don't commit credentials; don't hardcode them in `config.php`.
- **`OTP_DEBUG=1` is required for local citizen login and MUST be unset in production.** The OTP is now a stateless signed token (`lib/otp.php`) carried through `otp/request` → `otp/verify`; the old session-based code could never verify because the citizen pages call the API server-side with no cookie. With `OTP_DEBUG=1` the code is echoed in `data.debug_code` so the login screen can show it — without it, any caller could read the code.
- **SMS was dropped; OTPs go by email.** Citizen login is email-based (`users.email`, nullable + unique-indexed). Delivery goes through `lib/mail.php` → PHP `mail()`, which needs a working MTA on the host **and** `MAIL_FROM` in `.env`; without them `otp/request` still succeeds but nothing is sent (`data.sent === false`).
- **Basic data is admin-maintained** via `public/admin/basic_data.php` (province, districts, villages, lookups, zone rates, fee years) and `public/admin/users.php`. Both are **admin-role only**. Deleting a row that is still referenced is refused with a message naming the counts, rather than failing on an FK.
- **Fee values are placeholders.** `zones_rates` ships with sample kip/m² rates; the province is expected to overwrite them. Re-seeding resets district/village names (it truncates them) but leaves the province row alone.
- **Zone rates are history-tracked** (`zones_rates.valid_from`); "current" is the newest row per (district, road_category).
- **Citizens are scoped by `owners.citizen_user_id`** (not `payments.payer_owner_id`, which is an `owners.id`). A parcel links a citizen only when that column is set — officer scan-confirm never sets it, so new parcels are invisible to citizens until linked.
- **Windows PHP built-in server is single-threaded** (`PHP_CLI_SERVER_WORKERS` is Unix-only). Admin pages that call the API server-side deadlock if both share one process. For testing run the API on one port and the UI on another with `API_BASE` pointing at the API.
- **Production PHP runs under mod_php, not FPM.** The shared `php8.3-fpm` pool has `disable_functions` including `proc_open`/`exec`, which breaks `lib/ocr.php` (Tesseract is launched via `proc_open`). The `landfee.apis.com.la` vhost therefore serves `.php` via `SetHandler "application/x-httpd-php"` (mod_php) so OCR works; the server-wide FPM ini must NOT be edited to un-disable `proc_open` (other apps depend on it). If scan uploads 500 with an empty body, first confirm the landfee vhost is still on mod_php.

## Local Dev
- PHP 8.3+ built-in server from repo root: `php -S localhost:8080` with `PGHOST=localhost PGPORT=5432 PGDATABASE=landfee PGUSER=postgres PGPASSWORD=test1234 PG_SCHEMA=landfee`.
- DB: Docker `landfee-test-postgres` (postgres:16-alpine) on localhost:5432. Load `sql/schema.sql` then `sql/seed_savannakhet.sql`.
- OCR needs the `tesseract` binary with `lao` + `eng` language data and PHP GD; uploads land in `public/uploads` (gitignored).

## Demo Credentials
- **Officer:** `sav_officer` / `demo1234` (live DB only)
- **Citizen:** email OTP — set `OTP_DEBUG=1` locally; the code is echoed in `data.debug_code`. `otp_send_email()` in `lib/otp.php` is the (currently MTA-dependent) email seam, so **no real email is sent without a mail server**.

## Verification (no automated tests exist)
No test suite, composer, linter, or CI checks — verify manually: start the built-in server + Docker Postgres, exercise the API with curl/JSON, and click through `/public/admin/` and `/public/citizen/`. Phases/known open items are tracked in `docs/4-plan-land-fee-mvp.md` (§14).

## Production
- **Site:** `https://landfee.apis.com.la/public/admin/` (Apache, DocumentRoot `/var/www/html/landfee`, git checkout)
- **DB:** PostgreSQL `landfee` on apis.com.la, schema `landfee`
- **Deploy:** GitHub Actions `deploy.yml` on push to `main` (needs `VPS_SSH_KEY`); restarts Apache to clear OPcache

## Fee Model (user-confirmed)
Flat, location-only: `fee = zone_rate(district, road_category) × area_sqm`.
Zone rates: main=180k, branch=140k, alley=110k, none=85k kip/m². `transaction_type` and land-use are descriptive labels for reporting only — never fee multipliers.
