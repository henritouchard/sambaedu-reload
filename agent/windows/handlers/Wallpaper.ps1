# =============================================================================
# Wallpaper.ps1 — Handler `wallpaper`, côté SESSION user (Story 24.4)
# =============================================================================
# Exécuté par le compagnon de session (droits user — le wallpaper Windows est
# par-user : HKCU + SystemParametersInfo, piège n° 2). Le handler ne contient
# QUE le test/apply spécifique OS : la machine d'états §5 (strict/default/
# premier passage) vit dans le moteur (shared/ConvergenceEngine.ps1).
#
#   - test  : le fond courant de la session (HKCU:\Control Panel\Desktop,
#     valeur WallPaper) pointe-t-il vers le fichier attendu du cache d'assets
#     C:\ProgramData\SambaEdu\Agent\assets\<filename> ? Comparaison de chemins
#     CASE-INSENSITIVE + normalisation NFC (piège n° 5 — les filenames sont
#     hex ASCII mais la valeur registre peut venir d'ailleurs).
#   - apply : écrit la valeur + style `fill` (WallpaperStyle=10,
#     TileWallpaper=0) puis rafraîchit via SystemParametersInfo
#     (SPI_SETDESKWALLPAPER, UPDATEINIFILE|SENDCHANGE) — IDEMPOTENT : mêmes
#     écritures = même état, rejouable sans effet cumulatif.
#
# Cas du contrat :
#   - `asset: null` = règle EXPLICITE « pas de fond imposé » (contrat §8,
#     décision n° 8) : le handler NE TOUCHE PAS au fond et le test répond
#     conforme → `compliant`. Distinct du type absent (aucun statut émis —
#     géré par le moteur, jamais ici).
#   - asset pas encore téléchargé (course avec Sync-WallpaperAssets côté
#     SYSTEM) : apply LÈVE avec un detail explicite → `error` rapporté,
#     résorbé au passage suivant (le download est fait au cycle/logon).
#
# Le téléchargement des assets n'est JAMAIS fait ici : le compagnon n'a ni
# réseau ni token (frontière 24.3) — le cache est alimenté par le service
# SYSTEM (vérif SHA-256 incluse), lisible user (ACL Users:R à la création).
# =============================================================================

#Requires -Version 5.1
Set-StrictMode -Version Latest

# Cache d'assets partagé, alimenté par SYSTEM (décision n° 3).
$script:WallpaperAssetsDir = 'C:\ProgramData\SambaEdu\Agent\assets'
$script:WallpaperRegistryKey = 'HKCU:\Control Panel\Desktop'

