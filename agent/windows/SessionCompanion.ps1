# =============================================================================
# SessionCompanion.ps1 — Compagnon de session, cote USER (Stories 24.3 + 24.4)
# =============================================================================
# Tache planifiee `SambaEduAgent-SessionCompanion` (principal BUILTIN\Users,
# declencheur « At log on ») : s'execute DANS la session du user qui ouvre,
# avec SES droits — jamais SYSTEM. Enregistree par Install-SambaEduAgent.ps1.
#
# Frontiere de confiance (NFR5, contrat 23.3 FIGE) — ce processus :
#   - ne lit JAMAIS le token (ACL SYSTEM+Administrators : illisible ici) ;
#   - n'appelle JAMAIS le serveur (le canal reseau est 100 % SYSTEM —
#     SessionStateFetch.ps1 a tire l'etat au logon, Sync-WallpaperAssets a
#     pre-telecharge les assets) ;
#   - lit UNIQUEMENT son cache per-user cache\sessions\<SON SID>\state.json
#     (lecture seule) et le cache d'assets assets\ (lecture seule, Users:R) ;
#   - ecrit dans %LOCALAPPDATA%\SambaEdu\Agent\ (log, applied-state,
#     overlay.json) + UNE exception cadree (decision 24.4 n° 7) : SON drop
#     reports\sessions\<SON SID>\session-report.json (ACL <SID>:M posee par
#     SYSTEM — il n'ecrit ni ne lit les drops des autres) ;
#   - ne declare jamais son identite : son SID est resolu localement pour
#     trouver SON cache/SON drop, l'identite envoyee au serveur a ete
#     resolue cote SYSTEM par enumeration CIM (anti-usurpation).
#
# Story 24.4 — le no-op 24.3 est remplace par la CONVERGENCE REELLE :
#   1. attente bornee du cache frais (poll 2 s, timeout 60 s), fallback
#      dernier cache, sinon sortie silencieuse (iso 24.3) ;
#   2. Parse-State + partition : portees `session` + `machine_user`
#      SEULEMENT (la portee `machine` reste au service SYSTEM) ;
#   3. Invoke-ConvergencePass (shared/ConvergenceEngine.ps1) : dispatch par
#      type vers les handlers (wallpaper, overlay), machine d'etats §5
#      (strict/default/premier passage) avec applied-state PER-USER
#      (%LOCALAPPDATA%\SambaEdu\Agent\applied-state.json — le fichier machine
#      de 24.2 est inaccessible en ecriture ici, il reste aux futurs
#      handlers machine), isolation try/catch par item ;
#   4. drop des resultats : session-report.json (ecriture atomique tmp $PID)
#      — collecte + validation stricte par le service au cycle suivant ;
#   5. BOUCLE RESIDENTE (decision n° 6) : poll du mtime du cache (~60 s),
#      re-convergence quand l'etat change ET periodiquement (~5 min, level-
#      triggered — detecte les derives locales). Le processus meurt au
#      logoff (fin de session) ; ExecutionTimeLimit de la tache = illimite
#      (ajuste en 24.4, piege n° 9) et MultipleInstances IgnoreNew empeche
#      le doublon.
#
# Quarantaine (piege n° 12) : le fetch SYSTEM est saute en quarantaine — le
# compagnon continue de converger sur son DERNIER cache (level-triggered,
# inoffensif : l'etat ne change plus). Limitation MVP assumee.
#
# AUCUNE dependance AD/Kerberos/LDAP (NFR7). Aucun message visible, jamais :
# tout passe par companion.log.
# =============================================================================

#Requires -Version 5.1
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# Modules : a cote du script (C:\Program Files\SambaEdu\Agent\ — bundle a
# plat, lisible user) ou layout du repo pour le dev (..\shared, handlers\).
function Resolve-CompanionModule {
    param([Parameter(Mandatory = $true)][string[]]$Candidates)

    foreach ($candidate in $Candidates) {
        if (Test-Path $candidate) { return $candidate }
    }
    throw "Module introuvable (cherche : $($Candidates -join ' ; '))"
}

