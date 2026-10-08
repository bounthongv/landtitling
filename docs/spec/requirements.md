# Land Title Management System — Consolidated Requirements (SRS)

> **Status:** DRAFT v1.0 — now covers **all 20 reachable legacy videos**
> (19 transcripts: 13 YouTube ASR tracks + 6 local Whisper transcriptions of
> user audio + 1 SETUP clip). The only clip without a transcript is 19
> (pgAdmin-fix), whose YouTube video is gone (404).
>
> **Source basis:** `docs/llr7-spec/NN_*.md` (one file per video). Every
> requirement cites its source file. Items the ASR could not confirm are
> tagged **[verify]**. The 6 Whisper-based files (05, 07, 08, 09, 15, 16)
> transcribe **Thai** narration — workflow-level detail, all proper nouns
> [verify].

---

## 1. Executive summary

The legacy system is **LaoLandReg V7.2 (LLR7)** — a **QGIS 3.34 desktop plugin over
PostgreSQL 15 / pgAdmin4**, with GPS field capture that is packaged into files and
synced to a central server. The new system must be a **web-based land-title
registry** (officer back-office + citizen portal) that is **"not worse than
legacy"**, and adds the citizen-facing services the prototype already sketched
(ໃບແຈ້ງມີ, document requests, issue reporting, status tracking).

What the legacy covers, therefore defines the **minimum feature floor** for the
new system:

| Legacy capability (source) | New-system requirement |
|---|---|
| New title registration (03) | Create a parcel + rights-holder title from survey data |
| Full parcel transfer (04) | Transfer rights (sale / gift / inheritance) with valuation + fees |
| Parcel **subdivision** (05) | Split a parcel into registered sub-parcels |
| Parcel **merging** (07) | Combine parcels into one |
| Valuation + fee calc (06) | Zone-based valuation + registration/service/survey fee engine |
| Legacy data import (08) | Migrate pre-digital records into the digital system |
| Mortgage / loan contract (09) | Register a mortgage/loan against a parcel |
| Title authenticity letter (10) | Issue ໃບຢັ້ງຢືນຄວາມຖືກຕ້ອງຂອງໃບຕາດິນ (see §9) |
| Field → server sync (11, 15) | Two-tier sync: field local DB → central server (`.Lreg` export) |
| GIS tools (13, 14, 17, 20, 21) | Splitting (buffer/QAD), parcel editing, coordinate conversion |
| DB admin (01, 18, 16) | Per-district DB lifecycle, backup/restore |

**Decision point — the biggest open question in the whole project** (see §12 Q-1):
is the legacy **ໃບຢັ້ງຢືນຄວາມຖືກຕ້ອງຂອງໃບຕາດິນ** (EP10 "title authenticity letter") the
same document as the citizen portal's hero feature **ໃບແຈ້ງມີ** ("land availability
certificate"), or are they two distinct documents? The review meeting must settle
this.

---

## 2. System context

```
   Field (GPS/QGIS local DB)          Central office                 Citizens
   ┌──────────────────────┐   ┌──────────────────────────────┐  ┌──────────────────┐
   │ Field QGIS + LLR     │   │ Officer Back-Office (web)    │  │ Citizen Portal   │
   │  (LLR7.2 today)      │──▶│  + GIS server + PostgreSQL   │◀─│ (PWA, ປ່ອງບໍລິການ  │
   │  export user +       │   │  (new: replace the desktop  │  │  ສາທາລະນະ)        │
   │  parcel files        │   │   plugin w/ web + API)       │  │ ໃບແຈ້ງມີ, docs,   │
   └──────────────────────┘   └──────────────────────────────┘  │ issues, status   │
      file transport (USB/                                        └──────────────────┘
      courier/share) [verify]
```

- **Two apps, one data core** (per `docs/2-back-office-portal-architecture.md`):
  officer back-office and citizen portal share a single PostgreSQL DB + GIS layer.
