# Land Fee Payment MVP — Detailed Implementation Plan

> Savannakhet Province. Status: **live on apis.com.la (Phases 0–2c done; Phase 2d polish + open items remaining).**

## 1. Goal

A scoped MVP: (1) citizen PWA to compute & pay land transaction fees via a bank/counter payment slip, (2) officer back-office for the province, (3) web backend that scans a land title, OCRs it (district/village/area) to drive a zone-based fee rate. The full legacy-system clone (LLR7) is shelved — this MVP does not replicate subdivision/merging/sync.

## 2. Locked decisions (user-confirmed)

| \# | Decision | Choice |
| - | - | - |
| 1 | Fee | Land transaction/registration fee = **zone rate × area**. Rate depends ONLY on location. Identical across transaction types & land-use classes. |
| 2 | Payment | Slip flow: `ISSUED → citizen pays at bank/counter → uploads slip photo → officer verifies → PAID` (reject returns to ISSUED). No online gateway. |
| 3 | Zone (MVP) | Coarse: **district + road\_category** (main/branch/alley/none). Officer-managed rate table in DB. No GIS polygons. |
| 4 | Mobile | PWA first (installable Android), extends the citizen-portal prototype. |
| 5 | Backend | PHP 8 + PostgreSQL (`landfee` DB, schema `landfee`), Tesseract 5.5 OCR. Deploy to apis.com.la. |
| 6 | DB name | `landfee` (user-specified). |
| 7 | Auth | Citizen: phone OTP (dev-stub for MVP). Officer: username/password. JWT (HS256, no deps). |
| 8 | Slip QR | Plain reference for MVP: `SLIP:\<slip\_no\>\\|AMT:\<amount\>` (LaoQR = later). |
| 9 | MOF integration | CSV reconciliation export only for MVP. Keep `payments.mof\_ref` for later live API. |


## 3. Architecture (3 components, 1 repo)

```
Citizen PWA (public/citizen)        Officer Back-Office (public/admin)  
  mobile-first, installable           province officers  
  lookup / quote / pay / status       login / scan-review / rates / verify / reports  
         \\                                /  
          +------ PHP 8 + PostgreSQL API (api/v1) ------+  
                   |  
        +------------+-----------------+  
        |            |                 |  
  PostgreSQL    Tesseract CLI      File store (public/uploads)  
 (registry,     (lao+eng OCR,     title scans + slip photos)  
  fees, slips)  psm 6)
```

One codebase, two front-ends, one JSON API. Same shape as the prototype but with real persistence.

## 4. Repo structure (current)

```
api/v1/\*.php      Versioned JSON API  
lib/\*.php         db, auth(JWT), validate, slip, ocr, config  
public/admin/\*    Officer back-office screens  
public/citizen/\*  Citizen PWA (incl. manifest + service worker)  
sql/              schema.sql, seed\_savannakhet.sql  
docs/             plan + spec  
archive/          legacy Land Titling prototype + LLR7 specs (not live)  
.github/workflows/deploy.yml   CI/CD → apis.com.la
```

## 5. Data model (PostgreSQL, schema `landfee`)

```
districts   (id, code, name\_lo, name\_en)  
villages    (id, district\_id, code, name\_lo, name\_en)  
zones\_rates (id, district\_id, road\_category CHECK IN(main,branch,alley,none),  
             rate\_per\_sqm, valid\_from, note, updated\_at)  
owners      (id, citizen\_id NULL, full\_name, id\_number, phone, address)  
parcels     (id, parcel\_no, sub\_parcel\_no, village\_id, district\_id, area\_sqm,  
             road\_category, status CHECK IN(draft,scanned,confirmed,active),  
             scanned\_at, ocr\_confidence, created\_at)  
title\_scans (id, parcel\_id, image\_path, ocr\_raw JSONB, ocr\_extracted JSONB,  
             verified\_by, verified\_at, note)  
users       (id, role CHECK IN(admin,officer,citizen), username, phone, password\_hash, is\_active)  
citizens    (id, owner\_id, citizen\_name, phone, id\_number)  
payments    (id, parcel\_id, citizen\_id, transaction\_type, district\_id, area\_sqm,  
             zone\_rate, fee\_total, status CHECK IN(ISSUED,PAID,REJECTED),  
             slip\_no, slip\_qr, slip\_path, paid\_at, verified\_by, verify\_note, moif\_ref)  
audit\_log   (id, actor\_id, action, entity, entity\_id, detail JSONB, at)
```

