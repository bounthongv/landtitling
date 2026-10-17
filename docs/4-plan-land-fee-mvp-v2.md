# Land Fee Payment MVP — Back-Office Web Build Plan (Page by Page)

> **Savannakhet Province.** | **Status:** live on `https://landfee.apis.com.la/` | **Approach:** Build back-office web page by page, test through real web UI
>
> **v3 design (annual fee + title parsing + auto-verify):** see `docs/5-annual-land-fee-back-office-design.md`. It supersedes the fee/payment parts of this plan.
>
> **Admin scope (basic data + user management + email OTP auth):** see `docs/6-admin-basic-data-users-auth.md`.

---

## 1. What is this project?

A **Land Fee Payment MVP** for Savannakhet Province (Lao). Two front-ends over **one PHP JSON API + PostgreSQL**:

| Front-end | URL | Purpose |
|-----------|-----|---------|
| **Citizen PWA** | `https://landfee.apis.com.la/public/citizen/` | Mobile-first: phone-OTP login → parcel lookup → fee quote → create payment → print slip → upload slip photo → track status |
| **Officer Back-Office** | `https://landfee.apis.com.la/` (root → admin) | **THIS IS WHAT WE BUILD NOW** — page by page, test through real web UI |
| **JSON API** | `https://landfee.apis.com.la/api/v1/` | Powers both front-ends (already built) |

**Fee model (locked):** `fee = zone_rate(district, road_category) × area_sqm` — flat, location-only.

---

## 2. Current live URLs

| What | URL | Expected |
|------|-----|----------|
| **Admin login (root)** | `https://landfee.apis.com.la/` | 302 → login (root now redirects to admin) |
| **API health** | `https://landfee.apis.com.la/api/v1/districts.php` | 200 + JSON |
| **Citizen PWA** | `https://landfee.apis.com.la/public/citizen/` | 302 → login |
| HTTP → HTTPS | `http://landfee.apis.com.la/` | 301 → HTTPS |

---

## 3. Credentials

| Role | Username | Password |
|------|----------|----------|
| **Officer** | `sav_officer` | `demo1234` |
| **Citizen** | phone OTP | dev stub (code in JSON `meta.debug_code`) |

---

## 3.1 Current Back-Office Pages (already exist, need review/build)

| Page | File | URL | Status |
|------|------|-----|--------|
| **Login** | `public/admin/login.php` | `/public/admin/login.php` | ✅ exists |
| **Dashboard** | `public/admin/index.php` | `/public/admin/` | ✅ exists |
| **Scan Review Queue** | `public/admin/scans.php` | `/public/admin/scans.php` | ✅ exists |
| **Parcel Registry** | `public/admin/parcels.php` | `/public/admin/parcels.php` | ✅ exists |
| **Zone Rate Editor** | `public/admin/rates.php` | `/public/admin/rates.php` | ✅ exists |
| **Payment Verify Queue** | `public/admin/payments.php` | `/public/admin/payments.php` | ✅ exists |
| **Reports** | `public/admin/reports.php` | `/public/admin/reports.php` | ✅ exists |
| **Common (auth, nav, style)** | `public/admin/common.php`, `style.php` | — | ✅ exists |

---

## 4. Build Approach: Page by Page, Test in Browser

**Workflow for each page:**
1. **Open the page** in browser at `https://landfee.apis.com.la/public/admin/xxx.php`
2. **Test the UI flow** — click, fill, submit, verify
3. **Fix any issues** (PHP errors, JS errors, API errors, UI bugs)
4. **Commit & push** → auto-deploys to live
5. **Move to next page**

**Test credentials:** `sav_officer` / `demo1234`

---

## 4. Page Build Order (with test checklist)

### Page 1: Login (`/public/admin/login.php`) ✅
- [ ] Open `https://landfee.apis.com.la/public/admin/`
- [ ] Should redirect to `login.php`
- [ ] Enter `sav_officer` / `demo1234` → submit
- [ ] Should redirect to dashboard (`/public/admin/`)
- [ ] Verify session persists on refresh

### Page 2: Dashboard (`/public/admin/`) 
- [ ] Shows counts: districts, villages, parcels, scans, payments
- [ ] Shows recent scans needing review
- [ ] Navigation sidebar works (links to all pages)
- [ ] Logout works

