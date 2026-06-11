# =============================================================================
# SambaEduAgent.ps1 — Agent squelette Windows SE5 (Story 24.2, Epic 24)
# =============================================================================
# Service SYSTEM (portee machine) : boucle de check-in qui ferme le circuit
# UI -> etat cible -> agent -> rapport -> UI.
#
# Cycle (1 iteration) :
#   1. lire le token        C:\ProgramData\SambaEdu\Agent\token   (contrat 23.3)
#   2. GET  /api/v1/agent/state  avec If-None-Match (ETag du cache)
#   3. 200 -> persister cache\state.json + cache\etag.txt ; 304 -> cache valide
#   4. construire le rapport minimal (items: [] — aucun handler en 24.2)
#   5. POST /api/v1/agent/report
#   6. attendre (intervalle 3600 s + jitter ±10 %)
#
# Invariants geres ici :
#   - rotation token D5 (X-Agent-New-Token sur TOUTE reponse, ecrite sur disque,
#     ancien token garde en memoire pour la fenetre de grace) ;
#   - 401 irrecuperable -> ARRET + log local, JAMAIS de re-enrolement auto ;
#   - 403 AGENT_QUARANTINED -> check-ins legers seulement (GET /state continue,
#     plus aucun POST /report ni traitement d'etat) ;
#   - serveur injoignable / 5xx / 429 -> backoff exponentiel 30 s -> 3600 s ;
#   - hostname COURT ($env:COMPUTERNAME) dans le rapport (defer review 24.1 #8) ;
#   - ETag stocke VERBATIM (guillemets RFC 7232 inclus), renvoye tel quel ;
#   - NFR1 : ce service n'interagit JAMAIS avec le logon ;
#   - NFR7 : aucune dependance AD/Kerberos/LDAP — l'auth EST le bearer token.
# =============================================================================

#Requires -Version 5.1
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# ContractV1.ps1 : a cote du script (artefact bundle par Build-Agent.ps1) ou
# dans ..\shared (layout du repo pour le dev).
$contractModule = Join-Path $PSScriptRoot 'ContractV1.ps1'
if (-not (Test-Path $contractModule)) {
    $contractModule = Join-Path (Split-Path $PSScriptRoot -Parent) 'shared\ContractV1.ps1'
}
. $contractModule

# --- Chemins (contrat Epic 24 — docs/agent/enrollment.md §3 + agent/README.md) ---

$script:AgentRoot        = 'C:\ProgramData\SambaEdu\Agent'
$script:TokenPath        = Join-Path $script:AgentRoot 'token'           # FIGE 23.3 — ne jamais changer
$script:ConfigPath       = Join-Path $script:AgentRoot 'config.json'
$script:CacheDir         = Join-Path $script:AgentRoot 'cache'
$script:StateCachePath   = Join-Path $script:CacheDir 'state.json'
$script:EtagCachePath    = Join-Path $script:CacheDir 'etag.txt'
$script:AppliedStatePath = Join-Path $script:AgentRoot 'applied-state.json'  # infra 24.4 (mode default)
$script:LogDir           = Join-Path $script:AgentRoot 'logs'
$script:LogPath          = Join-Path $script:LogDir 'agent.log'
$script:LogRetentionDays = 7

# --- Etat du process (jamais persiste) ---

$script:PreviousToken = $null   # ancien token garde pendant la fenetre de grace D5
$script:Quarantined   = $false  # 403 AGENT_QUARANTINED -> check-ins legers

# =============================================================================
# Log local structure — [ISO 8601] [LEVEL] message
# Rotation quotidienne (agent-YYYY-MM-DD.log), retention 7 jours.
# =============================================================================

