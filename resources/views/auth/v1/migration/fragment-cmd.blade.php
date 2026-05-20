@echo off
chcp 65001 > NUL
REM ===================================================================
REM SambaEdu — Fragment migration SE4 -> SE5 (Story 16.13bis)
REM Module App\Auth\V1\Migration — auto-obsolescence quand plus aucun
REM deploiement SE4 actif n'existe (Sprint Change Proposal 2026-05-19).
REM ===================================================================
REM Etapes :
REM   1. Idempotence : exit si HKLM\SOFTWARE\SambaEdu\AuthV1\Migrated=1
REM   2. Decode & install CA root local (Cert:\LocalMachine\Root)
REM   3. Collecte UUID / MAC / hostname locaux
REM   4. POST /api/v1/agent/enroll (HTTPS strict, pas de skip TLS)
REM   5. Stockage tokens DPAPI machine (HKLM REG_BINARY)
REM   6. Write registre HKLM\SOFTWARE\SambaEdu\AuthV1\Endpoints\* pour
REM      pointer les futurs appels vers /api/v1/workstation-config/*
REM   7. Marquage Migrated = 1 (DWORD)
REM   8. Message console + shutdown /r /t 30
REM ===================================================================

setlocal enabledelayedexpansion

REM --- 1. Idempotence ---
REM Story 16.13bis — Correction Opus-D (2026-05-20) : parse REG_DWORD STRICT
REM `0x1$` (fin de ligne) au lieu du nom de cle. Un admin peut remettre
REM `Migrated=0` (reg add ... /v Migrated /t REG_DWORD /d 0 /f) pour relancer
REM la migration manuellement — le findstr courant matchait n'importe quelle
REM valeur et bloquait le re-run.
reg query "HKLM\SOFTWARE\SambaEdu\AuthV1" /v Migrated 2>nul | findstr /R "0x1$" >nul
if not errorlevel 1 (
    echo [SambaEdu] Poste deja migre. No-op.
    exit /b 0
)

REM --- 2. Configuration ---
set "SERVER_BASE_URL={!! $server_base_url !!}"
set "ENROLL_ENDPOINT={!! $enroll_endpoint !!}"
set "REFRESH_ENDPOINT={!! $refresh_endpoint !!}"
set "WORKSTATION_CONFIG_BASE={!! $workstation_config_base !!}"
set "CA_CERT_B64={!! $ca_cert_pem_b64 !!}"
REM Story 16.13bis — Correction Q1 Option A (2026-05-20) : BOOTSTRAP_TOKEN
REM minté côté serveur (clé APCu apps.<token>, TTL 1800s, parité 16.11).
REM Token éphémère 32 chars hex — validé par RequireBootstrapToken à l'enroll.
set "BOOTSTRAP_TOKEN={{ $bootstrap_token }}"
set "REFRESH_SCRIPT=%ProgramData%\SambaEdu\sambaedu-refresh.cmd"

REM --- 3. Decode & install CA root local ---
set "CA_TMP=%TEMP%\sambaedu-ca-root.crt"
powershell -NoProfile -Command "[System.IO.File]::WriteAllBytes('%CA_TMP%', [System.Convert]::FromBase64String('%CA_CERT_B64%'))"
if not exist "%CA_TMP%" (
    echo [SambaEdu] Echec decodage CA root. Migration annulee.
    exit /b 1
)

REM Story 16.13bis — Correction Opus-B (2026-05-20) : check taille minimale
REM defense-in-depth (un CA PEM reel fait >1500 bytes ; 100 bytes garantit
REM qu'on n'installe pas un fichier vide/tronque si CA_CERT_B64 etait vide).
for %%S in ("%CA_TMP%") do if %%~zS LSS 100 (
    echo [SambaEdu] CA root invalide ou tronque ^(taille %%~zS bytes^). Migration annulee.
    del /q "%CA_TMP%" 2>nul
    exit /b 2
)

certutil.exe -addstore -f "Root" "%CA_TMP%" >nul 2>&1
if errorlevel 1 (
    echo [SambaEdu] Echec installation CA root. Migration annulee.
    del "%CA_TMP%" >nul 2>&1
    exit /b 1
)

REM --- 4. Collecte metadata machine ---
for /f "tokens=*" %%U in ('powershell -NoProfile -Command "(Get-CimInstance Win32_ComputerSystemProduct).UUID"') do set "MACHINE_UUID=%%U"
for /f "tokens=*" %%M in ('powershell -NoProfile -Command "(Get-NetAdapter -Physical | Where-Object Status -eq 'Up' | Select-Object -First 1).MacAddress"') do set "MACHINE_MAC=%%M"
set "MACHINE_HOSTNAME=%COMPUTERNAME%"

REM --- 5. POST /api/v1/agent/enroll (HTTPS strict, sans -SkipCertificateCheck) ---
REM Le BOOTSTRAP_TOKEN a ete pose en debut de script via "set" (cf. Story
REM 16.13bis Correction Q1 Option A). PowerShell le recupere via $env:BOOTSTRAP_TOKEN
REM (set propage l'env vers les sous-processus). Si APCu cote serveur a
REM rejette le store, l'enroll renvoie 401 et le fragment quitte sans
REM marquer Migrated=1 (retentative au prochain boot).
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
  "  Write-Host '[SambaEdu] Enrollment reussi.';" ^
  "  exit 0" ^
  "} catch {" ^
  "  Write-Host ('[SambaEdu] Enrollment echoue : ' + $_.Exception.Message);" ^
  "  exit 1" ^
  "}"

if errorlevel 1 (
    echo [SambaEdu] Migration annulee suite a echec enroll.
    del "%CA_TMP%" >nul 2>&1
    exit /b 1
)

REM --- 6. Write registre HKLM\SOFTWARE\SambaEdu\AuthV1\Endpoints\* ---
REM Pivot par fichier conf (D6) : les futurs scripts logon Windows liront
REM ces cles pour pointer vers /api/v1/workstation-config/* au lieu de
REM /sambaedu/gpo/*_out.php legacy.
powershell -NoProfile -Command ^
  "New-Item -Path 'HKLM:\SOFTWARE\SambaEdu\AuthV1\Endpoints' -Force | Out-Null;" ^
  "Set-ItemProperty -Path 'HKLM:\SOFTWARE\SambaEdu\AuthV1\Endpoints' -Name 'WallpaperUrl' -Value '%WORKSTATION_CONFIG_BASE%/wallpaper' -Type String;" ^
  "Set-ItemProperty -Path 'HKLM:\SOFTWARE\SambaEdu\AuthV1\Endpoints' -Name 'FirefoxUrl' -Value '%WORKSTATION_CONFIG_BASE%/firefox' -Type String;" ^
  "Set-ItemProperty -Path 'HKLM:\SOFTWARE\SambaEdu\AuthV1\Endpoints' -Name 'ThunderbirdUrl' -Value '%WORKSTATION_CONFIG_BASE%/thunderbird' -Type String;" ^
  "Set-ItemProperty -Path 'HKLM:\SOFTWARE\SambaEdu\AuthV1\Endpoints' -Name 'ShortcutsUrl' -Value '%WORKSTATION_CONFIG_BASE%/shortcuts' -Type String;" ^
  "Set-ItemProperty -Path 'HKLM:\SOFTWARE\SambaEdu\AuthV1\Endpoints' -Name 'NetworkUrl' -Value '%WORKSTATION_CONFIG_BASE%/network' -Type String;" ^
  "Set-ItemProperty -Path 'HKLM:\SOFTWARE\SambaEdu\AuthV1\Endpoints' -Name 'VeyonUrl' -Value '%WORKSTATION_CONFIG_BASE%/veyon' -Type String;" ^
  "Set-ItemProperty -Path 'HKLM:\SOFTWARE\SambaEdu\AuthV1\Endpoints' -Name 'AssociationsUrl' -Value '%WORKSTATION_CONFIG_BASE%/associations' -Type String;" ^
  "Set-ItemProperty -Path 'HKLM:\SOFTWARE\SambaEdu\AuthV1\Endpoints' -Name 'ApplicationsScriptsUrl' -Value '%WORKSTATION_CONFIG_BASE%/applications-scripts' -Type String;"

REM --- 7. Marquage Migrated = 1 (avant-dernier step pour idempotence R4) ---
REM Si le script crashe avant ce point, le poste relance le fragment au
REM prochain boot et reprend la migration depuis le debut (les ecritures
REM precedentes sont idempotentes : install CA, tokens, registre).
powershell -NoProfile -Command "Set-ItemProperty -Path 'HKLM:\SOFTWARE\SambaEdu\AuthV1' -Name 'Migrated' -Value 1 -Type DWord;"

REM --- 8. Tache planifiee refresh tokens (parite 16.11) ---
if not exist "%ProgramData%\SambaEdu" mkdir "%ProgramData%\SambaEdu" >nul 2>&1

powershell -NoProfile -Command ^
  "$script = @'" ^
  "@echo off" ^
  "REM === SambaEdu refresh tokens (Story 16.13bis) ===" ^
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
  "    exit 0" ^
  "  } catch { exit 1 }" ^
  "}\"" ^
  "exit /b %%ERRORLEVEL%%" ^
  "'@;" ^
  "Set-Content -Path '%REFRESH_SCRIPT%' -Value $script -Encoding ASCII -Force"

schtasks /create /tn "SambaEdu-RefreshTokens" ^
    /sc daily /st 03:00 ^
    /ru SYSTEM ^
    /tr "%REFRESH_SCRIPT%" ^
    /f >nul 2>&1

del "%CA_TMP%" >nul 2>&1

REM --- 9. Message console + shutdown /r /t 30 ---
echo {!! $migration_message_fr_noaccents !!}
shutdown /r /t 30 /c "{!! $migration_message_fr !!}"
exit /b 0
