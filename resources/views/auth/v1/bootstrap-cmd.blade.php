@echo off
REM ===================================================================
REM SambaEdu auto-bootstrap Windows (Story 16.11) — idempotent
REM   - Vérifie l'état migré local (registry HKLM)
REM   - Installe le CA root local dans Trusted Root machine
REM   - POST /api/v1/agent/enroll avec UUID/MAC/hostname locaux
REM   - Stocke tokens en DPAPI machine (HKLM REG_BINARY)
REM   - Dépose sambaedu-refresh.cmd local et crée tâche planifiée 25j
REM ===================================================================

setlocal enabledelayedexpansion

REM --- Idempotence ---
reg query "HKLM\SOFTWARE\SambaEdu\AuthV1" /v Migrated 2>nul | findstr /C:"Migrated" >nul
if not errorlevel 1 (
    echo [SambaEdu] Already migrated. Exiting.
    exit /b 0
)

REM --- Configuration ---
set "SERVER_BASE_URL={!! $server_base_url !!}"
set "ENROLL_ENDPOINT={!! $enroll_endpoint !!}"
set "REFRESH_ENDPOINT={!! $refresh_endpoint !!}"
set "PING_ENDPOINT={!! $ping_endpoint !!}"
set "CA_CERT_B64={!! $ca_cert_pem_b64 !!}"
set "REFRESH_SCRIPT=%ProgramData%\SambaEdu\sambaedu-refresh.cmd"

REM --- Installation CA root ---
set "CA_TMP=%TEMP%\sambaedu-ca-root.crt"
powershell -NoProfile -Command "[System.IO.File]::WriteAllBytes('%CA_TMP%', [System.Convert]::FromBase64String('%CA_CERT_B64%'))"
if not exist "%CA_TMP%" (
    echo [SambaEdu] Failed to decode CA cert. Aborting.
    exit /b 1
)

certutil.exe -addstore -f "Root" "%CA_TMP%" >nul 2>&1
if errorlevel 1 (
    echo [SambaEdu] Failed to install CA root. Aborting.
    del "%CA_TMP%" >nul 2>&1
    exit /b 1
)

REM --- Récupération métadonnées machine ---
for /f "tokens=*" %%U in ('powershell -NoProfile -Command "(Get-CimInstance Win32_ComputerSystemProduct).UUID"') do set "MACHINE_UUID=%%U"
for /f "tokens=*" %%M in ('powershell -NoProfile -Command "(Get-NetAdapter -Physical | Where-Object Status -eq 'Up' | Select-Object -First 1).MacAddress"') do set "MACHINE_MAC=%%M"
set "MACHINE_HOSTNAME=%COMPUTERNAME%"

REM --- Récupération bootstrap token frais via legacy applications.php ---
REM Story 16.11 Q1.b — Le BOOTSTRAP_TOKEN est passé en env var par le fragment.
REM Le fragment télécharge ce script complet via le middleware InjectBootstrapFragment
REM qui pose un contexte APCu apps.<token> avec uuid matching avant injection.

REM --- POST enroll ---
REM Génération du payload JSON via PowerShell ConvertTo-Json pour échapper proprement
REM les caractères spéciaux dans hostname/uuid/mac (guillemets, backslash, etc.).
for /f "delims=" %%P in ('powershell -NoProfile -Command "$b=@{uuid='%MACHINE_UUID%'; mac='%MACHINE_MAC%'; hostname='%MACHINE_HOSTNAME%'; os='windows'} | ConvertTo-Json -Compress; Write-Output $b"') do set "PAYLOAD=%%P"

powershell -NoProfile -Command ^
  "try {" ^
  "  $headers = @{ 'X-Bootstrap-Token' = $env:BOOTSTRAP_TOKEN; 'Content-Type' = 'application/json' };" ^
  "  $body = '%PAYLOAD%';" ^
  "  $resp = Invoke-RestMethod -Uri '%ENROLL_ENDPOINT%' -Method POST -Headers $headers -Body $body;" ^
  "  $accessProtected = [System.Security.Cryptography.ProtectedData]::Protect([Text.Encoding]::UTF8.GetBytes($resp.access_token), $null, 'LocalMachine');" ^
  "  $refreshProtected = [System.Security.Cryptography.ProtectedData]::Protect([Text.Encoding]::UTF8.GetBytes($resp.refresh_token), $null, 'LocalMachine');" ^
  "  New-Item -Path 'HKLM:\SOFTWARE\SambaEdu\AuthV1' -Force | Out-Null;" ^
  "  Set-ItemProperty -Path 'HKLM:\SOFTWARE\SambaEdu\AuthV1' -Name 'AccessTokenProtected' -Value $accessProtected -Type Binary;" ^
  "  Set-ItemProperty -Path 'HKLM:\SOFTWARE\SambaEdu\AuthV1' -Name 'RefreshTokenProtected' -Value $refreshProtected -Type Binary;" ^
  "  Set-ItemProperty -Path 'HKLM:\SOFTWARE\SambaEdu\AuthV1' -Name 'ServerBaseUrl' -Value '%SERVER_BASE_URL%' -Type String;" ^
  "  Set-ItemProperty -Path 'HKLM:\SOFTWARE\SambaEdu\AuthV1' -Name 'EnrolledAt' -Value (Get-Date -Format 'o') -Type String;" ^
  "  Set-ItemProperty -Path 'HKLM:\SOFTWARE\SambaEdu\AuthV1' -Name 'Migrated' -Value 1 -Type DWord;" ^
  "  Write-Host '[SambaEdu] Enrolled successfully.';" ^
  "  exit 0" ^
  "} catch {" ^
  "  Write-Host ('[SambaEdu] Enroll failed: ' + $_.Exception.Message);" ^
  "  exit 1" ^
  "}"

