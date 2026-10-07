@echo off
rem ============================================================
rem  LLR7 spec clips - one-click audio downloader (v2)
rem
rem  Run on any Windows PC that can reach YouTube.
rem  If it fails: just send me the file
rem      audio_download_log.txt   (sits next to this .bat)
rem  No need to screenshot anything - the log has all details.
rem ============================================================
setlocal EnableExtensions

set "DIR=%~dp0"
set "LOG=%DIR%audio_download_log.txt"
set "OUTDIR=%DIR%audio_download"
if exist "%DIR%raw\audio" set "OUTDIR=%DIR%raw\audio"
if not exist "%OUTDIR%" mkdir "%OUTDIR%"

echo ============================================
echo  LLR7 audio downloader
echo  Output folder : %OUTDIR%
echo  Log file      : %LOG%
echo ============================================
echo.

rem ---- find a working Python launcher (python / py / python3)
set "PY="
for %%P in (python py python3) do (
  if not defined PY (
    %%P -V >nul 2>&1 && set "PY=%%P"
  )
)
if not defined PY (
  echo [FAIL] No Python found on this PC - tried python, py, python3.
  echo        Install it: https://www.python.org/downloads/
  echo        IMPORTANT: tick "Add python.exe to PATH" during install.
  echo        Then double-click this file again.
  goto :finish
)
echo [ok] Using Python launcher: %PY%

rem ---- make sure the yt-dlp module is installed
%PY% -m yt_dlp --version >nul 2>&1
if errorlevel 1 (
  echo [..] yt-dlp not found - installing with pip - needs internet ...
  %PY% -m pip install -U yt-dlp >"%LOG%" 2>&1
  if errorlevel 1 (
    rem pip missing too? bootstrap it, then retry
    %PY% -m ensurepip >nul 2>&1
    %PY% -m pip install -U yt-dlp >>"%LOG%" 2>&1
    if errorlevel 1 (
      echo [FAIL] Could not install yt-dlp on this PC.
      echo        Details in log: %LOG%
      goto :finish
    )
  )
)
echo [ok] yt-dlp ready

rem ---- download all 6 clips (audio only, named by video ID)
echo [..] Downloading 6 clips - this takes a few minutes.
echo     Progress is written to: %LOG%
echo.
%PY% -m yt_dlp -f bestaudio --no-update --retries 5 ^
  -o "%OUTDIR%\%%(id)s.%%(ext)s" ^
  "https://youtu.be/ZeVzTp1T7XI" "https://youtu.be/mp22eHRhsmM" "https://youtu.be/r1AmBLHJ2Ro" ^
  "https://youtu.be/Qe-M0fjP0PQ" "https://youtu.be/jYLmyWtqps8" "https://youtu.be/BfEUdQoXntE" ^
  >"%LOG%" 2>&1
if errorlevel 1 (
  echo [WARN] yt-dlp reported errors - full details in: %LOG%
)

rem ---- report what landed
echo.
echo === Files in output folder ===
dir /b "%OUTDIR%" 2>nul
echo.
echo If you see 6 audio files above:
echo   - if this PC has the repo, they are already in raw\audio: done, just tell me.
echo   - otherwise copy them into  D:\LandTitling\docs\llr7-spec\raw\audio\
echo If not all 6 are there: send me  %LOG%
:finish
echo.
pause
