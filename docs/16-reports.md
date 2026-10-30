# Reports — what each one answers and how to use it

Status: describes `public/admin/reports.php`, `api/v1/reports.php` and
`public/admin/reports_export.php` as they are today. Every figure comes from
live data — nothing is pre-aggregated or cached.

Open **Back-office → Reports** (`/admin/reports.php`). Reports are grouped in
the picker by what they are for:

| Group | Report | Answers |
|---|---|---|
| Money | Collection | How much money came in, when, and from where |
| Money | Arrears & aging | Who still owes, how much, and for how long |
| Money | MOF export | The statutory assessed / collected / outstanding figures |
| Registry | Data entry progress | How far parcel and title-scan data entry has got |
| Registry | Parcel & owner register | The line-by-line land register, with owners |
| Controls | Bank reconciliation | Do imported bank statements agree with settled payments? |
| Controls | Rate schedule | The zone × road rate table currently in force |
| Controls | Audit trail | Who changed what, and when |

## 1. Common behaviour

- **Report ids never change.** The picker label can be translated or renamed
  (e.g. `registration` is shown as "Data entry progress"); the id and filters
  stay the same, so links keep working.
- **Filters adapt to the report.** Province and district appear only for the
  parcel-based reports; road appears for all of those except the register;
  From/To appear for Collection and Audit trail; Actor/Action for Audit trail;
  a parcel/title search for the register.
- **Scope is enforced server-side.** A district officer always sees only their
  own district, whatever the URL says; province staff are pinned to their
  province (Savannakhet, id 1, until the multi-province rollout); a super admin
  sees everything and may filter.
- **Fee year** is offered only for Collection, Arrears and MOF — the reports
  it changes.
- **Summary cards** above the table are report-specific totals. Arrays in the
  summary (OCR confidence buckets, bank match statuses) render as small tables.
- **Download CSV** exports exactly the rows visible under the current filters,
  via `reports_export.php` (the browser cannot send the API's `Bearer` token, so
  the page proxies the request server-side). The file is `landfee-<report>.csv`;
  it contains the columns and rows only, not the summary cards.
- **No automated tests** cover reports; after changing one, open it and export
  the CSV, as described in each section below.

## 2. Collection (Money)

**Question:** how much money was actually collected, by district, village, road
category, fee year and month?

- **Source:** `payments` rows with `status = 'PAID'`, joined to their obligation
  and parcel. Cash-basis: grouped by the month of `paid_at`, not by fee year.
- **Filters:** province, district, road, fee year, From/To (payment date).
- **Columns:** District, Village, Road, Fee year, Month, Payments, Collected (kip).
- **Summary:** number of payments and total kip in the filtered range.

**How to use**

1. Run it with From/To set to the period you are closing (e.g. the month just
   ended).
2. Compare the summary total with the cash/bank deposits for the same period.
3. Export the CSV and hand it to finance with the deposit slip.
4. A fee year filter narrows to tax years, but the date range decides *when*
   the money arrived.

Only settled (`PAID`) payments count. A surcharge collected with a late payment
is inside `payments.amount`; the report does not split tax from surcharge.

## 3. Arrears & aging (Money)

**Question:** which obligations are still unpaid, and how badly overdue are
they?

- **Source:** `fee_obligations` with status `UNPAID` or `PARTIAL`.
- **Filters:** province, district, road, fee year.
- **Columns:** Parcel, District, Village, Year, Due date, Amount due, Paid,
  Penalty, Outstanding, Days overdue, Bucket, Years behind.
- **Summary:** distinct parcels, obligations, total outstanding kip.
- **Sort:** most overdue first (`days_overdue` descending).

`Penalty` is the 10% late-payment surcharge (decree 385/2009 art. 10). While an
obligation is `UNPAID`/`PARTIAL` the figure is derived live from `amount_due`
and `due_date`; once settled it is frozen. `Outstanding` = amount due + penalty
− paid. `Years behind` counts how many unpaid years the same parcel has up to
and including this one.

**How to use**

1. Clear all filters to see the district's whole arrears book, or pick a fee
   year to target one tax year.