function Write-AgentLog {
    param(
        [ValidateSet('DEBUG', 'INFO', 'WARNING', 'ERROR')][string]$Level = 'INFO',
        [Parameter(Mandatory = $true)][string]$Message
    )

    if (-not (Test-Path $script:LogDir)) {
        New-Item -ItemType Directory -Path $script:LogDir -Force | Out-Null
        Set-AgentAcl -Path $script:LogDir
    }

    # Rotation quotidienne : agent.log d'un jour precedent -> agent-YYYY-MM-DD.log
    if (Test-Path $script:LogPath) {
        $lastWrite = (Get-Item $script:LogPath).LastWriteTime.Date
        if ($lastWrite -lt (Get-Date).Date) {
            $archive = Join-Path $script:LogDir ('agent-{0:yyyy-MM-dd}.log' -f $lastWrite)
            Move-Item -Path $script:LogPath -Destination $archive -Force
        }
        # Purge > retention
        Get-ChildItem -Path $script:LogDir -Filter 'agent-*.log' |
            Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$script:LogRetentionDays) } |
            Remove-Item -Force -ErrorAction SilentlyContinue
    }

    $line = '[{0}] [{1}] {2}' -f (Get-Date -Format 'yyyy-MM-ddTHH:mm:ssK'), $Level, $Message
    Add-Content -Path $script:LogPath -Value $line -Encoding UTF8
}

# =============================================================================
# ACL — SYSTEM + Administrators uniquement (memes ACL que le token, 23.3)
# =============================================================================

function Set-AgentAcl {
    param([Parameter(Mandatory = $true)][string]$Path)

    # SIDs (pas de noms localises) : *S-1-5-18 = SYSTEM, *S-1-5-32-544 = Administrators.
    # /inheritance:r retire l'heritage : un utilisateur standard ne lit NI n'ecrit.
    & icacls.exe $Path /inheritance:r /grant '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' | Out-Null
}

# =============================================================================
# Token (chemin = CONTRAT 23.3, lu sur disque A CHAQUE cycle — la rotation
# peut changer le fichier entre deux cycles)
# =============================================================================

function Read-AgentToken {
    if (-not (Test-Path $script:TokenPath)) {
        throw "Token introuvable : $script:TokenPath (poste non enrole ?)"
    }
    $token = (Get-Content -Path $script:TokenPath -Raw).Trim()
    if ($token -notmatch '^[0-9a-f]{64}$') {
        throw "Token malforme dans $script:TokenPath (attendu : 64 hex sans newline)"
    }

    return $token
}

function Save-AgentToken {
    param([Parameter(Mandatory = $true)][string]$Token)

    # Ecriture atomique : fichier temporaire puis Move-Item (rename NTFS).
    # -NoNewline : le contrat 23.3 impose 64 hex SANS newline.
    $tmp = "$script:TokenPath.tmp"
    Set-Content -Path $tmp -Value $Token -NoNewline -Encoding Ascii
    Set-AgentAcl -Path $tmp
    Move-Item -Path $tmp -Destination $script:TokenPath -Force
}

# =============================================================================
# Cache local (etat + ETag) sous ACL SYSTEM + Administrators
# =============================================================================

function Initialize-AgentCache {
    if (-not (Test-Path $script:CacheDir)) {
        New-Item -ItemType Directory -Path $script:CacheDir -Force | Out-Null
        Set-AgentAcl -Path $script:CacheDir
    }
    # applied-state.json : cree VIDE des 24.2 — infrastructure du mode `default`
    # (persistance du dernier-applique par item, consommee par les handlers 24.4).
    if (-not (Test-Path $script:AppliedStatePath)) {
        Set-Content -Path $script:AppliedStatePath -Value '{}' -NoNewline -Encoding UTF8
        Set-AgentAcl -Path $script:AppliedStatePath
    }
}

function Read-CachedEtag {
    if (Test-Path $script:EtagCachePath) {
        # VERBATIM : guillemets RFC 7232 inclus — tout trim/dequotage brise le 304.
        return (Get-Content -Path $script:EtagCachePath -Raw)
    }

    return $null
}

function Save-StateCache {
    param(
        [Parameter(Mandatory = $true)][string]$StateJson,
        [Parameter(Mandatory = $true)][string]$Etag
    )

    foreach ($pair in @(
            @{ Path = $script:StateCachePath; Value = $StateJson },
            @{ Path = $script:EtagCachePath; Value = $Etag }
        )) {
        $tmp = "$($pair.Path).tmp"
        Set-Content -Path $tmp -Value $pair.Value -NoNewline -Encoding UTF8
        Set-AgentAcl -Path $tmp
        Move-Item -Path $tmp -Destination $pair.Path -Force
    }
}

