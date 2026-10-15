# EP7 — Parcel merging (combine)

- **Lao title:** LLR7 ການຈົດທະບຽນ ໂຮມຕອນດິນເຂົ້າກັນ EP.7
- **YouTube:** https://youtu.be/mp22eHRhsmM (28m 45s)
- **Group:** Part 1 · Core workflows
- **Transcript status:** Whisper medium (int8, CPU) local transcription, raw/audio/mp22eHRhsmM.whisper.txt
- **Confidence:** MEDIUM — Thai ASR of a Lao video; some command names garbled. Steps marked [verify] where unclear.

## Transcript (ASR)

See raw/audio/mp22eHRhsmM.whisper.txt (10 chunks x 3 min, Thai detected).

## Workflow steps (from ASR)

1. **Login** to the LLR7 program as a user.
2. **Prepare the merge case** — two adjacent parcels owned by the **same person** (the video uses a village example; "co-owner wants to merge"). The titles to be merged must first be in a state that allows merging [verify — precondition rule not explicit].
3. **Data in/out (ຂໍ້ມູນເຂົ້າ-ອອກ)** — add the owner data to the **big request set** (ຮູ້ສຶກໃຫຍ່): name, date of birth, nationality, occupation, address — as on the second title.
4. **Add a small request set** (ຮູ້ສຶກບໍ່ນ້ອຍ) under the big request set (with data in/out fields).
5. **Find the parcels to merge** — search by parcel number (video: parcel 45 and parcel 46); select each parcel, press **+ (ບວກ)** to add it to the request set.
6. **Set registration type** — even for a merge, the registration type = "survey change" (ການປ່ຽນແປງການສຳຫຼວດ); request type = **"parcel merging"** (ໂຮມຕອນດິນເຂົ້າກັນ); status = **"request out completed"** (ສົ່ງອອກ...ສຳເລັດ).
7. **Print copies** — add a tracking copy (ໃບຕິດຕາມ) for the applicant / another office; print.
8. **QGIS step** — "QGIS survey" main button: open QGIS, new project, set **zone 48 North** (or 47 North for a 47-zone district).
9. **Lock in** (ຫຸ່ນປະຕູ Lock-in) — paste the **request-set code** (copied from the LLR7 form) into the QGIS maintenance/search panel; search by request-set number; **load** → the two target parcels appear.
10. **Select both parcels** in the **SurveyTask** parcel layer (turn yellow).
11. **Merge geometry** — Edit → Edit Geometry → **Merge Selected Features** (Merg...Select filter). Confirm: "two layers will be merged". The merged shape is visible. [verify — exact menu wording]
12. **Set the new parcel number** — enter the merged parcel's number (test value 5,000 in the video; real value from the register book / parcel number). Save. [verify]
13. **Close & save the QGIS form** — **must close (save) every window** including the layer panel, else **Finalize fails with an error** and the user must start over.
14. **Finalize** (ກົດ Finalize) — record auto fields: surveyor (ຜູ້ລົງສຳຫຼວດ), equipment (RTK selected before finalize). In the newer version: **the request set disappearing = merge completed**.
15. **Back in LLR7 "change" panel** — the merged parcel now shows the **old numbers as history**; go to "parcel change" (ການປ່ຽນແປງ) → "registration change" (ຈັດທະບຽນ).
16. **Owner cleanup** — in the change-register form (register type form), **remove both old owner entries** from the big request set; if not removed, the printout shows the old owners (2–3 sets). The merged parcel then shows a **single owner**; the old parcel numbers show as **red (closed/void)**.
17. **Move/transfer (ໂອນ)** to the new parcel number; "opening" (ການເປີດ) completes → **request-set dot turns green** = all movements finished.
18. **Print** — the new parcel's printout: merged parcel with its two original numbers, road classification (main road / branch / alley), person data, and the closing stamp/set (ປິດອັດໄສ້... ແພ້ງ) [verify — print template details].

## Extracted screens / fields

| Screen / form | Fields & controls | Notes |
|---|---|---|
| Data in/out (ຂໍ້ມູນເຂົ້າ-ອອກ) | co-owner data, big request set, add small request set | merge case setup |
| Parcel search | parcel number (45 / 46 in example), + (ບວກ) button | add parcels to request set |
| Registration type | "survey change", request type "parcel merging", status "request out completed" | even merges use "survey change" [verify] |
| QGIS (LLR7) | zone 48N/47N, lock-in request-set code, SurveyTask layer, Edit Geometry → Merge Selected Features, new parcel number, Finalize | GIS merge step |
| Change / register panel | parcel change, big request set, remove old owners, move (ໂອນ), green status | owner cleanup + completion |
| Print | merged parcel number, history of old numbers, road type, owner data | [verify] |

## Open questions for review

- [ ] Must the merged parcels be **contiguous and/or same-owner**? (video implies same-owner + adjacent; rule not stated)
- [ ] What happens to the **original titles** of the merged parcels — cancelled, kept as history? (video: old numbers show as red/closed — confirm semantics)
- [ ] Is merging allowed on parcels that carry a **mortgage**?
- [ ] Who assigns the **new parcel number** — the operator or the register book? (video: "take from the register book" for real data)
- [ ] Exact QGIS menu wording for **Merge Selected Features** (ASR garbled) [verify]
- [ ] Does a merge trigger a **new title document** or an amendment to the existing one?

## Notes

- Longest core-workflow clip (28:45). Covers **both** halves: the LLR7 request-set/registration side **and** the QGIS geometry-merge side, plus the owner-cleanup step that keeps printouts correct.
- Key operational pitfall from the ASR: **unsaved QGIS windows block Finalize** (error, must restart) — worth a UI guardrail in the new system.
