@echo off
rem ============================================================
rem  LLR7 spec clips - one-click audio downloader (v3, NO Python)
rem
rem  Run on a PC with a residential connection (home/phone hotspot).
rem  Needs nothing installed: it grabs a standalone yt-dlp.exe
rem  from GitHub automatically on first run.
rem  If it fails: send me  audio_download_log.txt  (next to this .bat)
rem ============================================================
setlocal EnableExtensions

set "DIR=%~dp0"
set "LOG=%DIR%audio_download_log.txt"
set "OUTDIR=%DIR%audio_download"
if exist "%DIR%raw\audio" set "OUTDIR=%DIR%raw\audio"
if not exist "%OUTDIR%" mkdir "%OUTDIR%"

echo ============================================
echo  LLR7 audio downloader v3 - no Python needed
echo  Output folder : %OUTDIR%
echo  Log file      : %LOG%
echo ============================================
echo.

set "YTDLP=%DIR%yt-dlp.exe"
if not exist "%YTDLP%" (
  echo [1/2] First run - downloading standalone yt-dlp.exe from GitHub ...
  powershell -NoProfile -Command "Invoke-WebRequest -Uri 'https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp.exe' -OutFile '%YTDLP%'"
  if not exist "%YTDLP%" (
    echo [FAIL] Could not download yt-dlp.exe - check internet access.
    goto :finish
  )
  echo [ok] yt-dlp.exe ready
) else (
  echo [ok] yt-dlp.exe already present
)

echo [2/2] Downloading 6 clips - takes a few minutes.
echo       Progress in: %LOG%
echo.
"%YTDLP%" -f bestaudio --no-update --retries 5 ^
  -o "%OUTDIR%\%%(id)s.%%(ext)s" ^
  "https://youtu.be/ZeVzTp1T7XI" "https://youtu.be/mp22eHRhsmM" "https://youtu.be/r1AmBLHJ2Ro" ^
  "https://youtu.be/Qe-M0fjP0PQ" "https://youtu.be/jYLmyWtqps8" "https://youtu.be/BfEUdQoXntE" ^
  >"%LOG%" 2>&1
if errorlevel 1 (
  echo [WARN] Some downloads failed - full details in: %LOG%
)

echo.
echo === Files in output folder ===
dir /b "%OUTDIR%" 2>nul
echo.
echo If you see 6 audio files above:
echo   - if this PC has the repo, they are already in raw\audio: done, tell me.
echo   - otherwise copy them into  D:\LandTitling\docs\llr7-spec\raw\audio\
echo If NOT all 6: send me  %LOG%
:finish
echo.
pause