. (Resolve-CompanionModule -Candidates @(
    (Join-Path $PSScriptRoot 'ContractV1.ps1'),
    (Join-Path (Split-Path $PSScriptRoot -Parent) 'shared\ContractV1.ps1')))
. (Resolve-CompanionModule -Candidates @(
    (Join-Path $PSScriptRoot 'ConvergenceEngine.ps1'),
    (Join-Path (Split-Path $PSScriptRoot -Parent) 'shared\ConvergenceEngine.ps1')))
. (Resolve-CompanionModule -Candidates @(
    (Join-Path $PSScriptRoot 'Wallpaper.ps1'),
    (Join-Path $PSScriptRoot 'handlers\Wallpaper.ps1')))
. (Resolve-CompanionModule -Candidates @(
    (Join-Path $PSScriptRoot 'Overlay.ps1'),
    (Join-Path $PSScriptRoot 'handlers\Overlay.ps1')))

# --- Chemins -----------------------------------------------------------------
# Ecritures : profil user + SON drop per-SID (decision 24.4 n° 7).
# Lectures : cache per-SID + cache d'assets (ProgramData, lecture seule).

$script:CompanionLogDir    = Join-Path $env:LOCALAPPDATA 'SambaEdu\Agent'
$script:CompanionLogPath   = Join-Path $script:CompanionLogDir 'companion.log'
$script:LogRetentionDays   = 7
$script:SessionCacheRoot   = 'C:\ProgramData\SambaEdu\Agent\cache\sessions'
$script:SessionReportsRoot = 'C:\ProgramData\SambaEdu\Agent\reports\sessions'
# Dernier-applique PER-USER (mode default §5) — JAMAIS le fichier machine
# C:\ProgramData\SambaEdu\Agent\applied-state.json (ACL SYSTEM, et le
# dernier-applique d'un item session est propre a CHAQUE user).
$script:AppliedStatePath   = Join-Path $script:CompanionLogDir 'applied-state.json'

$script:PollIntervalSeconds = 2
$script:PollTimeoutSeconds  = 60
# « Frais » = RECENT (< 5 min), PAS « garanti du logon courant » (review
# 24.3 #4) : la tache SessionFetch demarre en parallele, son ecriture peut
# preceder de peu le demarrage du compagnon — la fenetre de tolerance accepte
# donc aussi un cache ecrit par un cycle service juste avant le logon.
$script:FreshWindowMinutes  = 5

# Boucle residente (decision 24.4 n° 6) : poll du mtime du cache + re-test
# periodique level-triggered (derives locales detectees sans changement
# d'etat). Cadences volontairement laxes (NFR3) : la re-convergence n'est
# pas du temps reel.
$script:CachePollSeconds    = 60
$script:PeriodicPassSeconds = 300

# =============================================================================
# Log local — format/rotation iso 24.2 ([ISO 8601] [LEVEL] message,
# rotation quotidienne companion-YYYY-MM-DD.log, retention 7 jours), mais
# dans %LOCALAPPDATA% : aucune elevation, aucune ACL a poser (profil user).
# Reimplementation assumee (PAS de dot-source de SambaEduAgent.ps1 : son
# Write-AgentLog ecrit sous ProgramData, interdit d'ecriture ici).
# =============================================================================

