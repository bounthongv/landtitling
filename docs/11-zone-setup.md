# Zone Setup — Analysis & Implementation Plan

Status: implemented (migration 006 + Savannakhet skeleton seed + admin/API changes).
Source of the target structure: `docs/ຂໍ້ຕົກລົງການປະເມີນລາຄາທີ່ດິນ.md`
(Decree 142/ລົງ, 26 Feb 2021, Vientiane Capital land valuation).

## 1. What the decree actually defines

The decree prices land with a three-level key:

1. **District** — each district has its own rate table.
2. **Standard planning zone (ເຂດວາງແຜນມາດຕະຖານ)** — a numbered zone inside the district.
3. **Zone code** — the code used on the title/valuation map (e.g. `A1`, `B3`, `G17`).
4. **Road access tier** — four columns per zone: main road, branch road, alley, no road.

So the real price is a **matrix**, not the single `rate_per_sqm` the app stores today:

```
fee = zone_rate(zone_code, road_category) × area_sqm
```

The four decree columns map exactly onto the existing `road_categories`
lookup (`main`, `branch`, `alley`, `none`). Vientiane's figures run from
1,500 to 6,800,000 kip/m²; they are **not** valid for Savannakhet and must
never be copied into this database.

Some Vientiane zone codes (e.g. `A6`) contain more than one rate block for
different village groups. MVP rule: **one rate block per zone code**; split a
sub-area into its own zone code instead (`A6.1`, `A6.2` or the next number).

## 2. Gap analysis (current code)

| Area | Current state | Problem |
|------|---------------|---------|
| `zones` | one `rate_per_sqm` per code | cannot express 4 road tiers |
| `zones` | no `district_id`, no planning zone | code is ambiguous across districts |
| `districts` | no short letter | no stable way to build district-scoped codes |
| Fee logic | `zone_rate_for($code)` only | ignores road access |
| `fee_obligations` | has `road_category` | never populated by `fee_upsert_obligation()` |
| `api/v1/fee.php` | accepts `zone_code` only | citizen/officer quote cannot pick a tier |
| `api/v1/rates.php` | flat CRUD | admin cannot maintain 4 rates per zone |
| Admin UI | one rate field | same |
| `parcel_entry.php` | `zone_text` only | cannot set the valuation zone/road |
| `scans.php` | zone select only | no road-access select |
| `rates.php` (admin) | legacy district×road editor | stale; duplicates `basic_data.php` |

The legacy `zones_rates` table (district × road_category) is already unused by
the fee logic. It is kept only as reference and is not modified.

## 3. Recommended model (implemented)

### 3.1 Reference tables

- `zone_plans` — editable lookup of standard planning zones:
  `1` urban core, `2` peri-urban, `3` agricultural, `4` rural,
  `5` commercial, `6` industrial (Lao + English names).
- `road_categories` — unchanged (`main`, `branch`, `alley`, `none`).

### 3.2 `zones` (one row per valuation zone)

New columns:

- `district_id INT NULL` → `districts(id)`. `NULL` = legacy/unassigned zone.
- `plan_zone VARCHAR(10) NULL` → `zone_plans(code)`.
- `sort_order INT NOT NULL DEFAULT 0`.
- `rate_per_sqm` and `valid_from` are kept for backward compatibility and as
  the "default" rate, but fee calculation now reads `zone_rate_tiers`.

### 3.3 `zone_rate_tiers` (the matrix)

One row per `(zone_code, road_category, valid_from)`. A rate change inserts a
new `valid_from` row; the newest row on/before today wins. This preserves the
history the old `zones.valid_from` provided, but per tier.

### 3.4 `districts.zone_letter`

A one-letter prefix (`A`–`O` for Savannakhet's 15 districts, in official code
order 1301–1315). Zone codes are then `<letter><n>`, e.g. `A1`, `A4`, `O2`.
The letter is **editable in Basic Data → Districts**, so another province can
use its own convention without a code change.

### 3.5 Fee rule (unchanged shape, one more key)

```
fee = tier_rate(zone_code, road_category) × area_sqm
```

