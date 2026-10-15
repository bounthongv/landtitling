# Land Fee Payment MVP — AGENTS.md

## What is this?
Land Fee Payment MVP for Savannakhet Province (Lao). Two front-ends over one PHP JSON API and a PostgreSQL DB:
- **Citizen PWA** (`public/citizen/`) — mobile-first, installable: phone-OTP login, fee quote, payment slip, tracking
- **Officer Back-Office** (`public/admin/`) — scan-upload + OCR review, parcel registry, zone-rate editor, verify/reject payments, MOF reports

## Key Files
| File | Purpose |
|------|---------|
| `sql/schema.sql` | PostgreSQL schema (schema `landfee`) |
| `sql/seed_savannakhet.sql` | 15 districts, 1,029 villages, 60 zone rates |
| `lib/db.php` | PDO connect + `{ok,data,error,meta}` envelope helpers |
| `lib/config.php` | DB/JWT config (env-overridable) |
| `lib/auth.php` | HS256 JWT (no deps) |
| `lib/slip.php` | Slip number generator + A4 HTML |
| `lib/ocr.php` | Tesseract OCR (lao+eng) |
| `api/v1/*.php` | Versioned JSON API (auth, fee, payments, districts, villages, rates, parcels, scans, reports) |
| `public/admin/*.php` | Officer back-office screens |
| `public/citizen/*.php` | Citizen PWA screens + manifest + service worker |
| `.github/workflows/deploy.yml` | CI/CD → apis.com.la on push to main |
| `archive/` | Legacy Land Titling prototype + LLR7 video specs (not live) |

## Production
- **Site:** `https://landfee.apis.com.la/` (Apache on apis.com.la, `/var/www/html/landfee`)
- **DB:** PostgreSQL `landfee` on apis.com.la, schema `landfee`
- **Deploy:** GitHub Actions `deploy.yml` on push to `main` (needs `VPS_SSH_KEY` secret)

## Local Dev
- PHP built-in server: `php -S localhost:8080` (env: `PGHOST=localhost PGPORT=5432 PGDATABASE=landfee PGUSER=postgres PGPASSWORD=test1234 PG_SCHEMA=landfee`)
- DB: Docker `landfee-test-postgres` (postgres:16-alpine) on localhost:5432

## Demo Credentials
- **Officer:** `sav_officer` / `demo1234`
- **Citizen:** phone OTP (dev stub returns code in JSON `meta.debug_code`)

## Fee Model (user-confirmed)
Flat, location-only: `fee = zone_rate(district, road_category) × area_sqm`.
Zone rates: main=180k, branch=140k, alley=110k, none=85k kip/m². No land_use or transaction_type in fee math.
