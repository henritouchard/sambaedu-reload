@verbatim
@echo off
REM === SambaEdu script-execution-logs wrapper (Story 16.12 — Windows) ===
REM Emballe un script user user-managed (Story 17.x) ou GPO legacy pour
REM capturer stdout/stderr/exit_code/duration + POST le résultat sur
REM /api/v1/script-execution-logs (JWT lu depuis DPAPI HKLM).
REM Le wrapper retourne TOUJOURS l'exit_code du script user (transparent
REM pour la chaîne d'appel parente — ex: GPO logon/startup).
setlocal enabledelayedexpansion
@endverbatim

REM Variables injectées par WrapperScriptRenderer côté serveur :
set "CORR={{ $correlation_id }}"
set "ENDPOINT={{ $endpoint_url }}"
set "ACTION={{ $action }}"
set "OS_TAG={{ $os }}"
set "SOURCE_TAG={{ $source }}"
@if ($script_id !== null)
set "SCRIPT_ID={{ (int) $script_id }}"
@else
set "SCRIPT_ID="
@endif

@verbatim
set "SCRIPT_FILE=%TEMP%\sambaedu-script-%CORR%.cmd"
set "STDOUT_FILE=%TEMP%\sambaedu-stdout-%CORR%.log"
set "STDERR_FILE=%TEMP%\sambaedu-stderr-%CORR%.log"
set "BODY_FILE=%TEMP%\sambaedu-body-%CORR%.json"
set "B64_FILE=%TEMP%\sambaedu-script-%CORR%.b64"

REM 1. Décodage du script user (base64 → fichier .cmd temporaire).
REM    Le b64 est splitté en chunks de 4000 chars max (cmd.exe limite 8191/ligne)
REM    pour supporter les scripts user > 6 KB (post code-review F2 — Story 16.12).
del /q /f "%B64_FILE%" >nul 2>&1
@endverbatim
@php
    $chunks = str_split($script_content_b64, 4000);
@endphp
@foreach ($chunks as $chunk)
>>"%B64_FILE%" echo {!! $chunk !!}
@endforeach
@verbatim
certutil -decode "%B64_FILE%" "%SCRIPT_FILE%" >nul 2>&1

REM 2. Lecture timestamp démarrage (ISO 8601 UTC).
for /f "delims=" %%T in ('powershell -NoProfile -Command "[DateTime]::UtcNow.ToString('o')"') do set "STARTED_AT=%%T"
for /f "delims=" %%S in ('powershell -NoProfile -Command "[DateTime]::UtcNow.Ticks"') do set "STARTED_TICKS=%%S"

REM 3. Exécution du script user (stdout / stderr redirigés vers fichiers).
cmd /c "%SCRIPT_FILE%" > "%STDOUT_FILE%" 2> "%STDERR_FILE%"
set "EXIT_CODE=%ERRORLEVEL%"

REM 4. Calcul durée en ms.
for /f "delims=" %%E in ('powershell -NoProfile -Command "[Math]::Round(([DateTime]::UtcNow.Ticks - %STARTED_TICKS%) / 10000)"') do set "DURATION_MS=%%E"

REM 5. Détermine status applicatif.
if "%EXIT_CODE%"=="0" (
    set "STATUS=success"
) else if "%EXIT_CODE%"=="124" (
    set "STATUS=timeout"
) else (
    set "STATUS=failure"
)

REM 6. Lecture des excerpts stdout/stderr (head 4 KB + tail 4 KB max).
REM    Construction du body JSON via PowerShell ConvertTo-Json -Compress
REM    (pas de concat manuel → escape robuste des quotes et newlines).
REM    Lecture token DPAPI depuis HKLM\SOFTWARE\SambaEdu\AuthV1.
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$ErrorActionPreference='Continue';" ^
  "$stdoutPath = $env:STDOUT_FILE; $stderrPath = $env:STDERR_FILE;" ^
  "$stdout = if (Test-Path $stdoutPath) { (Get-Content -Raw -Encoding UTF8 -ErrorAction SilentlyContinue $stdoutPath) } else { '' };" ^
  "$stderr = if (Test-Path $stderrPath) { (Get-Content -Raw -Encoding UTF8 -ErrorAction SilentlyContinue $stderrPath) } else { '' };" ^
  "if ($null -eq $stdout) { $stdout = '' };" ^
  "if ($null -eq $stderr) { $stderr = '' };" ^
  "$maxBytes = 8192;" ^
  "if ([System.Text.Encoding]::UTF8.GetByteCount($stdout) -gt $maxBytes) { $stdout = ($stdout.Substring(0, [Math]::Min($stdout.Length, 4000))) + \"`n[...truncated]`n\" + ($stdout.Substring([Math]::Max(0, $stdout.Length - 4000))) };" ^
  "if ([System.Text.Encoding]::UTF8.GetByteCount($stderr) -gt $maxBytes) { $stderr = ($stderr.Substring(0, [Math]::Min($stderr.Length, 4000))) + \"`n[...truncated]`n\" + ($stderr.Substring([Math]::Max(0, $stderr.Length - 4000))) };" ^
  "$scriptId = if ($env:SCRIPT_ID -and $env:SCRIPT_ID -ne '') { [int]$env:SCRIPT_ID } else { $null };" ^
  "$body = [PSCustomObject]@{ script_id = $scriptId; script_source = $env:SOURCE_TAG; action = $env:ACTION; os = $env:OS_TAG; status = $env:STATUS; exit_code = [int]$env:EXIT_CODE; stdout = $stdout; stderr = $stderr; started_at = $env:STARTED_AT; duration_ms = [int]$env:DURATION_MS; correlation_id = $env:CORR } | ConvertTo-Json -Compress;" ^
  "[System.IO.File]::WriteAllText($env:BODY_FILE, $body, [System.Text.UTF8Encoding]::new($false));" ^
  "$tokenBlob = '';" ^
  "try { $regVal = (Get-ItemProperty -Path 'HKLM:\SOFTWARE\SambaEdu\AuthV1' -Name 'AccessTokenProtected' -ErrorAction Stop).AccessTokenProtected; $tokenBytes = [System.Security.Cryptography.ProtectedData]::Unprotect([Convert]::FromBase64String($regVal), $null, 'LocalMachine'); $tokenBlob = [System.Text.Encoding]::UTF8.GetString($tokenBytes); } catch { $tokenBlob = '' };" ^
  "if ($tokenBlob -eq '') { Write-Host '[sambaedu-wrapper] DPAPI token unreadable — skip POST'; exit 0 };" ^
  "$success = $false;" ^
  "foreach ($attempt in 1..3) {" ^
  "  try {" ^
  "    Invoke-RestMethod -Uri $env:ENDPOINT -Method Post -ContentType 'application/json' -Body $body -Headers @{ Authorization = 'Bearer ' + $tokenBlob } -TimeoutSec 10 -ErrorAction Stop | Out-Null;" ^
  "    $success = $true; break;" ^
  "  } catch { Start-Sleep -Seconds ($attempt * 2) }" ^
  "};" ^
  "if (-not $success) { Add-Content -Path \"$env:TEMP\sambaedu-wrapper-retry.log\" -Value (\"[$(Get-Date -Format o)] POST failed after 3 attempts — correlation=$env:CORR\") }"

REM 7. Cleanup fichiers temp.
del "%SCRIPT_FILE%" >nul 2>&1
del "%STDOUT_FILE%" >nul 2>&1
del "%STDERR_FILE%" >nul 2>&1
del "%BODY_FILE%" >nul 2>&1
del "%B64_FILE%" >nul 2>&1

endlocal & exit /b %EXIT_CODE%
@endverbatim
