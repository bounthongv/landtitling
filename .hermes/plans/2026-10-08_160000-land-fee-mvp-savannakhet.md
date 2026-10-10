# Land Fee Payment MVP — Savannakhet Province

> **For Hermes:** Use subagent-driven-development skill to implement this plan task-by-task.

**Goal:** A scoped MVP for Savannakhet Province: (1) citizen PWA to compute & pay land
transaction fees via a bank/counter payment slip, (2) officer back-office for the province,
(3) web backend that accepts a scanned land title and OCRs it (province/district/village,
area) to drive the zone-based fee rate. The full legacy-system clone (LLR7 workflows) is
shelved for later — this MVP does NOT replicate subdivision/merging/sync.

## Context & assumptions

- Customer = Savannakhet Province only (1 province). No written requirements; scope pinned
  in a Q&A round.
- **No land-title database exists yet** — the registry is built by scanning titles, so the
  scan/OCR + officer-review loop IS the data-entry system, not a side feature.
- Fee model confirmed: **flat land transaction/registration fee, `fee = zone rate × area`,
  rate depends ONLY on location (zone)**. The rate is identical across transaction types
  (transfer / inheritance / new title) and land-use classes. Matches legacy EP6 logic,
  coarse version.
- Payment: NO online gateway, NO bank API. Slip flow (user's standard edoc flow):
  `ISSUED → citizen pays at bank/counter → uploads slip photo → officer verifies → PAID`
  (reject_payment returns to ISSUED).
- Zone (MVP, coarse): zone = **district + road category** (main road / branch / alley).
  Officer-managed rate table in DB. No GIS polygons in MVP (that is the later "legacy
  phase").
- Mobile: PWA first (installable Android), extends the existing citizen-portal prototype;
  no Play Store, no APK.
- Backend: **PHP 8 + MySQL** on user's proven infra (apis.com.la or a DigitalOcean
  droplet), existing CI/CD (GitHub Actions, typed-PROD gate). Tesseract 5.5 OCR via CLI.

## Decisions (locked)

| # | Decision | Choice |
|---|---|---|
| 1 | Fee collected | Land transaction/registration fee = zone rate × area (transfer / inheritance / new title) |
| 2 | Payment | Slip flow: issue slip → pay at bank/counter → upload slip photo → officer verifies → PAID |
| 3 | Zone | Coarse: district + road category; rate table managed by officers in DB; no GIS |
| 4 | Mobile | PWA (installable), built on the existing citizen portal |
| 5 | Hosting | PHP 8 + MySQL on user's infra; CI/CD per php-apps-sync-workflow |

## Architecture (3 components, 1 repo)

```
Citizen PWA (public/citizen)          Officer Back-Office (public/admin)
   Android home-screen app                  Province officers
   scan title / enter fee / pay slip   login (OTP or acct) / verify / reports
          \                                      /
           +------ PHP 8 + MySQL API (web/) ------+
                    |
        +------------+-----------------+
        |            |                 |
   MySQL DB    Tesseract CLI      File store
 (registry,     (lao+eng OCR,     (title scans,
  fees, slips)  psm 6)            slip photos)
```

- One codebase, two front-ends (PWA + admin), one JSON API layer — same shape as the
  prototype (officer back-office + citizen portal) but with real persistence behind it.
- OCR is a thin PHP service wrapping `tesseract` CLI (proven reliable on Windows/Linux),
  not a new microservice.
- Legacy LLR7 workflow docs (`docs/llr7-spec/`, SRS v1.0) stay in the repo as the
  reference for the later full phase — this MVP deliberately covers a subset:
  "fee + payment" (EP6/EP9 fee portions) and "registry data entry".

## MVP scope

**IN:**
- Scan/upload a land title photo → OCR extracts title no., district, village,
  parcel no., area, use type, owner name → officer confirms/edits → parcel stored.
- Fee estimate (zone = district + road category × area, by transaction type).
- Payment slip: QR + printable slip, `ISSUED → PAID/REJECTED` lifecycle with slip
  photo verification by officer.
- Officer back-office: login/roles, title-scan review queue, rate-table admin,
  payment-verification queue, basic reports (fees by district/month), data import.
- Citizen PWA: title lookup by no., fee quote, request payment, pay status, receipt.

**OUT (explicit):**
- Online bank gateway, MOF/TaxRIS API integration (reconciliation export only; see
  open questions), GIS polygon zones, full registry workflows (transfer/subdivision/
  merging/mortgage), field sync, SMS (kept as a toast/placeholder like the prototype).

## Data model (MySQL)

Savannakhet is single-province, so `province` is a constant column, not a table.
Savannakhet has ~15–20 districts (confirm list with PERN) and their villages.

```
districts      (id, code, name_lo, name_en)
villages       (id, district_id, code, name_lo, name_en)
zones_rates    (id, district_id, road_category ENUM('main','branch','alley','none'),
                 rate_per_sqm DECIMAL(12,2), valid_from DATE, note, updated_at)
owners         (id, citizen_id NULL, full_name, id_number, phone, address, created_at)
parcels        (id, parcel_no, sub_parcel_no NULL, village_id, district_id,
                 area_sqm DECIMAL(12,2), road_category,
                 status ENUM('draft','scanned','confirmed','active'),
                 scanned_at, ocr_confidence INT, created_at)
title_scans    (id, parcel_id, image_path, ocr_raw JSON, ocr_extracted JSON,
                 verified_by, verified_at, note, created_at)
users          (id, role ENUM('admin','officer','citizen'), username, phone,
                 password_hash, is_active)
citizens       (id, owner_id, citizen_name, phone, id_number, created_at)
payments       (id, parcel_id, citizen_id, transaction_type ENUM('transfer','inheritance','new_title'),
                 district_id, area_sqm, zone_rate DECIMAL(12,2), fee_total DECIMAL(12,2),
                 status ENUM('ISSUED','PAID','REJECTED'),
                 slip_no, slip_qr, slip_path, paid_at, verified_by, verify_note,
                 moif_ref NULL, created_at)
audit_log      (id, actor_id, action, entity, entity_id, detail JSON, at)
```

Key points:
- `payments` is the fee-transaction record; `slip_no` + `slip_qr` power the printable slip
  (QR encodes `slip_no` + amount — a "Lao QR"-style reference for bank-counter staff).
- `title_scans.ocr_extracted` keeps the parsed fields JSON so the officer's correction
  is an audit trail, not a loss of the original.
- `parcels` carries `road_category` (feeds the zone lookup). OCR'd land-use/owner fields
  are kept in `title_scans.ocr_extracted` JSON for the record — they do not affect the
  flat fee. `payments.transaction_type` is a descriptive label only (reporting), never a
  fee multiplier.

## API surface (JSON, under /api/)

| Endpoint | Method | Purpose |
|---|---|---|
| `/api/auth/otp/request` `/verify` | POST | phone+OTP login for officers/citizens (or email login MVP shortcut) |
| `/api/scans` | POST (multipart) | upload title photo → run OCR → return extracted fields |
| `/api/parcels` CRUD | | list/filter, create from scan, update, confirm |
| `/api/parcels/{id}` | GET | parcel detail + history |
| `/api/rates` | GET/PUT | list/update zone rate table (admin) |
| `/api/fee/quote` | POST | {district, road_category, area} → fee (flat rate × area) |
| `/api/payments` | POST/GET | create fee request → ISSUED; list for citizen |
| `/api/payments/{id}/slip` | GET | printable slip HTML/PDF (QR) |
| `/api/payments/{id}/receipt` | POST | citizen uploads slip photo |
| `/api/payments/{id}/verify` `/{id}/reject` | POST | officer verifies → PAID / REJECTED |
| `/api/reports/fees` | GET | aggregate by district/month/transaction_type |

Auth: session/JWT per user's preference (edoc uses plain-plain with OTP; simplest:
email+password for officers, phone OTP for citizens). Keep it consistent with edoc's
OTP model.

