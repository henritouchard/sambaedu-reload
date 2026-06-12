# =============================================================================
# Build-Agent.ps1 — Build + signature Authenticode de l'agent (Story 24.2, AC5)
# =============================================================================
# Produit dans agent/build/dist/ un artefact pret a deployer sur le poste lab :
#   - SambaEduAgent.ps1 + ContractV1.ps1 + SessionStateFetch.ps1 +
#     SessionCompanion.ps1 (compagnon de session 24.3) + Install/Uninstall
#     (bundle a plat, les scripts dot-sourcent leurs dependances depuis leur
#     propre dossier) ;
#   - chaque .ps1 SIGNE Authenticode avec un certificat code-signing emis par
#     la CA interne SambaEdu-RootCA (NFR6 : un artefact non signe = SmartScreen
#     bloque = demo impossible). La CA racine est deja deployee sur les postes
#     par la chaine iPXE 23.3 ;
#   - SambaEduAgent-<version>.zip de l'ensemble signe.
#
# Usage (sur un poste Windows de build disposant du certificat) :
#   # certificat dans le magasin utilisateur/machine :
#   .\Build-Agent.ps1 -CertificateThumbprint 'ABCD1234...'
#   # ou certificat fichier PFX :
#   .\Build-Agent.ps1 -PfxPath .\sambaedu-codesign.pfx
#
# Obtention du certificat de signature : cf. agent/README.md §Signature.
# =============================================================================

#Requires -Version 5.1

[CmdletBinding(DefaultParameterSetName = 'Store')]
param(
    [Parameter(Mandatory = $true, ParameterSetName = 'Store')]
    [string]$CertificateThumbprint,

    [Parameter(Mandatory = $true, ParameterSetName = 'Pfx')]
    [string]$PfxPath,

    [Parameter(ParameterSetName = 'Pfx')]
    [securestring]$PfxPassword,

    # Horodatage de la signature : la signature reste valide apres expiration
    # du certificat. Serveur public par defaut, vide pour un lab hors-ligne.
    [string]$TimestampServer = 'http://timestamp.digicert.com'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repoAgentDir = Split-Path $PSScriptRoot -Parent
$distDir = Join-Path $PSScriptRoot 'dist'

# --- 1. Resolution du certificat de signature --------------------------------
if ($PSCmdlet.ParameterSetName -eq 'Pfx') {
    $certificate = Get-PfxCertificate -FilePath $PfxPath
} else {
    $certificate = Get-ChildItem -Path Cert:\ -Recurse -CodeSigningCert |
        Where-Object { $_.Thumbprint -eq $CertificateThumbprint } |
        Select-Object -First 1
    if ($null -eq $certificate) {
        throw "Certificat code-signing introuvable (thumbprint $CertificateThumbprint). Cf. agent/README.md §Signature."
    }
}

# Garde NFR6 : la chaine doit remonter a la CA interne SambaEdu.
$chain = New-Object System.Security.Cryptography.X509Certificates.X509Chain
$null = $chain.Build($certificate)
$rootSubject = $chain.ChainElements[$chain.ChainElements.Count - 1].Certificate.Subject
if ($rootSubject -notmatch 'SambaEdu') {
    Write-Warning "La racine de la chaine ($rootSubject) ne semble pas etre SambaEdu-RootCA : la signature sera refusee sur les postes du parc."
}

# --- 2. Bundle a plat dans dist/ ----------------------------------------------
if (Test-Path $distDir) { Remove-Item -Path $distDir -Recurse -Force }
New-Item -ItemType Directory -Path $distDir | Out-Null

$files = @(
    (Join-Path $repoAgentDir 'windows\SambaEduAgent.ps1'),
    # Compagnon de session 24.3 : scripts EXECUTES PAR LE USER (SessionCompanion)
    # ou par SYSTEM at-logon (SessionStateFetch) — signes comme le reste (AC6).
    (Join-Path $repoAgentDir 'windows\SessionStateFetch.ps1'),
    (Join-Path $repoAgentDir 'windows\SessionCompanion.ps1'),
    # Story 24.4 : moteur de convergence (shared, cross-OS) + handlers Windows
    # — dot-sources par le compagnon, signes comme le reste (bundle a plat).
    (Join-Path $repoAgentDir 'shared\ConvergenceEngine.ps1'),
    (Join-Path $repoAgentDir 'windows\handlers\Wallpaper.ps1'),
    (Join-Path $repoAgentDir 'windows\handlers\Overlay.ps1'),
    (Join-Path $repoAgentDir 'windows\Install-SambaEduAgent.ps1'),
    (Join-Path $repoAgentDir 'windows\Uninstall-SambaEduAgent.ps1'),
    (Join-Path $repoAgentDir 'shared\ContractV1.ps1')
)
foreach ($file in $files) {
    Copy-Item -Path $file -Destination $distDir -Force
}

# --- 3. Signature Authenticode de chaque script -------------------------------
$signArgs = @{ Certificate = $certificate; HashAlgorithm = 'SHA256' }
if (-not [string]::IsNullOrEmpty($TimestampServer)) {
    $signArgs['TimestampServer'] = $TimestampServer
}

foreach ($script in Get-ChildItem -Path $distDir -Filter '*.ps1') {
    $signature = Set-AuthenticodeSignature -FilePath $script.FullName @signArgs
    if ($signature.Status -ne 'Valid') {
        throw "Signature en echec sur $($script.Name) : $($signature.Status) — $($signature.StatusMessage)"
    }
    Write-Host "Signe : $($script.Name) ($($signature.SignerCertificate.Subject))"
}

# --- 4. Archive versionnee -----------------------------------------------------
# Version lue dans ContractV1.ps1 ($script:AgentVersion) — source unique.
$versionLine = Select-String -Path (Join-Path $distDir 'ContractV1.ps1') -Pattern "AgentVersion\s*=\s*'([^']+)'"
$version = $versionLine.Matches[0].Groups[1].Value
$zipPath = Join-Path $PSScriptRoot "SambaEduAgent-$version.zip"
if (Test-Path $zipPath) { Remove-Item $zipPath -Force }
Compress-Archive -Path (Join-Path $distDir '*') -DestinationPath $zipPath

Write-Host ''
Write-Host "Build OK : $zipPath"
Write-Host "Deploiement lab (AC8) : copier dist\ sur le poste puis .\Install-SambaEduAgent.ps1 -ServerUrl 'http://<serveur-se5>'"