- If the tier is missing, fall back to `zones.rate_per_sqm` (legacy rows).
- If the parcel has no `road_category`, use `none` (lowest tier) rather than
  failing. New parcels/scans are required to supply it in the UI.
- `transaction_type` and land-use remain descriptive labels, never multipliers.

## 4. Savannakhet skeleton

`sql/seed_zone_rates_savannakhet.sql` creates a placeholder skeleton for the
province (code `13`):

- Assigns letters `A`–`O` to districts `1301`–`1315` (official order).
- Creates **4 zones per district** for plans 1–4, plus plans 5–6 for the
  capital district `1301` (Kaysone Phomvihane) → **62 zones**.
- Creates 4 tier rates per zone (248 rows), in the confirmed placeholder band
  85,000–180,000 kip/m². They are placeholders only and are safe to overwrite.
- `ON CONFLICT DO NOTHING` throughout: re-running never overwrites figures the
  province has already edited.
- Does **not** delete or reassign existing zones (`G17`, `A7`, `C7`, `C8`).
  Those stay active as legacy/unassigned until an officer maps them.

Village → zone mapping is deliberately **phase 2**. The decree maps village
groups to zones, but the national village dataset has no zone column, and
guessing would misprice parcels. Officers assign the zone on the parcel (scan
confirm or parcel entry) until a mapping table is populated.

## 5. Code changes

| File | Change |
|------|--------|
| `sql/migrations/006_zone_rates_v2.sql` | add columns/tables; backfill tiers from `zones.rate_per_sqm` |
| `sql/seed_zone_rates_savannakhet.sql` | 15 districts × plans, tiers |
| `sql/schema.sql` | target-state tables/columns for fresh installs |
| `lib/fee.php` | `zone_rate_for($pdo,$code,$road)`, tier lookup + fallback; snapshot `road_category` in obligations |
| `api/v1/fee.php` | accept `road_category` (default `none`) |
| `api/v1/rates.php` | GET zones with district/plan/tiers; POST/PUT maintain tiers |
| `api/v1/districts.php` | accept `zone_letter` |
| `api/v1/refdata.php` | expose `zone_plans` |
| `api/v1/reports.php` | rate schedule reports 4 tiers |
| `api/v1/parcels.php`, `api/v1/scans/confirm.php` | validate zone belongs to district; validate road category |
| `public/admin/basic_data.php` | zone table with 4 rate columns + modal; district letter field |
| `public/admin/scans.php` | road-access select + district-filtered zone list |
| `public/admin/parcel_entry.php` | valuation-zone + road-access selects |
| `public/admin/rates.php` | redirect to `basic_data.php?tab=zones` |

## 6. Rollout

1. Back up the DB.
2. Apply `sql/migrations/006_zone_rates_v2.sql` (additive, idempotent).
3. Apply `sql/seed_zone_rates_savannakhet.sql` (idempotent, non-destructive).
4. Deploy code; restart Apache/OPcache.
5. In **Basic Data → Zone rates**, check the 62 skeleton zones and the district
   letters; overwrite placeholder rates with the official Savannakhet figures.
6. Existing parcels keep their current zone code; `road_category` defaults to
   `none` for pricing until an officer edits the parcel. New records require
   both fields.

## 7. Verification

- `GET /api/v1/rates.php` returns each zone with `district_id`, `plan_zone`
  and a `rates` object keyed by road category.
- `POST /api/v1/fee.php {"zone_code":"A1","area_sqm":100,"road_category":"main"}`
  returns the main-road tier rate.
- Admin can add/edit a zone with 4 rates and see history rows.
- Re-running the migration and seed changes nothing.
- `php -l` clean on every changed PHP file.

## 8. Known limits / phase 2

- No village → zone mapping (officers pick the zone per parcel).
- No bulk import of the official rate table yet; the UI edits one zone at a time.
- Legacy zones `G17/A7/C7/C8` remain active and unassigned until mapped.
- The `zones.rate_per_sqm` column is retained only as a fallback; new admin
  edits write tiers first.
