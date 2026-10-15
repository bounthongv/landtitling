# LLR7.2 Legacy System — Specification Source (from video docs)

Specification collected from the 21 PERN Workstation YouTube clips documenting
**LaoLandReg V7.2 (LLR7)** — the legacy system: a **QGIS 3.34 desktop plugin
over PostgreSQL 15 / pgAdmin4**, with GPS field capture synced as `.Lreg` files
to a central server. Source link-list:
`docs/ສັງລວມບັນດາລິ້ງວີດີໂອ ... LLR7.2 By ketsoulin.pdf`

## Layout

- `NN_*.md` — **one file per video** (21 files), each with:
  - header (Lao title, link, duration, part)
  - cleaned ASR transcript (Lao where available)
  - fill-in sections: screens/fields table, workflow steps, open questions
- `raw/` — machine outputs (KEEP, do not hand-edit):
  - `raw/videos.json` — master registry (21 clips, titles, notes)
  - `raw/caps/*.vtt` — raw caption files + `clean/*.txt` (cleaned)
  - `raw/thumb/*.jpg` — 1 thumbnail per clip (UI reference only)
  - `raw/caps/lo_availability.json` — authoritative lo/th ASR map
  - `raw/audio/` — **empty** (media is IP-walled here; see below)
  - scripts: `fetch_caps.py` (re-fetch captions), `build_spec.py` (regenerate all .md)

## Transcript coverage (current)

| Status | Videos |
|---|---|
| **ASR transcript captured + workflow steps extracted** | 01 install, 02 install, 03 new title, 04 transfer, 06 valuation (th), 10 title letter, 11 field sync, 13 buffer, 14 drilling, 17 parcel edit, 18 create/restore DB, 20 Zone48, 21 QAD |
| **Whisper large-v3 local transcript** | 12 initial config (Thai narration, low-conf on 68s clip) |
| **Title-only, no ASR, audio still pending (6 clips)** | 05 subdivision, 07 merging, 08 legacy import, 09 mortgage, 15 Lreg export, 16 backup |
| **Video gone (404)** | 19 pgAdmin fix |

**Key finding:** PERN narration is **Thai**, not Lao. The YouTube `lo` caption
tracks are auto-translations of underlying ASR; auto-detect on the SETUP clip
gave `th` @ 0.992. So Whisper runs use per-clip auto-detect (fallback `th`),
**not** a forced `language="lo"` — see `raw/whisper_pass.py`.

## How to fill the 6 pending audio gaps

YouTube media (audio) is 403-blocked on the dev host. Download just the audio
in your browser into `raw/audio/` named `<YouTubeID>.<ext>`:

| Clip | YouTube | Save as |
|---|---|---|
| 05 Subdivision | https://youtu.be/ZeVzTp1T7XI | `ZeVzTp1T7XI.m4a` |
| 07 Merging | https://youtu.be/mp22eHRhsmM | `mp22eHRhsmM.m4a` |
| 08 Legacy import | https://youtu.be/r1AmBLHJ2Ro | `r1AmBLHJ2Ro.m4a` |
| 09 Mortgage | https://youtu.be/Qe-M0fjP0PQ | `Qe-M0fjP0PQ.m4a` |
| 15 Lreg export | https://youtu.be/jYLmyWtqps8 | `jYLmyWtqps8.m4a` |
| 16 DB backup | https://youtu.be/BfEUdQoXntE | `BfEUdQoXntE.m4a` |

Then run: `python raw/whisper_pass.py` (uses the hermes-agent venv python),
which writes `<id>.whisper.txt` auto-detected-language transcripts.

> NOTE: `build_spec.py` now refuses to overwrite existing (enriched) files
> unless you pass `--force`. The enrichment lives in the .md files, not the
> generator — re-running the generator is safe.

## What "not worse than legacy" implies (feature gaps vs our prototype)

Legacy has, prototype lacks:
1. **Subdivision** (EP5) — parcel split with new codes
2. **Parcel merging** (EP7)
3. **Valuation + fee engine** (EP6) — price assessment + fee rules
4. **Field-capture sync** (EP11) — field QGIS DB → central server, .Lreg exchange
5. **Title authenticity letter** (EP10) — confirm vs our ໃບແຈ້ງມີ: same document or different?

## Known tooling constraints on the dev host

- YouTube **media streams (audio/video) return 403** on this IP (datacenter).
  Captions (ASR API) and thumbnails work. ⇒ For the 7 "needs audio" clips:
  **download the audio in your browser** (residential IP works) → put the
  files in `raw/audio/` (wav/m4a/mp3, named `<YouTubeID>.*`) → Whisper
  (faster-whisper large-v3, int8, CPU) is already installed; run it here.
- ASR quality: Lao auto-captions are noisy; they drive the **flow**, never
  field labels. Labels come from the human review pass / screen capture.

## Next steps (after human review with PERN/customer)

1. Confirm open questions per .md, especially EP10 vs ໃບແຈ້ງມີ
2. Fill `raw/audio/` for the 7 clips → generate transcripts → update .md
3. Consolidate into `docs/spec/requirements.md` (functional SRS) +
   data-model (tables/fields) → then real backend (PostgreSQL + API)
