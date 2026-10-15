# EP8 — Import legacy data to digital system

- **Lao title:** LLR7 ການນຳເຂົ້າຂໍ້ມູນຈາກລະບົບເກົ່າເປັນລະບົບDigital EP.8
- **YouTube:** https://youtu.be/r1AmBLHJ2Ro (21m 14s)
- **Group:** Part 1 · Core workflows
- **Transcript status:** Whisper medium (int8, CPU) local transcription, raw/audio/r1AmBLHJ2Ro.whisper.txt
- **Confidence:** MEDIUM — Thai ASR of a Lao video; DB names / some terms garbled. Steps marked [verify] where unclear.

## Transcript (ASR)

See raw/audio/r1AmBLHJ2Ro.whisper.txt (8 chunks x 3 min, Thai detected).

## Workflow steps (from ASR)

**Case:** a **pre-digital (old-system) title** with a movement to record, but **no digital data yet** — the record exists only on paper. Goal: bring the legacy data into the digital system.

### Part A — Create the digital record (LLR7 data in/out)

1. Open the **LLR7 program → Data in/out (ຂໍ້ມູນເຂົ້າ-ອອກ) → village/parcel data** for the target title.
2. **Edit data (ເບີ່ງຂໍ້ມູນ)** → **add a new small request set** (ຮູ້ສຶກອຸດໃໝ່).
3. **Add the applicant** (ຜູ້ຢືນຄຳສຳຫຼວດ) = the title owner (ຜູ້ທີ່ເຄີຍ / ຜູ້ຄຸ້ມຄອງ). Fill the data **from the old title**: name, date of birth, address, etc. [verify — field list from ASR]
4. **Set the registration type** = "internal" (ພາຍໃນ); **request type** = **"old-system title into digital system"** (ເລືອກ... ພາກພື້ນເກົ່າເປັນລະບົບດິຈິຕອນ) [verify].
5. **Add the parcel data** (parcel number, area, land-use type — e.g. "residential construction land" [verify]; village / district / town; road type (main road / branch / alley); map sheet number C2CC990-C [verify — garbled]).
6. **Bind the owner to the parcel data** (select owner → "transfer to owner" [verify — command garbled]).
7. **Registration book / title number** — enter the old title's book/page numbers (e.g. "up to 4, book 11, page 1" [verify]); mark as "inheritance" (ມໍລະດົກ) example.
8. **Close the data-edit form** — the parcel-data step is complete; the tracking copy (ໃບຕິດຕາມ) can now be printed for the applicant / another office.

### Part B — Update the GIS (QGIS / LLR7 tool)

9. Open **QGIS (LLR7 survey tool)** → new project → set **zone 48N (or 47N)** → OK.
10. **Add the survey step-5 data** (ຊັ້ນ layer): the newly-surveyed parcel geometry, plus the surveyor's preparation files ("copy... follow the old title or re-surveyed" [verify]).
11. **DB settings** — the "PG admin / connection" tab: Postgres host name "XN" [verify], **port 5434** [verify — ASR says 5434, confirm], same username/password as the program login. **Test Connection** → success → OK.
12. **Load the "parcel update" table** — from the update table (ຕາຕະລາງ update), select the **partial update** layer and **Add** → get the "save file update".
13. **Export the save file** — format = **Save File**; file name e.g. "update 8.4" [verify]; CRS = **WGS84**; OK → the updated parcel geometry is available.
14. **Remap / update the geometry** — start the update in the chosen zone (48); enable **snapping** (Snap toolbar → meter, point difference) so the update layer aligns with the LLR7 layer. **Parcel number must match** (e.g. 146). OK.
15. **Close & save** the update panel; **Copy Fields** (ສັງເຫື່ອ command Copy [verify]) from the update layer **into** the SurveyTask (parcel) layer. Save.
16. **Back in LLR7 — refresh** the parcel view: the parcel now shows the **green/updated** geometry with the person data joined.

### Part C — PENCY → PENA (old title → new title number)

17. **If the old title is PENCY (ເກົ່າ)** — it must be **updated to PENA (ໃໝ່)** first. Take the **last parcel number of the PENA** and enter it: the PENCY parcel number, the old title book number, and the PENA sheet number are all carried over. [verify — PENCY/PENA semantics garbled]
18. **Close the edit form** → the parcel **moves to the PENA number** (e.g. parcel 200). The old parcel number stays as "parcel no. 200 = old parcel" [verify].
19. **Enter the handover** (ການມອບຕ່າງ) — as a normal / general handover (ການມອບຕ່າງທົ່ວໄປ) → complete the "add data from the old title" workflow.

## Extracted screens / fields

| Screen / form | Fields & controls | Notes |
|---|---|---|
| Data in/out → village/parcel data | add small request set, applicant (= owner), old-title data | Part A |
| Registration type | "internal", request type "old → digital" | [verify] |
| Parcel data | parcel no., area, land-use type, village/district/town, road type, map sheet no. | [verify values] |
| QGIS DB settings | Postgres host "XN" [verify], port **5434** [verify], user/password, Test Connection | Part B |
| Parcel update table | partial update layer, Add, Save File export (WGS84) | Part B |
| Snap / update | Snap toolbar (meter), parcel no. match (146), Copy Fields → SurveyTask layer | Part B |
| PENCY/PENA | last PENA parcel no., old book no., PENA sheet no. | Part C [verify] |
| Handover | general handover (ການມອບຕ່າງທົ່ວໄປ) | Part C |

## Open questions for review

- [ ] **PENCY vs PENA** — what exactly are these (old vs new title register book types)? The ASR is garbled; this is the crux of the legacy-migration path [verify].
- [ ] The ASR shows Postgres **port 5434** — confirm the real port (5432 default vs 5434).
- [ ] Which **survey step-5 files** feed the GIS update, and who prepares them?
- [ ] How are **duplicates / mismatches** (legacy vs freshly surveyed) detected and resolved?
- [ ] Who **validates** the imported data before the parcel is marked PENA / green?

## Notes

- This is the **migration path** that the SRS calls out: legacy AutoCAD Lao97 / pre-digital data → digital LLR7, including a coordinate-system concern (WGS84 save-file) and the PENCY→PENA title-number transition.
- Three distinct phases (LLR7 data entry, QGIS geometry update, PENCY/PENA transition) — a good model for a "legacy import" module in the new system.