function Write-CompanionLog {
    param(
        [ValidateSet('DEBUG', 'INFO', 'WARNING', 'ERROR')][string]$Level = 'INFO',
        [Parameter(Mandatory = $true)][string]$Message
    )

    if (-not (Test-Path $script:CompanionLogDir)) {
        New-Item -ItemType Directory -Path $script:CompanionLogDir -Force | Out-Null
    }

    if (Test-Path $script:CompanionLogPath) {
        $lastWrite = (Get-Item $script:CompanionLogPath).LastWriteTime.Date
        if ($lastWrite -lt (Get-Date).Date) {
            $archive = Join-Path $script:CompanionLogDir ('companion-{0:yyyy-MM-dd}.log' -f $lastWrite)
            Move-Item -Path $script:CompanionLogPath -Destination $archive -Force
        }
        Get-ChildItem -Path $script:CompanionLogDir -Filter 'companion-*.log' |
            Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$script:LogRetentionDays) } |
            Remove-Item -Force -ErrorAction SilentlyContinue
    }

    $line = '[{0}] [{1}] {2}' -f (Get-Date -Format 'yyyy-MM-ddTHH:mm:ssK'), $Level, $Message
    Add-Content -Path $script:CompanionLogPath -Value $line -Encoding UTF8
}

# =============================================================================
# Cache per-user : attente bornee puis lecture seule
# =============================================================================

<#
.SYNOPSIS
    Attend (poll borne) un state.json frais dans le cache de CE SID, sinon
    retombe sur le dernier cache existant, sinon $null (decision 24.3 n° 1).
#>
function Wait-SessionStateCache {
    param([Parameter(Mandatory = $true)][string]$StatePath)

    $start = Get-Date
    $deadline = $start.AddSeconds($script:PollTimeoutSeconds)
    $freshFloor = $start.AddMinutes(-$script:FreshWindowMinutes)

    while ((Get-Date) -lt $deadline) {
        if (Test-Path $StatePath) {
            if ((Get-Item $StatePath).LastWriteTime -ge $freshFloor) {
                return @{ Fresh = $true }
            }
        }
        Start-Sleep -Seconds $script:PollIntervalSeconds
    }

    if (Test-Path $StatePath) {
        # Serveur injoignable au logon (ou fetch en retard) : la session vit
        # sur le DERNIER etat connu — le cycle du service rattrapera.
        return @{ Fresh = $false }
    }

    return $null
}

# =============================================================================
# Applied-state PER-USER (mode default §5 — Story 24.4, decision n° 5)
# =============================================================================

<#
.SYNOPSIS
    Charge le dernier-applique per-user : map type -> {hash, applied_at}.
    Fichier corrompu/absent = map vide + log (premier passage §5 : jamais
    interprete comme une derive humaine).
#>
function Read-AppliedState {
    $state = @{}
    if (-not (Test-Path $script:AppliedStatePath)) {
        return $state
    }

    try {
        $parsed = Get-Content -Path $script:AppliedStatePath -Raw | ConvertFrom-Json
        foreach ($prop in @($parsed.PSObject.Properties)) {
            $state[$prop.Name] = $prop.Value
        }
    } catch {
        Write-CompanionLog -Level WARNING -Message "applied-state.json corrompu : repart sans memoire (premier passage §5) — $($_.Exception.Message)"
        $state = @{}
    }

    return $state
}

<#
.SYNOPSIS
    Persiste le dernier-applique per-user (ecriture atomique tmp $PID + Move
    — profil user, deux passes du MEME process ne se chevauchent pas mais la
    convention TOCTOU 24.3 est conservee partout).
#>
function Save-AppliedState {
    param([Parameter(Mandatory = $true)][hashtable]$State)

    if (-not (Test-Path $script:CompanionLogDir)) {
        New-Item -ItemType Directory -Path $script:CompanionLogDir -Force | Out-Null
    }

    $json = ConvertTo-Json -InputObject $State -Depth 5 -Compress
    $tmp = "$script:AppliedStatePath.$PID.tmp"
    Set-Content -Path $tmp -Value $json -NoNewline -Encoding UTF8
    Move-Item -Path $tmp -Destination $script:AppliedStatePath -Force
}

# =============================================================================
# Drop des resultats session (Story 24.4, decision n° 7)
# =============================================================================

<#
.SYNOPSIS
    Ecrit session-report.json dans le drop per-SID — la SEULE ecriture du
    compagnon hors %LOCALAPPDATA% (ACL <SID>:M posee par SYSTEM).