# =============================================================================
# Configuration locale — C:\ProgramData\SambaEdu\Agent\config.json
#   { "server_url": "http://se5.example", "interval_seconds": 3600 }
# Posee par Install-SambaEduAgent.ps1. interval_seconds optionnel (3600 par
# defaut = ttl_seconds conseille par le serveur, D7).
# =============================================================================

function Read-AgentConfig {
    if (-not (Test-Path $script:ConfigPath)) {
        throw "Configuration introuvable : $script:ConfigPath (relancer Install-SambaEduAgent.ps1)"
    }
    $config = Get-Content -Path $script:ConfigPath -Raw | ConvertFrom-Json
    # server_url obligatoire : sous StrictMode, un acces direct a une propriete
    # absente (config corrompue/tronquee) crasherait sans message exploitable.
    if (-not $config.PSObject.Properties['server_url'] -or
        [string]::IsNullOrWhiteSpace([string]$config.server_url)) {
        throw "Configuration invalide : champ 'server_url' absent ou vide dans $script:ConfigPath (relancer Install-SambaEduAgent.ps1)"
    }
    $interval = 3600
    if ($config.PSObject.Properties['interval_seconds'] -and [int]$config.interval_seconds -gt 0) {
        $interval = [int]$config.interval_seconds
    }

    return [pscustomobject]@{
        ServerUrl       = ([string]$config.server_url).TrimEnd('/')
        IntervalSeconds = $interval
    }
}

# =============================================================================
# Couche HTTP — System.Net.HttpWebRequest (PS 5.1 : Invoke-WebRequest leve une
# exception sur 304/4xx/5xx ; HttpWebRequest donne le controle complet).
# Retourne toujours @{ StatusCode; Body; Headers } ; leve seulement sur erreur
# RESEAU (timeout, DNS, connexion refusee) -> backoff par l'appelant.
# =============================================================================

function Invoke-AgentHttp {
    param(
        [Parameter(Mandatory = $true)][ValidateSet('GET', 'POST')][string]$Method,
        [Parameter(Mandatory = $true)][string]$Url,
        [Parameter(Mandatory = $true)][string]$Token,
        [hashtable]$Headers = @{},
        [string]$Body = $null
    )

    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

    $request = [System.Net.HttpWebRequest]::Create($Url)
    $request.Method = $Method
    $request.Timeout = 30000
    $request.Accept = 'application/json'
    $request.Headers.Add('Authorization', "Bearer $Token")
    # Identite presentee (middleware 23.2) : hostname COURT uniquement.
    # X-Agent-Mac volontairement NON envoye en 24.2 : le middleware QUARANTAINE
    # sur mismatch MAC, et un poste multi-NIC (Wi-Fi, dock USB) presenterait
    # facilement une autre MAC que celle d'enrolement (PXE) -> faux positif.
    # A cabler quand une selection fiable de la NIC sera decidee (decision user).
    $request.Headers.Add('X-Agent-Hostname', $env:COMPUTERNAME)
    foreach ($key in $Headers.Keys) {
        $request.Headers.Add($key, $Headers[$key])
    }

    if ($null -ne $Body) {
        $request.ContentType = 'application/json'
        $bytes = [System.Text.Encoding]::UTF8.GetBytes($Body)
        $stream = $request.GetRequestStream()
        try { $stream.Write($bytes, 0, $bytes.Length) } finally { $stream.Close() }
    }

    $response = $null
    try {
        $response = $request.GetResponse()
    } catch [System.Net.WebException] {
        if ($null -eq $_.Exception.Response) {
            # Vraie erreur reseau (DNS, timeout, connexion refusee) -> backoff.
            throw
        }
        # 304/4xx/5xx : reponse HTTP normale du point de vue de l'agent.
        $response = $_.Exception.Response
    }

    try {
        $statusCode = [int]$response.StatusCode
        $headers = @{}
        foreach ($name in $response.Headers.AllKeys) {
            $headers[$name] = $response.Headers[$name]
        }
        $body = ''
        $responseStream = $response.GetResponseStream()
        if ($null -ne $responseStream) {
            $reader = New-Object System.IO.StreamReader($responseStream, [System.Text.Encoding]::UTF8)
            try { $body = $reader.ReadToEnd() } finally { $reader.Close() }
        }
    } finally {
        $response.Close()
    }

    return @{ StatusCode = $statusCode; Body = $body; Headers = $headers }
}

