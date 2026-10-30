# Land Fee — Annual Fee + Back-Office Design (v3)

> Savannakhet Province. **Status:** design for review — supersedes the fee/payment parts of
> `4-plan-land-fee-mvp-v2.md`. No implementation yet.
>
> **Purpose of this project (restated):** let provincial officers scan existing land titles, OCR
> and **parse every field** into the database per parcel (location: province/district/village,
> area, owner name + residence, title metadata, …), compute the **annual** land fee from a
> location-based flat rate, and let citizens pay yearly (separate mobile app). Officers verify
> payments (auto where possible) and run reports.

## 0. Locked decisions (this round)

| # | Decision | Choice |
|---|----------|--------|
| 1 | Fee model | **Annual recurring fee replaces the one-off transaction fee.** `fee = zone_rate(district, road_category) × area_sqm`, per parcel, per year. |
| 2 | Auto-verification source | **Bank statement file (CSV/Excel) with references.** Import + auto-match reference & amount; manual override always available. LaoQR/webhook = later. |
| 3 | Slip reference | **Plain human-typable reference now** (`LFE-{year}-{seq}`); swap to LaoQR standard later. |
| 4 | Title parsing | Parse **all** title fields, not just fee inputs. Never auto-commit — officer confirms. |

---

## 1. Current state — verified gaps (must fix before anything is testable)

The UI pages exist but the scan → parcel → fee → verify chain **does not run end-to-end**.
Verified in-repo (schema vs code disagree — confirm against the live DB too):

| # | Where | Problem |
|---|-------|---------|
| 1 | `api/v1/scans.php:65` | Inserts `created_by` into `title_scans` — column does **not** exist (`sql/schema.sql:77`). Also passes a PHP array into JSONB. |
| 2 | `api/v1/parcels.php:39` | `ORDER BY p.updated_at` — `parcels` has no `updated_at`. |
| 3 | `public/admin/scans.php:76` | Upload form has **no server handler**; the POST branch only handles `confirm_parcel`. |
| 4 | `api/v1/scans/confirm.php:11` | Reads `$_GET['id']`, but admin calls `scans/{id}/confirm.php` and there is **no `.htaccess`** → id always 0. |
| 5 | `public/admin/index.php:50`, `scans.php:173` | JS uses relative `api/v1/...` → resolves to `/public/admin/api/v1/...` → 404. |
| 6 | `public/admin/rates.php:30` | Always PUTs `id=0` → "Rate id required". No create-row path. |
| 7 | `api/v1/reports.php` / `admin/reports.php` | Admin re-emits CSV with `header()` **after** HTML output. |
| 8 | `public/citizen/payments.php:8` | Calls `payments.php` with **no Bearer token** → 401. Also links to `/payment-view.php` (wrong path). |
| 9 | `public/admin/review.php` | Orphan page; bypasses the API, calls dead root `api/*` stubs, uses stale `$_SESSION['current_scan']`. |
| 10 | `lib/slip.php:9` | `slip_generate_no()` uses `COUNT(*)+1` → collisions/races. |

**Implication:** Phase A below (fixes) is a prerequisite for testing every later phase.

---

## 2. Target data model (schema v2)

Schema lives in PostgreSQL schema `landfee` (`search_path`). Migrations are **manual on the
server**. Two ways to land this:

- **Preferred while there is no real parcel/payment data:** update `sql/schema.sql` (it already
  `DROP SCHEMA … CASCADE`) and re-apply `schema.sql` + `seed_savannakhet.sql`.
- **If live data must survive:** ship additive files under `sql/migrations/` (e.g.
  `001_parcels_title_fields.sql`) and apply by hand.

Below is the target shape. `zones_rates`, `districts`, `villages` are unchanged except as noted.

### 2.1 Reference/lookup tables (replace hardcoded lists)

```sql
CREATE TABLE road_categories (
  code VARCHAR(10) PRIMARY KEY,
  name_lo VARCHAR(50) NOT NULL,
  name_en VARCHAR(50) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0
);
INSERT INTO road_categories (code, name_lo, name_en, sort_order) VALUES
  ('main','ຖະໜົນຫຼັກ','Main road',1),
  ('branch','ຖະໜົນສາຂາ','Branch road',2),
  ('alley','ຊອຍ','Alley',3),
  ('none','ບໍ່ມີ','None',4);

CREATE TABLE title_types (
  code VARCHAR(30) PRIMARY KEY, name_lo VARCHAR(80) NOT NULL, name_en VARCHAR(80) NOT NULL
);
CREATE TABLE land_use_classes (
  code VARCHAR(30) PRIMARY KEY, name_lo VARCHAR(80) NOT NULL, name_en VARCHAR(80) NOT NULL
);
```

