# =============================================================================
# Overlay.ps1 — Handler `overlay`, côté SESSION user (Story 24.4)
# =============================================================================
# L'agent DEVIENT le fetch du POC overlay (AC2) : il compose et écrit
# `%LOCALAPPDATA%\SambaEdu\Agent\overlay.json` — per-user par construction
# (multi-session correct), seule arborescence où le compagnon écrit (NFR5).
# Le render (Rainmeter/Conky) est INCHANGÉ : seule la variable de chemin de
# la skin Windows pointe sur le nouveau fichier (config d'adaptateur).
#
# Composition (décision n° 4) — iso contrat render POC
# (resources/overlay/README.md : identity.fullname, machine.name,
# machine.room, alerts[] {severity, title, text}) :
#   - item `kind: "identity"` (enrichissement serveur OverlayStateProvider) →
#     identity.fullname/login + machine.room — le compagnon ne connaît
#     localement ni le fullname ni la salle, et le critère Keycloak interdit
#     tout appel AD côté poste ;
#   - machine.name = $env:COMPUTERNAME LOCAL (jamais demandé au serveur) ;
#   - les autres items (signaux postés) → alerts[], ordre serveur (aggregate
#     = union).
#
# Sérialisation : Format-OverlayJson, sérialiseur MINIMAL à structure fixe —
# pas ConvertTo-Json (PS 5.1 met DEUX espaces après les deux-points et
# échappe l'Unicode en \uXXXX : les deux cassent la regex WebParser du render,
# piège n° 15). Ordre de clés STABLE (structure littérale), `": "` simple,
# UTF-8 sans BOM. Texte aplati iso `OverlayService::sanitizeText` (retours
# ligne/espaces multiples → un espace, clamp) — un `"` dans un titre reste le
# caveat documenté du POC (regex tronquée, fichier JSON valide : échappé \").
#
# Pas de champ volatil (`generated_at`…) dans le document : le `test` est une
# comparaison de CONTENU (après NFC) — un champ horodaté ferait dériver
# chaque passe.
#
# Rainmeter ABSENT du poste (amendement Henri 2026-06-12) : comportement
# gracieux — le handler compose et écrit quand même overlay.json (la
# ressource config EST convergée → statut machine d'états normale, JAMAIS
# `error` du seul fait de l'absence) + log info « rainmeter absent, overlay
# non rendu ». Installer une application n'est pas du desired-state config.
# =============================================================================

#Requires -Version 5.1
Set-StrictMode -Version Latest

$script:OverlayDir      = Join-Path $env:LOCALAPPDATA 'SambaEdu\Agent'
$script:OverlayJsonPath = Join-Path $script:OverlayDir 'overlay.json'
# Schéma de la facade locale — celui que le render POC valide/connaît.
$script:OverlaySchema   = 'se5.wallpaper-overlay/v1'
# Détection de présence du render (jamais bloquante, jamais une erreur).
$script:RainmeterExePaths = @(
    (Join-Path $env:ProgramFiles 'Rainmeter\Rainmeter.exe'),
    (Join-Path $env:LOCALAPPDATA 'Rainmeter\Rainmeter.exe')
)
# Logger injecté par le compagnon (Write-CompanionLog) — $null = silencieux.
$script:OverlayLogAction = $null

# Bornes iso OverlayService::sanitizeText (title 255, text 2000).
$script:OverlayTitleMaxLength = 255
$script:OverlayTextMaxLength  = 2000

<#
.SYNOPSIS
    Aplatissement de texte iso `OverlayService::sanitizeText` : retours
    ligne / espaces multiples → un espace, trim, clamp — protège le parsing
    regex mono-ligne du render (piège n° 15).
#>
function Format-OverlayText {
    param(
        [AllowNull()][AllowEmptyString()][string]$Value,
        [Parameter(Mandatory = $true)][int]$MaxLength
    )

    if ([string]::IsNullOrEmpty($Value)) { return '' }
    $flat = ([regex]::Replace($Value, '\s+', ' ')).Trim()
    if ($flat.Length -gt $MaxLength) { $flat = $flat.Substring(0, $MaxLength) }

    return $flat
}

