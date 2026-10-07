"""Generate one .md spec file per LLR7 video under docs/llr7-spec/.

Input:
  raw/caps/<vid>_<lang>.<lang>.vtt   - Lao/Thai ASR transcripts (cleaned)
  raw/caps/lo_availability.json      - authoritative lo/th availability + durations
  raw/thumb/<vid>.jpg                - thumbnails (reference only, not OCR-critical)

Output:
  NN_<slug>.md  - one file per video with transcript + structured notes

Re-run any time; cleaned VTTs are reused from raw/caps/clean/.
"""
import os, re, json, glob

BASE = r"D:\LandTitling\docs\llr7-spec"
RAW  = os.path.join(BASE, "raw")
CAPS = os.path.join(RAW, "caps")
CLEAN = os.path.join(CAPS, "clean")
OUT  = BASE  # one md per video, top level of llr7-spec
os.makedirs(CLEAN, exist_ok=True)

avail = json.load(open(os.path.join(CAPS, "lo_availability.json"), encoding="utf-8"))

# human labels + slugs, aligned to PDF numbering
LABELS = {
 "DqM0Kndkz1U":  ("EP1",  "postgres-pgadmin4-install",  "Install PostgreSQL 15 + pgAdmin4"),
 "RsLpvQwGdEU":  ("EP2",  "qgis-llr7-install",          "Install QGIS 3.34 + LaoLandReg 7.2 plugin"),
 "47BM01MAocA":  ("EP3",  "new-title-registration",     "New title registration (create land title)"),
 "9X0FSJFq-k4":  ("EP4",  "full-transfer",              "Full parcel transfer registration"),
 "ZeVzTp1T7XI":  ("EP5",  "subdivision",                "Parcel subdivision"),
 "k5N59T3ay-k":  ("EP6",  "valuation-fee-calc",         "Valuation + land fee calculation"),
 "mp22eHRhsmM":  ("EP7",  "parcel-merging",             "Parcel merging (combine)"),
 "r1AmBLHJ2Ro":  ("EP8",  "legacy-data-import",         "Import legacy data to digital system"),
 "Qe-M0fjP0PQ":  ("EP9",  "mortgage-loan",              "Mortgage / loan contract registration"),
 "5m74zCwNxpY":  ("EP10", "title-authenticity-letter",  "Title authenticity letter procedure"),
 "-PJH8cU8M8k":  ("SYNC", "field-to-server-sync",       "Sync users+parcels from field DB to central server"),
 "hL1WCU5Bq1Q":  ("CFG",  "initial-configuration",      "LLR7.2 initial configuration before use"),
 "KkQO55HBhok":  ("BUF",  "buffer-circle-split",        "Draw circle/buffer for parcel splitting"),
 "BIAhi9MW5g0":  ("DRILL","parcel-drilling",            "Drilling/punching parcels in QGIS3"),
 "jYLmyWtqps8":  ("LREG", "export-lreg-file",           "Export field parcels to .Lreg file"),
 "BfEUdQoXntE":  ("BKUP", "db-backup-pgadmin",          "Database backup via pgAdmin4"),
 "1mTxz1hiVfI":  ("EDIT", "parcel-edit-settings",       "Settings for editing a parcel in QGIS"),
 "UqtkHpv6PJk":  ("DBRST","create-restore-db",          "Create database + restore database"),
 "Qh6ZYglhz_g":  ("PGFIX","pgadmin-wont-open-fix",      "Fix: pgAdmin4 won't open (video UNAVAILABLE)"),
 "uSnpKIrRK2w":  ("Z48",  "autocad-zone48-conversion",  "Convert AutoCad Lao97 data to Zone48"),
 "wfBiN0-ePUA":  ("QAD",  "qad-tool-qgis3",             "Using QAD measurement tool in QGIS3"),
}

def clean_vtt(path):
    """VTT auto-captions here come in 3-line groups:
       1) word-level raw with <c> tags + timestamps, 2) plain text, 3) plain text repeated.
       Keep only the plain line once per cue."""
    out = []
    prev = None
    for line in open(path, encoding="utf-8", errors="ignore").read().splitlines():
        s = line.strip()
        if not s or s.startswith("WEBVTT") or s.startswith("Kind:") or s.startswith("Language:") \
           or s.startswith("NOTE") or s.startswith("STYLE") or s.startswith("Region:"):
            continue
        if "-->" in s:
            continue
        # line with timing tags embedded
        if re.match(r"^.*<\d{2}:", s) or "<c>" in s:
            continue
        if s == prev:
            continue  # drop the immediate repeat
        out.append(s)
        prev = s
    return out

