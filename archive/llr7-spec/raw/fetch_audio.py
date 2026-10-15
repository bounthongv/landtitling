"""Retry audio downloads for the 6 clips without ASR + convert to 16k wav.
Runs with persistence: each video retried up to 8 times with backoff.
Drop target: raw/audio/<YouTubeID>.m4a (+ .wav for whisper).
"""
import os, time, subprocess, sys, yt_dlp

RAW = r"D:\LandTitling\docs\llr7-spec\raw"
A   = os.path.join(RAW, "audio")
os.makedirs(A, exist_ok=True)

NEED = {
 "ZeVzTp1T7XI": "EP5 subdivision",
 "mp22eHRhsmM": "EP7 merging",
 "r1AmBLHJ2Ro": "EP8 legacy import",
 "Qe-M0fjP0PQ": "EP9 mortgage",
 "jYLmyWtqps8": "LREG export",
 "BfEUdQoXntE": "pgAdmin backup",
}

def m4a_to_wav(m4a):
    wav = m4a.rsplit(".",1)[0] + ".wav"
    r = subprocess.run(["ffmpeg","-y","-i",m4a,"-ar","16000","-ac","1","-b:a","128k",wav],
                       capture_output=True)
    return wav if r.returncode == 0 else None

ok, fail = [], []
for vid, label in NEED.items():
    m4a = os.path.join(A, f"{vid}.m4a")
    if os.path.exists(m4a) and os.path.getsize(m4a) > 100_000:
        w = m4a_to_wav(m4a)
        ok.append(f"{vid} ({label}) cached m4a{'+wav' if w else ''}"); continue
    last = "unknown"
    for i in range(8):
        opts = {"format":"bestaudio[acodec!=none]/best","outtmpl":m4a,
                "quiet":True,"no_warnings":True,"noprogress":True,
                "extractor_args":{"youtube":{"player_client":"web,web_safari"}}}
        try:
            with yt_dlp.YoutubeDL(opts) as y:
                y.download([f"https://www.youtube.com/watch?v={vid}"])
            if os.path.exists(m4a) and os.path.getsize(m4a) > 100_000:
                w = m4a_to_wav(m4a)
                ok.append(f"{vid} ({label}) attempt{i+1} "
                          f"{os.path.getsize(m4a)//1024}KB{'+wav' if w else ''}")
                last = "OK"
                break
            last = "empty file"
        except Exception as e:
            last = str(e)[:80]
        time.sleep(45*(i+1))
    print(f"{vid} ({label}): {last}", flush=True)
    if last != "OK":
        fail.append(vid)

print("\nSUMMARY ok=", len(ok), "fail=", len(fail), fail)
open(os.path.join(A,"audio_pass.log"),"w",encoding="utf-8").write(
    "\n".join(["OK: "+o for o in ok]+["FAIL: "+f for f in fail]))