- **Two-tier data flow** (from 11): field teams run a *local* DB; users are
  exported **once**, then parcels are exported **repeatedly** and imported into
  the *central* server DB. The new system needs an equivalent sync mechanism
  (see FR-SYNC).
- **Deployment:** per-district databases (name = district/province, English only,
  owner `postgres` or `dbperlet` [verify]) — one DB per district (01, 18).

---

## 3. Roles & permissions

Derived from the transcripts (roles are named in several files; confirm at review):

| Role | Evidence | Capabilities |
|---|---|---|
| **Field operator / surveyor** | 11, 13, 17, 21 | Capture/edit parcels, run GIS split tools, export users+parcels |
| **Registration officer** | 04, 10 | Run transfer / authenticity-letter workflows, print |
| **Valuation / fee officer** | 06 | Compute zone valuation + fees, enter valuation zone |
| **District / province admin** (`ຜູ້ຄຸ້ມຄອງ`) | 11, 17 | Import users, manage per-district connection settings |
| **System admin** | 11 | System admin → Admin → Import users |
| **Printer / document unit** | 04, 10 | Produce printouts, route document sets |
| **DBA** | 01, 18, 16 | Create/restore/backup the per-district DB |

Open: whether a single officer may complete a whole transfer unilaterally, or a
second sign-off is required (Q from 04). **[verify]**

---

## 4. Functional requirements

Each requirement lists its source file. `[verify]` = not confirmed by ASR.
**(audio pending)** = source clip has no transcript yet.

### 4.1 New title registration (03)

- **FR-REG-1** Import survey points from a field file (Excel/CSV or the system's
  point export). Points are **not** addable manually — they come from the import
  file. *[verify — manual add observed not to work]*
- **FR-REG-2** Verify each imported point against the real location using satellite
  imagery / base map (a base-map plugin is required on fresh installs).
- **FR-REG-3** Set the project CRS to **WGS84 (EPSG:4326)**; export/save files with
  a WGS84 marker ("84" naming convention). *[verify]*
- **FR-REG-4** Import parcel geometry; run a **"verify import"** check that flags
  overlapping/parallel geometry errors; the operator fixes and re-imports until clean.
- **FR-REG-5** On success the parcel's symbol **changes colour** to indicate it is
  registered.
- **FR-REG-6** Data entry (per district/city rules): parcel number, area, use type,
  road type (4 main types [verify]); urban-planning zone. **Land data is entered
  before house data.**
- **FR-REG-7** Owner/family data entered **exactly as on the documents** (registered
  family name, national ID [verify], occupation, family data, current address even
  if the owner lives elsewhere).
- **FR-REG-8** Bind into the registration book (ການຜູກມັດ) once all personal data is
  entered; print **2 copies** of the parcel file; produce the printed notice
  (ໃບແຈ້ງໜີ້).
- **FR-REG-9** **Select the correct region/zone per district rules** (zones ເຂດ 47
  / ເຂດ 48; north includes ໄຊຍະບຸລີ, ບາງບໍ່ແກ້ວ) before proceeding.

### 4.2 Full parcel transfer (04)

- **FR-XFER-1** Choose operation type (transfer), then a registration type
  (e.g. ສິດນຳໃຊ້ທີ່ດິນ / land-use rights).
- **FR-XFER-2** Locate the target parcel by name or number at the land level, or via
  the land layer + district filter.