def find_vtt(vid):
    """Return (lang, path) of best available transcript for vid."""
    for lang in ("lo", "th"):
        for p in glob.glob(os.path.join(CAPS, f"{vid}_{lang}.*")):
            if p.endswith(".vtt") and os.path.getsize(p) > 100:
                return lang, p
    return None, None

def main():
    n_files = 0
    for vid, (ep, slug, en_label) in LABELS.items():
        if vid not in avail:
            a = {"title": LABELS[vid][2], "lo": False, "th": False, "dur": 0,
                 "error": "video unavailable on YouTube (404)"}
        else:
            a = avail[vid]
        title = a.get("title") or LABELS[vid][0]
        dur = a.get("dur") or 0
        minutes = f"{dur//60}m {dur%60:02d}s"

        lang, vpath = find_vtt(vid)
        lines = []
        if lang:
            lines = clean_vtt(vpath)
            txt_path = os.path.join(CLEAN, f"{vid}_{lang}.txt")
            open(txt_path, "w", encoding="utf-8").write("\n".join(lines))

        # Status block
        if a.get("error"):
            status = "VIDEO UNAVAILABLE on YouTube (404) — needs re-link or PERN copy"
        elif not a.get("lo") and not a.get("th"):
            status = ("NO ASR captions on YouTube. Audio is media-blocked on the "
                      "dev host (403) — download audio in browser, run faster-whisper "
                      "large-v3 locally, drop WAV into raw/audio/, re-run.")
        elif lang == "lo":
            status = f"Lao ASR transcript captured ({len(lines)} cues)"
        elif lang == "th":
            status = f"Thai ASR transcript captured ({len(lines)} cues) — verify vs video"
        else:
            status = "Transcript fetch pending (429 rate limit — will retry)"

        md = []
        md.append(f"# {ep} — {en_label}")
        md.append("")
        md.append(f"- **Lao title:** {title}")
        md.append(f"- **YouTube:** https://youtu.be/{vid} ({minutes})")
        md.append(f"- **Group:** { 'Part 1 · Core workflows' if ep in ('EP1','EP2','EP3','EP4','EP5','EP6','EP7','EP8','EP9','EP10','SYNC') else 'Part 2 · GIS tools & admin' }")
        md.append(f"- **Transcript status:** {status}")
        md.append("")
        md.append("## Transcript (ASR)")
        md.append("")
        if lines:
            md.append("<!-- Auto-generated captions: ASR quality, verify proper nouns/field names. -->")
            md.append("")
            md.append("\n".join(lines))
        else:
            md.append("_No transcript yet. See status._")
        md.append("")
        md.append("## Extracted screens / fields  (fill after human review or vision pass)")
        md.append("")
        md.append("| Screen / form | Fields & controls | Notes |")
        md.append("|---|---|---|")
        md.append("| _pending_ | | |")
        md.append("")
        md.append("## Workflow steps (from transcript)")
        md.append("")
        md.append("1. _pending — extract from transcript during spec build_")
        md.append("")
        md.append("## Open questions for review")
        md.append("")
        md.append("- [ ] _add questions that the transcript can't answer (rules, roles, edge cases)_")
        md.append("")

        # write file
        num = {"EP1":1,"EP2":2,"EP3":3,"EP4":4,"EP5":5,"EP6":6,"EP7":7,"EP8":8,"EP9":9,"EP10":10,
               "SYNC":11,"CFG":12,"BUF":13,"DRILL":14,"LREG":15,"BKUP":16,"EDIT":17,
               "DBRST":18,"PGFIX":19,"Z48":20,"QAD":21}[ep]
        outpath = os.path.join(OUT, f"{num:02d}_{slug}.md")
        open(outpath, "w", encoding="utf-8").write("\n".join(md))
        n_files += 1
        print(f"{outpath}  [{status.split(' —')[0][:40]}]")

    print(f"\n{len([p for p in os.listdir(OUT) if p.endswith('.md')])} files in {OUT}")

if __name__ == "__main__":
    main()
