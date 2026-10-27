Obligations Page (obligations.php)

Purpose
The annual land tax obligation engine — creates, tracks, and manages the yearly tax bill for each parcel.



Core Concepts

| Term       | Meaning                                                                                                                           |
|------------|-----------------------------------------------------------------------------------------------------------------------------------|
| Fee Year   | The tax year (e.g., 2026). Each year has its own rate schedule.                                                                   |
| Obligation | One row = one parcel × one fee year. Stores: rate (kip/m²), area, amount_due, amount_paid, penalty, status.                       |
| Generation | Officer clicks Generate → system creates obligation rows for all parcels (or by district) using current zone rates × parcel area. |
| Recompute  | Re-calculates a specific obligation if rates changed or parcel data corrected.                                                    |



How It Works

1. Officer opens Obligations page → sees filters: Fee Year, District, View (All/Arrears), Status
2. Selects a Fee Year (must exist in Basic Data → Fee Years)
3. Clicks "Generate Obligations" (POST to fee_obligations.php?action=generate)
   - Backend loops all parcels (or district's parcels)
   - For each parcel: looks up zone rate by zone_code + road_category
   - Creates obligation: amount_due = rate_per_sqm × area_sqm
   - Status = open (unpaid), due_date = Dec 31 of that year
4. List shows each obligation with:
   - Parcel No, Owner, District, Village
   - Rate (kip/m²), Area, Amount Due, Amount Paid, Penalty
   - Status pill: open / partial / paid / overpaid / waived / overdue
   - Recompute button (if unpaid) — re-fetches current rate × area



Key Flows


┌─────────────────────────────────────────────────────────────┐
│  BASIC DATA → Fee Years  (create 2026, set due_date=Dec 31) │
└─────────────────────────┬───────────────────────────────────┘
                          ▼
┌─────────────────────────────────────────────────────────────┐
│  OBLIGATIONS → Select 2026 → "Generate Obligations"         │
│  Creates: one row per parcel × 2026 rate                     │
└─────────────────────────┬───────────────────────────────────┘
                          ▼
┌─────────────────────────────────────────────────────────────┐
│  PAYMENTS PAGE → citizen pays → bank import matches →       │
│  obligation.amount_paid updated → status: paid/partial      │
└─────────────────────────┬───────────────────────────────────┘
                          ▼
┌─────────────────────────────────────────────────────────────┐
│  REPORTS → Collection Report, Arrears Report, MOF Report    │
└─────────────────────────────────────────────────────────────┘




Status Meanings

| Status   | When                                  |
|----------|---------------------------------------|
| open     | Generated, nothing paid yet           |
| partial  | Some payment received, not full       |
| paid     | Fully paid (amount_paid ≥ amount_due) |
| overpaid | Paid more than due                    |
| waived   | Officer waived the obligation         |
| overdue  | Past due_date and not paid/waived     |



Typical Officer Workflow

1. Jan 1 — Create Fee Year 2026 in Basic Data
2. Jan 15 — Open Obligations → Select 2026 → Generate (creates ~1000s rows)
3. Throughout year — Citizens pay via bank slip / counter
4. Bank Import — CSV uploaded → auto-matches payments to obligations
5. Payment Verify — Officer reviews unmatched / partial matches
6. Dec — Run Arrears Report to see who hasn't paid
7. Next year — Repeat; old obligations stay for history



What It Doesn't Do (Yet)

- No automatic penalty accrual (penalty field is manual/override)
- No scheduled/recurring generation
- No "carry forward" unpaid to next year
- No installment plans
