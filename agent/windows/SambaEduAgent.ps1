# =============================================================================
# SambaEduAgent.ps1 — Agent squelette Windows SE5 (Stories 24.2 + 24.3, Epic 24)
# =============================================================================
# Service SYSTEM (portee machine) : boucle de check-in qui ferme le circuit
# UI -> etat cible -> agent -> rapport -> UI.
#
# Story 24.3 (compagnon de session) ajoute ici les fonctions PARTAGEES du
# sous-systeme compagnon : enumeration des sessions interactives (CIM),
# cache per-user (cache\sessions\<SID>\), fetch SYSTEM `GET /state?user=`
# (Invoke-SessionStateFetch — aussi point d'entree de la tache planifiee
# SessionStateFetch.ps1), et durcissement 401 deux-acteurs dans
# Invoke-AgentHttpWithGrace. Le processus user (SessionCompanion.ps1) ne
# dot-source JAMAIS ce fichier : il vit sous Program Files avec ContractV1
# seulement — tout le HTTP et le token restent cote SYSTEM (contrat 23.3).
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
$script:SessionCacheRoot = Join-Path $script:CacheDir 'sessions'   # cache per-user <SID>\ (24.3)
$script:AppliedStatePath = Join-Path $script:AgentRoot 'applied-state.json'  # infra 24.4 (mode default)
$script:LogDir           = Join-Path $script:AgentRoot 'logs'
$script:LogPath          = Join-Path $script:LogDir 'agent.log'
$script:LogRetentionDays = 7

# --- Etat du process (jamais persiste) ---

$script:PreviousToken = $null   # ancien token garde pendant la fenetre de grace D5
# 403 AGENT_QUARANTINED -> check-ins legers. ATTENTION (review 24.3 #2) : ce
# flag est PROCESS-LOCAL, jamais persiste ni partage — la tache at-logon
# SessionStateFetch (process neuf a chaque logon) demarre toujours a $false :
# elle tente UN fetch, encaisse le 403 et s'arrete (asymetrie assumee avec le
# service, cf. session-companion.md §7). Les handlers 24.4 ne doivent PAS
# supposer un etat de quarantaine partage entre les deux acteurs.
$script:Quarantined   = $false

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
    # tmp suffixe $PID (review 24.3 #3 etendu) : depuis 24.3 le token a DEUX
    # ecrivains SYSTEM (rotation D5 recue par le service OU par la tache
    # at-logon) — meme risque TOCTOU que le cache de session.
    $tmp = "$script:TokenPath.$PID.tmp"
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
# Story 24.3 — sessions interactives + cache per-user
# =============================================================================
# Le compagnon de session (droits user) ne peut NI lire le token NI appeler
# le serveur (ACL 23.3 figee, NFR5). Le canal reseau reste 100 % SYSTEM :
# les fonctions ci-dessous (executees par le service et par la tache
# SessionStateFetch at-logon, toutes deux SYSTEM) tirent `GET /state?user=`
# pour chaque session interactive et ecrivent un cache PER-USER que le
# processus user lit en LECTURE SEULE.
# =============================================================================

<#
.SYNOPSIS
    Enumere les sessions interactives via CIM et retourne login court + SID.
.NOTES
    JAMAIS quser (sortie localisee, fragile) : Win32_LogonSession LogonType
    2 (Interactive) / 10 (RemoteInteractive) / 11 (CachedInteractive) +
    association Win32_LoggedOnUser -> Win32_Account (Name = login COURT sans
    domaine, SID = cle stable des ACL et du repertoire de cache).
    L'identite est resolue ICI, cote SYSTEM — le processus user ne declare
    jamais la sienne (anti-usurpation, decision n° 2).
    Dedoublonne par SID (un user peut avoir plusieurs LogonSession).