2. Use "overdue" rows (due date in the past) for follow-up; "not yet due" rows
   are future work.
3. Sort order already puts the oldest debt on top; use Years behind to spot
   parcels that have skipped several years.
4. Export the CSV and give a village-filtered copy to the field team.
5. To collect, take the parcel number to **Payments → Office counter**; the
   counter shows the same outstanding amount, surcharge included.

Waived, disputed and refunded obligations are not arrears and do not appear.

## 4. MOF export (Money)

**Question:** for the Ministry of Finance, how much was assessed, collected and
is still outstanding?

- **Source:** `fee_obligations`, grouped by fee year, district, village and road
  category.
- **Filters:** province, district, road, fee year.
- **Columns:** Fee year, District, Village, Road, Parcels, Assessed (kip),
  Collected (kip), Outstanding (kip).
- **Summary:** number of grouped rows.

`Assessed` is the sum of `amount_due`; `Collected` the sum of `amount_paid`;
`Outstanding` is amount due + surcharge − collected, summed only over unpaid and
partially paid obligations.

**How to use**

1. Pick the fee year being reported.
2. Leave district/province unfiltered at province level for the province-wide
   submission, or filter per district if the ministry asks for a breakdown.
3. Export the CSV; that file is the submission.
4. Keep the same fee year everywhere: this report, arrears and collection
   should agree once the year is closed.

`Outstanding` therefore reconciles with the Arrears report. Waived, disputed and
refunded rows still count in `Assessed` and `Collected` because they are part of
the year's record.

## 5. Data entry progress (Registry, id `registration`)

**Question:** how much of the paper registry has been entered and scanned, and
how good is the OCR?

- **Source:** `parcels` (rows) and `title_scans` (counters).
- **Filters:** province, district, road.
- **Columns:** District, Parcels, Total area (m²), Confirmed, Draft, Road
  categories.
- **Summary:** parcels, total area, scans, scans pending (scans not yet linked
  to a parcel), average OCR confidence, and the confidence distribution
  (80–100 high, 50–79 medium, 1–49 low, 0 unparsed) as a small table.

This was previously labelled "Registration" — it measures digitisation work,
not title registration. Scan counters are scoped by district/province only (a
road filter narrows the parcel rows, not the scan counters).

**How to use**

1. Run it weekly during the digitisation phase.
2. A high "scans pending" count means uploaded titles are waiting for review on
   **Scans**.
3. Watch the low/unparsed OCR buckets; if they grow, title scans need better
   images or parser work before data entry can keep up.
4. Confirmed vs draft shows how many parcels are usable for billing.

## 6. Parcel & owner register (Registry)

**Question:** what exactly is in the land register?

- **Source:** `parcels` joined to district, village and owners.
- **Filters:** province, district, road (API only — the screen shows the search
  box instead), parcel status (API only), and a parcel/title search (`q`,
  case-insensitive contains on parcel number or title number).
- **Columns:** Parcel, Sub, Title no, Map sheet, Title type, Book, Volume, Page, Land use,
  Issued, Area (m²), Road, District, Village, Status, No. of owners, Owners
  (name and national ID, `;`-separated).
- **Summary:** number of parcels.

**How to use**

1. Leave the search empty for a full export, or type part of a parcel or title
   number to check one record.
2. Use the CSV as the registry snapshot for field verification, audits, or
   hand-over to another office.
3. For day-to-day lookup use **Registry → Parcels** instead; this report is for
   export and completeness checks.

## 7. Bank reconciliation (Controls)

**Question:** do the imported bank statements agree with the payments the
system says were settled?

- **Source:** `bank_transactions` grouped by import file and `match_status`.
- **Scope:** a district officer sees only transactions matched to payments in
  their district; unmatched statement rows are not attributable to a district
  and stay hidden from them. Province staff see matched rows for the province.
- **Columns:** Import, File, Imported, Match status, Rows, Total (kip).
- **Summary:** rows and kip per match status (table), `statement_matched_kip`,
  `payments_paid_kip` (all settled money), `counter_cash_kip` (cash, cheque and
  legacy counter rows), `bank_paid_kip` (bank transfer + mobile) and
  `difference_kip` = matched statements − bank payments.