.NOTES
    Repertoire absent (install pas a niveau, fetch pas encore passe) : log +
    skip — la convergence locale a EU lieu, seul le rapport attendra que le
    service ait cree le drop (cycle suivant).
#>
function Write-SessionReportDrop {
    param(
        [Parameter(Mandatory = $true)][string]$Sid,
        [AllowEmptyCollection()][object[]]$Items = @()
    )

    $dir = Join-Path $script:SessionReportsRoot $Sid
    if (-not (Test-Path $dir)) {
        Write-CompanionLog -Level WARNING -Message "Repertoire de drop absent ($dir) : resultats non deposes (le fetch SYSTEM le creera — rapport au cycle suivant)."
        return
    }

    $drop = [ordered]@{
        generated_at = [DateTime]::UtcNow.ToString("yyyy-MM-dd'T'HH:mm:ssK")
        items        = @($Items)
    }
    $json = ConvertTo-Json -InputObject $drop -Depth 10 -Compress

    $path = Join-Path $dir 'session-report.json'
    $tmp = "$path.$PID.tmp"
    Set-Content -Path $tmp -Value $json -NoNewline -Encoding UTF8
    Move-Item -Path $tmp -Destination $path -Force

    Write-CompanionLog -Level DEBUG -Message "Drop depose : $(@($Items).Count) item(s) de rapport ($path)."
}

# =============================================================================
# Passe de convergence (Story 24.4 — remplace le no-op 24.3)
# =============================================================================

<#
.SYNOPSIS
    Registre des handlers session : type -> @{ Test; Apply }. Les deux
    premiers types reels (24.4) — tout type sans handler est ignore par le
    moteur (contrat §8), les suivants arrivent avec l'Epic 27.
#>
function New-CompanionHandlers {
    $logAction = { param($Level, $Message) Write-CompanionLog -Level $Level -Message $Message }

    return @{
        wallpaper = New-WallpaperHandler
        overlay   = New-OverlayHandler -LogAction $logAction
    }
}

<#
.SYNOPSIS
    UNE passe : lecture du cache, partition, convergence, applied-state,
    drop. Retourne $true si une passe a effectivement tourne.
