# Agent Cash Payment Feature — Citizen Mobile App

## Overview
Enable **community agents** (village heads, secretaries, trusted locals) to collect **cash payments** on behalf of citizens who cannot use the app themselves. The agent uses their own app to record the payment, generating a receipt for the citizen and a batch for later handover to the district office.

---

## 1. Data Model Additions

### `payments` table — new columns
| Column | Type | Purpose |
|--------|------|---------|
| `payment_method` | enum (`bank_transfer`, `cash_agent`) | Distinguish cash-agent payments |
| `agent_id` | FK → `users.id` | Link to agent who collected |
| `agent_role` | varchar (village_head/secretary/agent) | Agent's formal role |
| `payer_name` | varchar | Payer name if different from obligation owner |
| `payer_phone` | varchar | Payer phone if different |
| `payer_id_card` | varchar | Payer ID card number |
| `collected_at` | timestamptz | When agent collected cash |
| `handed_over_at` | timestamptz | When agent handed cash to district officer |
| `handover_officer_id` | FK → `users.id` | District officer who received handover |

### `users` table — agent fields (reuse officer role)
| Column | Type | Purpose |
|--------|------|---------|
| `is_agent` | boolean default false | Flag agent accounts |
| `agent_village_id` | FK → `villages.id` | Agent's **home village** (label/reporting; collections are district-wide) |
| `agent_district_id` | FK → `districts.id` | District scope: the agent may collect for **any parcel in this district** |
| `agent_license_no` | varchar | Optional license/ID for agent |

### New table: `agent_handovers`
| Column | Type | Purpose |
|--------|------|---------|
| `id` | bigserial PK | |
| `agent_id` | FK → `users.id` | Agent who created batch |
| `district_officer_id` | FK → `users.id` | Officer who verified |
| `amount_total` | numeric(14,2) | Sum of payments in batch |
| `payment_count` | int | Number of payments |
| `handed_over_at` | timestamptz | When batch handed over |
| `verified_at` | timestamptz | When officer verified |
| `verified_by` | FK → `users.id` | Verifying officer |
| `status` | enum (`pending`, `verified`, `rejected`) | Batch state |
| `notes` | text | Officer notes |

### New table: `payment_receipts` (immutable receipt storage)
| Column | Type | Purpose |
|--------|------|---------|
| `id` | bigserial PK | |
| `payment_id` | FK → `payments.id` | |
| `receipt_html` | text | Rendered citizen receipt |
| `receipt_pdf` | bytea | Optional generated PDF |
| `created_at` | timestamptz | |

---

## 2. App Flows

### A. Agent Onboarding (one-time, by district admin)
1. District admin creates agent account via **Users** page → role `officer`, level `district`, `is_agent=true`, assign village
2. Agent logs in, sees **"Agent Mode"** toggle in profile

### B. Citizen Payment via Agent (core flow)
1. Agent opens app → **Agent Mode** dashboard (list of pending obligations in their district)
2. Tap parcel → shows citizen name, amount due
3. **Collect Cash** screen:
   - Pre-filled: parcel, amount, citizen (from obligation)
   - **Editable**: payer name (if different), payer phone, payer ID card
   - **Agent auto-filled**: agent name, role, license no, collected timestamp
   - Optional: photo of cash + citizen ID (camera)
4. Submit → generates **dual receipt**:
   - **Citizen copy** (printed/shared): parcel, amount, date, agent name/role, "Cash paid to village agent"
   - **Agent copy** (stored in app): same + internal reference, batch ID
5. Payment status = `SUBMITTED` (same as bank receipt upload), `payment_method = 'cash_agent'`

