# EP5 — Parcel subdivision

- **Lao title:** LLR7 ການຈົດທະບຽນ ແບ່ງແຍກ EP.5
- **YouTube:** https://youtu.be/ZeVzTp1T7XI (17m 46s)
- **Group:** Part 1 · Core workflows
- **Transcript status:** Whisper large-v3 (int8, CPU) local transcription, raw/audio/ZeVzTp1T7XI.whisper.txt
- **Confidence:** MEDIUM — Thai ASR of a Lao video; proper nouns and some command names are garbled. Steps are marked [verify] where the ASR is unclear.

## Transcript (ASR)

See raw/audio/ZeVzTp1T7XI.whisper.txt (6 chunks x 3 min, Thai detected).

## Workflow steps (from ASR)

1. **Identify the split composition** — who will own the new parcels after the split. E.g., one parent parcel split into 3; 3 people receive them. The number of sub-parcels must match the number of new owners. [verify — composition rule not explicit]
2. **Add owners to the request set** (ຮູ້ສຶກ / request-set owner data) — add up to 3 owners, with date of birth / family data, as observed on the parent title.
3. **Create a small request set** (ຮູ້ສຶກບໍ່ນ້ອຍ) under the parent request-set, type = "land-use change", land-type = "subdivision & measurement" (ແບ່ງແຍກຕອນດິນ). Status = "request completed" (ຮູ້ສຶກອອກສຳເລັດ).
4. **Open QGIS (LLR7 tool)** — new project, set zone = 47 or 48 North depending on district. [verify — zone code from ASR, not confirmed]
5. **Lock-in the request-set ID** — copy the request-set ID from the LLR7 form, paste it into the QGIS maintenance panel, click "search" to load it.
6. **Load the SurveyTask layer** — open the SurveyTask layer in QGIS, select the two parent parcels to be split (turn them yellow).
7. **Split geometry** — Edit → Edit Geometry → Split Feature. Click along the parcel boundary: 1 cut = 2 parcels, 2 cuts = 3 parcels, etc. Save.
8. **Close the LLR7 form** — the request-set disappears from the list (indicating "completed"); the sub-parcels remain in the GIS layer.
9. **Return to LLR7 "change register" panel** — complete the change-register workflow for the first sub-parcel.
10. **Transfer each sub-parcel to its new owner** — open the "register" form, add sub-parcel N (e.g., parcel 70 = split-1, 71 = split-2, 72 = split-3), remove the old owner(s), add the new owner(s). If the old owner is not removed, it gets printed with the new owners.
11. **Close each form to green** — the status dot for the sub-parcel must be green before the next one is processed. If it stays red, the form was not closed.
12. **Verify** — back in the register panel, the parent parcel now shows the sub-parcels (70 / 71 / 72) with their new owners.
13. **Print the documents** — the printout shows sub-parcel number, owner, and the subdivision type ("parcel split for inheritance / relative transfer" in this example). [verify — document template not fully visible in ASR]

## Extracted screens / fields

| Screen / form | Fields & controls | Notes |
|---|---|---|
| LLR7 request-set form | request-set type, land type (subdivision & measurement), status | "request completed" gate |
| QGIS (LLR7 tool) | project zone (47N / 48N), request-set ID maintenance panel, SurveyTask layer, Edit Geometry → Split Feature | GIS split step |
| Register / change-register panel | sub-parcel number, old owner(s) (removed), new owner(s) (added), status dot (green) | per-sub-parcel |
| Print | sub-parcel number, owner name, transfer type | 2-part template [verify] |

## Open questions for review

- [ ] What is the **minimum area** allowed for a resulting sub-parcel? Does the system enforce it?
- [ ] Can a subdivision be initiated **without** the GIS split (i.e., from the registration side only)?
- [ ] Are **fees / approvals** triggered by the subdivision registration, and who sets them?
- [ ] What happens if a sub-parcel is **not transferred** after the split (stays under the original owner)?
- [ ] The ASR shows "3 cuts = 3 parcels" — is the **number of cuts** strictly N-1 for N sub-parcels?
- [ ] Which **print templates** are used for subdivision (vs. a regular transfer)?

## Notes

- This is the **most detailed GIS workflow** in the LLR7 spec. The QGIS "Split Feature" command is the core GIS operation; everything else is LLR7 request-set + register management.
- The 18-minute video covers a full 3-way split with 3 new owners — a realistic end-to-end case.
