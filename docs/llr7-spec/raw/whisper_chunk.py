"""Transcribe ONE chunk of audio in a fresh process.

Fresh-process-per-chunk avoids the mkl_malloc OOM that long-lived CTranslate2
processes hit after hibernate/resume. Also fast to crash: worst case loses
one 3-min chunk, which the orchestrator re-runs from its chunk cache.

Usage:
    python whisper_chunk.py <chunk.wav> <lang|auto> <out.txt> [threads]

Writes to out.txt:
    ## lang=th            (only when lang arg was "auto")
    <transcript lines>
    ### CHOK              (sentinel: chunk completed successfully)
"""
import os, sys, time


def main():
    cwav, lang_arg, out = sys.argv[1], sys.argv[2], sys.argv[3]
    threads = int(sys.argv[4]) if len(sys.argv) > 4 else 4

    os.environ["OMP_NUM_THREADS"] = str(threads)
    os.environ["MKL_NUM_THREADS"] = str(threads)
    os.environ["HF_HOME"] = r"D:\whisper-cache\hf"
    os.environ["HUGGINGFACE_HUB_CACHE"] = r"D:\whisper-cache\hf\hub"
    os.environ["XDG_CACHE_HOME"] = r"D:\whisper-cache\xdg"

    import faster_whisper
    t0 = time.time()
    model = faster_whisper.WhisperModel("large-v3", device="cpu",
                                        compute_type="int8", cpu_threads=threads)
    print(f"model ready in {time.time()-t0:.0f}s", flush=True)

    lines, lang = [], lang_arg
    if lang_arg == "auto":
        # PERN narration is Thai (SETUP auto-detect=th 0.992); treat Lao the
        # same, fall back to Thai for anything else.
        segs, info = model.transcribe(cwav, language=None, beam_size=1,
                                      vad_filter=True)
        lang = info.language if info.language in ("th", "lo") else "th"
        lines = [s.text.strip() for s in segs if s.text.strip()]
    else:
        segs, _ = model.transcribe(cwav, language=lang_arg, beam_size=1,
                                   vad_filter=True)
        lines = [s.text.strip() for s in segs if s.text.strip()]

    with open(out, "w", encoding="utf-8") as fh:
        if lang_arg == "auto":
            fh.write(f"## lang={lang}\n")
        fh.write("\n".join(lines) + "\n### CHOK\n")
    print(f"chunk ok: lang={lang} {len(lines)} lines in {time.time()-t0:.0f}s",
          flush=True)


if __name__ == "__main__":
    main()
