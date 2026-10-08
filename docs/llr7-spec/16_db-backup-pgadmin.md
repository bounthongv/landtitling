# BKUP — Database backup via pgAdmin4

- **Lao title:** ວິທີ Backup ຖານຂໍ້ມູນ pgAdmin4
- **YouTube:** https://youtu.be/BfEUdQoXntE (5m 22s)
- **Group:** Part 2 · GIS tools & admin
- **Transcript status:** Whisper large-v3 (int8, CPU) local transcription, raw/audio/BfEUdQoXntE.whisper.txt
- **Confidence:** MEDIUM — Thai ASR, short clip; procedure is clear.

## Transcript (ASR)

See raw/audio/BfEUdQoXntE.whisper.txt (54 lines, Thai detected).

## Workflow steps (from ASR)

**What this is:** backing up the **PostgreSQL database** (the district/field LLR7 DB, "LaoLandReg") via **pgAdmin4**.

1. **Open pgAdmin4** — via the system search (type "PG" → pgAdmin4 appears), or double-click; some PCs have the **password saved** so they connect directly, others type the password.
2. **Connect to Postgres 15** (the LLR7 server) with the DB password.
3. **Prepare the backup location** — create a folder (e.g. `backup`), inside it a file named e.g. **`backup_llr7`** (or "backup LaoLandReg 7" [verify — naming convention]).
4. **Run the backup** — right-click the database → **Backup**:
   - **Format: PostgreSQL (SQL)** — i.e. a plain SQL dump [verify — "SQL" vs custom].
   - **Encoding: UTF-8** (tick/enter "utf-8").
   - scope can be a single schema/table or the whole DB (video: the **field ("Z") database** with its Logo/field load).
5. **Wait for completion** — pgAdmin shows progress; on success it reports **"... fully complete"**.
6. **Result** — a **backup .sql file** exists in the folder.
7. **Send it** — pass the backup file to the **district (แขวง) or central office (ສູນກາງ)** for consolidation.

## Extracted screens / fields

| Screen / control | Fields & controls | Notes |
|---|---|---|
| pgAdmin4 launch | system search "PG", saved password / type password | some PCs auto-connect |
| Server | Postgres 15, DB password | per-district server |
| Backup dialog | target folder/file (`backup_llr7`), format = **PostgreSQL (SQL)**, encoding = **UTF-8**, scope | the backup itself |
| Progress / result | "fully complete" | success indicator |
| Distribution | send file → district / central office | consolidation |

## Open questions for review

- [ ] **Format: plain SQL dump vs custom/pg_dump custom** — the ASR reads "PostgreSQL (SQL)"; confirm.
- [ ] **Cadence & ownership** — how often (daily? weekly?), who runs it, retention policy.
- [ ] Is there a matching **restore** procedure (compare with `18_create-restore-db.md`)?
- [ ] Which DB(s) are backed up here — the per-district parcel DB, the DOC (scanned files) DB, or both?

## Notes

- Pairs with `18_create-restore-db.md` (restore from a backup SQL dump).
- The "send to district/central" step is the offline analog of what the new system's sync API would replace.
