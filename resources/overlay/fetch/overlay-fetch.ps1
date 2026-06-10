<#
.SYNOPSIS
    Adaptateur FETCH (Windows) du POC overlay SambaEdu.

.DESCRIPTION
    Poll authentifié de GET /api/v1/workstation-config/overlay puis écriture
    atomique du résultat dans overlay.json local. C'est le SEUL composant qui
    porte le JWT workstation ; l'adaptateur render (Rainmeter) ne lit que le
    fichier local.

    Single-shot : la cadence est pilotée par une tâche planifiée (toutes les
    ttl_seconds, défaut 60 s). Cf. resources/overlay/README.md.

.NOTES
    POC — non testé sur poste réel. Le store du token (-TokenPath) est un TODO :
    à brancher sur le store réel du token workstation (enroll/refresh 16.10).
#>
[CmdletBinding()]
param(
    # TODO: résoudre l'hôte SE4FS réel (variable d'env / config poste).
    [string]$BaseUrl  = "https://$($env:SE4FS)/api/v1/workstation-config/overlay",
    # TODO: brancher sur le vrai store du JWT workstation.
    [string]$TokenPath = "$env:ProgramData\SambaEdu\workstation.jwt",
    [string]$OutPath   = "$env:ProgramData\SambaEdu\overlay.json",
    [string]$User      = $env:USERNAME,
    [string]$Os        = "windows"
)

$ErrorActionPreference = "Stop"

try {
    if (-not (Test-Path $TokenPath)) {
        throw "Token workstation introuvable : $TokenPath"
    }
    $token = (Get-Content -Raw -Path $TokenPath).Trim()

    $uri = "{0}?os={1}&user={2}" -f $BaseUrl, $Os, [uri]::EscapeDataString($User)

    $resp = Invoke-WebRequest -Uri $uri -Method GET -Headers @{
        Authorization = "Bearer $token"
        Accept        = "application/json"
    } -UseBasicParsing -TimeoutSec 10

    # Validation minimale : c'est bien notre schéma.
    $json = $resp.Content
    $parsed = $json | ConvertFrom-Json
    if (-not $parsed.schema -or $parsed.schema -notlike "se5.wallpaper-overlay/*") {
        throw "Réponse inattendue (schema absent/incompatible)."
    }

    $outDir = Split-Path -Parent $OutPath
    if (-not (Test-Path $outDir)) {
        New-Item -ItemType Directory -Path $outDir -Force | Out-Null
    }

    # Écriture atomique : fichier temporaire puis remplacement.
    $tmp = "$OutPath.tmp"
    [System.IO.File]::WriteAllText($tmp, $json, [System.Text.UTF8Encoding]::new($false))
    # File.Replace = remplacement in-place atomique sur NTFS (review finding L) ;
    # Move pour le tout premier passage (destination absente).
    if (Test-Path $OutPath) {
        [System.IO.File]::Replace($tmp, $OutPath, $null)
    } else {
        [System.IO.File]::Move($tmp, $OutPath)
    }

    exit 0
}
catch {
    # En cas d'échec : NE PAS écraser le dernier overlay.json valide.
    Write-Error "[overlay-fetch] $($_.Exception.Message)"
    exit 1
}