- **FR-XFER-3** For co-owned parcels, add **every** co-owner; enter applicant data
  (national ID, nationality, occupation, owner type, address). **[verify: is one
  co-owner's consent enough, or must all sign?]**
- **FR-XFER-4** Select delivery type per the supporting documents — sale / gift /
  inheritance (ມໍລະດົກ); enter house number where applicable.
- **FR-XFER-5** Complete the export request, print for document tracking, attach the
  printed copy, route to the registration unit; collect images + fee data.
- **FR-XFER-6** Officer completion: open the last form, previous rights-holder,
  person/entity symbol, opinion (shows username), handover; issue the **permanent
  change order** and **registration-completion** commands; press the red button in
  the registration document.
- **FR-XFER-7** **Status-colour gate:** the step must close to **green** before the
  parcel may be transferred again; in-progress = blue/yellow [verify].
- **FR-XFER-8** Before a further transfer, re-enter the **valuation** (zone, road
  type, area, structure/building check). A parcel already transferred multiple
  times supports **re-transfers** (the sample had a third transfer).
- **FR-XFER-9** Standard printout at the end is a **two-part template**
  (withdraw/place) *[verify]*.

### 4.3 Valuation + fee calculation (06)  — *least-reliable source (Thai ASR of a Lao video; all [verify])*

- **FR-FEE-1** Two document sheets: a **cover sheet** (produced at registration and
  re-produced on every movement) and a **document sheet** (paperwork), each with its
  own barcode/code.
- **FR-FEE-2** On the fee-calc screen, check the **document/movement type** first
  (e.g. a full transfer = "change-of-rights registration").
- **FR-FEE-3** Scan the **document-sheet barcode**; scanning the cover-sheet barcode
  is not accepted (they are unrelated). Fallback: copy the code from the
  entry/exit information (village per the cover sheet).
- **FR-FEE-4** Open the **valuation-zone layer** (edit mode [verify]), read the zone
  code from the bottom panel (e.g. "A7"), and enter the **digit only** (e.g. "7"),
  not the English letter code. **[verify: rule when code is letter+digit like G17?]**
- **FR-FEE-5** Check the parcel's structures (building/construction type) and use
  type; the fee formula may show a **Factor** then a price. Service fees sit under
  the service-fee menu; **survey/measurement fees do not appear on this screen**.
  **[verify]**
- **FR-FEE-6** For other land types the fee is computed per the document sheet.

### 4.4 Title authenticity letter (10)

- **FR-LTR-1** Reception (ຄ່າເຂົ້າ): pick the parcel's village, open data editing, add
  the **request set** then the **request presenter** (the owner; name + data exactly
  per the title; residence per ID).
- **FR-LTR-2** Search the parcel by number, press search, bring it into the request.
- **FR-LTR-3** Select registration type, then in the "issue letter" menu choose the
  request type = **"issue the title-authenticity letter"**; enter the
  **local-administration number** from the submission cover-set; purchase price may
  be blank for a submission case.
- **FR-LTR-4** Export the request; print a copy for tracking + a copy for the citizen
  submitter; attach to the submission cover-set and send to the registration unit.
- **FR-LTR-5** At the registration unit, work through the sub-steps: **fee
  calculation → approval → fee-fee → tax sending → payment confirmation**; the
  "complete registration" command makes a red button appear.
- **FR-LTR-6** Confirm the land-use change; set status to **completed**; run
  "complete all requests". The request-set dot must be **green** before the letter
  prints and before any further transfer.
- **FR-LTR-7** On the parcel, the attached **request set** (number + date) is the
  **history of authenticity letters**; clicking it shows when a letter was issued.
  Print the letter (number = local-administration number).
- **FR-LTR-8** Officer checks documents + movements, notes any movement on the
  letter, then hands it to the citizen — procedure complete.

### 4.5 Field → server sync (11, 15)

- **FR-SYNC-1** **Users are exported first, once.** From the field local DB: select
  province + district, tick the user boxes, use the **save-data** button → file is
  written to a chosen location. Message "export complete".
- **FR-SYNC-2** The server **remembers** users after the first export; thereafter
  **parcels** are exported and imported **repeatedly**, one file at a time.
- **FR-SYNC-3** Central side: log out, re-point settings to the real server
  (IP [verify], **port 9901** [verify], DB name = the district DB, user
  `dbperlet` [verify], central-provided password); test connect → success.
- **FR-SYNC-4** The **DOC** (document) DB uses the same IP/port, DB name
  "document", same user.
- **FR-SYNC-5** Import path: **System admin → Admin → Import users**; browse to the
  file, tick users, Import → "import successful" lists the names.
- **FR-SYNC-6** **Parcel export format confirmed: `.Lreg` file** (15 — see §4.9).
  From the field machine ("Logo host") the operator selects the area (village),
  ticks the parcel numbers (a village with 154 plots; a plot with 5 sub-parcels
  exports as 5), presses **"send data out"**, names the file (English OK), saves →
  confirmation "send completed". The `.Lreg` is carried to the server/office and
  imported (11). The new system must reproduce this sync (see §7); the API payload
  can be modelled on `.Lreg` contents [verify — exact attribute set unknown].

### 4.6 GIS tools (13, 14, 17, 20, 21)

- **FR-GIS-SPLIT (13)** Draw a **buffer circle** (default 15 m, operator-chosen
  [verify]) at a cut point to create a split point; enable snapping first; adjust
  symbol size/transparency; repeat for multiple cuts.
- **FR-GIS-QAD (21)** Use the **QAD** tool: create a line layer + a point layer in
  the parcel's UTM zone (48N, code 32648 [verify]); draw circles (e.g. 25 m / 20 m)
  from an offset point; snap-add points at the intersections to capture cut points.
- **FR-GIS-DRILL (14)** Parcel **drilling/punching** in QGIS3 — **no transcript yet**
  (single ASR cue); steps inferred from title only [verify].
- **FR-GIS-EDIT (17)** Per-district connection settings (IP via VPN [verify],
  district, base name, password); test → **green = configured**. Scroll to "parcel",
  add the old record, save. One connection + password per district, managed
  separately [verify].
- **FR-GIS-ZONE (20)** **Coordinate-system migration:** legacy **AutoCAD Lao97**
  data is not georeferenced to UTM Zone 48 and will not align with orthophotos
  until converted. Create a coordinate entry (name = free text; zone from
  dropdown), paste the central-office (ສູນກາງ) parameter values (no trailing
  spaces), import DXF + image layer, run the **Lao97 conversion tool** (select the
  coordinate; it computes parcel areas and moves shapes to correct positions), then
  **batch-load into the DB**. The new system must reproduce this
  import → convert → load path [verify details].

### 4.7 Database administration (01, 18, 16)

- **FR-DB-1** Install PostgreSQL 15 (default port 5432 [verify]) + pgAdmin4.
- **FR-DB-2** Create a **document DB** ("doc"/"Document") that stores scanned
  document files.
- **FR-DB-3** **Per-district DB** creation: name = district/province, **English only**
  (no Lao), upper/lower case allowed; owner = `postgres` or `dbperlet` [verify].
- **FR-DB-4** **Restore** from a backup SQL dump (from another PC): create the DB,
  right-click → restore, browse to the backup file, owner must match the creation
  user, restore; green = success, red = delete the DB, fix/re-backup, retry.
- **FR-DB-5** **Backup via pgAdmin4** (16): open pgAdmin4 → connect to Postgres 15
  (some PCs have the password saved) → right-click the database → **Backup**:
  format **PostgreSQL (SQL)**, encoding **UTF-8**, target folder/file e.g.
  `backup\backup_llr7` → wait for "**... fully complete**" → the `.sql` backup
  exists. The backup file is then **sent to the district (แขวง) or central office
  (ສູນກາງ)** for consolidation. [verify: SQL-dump vs custom; cadence; which DBs —
  parcel DB, DOC DB, or both.]

### 4.8 Parcel subdivision (05)

- **FR-SUBDIV-1** Identify the **split composition** — how the parent parcel divides
  and who owns each resulting sub-parcel (e.g. 1 parent → 3 sub-parcels, 3 new
  owners). The sub-parcel count must match the new-owner count [verify].
- **FR-SUBDIV-2** **GIS split first** — in QGIS (new project, zone 47N/48N), load
  the request-set code into the maintenance panel, open the **SurveyTask** layer,
  select the parent parcel(s) (turn yellow), **Edit Geometry → Split Feature**;
  N cuts → N+1 parcels. Save.
- **FR-SUBDIV-3** **Close the LLR7 form to green** — the request set disappears from
  the list (completed); sub-parcels remain in the GIS layer.
- **FR-SUBDIV-4** **Register each sub-parcel** — in the "change register" panel,
  add sub-parcel N (e.g. 70/71/72), **remove the old owner(s)**, add the new
  owner(s) (an un-removed old owner prints alongside the new ones).
- **FR-SUBDIV-5** Each sub-parcel's form must close to **green** before the next is
  processed.
- **FR-SUBDIV-6** **Print** the subdivision documents (sub-parcel no., owner,
  transfer type) [verify — template].

### 4.9 Parcel merging (07)

- **FR-MERGE-1** Precondition: two **adjacent parcels owned by the same person**
  [verify] that are in a state allowing merging [verify].
- **FR-MERGE-2** Set up the **big request set** (data in/out): add co-owner data
  (name, DOB, nationality, occupation, address) + a **small request set**.
- **FR-MERGE-3** **Add the parcels** — search each parcel by number (video: 45 &
  46), select, press **+** to add to the request set.
- **FR-MERGE-4** Set registration type = **"survey change"** (even for a merge
  [verify]); request type = **"parcel merging"**; status = "request out completed".
- **FR-MERGE-5** **GIS merge** — QGIS new project (zone 48N), lock in the request-set
  code, load **SurveyTask**, select both parcels (yellow), **Edit Geometry →
  Merge Selected Features** → confirm "two layers will be merged".
- **FR-MERGE-6** Enter the **new merged parcel number** (real value from the
  register book; test value 5,000 in the video) [verify].
- **FR-MERGE-7** **Close & save every QGIS window** (incl. layer panel) — unsaved
  windows block **Finalize** (error, must restart). [operational pitfall]
- **FR-MERGE-8** **Finalize** — auto fields: surveyor, equipment (RTK). The request
  set disappearing = merge completed.
- **FR-MERGE-9** **Owner cleanup** — in the "change" panel (parcel change →
  registration change), **remove both old owner entries** from the big request set;
  the merged parcel then shows a single owner; old parcel numbers show **red
  (closed/void)**.
- **FR-MERGE-10** **Move (ໂອນ)** to the new parcel number; "opening" completes →
  request-set dot **green**.
- **FR-MERGE-11** **Print** — merged parcel with both original numbers, road
  classification, person data, closing stamp [verify — template].

### 4.10 Legacy data import (08)

Three phases (per the 21-minute clip):

**Phase A — create the digital record (LLR7 data in/out):**
- **FR-IMP-A1** Open Data in/out → village/parcel data → **add a new small request
  set**; applicant = the title owner, data **from the old (paper) title**.
- **FR-IMP-A2** Registration type = "internal"; request type = **"old-system title
  into digital system"** [verify].
- **FR-IMP-A3** Add parcel data (parcel no., area, land-use type, village/district/
  town, road type, map sheet no. [verify values]) and **bind the owner** to the
  parcel; enter old title book/page numbers; close the data-edit form (tracking
  copy printable).

**Phase B — update the GIS (QGIS):**
- **FR-IMP-B1** QGIS new project, zone 48N/47N; add the **survey step-5 layer**
  (newly-surveyed geometry).
- **FR-IMP-B2** DB settings tab: Postgres host [verify], **port 5434** [verify —
  confirm vs 5432], program user/password; **Test Connection** → success.
- **FR-IMP-B3** Load the **"parcel update"** table → **partial update** layer →
  **Add** → export **Save File** (CRS **WGS84**, e.g. "update 8.4").
- **FR-IMP-B4** **Remap** the update layer into the zone; enable **snapping**
  (meter) so the update aligns with the LLR7 layer; **parcel number must match**
  (e.g. 146). Save; **Copy Fields** from the update layer into the **SurveyTask**
  layer.
- **FR-IMP-B5** Back in LLR7, **refresh** — the parcel shows the updated (green)
  geometry with person data joined.

**Phase C — PENCY → PENA (old title → new title number):**
- **FR-IMP-C1** If the old title is **PENCY** (ເກົ່າ), it must be updated to
  **PENA** (ໃໝ່) first: carry over the PENCY parcel no., old book no., PENA sheet
  no. [verify — PENCY/PENA semantics garbled].
- **FR-IMP-C2** Close the edit form → the parcel **moves to the PENA number**
  (e.g. parcel 200).
- **FR-IMP-C3** Enter the **handover** (general/normal handover,
  ການມອບຕ່າງທົ່ວໄປ) → complete the "add data from old title" workflow.

### 4.11 Mortgage / loan-contract registration (09)

- **FR-MORT-1** Enter the loan data on the form; **add the request**; go to the
  **land-use rights** step.
- **FR-MORT-2** Find the target parcel by sub-parcel number; load the request set;
  add the relevant co-owners/parties [verify].
- **FR-MORT-3** Set registration type = **"record the movement" → "record the
  loan-contract registration"** (ຈຶດທະບຽນສັນຍາຄຳປະກັນເງິນກູ້).
- **FR-MORT-4** Enter the loan amount / contract authority (video demo: 600,000
  kip; 500,000 limit) [verify — demo values]; record the receipt line.
- **FR-MORT-5** Add a **tracking copy**; hand over to the **title owner who came to
  stand**.
- **FR-MORT-6** In the **registration panel** → movement → change edit → register
  form: select the request set, **add the movement** (ເພີ່ມສິດເຄື່ອນໄພ), confirm
  the change ("registration land"), set status = main, press **"complete all
  requests"** → OK.
- **FR-MORT-7** **Close the app; the status dot must turn green** (from blue). If
  still red, open the other edit form and close it.
- **FR-MORT-8** **Fees** — the mortgage triggers a **fee document** via the same
  **barcode / fee-calc** path as EP6 (scan or type the barcode; the system adds a
  **stamp-duty / service-fee** line; video demo: land 600,000 → service 50,000 →
  stamp 50,000 [verify]); **create the document**.
- **FR-MORT-9** The loan-contract type is stored on the parcel; a later movement
  **creates a new request set** (does not delete).
- **Open (from clip):** whether a registered mortgage **blocks** other
  transactions (sale/subdivision) and how a **release/discharge** is recorded —
  the clip ends before these. The citizen-portal prototype currently **blocks**
  ໃບແຈ້ງມີ on mortgaged parcels — confirm this matches legacy.

---

## 5. Citizen-portal requirements (new, beyond legacy)

The legacy is officer/GIS-centric; the citizen portal is **new** scope and is
specified separately in `docs/2-back-office-portal-architecture.md` (ໃບແຈ້ງມີ,
document requests, issue reporting, status tracking, geo-QR). Cross-links:
- The portal's **ໃບແຈ້ງມີ** request likely satisfies a workflow that legacy meets
  via the **title authenticity letter (10)** — see §12 Q-1.
- Portal **issue reporting** (boundary disputes, encroachment) has no direct
  legacy counterpart in the 21 clips; it is new.

---

## 6. Data model (draft, derived)

Entities and the fields that appear across the transcripts. **[verify]** = field
name/semantics unconfirmed.

| Entity | Key fields observed | Source |
|---|---|---|
| **Parcel** | parcel number; area (e.g. 5,000 [verify]); use type; road type (4 types); land type; house number; phone; zone (e.g. G17 / 17); geometry (WGS84 / UTM 48); registration status + colour | 03, 04, 06 |
| **Rights holder / owner** | name; registered family name; national ID [verify]; nationality; occupation; owner type; current address; co-owners (joint vs individual) | 03, 04 |
| **Title / document** | title number; document; scanned image (stored in the DOC DB); barcode (cover sheet vs document sheet) | 03, 06, 10 |
| **Valuation zone** | zone code (A7 / G17); digit value (7); factor; road type; building/construction type | 06 |
| **Fee** | registration fee; service fee; survey/measurement fee; tax; amount; formula | 04, 06, 10 |
| **Request / request set** | request-set number + date; attached to parcel; carries movement history; status colour (blue/yellow = in progress, green = closed) | 10 |
| **Transfer / request (movement)** | delivery type (sale/gift/inheritance); applicant; previous holder; permanent-change order; completion command | 04 |
| **Authenticity letter** | letter no. = local-administration number; village; issues date; movement notes | 10 |
| **User / account** | officer/field/admin identity; exportable; remembered on the server | 11 |
| **DB connection (per district)** | IP [verify]; port (5432 local / 9901 central [verify]); DB name (= district); user (`postgres`/`dbperlet` [verify]); password; VPN flag | 01, 11, 17 |
| **GIS layers** | parcel layer; point layer; line layer; buffer/QAD circles; split points | 13, 21 |
| **Coordinate system** | Lao97 (legacy) → UTM Zone 48 (EPSG 32647/32648 [verify]) / WGS84 (EPSG 4326); central-office parameter set | 20 |

**Open:** exact export-file schema for user/parcel sync (the `.Lreg` question) —
critical for the sync design (FR-SYNC-6).

---

## 7. Sync mechanism (design focus)

The legacy's **field local DB → central server** pattern (11) is the single most
important thing to design correctly in the new system:

1. **Users:** one-time export from the field DB → carried (USB/courier/share
   [verify]) → central admin imports via System admin → Admin → Import users.
2. **Parcels:** repeated export → import into the central district DB, one file at a time.
3. **Conflict rule:** unknown [verify] — overwrite / skip / error when a user or
   parcel already exists centrally.
4. **Direction:** observed as one-way field→center [verify]; corrections back down
   are unconfirmed.

**Recommendation for review:** the new system should replace the file hand-off with
an **API-based sync** (field device uploads user/parcel payloads to a sync endpoint;
server upserts with a defined conflict policy). The file format question (FR-SYNC-6)
must be answered before the API can be specified.

---

## 8. Non-functional requirements

- **Deployment:** per-district databases; central server with a fixed port
  (9901 [verify]) reachable over VPN from field stations.
- **Offline / two-tier:** field work happens on a local DB; sync is file-based in
  legacy, to become API-based in the new system.
- **Languages:** Lao-primary UI; legacy is QGIS (English UI + Lao data); narration
  in the tutorials is **Thai**.
- **Coordinates:** UTM Zone 47/48 for the north/south split (zone 47 north incl.
  ໄຊຍະບຸລີ/ບາງບໍ່ແກ້ວ; zone 48 the south) [verify] — the system must store zone per district.
- **Scanned documents:** a separate DOC database holds scanned files.
- **Print:** 2 copies at registration; specific letter/print templates.
- **Security:** DB access requires a password every time (01); central passwords
  are issued by the central office (11); roles gate import (11).
- **Status-colour workflow gate:** a step must reach **green** before the next
  movement (04, 10).

---

## 9. Cross-reference note — ໃບແຈ້ງມີ vs ໃບຢັ້ງຢືນ

The prototype's citizen-portal hero feature is **ໃບແຈ້ງມີ** (land-availability
certificate). Legacy EP10 issues a **ໃບຢັ້ງຢືນຄວາມຖືກຕ້ອງຂອງໃບຕາດິນ** (title-
authenticity letter). These are **likely related but not proven to be identical**.
The review must decide:
- Are they the **same** document (then the portal's ໃບແຈ້ງມີ = EP10 flow), or
- **Two different** documents (then the new system needs both)?

This affects the portal data model and the requirement traceability. **[verify]**

---

## 10. Confidence map of this document

| Section | Source quality | Reliability |
|---|---|---|
| 4.1 registration (03) | Lao ASR, 319 cues | Medium — proper nouns [verify] |
| 4.2 transfer (04) | Lao ASR, 160 cues | Medium — print/garble in closing steps |
| 4.3 valuation/fees (06) | **Thai ASR of a Lao video**, 135 cues | **Low** — all [verify] |
| 4.4 authenticity letter (10) | Lao ASR, only 37 cues | Low-Medium — short + noisy |
| 4.5 sync (11) | Lao ASR, 74 cues | Medium-High — clear 2-tier pattern |
| 4.6 GIS (13/17/20/21) | Lao ASR | Medium — zone codes [verify] |
| 4.7 DB (01/18) | Lao ASR | Medium-High |
| 4.8 pending (05/07/08/09) | none yet | N/A — title only |

---

## 11. Next steps

1. **Review meeting** with PERN / customer — walk §4–§9, answer the `[verify]` and
   Q items, and settle §9 (ໃບແຈ້ງມີ vs ໃບຢັ້ງຢືນ) and §7 (sync file format + conflict rule).
2. **Populate `raw/audio/`** with the 6 missing clips (EP5, EP7, EP8, EP9, Lreg,
   backup) → run `raw/whisper_pass.py` (auto-detect, beam=1, VAD) → fill §4.8.
3. **Promote this to v1.0** SRS + a concrete **data-model DDL** and **API contract**,
   then begin real backend implementation (PostgreSQL + API, per architecture doc).

---

## Appendix A — Open-questions register (for the review meeting)

Prioritized (P = high, decide first):

- **Q-1 [P]** §9: Is ໃບແຈ້ງມີ the same document as the legacy ໃບຢັ້ງຢືນຄວາມຖືກຕ້ອງຂອງໃບຕາດິນ, or two documents?
- **Q-2 [P]** §7 / 11: What is the exact **export file format** for user/parcel sync (.Lreg? JSON/CSV/SQLite?), and the **conflict rule** on re-import?
- **Q-3 [P]** 04: Is a **second sign-off** required to complete a transfer, or can one officer do it end-to-end?
- **Q-4 [P]** 06: How is the fee **Factor** determined and who sets it? Are survey/measurement fees on a separate screen?
- **Q-5** 03: What are the **C7/C8** references and the "PZ / PZ Import" file types?
- **Q-6** 06: Rule for entering a valuation-zone code that is letter+digit (e.g. G17)?
- **Q-7** 20: District→zone rule (47 vs 48)? Is "from 97 to 84" a real Lao84 system or an ASR misread? Does the conversion also fix **area** (scale factor), not just position?
- **Q-8** 11: Confirm central server **IP** and **port 9901**; confirm DB user `dbperlet`.
- **Q-9** 17: What are the "small and large" settings, and the base name "ແບ້"? Who manages the per-district connections?
- **Q-10** 04/10: Official meaning of the status colours (blue/yellow = in progress, green = closed).
- **Q-11** 03: Does manual point-add ever work (ASR says "ພີ່ມ" adds nothing)?
- **Q-12** 01: The Postgres port, the second installer, and what the "block" is that gets deleted/recreated on failure.
- **Q-13** 18: Backup file extension/format; is "delete DB and retry" the only sanctioned restore recovery?
- **Q-14** 05/07/08/09 **(audio pending)**: subdivision, merging, legacy-import, and mortgage rules — to be filled after the Whisper pass.
- **Q-15** 14: What exactly does "drilling" (ເຈາະຕອນດິນ) mean — new sub-parcels, or re-punching boundary points?
