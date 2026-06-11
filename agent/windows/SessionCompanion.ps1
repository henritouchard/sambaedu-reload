# =============================================================================
# SessionCompanion.ps1 — Compagnon de session, cote USER (Story 24.3, Epic 24)
# =============================================================================
# Tache planifiee `SambaEduAgent-SessionCompanion` (principal BUILTIN\Users,
# declencheur « At log on ») : s'execute DANS la session du user qui ouvre,
# avec SES droits — jamais SYSTEM. Enregistree par Install-SambaEduAgent.ps1.
#
# Frontiere de confiance (NFR5, contrat 23.3 FIGE) — ce processus :
#   - ne lit JAMAIS le token (ACL SYSTEM+Administrators : illisible ici) ;
#   - n'appelle JAMAIS le serveur (le canal reseau est 100 % SYSTEM —
#     SessionStateFetch.ps1 a tire l'etat au logon) ;
#   - lit UNIQUEMENT son cache per-user cache\sessions\<SON SID>\state.json
#     (lecture seule, ACL posee par le fetch SYSTEM) ;
#   - n'ecrit RIEN hors de %LOCALAPPDATA%\SambaEdu\Agent\ (son log) ;
#   - ne declare jamais son identite : son SID est resolu localement pour
#     trouver SON cache, l'identite envoyee au serveur a ete resolue cote
#     SYSTEM par enumeration CIM (anti-usurpation, decision n° 2).
#
# Ce que fait UNE execution (24.3 — AUCUN handler, iso-squelette 24.2) :
#   1. attente bornee du cache frais (poll 2 s, timeout 60 s) — asynchrone a
#      l'ouverture de session (NFR1 : la tache tourne en parallele du logon,
#      rien n'attend ce script) ;
#   2. fallback : dernier cache existant ; sinon sortie SILENCIEUSE (le
#      prochain cycle du service convergera) ;
#   3. Parse-State (ContractV1.ps1 — refus major inconnu) ;
#   4. partition des portees : traite `session` + `machine_user` SEULEMENT
#      (la portee `machine` est l'exclusivite du service SYSTEM — piege
#      n° 4 : ces portees ne sont PAS vides en machine-only, un broadcast
#      sort en portee session) ;
#   5. traitement no-op journalise par item (type, scope, mode) — les
#      handlers (wallpaper, overlay, ...) arrivent en 24.4.
#
# AUCUNE dependance AD/Kerberos/LDAP (NFR7). Aucun message visible, jamais :
# tout passe par companion.log.
# =============================================================================

#Requires -Version 5.1
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# ContractV1.ps1 : a cote du script (C:\Program Files\SambaEdu\Agent\ —
# lisible user, piege n° 7) ou ..\shared (layout du repo pour le dev).
$contractModule = Join-Path $PSScriptRoot 'ContractV1.ps1'
if (-not (Test-Path $contractModule)) {
    $contractModule = Join-Path (Split-Path $PSScriptRoot -Parent) 'shared\ContractV1.ps1'
}
. $contractModule

# --- Chemins -----------------------------------------------------------------
# Ecritures : profil user UNIQUEMENT (decision n° 8). Lectures : cache per-SID.

$script:CompanionLogDir   = Join-Path $env:LOCALAPPDATA 'SambaEdu\Agent'
$script:CompanionLogPath  = Join-Path $script:CompanionLogDir 'companion.log'
$script:LogRetentionDays  = 7
$script:SessionCacheRoot  = 'C:\ProgramData\SambaEdu\Agent\cache\sessions'