<#
.SYNOPSIS
    Rotation D5 : si X-Agent-New-Token est present, ecrit le nouveau token sur
    disque et garde l'ancien en memoire (fenetre de grace cote serveur).
#>
function Update-TokenIfRotated {
    param(
        [Parameter(Mandatory = $true)][hashtable]$Response,
        [Parameter(Mandatory = $true)][string]$CurrentToken
    )

    $newToken = $Response.Headers['X-Agent-New-Token']
    if ([string]::IsNullOrEmpty($newToken)) {
        return $CurrentToken
    }

    $script:PreviousToken = $CurrentToken
    Save-AgentToken -Token $newToken
    Write-AgentLog -Level INFO -Message 'Rotation token recue (X-Agent-New-Token) : nouveau token ecrit sur disque, ancien garde pour la fenetre de grace.'

    return $newToken
}

<#
.SYNOPSIS
    Appel HTTP avec gestion du 401 de rotation (AC3) : si 401 avec le nouveau
    token juste ecrit, on reessaie UNE fois avec l'ancien (fenetre de grace).
    401 avec l'ancien aussi -> irrecuperable, l'appelant arrete le service.
#>
function Invoke-AgentHttpWithGrace {
    param(
        [Parameter(Mandatory = $true)][ValidateSet('GET', 'POST')][string]$Method,
        [Parameter(Mandatory = $true)][string]$Url,
        [Parameter(Mandatory = $true)][string]$Token,
        [hashtable]$Headers = @{},
        [string]$Body = $null
    )

    $response = Invoke-AgentHttp -Method $Method -Url $Url -Token $Token -Headers $Headers -Body $Body

    $hasPrevious = -not [string]::IsNullOrEmpty($script:PreviousToken)
    if ($response.StatusCode -eq 401 -and $hasPrevious -and $Token -ne $script:PreviousToken) {
        Write-AgentLog -Level WARNING -Message '401 avec le token courant juste apres une rotation : nouvel essai avec l''ancien token (fenetre de grace D5).'
        $response = Invoke-AgentHttp -Method $Method -Url $Url -Token $script:PreviousToken -Headers $Headers -Body $Body
        if ($response.StatusCode -ne 401) {
            # L'ancien marche encore. Affectation VIVANTE pour la suite du MEME
            # cycle (Update-TokenIfRotated + POST /report lisent $script:Token) ;
            # entre deux cycles le token est de toute facon relu sur disque.
            # Le middleware re-emet TOUJOURS un X-Agent-New-Token sur une auth
            # via previous (rotateFor) : Update-TokenIfRotated ecrira le
            # remplacant sur disque juste apres.
            $script:Token = $script:PreviousToken
        }
    } elseif ($response.StatusCode -ne 401 -and $hasPrevious -and $Token -ne $script:PreviousToken) {
        # Le token courant (post-rotation) vient d'etre accepte : le serveur a
        # ferme la fenetre de grace (confirmRotation au premier usage). Purge
        # locale — sinon TOUT 401 futur, meme legitime (revocation), declenche
        # un reessai parasite avec un token perime.
        $script:PreviousToken = $null
    }

    return $response
}

# =============================================================================
# Cycle de convergence (1 iteration de la boucle)
# Retourne 'ok' | 'backoff' | 'stop'
# =============================================================================