> `parcels.road_category` / `zones_rates.road_category`: drop the inline `CHECK` and add FK
> `REFERENCES road_categories(code)` so labels/order have one home.

### 2.2 Parcels — full title content

```sql
CREATE TABLE parcels (
  id SERIAL PRIMARY KEY,
  parcel_no VARCHAR(50) NOT NULL,
  sub_parcel_no VARCHAR(20),
  province VARCHAR(100) NOT NULL DEFAULT 'Savannakhet',   -- future multi-province
  district_id INT NOT NULL REFERENCES districts(id),
  village_id  INT NOT NULL REFERENCES villages(id),
  area_sqm DECIMAL(14,2) NOT NULL DEFAULT 0,
  road_category VARCHAR(10) REFERENCES road_categories(code),
  -- title metadata parsed from the scan
  title_no VARCHAR(50),
  title_type VARCHAR(30) REFERENCES title_types(code),
  book_no VARCHAR(30),
  volume_no VARCHAR(30),
  page_no VARCHAR(30),
  land_use_class VARCHAR(30) REFERENCES land_use_classes(code),
  issued_date DATE,
  geo_lat DECIMAL(9,6),
  geo_lng DECIMAL(9,6),
  status VARCHAR(20) NOT NULL DEFAULT 'draft'
    CHECK (status IN ('draft','scanned','confirmed','active','archived')),
  scanned_at TIMESTAMP,
  ocr_confidence INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (district_id, village_id, map_ref_no, parcel_no, sub_parcel_no)   -- duplicate guard (migration 013)
);
CREATE INDEX idx_parcels_parcel_no ON parcels (parcel_no);
```

### 2.3 Owners — actually used, linked to parcels and citizen logins

```sql
CREATE TABLE owners (
  id SERIAL PRIMARY KEY,
  full_name VARCHAR(200) NOT NULL,
  id_number VARCHAR(50),
  id_type VARCHAR(30),                       -- national_id / passport / family_book
  phone VARCHAR(20),
  address TEXT,                              -- residence (from the title)
  village_id  INT REFERENCES villages(id),
  district_id INT REFERENCES districts(id),
  owner_type VARCHAR(20) NOT NULL DEFAULT 'individual'
    CHECK (owner_type IN ('individual','organization','joint')),
  citizen_user_id INT REFERENCES users(id),  -- link to a citizen login, if any
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- co-ownership: a parcel may have several owners
CREATE TABLE parcel_owners (
  parcel_id INT NOT NULL REFERENCES parcels(id) ON DELETE CASCADE,
  owner_id  INT NOT NULL REFERENCES owners(id)  ON DELETE CASCADE,
  share_pct DECIMAL(5,2),
  role VARCHAR(20) NOT NULL DEFAULT 'owner'
    CHECK (role IN ('owner','co_owner','representative')),
  PRIMARY KEY (parcel_id, owner_id)
);
```

> Retire the unused `citizens` table (replaced by `owners.citizen_user_id`).

### 2.4 Title scans — a first-class OCR job

