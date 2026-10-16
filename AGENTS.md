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
| `sql/seed_savannakhet.sql` | 15 districts, 1,029 villages, 60 zone rates. **No login users.** |
| `lib/db.php` | PDO connect (`db_connect`) + envelope helpers `json_ok` / `json_error` |
| `lib/config.php` | DB/JWT config; loads repo-root `.env` (gitignored), env vars win |
| `lib/auth.php` | HS256 JWT (no deps); reads `Authorization: Bearer` header only |
| `lib/otp.php` | Stateless signed OTP tokens + the `otp_send_sms()` seam |
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
- **Citizens are scoped by `owners.citizen_user_id`** (not `payments.payer_owner_id`, which is an `owners.id`). A parcel links a citizen only when that column is set — officer scan-confirm never sets it, so new parcels are invisible to citizens until linked.
- **Windows PHP built-in server is single-threaded** (`PHP_CLI_SERVER_WORKERS` is Unix-only). Admin pages that call the API server-side deadlock if both share one process. For testing run the API on one port and the UI on another with `API_BASE` pointing at the API.

## Local Dev
- PHP 8.3+ built-in server from repo root: `php -S localhost:8080` with `PGHOST=localhost PGPORT=5432 PGDATABASE=landfee PGUSER=postgres PGPASSWORD=test1234 PG_SCHEMA=landfee`.
- DB: Docker `landfee-test-postgres` (postgres:16-alpine) on localhost:5432. Load `sql/schema.sql` then `sql/seed_savannakhet.sql`.
- OCR needs the `tesseract` binary with `lao` + `eng` language data and PHP GD; uploads land in `public/uploads` (gitignored).

## Demo Credentials
- **Officer:** `sav_officer` / `demo1234` (live DB only)
- **Citizen:** phone OTP — set `OTP_DEBUG=1` locally; the code is echoed in `data.debug_code`. `otp_send_sms()` in `lib/otp.php` is the (currently empty) SMS seam, so **no real SMS is sent yet** — a gateway is required before production.

## Verification (no automated tests exist)
No test suite, composer, linter, or CI checks — verify manually: start the built-in server + Docker Postgres, exercise the API with curl/JSON, and click through `/public/admin/` and `/public/citizen/`. Phases/known open items are tracked in `docs/4-plan-land-fee-mvp.md` (§14).

## Production
- **Site:** `https://landfee.apis.com.la/public/admin/` (Apache, DocumentRoot `/var/www/html/landfee`, git checkout)
- **DB:** PostgreSQL `landfee` on apis.com.la, schema `landfee`
- **Deploy:** GitHub Actions `deploy.yml` on push to `main` (needs `VPS_SSH_KEY`); restarts Apache to clear OPcache

## Fee Model (user-confirmed)
Flat, location-only: `fee = zone_rate(district, road_category) × area_sqm`.
Zone rates: main=180k, branch=140k, alley=110k, none=85k kip/m². `transaction_type` and land-use are descriptive labels for reporting only — never fee multipliers.