$script:PollIntervalSeconds = 2
$script:PollTimeoutSeconds  = 60
# « Frais » = RECENT (< 5 min), PAS « garanti du logon courant » (review
# 24.3 #4) : la tache SessionFetch demarre en parallele, son ecriture peut
# preceder de peu le demarrage du compagnon — la fenetre de tolerance accepte
# donc aussi un cache ecrit par un cycle service juste avant le logon. C'est
# suffisant en 24.3 (meme contenu a etat serveur egal) ; une correlation
# stricte au logon serait un design de l'agent definitif.
$script:FreshWindowMinutes  = 5

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
    retombe sur le dernier cache existant, sinon $null (decision n° 1).
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
        # sur le DERNIER etat connu (AC3) — le cycle du service rattrapera.
        return @{ Fresh = $false }
    }

    return $null
}

# =============================================================================
# Traitement 24.3 : parse + partition + journal (no-op — handlers en 24.4)
# =============================================================================

function Invoke-CompanionPass {
    # SON SID, resolu localement — uniquement pour trouver SON repertoire de
    # cache. Jamais transmis a personne.
    $identity = [System.Security.Principal.WindowsIdentity]::GetCurrent()
    $sid = $identity.User.Value
    Write-CompanionLog -Level INFO -Message "Compagnon de session demarre (user=$($identity.Name), sid=$sid) — apres ouverture de session, jamais dans son chemin synchrone (NFR1)."

    $statePath = Join-Path (Join-Path $script:SessionCacheRoot $sid) 'state.json'

    $cache = Wait-SessionStateCache -StatePath $statePath
    if ($null -eq $cache) {
        # Premier logon hors-ligne d'un user sans cache : sortie silencieuse,
        # AUCUN message visible (AC3) — le prochain cycle convergera.
        Write-CompanionLog -Level INFO -Message "Aucun cache de session ($statePath) apres $script:PollTimeoutSeconds s : sortie silencieuse, convergence au prochain cycle du service."
        return
    }
    if (-not $cache.Fresh) {
        Write-CompanionLog -Level WARNING -Message 'Pas de cache frais dans le delai (serveur injoignable au logon ?) : traitement sur le DERNIER cache connu.'
    }

    # Lecture SEULE (ACL <SID>:R posee par le fetch SYSTEM). Course assumee
    # (review 24.3 #4) : le fetch peut renommer state.json ENTRE le test de
    # fraicheur et ce Get-Content — la lecture echoue alors, le try/catch du
    # main loggue et sort (logon rate, rattrape au prochain cycle/logon).
    # Toute erreur de lecture/parse suit le meme chemin : jamais de crash.
    $stateJson = Get-Content -Path $statePath -Raw
    $state = Parse-State -Json $stateJson

    # Partition des portees (piege n° 4) — JAMAIS de recouvrement :
    #   service SYSTEM  -> machine SEULEMENT ;
    #   compagnon (ici) -> session + machine_user SEULEMENT.
    $machineCount = @($state.Machine).Count
    Write-CompanionLog -Level DEBUG -Message "Portee machine ignoree ($machineCount item(s)) : exclusivite du service SYSTEM."

    foreach ($partition in @(
            @{ Scope = 'session'; Items = @($state.Session) },
            @{ Scope = 'machine_user'; Items = @($state.MachineUser) }
        )) {
        foreach ($item in $partition.Items) {
            $type = if ($item.PSObject.Properties['type']) { [string]$item.type } else { '?' }
            $mode = if ($item.PSObject.Properties['mode']) { [string]$item.mode } else { '?' }
            # 24.3 : no-op journalise — le handler correspondant (24.4)
            # remplacera cette ligne par l'application effective.
            Write-CompanionLog -Level INFO -Message "Item recu (no-op 24.3, aucun handler) : type=$type scope=$($partition.Scope) mode=$mode."
        }
    }

    $total = @($state.Session).Count + @($state.MachineUser).Count
    Write-CompanionLog -Level INFO -Message "Passe compagnon terminee : $total item(s) en portees session+machine_user (generated_at=$($state.GeneratedAt))."
}

# Point d'entree (pas d'execution si dot-source pour relecture/tests).
if ($MyInvocation.InvocationName -ne '.') {
    try {
        Invoke-CompanionPass
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