if errorlevel 1 (
    echo [SambaEdu] Bootstrap aborted on enroll failure.
    del "%CA_TMP%" >nul 2>&1
    exit /b 1
)

REM ===================================================================
REM SECTION 6 : déposer le script refresh local (sambaedu-refresh.cmd)
REM ===================================================================
REM Story 16.11 Q1.c — Le script refresh local lit le RefreshTokenProtected
REM depuis le registre via DPAPI Unprotect, POST /refresh avec body JSON,
REM puis ré-Protect les nouveaux access+refresh tokens.
REM Invocation : la tâche planifiée invoque CE script local (pas un curl direct).
REM ===================================================================

if not exist "%ProgramData%\SambaEdu" mkdir "%ProgramData%\SambaEdu" >nul 2>&1

REM Écriture du script refresh local via PowerShell heredoc-like (Out-File UTF8).
powershell -NoProfile -Command ^
  "$script = @'" ^
  "@echo off" ^
  "REM === SambaEdu refresh tokens (Story 16.11 Q1.c) ===" ^
  "powershell -NoProfile -ExecutionPolicy Bypass -Command \"& {" ^
  "  try {" ^
  "    $rp = Get-ItemProperty -Path 'HKLM:\\SOFTWARE\\SambaEdu\\AuthV1' -Name 'RefreshTokenProtected' -ErrorAction Stop;" ^
  "    $refreshBytes = [System.Security.Cryptography.ProtectedData]::Unprotect($rp.RefreshTokenProtected, $null, 'LocalMachine');" ^
  "    $refreshToken = [Text.Encoding]::UTF8.GetString($refreshBytes);" ^
  "    $body = @{ refresh_token = $refreshToken } | ConvertTo-Json -Compress;" ^
  "    $headers = @{ 'Content-Type' = 'application/json' };" ^
  "    $resp = Invoke-RestMethod -Uri '%REFRESH_ENDPOINT%' -Method POST -Headers $headers -Body $body;" ^
  "    $newAccess = [System.Security.Cryptography.ProtectedData]::Protect([Text.Encoding]::UTF8.GetBytes($resp.access_token), $null, 'LocalMachine');" ^
  "    $newRefresh = [System.Security.Cryptography.ProtectedData]::Protect([Text.Encoding]::UTF8.GetBytes($resp.refresh_token), $null, 'LocalMachine');" ^
  "    Set-ItemProperty -Path 'HKLM:\\SOFTWARE\\SambaEdu\\AuthV1' -Name 'AccessTokenProtected' -Value $newAccess -Type Binary;" ^
  "    Set-ItemProperty -Path 'HKLM:\\SOFTWARE\\SambaEdu\\AuthV1' -Name 'RefreshTokenProtected' -Value $newRefresh -Type Binary;" ^
  "    Set-ItemProperty -Path 'HKLM:\\SOFTWARE\\SambaEdu\\AuthV1' -Name 'RefreshedAt' -Value (Get-Date -Format 'o') -Type String;" ^
  "    Write-Host '[SambaEdu] Tokens refreshed.';" ^
  "    exit 0" ^
  "  } catch {" ^
  "    Write-Host ('[SambaEdu] Refresh failed: ' + $_.Exception.Message);" ^
  "    exit 1" ^
  "  }" ^
  "}\"" ^
  "exit /b %%ERRORLEVEL%%" ^
  "'@;" ^
  "Set-Content -Path '%REFRESH_SCRIPT%' -Value $script -Encoding ASCII -Force"

if not exist "%REFRESH_SCRIPT%" (
    echo [SambaEdu] Failed to deploy refresh script. Aborting.
    del "%CA_TMP%" >nul 2>&1
    exit /b 1
)

REM --- Création tâche planifiée refresh (invoque le script local) ---
schtasks /create /tn "SambaEdu-RefreshTokens" ^
    /sc daily /st 03:00 ^
    /ru SYSTEM ^
    /tr "%REFRESH_SCRIPT%" ^
    /f >nul 2>&1

del "%CA_TMP%" >nul 2>&1
echo [SambaEdu] Bootstrap complete.
exit /b 0