## Build phases (each phase = a working increment)

### Phase 0 — Infra (0.5 day)
- MySQL schema + seed: `districts`/`villages` for Savannakhet (fill from PERN/official
  list), sample `zones_rates` (placeholder rates, officers edit later), `users`.
- PHP 8 app skeleton (plain PHP, no framework — matches user's stack): `web/` (public),
  `api/`, `lib/` (ocr, auth, db), `public/citizen` (PWA), `public/admin` (back-office),
  `uploads/`.
- CI/CD via existing GitHub Actions (php-apps-sync-workflow) — dev deploy automatic,
  prod typed gate.

### Phase 1 — Scan & registry (2–3 days)
- Upload title photo endpoint; Tesseract CLI pipeline (lao+eng, psm 6, 300dpi) inside
  `lib/ocr.php`; JSON-extracted fields (title no., district, village, parcel no., area,
  use type, owner) → `title_scans.ocr_extracted`.
- Officer review UI: show OCR result side-by-side with image, officer confirms/edits →
  creates/updates `parcels` (status `confirmed`). Unconfirmed parcels stay `scanned`.
- Officer list/filter screen with OCR confidence sort.
- **Validation:** run OCR against the printable title we already made in the prototype
  (the realistic official Lao land title) — that is the ground-truth sample to calibrate
  the extraction (regex + label matching) against.

### Phase 2 — Fee + payment slip (2 days)
- Fee quote API + PWA quote screen (pick district, road category, area).
- Payment request → `ISSUED`, generates `slip_no` + QR; printable slip (A4, bilingual
  Lao/EN, amount + QR + payer + parcel ref).
- Citizen uploads slip photo → officer verify/reject → `PAID`/`REJECTED`.
- **Validation:** end-to-end flow with a test parcel; print the slip; confirm the QR
  encodes the slip no.

### Phase 3 — Citizen PWA + reports (1–2 days)
- PWA manifest + offline shell on `public/citizen` (installable).
- Citizen screens: title lookup by no., fee quote, request payment, pay status, receipt
  (printable).
- Officer reports: fees by district/month/type; CSV export for reconciliation.
- **Validation:** install PWA on an Android device (home screen), walk through a full
  fee+payment cycle on real data.

### Phase 4 — Data import + polish (1 day)
- Bulk import (CSV) for existing paper titles → `parcels`.
- SMS/notification placeholders (toast in PWA like prototype), role enforcement, audit log.

## Tech stack summary
- PHP 8.1/8.3, MySQL 8, Tesseract 5.5 CLI (lao+eng)
- PWA = plain HTML/CSS/JS (no framework), offline-first shell, manifest for Android install
- Admin = plain HTML/CSS/JS (matches the existing prototype's look & feel)
- CI/CD: GitHub Actions (dev auto, prod typed gate), PHP 8 on user's infra
- No gateway, no GIS, no SMS (placeholder)

## Risks / trade-offs
- **OCR accuracy on real titles:** Tesseract on Lao is decent but not perfect. Mitigation:
  officer-review-then-confirm loop (Phase 1) is mandatory, not optional; confidence score
  flags low-quality scans. Calibrate with the prototype's printable title sample.
- **Placeholder zone rates:** officers must fill real rates before launch. Seed with
  zero/placeholder + a "rate pending" message in fee quote when a district has no rate.
- **No bank gateway:** citizen experience = print/QR slip → go to counter. This is
  acceptable (user's edoc flow) but must be clearly labelled "bank/counter payment".
- **Single-province:** province column constant; if PERN later wants multi-province,
  `province_id` column + migration (schema is already provincial-aware).
- **MOF/TaxRIS:** NOT wired in MVP (see open questions). Keep `payments.mof_ref` for
  later reconciliation; if a real TaxRIS integration is required, that is a separate
  phase with its own plan.

## Open questions (answer before/during Phase 0)
1. **MOF integration depth:** MVP = reconciliation export only (CSV of payments), or does
   PERN require a live TaxRIS API? (I assume export-only for MVP; live = later phase.)
2. **Rate authority:** who sets `zones_rates` — PERN? District? How often, what approval?
   (MVP = officer-editable table with audit log; PERN confirms values.)
3. **Real Savannakhet district/village list + zone-rate schedule** — PERN to provide,
   or is it on PERN's website? (Needed to seed the DB; without it we use placeholders.)
4. **Payment channel naming:** bank / post office / e-wallet counter? (Affects slip text.)
5. **Citizen identity:** phone-only (OTP) or also national ID? (MVP = phone OTP;
   national-ID field is optional in the form.)

## File map (what gets created)
```
web/                    # PHP API + admin PWA entry points
api/scans.php           # upload+OCR endpoint
api/parcels.php        # CRUD
api/fee.php            # quote
api/payments.php       # create/slip/receipt/verify/reject
api/auth.php           # OTP/email login
lib/db.php, ocr.php, auth.php, slip.php (QR + printable)
public/citizen/        # PWA (index.html, manifest.json, app.js) — extends prototype
public/admin/          # back-office (login, scan-queue, verify-queue, rates, reports)
uploads/               # title scans + slip photos (on-disk, not git)
sql/schema.sql, sql/seed_savannakhet.sql
docs/plan-land-fee-mvp.md   # THIS FILE
```

## First action
Answer the 5 open questions (or let me proceed with the stated assumptions), then I
execute Phase 0 (schema + skeleton + CI/CD wiring) and commit. Phases 1–3 follow in
sequence, each a working increment you can demo.