**How to use**

1. Run it after importing a statement on **Payments → Mobile app**.
2. Start with the per-status table: rows still `unmatched`, `duplicate` or
   `ignored` are the ones needing attention; resolve them in the bank import
   screens and re-run.
3. `difference_kip` compares only `bank_paid_kip` with the matched statement
   rows; counter cash and cheques are excluded, so it is a real bank-vs-ledger
   check. A positive figure means statements carry money not booked as a
   bank/mobile payment; a negative one means booked bank payments are missing
   from the statements.

This report is only meaningful once statements are being imported; until then
it will show zeros.

## 8. Rate schedule (Controls)

**Question:** which rate is in force for each zone and road-access tier?

- **Source:** `zones` joined to the newest `zone_rate_tiers` row per
  (zone, road category) whose `valid_from` is today or earlier. Zones without
  tier rows fall back to `zones.rate_per_sqm`.
- **Filters:** none (fee year is ignored — the report always shows what is
  current).
- **Columns:** Zone, Name (Lao), Name (EN), District, Plan, Main, Branch, Alley,
  None, Valid from, Status (current/inactive).
- **Summary:** number of zones.

**How to use**

1. Export the CSV to share with the province when confirming or publishing the
   decree rates.
2. To change a rate, use **Basic Data → Zone rates**; this report is read-only.
3. "Valid from" tells you when the current tier took effect; older tiers stay in
   the database for history but are not shown.

## 9. Audit trail (Controls)

**Question:** who did what to the data, and when?

- **Source:** `audit_log` left-joined to `users`.
- **Filters:** actor (username contains or numeric id), action (contains, e.g.
  `auto_match`, `obligation_penalty`, `recompute`), From/To.
- **Scope:** a district officer sees only actions by users of their district;
  province staff see their province; super admin sees everything.
- **Columns:** ID, When, Actor, Action, Entity, Entity ID, Detail.
- **Summary:** number of entries. The newest 1000 matching rows are returned.

**How to use**

1. For a disputed payment, search by the officer's username and the date range,
   then read `Detail` (before/after values are stored there).
2. To review late-payment surcharge decisions, filter action
   `obligation_penalty`; the detail shows mode, before/after and the reason.
3. To review fee recomputation, filter `recompute`; bank matching is
   `auto_match`.
4. Export the CSV when an audit needs a file.

The 1000-row cap is deliberate; narrow with actor/action/dates rather than
paging.

## 10. API reference (for scripts and the PWA)

```
GET /api/v1/reports.php?report=<id>&format=json|csv&...
Authorization: Bearer <staff JWT>      (officer, admin or manager)
```

| Parameter | Used by | Meaning |
|---|---|---|
| `report` | all | `collection`, `arrears`, `registration`, `register`, `bank`, `rates`, `audit`, `mof` |
| `format` | all | `json` (default) or `csv` |
| `province_id` | collection, arrears, registration, register, mof | province filter |
| `district_id` | same as above | district filter |
| `road_category` | same as above | `main`, `branch`, `alley`, `none` |
| `fee_year` | collection, arrears, mof | tax year |
| `from`, `to` | collection, audit | inclusive date range |
| `actor`, `action` | audit | substring match on username / action |
| `q` | register | parcel or title number contains |
| `status` | register | parcel status (`draft`, `confirmed`, …); no UI control |

JSON responses use the standard envelope:
`{ ok: true, data: { columns: [...], rows: [...], summary: {...} } }`.
CSV responses stream the header row and data rows, filename
`landfee-<report>.csv`.

## 11. Known limitations

- CSV exports omit the summary cards and any nested summary arrays.
- The audit report returns at most 1000 rows.
- Collection reports settled money only; surcharges are not broken out there
  (they are in Arrears, MOF and on the receipt).
- MOF `Assessed` and `Collected` include waived/disputed/refunded obligations;
  only `Outstanding` is restricted to `UNPAID`/`PARTIAL` (collectable debt).
- Bank `difference_kip` excludes counter cash and cheques, which never appear on
  a statement (see §7).