#>
function Get-InteractiveSessions {
    $sessions = Get-CimInstance -ClassName Win32_LogonSession `
        -Filter 'LogonType = 2 OR LogonType = 10 OR LogonType = 11' -ErrorAction Stop

    $bySid = @{}
    foreach ($session in @($sessions)) {
        $accounts = Get-CimAssociatedInstance -InputObject $session `
            -Association Win32_LoggedOnUser -ErrorAction SilentlyContinue
        foreach ($account in @($accounts)) {
            if ($null -eq $account -or -not $account.PSObject.Properties['SID']) { continue }
            $sid = [string]$account.SID
            # Liste BLANCHE (review 24.3 #1) : seuls les comptes users reels
            # (domaine OU locaux) portent un SID S-1-5-21-<machine/domaine>-RID.
            # Tout le reste — pseudo-sessions DWM (S-1-5-90-) / UMFD (S-1-5-96-)
            # en LogonType 2, comptes virtuels de service (S-1-5-80-, S-1-5-82-),
            # builtin — n'a aucun etat a tirer ; une liste noire serait
            # structurellement incomplete.
            if ($sid -notmatch '^S-1-5-21-') { continue }
            if ($bySid.ContainsKey($sid)) { continue }
            # Win32_Account.Name = login court SAM (jamais DOMAIN\user) : le
            # strip du domaine est structurel, pas du parsing. Garde login
            # vide (review 24.3 #1) : un Name non resolu (compte orphelin)
            # produirait un fetch `?user=` (vide) + cache parasite.
            $login = if ($account.PSObject.Properties['Name']) { [string]$account.Name } else { '' }
            if ([string]::IsNullOrWhiteSpace($login)) { continue }
            $bySid[$sid] = [pscustomobject]@{
                Login = $login
                Sid   = $sid
            }
        }
    }

    return @($bySid.Values)
}

<#
.SYNOPSIS
    Repertoire de cache per-user, cree avec son ACL si absent (decision n° 3).
.NOTES
    ACL posee A LA CREATION : /inheritance:r, SYSTEM F, Administrators F,
    <SID>:R — le user LIT son etat ((OI) propage le R aux fichiers), n'ecrit
    rien, ne lit pas le cache d'un autre SID. Les parents (cache\, sessions\)
    restent SYSTEM+Administrators : le user n'enumere pas l'arborescence mais
    ouvre son fichier par chemin complet (bypass traverse checking, privilege
    SeChangeNotifyPrivilege par defaut pour Users).
#>
function Initialize-SessionCacheDir {
    param([Parameter(Mandatory = $true)][string]$Sid)

    if (-not (Test-Path $script:SessionCacheRoot)) {
        New-Item -ItemType Directory -Path $script:SessionCacheRoot -Force | Out-Null
        Set-AgentAcl -Path $script:SessionCacheRoot
    }

    $dir = Join-Path $script:SessionCacheRoot $Sid
    if (-not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
        & icacls.exe $dir /inheritance:r /grant '*S-1-5-18:(OI)(CI)F' '*S-1-5-32-544:(OI)(CI)F' "*${Sid}:(OI)(CI)R" | Out-Null
    }

    return $dir
}

