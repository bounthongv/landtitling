"""Bounded fetch: lo captions (13) + th fallback + best thumbnails (20).
Artifacts: caps/  (vtt),  thumb/  (jpg).  No long sleeps.
"""
import os, json, time, urllib.request
import yt_dlp

RAW   = r"D:\LandTitling\docs\llr7-spec\raw"
CAPS  = os.path.join(RAW, "caps")
THUMB = os.path.join(RAW, "thumb")
os.makedirs(THUMB, exist_ok=True)

avail = json.load(open(os.path.join(CAPS, "lo_availability.json"), encoding="utf-8"))

def fetch_caption(vid, lang, tries=4):
    dest = os.path.join(CAPS, f"{vid}_{lang}.vtt")
    if os.path.exists(dest) and os.path.getsize(dest) > 100:
        return "cached"
    opts = {"skip_download":True,"writeautomaticsub":True,"subtitleslangs":[lang],
            "outtmpl":os.path.join(CAPS,f"{vid}_{lang}.%(ext)s"),
            "quiet":True,"no_warnings":True,"noprogress":True}
    for a in range(tries):
        try:
            with yt_dlp.YoutubeDL(opts) as y:
                y.download([f"https://www.youtube.com/watch?v={vid}"])
            if os.path.exists(dest) and os.path.getsize(dest) > 100:
                return "ok"
            return "empty"
        except Exception as e:
            if "429" in str(e) or "rate" in str(e).lower():
                time.sleep(15); continue
            return f"err:{str(e)[:60]}"
    return "429-gave-up"

report = {}
for vid, d in avail.items():
    if d.get("error"):
        report[vid] = {"skip":"video unavailable"}; continue
    row = {}
    if d.get("lo"):
        row["lo"] = fetch_caption(vid, "lo"); time.sleep(2)
    if not d.get("lo") and d.get("th"):
        row["th"] = fetch_caption(vid, "th"); time.sleep(2)
    report[vid] = row
    print(vid, row, flush=True)

json.dump(report, open(os.path.join(CAPS,"cap_report.json"),"w"), indent=1)
print("CAPTION PASS DONE")