function Invoke-AgentCycle {
    param([Parameter(Mandatory = $true)][pscustomobject]$Config)

    # 1. Token relu sur disque a CHAQUE cycle (la rotation peut l'avoir change).
    $script:Token = Read-AgentToken

    # 2. GET /state avec If-None-Match (ETag verbatim du cache)
    $headers = @{}
    $etag = Read-CachedEtag
    if (-not [string]::IsNullOrEmpty($etag)) {
        $headers['If-None-Match'] = $etag
    }

    $stateUrl = "$($Config.ServerUrl)/api/v1/agent/state"
    try {
        $response = Invoke-AgentHttpWithGrace -Method GET -Url $stateUrl -Token $script:Token -Headers $headers
    } catch {
        Write-AgentLog -Level WARNING -Message "Serveur injoignable sur GET /state : $($_.Exception.Message)"
        return 'backoff'
    }

    $script:Token = Update-TokenIfRotated -Response $response -CurrentToken $script:Token

    switch ($response.StatusCode) {
        200 {
            # 3. Persister cache + ETag (verbatim, guillemets inclus).
            $null = Parse-State -Json $response.Body   # refuse un major inconnu (§9)
            $newEtag = $response.Headers['ETag']
            if (-not [string]::IsNullOrEmpty($newEtag)) {
                Save-StateCache -StateJson $response.Body -Etag $newEtag
            }
            if ($script:Quarantined) {
                $script:Quarantined = $false
                Write-AgentLog -Level INFO -Message 'Quarantaine levee par le serveur : reprise du cycle complet.'
            }
            Write-AgentLog -Level INFO -Message 'GET /state -> 200 : etat cible rafraichi en cache.'
        }
        304 {
            if ($script:Quarantined) {
                $script:Quarantined = $false
                Write-AgentLog -Level INFO -Message 'Quarantaine levee par le serveur : reprise du cycle complet.'
            }
            Write-AgentLog -Level DEBUG -Message 'GET /state -> 304 : cache local valide, etat inchange.'
        }
        401 {
            Write-AgentLog -Level ERROR -Message '401 irrecuperable sur GET /state (token courant ET fenetre de grace refuses). ARRET du service — re-enrolement MANUEL requis par un admin, jamais automatique.'
            return 'stop'
        }
        403 {
            if (-not $script:Quarantined) {
                $script:Quarantined = $true
                Write-AgentLog -Level WARNING -Message 'AGENT_QUARANTINED (403) : passage en check-ins legers — GET /state continue a cadence normale, plus aucun POST /report ni traitement d''etat tant que la quarantaine n''est pas levee.'
            }
            return 'ok'   # cadence normale, PAS de backoff agressif sur 403
        }
        429 {
            Write-AgentLog -Level WARNING -Message 'GET /state -> 429 (throttle serveur) : backoff.'
            return 'backoff'
        }
        default {
            Write-AgentLog -Level WARNING -Message "GET /state -> $($response.StatusCode) inattendu : backoff."
            return 'backoff'
        }
    }

    # En quarantaine on ne devrait pas arriver ici (return plus haut) ; garde
    # defensive : pas de rapport tant que quarantaine active.
    if ($script:Quarantined) {
        return 'ok'
    }

    # 4. Rapport minimal — hostname COURT (defer 24.1 #8) + UUID SMBIOS.
    #    items: [] : aucun handler en 24.2, rapport vide VALIDE (200 cote serveur).
    #    Le rapport part MEME sur 304 : etat inchange = on rapporte quand meme.
    $uuid = [string](Get-CimInstance -ClassName Win32_ComputerSystemProduct).UUID
    # UUID SMBIOS non fiable sur certains firmwares (vide, tout-F, tout-0) :
    # on l'envoie tel quel (champ declaratif, l'identite reelle est le token)
    # mais on trace localement — divergence = identity_mismatch cote serveur.
    if ([string]::IsNullOrWhiteSpace($uuid) -or
        $uuid -match '^(?i)(F{8}-F{4}-F{4}-F{4}-F{12}|0{8}-0{4}-0{4}-0{4}-0{12})$') {
        Write-AgentLog -Level WARNING -Message "UUID SMBIOS invalide ou placeholder firmware ('$uuid') : le champ workstation.uuid du rapport n'est pas fiable (warnings identity_mismatch possibles cote serveur)."
    }
    $reportBody = Build-Report -Hostname $env:COMPUTERNAME -Uuid $uuid -Items @()

    # 5. POST /report
    $reportUrl = "$($Config.ServerUrl)/api/v1/agent/report"
    try {
        $response = Invoke-AgentHttpWithGrace -Method POST -Url $reportUrl -Token $script:Token -Body $reportBody
    } catch {
        Write-AgentLog -Level WARNING -Message "Serveur injoignable sur POST /report : $($_.Exception.Message)"
        return 'backoff'
    }

    # D5 : la rotation peut arriver sur la reponse du POST (meme sur 422).
    $script:Token = Update-TokenIfRotated -Response $response -CurrentToken $script:Token

    switch ($response.StatusCode) {
        200 {
            Write-AgentLog -Level INFO -Message 'POST /report -> 200 : rapport accepte, boucle fermee.'
            return 'ok'
        }
        401 {
            Write-AgentLog -Level ERROR -Message '401 irrecuperable sur POST /report. ARRET du service — re-enrolement MANUEL requis.'
            return 'stop'
        }
        403 {
            $script:Quarantined = $true
            Write-AgentLog -Level WARNING -Message 'AGENT_QUARANTINED (403) sur POST /report : passage en check-ins legers.'
            return 'ok'
        }
        429 {
            Write-AgentLog -Level WARNING -Message 'POST /report -> 429 (throttle serveur) : backoff.'
            return 'backoff'
        }
        default {
            Write-AgentLog -Level WARNING -Message "POST /report -> $($response.StatusCode) inattendu : backoff."
            return 'backoff'
        }
    }
}

