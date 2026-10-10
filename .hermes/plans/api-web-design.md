# Land Fee MVP — API + Web Design (Phase 2 blueprint)

Status: DESIGN DOC. Database is done; API is a scaffold (thin, unauthenticated);
web has only a landing page + one rough officer screen. This is the plan to build the
real product properly.

## System shape

One API (PHP 8 + PDO/PostgreSQL, JSON), three consumers:
1. Citizen PWA (mobile-first, installable)
2. Officer back-office (province staff)
3. Fee engine (server-side calc)

## API surface — `/api/v1/`

Consistent envelope on every endpoint: `{ "ok": bool, "data": ...|"error": {...} }`

| Resource | Method | Purpose | Auth |
|---|---|---|---|
| POST /auth/otp, /auth/verify | | phone OTP login (citizen), account login (officer) | — |
| GET /districts | | reference list (dropdowns) | public |
| GET /districts/{id}/villages | | villages for a district | public |
| GET /rates | | zone-rate table | officer |
| PUT /rates/{id} | | edit a zone rate | officer |
| POST /scans | | upload title photo -> OCR -> store scan | officer |
| GET /scans/{id} | | get a scan + OCR result | officer |
| POST /scans/{id}/confirm | | officer confirms/edits -> creates/updates parcel | officer |
| GET /parcels?parcel_no= | | search parcels | citizen+officer |
| GET /parcels/{id} | | parcel detail | citizen+officer |
| POST /fee/quote | | {district_id, road_category, area_sqm} -> fee | public |
| POST /payments | | create payment -> ISSUED + slip | citizen |
| GET /payments/{id}/slip | | printable A4 slip (QR + amount) | citizen |
| POST /payments/{id}/receipt | | citizen uploads bank slip photo | citizen |
| POST /payments/{id}/verify | | officer verifies -> PAID | officer |
| POST /payments/{id}/reject | | officer rejects -> REJECTED (returns to ISSUED) | officer |
| GET /payments?status=&mine= | | list (citizen: mine; officer: queue) | both |
| GET /reports/fees?district=&from=&to= | | CSV/aggregate for MOF | admin |

## Conventions (standardize now)

- Envelope: `{ok, data|error, meta}` everywhere.
- Auth: JWT (or session) + role gate (citizen vs officer vs admin). Public: districts,
  villages, fee/quote. Everything else authed.
- Validation layer: `lib/validate.php` with shared rules per resource.
- DB: PDO only (done). JSONB for scan OCR payloads (done).

## Front-ends

### Citizen PWA (`public/citizen/`)
Mobile-first, installable (manifest + service worker). Screens:
- Title lookup by parcel no. (or OCR scan) -> parcel view
- Fee quote (district/road/area -> amount)
- Request payment -> ISSUED, shows printable slip (QR + amount)
- Pay at bank/counter -> upload slip photo
- Track status timeline (ISSUED -> PAID/REJECTED), view receipt
- Lao-first UI, English labels mixed (existing visual language)

### Officer back-office (`public/admin/`)
Desktop-first. Screens:
- Login
- Scan review queue: pending OCR confirmations (image + OCR side-by-side, confirm/edit)
- Parcel registry: search/filter/list
- Zone-rate table editor (district x road_category -> rate_per_sqm)
- Payment verification queue: view slip photo, verify/reject
- Reports: fees by district/month/type, CSV export for MOF

## Fee engine (server-side)

fee_total = zone_rate(district, road_category) * area_sqm
zone_rate read from zones_rates (effective-date-aware). Returns rate + computed total.
No land_use / transaction_type factor (flat rate per earlier decision).

## Payment slip

ISSUED payment -> slip_no (unique) + QR encoding slip_no + amount. Printable A4
bilingual (Lao/EN), amount + QR + payer + parcel ref + bank/counter pay instructions.

## Build order (Phases 2-4)

- 2a: Auth + API conventions + fee quote + payments + slip (backend complete)
- 2b: Officer back-office (scan review, rates, verify queue, reports)
- 2c: Citizen PWA (lookup, quote, pay, slip upload, status)
- 2d: Polish: CSV import, audit log, SMS placeholder, deploy wiring

## Open items

- OTP delivery (real SMS gateway vs dev stub) — user may prefer email/OTP stub for MVP
- Slip QR standard (LaoQR vs plain reference) — pending bank/counter channel
- Live zone-rate schedule — pending central office (currently 80-180k band)