# P/Invoke SystemParametersInfo — Add-Type une seule fois par process (un
# type .NET ne se recharge pas ; garde idempotente pour la boucle résidente).
if ($null -eq ('SambaEdu.Agent.WallpaperNative' -as [type])) {
    Add-Type -Namespace 'SambaEdu.Agent' -Name 'WallpaperNative' -MemberDefinition @'
[DllImport("user32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
public static extern int SystemParametersInfo(int uAction, int uParam, string lpvParam, int fuWinIni);
'@
}

# SPI_SETDESKWALLPAPER = 20 ; SPIF_UPDATEINIFILE (1) | SPIF_SENDCHANGE (2) = 3.
$script:SpiSetDeskWallpaper = 20
$script:SpifUpdateAndNotify = 3

<#
.SYNOPSIS
    Chemin local attendu pour l'item wallpaper, ou $null si la règle est
    « pas de fond imposé » (`asset: null`).
.NOTES
    Le filename est validé (format content-addressed) AVANT tout Join-Path :
    un payload serveur reste une entrée externe — jamais de traversal depuis
    le cache d'assets.
#>
function Get-WallpaperTargetPath {
    param([Parameter(Mandatory = $true)][object]$Item)

    $payload = if ($Item.PSObject.Properties['payload']) { $Item.payload } else { $null }
    if ($null -eq $payload -or -not $payload.PSObject.Properties['asset']) {
        throw 'payload wallpaper sans champ asset : enveloppe inattendue'
    }
    $asset = $payload.asset
    if ($null -eq $asset -or [string]::IsNullOrEmpty([string]$asset)) {
        return $null   # règle explicite « pas de fond imposé » (contrat §8)
    }

    $filename = [string]$asset
    if ($filename -notmatch '^[0-9a-f]{64}\.[a-z0-9]{2,5}$') {
        throw "filename d'asset wallpaper inattendu ('$filename') : format content-addressed requis"
    }

    return Join-Path $script:WallpaperAssetsDir $filename
}

<#
.SYNOPSIS
    `test` du handler : le fond courant correspond-il à la cible ?
.NOTES
    Sémantique exclusive (§3.1) : un seul item fait foi — le DERNIER si le
    serveur en envoyait plusieurs (le moteur a déjà loggé l'anomalie).
#>
function Test-WallpaperItems {
    param([Parameter(Mandatory = $true)][AllowEmptyCollection()][object[]]$Items)

    $target = Get-WallpaperTargetPath -Item (@($Items)[-1])
    if ($null -eq $target) {
        # asset null : on ne touche pas, on rapporte compliant (décision n° 8).
        return $true
    }

    $current = ''
    $desktop = Get-ItemProperty -Path $script:WallpaperRegistryKey -Name 'WallPaper' -ErrorAction SilentlyContinue
    if ($null -ne $desktop -and $null -ne $desktop.WallPaper) {
        $current = [string]$desktop.WallPaper
    }
    if ([string]::IsNullOrEmpty($current)) {
        return $false
    }

    # NFC (piège n° 5) + case-insensitive (sémantique chemins Windows).
    return [string]::Equals(
        $current.Normalize([Text.NormalizationForm]::FormC),
        $target.Normalize([Text.NormalizationForm]::FormC),
        [System.StringComparison]::OrdinalIgnoreCase)
}

<#
.SYNOPSIS
    `apply` du handler : registre (valeur + style fill) puis rafraîchissement
    SystemParametersInfo. Idempotent.
#>
function Set-WallpaperItems {
    param([Parameter(Mandatory = $true)][AllowEmptyCollection()][object[]]$Items)

    $target = Get-WallpaperTargetPath -Item (@($Items)[-1])
    if ($null -eq $target) {
        return   # asset null : no-op (jamais d'effacement du fond courant)
    }

    if (-not (Test-Path $target)) {
        # Course avec le download SYSTEM : error explicite, résorbée au
        # passage suivant (detail obligatoire sur error, contrat §6).
        throw "asset wallpaper absent du cache local ($target) : telechargement SYSTEM pas encore passe, nouvel essai au prochain passage"
    }

    # Style `fill` (decision n° 8) : WallpaperStyle=10, TileWallpaper=0.
    Set-ItemProperty -Path $script:WallpaperRegistryKey -Name 'WallpaperStyle' -Value '10'
    Set-ItemProperty -Path $script:WallpaperRegistryKey -Name 'TileWallpaper' -Value '0'
    Set-ItemProperty -Path $script:WallpaperRegistryKey -Name 'WallPaper' -Value $target

    $result = [SambaEdu.Agent.WallpaperNative]::SystemParametersInfo(
        $script:SpiSetDeskWallpaper, 0, $target, $script:SpifUpdateAndNotify)
    if ($result -eq 0) {
        $lastError = [System.Runtime.InteropServices.Marshal]::GetLastWin32Error()
        throw "SystemParametersInfo(SPI_SETDESKWALLPAPER) en echec (Win32 $lastError) : fond non rafraichi"
    }
}

<#
.SYNOPSIS
    Enregistrement pour le moteur : @{ Test; Apply } (contrat handler).
#>
function New-WallpaperHandler {
    return @{
        Test  = { param([object[]]$Items) Test-WallpaperItems -Items $Items }
        Apply = { param([object[]]$Items) Set-WallpaperItems -Items $Items }
    }
}
