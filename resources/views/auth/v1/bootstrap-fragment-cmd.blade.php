@echo off
REM === SambaEdu auto-bootstrap (Story 16.11) — idempotent ===
if exist "%ProgramData%\SambaEdu\auth.json" goto :sambaedu_skip
reg query "HKLM\SOFTWARE\SambaEdu\AuthV1" /v Migrated 2>nul | findstr /C:"Migrated" >nul && goto :sambaedu_skip
if exist "%SystemRoot%\Temp\sambaedu-bootstrap-running.flag" goto :sambaedu_skip
echo. > "%SystemRoot%\Temp\sambaedu-bootstrap-running.flag" 2>nul
REM Q1.b — token md5 frais posé en APCu par middleware InjectBootstrapFragment, transmis au script complet via env var.
set "BOOTSTRAP_TOKEN={!! $bootstrap_token_placeholder !!}"
curl.exe -kfsS "{!! $server_base_url !!}/api/v1/agent/bootstrap.cmd" > "%SystemRoot%\Temp\sambaedu-bootstrap.cmd" 2>nul
if exist "%SystemRoot%\Temp\sambaedu-bootstrap.cmd" call "%SystemRoot%\Temp\sambaedu-bootstrap.cmd"
del "%SystemRoot%\Temp\sambaedu-bootstrap.cmd" >nul 2>&1
del "%SystemRoot%\Temp\sambaedu-bootstrap-running.flag" >nul 2>&1
set "BOOTSTRAP_TOKEN="
:sambaedu_skip
REM === Fin auto-bootstrap ===

