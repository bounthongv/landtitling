@echo off
rem ============================================================
rem  One-click audio downloader for the 6 LLR7 spec clips
rem  Run on ANY Windows PC that can reach YouTube (home PC).
rem  Then copy the audio_download\*.m4a (or .webm) files to:
rem    D:\LandTitling\docs\llr7-spec\raw\audio\
rem  (or this folder, if this PC has the repo checked out - the
rem   script drops them into raw\audio\ automatically in that case)
rem ============================================================
setlocal
set OUTDIR=%~dp0audio_download
if not exist "%OUTDIR%" mkdir "%OUTDIR%"

rem If run from inside the repo, drop straight into raw\audio\
if exist "%~dp0raw\audio" (
  set OUTDIR=%~dp0raw\audio
)

echo [1/3] Checking yt-dlp ...
python -m yt_dlp --version >nul 2>nul
if errorlevel 1 (
  echo yt-dlp not found - installing via pip ...
  python -m pip install -U yt-dlp
)
if errorlevel 1 (
  echo Could not install yt-dlp. Install Python first: https://www.python.org/downloads/
  pause & exit /b 1
)

echo [2/3] Downloading audio-only for 6 clips ...
python -m yt_dlp -f "bestaudio" --no-update ^
  -o "%OUTDIR%\%%(id)s.%%(ext)s" ^
  "https://youtu.be/ZeVzTp1T7XI" ^
  "https://youtu.be/mp22eHRhsmM" ^
  "https://youtu.be/r1AmBLHJ2Ro" ^
  "https://youtu.be/Qe-M0fjP0PQ" ^
  "https://youtu.be/jYLmyWtqps8" ^
  "https://youtu.be/BfEUdQoXntE"

echo.
echo [3/3] Result in: %OUTDIR%
dir /b "%OUTDIR%" 2>nul | findstr /i "ZeVzTp1T7XI mp22eHRhsmM r1AmBLHJ2Ro Qe-M0fjP0PQ jYLmyWtqps8 BfEUdQoXntE" >nul
if errorlevel 1 (
  echo !! SOME OR ALL FILES MISSING - check errors above, re-run.
) else (
  echo OK - all 6 present. If %OUTDIR% is not the repo raw\audio,
  echo copy the files into D:\LandTitling\docs\llr7-spec\raw\audio\
  echo (any of m4a / webm / opus / flac is fine - the whisper
  step converts to wav automatically).
)
pause
