# Land Title Management System — AGENTS.md

## What is this?
Two-app prototype for a land title registry (Lao language):
- **Back-Office:** Officer app for parcel management, transfers, mortgages, reporting
- **Citizen Portal:** Mobile-first PWA for citizens to request documents, report issues, track status

## Key Files
| File | Purpose |
|------|---------|
| `prototype/index.html` | Officer back-office prototype (HTML/CSS/JS) |
| `prototype/citizen/index.html` | Citizen portal prototype (mobile-first PWA) |
| `prototype/citizen/manifest.json` | PWA manifest for citizen portal install |
| `prototype/build/gen_qr.py` | Generates QR codes for title parcels |
| `docs/2-back-office-portal-architecture.md` | System architecture (both apps) |
| `docs/LandTitling.md` | System requirements (Lao/English) |

## How to Run
1. **Back-Office:** Open `prototype/index.html` — login: `officer` / `demo1234`
2. **Citizen Portal:** Open `prototype/citizen/index.html` — resize browser to ~400px for mobile view

## Architecture Notes
- Pure HTML/CSS/JS — no build system, no framework
- Mock data embedded in JS (PARCELS, PERSONS, DISTRICTS constants)
- Both apps share the same visual language (gov green + gold branding)
- Citizen portal is mobile-first with bottom navigation bar

## Citizen Portal Features
- **ໃບແຈ້ງມີ** — Land Availability Certificate request (parcel lookup → eligibility → submit → track)
- **Document Requests** — Title copy, map extract, history (with fee calc + payment slip)
- **Issue Reporting** — Boundary disputes, encroachment, data errors (photo + GPS)
- **Status Tracking** — Timeline view of all requests
- Mock SMS notifications (toast messages)
- PWA installable on Android/iOS

## Demo Data
- 6 parcels across 6 districts in Savannakhet
- Pre-loaded sample requests in citizen portal (REQ-2026-001, REQ-2026-002)
- Eligibility checks: parcels with mortgages are blocked from ໃບແຈ້ງມີ

## Important
- This is **demo-only** — no backend, no database
- For real implementation: need backend, database, OTP auth, SMS gateway, GIS server
