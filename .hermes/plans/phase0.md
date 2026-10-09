# Phase 0 — Infra (completed)

**Goal:** Build database schema, PHP skeleton, and deploy workflow for MVP.

## Completed tasks

### 1. Schema (sql/schema.sql)
- Created 11 tables matching the plan
- `zones_rates` (flat, location-based: district + road_category → rate_per_sqm)
- `parcels` (parcel_no, district_id, village_id, area_sqm, road_category, status)
- `payments` (parcel_id, status, slip_no, fee_total, etc.)
- `title_scans`, `users`, `districts`, `villages`, etc.

### 2. Seed data (sql/seed_savannakhet.sql)
- Added placeholder district list (verify/replace with official PERN list)
- Added placeholder zone rates (50k/40k/30k/20k kip per m² for main/branch/alley/none)
- Added one sample admin user (password must be changed)

### 3. PHP skeleton (lib/, api/, public/)
- `lib/config.php` — environment config (DB, uploads path, timezone)
- `lib/db.php` — `db_connect()`, `json_output()`, `require_method()`, `require_auth()` helpers
- `api/fee.php` — minimal fee quote endpoint (POST district_id + road_category + area → fee_total)
- `public/index.html` — landing page linking to API docs
- `public/citizen/` and `public/admin/` directories created (ready for Phase 1–3)

### 4. Deployment workflow (.github/workflows/deploy.yml)
- GitHub Actions `Deploy` job on push to `main`
- Configurable via secrets (`VPS_HOST`, `VPS_USER`, `VPS_SSH_KEY`)
- Placeholder rsync command (fill in your server paths)

### 5. .gitignore update
- Added `uploads/` to avoid committing scanned files/photos

## Phase 1 — Scan & Registry (core completed)

### Completed Components:
- **OCR library** (`lib/ocr.php`): wraps Tesseract CLI (lao+eng, psm 6) to extract text from uploaded images.
- **Scan endpoint** (`api/scans.php`): accepts image upload, runs OCR, returns raw text and saves the image to `public/uploads/`.
- **Parcels CRUD** (`api/parcels.php`): 
  - GET: list parcels with optional filters (district_id, village_id, status)
  - POST: create a new parcel (requires parcel_no, village_id, district_id, area_sqm)
- **Reference data APIs**:
  - `api/districts.php`: list all districts
  - `api/villages.php`: list villages by district_id
  - `api/clear-scan.php`: clear OCR scan from session
- **Officer Review UI** (`public/admin/review.php`):
  - Upload a land title image → OCR extracts text → display image + OCR text side-by-side
  - Officer confirms/edits parcel data (parcel number, area, road category, status, village, district)
  - Dropdowns populate dynamically via AJAX from the districts/villages APIs
  - On confirmation: inserts a new parcel into the `parcels` table
  - After successful creation, clears the scan from session and shows success message

### Files created / modified in Phase 1:
- `lib/ocr.php`
- `api/scans.php`
- `api/parcels.php`
- `api/districts.php`
- `api/villages.php`
- `api/clear-scan.php`
- `public/admin/review.php`
- Updated `lib/config.php` to point uploads to `public/uploads/`

---

## Remaining Actions

- [ ] **Get official Savannakhet district/village list** (replace seed placeholders in `sql/seed_savannakhet.sql`)
- [ ] **Get official zone rates** (replace seed placeholders in `sql/seed_savannakhet.sql`)
- [ ] Set DB credentials as GitHub Actions secrets for deploy
- [ ] **Phase 2:** fee quote + payment slip workflow (create fee request → ISSUED → upload slip photo → officer verifies → PAID)
- [ ] **Phase 3:** Citizen PWA + reports (fee quote, request payment, pay status, receipt; officer reports by district/month)
- [ ] **Phase 4:** data import + polish (bulk CSV import, audit log)

---
**Phase 0 & 1 core complete.** Ready to replace seed placeholders with official data and proceed to Phase 2.