### Page 3: Scan Review Queue (`/public/admin/scans.php`)
- [ ] List shows pending scans (or "no scans" message)
- [ ] **Upload scan**: choose image → submit → shows in queue
- [ ] **Review scan**: click scan → shows image + OCR extracted fields side by side
- [ ] **Confirm/Edit**: edit district/village/parcel/area → click Confirm → creates parcel
- [ ] Scan moves from "pending" to "confirmed" in list
- [ ] AJAX dropdowns for district → village work

### Page 4: Parcel Registry (`/public/admin/parcels.php`)
- [ ] List all parcels with pagination
- [ ] Filter by district, parcel number, status
- [ ] Click parcel → shows detail (area, road category, village, scan image)
- [ ] Edit parcel fields works
- [ ] Delete parcel works (with confirmation)

### Page 5: Zone Rate Editor (`/public/admin/rates.php`)
- [ ] Grid: district × road_category (main/branch/alley/none) → rate_per_sqm
- [ ] Edit a rate inline → save → persists
- [ ] Add new district-rate row works
- [ ] Rates used by fee quote API

### Page 6: Payment Verify Queue (`/public/admin/payments.php`)
- [ ] List all payments with status pills (ISSUED/PAID/REJECTED)
- [ ] Filter by status, date range, district
- [ ] Click payment → shows detail + slip image + QR
- [ ] **Verify**: click Verify → status → PAID, paid_at set
- [ ] **Reject**: click Reject → status → REJECTED, reason stored
- [ ] Rejected payment returns to ISSUED for citizen

### Page 7: Reports (`/public/admin/reports.php`)
- [ ] Filter by district, date range
- [ ] Shows fee totals by district, month, transaction type
- [ ] **Export CSV** → downloads properly formatted
- [ ] **Export JSON** → valid JSON

### Page 8: Common / Navigation / Style
- [ ] Sidebar navigation highlights current page
- [ ] Responsive: works on desktop + tablet
- [ ] Lao/English labels consistent
- [ ] No console errors (JS/CSS)

---

## 5. Current Status (2026-10-15)

| Area | Status |
|------|--------|
| **API** | ✅ working (all endpoints 200) |
| **Officer Back-Office** | ✅ deployed, pages exist |
| **Citizen PWA** | ✅ deployed |
| **SSL/HTTPS** | ✅ working |
| **CI/CD** | ✅ auto-deploy on push |
| **DB** | ✅ 15 districts, 1029 villages, 60 rates |

**Login:** `sav_officer` / `demo1234` at `https://landfee.apis.com.la/public/admin/`

---

## 6. Remaining Work (Phase 2d — Page by Page)

| # | Page | What to do | Priority |
|---|------|------------|----------|
| 1 | **Login** | Test login flow, session, logout | HIGH |
| 2 | **Dashboard** | Verify counts, nav, logout | HIGH |
| 3 | **Scan Review** | Test upload, OCR review, confirm/edit → parcel | HIGH |
| 4 | **Parcel Registry** | Test list, filter, detail, edit | HIGH |
| 5 | **Zone Rates** | Test grid edit, add row, API integration | HIGH |
| 6 | **Payment Verify** | Test detail, verify, reject, status change | HIGH |
| 7 | **Reports** | Test filters, CSV/JSON export | MEDIUM |
| 8 | **Nav/Style** | Fix any UI bugs, responsive, Lao labels | MEDIUM |
| 9 | **Real zone rates** | Get official rates from PERN, replace placeholders | HIGH |
| 10 | **SMS gateway** | Replace OTP dev stub with real SMS/email | MEDIUM |
| 11 | **LaoQR** | Decide plain vs LaoQR, implement | MEDIUM |
| 12 | **CSV import** | Bulk import paper titles → parcels | LOW |
| 13 | **Audit log** | Wire key actions (verify, confirm, rate edit) | LOW |
| 14 | **District Lao-name fix** | Confirm 1311–1315 with PERN | LOW |

---

## 7. Deploy / CI-CD

**Push to main → auto-deploys code only:**
1. SSH to `apis.com.la` (user `apis`, key `VPS_SSH_KEY`)
2. `cd /var/www/html/landfee && git pull origin main`
3. `chown -R apis:www-data /var/www/html/landfee`
4. `sudo systemctl restart apache2`

**DB changes are manual** — schema/seed applied on server when you decide.

---

## 8. Next Action

**Open the live back-office at the root URL:**

```
https://landfee.apis.com.la/
```

**Credentials:** `sav_officer` / `demo1234`

Test each page in order, fix issues, commit, push → auto-deploys. Move to next page.

---

**That's it. Simple: open page → test → fix → push → next page.**