<#
.SYNOPSIS
    Échappement JSON d'une valeur chaîne — backslash, guillemet, contrôles.
    L'Unicode reste BRUT (UTF-8) : lisible par le render, pas de \uXXXX.
#>
function ConvertTo-OverlayJsonString {
    param([AllowNull()][AllowEmptyString()][string]$Value)

    if ([string]::IsNullOrEmpty($Value)) { return '' }
    $escaped = $Value.Replace('\', '\\').Replace('"', '\"')
    # Contrôles résiduels (le sanitize a déjà aplati \r\n\t en espaces).
    $escaped = [regex]::Replace($escaped, '[\x00-\x1f]', ' ')

    return $escaped
}

<#
.SYNOPSIS
    Compose le document overlay.json cible depuis TOUS les items overlay de
    la passe (ordre serveur). Retourne la chaîne JSON exacte à écrire.
.NOTES
    Sérialiseur à structure FIXE : ordre de clés stable, `": "` simple —
    aligné sur la regex WebParser de la skin
    (`"fullname": "…" … "name": "…" … "room": "…" … "alerts":`).
    Champs absents (machine-only sans identity) = chaînes vides, jamais
    omis : la regex exige la présence des clés.
#>
function Build-OverlayDocument {
    param([AllowEmptyCollection()][object[]]$Items = @())

    $identity = $null
    $alerts = @()
    foreach ($item in @($Items)) {
        $payload = if ($item.PSObject.Properties['payload']) { $item.payload } else { $null }
        if ($null -eq $payload) { continue }
        $kind = if ($payload.PSObject.Properties['kind']) { [string]$payload.kind } else { '' }

        if ($kind -eq 'identity') {
            # Un seul bloc identité (le serveur n'en émet qu'un — défense :
            # le premier gagne, ordre serveur).
            if ($null -eq $identity) { $identity = $payload }
            continue
        }

        $severity = if ($payload.PSObject.Properties['severity']) { [string]$payload.severity } else { 'info' }
        $title = if ($payload.PSObject.Properties['title']) { [string]$payload.title } else { '' }
        $text = if ($payload.PSObject.Properties['text']) { [string]$payload.text } else { '' }
        $alerts += [pscustomobject]@{
            Severity = Format-OverlayText -Value $severity -MaxLength 16
            Title    = Format-OverlayText -Value $title -MaxLength $script:OverlayTitleMaxLength
            Text     = Format-OverlayText -Value $text -MaxLength $script:OverlayTextMaxLength
        }
    }

    $fullname = ''
    $login = ''
    $room = ''
    if ($null -ne $identity) {
        if ($identity.PSObject.Properties['fullname'] -and $null -ne $identity.fullname) {
            $fullname = Format-OverlayText -Value ([string]$identity.fullname) -MaxLength 255
        }
        if ($identity.PSObject.Properties['login'] -and $null -ne $identity.login) {
            $login = Format-OverlayText -Value ([string]$identity.login) -MaxLength 255
        }
        if ($identity.PSObject.Properties['room'] -and $null -ne $identity.room) {
            $room = Format-OverlayText -Value ([string]$identity.room) -MaxLength 255
        }
    }

    $lines = [System.Collections.ArrayList]::new()
    [void]$lines.Add('{')
    [void]$lines.Add('    "schema": "{0}",' -f $script:OverlaySchema)
    [void]$lines.Add('    "identity": {')
    [void]$lines.Add('        "fullname": "{0}",' -f (ConvertTo-OverlayJsonString $fullname))
    [void]$lines.Add('        "login": "{0}"' -f (ConvertTo-OverlayJsonString $login))
    [void]$lines.Add('    },')
    [void]$lines.Add('    "machine": {')
    [void]$lines.Add('        "name": "{0}",' -f (ConvertTo-OverlayJsonString ([string]$env:COMPUTERNAME)))
    [void]$lines.Add('        "room": "{0}"' -f (ConvertTo-OverlayJsonString $room))
    [void]$lines.Add('    },')
    if (@($alerts).Count -eq 0) {
        [void]$lines.Add('    "alerts": []')
    } else {
        [void]$lines.Add('    "alerts": [')
        for ($i = 0; $i -lt @($alerts).Count; $i++) {
            $alert = @($alerts)[$i]
            $suffix = if ($i -lt (@($alerts).Count - 1)) { ',' } else { '' }
            [void]$lines.Add('        {')
            [void]$lines.Add('            "severity": "{0}",' -f (ConvertTo-OverlayJsonString $alert.Severity))
            [void]$lines.Add('            "title": "{0}",' -f (ConvertTo-OverlayJsonString $alert.Title))
            [void]$lines.Add('            "text": "{0}"' -f (ConvertTo-OverlayJsonString $alert.Text))
            [void]$lines.Add('        }' + $suffix)
        }
        [void]$lines.Add('    ]')
    }
    [void]$lines.Add('}')

    return ($lines -join "`n")
}

<#
.SYNOPSIS
    `test` du handler : le fichier overlay.json existant est-il identique au
    document cible ? Comparaison de CONTENU après normalisation NFC
    (piège n° 5 — le document porte fullname/room accentués).
#>
function Test-OverlayItems {
    param([Parameter(Mandatory = $true)][AllowEmptyCollection()][object[]]$Items)

    $target = Build-OverlayDocument -Items $Items
    if (-not (Test-Path $script:OverlayJsonPath)) {
        return $false
    }

    $current = [System.IO.File]::ReadAllText($script:OverlayJsonPath, [System.Text.Encoding]::UTF8)

    return [string]::Equals(
        $current.Normalize([Text.NormalizationForm]::FormC),
        $target.Normalize([Text.NormalizationForm]::FormC),
        [System.StringComparison]::Ordinal)
}

<#
.SYNOPSIS
    `apply` du handler : écriture ATOMIQUE (tmp suffixé $PID + Move — leçon
    TOCTOU 24.3) du document composé, UTF-8 sans BOM, sous %LOCALAPPDATA%.
.NOTES
    Mode `strict` (constante provider) : toute divergence est réécrite — le
    moteur rapporte `drift`. Rainmeter absent = log info, JAMAIS une erreur
    (AC2 amendé) : la ressource config EST convergée.
#>
function Set-OverlayItems {
    param([Parameter(Mandatory = $true)][AllowEmptyCollection()][object[]]$Items)

    $target = Build-OverlayDocument -Items $Items

    if (-not (Test-Path $script:OverlayDir)) {
        New-Item -ItemType Directory -Path $script:OverlayDir -Force | Out-Null
    }

    # Profil user : aucune ACL à poser (jamais de ré-ACL des tmp). UTF-8 sans
    # BOM iso fetch POC ([System.Text.UTF8Encoding]::new($false)).
    $tmp = "$script:OverlayJsonPath.$PID.tmp"
    [System.IO.File]::WriteAllText($tmp, $target, [System.Text.UTF8Encoding]::new($false))
    Move-Item -Path $tmp -Destination $script:OverlayJsonPath -Force

    if (-not (Test-RainmeterPresent)) {
        # Comportement gracieux (amendement Henri 2026-06-12) : la livraison
        # de Rainmeter = workflow d'install des postes, PAS du desired-state.
        if ($null -ne $script:OverlayLogAction) {
            & $script:OverlayLogAction 'INFO' 'rainmeter absent, overlay non rendu (overlay.json converge quand meme — install render = workflow postes, hors desired-state).'
        }
    }
}

<#
.SYNOPSIS
    Rainmeter est-il installé ? (détection par chemins standards — purement
    informative, n'influe JAMAIS sur le statut de convergence.)
#>
function Test-RainmeterPresent {
    foreach ($path in $script:RainmeterExePaths) {
        if (Test-Path $path) { return $true }
    }

    return $false
}

<#
.SYNOPSIS
    Enregistrement pour le moteur : @{ Test; Apply }. $LogAction =
    scriptblock(level, message) du compagnon (pour le log « rainmeter absent »).
#>
function New-OverlayHandler {
    param([scriptblock]$LogAction = $null)

    $script:OverlayLogAction = $LogAction

    return @{
        Test  = { param([object[]]$Items) Test-OverlayItems -Items $Items }
        Apply = { param([object[]]$Items) Set-OverlayItems -Items $Items }
    }
}