# =============================================================================
# Boucle principale — timer + jitter ±10 % (D7/FR23), backoff exponentiel
# 30 s -> 60 s -> ... plafonne a l'intervalle normal (FR22).
# =============================================================================

function Start-AgentLoop {
    Write-AgentLog -Level INFO -Message ("SambaEdu Agent {0} demarre (hostname={1})." -f (Get-AgentVersion), $env:COMPUTERNAME)

    Initialize-AgentCache
    $backoffSeconds = 0   # 0 = pas de backoff en cours

    while ($true) {
        $config = $null
        $outcome = 'backoff'
        try {
            $config = Read-AgentConfig
            $outcome = Invoke-AgentCycle -Config $config
        } catch {
            # Defaut de config/token : on loggue et on retentera — un agent ne
            # crashe jamais silencieusement (AC2).
            Write-AgentLog -Level ERROR -Message "Cycle en echec : $($_.Exception.Message)"
        }

        if ($outcome -eq 'stop') {
            Write-AgentLog -Level ERROR -Message 'Arret du service sur erreur d''authentification irrecuperable.'
            break
        }

        $interval = if ($null -ne $config) { $config.IntervalSeconds } else { 3600 }

        if ($outcome -eq 'backoff') {
            # Backoff exponentiel : 30 s, double a chaque echec, plafonne a la
            # cadence normale. Jamais de retry agressif sur un serveur qui redemarre.
            $backoffSeconds = if ($backoffSeconds -le 0) { 30 } else { [Math]::Min($backoffSeconds * 2, $interval) }
            Write-AgentLog -Level INFO -Message "Prochain essai dans $backoffSeconds s (backoff exponentiel)."
            Start-Sleep -Seconds $backoffSeconds
            continue
        }

        # Cycle reussi : reset du backoff, attente cadence normale + jitter ±10 %
        # (evite les vagues synchronisees sur ~600 postes).
        $backoffSeconds = 0
        # Cast [int] obligatoire : [Math]::Floor retourne un double, et avec des
        # bornes double Get-Random tire un REEL uniforme sur [min, max) — le +1
        # decalerait alors la moyenne de +0.5. En bornes int : entier uniforme
        # sur [-jitterMax, +jitterMax] inclus (Maximum exclusif compense par +1),
        # symetrique.
        $jitterMax = [int][Math]::Floor($interval * 0.1)
        $jitter = Get-Random -Minimum (-$jitterMax) -Maximum ($jitterMax + 1)
        $sleep = [Math]::Max(1, $interval + $jitter)
        Write-AgentLog -Level DEBUG -Message "Prochain cycle dans $sleep s (intervalle $interval s, jitter $jitter s)."
        Start-Sleep -Seconds $sleep
    }
}

# Point d'entree (pas d'execution si le fichier est dot-source pour des tests).
if ($MyInvocation.InvocationName -ne '.') {
    Start-AgentLoop
}
