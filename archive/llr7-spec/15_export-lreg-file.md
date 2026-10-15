# LREG — Export field parcels to .Lreg file

- **Lao title:** ການສົ່ງຕອນດິນອອກຈາກພາກສະໜາມເປັນຟາຍ Lreg
- **YouTube:** https://youtu.be/jYLmyWtqps8 (3m 30s)
- **Group:** Part 2 · GIS tools & admin
- **Transcript status:** Whisper large-v3 (int8, CPU) local transcription, raw/audio/jYLmyWtqps8.whisper.txt
- **Confidence:** MEDIUM-HIGH — Thai ASR, short clip; flow is clear. Marks the **confirmed .Lreg export** (answers SRS open question Q-2).

## Transcript (ASR)

See raw/audio/jYLmyWtqps8.whisper.txt (37 lines, Thai detected).

## Workflow steps (from ASR)

**What this is:** exporting field-collected parcel data out as a **file (Lreg)** — either from a field machine (Logo host) up to the server, or from one PC to another — for the **registration and record-keeping of many parcels** (ບ້ານ / village level).

1. **Choose the scope** — "select area" (ເລືອກເຂດ [verify]): e.g. the whole village.
2. **Select the parcels** — tick the parcel numbers to export:
   - the video shows a village with **154 plots (ຕອນ)**; some are already ticked (3 in the example).
   - select by plot number; a plot with 5 sub-parcels ("5 ຕອນ") exports as 5 parcels.
3. **Press the "send data out" button** (ປຸ່ມສົ່ງຂໍ້ມູນອອກ, bottom of the screen) → click → tick → **type the file name** into the save dialog (English is fine, e.g. "Dian [of this village]" — the data is a **Lreg** file).
4. **Save** → a confirmation appears: **"send completed"** (ສົ່ງອອກສຳເລັດ) → OK.
5. **Result** — the exported parcel data (now as an **.Lreg file**) can be carried to the server / another machine and imported (see 11_field-to-server-sync).

## Extracted screens / fields

| Screen / form | Fields & controls | Notes |
|---|---|---|
| Area/parcel selection | area (village) selector, parcel-number list, tick boxes | scope of export |
| "Send data out" button | file-name field (English OK), Save | writes the **.Lreg** file |
| Confirmation dialog | "send completed" (ສົ່ງອອກສຳເລັດ), OK | success gate |

## Key finding

- **The parcel export/sync file format is confirmed to be the `.Lreg` file** (ອາວະເລັກ / "Lreg"). This resolves SRS open question **Q-2** (the export-file-format question) — the new system's sync API can model the payload on the .Lreg contents.

## Open questions for review

- [ ] What **attribute fields** are inside an .Lreg (geometry, parcel no., owner, request-set state)?
- [ ] Who imports the .Lreg into the central DB (field operator vs office admin)?
- [ ] How are **duplicates / conflicts** handled on re-export / re-import (same parcel sent twice)?
- [ ] Is .Lreg a single-DB transfer file or does it also carry **user** data (users were a separate one-time export in 11)?

## Notes

- Short clip (3:30) but high value: it closes the loop between field capture and central registration.
- Pairs with `11_field-to-server-sync.md` (users-once / parcels-repeated) — this is the "parcels, repeatedly" side.