Key points:

- `payments.fee\_total = zone\_rate × area\_sqm` — flat, location-only.

- `transaction\_type` & land-use are descriptive labels only (reporting), never fee multipliers.

- `title\_scans.ocr\_extracted` keeps parsed fields JSON so officer correction is an audit trail.

- Seeded: **15 districts, 1,029 villages, 60 zone rates** (main=180k, branch=140k, alley=110k, none=85k kip/m² placeholder).

## 6. API surface — `/api/v1/` (envelope `\{ok, data|error, meta\}`)

| Endpoint | Method | Purpose | Auth |
| - | - | - | - |
| `/auth.php` (action=otp/request, otp/verify, login) | POST | OTP (citizen) / password (officer) login → JWT | public |
| `/districts.php` | GET | reference list (dropdowns) | public |
| `/villages.php` | GET | villages for a district | public |
| `/rates.php` | GET/PUT | zone-rate table edit | officer |
| `/scans.php` | POST | upload title photo → OCR → store | officer |
| `/scans/confirm.php` | POST | officer confirms/edits → creates/updates parcel | officer |
| `/parcels.php` | GET/POST | list/search/create (by id, parcel\_no) | citizen+officer |
| `/fee.php` | POST | `\{district\_id, road\_category, area\_sqm\}` → fee | public |
| `/payments.php` | POST/GET | create → ISSUED + slip; list; verify/reject | citizen+officer |
| `/payments.php?id=X` | GET | slip/receipt/detail | citizen+officer |
| `/reports.php` | GET | fees by district/month/type; CSV/JSON for MOF | admin |


## 7. Build phases (status)

| Phase | Scope | Status |
| - | - | - |
| **0 — Infra** | PostgreSQL schema + seed, PHP skeleton, CI/CD | ✅ done |
| **1 — Scan & registry** | upload+OCR endpoint, officer review UI, parcels CRUD | ✅ done |
| **2a — API conventions** | envelope, JWT, validate, PDO; auth/fee/payments/slip/reports | ✅ done |
| **2b — Officer back-office** | login, scan queue, parcel registry, rates editor, verify queue, reports | ✅ done |
| **2c — Citizen PWA** | manifest, service worker, OTP login, lookup, quote, payment, slip, tracking | ✅ done |
| **2d — Polish & ship** | deploy wiring, CSV import, audit log, SMS placeholder, E2E test | 🟡 in progress |


## 8. What is DONE (verified on apis.com.la)