### C. Agent Batch Handover (periodic)
1. Agent → **Handover** screen: lists all un-handed-over cash payments
2. Tap **Create Handover Batch** → summary (count, total, date range)
3. District officer scans agent's QR/enters batch ID → verifies cash → marks `handed_over_at`
4. Officer can **reject individual payments** (moves back to agent's pending)
5. Once verified, payments → `PAID` (same as bank verify)

### D. Citizen View (if they also have app)
- Citizen logs in → sees payment `SUBMITTED` with agent name, "Cash via [Agent Name]"
- Can download receipt

---

## 3. API Endpoints (add to `api/v1/`)

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/agent/obligations` | GET | List payable obligations in agent's village (filter: unpaid, current year) |
| `/agent/collect` | POST | Record cash collection (creates payment + obligation settlement snapshot) |
| `/agent/receipt/{payment_id}` | GET | Download/print citizen receipt (PDF/HTML) |
| `/agent/handovers` | GET | Agent's handover history |
| `/agent/handovers` | POST | Create new handover batch |
| `/officer/agent-handover/{id}/verify` | POST | District officer verifies batch (marks payments PAID) |
| `/officer/agent-handover/{id}/reject` | POST | Reject specific payment(s) in batch |

### Request/Response Examples

#### `POST /agent/collect`
```json
// Request
{
  "obligation_id": 12345,
  "payer_name": "Somchai Sipaseuth",
  "payer_phone": "020 5555 1234",
  "payer_id_card": "130101000123",
  "cash_photo_base64": "optional...",
  "id_photo_base64": "optional..."
}

// Response
{
  "payment_id": 98765,
  "reference": "LFE-2025-000123",
  "status": "SUBMITTED",
  "collected_at": "2025-06-15T14:30:00+07:00",
  "receipt_url": "/api/v1/agent/receipt/98765"
}
```

#### `POST /agent/handovers`
```json
// Request
{
  "payment_ids": [98765, 98766, 98767]
}

// Response
{
  "handover_id": 42,
  "batch_reference": "AH-2025-06-15-001",
  "amount_total": 450000,
  "payment_count": 3,
  "created_at": "2025-06-15T16:00:00+07:00"
}
```

---

## 4. Security & Audit

- **Agent scope**: API enforces the agent's **district** (`agent_district_id`) — the agent may collect for any parcel in that district (the assigned village is the home label, not a restriction)
- **Idempotency**: `collect` uses parcel+year+agent unique constraint (prevents double-collect)
- **Immutable receipt**: receipt HTML/PDF generated server-side, stored in `payment_receipts` table
- **Cash trail**: every cash payment has `collected_at` (agent) + `handed_over_at` (officer) + `verified_at`
- **No offline-first for MVP** — agent must be online to submit (simplifies audit); can queue locally and sync when online (phase 2)
- **Agent authentication**: reuses `officer` role + `is_agent` flag; district admin creates via existing Users page

---

## 5. UI Additions (Flutter)

### Agent Dashboard (`lib/screens/agent/dashboard_screen.dart`)
- Village name badge, stats cards (pending, collected this month, awaiting handover)
- List of parcels with "Collect" button (filter: current year, unpaid, in agent's district)

### Collect Cash Form (`lib/screens/agent/collect_cash_screen.dart`)
- Pre-filled obligation card (parcel no, owner, amount, due date)
- Editable payer fields (name, phone, ID card) — pre-filled from obligation owner
- Camera buttons for cash photo + ID photo
- Large "Confirm & Print Receipt" button

### Receipt Preview/Share (`lib/screens/agent/receipt_screen.dart`)
- Citizen copy: big text, agent stamp area, QR code for verification
- Share via Bluetooth/print/export PDF (uses `printing` + `share_plus` packages)

### Handover List (`lib/screens/agent/handover_list_screen.dart`)
- Grouped by date, expandable batch rows
- "Create Batch" FAB → navigates to batch builder

### Batch Detail (`lib/screens/agent/batch_detail_screen.dart`)
- Payment rows with checkboxes, running total
- "Handover to Officer" button (generates QR for officer scan)

---

## 6. Officer Back-Office Changes (minimal)

### Payment Verify (`public/admin/payments.php`)
- Filter `payment_method = cash_agent` → shows agent name, collected_at, handover status
- New column: "Agent" (links to agent profile)
- Verify/Reject actions work identically to bank receipts

### Reports (`public/admin/reports.php`)
- New report: **Agent Cash Collection**
  - Filters: village, agent, date range, handover status
  - Columns: date, agent, parcel, citizen, amount, collected_at, handover_status

---

## 7. Rollout Phases

| Phase | Scope |
|-------|-------|
| **1. MVP** | Agent collect → SUBMITTED → officer verify → PAID (online only, **one district per agent** — collects for any parcel in the district) |
| **2. Offline Queue** | Local SQLite queue, background sync when connectivity returns |
| **3. Multi-district / Provincial Agents** | Agent covers more than one district |
| **4. Commission/Incentive** | Track agent collection volume for performance |

---

## 8. Open Questions (Decided for MVP)

| Question | Decision |
|----------|----------|
| Agent authentication | Reuse `officer` role + `is_agent` flag (simpler, existing admin UI) |
| Payer ID card | Required field (text), photo optional |
| Receipt printing | PDF generation + share (Bluetooth thermal printer phase 2) |
| Handover verification | Officer manually selects batch from list + scans agent QR (phase 1: list only) |
| Commission tracking | Out of scope for MVP |
| Agent roles | Single "agent" type; `agent_role` field for reporting only |

---

## 9. Database Migration Plan

Create `sql/migrations/020_agent_cash_payment.sql` with:
1. Add columns to `payments` table
2. Add agent columns to `users` table
3. Create `agent_handovers` table
4. Create `payment_receipts` table
5. Indexes: `payments(agent_id)`, `payments(payment_method)`, `agent_handovers(agent_id, status)`
6. Unique index: `payments(obligation_id) WHERE payment_method='cash_agent' AND status IN ('ISSUED','SUBMITTED')` (one **open** collection per obligation, by any agent — a settled/rejected slip releases it)

---

## 10. API Contract Alignment

Follows existing patterns in `docs/9-citizen-app-api-contract.md`:
- Envelope responses: `{ ok: true, data: {...}, error: null }` (`json_ok` / `json_error`)
- Auth: `Authorization: Bearer <jwt>` (agent uses officer JWT)
- Error codes: `not_agent`, `agent_unscoped`, `obligation_not_in_district`, `already_collected`, `invalid_payment_selection`, `bad_state`

**No URL router exists.** Apache serves the `.php` files directly, so path-style URLs such as `/api/v1/agent/receipt/123` return 404. Every endpoint uses a query string, like `payments.php?id=N&slip=1`:

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET  | `api/v1/agent/obligations.php?fee_year=&status=&page=&limit=` | Payable obligations in the agent's village |
| GET  | `api/v1/agent/collections.php` | Collected cash not yet handed over |
| POST | `api/v1/agent/collect.php` | Record a cash collection |
| GET  | `api/v1/agent/receipt.php?payment_id=N[&format=html]` | Receipt (JSON for the app, HTML for printing) |
| GET  | `api/v1/agent/handovers.php?status=&page=&limit=` | Handover batch history |
| POST | `api/v1/agent/handovers.php` (`payment_ids` array) | Create a handover batch |
| GET  | `api/v1/officer/agent-handover.php?status=pending` | Officer review queue |
| POST | `api/v1/officer/agent-handover.php?id=N&action=verify\|reject` | Verify/reject a batch |
| POST | `api/v1/auth.php` (`action=login` / `action=me`) | Agent login and session refresh |

---

## 13. Implementation Status & Deploy Order

**Migration first.** Apply `sql/migrations/020_agent_cash_payment.sql` as `postgres` **before** deploying the code that reads the new columns. The web role (`landfee_web`) gets its grants from the migration's idempotent `DO` block; without them the agent endpoints return empty 500s.

```
psql -U postgres -d landfee -f sql/migrations/020_agent_cash_payment.sql
```

Implemented:
- **DB:** `020_agent_cash_payment.sql` — `payments.payment_method` (NOT NULL DEFAULT `'bank_transfer'`), agent/payer columns, `agent_handovers`, `payment_receipts`, `users.is_agent` + agent scope fields, indexes, partial unique open-collection index, views, grants.
- **Citizen app (Flutter):** login tab for agents (`auth.php?action=login`), `AgentDashboardScreen`, `AgentCollectScreen`, `AgentReceiptScreen` (rendered natively, no WebView), `AgentHandoversScreen` (select pending collections → batch), `AgentController`.
- **Officer back-office:** `agent_handovers.php` (verify/reject), agent fields on `users.php` (create/edit: assigned village, role, licence), `payments.php` ledger filter `cash_agent`, `payment_method_label('cash_agent')`.
- **API:** agent endpoints above; `auth.php` returns `is_agent`/`agent_village_id`/`agent_role` and `action=me`; `users.php` list/create/update agent fields (column-probed so it works pre-migration); `payments.php` supports `method=cash_agent`.

Not done yet (phase 2): cash photo/ID photo upload, commission tracking, thermal-printer integration, QR scan of the agent's batch at the counter. (The dedicated **Agent Cash Collection report** landed with this feature: `reports.php?report=agent`, grouped province → district → village with per-group subtotals.)

**Security guard (applied in review):** an agent is an `officer` with district scope, so the JWT technically allows normal officer writes. `require_not_agent()` (lib/auth.php) now refuses agent accounts on **every** `require_staff_write()` endpoint — an agent cannot verify a handover batch (self-approving uncollected cash), verify ledger payments, or write parcels/users/scans. The agent's own `agent/*` collection endpoints use plain `require_auth(['officer'])` and are unaffected. Remaining accepted gap: a genuine officer or admin can still verify any batch in their district — the control there is the physical handover, not the software.

---

## 11. Testing Checklist (Manual)

- [ ] District admin creates agent account → agent logs in, sees Agent Mode
- [ ] Agent dashboard shows their district's unpaid obligations (all villages)
- [ ] Collect cash form pre-fills correctly, allows payer override
- [ ] Submission creates payment with `payment_method=cash_agent`, status=SUBMITTED
- [ ] Receipt generated, contains all required fields, shareable
- [ ] Agent creates handover batch → appears in officer verify list
- [ ] Officer verifies batch → payments become PAID, obligation settled
- [ ] Officer rejects single payment → returns to agent pending
- [ ] Citizen (separate login) sees payment with agent name
- [ ] Reports show agent cash collection correctly

---

## 12. Related Documents

- `docs/9-citizen-app-api-contract.md` — citizen API patterns
- `docs/14-payment-verify.md` — officer verify flow (extends to cash_agent)
- `docs/16-reports.md` — report framework
- `docs/8-citizen-flutter-app-plan.md` — Flutter app architecture
- `sql/migrations/015_payment_submitted_status.sql` — SUBMITTED status exists