```sql
CREATE TABLE title_scans (
  id SERIAL PRIMARY KEY,
  parcel_id INT REFERENCES parcels(id) ON DELETE SET NULL,
  image_path VARCHAR(255) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'uploaded'
    CHECK (status IN ('uploaded','ocr_done','parsed','confirmed','rejected')),
  ocr_engine VARCHAR(30),                    -- 'tesseract 5.x lao+eng'
  ocr_raw TEXT,                              -- full OCR text
  ocr_words JSONB,                           -- word boxes for the image viewer
  parsed_fields JSONB,                       -- structured extraction (§3)
  field_confidence JSONB,                    -- per-field confidence
  overall_confidence INT,
  created_by  INT REFERENCES users(id),
  verified_by INT REFERENCES users(id),
  verified_at TIMESTAMP,
  note TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### 2.5 Annual fee — explicit obligation layer

The yearly bill is a distinct object from a payment. **Snapshot** rate/area on the obligation so
editing rates later never rewrites history.

```sql
CREATE TABLE fee_years (
  year INT PRIMARY KEY,
  due_date DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open'
    CHECK (status IN ('draft','open','closed')),
  note TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE fee_obligations (
  id SERIAL PRIMARY KEY,
  parcel_id INT NOT NULL REFERENCES parcels(id) ON DELETE CASCADE,
  fee_year  INT NOT NULL REFERENCES fee_years(year),
  district_id INT NOT NULL REFERENCES districts(id),
  road_category VARCHAR(10) REFERENCES road_categories(code),
  area_sqm     DECIMAL(14,2) NOT NULL,       -- snapshot
  rate_per_sqm DECIMAL(12,2) NOT NULL,       -- snapshot
  amount_due   DECIMAL(14,2) NOT NULL,       -- snapshot = rate_per_sqm * area_sqm
  amount_paid  DECIMAL(14,2) NOT NULL DEFAULT 0,
  penalty      DECIMAL(14,2) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'UNPAID'
    CHECK (status IN ('UNPAID','PARTIAL','PAID','OVERPAID','WAIVED','REFUNDED','DISPUTED')),
  due_date DATE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (parcel_id, fee_year)
);
CREATE INDEX idx_obl_year_status ON fee_obligations (fee_year, status);
```

### 2.6 Payments — a settlement against an obligation

```sql
CREATE TABLE payments (
  id SERIAL PRIMARY KEY,
  obligation_id INT REFERENCES fee_obligations(id),
  parcel_id INT NOT NULL REFERENCES parcels(id),
  fee_year  INT NOT NULL,
  payer_owner_id INT REFERENCES owners(id),
  method VARCHAR(20) NOT NULL DEFAULT 'counter'
    CHECK (method IN ('counter','bank_transfer','mobile','cash')),
  amount DECIMAL(14,2) NOT NULL,
  reference_no VARCHAR(50) UNIQUE NOT NULL,  -- printed on the slip, used for matching
  bank_ref VARCHAR(100),                     -- reference seen on the bank statement
  slip_qr VARCHAR(255),
  slip_path VARCHAR(255),                    -- uploaded slip photo
  status VARCHAR(20) NOT NULL DEFAULT 'ISSUED'
    CHECK (status IN ('ISSUED','PAID','REJECTED','REFUNDED')),
  paid_at TIMESTAMP,
  verified_by INT REFERENCES users(id),      -- NULL = system (see verify_method)
  verify_method VARCHAR(20)                      -- manual | auto_statement | auto_ocr
    CHECK (verify_method IN ('manual','auto_statement','auto_ocr')),
  verify_note TEXT,
  bank_import_id INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### 2.7 Bank statement import (auto-verification)

```sql
CREATE TABLE bank_imports (
  id SERIAL PRIMARY KEY,
  filename VARCHAR(255) NOT NULL,
  imported_by INT REFERENCES users(id),
  row_count INT NOT NULL DEFAULT 0,
  matched_count INT NOT NULL DEFAULT 0,
  imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE bank_transactions (
  id SERIAL PRIMARY KEY,
  import_id INT NOT NULL REFERENCES bank_imports(id) ON DELETE CASCADE,
  txn_date DATE,
  amount DECIMAL(14,2) NOT NULL,
  reference_no VARCHAR(50),                  -- parsed from statement
  bank_ref VARCHAR(100),
  raw_line TEXT,
  matched_payment_id INT REFERENCES payments(id),
  match_status VARCHAR(20) NOT NULL DEFAULT 'unmatched'
    CHECK (match_status IN ('unmatched','matched','duplicate','partial','ignored')),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

`audit_log` (already present) stays; every auto-action and officer correction writes a row.

---

## 3. OCR → structured title extraction

Replace the current "OCR text + one regex hint" with a real pipeline.

### 3.1 Pipeline

1. **Capture** — single or batch upload; store raw image; record officer + district office.
2. **Pre-OCR** (`ocr_prepare()` in `lib/ocr.php`): rotate by JPEG EXIF orientation (GD ignores
   EXIF, so phone photos would otherwise be cropped sideways), upscale toward 3000 px on the long
   edge, grayscale. **No contrast boost**: `IMG_FILTER_CONTRAST` with a negative argument *reduces*
   contrast in GD; measured on real titles it cost ~8 points of mean word confidence and two
   ground-truth tokens versus grayscale alone.
3. **OCR** — Tesseract `lao+eng --psm 3` with the TSV sidecar. Lao alone misses the Latin serials
   printed on the form (`A250-038`, `17 JUL 2026`); measured on three real titles lao+eng raised
   mean confidence by 2–5 points with no Lao recall loss. Full text → `title_scans.ocr_raw`, word
   boxes (with Tesseract's line ids) → `title_scans.ocr_words`.
4. **Parse** (`lib/title_parser.php`):
   - `title_parse_text()` — anchored Lao/EN labels + regex over the full text: title no, location
     code, parcel no, area, book/leaf, map sheet, scale, issue date, district/village names.
   - `title_extract_word_fields()` — **label-anchored read of the word boxes**: find each field's
     printed label among the recognised words, then take the words to its right on the same
     Tesseract line, stopping at the next known label or a wide column gap. Layout-independent
     (two certificate layouts are in circulation), needs no positional template and no second OCR
     pass. The longest matching label wins, so `ຕອນດິນເລກທີ` is never read as the title number.
   - The two results are merged by per-field confidence; `district_name`/`village_name` are resolved
     to ids (pg_trgm, PHP fallback) but only trusted at ≥ 90 — below that the officer picks it.
     Names are compared on a normalised key (`title_name_key()`): whitespace, Lao tone marks and
     `ຳ`/`ໍາ` spelling variance are ignored, because OCR splits and re-spells names freely
     (`ນໍ້າອ່າງ` vs the stored `ນ້ຳອ່າງ`); the stored name is never rewritten.
5. **Review & confirm** — officer sees image ⇄ parsed fields side by side, corrects, confirms.
   Confirm creates/updates the parcel + owner(s) and links the scan. Corrections are audit
   signal (future tuning).

**Measured limits (Oct 2026, three real titles).** A 924×1280 portrait scan yields 9 of 10 printed
fields (title no, location code, parcel, area, book, leaf, map sheet, district, village; the scale
prints as `ນອດ00` and is the only miss) and resolves both district and village ids automatically. A
637×871 portrait JPEG reads the printed location code, parcel, area, leaf, map sheet, scale and
village, but its district line is garbled (`ນະຄູຄູນ` for `ນະຄອນ ຫຼວງພະບາງ`) and is therefore
left for the officer. **Handwritten fields are never expected**: on that certificate the title no,
book no and issue date are filled in by pen and no engine reads them. A 967×742 landscape
*screenshot* with red pen markup yields almost nothing — below ~1000 px the field rows are only a
few pixels tall, so that is a capture problem, not a parser one. Uploads under 500 px on the long
edge are rejected (`image_too_small`) rather than stored as noise.

### 3.2 Lao label dictionary (from the printed certificate)

| Field | Printed label |
|-------|---------------|
| Title no | ເລກທີ |
| Issue batch | ອອກຄັ້ງທີ |
| Land location code | ທີ່ຕັ້ງຕອນດິນ |
| Province / district / village / unit | ແຂວງ / ເມືອງ / ບ້ານ / ໜ່ວຍ |
| Owner / DOB / nationality / occupation | ອອກໃຫ້ແກ່ / ວັນເດືອນປີເກີດ / ສັນຊາດ / ອາຊີບ |
| Acquisition / ownership status | ການໄດ້ມາ… / ຖານະ… |
| Land-use class / zone | ປະເພດທີ່ດິນ / ເຂດ |
| Book / leaf | ເຫຼັ້ມທີ / ໃບທີ |
| Map sheet | ແຜນທີ່ດິນເລກທີ |
| Parcel no | ຕອນດິນເລກທີ |
| Area / scale | ເນື້ອທີ່ (…ຕາແມັດ) / ມາດຕາສ່ວນ |
| Issued at / date | ອອກໃຫ້ທີ່ / ວັນທີ … ເດືອນ … ປີ … |

> OCR drops or swaps the tone marks constantly (`ຕອນດິນເລກທິ`, `ແຜນທິຕາດິນເລກທ໌`), so the
> matcher compares consonant skeletons and tolerates one wrong character in a long label.

### 3.3 New API/changed endpoints

| Endpoint | Change |
|----------|--------|
| `POST /api/v1/scans.php` | Fix insert; store `ocr_raw`/`ocr_words`/`parsed_fields`/`field_confidence`; status `parsed`. |
| `GET /api/v1/scans.php?status=…` | Fix pending filter (currently joins parcel ids to scan ids). |
| `GET /api/v1/scans.php?id={id}` | New: fetch one scan for review. |
| `POST /api/v1/scans.php?id={id}/confirm` | **Query-string** id, not path segment (no rewrite). Creates parcel + owners. |
| `POST /api/v1/owners.php` | New: create/update owner + link to parcel. |
| `GET /api/v1/parcels.php?id={id}` | Add owners, title fields, obligations to detail response. |

---

## 4. Annual fee lifecycle

1. Officer defines a **fee year** (`fee_years`) with a due date.
2. **Generate obligations**: for each `active` parcel, snapshot `road_category`, `area_sqm`, and
   the effective `zone_rate(district, road_category)` → `amount_due`. Idempotent per
   `(parcel_id, fee_year)`.
3. Officer may adjust a parcel's location/zone/area → **recompute the current-year obligation**
   with a note (audited). Past-year obligations are immutable.
4. Citizen PWA (separate) creates a payment (ISSUED) with a `reference_no`; pays at bank/counter.
5. Bank statement import → auto-match (§5) → obligation → `PAID`. Manual verify/reject otherwise.
6. Partial/over-payment: obligation becomes `PARTIAL`/`OVERPAID`; multi-year settlement allowed.

---

## 5. Auto-verification via bank statement (build now)

**Reference format:** `LFE-{year}-{seq6}` (e.g. `LFE-2026-000123`), unique, printed on the slip
(matching dec. #3). Replaces the collision-prone `COUNT(*)+1` scheme.

**Flow**

1. Officer imports a CSV/Excel statement → `bank_imports` + `bank_transactions`
   (column mapping configurable: date, amount, reference).
2. Normalize and match each unmatched transaction:
   - exact `reference_no` **and** `amount == amount_due` → **matched**;
   - exact ref **and** `amount > due` → matched, obligation `OVERPAID`;
   - exact ref **and** `amount < due` → `partial`;
   - no ref, but amount matches exactly one pending payment same date → **candidate** (manual);
   - ref already used → **duplicate** (never auto-apply).
3. On match: set `payments.status='PAID'`, `paid_at`, `verify_method='auto_statement'`,
   `bank_import_id`; update `fee_obligations.amount_paid` + status; write `audit_log`.
4. **Idempotency:** `payments.reference_no` UNIQUE; `bank_transactions.match_status` guards
   re-imports.
5. Unmatched/partial/duplicate rows stay in the officer queue with a manual verify/reject override.

**Later:** swap plain reference for a LaoQR/webhook (decision #3) — same matching contract, just a
different source of the credit event.

---

## 6. Reports

| Report | Contents |
|--------|----------|
| Collection | kip collected by district / village / month / fee_year / road_category |
| Arrears & aging | parcels `UNPAID`/`PARTIAL` for year N; how many years behind per parcel |
| Data entry progress | parcels registered per district; scans pending; OCR confidence distribution |
| Parcel & owner register | full export (CSV) of parcels + owners + title fields |
| Bank reconciliation | matched / unmatched / duplicate statement rows; totals vs collected |
| Rate schedule | current + historical `zones_rates` (valid_from) |
| Audit trail | `audit_log` filtered by actor/action/date |

MOF CSV stays, re-based on `payments` + `fee_obligations`.

---

## 7. Officer back-office UX additions

- **Scan review**: batch upload; image ⇄ parsed fields with confidence flags; correct → confirm.
- **Parcel detail**: title fields, owners (add/edit, co-ownership), location/zone, **annual
  obligation history** (per year, paid/unpaid), scan image.
- **Bulk zone assignment**: set `road_category` for many parcels at once (avoid editing 1,000
  parcels individually).
- **Payment verify queue**: show slip photo, reference, auto-match status; verify/reject/waive.
- **Rate editor**: fix the PUT id bug + add create-row.

---

## 8. Phased PR plan

> Each PR is independently deployable (CI = code only; DB changes applied manually). Fix broken
> chain first so later phases are testable.

### Phase A — Foundation: schema v2 + repair the chain
- **A1** ✅ done — Schema v2: reference tables, parcels title fields + `updated_at` + unique guard,
  owners + `parcel_owners`, `title_scans` v2, annual obligations, settlements. `sql/schema.sql`
  rewritten; additive `sql/migrations/001_annual_fee_v2.sql` added (validated on Docker Postgres:
  fresh apply + v1→migration + re-run all pass; preserves districts/villages/zones_rates/users).
- **A2** ✅ done — Scan chain repaired & verified end-to-end (upload+OCR → review → confirm →
  parcel) against Docker Postgres through the admin UI: `POST scans.php`, `GET scans.php?id`,
  confirm by query-string id, admin `api_upload()`, absolute JS API paths, `rates.php` PUT upsert.
  Fixed `lib/config.php` uploads path (missing trailing slash wrote files to `public/uploadsscan_*`
  while the DB stored `/public/uploads/…`, so images were unreachable); added `API_BASE`/`UPLOADS`
  env overrides for local dev/testing.
- **A3** ✅ done — Deleted the orphan `public/admin/review.php` and the dead root `api/*.php` stubs
  (`villages`, `fee`, `districts`, `clear-scan`, `parcels`, `scans`); `api/` now contains only `v1/`.

### Phase B — Title extraction
- **B1** ✅ done — `lib/title_parser.php` (`title_parse_text`, `title_resolve_district/village`,
  `title_normalize_digits/number`, `title_similarity`, `title_overall_confidence`); `lib/ocr.php`
  reworked: `ocr_prepare()` (grayscale/upscale/contrast) + TSV word extraction
  (`ocr_extract_from_image`/`ocr_parse_tsv` → boxes + conf + reconstructed text). Upload
  (`api/v1/scans.php`) now runs the parser and stores `ocr_words`/`parsed_fields`/`field_confidence`/
  `overall_confidence`, status `parsed`. Verified: synthetic Lao title resolves real district/village
  ids (area `1.250,50`→1250.5, date `12/03/2021`→2021-03-12, type/use/owner); messy sample → clean
  empty fields (no false positives after requiring digits in number fields).
- **B2** ✅ done — `public/admin/scans.php` review UI: side-by-side image + OCR text + parsed-fields
  table with confidence badges, extended confirm form (parcel + title metadata + issued_date), owners
  repeater. `api/v1/scans/confirm.php` persists title metadata (FK-guarded) + owners
  (`owners`/`parcel_owners`, ON CONFLICT) with `23505`→409 duplicate guard; `api/v1/owners.php` added
  (GET by id/parcel_id, POST, PUT). Verified via API: confirm → parcel with all title fields +
  2 linked owners.
- **B3** ✅ done — `pg_trgm` enabled (guarded `DO` blocks in `schema.sql` + migration 001, so DBs
  without the extension still apply), starter vocab for `title_types`/`land_use_classes`, trgm GIN
  indexes on `villages.name_lo`/`districts.name_lo`; resolver falls back to PHP `similar_text` when
  the extension is absent. Verified fuzzy district typo → correct id @ 70%.
- **Note:** `tsv` as a trailing config arg is ignored by Tesseract 5.5 (emits plain text); use
  `-c tessedit_create_tsv=1` writing a `.tsv` sidecar (with a plain-text fallback).

### Phase C — Annual obligations
- **C1** ✅ done — `lib/fee.php` (`zone_rate_for`, `fee_compute_amount` = rate × area,
  `fee_ensure_year`, `fee_upsert_obligation` idempotent on `parcel_id`+`fee_year` and never
  downgrading a paid row, `fee_audit`). `api/v1/fee_obligations.php` GET (filters: `fee_year`,
  `district_id`, `parcel_id`, `status`, `overdue`) + POST `generate` (all / by district / by parcel);
  `api/v1/fee_years.php` GET/POST. Verified: 2026 generation → 1 obligation @ 140,000 × 1250.5 =
  175,070,000; re-running generate is idempotent (still 2 rows).
- **C2** ✅ done — `api/v1/scans/confirm.php` auto-assesses the current-year obligation on confirm
  (returns `obligation_id`, wrapped in try/catch so the fee layer can't fail a confirm).
  `public/admin/parcel.php` detail page: title, owners, obligation history + audited Recompute
  (`POST action=recompute`); `parcels.php` links to it. Verified: confirm → `obligation_id=2`
  (alley 110,000 × 300 = 33,000,000); after a rate change to 120,000 recompute → 36,000,000 with
  before/after written to `audit_log`.
- **C3** ✅ done — Arrears/aging in `public/admin/obligations.php` (`?view=arrears` + summary
  totals, `days_overdue` computed in SQL). Changing a year's `due_date` propagates to unpaid
  obligations. Verified: due_date 2026-03-31 → 2 arrears rows at 199 days overdue.
- **Note:** `fee_obligations.due_date` is a denormalized copy of `fee_years.due_date`; the
  `fee_years` POST keeps unpaid obligations in step, but paid rows intentionally keep their date.

### Phase D — Payments + auto-verify
- **D1** ✅ done — `api/v1/payments.php` **rewritten** for the v2 model: it previously targeted the
  dropped v1 columns (`citizen_id`, `slip_no`, `fee_total`, `zone_rate`) and was broken against the
  v2 schema. Payments now settle an `fee_obligations` row (by `obligation_id`, or `parcel_id` +
  `fee_year` which auto-assesses), amount defaults to the outstanding balance and is capped at it,
  with slip/receipt upload, verify, reject and waive. `lib/slip.php`: `slip_generate_no()` replaced
  the racy `COUNT(*)+1` with a `payment_ref_seq` sequence (lazily created, seeded past any existing
  refs) so references can't collide or be reused after deletes; the A4 slip HTML was moved to v2
  fields (`reference_no`/`amount`/`method`, rate + due date from the obligation) and now escapes
  output locally instead of relying on the admin `e()` helper.
- **D2** ✅ done — `lib/bank_match.php` (`bank_extract_reference`, `bank_normalize_amount`,
  `bank_normalize_date`, `bank_parse_csv`, `bank_match_run`) + `api/v1/bank_imports.php`
  (upload/JSON import, re-match, per-transaction officer override). Column mapping is optional:
  headers are normalized ("Bank Ref" → `bankref`) and matched against aliases, so unknown bank
  exports still map. Matching follows §5 exactly. Verified on a 5-row statement:
  1 matched / 1 partial / 1 duplicate / 2 unmatched, with `bank_ref` resolved per row.
- **D3** ✅ done — `fee_audit()` wired into `payment_issue`, `auto_match`, `payment_verify`,
  `payment_reject`, `obligation_waive`, `bank_import`, `bank_rematch`, `bank_txn_apply/ignore`.
  Added `fee_settle_obligation()` as the single place obligation status is derived from PAID
  payments, so manual verification and auto-matching cannot drift. Officer UI: `payments.php`
  rewritten as the v2 verify queue (reference, obligation status, verify method, print slip,
  verify/reject/waive) and `bank_imports.php` added (upload with mapping, imports list, re-match,
  per-transaction Apply/Ignore).
- **Overpayment fix:** auto-match records the amount the bank actually credited, not the issued
  amount — otherwise a 10M payment against an 8.5M due reported PAID instead of OVERPAID.
- **Idempotency:** re-importing a statement re-marks its rows `duplicate` and never double-applies.
- **Note:** the citizen PWA (`quote.php`, `payments.php`, `payment-view.php`) still reads v1
  columns and is broken against v2 — deferred to Phase F.

### Phase E — Reports
- **E1** ✅ done — `api/v1/reports.php` rewritten as a multi-report endpoint
  (`?report=collection|arrears|registration|register|bank|rates|audit|mof`, `format=json|csv`).
  It previously queried dropped v1 columns (`payments.transaction_type`, `fee_total`, `district_id`)
  and was therefore broken. Every report returns `{columns, rows, summary}` so the UI renders
  generically. Verified: collection (7 cols), arrears with aging + `years_behind`, registration
  (parcels/area + scan backlog + OCR-confidence distribution), register (parcel/owner export).
- **E2** ✅ done — Bank reconciliation report: rows grouped per import × match status, plus a
  summary breaking down `by_status` and comparing `statement_matched_kip` against
  `bank_paid_kip` (bank transfer + mobile; counter cash and cheques are split into
  `counter_cash_kip`) with a `difference_kip`.
- **E3** ✅ done — MOF CSV re-based on `fee_obligations` + `payments`: fee_year, district, village,
  road, parcels, assessed / collected / outstanding. **Provisional:** the column set was not
  confirmed with the Ministry of Finance and must be checked before this is filed officially.
- **UI** — `public/admin/reports.php` rewritten (report picker, context-sensitive filters,
  generic table, summary cards). It previously emitted CSV *inline* after HTML output, where
  `header()` can no longer take effect, producing corrupt downloads. CSV now goes through
  `public/admin/reports_export.php`, which authenticates server-side with the session token and
  re-emits the body; verified correct `text/csv` headers for all 8 reports and a 302 (no data
  leak) when unauthenticated. Added `api_fetch_raw()` for non-JSON responses, since `api_call()`
  JSON-decodes and cannot carry CSV.

### Phase F — Citizen PWA
- **Critical fix — OTP login never worked.** The code was stored in the API's PHP session, but the
  citizen pages call the API server-side (no cookie round-trip), so `otp/request` and `otp/verify`
  landed in different sessions and verification failed every time. Replaced with stateless
  HMAC-signed tokens (`lib/otp.php`), verified by signature before anything else. (Channel moved from
  SMS to email in Phase G.)
- **Security fix — OTP leaked in the response body.** `debug_code` was always returned. It is now
  gated behind `OTP_DEBUG=1` (default off). Verified: no `debug_code` in the response without it.
  **`OTP_DEBUG` must be unset in production** — anyone could otherwise read the code.
- **Auth was missing entirely in the UI.** Every citizen page called the API with no
  `Authorization` header, so all requests were 401. Added `citizen_api()` / `citizen_api_upload()`
  to `public/citizen/common.php`.
- **Broken scoping.** `payments.php` compared `payer_owner_id` (an `owners.id`) against the user's
  id, so citizens matched other people by accident. Now scoped by `owners.citizen_user_id`.
- **Privacy fix.** Any citizen could list the entire land registry. `parcels.php` now requires a
  parcel number or `mine=1` for citizens, and by-id access is ownership-checked. Verified 403 for
  both registry enumeration and another citizen's parcel.
- **New** `api/v1/my_parcels.php` — the citizen's parcels with per-year obligations, which is what
  the app actually renders.
- **UI re-aligned to v2:** `lookups.php` → my parcels with oldest-unpaid-year focus;
  `quote.php` → obligation breakdown (assessed/paid/penalty/outstanding) with explicit method
  choice, and **no payment is created merely by viewing**; `payments.php` / `payment-view.php` →
  v2 fields, QR, print, tracking timeline, and receipt photo upload. `index.php` had a fatal
  `json_decode()` on an already-decoded session array (HTTP 500 on every load) — fixed.
- **Verified end-to-end:** OTP login → my parcels (18,000,000 outstanding) → create slip
  `LFE-2026-000006` → officer verify → citizen sees PAID; plus receipt upload, settled-year state,
  and cross-citizen isolation (a second citizen sees no parcels and cannot open another's slip).
- **Known gap:** OTP codes are replayable within their 5-minute window until a used-token store lands.

---

### Phase G — Basic data, user management, email OTP
Decision: the fee values shipped in `zones_rates` are **placeholders**. The province owns the numbers
and revises them through Basic Data; nothing here should be read as an approved tariff.

- **G1 — Email OTP (SMS dropped).** No SMS gateway is available, so citizen login moved to email
  (`users.email`, nullable + case-insensitive unique index). `otp_issue()`/`otp_verify()` are
  unchanged; delivery is now `otp_send_email()` → `lib/mail.php` → PHP `mail()`, which needs a system
  MTA plus `MAIL_FROM` in `.env`. Without them `otp/request` still returns a token but
  `data.sent === false`. Deactivated citizens can no longer obtain a code. `debug_code` still only
  appears when `OTP_DEBUG=1`.
- **G2 — Basic data.** `provinces` table (single configurable province) with `districts.province_id`
  (migration 002, additive + idempotent, backfills all existing districts). `public/admin/basic_data.php`
  maintains province, districts, villages, road categories, title types, land use, zone rates and
  fee years. `api/v1/refdata.php` handles the lookup tables with an explicit whitelist (table names
  are never interpolated from the request). Deletes that are still referenced are refused with the
  usage counts rather than a raw FK error.
- **G3 — User management.** `api/v1/users.php` + `public/admin/users.php` (admin-only): create/edit
  staff, reset passwords, activate/deactivate, and link a citizen login to an owner record
  (`owners.citizen_user_id`) — the link without which a citizen sees nothing. Guards: an admin cannot
  deactivate their own account, and the last active admin cannot be demoted or disabled.
  Candidate-owner search matches owner name, ID number, phone **and parcel number**, because officers
  usually know the parcel.
- **Verified:** fresh `schema.sql` + seed (15 districts / 1,029 villages / 60 rates, idempotent);
  the real server upgrade path v1 → 001 → 002 with existing districts, villages and a user preserved;
  email OTP login end-to-end; CRUD and in-use refusals for all four lookup tables, districts and
  villages; staff create/reset/deactivate + last-admin and role guards; officer is refused (403) on
  both admin pages; link citizen → owner → citizen sees their parcel.
- **Seed caveat:** the province insert is `DO NOTHING` so an admin rename survives re-seeding, but the
  seed still truncates and reloads districts/villages, which does overwrite edits to those names.

## 9. Open items / assumptions

1. **Lao label dictionary** (§3.2) must be verified against real titles with officers.
2. **`pg_trgm`** needs an extension install (DB privileges); PHP fuzzy fallback documented.
3. **Bank CSV format** unknown → column mapping is configurable, but confirm against a real statement.
4. **Live DB migration**: 001 and 002 are additive, but 001 drops/recreates `parcels`, `payments`
   and `title_scans` — confirm those are empty before applying. Migration order is 001 then 002.
5. **Penalty/interest policy** for late annual payment (field exists, policy undefined).
6. **Multi-owner payer**: who is the `payer_owner_id` when a parcel has co-owners?
7. **District Lao-name misalignment 1311–1315** (pre-existing) — confirm with PERN.
8. **Officer local login**: seed creates no users — insert an officer manually for local testing.
9. **MOF export format** (§E3) is a placeholder column set — obtain the real Ministry template.
10. **Fee values are placeholders**; the province revises them via Basic Data.
11. **Email OTP needs a system MTA + `MAIL_FROM`**; without them no code is ever delivered.
12. **OTP replay** is possible within the 5-minute window until a used-token store is added.
13. **No `updated_at` trigger** — every write path sets it in code, so a new write path can forget.