- Site live at `https://landfee.apis.com.la/` (HTTPS-only, valid Let's Encrypt cert).

- Apache vhost: DocumentRoot `/var/www/html/landfee`, HTTP→HTTPS redirect, no redirect loop.

- PostgreSQL `landfee` seeded: 15 districts, 1,029 villages, 60 zone rates.

- `landfee\_web` DB role created with privileges; `lib/config.php` points to it.

- `php8.3-pgsql` installed & enabled (pdo\_pgsql + pgsql) — API connects.

- `GET /api/v1/districts.php` → 200 with JSON.

- `/public/citizen/` → 302 → login.php; `/public/admin/` → 302 → login.php.

- GitHub Actions `deploy.yml` in place and working: secret `VPS\_SSH\_KEY` added, auto-deploys code on push to `main`.

- CI/CD deploy: pulls code, chown, restarts Apache (clears OPcache). **DB changes are manual**.

- `lib/config.php` env-driven: loads optional `.env` (gitignored) so prod secrets survive `git pull`.

- Server `/var/www/html/landfee` is a proper git checkout; `.env` with prod DB credentials is in place.

- DB schema privileges granted to `landfee\_web` role.

## 9. What REMAINS (open items / Phase 2d)

1. **E2E test on live:** full chain (OTP → quote → create payment → slip → officer verify → PAID) verified on local Docker postgres; repeat against apis.com.la and fix any issues.

2. **Real zone-rate schedule** — pending central office/PERN (currently 80–180k placeholder band).

3. **SMS gateway** for OTP delivery — dev stub returns code in `meta.debug\_code`; replace with real SMS (or email) before rollout.

4. **LaoQR standard** vs plain reference slip QR — pending bank/counter channel decision.

5. **CSV bulk import** for existing paper titles → `parcels` (Phase 4 in original plan).

6. **Audit log + role enforcement** hardening (audit\_log table exists; wire key actions).

7. **District Lao-name misalignment** (codes 1311–1315 in tax\_ets source) — confirm with PERN; English names are correct.

8. **Officer password hash** on local Docker was a bad placeholder (`y0`) — real hash used on apis.com.la (`demo1234`).

## 10. Deploy / CI-CD

`.github/workflows/deploy.yml` on push to `main`:

1. SSH to apis.com.la (`apis` user, `VPS\_SSH\_KEY` secret)

2. `cd /var/www/html/landfee && git pull origin main`

3. `chown -R apis:www-data /var/www/html/landfee`

4. `systemctl restart apache2` (clear OPcache)

**DB changes** (schema/seed) are applied **manually on the server** when you decide — not in CI/CD.

## 11. Local dev

```
cd /d/LandTitling  
env PGHOST=localhost PGPORT=5432 PGDATABASE=landfee PGUSER=postgres PGPASSWORD=test1234 PG\_SCHEMA=landfee \\  
  /c/tools/php85/php -S localhost:8080  
\# DB: Docker container landfee-test-postgres (postgres:16-alpine)  
\# Officer login: sav\_officer / demo1234
```

## 12. Risks / trade-offs

- **OCR accuracy** on real Lao titles — mitigation: officer-review-then-confirm loop is mandatory; confidence score flags low-quality scans; calibrate against the prototype's printable title.

- **Placeholder zone rates** — officers must load real rates before launch; fee quote shows a "rate pending" message when a district has no rate.

- **No bank gateway** — citizen prints/QRs the slip and pays at a counter; must be clearly labelled.

- **Single-province** — province is a constant; schema is provincial-aware for later multi-province.

- **MOF/TaxRIS** — not wired in MVP (export-only); `payments.mof\_ref` reserved for later.

## 13. First actions to finish Phase 2d

1. Run E2E test against apis.com.la and fix any issues.

2. Handle the open items (real rates, SMS, LaoQR, CSV import) in priority order set by the user.

## 14. Progress Tracker (update as items close)

| \# | Item | Status | Notes |
| - | - | - | - |
| 1 | E2E test on live (apis.com.la) | ☐ pending | Run full chain: OTP → quote → payment → slip → officer verify → PAID |
| 2 | Real zone-rate schedule from PERN/central office | ☐ pending | Currently 80–180k placeholder band |
| 3 | SMS gateway for OTP (replace dev stub) | ☐ pending | Dev stub returns code in `meta.debug\_code` |
| 4 | LaoQR standard vs plain reference QR | ☐ pending | Awaits bank/counter channel decision |
| 5 | CSV bulk import for paper titles → parcels | ☐ pending | Phase 4 work |
| 6 | Audit log + role enforcement hardening | ☐ pending | audit\_log table exists, needs wiring |
| 7 | District Lao-name misalignment (1311–1315) | ☐ pending | Confirm with PERN; English names correct |
| 8 | Local Docker officer password hash fix | ☐ pending | Was placeholder `y0`; live server uses `demo1234` |
| 9 | CI/CD secret (`VPS\_SSH\_KEY`) added to GitHub | ✅ done | Auto-deploy works on push to main |
| 10 | CI/CD deploy workflow (code only, no DB) | ✅ done | Pulls code, chown, restarts Apache |
| 11 | Server git checkout + `.env` with prod creds | ✅ done | Survives git pull |
| 12 | `lib/config.php` env-driven (loads `.env`) | ✅ done | No hardcoded prod creds in repo |
| 13 | DB schema privileges for `landfee\_web` | ✅ done | Granted after schema recreate |
| 14 | Server `/var/www/html/landfee` as git checkout | ✅ done | Clean working tree, `.env` gitignored |


**Legend:** ✅ done | 🟡 in progress | ☐ pending

