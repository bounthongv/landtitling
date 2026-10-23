# Land Fee Back-Office — Basic Data, User Management & Auth (Admin Scope)

> **Status:** already implemented (Phases G1–G3 of the v3 design) and live on `https://landtax.apis.com.la/`.
> This doc is the focused plan for the **admin-maintained parts** of the system. The full design lives in
> `docs/5-annual-land-fee-back-office-design.md`; the back-office build checklist is in
> `docs/4-plan-land-fee-mvp-v2.md`.

---

## 1. Scope (what the system administrator maintains)

These are the **reference/lookup data** and **users** that an administrator manages through the back-office.
They are the foundation everything else runs on.

| Area | What it covers | Screen |
|------|----------------|--------|
| **Basic data** | Province, districts, villages, road categories, title types, land-use classes, **zone rates (fee)**, fee years | `public/admin/basic_data.php` |
| **User management** | Create/edit officers & citizens, reset password, activate/deactivate, link a citizen to their owner record | `public/admin/users.php` |
| **Authentication** | Officer login (username/password) + citizen login (**email OTP**) | `api/v1/auth.php`, `public/citizen/login.php` |

---

## 2. Basic Data (`basic_data.php`) — ✅ implemented, live

The admin maintains the province's reference data here. Deleting a row that is still referenced
is refused with a message naming the counts (not a raw FK error).

| Lookup | What the admin can do |
|--------|-----------------------|
| **Province** | Add/edit the province name (currently single-province: Savannakhet) |
| **Districts** | Add/edit the 15 Savannakhet districts |
| **Villages** | Add/edit the 1,029 villages (belong to a district) |
| **Road categories** | `main` / `branch` / `alley` / `none` (labels + order) |
| **Title types** | Land title, survey certificate, concession, other |
| **Land-use classes** | Residential, agricultural, commercial, other |
| **Zone rates (fee)** | The **rate per m²** per district × road category — **this is the fee tariff** |
| **Fee years** | The annual billing year + due date (drives obligation generation) |

> **Important:** the zone rates currently in the DB are **placeholders** (80–180k band). The
> province owns the real numbers and revises them through this Basic Data screen. Nothing in the
> seed is an approved tariff.

### Backing tables
```
provinces, districts, villages, road_categories, title_types, land_use_classes,
zones_rates, fee_years
```
Maintained via `api/v1/refdata.php` (whitelisted table names — never interpolated from the request).

---

## 3. User Management (`users.php`) — ✅ implemented, live

Admin-only (role `admin`). An officer role cannot open this page (403).

| Action | Notes |
|--------|-------|
| **Create staff** | Add an officer/admin account (username + password) |
| **Edit staff** | Change username/role |
| **Reset password** | Set a new password for a staff account |
| **Activate / deactivate** | Disable a login (deactivated citizens cannot request an OTP) |
| **Link citizen → owner** | Connect a citizen login to an owner record — **without this link the citizen sees nothing** (scoping is by `owners.citizen_user_id`) |

**Guards:**
- An admin cannot deactivate their own account.
- The last active admin cannot be demoted or disabled.
- Candidate-owner search matches owner name, ID number, phone **and parcel number** (officers usually know the parcel).

### Backing table: `users`
Maintained via `api/v1/users.php` (admin-only).

---

## 4. Authentication — ✅ implemented, live

### 4.1 Officer login (username/password)
`POST /api/v1/auth.php` `{action: "login", username, password}` → JWT (HS256).
Session-stored on the server; dashboard and all back-office pages check the JWT.

### 4.2 Citizen login (email OTP)
The **SMS channel was dropped** (no gateway available) — login is now **email-based**.

| Step | API action | What happens |
|------|-----------|--------------|
| 1. Request code | `otp/request` `{email}` | Upserts a citizen by email (auto-registers first-time users), issues a **stateless HMAC-signed OTP token**, emails the code via `lib/mail.php` |
| 2. Verify | `otp/verify` `{email, code, otp_token}` | Verifies signature + code → returns JWT |

**Security properties (implemented):**
- **Stateless token** — no PHP session needed (the citizen pages call the API server-side, so sessions never round-trip).
- **Code never leaked** — `debug_code` only appears when `OTP_DEBUG=1` is set. It is **unset in production**.
- **Deactivated citizens blocked** — cannot request or verify a code.
- **`users.email`** — nullable, case-insensitive unique index.

### 4.3 Delivery requirement (open item)
`otp_send_email()` → PHP `mail()` needs:
- a **system MTA** (mail server) on the host, **and**
- `MAIL_FROM` in `.env` (gitignored).

Without them, `otp/request` still succeeds but returns `"sent": false` — **no code is ever delivered**.
This must be configured before real citizens can log in.

---

## 5. How to verify each part (browser)

| Step | URL | Action | Expected |
|------|-----|--------|----------|
| 1 | `https://landtax.apis.com.la/` | → admin login | `sav_officer` / `demo1234` → dashboard |
| 2 | `/public/admin/basic_data.php` | Open each tab | Edit a zone rate → persists; delete an in-use row → refused with counts |
| 3 | `/public/admin/basic_data.php` | Add a fee year | New year appears; drives obligation generation |
| 4 | `/public/admin/users.php` | Create a staff user | New officer can log in |
| 5 | `/public/admin/users.php` | Link a citizen login to an owner | Citizen then sees their parcel in the PWA |
| 6 | `/public/citizen/` | Email OTP login | Request code → code emailed (or `debug_code` if `OTP_DEBUG=1` locally) → verify → sees parcels |

---

## 6. Status summary

| Item | Status |
|------|--------|
| Basic Data screen (province/district/village/zone/fee/fee-year) | ✅ implemented, live (HTTP 200) |
| User Management screen (create/reset/deactivate/link) | ✅ implemented, live (HTTP 200) |
| Email OTP auth (officer password + citizen email OTP) | ✅ implemented, live |
| Live DB migrated to v3 schema (fee_years, road_categories, etc.) | ✅ done (2026-10-17) |
| DB privileges for `landfee_web` on new tables | ✅ done |
| Email delivery (MTA + `MAIL_FROM`) | ⏳ **open** — needed before real citizen logins |
| Real zone-rate tariff from the province | ⏳ open — revise via Basic Data |

---

## 7. Next actions

1. **Verify in browser** — walk through the table in §5 and report any issues.
2. **Configure email delivery** — set up a system MTA + `MAIL_FROM` in `.env` so OTP codes actually reach citizens.
3. **Load real zone rates** — the province enters the actual fee tariff via Basic Data.
4. **Test the citizen link** — create a citizen login, link it to an owner, confirm the PWA shows their parcel + obligation.