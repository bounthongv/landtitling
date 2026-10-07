"""Run faster-whisper large-v3 (int8, CPU) on available local audio files.
Transcripts land in raw/audio/<id>.whisper.txt.
Model cache lives on D: (HF_HOME) to protect C:.
"""
import os, sys, time, shutil, subprocess

os.environ["HF_HOME"] = r"D:\whisper-cache\hf"
os.environ["HUGGINGFACE_HUB_CACHE"] = r"D:\whisper-cache\hf\hub"
os.environ["XDG_CACHE_HOME"] = r"D:\whisper-cache\xdg"
for d in (r"D:\whisper-cache\hf\hub", r"D:\whisper-cache\xdg"):
    os.makedirs(d, exist_ok=True)

import faster_whisper

A = r"D:\LandTitling\docs\llr7-spec\raw\audio"

files = [f for f in os.listdir(A)
         if f.lower().endswith((".m4a", ".wav", ".mp3", ".opus", ".webm", ".flac", ".ogg"))
         and not f.endswith(".txt")]
print(f"AUDIO FILES: {files}", flush=True)

import subprocess
ffmpeg = shutil.which("ffmpeg") or "ffmpeg"
for f in files:
    p = os.path.join(A, f)
    if not f.lower().endswith(".wav"):
        wav = p.rsplit(".", 1)[0] + ".wav"
        if os.path.exists(wav):
            continue
        print(f"{f}: converting to wav via ffmpeg ...", flush=True)
        r = subprocess.run([ffmpeg, "-y", "-i", p, "-ar", "16000", "-ac", "1", "-c:a",
                            "pcm_s16le", wav], capture_output=True, text=True)
        if r.returncode != 0 or not os.path.exists(wav):
            print(f"  FAILED ({r.stderr[-300:]}); skipping", flush=True)
            continue

print("Loading large-v3 int8 (cached on D: after first run)...", flush=True)
t0 = time.time()
# NOTE: PERN narration is THAI (SETUP auto-detect=th 0.992). Do NOT force
# language="lo". Auto-detect per clip, fall back to "th". beam_size=1 +
# vad_filter=True avoid the mkl_malloc OOM that beam_size=5 + no-VAD hit.
model = faster_whisper.WhisperModel("large-v3", device="cpu", compute_type="int8",
                                    cpu_threads=14)
print(f"model ready in {time.time()-t0:.0f}s", flush=True)

for f in files:
    p = os.path.join(A, f)
    out = p.rsplit(".", 1)[0] + ".whisper.txt"
    if os.path.exists(out) and os.path.getsize(out) > 50:
        print(f"{f}: cached", flush=True)
        continue
    p_use = p.rsplit(".", 1)[0] + ".wav" if os.path.exists(p.rsplit(".", 1)[0] + ".wav") else p
    if p_use != p:
        print(f"  using converted {os.path.basename(p_use)}", flush=True)
    t0 = time.time()
    segs, info = model.transcribe(p_use, language=None, beam_size=1, vad_filter=True)
    lang = info.language
    if lang not in ("lo", "th"):
        lang = "th"
        segs, info = model.transcribe(p_use, language=lang, beam_size=1, vad_filter=True)
    lines = [s.text.strip() for s in segs if s.text.strip()]
    open(out, "w", encoding="utf-8").write(
        f"# detected_language={lang} (prob={info.language_probability:.2f})\n"
        + "\n".join(lines))
    print(f"{f}: lang={lang} {len(lines)} lines in {time.time()-t0:.0f}s -> {out}", flush=True)

print("WHISPER PASS DONE", flush=True)
