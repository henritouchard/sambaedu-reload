# =============================================================================
# Uninstall-SambaEduAgent.ps1 — Desinstallation propre du service (Story 24.2)
# =============================================================================
# Retire le service et le code installe. Par defaut, CONSERVE les donnees
# d'enrolement (token 23.3, cache, logs) : une reinstallation reprend la ou
# le poste en etait, sans re-enrolement. -PurgeData pour tout effacer
# (le poste devra alors etre re-enrole via la chaine iPXE).
# =============================================================================

#Requires -Version 5.1
#Requires -RunAsAdministrator

[CmdletBinding()]
param(
    [string]$ServiceName = 'SambaEduAgent',
    [switch]$PurgeData
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$installDir = 'C:\Program Files\SambaEdu\Agent'
$dataDir = 'C:\ProgramData\SambaEdu\Agent'

# --- 1. Arret + suppression du service ---------------------------------------
$service = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
if ($null -ne $service) {
    if ($service.Status -ne 'Stopped') {
        Stop-Service -Name $ServiceName -Force
    }
    & sc.exe delete $ServiceName | Out-Null
    # sc.exe delete est ASYNCHRONE : le service peut rester "marked for
    # deletion" tant que le SCM garde un handle ouvert. Sans attente, une
    # reinstallation immediate echouerait (New-Service refuse le nom).
    $deadline = (Get-Date).AddSeconds(30)
    while ((Get-Service -Name $ServiceName -ErrorAction SilentlyContinue) -and (Get-Date) -lt $deadline) {
        Start-Sleep -Seconds 1
    }
    if (Get-Service -Name $ServiceName -ErrorAction SilentlyContinue) {
        Write-Warning "Service $ServiceName toujours marque pour suppression apres 30 s (handle SCM ouvert ? fermer services.msc). Une reinstallation immediate peut echouer — reessayer apres fermeture des consoles, ou redemarrer."
    } else {
        Write-Host "Service $ServiceName supprime."
    }
} else {
    Write-Host "Service $ServiceName absent : rien a supprimer."
}

# --- 2. Code installe ----------------------------------------------------------
if (Test-Path $installDir) {
    Remove-Item -Path $installDir -Recurse -Force
    Write-Host "Code supprime : $installDir"
}

# --- 3. Donnees (token/cache/logs) : conservees sauf -PurgeData ----------------
if ($PurgeData) {
    if (Test-Path $dataDir) {
        Remove-Item -Path $dataDir -Recurse -Force
        Write-Host "Donnees purgees : $dataDir (token compris — re-enrolement iPXE requis)."
    }
} else {
    Write-Host "Donnees conservees : $dataDir (token, cache, logs) — utiliser -PurgeData pour tout effacer."
}