#>
function Invoke-CompanionPass {
    param(
        [Parameter(Mandatory = $true)][string]$Sid,
        [Parameter(Mandatory = $true)][string]$StatePath,
        [Parameter(Mandatory = $true)][hashtable]$Handlers
    )

    if (-not (Test-Path $StatePath)) {
        Write-CompanionLog -Level DEBUG -Message "Cache absent ($StatePath) : pas de passe."
        return $false
    }

    # Lecture SEULE (ACL <SID>:R posee par le fetch SYSTEM). Course assumee
    # (review 24.3 #4) : le fetch peut renommer state.json ENTRE le Test-Path
    # et ce Get-Content — l'erreur remonte au try/catch de l'appelant
    # (loggee, re-tentee au tick suivant de la boucle residente).
    $stateJson = Get-Content -Path $StatePath -Raw
    $state = Parse-State -Json $stateJson

    # Partition des portees (24.3, piege n° 4) — JAMAIS de recouvrement :
    #   service SYSTEM  -> machine SEULEMENT ;
    #   compagnon (ici) -> session + machine_user SEULEMENT.
    $machineCount = @($state.Machine).Count
    if ($machineCount -gt 0) {
        Write-CompanionLog -Level DEBUG -Message "Portee machine ignoree ($machineCount item(s)) : exclusivite du service SYSTEM."
    }

    # Ordre SERVEUR (FR18) : items de la portee session puis machine_user,
    # chacun dans l'ordre du payload — jamais d'ordre invente.
    $items = @(@($state.Session) + @($state.MachineUser))

    $appliedState = Read-AppliedState
    $reportItems = @(Invoke-ConvergencePass -Items $items -Handlers $Handlers `
            -AppliedState $appliedState -LogAction {
            param($Level, $Message) Write-CompanionLog -Level $Level -Message $Message
        })
    Save-AppliedState -State $appliedState

    Write-SessionReportDrop -Sid $Sid -Items $reportItems

    Write-CompanionLog -Level INFO -Message "Passe compagnon terminee : $(@($items).Count) item(s) traites, $(@($reportItems).Count) statut(s) (generated_at=$($state.GeneratedAt))."
    return $true
}

<#
.SYNOPSIS
    Vie du compagnon : passe initiale apres attente bornee du cache, puis
    boucle RESIDENTE — re-convergence quand le cache change (mtime) et
    periodiquement (level-triggered). Le processus se termine au logoff
    (fin de session) ; aucune sortie n'est jamais visible du user.
#>
function Start-CompanionLoop {
    # SON SID, resolu localement — uniquement pour trouver SON repertoire de
    # cache et SON drop. Jamais transmis a personne.
    $identity = [System.Security.Principal.WindowsIdentity]::GetCurrent()
    $sid = $identity.User.Value
    Write-CompanionLog -Level INFO -Message "Compagnon de session demarre (user=$($identity.Name), sid=$sid) — apres ouverture de session, jamais dans son chemin synchrone (NFR1). Boucle residente 24.4 (poll $script:CachePollSeconds s, re-test $script:PeriodicPassSeconds s)."

    $statePath = Join-Path (Join-Path $script:SessionCacheRoot $sid) 'state.json'
    $handlers = New-CompanionHandlers

    $cache = Wait-SessionStateCache -StatePath $statePath
    if ($null -eq $cache) {
        # Premier logon hors-ligne d'un user sans cache : on RESTE resident
        # (le cycle du service peut ecrire le cache mid-session) mais en
        # silence — AUCUN message visible.
        Write-CompanionLog -Level INFO -Message "Aucun cache de session ($statePath) apres $script:PollTimeoutSeconds s : attente residente, convergence des qu'un cache apparait."
    } elseif (-not $cache.Fresh) {
        Write-CompanionLog -Level WARNING -Message 'Pas de cache frais dans le delai (serveur injoignable au logon ?) : convergence sur le DERNIER cache connu.'
    }

    $lastSeenWrite = [DateTime]::MinValue
    $lastPassAt = [DateTime]::MinValue

    while ($true) {
        $currentWrite = $null
        if (Test-Path $statePath) {
            $currentWrite = (Get-Item $statePath).LastWriteTime
        }

        $stateChanged = ($null -ne $currentWrite) -and ($currentWrite -ne $lastSeenWrite)
        $periodicDue = ((Get-Date) - $lastPassAt).TotalSeconds -ge $script:PeriodicPassSeconds

        if ($stateChanged -or ($periodicDue -and $null -ne $currentWrite)) {
            try {
                if (Invoke-CompanionPass -Sid $sid -StatePath $statePath -Handlers $handlers) {
                    $lastSeenWrite = $currentWrite
                    $lastPassAt = Get-Date
                }
            } catch {
                # Une passe ratee (cache en cours de rename, parse, handler
                # hors isolation) ne tue JAMAIS la boucle : log + retry au
                # tick suivant.
                Write-CompanionLog -Level ERROR -Message "Passe compagnon en echec : $($_.Exception.Message) — nouvel essai au prochain tick."
                $lastPassAt = Get-Date   # pas de retry agressif en boucle serree
            }
        }

        Start-Sleep -Seconds $script:CachePollSeconds
    }
}

# Point d'entree (pas d'execution si dot-source pour relecture/tests).
if ($MyInvocation.InvocationName -ne '.') {
    try {
        Start-CompanionLoop
    } catch {
        # Rien ne doit jamais etre visible ni bloquant dans la session.
        try {
            Write-CompanionLog -Level ERROR -Message "Compagnon en echec : $($_.Exception.Message)"
        } catch {
            # Log impossible : sortie silencieuse.
        }
        exit 1
    }
}
