# Land Title System — Back-Office + Citizen Portal Architecture

## Overview

Two-app architecture sharing a common backend:

| App | Audience | Purpose |
|-----|----------|---------|
| **Officer Back-Office** | Land officers, district/province staff | Case management, title issuance, transfer/mortgage workflows, mortgage registration, reporting |
| **ປ່ອງບໍລິການສາທາລະນະ (Citizen Portal)** | Land owners, citizens, notaries | Request ໃບແຈ້ງມີ, report issues, request documents, track status, download PDFs |
| **Shared API + DB** | Both apps | Parcels, holders, workflows, documents, fees, notifications |

---

## 1. Officer Back-Office (Current Prototype)

**File:** `D:\LandTitling\prototype\index.html`

**Features Implemented:**
- Dashboard with stats, recent activity, district breakdown
- Parcel search + detail modal (real Lao title format)
- Transfer workflow (5-step: Request → Verify → Approve → Update Rights → History)
- Mortgage register/release
- GIS map with parcel pins
- Reports (land use, transfers/year, mortgage status, holder types)
- Add/Import (manual entry + legacy import mock)
- Printable Land Title (A4, matches real Lao format, geo-QR)

**Data Model:** 6 demo parcels, 7 holders, Savannakhet districts

---

## 2. Citizen Portal — MVP Scope

### Core User Journeys (Priority Order)

| # | Journey | Description |
|---|---------|-------------|
| **1** | **ໃບແຈ້ງມີ (Land Availability Certificate)** | Citizen enters parcel code → checks eligibility → submits request → officer reviews → citizen downloads PDF with geo-QR. Highest demand: needed for mortgage/finance. |
| **2** | **Land Issue Reporting** | Boundary disputes, encroachment, data errors. Photo + GPS + description → auto-routes to district officer → status updates via SMS/push. |
| **3** | **Document Requests** | Title copy, map extract, transaction history. Pre-filled from parcel data → fee calc (via Document Items) → payment slip upload → officer verifies. |
| **4** | **Transfer/Mortgage Initiation** | Citizen starts (pre-populates transferee info) → officer completes. Digital signature in Phase 3. |

---

### Technical Stack (Laos Context)

| Concern | Recommendation |
|---------|----------------|
| **Platform** | PWA (Progressive Web App) — single codebase, installs on Android/iOS, works offline, no app store approval needed for gov deployments. React/Vue + Capacitor for future native. |
| **Connectivity** | Offline-first: cache requests, sync when online. Service Worker for static assets. |
| **Language** | Lao-first UI, English fallback. Lao numerals option (໐໑໒...). |
| **Auth** | National ID (ບັດປະຈໍາຕົວ) + OTP SMS. No passwords. Link to existing holder records. |
| **Payments** | Slip upload first (matches back-office flow). Integrate BCEL/BCEL One later. Fee per service via existing Document Items. |
| **Notifications** | SMS gateway (works on feature phones) + push for smartphones. Template: request received, under review, approved, ready for download. |
| **Hosting** | Gov cloud or VPS in Thailand/Singapore. CDN for static assets. HTTPS mandatory. |
| **GIS** | Leaflet/MapLibre for map view. Parcel boundaries from back-office GeoJSON. |

---

### Data Model Extensions (Shared DB)

```sql
-- Citizen requests
CREATE TABLE citizen_requests (
  id UUID PRIMARY KEY,
  parcel_code VARCHAR(50) REFERENCES parcels(code),
  holder_id UUID REFERENCES holders(id),
  request_type VARCHAR(30), -- 'LAND_AVAILABILITY', 'DOCUMENT_COPY', 'ISSUE_REPORT', 'TRANSFER_INIT'
  status VARCHAR(20), -- 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'READY_DOWNLOAD'
  payload JSONB, -- request-specific data
  fee_amount BIGINT DEFAULT 0,
  payment_slip_url TEXT,
  officer_id UUID, -- assigned officer
  created_at TIMESTAMP DEFAULT NOW(),
  updated_at TIMESTAMP DEFAULT NOW(),
  completed_at TIMESTAMP
);

-- Notifications
CREATE TABLE notifications (
  id UUID PRIMARY KEY,
  request_id UUID REFERENCES citizen_requests(id),
  channel VARCHAR(10), -- 'SMS', 'PUSH', 'EMAIL'
  recipient VARCHAR(50), -- phone/token
  template_key VARCHAR(50),
  payload JSONB,
  sent_at TIMESTAMP,
  status VARCHAR(10)
);

-- Documents (generated PDFs)
CREATE TABLE generated_documents (
  id UUID PRIMARY KEY,
  request_id UUID REFERENCES citizen_requests(id),
  doc_type VARCHAR(30), -- 'LAND_AVAILABILITY_CERT', 'TITLE_COPY', 'MAP_EXTRACT'
  file_url TEXT, -- S3/minio path
  qr_payload TEXT, -- geo: URI or verification URL
  created_at TIMESTAMP DEFAULT NOW()
);
```

---

### API Contract (Citizen Portal ↔ Back-Office)