<#
.SYNOPSIS
    ETag du contexte (poste, user) — un fichier etag.txt PAR repertoire de
    session (piege n° 2 : reutiliser l'ETag machine casserait la revalidation).
#>
function Read-SessionEtag {
    param([Parameter(Mandatory = $true)][string]$Sid)

    $path = Join-Path (Join-Path $script:SessionCacheRoot $Sid) 'etag.txt'
    if (Test-Path $path) {
        # VERBATIM, guillemets RFC 7232 inclus (convention 24.2).
        return (Get-Content -Path $path -Raw)
    }

    return $null
}

<#
.SYNOPSIS
    Persiste l'enveloppe + ETag du contexte user (ecriture atomique tmp+Move).
.NOTES
    PAS de Set-AgentAcl sur le tmp (contrairement au cache machine) : les
    fichiers HERITENT de l'ACL du repertoire per-SID (grants (OI)) — un
    icacls explicite SYSTEM+Administrators retirerait le R du user.
    Le tmp nait dans le repertoire cible : il porte la bonne ACL des sa
    creation et Move-Item (rename NTFS) la conserve.
#>
function Save-SessionStateCache {
    param(
        [Parameter(Mandatory = $true)][string]$Sid,
        [Parameter(Mandatory = $true)][string]$StateJson,
        [Parameter(Mandatory = $true)][string]$Etag
    )

    $dir = Initialize-SessionCacheDir -Sid $Sid

    foreach ($pair in @(
            @{ Path = (Join-Path $dir 'state.json'); Value = $StateJson },
            @{ Path = (Join-Path $dir 'etag.txt'); Value = $Etag }
        )) {
        # tmp suffixe $PID (review 24.3 #3) : DEUX ecrivains SYSTEM possibles
        # (tache at-logon + cycle du service) — un tmp a nom fixe pourrait
        # etre ecrit par les deux a la fois (corruption) ou rename-vole.
        $tmp = "$($pair.Path).$PID.tmp"
        Set-Content -Path $tmp -Value $pair.Value -NoNewline -Encoding UTF8
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
    Appel HTTP avec gestion du 401 de rotation (AC3 24.2) : si 401 avec le
    nouveau token juste ecrit, on reessaie UNE fois avec l'ancien (fenetre de
    grace). Durcissement deux-acteurs (24.3, decision n° 5) : avant de
    laisser l'appelant declarer l'irrecuperable, le token est RELU SUR DISQUE
    — le service (cycle) et le fetch de session (logon) partagent le meme
    fichier token, et l'autre acteur peut avoir rotate pendant que cet appel
    etait en vol ($script:PreviousToken de CE process est alors null). S'il
    differe des tokens deja essayes : UN reessai. 401 apres tout ca ->
    irrecuperable, l'appelant arrete.
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

    # Etape (b) du traitement 401 (durcissement deux-acteurs 24.3) : la grace
    # MEMOIRE n'a rien donne (ou n'existait pas). Si le fichier token a change
    # entre-temps (rotation recue par l'AUTRE acteur, ecriture atomique 24.2),
    # le 401 n'est PAS irrecuperable : un seul reessai avec le token du disque.
    if ($response.StatusCode -eq 401) {
        $diskToken = $null
        try {
            $diskToken = Read-AgentToken
        } catch {
            Write-AgentLog -Level WARNING -Message "401 : relecture du token sur disque impossible ($($_.Exception.Message)) — durcissement deux-acteurs sans effet."
        }

        $alreadyTried = @($Token)
        if ($hasPrevious) { $alreadyTried += $script:PreviousToken }

        if (-not [string]::IsNullOrEmpty($diskToken) -and $alreadyTried -notcontains $diskToken) {
            Write-AgentLog -Level WARNING -Message '401 mais le token sur disque a change (rotation par l''autre acteur — service ou fetch de session) : reessai UNIQUE avec le token du disque (durcissement deux-acteurs 24.3).'
            $response = Invoke-AgentHttp -Method $Method -Url $Url -Token $diskToken -Headers $Headers -Body $Body
            if ($response.StatusCode -ne 401) {
                # Le token du disque est le bon : adoption pour la suite du
                # cycle, purge de la grace memoire (elle reference un token
                # que l'autre acteur a deja remplace).
                $script:Token = $diskToken
                $script:PreviousToken = $null
                Write-AgentLog -Level INFO -Message 'Reessai avec le token du disque accepte : rotation concurrente rattrapee, poursuite normale.'
            } else {
                Write-AgentLog -Level ERROR -Message '401 aussi avec le token relu sur disque : authentification irrecuperable.'
            }
        }
    }

    return $response
}

# =============================================================================
# Story 24.3 — fetch de session cote SYSTEM (GET /state?user=<login court>)
# =============================================================================

<#
.SYNOPSIS
    Pour chaque session interactive : `GET /state?user=` avec l'If-None-Match
    DU CONTEXTE, puis cache per-user. Un seul code pour les deux declencheurs
    (decision n° 4) : tache planifiee at-logon (SessionStateFetch.ps1) ET
    cycle du service (rafraichissement mid-session).
.NOTES
    - quarantaine (piege n° 11) : AUCUN fetch de session — l'etat ne serait
      pas traite, et les check-ins legers restent le GET /state machine ;
    - erreur reseau : log + skip de la session, PAS de backoff propre — le
      rattrapage est le cycle du service (AC5) ;
    - rotation D5 : Update-TokenIfRotated sur chaque reponse (304 compris) ;
    - login inconnu / compte local : le serveur repond 200 machine-only
      (agent.state.unknown_user cote serveur) — traite comme tout 200,
      aucun bruit cote poste (piege n° 3).
#>
function Invoke-SessionStateFetch {
    param([Parameter(Mandatory = $true)][pscustomobject]$Config)

    if ($script:Quarantined) {
        Write-AgentLog -Level DEBUG -Message 'Quarantaine active : fetch de session saute (check-ins legers = GET /state machine uniquement).'
        return
    }

    $sessions = @()
    try {
        # @() : une session unique ne doit pas etre deroulee en scalaire
        # (StrictMode + .Count).
        $sessions = @(Get-InteractiveSessions)
    } catch {
        Write-AgentLog -Level WARNING -Message "Enumeration des sessions interactives en echec : $($_.Exception.Message)"
        return
    }
    if ($sessions.Count -eq 0) {
        Write-AgentLog -Level DEBUG -Message 'Aucune session interactive : pas de fetch de session.'
        return
    }

    # Token relu sur disque a CHAQUE fetch : l'autre acteur (service ou tache
    # logon) peut l'avoir rotate depuis la derniere lecture de CE process.
    $script:Token = Read-AgentToken

    foreach ($session in $sessions) {
        $headers = @{}
        $etag = Read-SessionEtag -Sid $session.Sid
        if (-not [string]::IsNullOrEmpty($etag)) {
            $headers['If-None-Match'] = $etag
        }

        $url = "$($Config.ServerUrl)/api/v1/agent/state?user=$([uri]::EscapeDataString($session.Login))"
        try {
            $response = Invoke-AgentHttpWithGrace -Method GET -Url $url -Token $script:Token -Headers $headers
        } catch {
            Write-AgentLog -Level WARNING -Message "Serveur injoignable sur GET /state?user=$($session.Login) : $($_.Exception.Message) — skip (rattrapage au cycle du service)."
            continue
        }

        $script:Token = Update-TokenIfRotated -Response $response -CurrentToken $script:Token

        switch ($response.StatusCode) {
            200 {
                $null = Parse-State -Json $response.Body   # refuse un major inconnu (§9)
                $newEtag = $response.Headers['ETag']
                if (-not [string]::IsNullOrEmpty($newEtag)) {
                    Save-SessionStateCache -Sid $session.Sid -StateJson $response.Body -Etag $newEtag
                }
                Write-AgentLog -Level INFO -Message "GET /state?user=$($session.Login) -> 200 : cache de session $($session.Sid) rafraichi."
            }
            304 {
                Write-AgentLog -Level DEBUG -Message "GET /state?user=$($session.Login) -> 304 : cache de session $($session.Sid) valide."
            }
            401 {
                # Grace memoire ET relecture disque deja tentees par la couche
                # HTTP : irrecuperable. On ARRETE les fetchs (les sessions
                # suivantes echoueraient pareil) ; jamais de re-enrolement auto.
                Write-AgentLog -Level ERROR -Message "401 irrecuperable sur GET /state?user=$($session.Login) : fetchs de session interrompus — re-enrolement MANUEL requis."
                return
            }
            403 {
                # Quarantaine prononcee pendant le fetch : plus AUCUN
                # traitement d'etat (piege n° 11) — le flag coupe aussi le
                # rapport du cycle en cours cote service.
                if (-not $script:Quarantined) {
                    $script:Quarantined = $true
                    Write-AgentLog -Level WARNING -Message 'AGENT_QUARANTINED (403) sur un fetch de session : arret des fetchs, passage en check-ins legers.'
                }
                return
            }
            default {
                Write-AgentLog -Level WARNING -Message "GET /state?user=$($session.Login) -> $($response.StatusCode) inattendu : skip (rattrapage au cycle du service)."
            }
        }
    }
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

    # Story 24.3 (decision n° 4) : apres la portee machine, le cycle rafraichit
    # aussi les caches de session (fraicheur laxe NFR3 : logon + timer). Meme
    # code que la tache at-logon ; une erreur ici ne casse JAMAIS le cycle
    # machine (le rattrapage est le cycle suivant), et les retours
    # ok|backoff|stop du cycle restent ceux de la portee machine.
    if (-not $script:Quarantined) {
        try {
            Invoke-SessionStateFetch -Config $Config
        } catch {
            Write-AgentLog -Level WARNING -Message "Rafraichissement des caches de session en echec : $($_.Exception.Message) (rattrapage au prochain cycle)."
        }
    }

    # En quarantaine on ne devrait pas arriver ici (return plus haut) ; garde
    # defensive : pas de rapport tant que quarantaine active. La quarantaine
    # peut aussi etre TOMBEE pendant le fetch de session (403) : pas de
    # rapport non plus dans ce cas.
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
