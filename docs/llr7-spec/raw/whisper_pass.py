"""Orchestrate the Whisper pass: one FRESH process per 3-minute chunk.

Why fresh processes: CTranslate2/MKL in a long-lived process OOMs
(mkl_malloc) after hibernate/resume. A fresh process per chunk has a clean
allocator; the chunk cache (raw/audio/<id>.chunks/) means a crash or reboot
loses at most one 3-min chunk.

Usage:
    python whisper_pass.py            # transcribe everything not done yet
    python whisper_pass.py <vid> ...  # only specific video ids
"""
import os, re, time, math, shutil, subprocess

A = r"D:\LandTitling\docs\llr7-spec\raw\audio"
RAW = os.path.dirname(os.path.dirname(A))
PY = r"C:\Users\bount\AppData\Local\hermes\hermes-agent\.venv\Scripts\python.exe"
CHUNK = 180  # seconds
THREADS_FALLBACK = [4, 2, 1]  # try 4, then 2, then 1 if a chunk OOMs
CHUNK_OK = "### CHOK"


def get_duration(path):
    import wave
    with wave.open(path, "rb") as w:
        return w.getnframes() / float(w.getframerate())


def pick_files(only):
    files = [f for f in os.listdir(A)
             if f.lower().endswith((".m4a", ".wav", ".mp3", ".opus",
                                    ".webm", ".flac", ".ogg"))
             and not f.endswith(".txt")]
    if only:
        files = [f for f in files if f.rsplit(".", 1)[0] in only]
    # P1 clips (the 4 core missing workflows) first
    p1 = {"mp22eHRhsmM", "r1AmBLHJ2Ro", "Qe-M0fjP0PQ", "ZeVzTp1T7XI"}
    files.sort(key=lambda f: (0 if f.split(".")[0] in p1 else 1, f))
    return files


def ensure_wav(f):
    """Return the wav path for f, converting if needed. None on failure."""
    p = os.path.join(A, f)
    wav = p.rsplit(".", 1)[0] + ".wav"
    if os.path.exists(wav):
        return wav
    ffmpeg = shutil.which("ffmpeg") or "ffmpeg"
    print(f"{f}: converting to wav ...", flush=True)
    r = subprocess.run([ffmpeg, "-y", "-i", p, "-ar", "16000", "-ac", "1",
                        "-c:a", "pcm_s16le", wav], capture_output=True, text=True)
    if r.returncode != 0 or not os.path.exists(wav):
        print(f"  convert FAILED ({r.stderr[-300:]}); skipping file", flush=True)
        return None
    return wav


def run_chunk(cdir, cwav, i, n, lang):
    """Run one chunk in a fresh process. lang is 'auto' for chunk 1, else
    the detected language. Returns (lines, lang)."""
    cpath = os.path.join(cdir, f"{i+1:03d}.txt")
    if os.path.exists(cpath) and CHUNK_OK in open(cpath, encoding="utf-8",
                                                  errors="ignore").read():
        body = open(cpath, encoding="utf-8", errors="ignore").read()
        got_lang = re.search(r"^## lang=(\S+)", body, re.M)
        lines = [l for l in body.splitlines()
                 if not l.startswith("## lang=") and l != CHUNK_OK]
        return lines, (got_lang.group(1) if got_lang else lang)
    print(f"  chunk {i+1}/{n} ...", flush=True)
    t1 = time.time()
    used_threads = None
    for idx, threads in enumerate(THREADS_FALLBACK):
        cmd = [PY, os.path.join(RAW, "whisper_chunk.py"), cwav, lang, cpath,
               str(threads)]
        r = subprocess.run(cmd, capture_output=True, text=True, timeout=1200)
        body = open(cpath, encoding="utf-8", errors="ignore").read() \
            if os.path.exists(cpath) else ""
        if CHUNK_OK in body:
            used_threads = threads
            break
        detail = (r.stderr.strip().splitlines()[-1]
                  if r.stderr.strip() else "no sentinel")
        if idx < len(THREADS_FALLBACK) - 1:
            print(f"  chunk {i+1}: threads={threads} failed ({detail}); "
                  f"retrying with {THREADS_FALLBACK[idx+1]} threads", flush=True)
        else:
            print(f"  chunk {i+1}: FAILED at all thread counts ({detail})",
                  flush=True)
    body = open(cpath, encoding="utf-8", errors="ignore").read() \
        if os.path.exists(cpath) else ""
    got_lang = re.search(r"^## lang=(\S+)", body, re.M)
    lines = [l for l in body.splitlines()
             if not l.startswith("## lang=") and l != CHUNK_OK]
    out_lang = got_lang.group(1) if got_lang else lang
    print(f"    -> {len(lines)} lines in {time.time()-t1:.0f}s "
          f"(threads={used_threads}, lang={out_lang})", flush=True)
    return lines, out_lang


def main():
    import sys
    only = set(sys.argv[1:])
    files = pick_files(only)
    print("FILES:", [f.rsplit('.', 1)[0] for f in files], flush=True)

    for f in files:
        wav = ensure_wav(f)
        if wav is None:
            continue
        vid = f.rsplit(".", 1)[0]
        out = os.path.join(A, vid + ".whisper.txt")
        cdir = os.path.join(A, vid + ".chunks")
        if os.path.exists(out) and "### DONE" in open(out, encoding="utf-8",
                                                      errors="ignore").read():
            print(f"{f}: DONE (cached)", flush=True)
            continue
        os.makedirs(cdir, exist_ok=True)
        dur = get_duration(wav)
        n = max(1, math.ceil(dur / CHUNK))
        lines_all, lang = [], "auto"
        for i in range(n):
            cwav = os.path.join(cdir, f"{i+1:03d}.wav")
            ffmpeg = shutil.which("ffmpeg") or "ffmpeg"
            subprocess.run([ffmpeg, "-y", "-ss", str(i * CHUNK), "-t", str(CHUNK),
                            "-i", wav, "-ar", "16000", "-ac", "1", "-c:a",
                            "pcm_s16le", cwav], capture_output=True, text=True)
            lines, lang = run_chunk(cdir, cwav, i, n, lang)
            lines_all.extend(lines)
            try:
                os.remove(cwav)
            except OSError:
                pass
        open(out, "w", encoding="utf-8").write(
            f"# detected_language={lang if lang != 'auto' else '?'}\n"
            f"# chunks={n}x{CHUNK}s\n" + "\n".join(lines_all) + "\n### DONE\n")
        print(f"{f}: {len(lines_all)} lines total -> {vid}.whisper.txt",
              flush=True)

    print("WHISPER PASS DONE", flush=True)


if __name__ == "__main__":
    main()