```
POST   /api/v1/requests              # Submit new request
GET    /api/v1/requests/:id          # Get request status + details
GET    /api/v1/requests              # List citizen's requests (auth)
POST   /api/v1/requests/:id/payment  # Upload payment slip
GET    /api/v1/parcels/:code         # Public parcel info (for verification)
GET    /api/v1/documents/:id/download # Download generated PDF
POST   /api/v1/auth/otp/request      # Request OTP via SMS
POST   /api/v1/auth/otp/verify       # Verify OTP → returns JWT
```

**Back-Office Officer Endpoints:**
```
GET    /api/v1/officer/requests              # Paginated, filterable
PUT    /api/v1/officer/requests/:id/review   # Approve/reject + notes
POST   /api/v1/officer/requests/:id/generate # Generate PDF document
PUT    /api/v1/officer/requests/:id/assign   # Assign to officer
```

---

## 3. Integration Points

| Direction | Mechanism |
|-----------|-----------|
| Citizen → Back-Office | New request appears in officer dashboard as "New Case" card |
| Back-Office → Citizen | Status change triggers SMS/push notification |
| Back-Office → Citizen | Document generation → PDF stored → citizen gets download link |
| Back-Office ↔ GIS | Parcel boundaries synced to citizen map view |

---

## 4. Phased Rollout Plan

| Phase | Timeline | Scope |
|-------|----------|-------|
| **Phase 1** | 2–3 months | ໃບແຈ້ງມີ request flow, status tracking, PDF download with geo-QR, SMS notifications, officer review UI |
| **Phase 2** | +2 months | Issue reporting (photo+GPS), document requests (title copy, map), fee calc + slip upload |
| **Phase 3** | +3 months | Transfer/mortgage initiation, digital signature (Docusign-like), BCEL payment gateway, native app builds |

---

## 5. Citizen Portal Prototype (Demo)

**File:** `D:\LandTitling\prototype\citizen\index.html`

**Implemented Features:**
- Mobile-first responsive layout (480px max, simulates phone screen)
- Lao gov branding with official styling
- Home screen with 4 service cards + request status cards
- **ໃບແຈ້ງມີ flow:** Parcel lookup → eligibility check (rejects mortgaged/pending parcels) → fee display → submit → confirmation with next steps timeline
- **Document requests:** Title copy, map extract, transaction history with fee calculation + payment slip upload
- **Issue reporting:** Type selector, parcel link, description, photo upload (3 slots), GPS map placeholder
- **Status tracking:** Request list with status badges → detail view with progress stepper + timeline
- Mock SMS notifications (toast messages)
- PWA manifest (installable on Android/iOS)
- Bottom navigation bar (mobile pattern)
- Back navigation stack

**How to Demo:**
1. Open `prototype/citizen/index.html` in a mobile browser or resize desktop to ~400px
2. Try the ໃບແຈ້ງມີ flow with parcel code `0130.001.02.011.0033` (eligible) or `0130.002.03.021.0344` (mortgaged → blocked)
3. Check "Track Requests" to see submitted requests with timeline
4. Try document requests and issue reporting

---

## 6. Security & Compliance

- **Data residency:** All data in Laos/approved region
- **Audit trail:** All actions logged (who, what, when)
- **Access control:** Role-based (Citizen, Officer, Admin, Auditor)
- **PII protection:** National ID encrypted at rest; minimal storage
- **OCR/ID verification:** Future: integrate with national ID system

---

## 7. Architecture Improvements (Added)

### 7a. Citizen Auth — Enhanced Flow
The original spec mentions National ID + OTP. For the prototype, we demonstrate a **simplified auth flow** (no login required for demo). Production recommendations:
- **Step 1:** Enter national ID number → system looks up holder record
- **Step 2:** OTP sent to registered phone number
- **Step 3:** JWT token issued, linked to holder_id
- **Step 4:** All requests auto-linked to authenticated holder

### 7b. Offline-First Strategy
For rural Laos with intermittent connectivity:
- Service Worker caches static assets (HTML, CSS, JS)
- Requests queued in IndexedDB when offline
- Background sync when connectivity restored
- SMS notifications as fallback for non-smartphone users

### 7c. Payment Evolution
| Phase | Method | Notes |
|-------|--------|-------|
| Phase 1 | Bank slip upload | Citizen uploads photo of deposit slip |
| Phase 2 | BCEL One QR | Generate QR for mobile banking payment |
| Phase 3 | Direct gateway | API integration with BCEL/LDB systems |

### 7d. Shared Components
Both apps share:
- Parcel data model and search logic
- Holder records and verification
- Document generation (PDF + QR)
- Notification templates and delivery
- Fee calculation (Document Items)

---

## 8. Next Steps

1. ✅ Architecture documented
2. ✅ Build citizen portal prototype (`citizen/index.html`) — **Complete**
3. ⬜ Add OTP login screen to citizen portal
4. ⬜ Build officer-side request review UI in back-office
5. ⬜ Define API contracts in OpenAPI/Swagger
6. ⬜ Set up shared PostgreSQL schema
7. ⬜ SMS gateway integration (Lao telco)
8. ⬜ Pilot with one district office