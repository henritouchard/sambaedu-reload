# =============================================================================
# SambaEdu Agent — Contrat se5.desired-state/v1 (Story 24.2)
# =============================================================================
# Module PARTAGEABLE (coeur cross-OS, contrainte n.5 du cahier des charges) :
# aucune dependance Windows ici — uniquement le parsing du contrat v1 et la
# construction du rapport. Les golden files normatifs sont cote serveur :
# tests/Fixtures/Agent/{state,report}.v1.json (FIGES — ne jamais diverger).
#
# Regle d'evolution (docs/agent/contract-v1.md §9) :
#   - champ ajoute  = version mineure -> l'agent IGNORE l'inconnu ;
#   - major inconnu = refus (Parse-State leve une erreur).
# =============================================================================

#Requires -Version 5.1
Set-StrictMode -Version Latest

# --- Constantes de contrat (FIGEES — iso App\Services\Agent\StateContract) ---

# Nom de schema complet. Jamais une variable d'environnement (NFR12).
$script:ContractSchema = 'se5.desired-state/v1'

# Version majeure acceptee (l'agent refuse v2, v3, ... — §9).
$script:ContractMajor = 1

# Les trois portees de l'enveloppe etat (aussi les cles JSON).
$script:ContractScopes = @('machine', 'session', 'machine_user')

# Identifiants de type de ressource publies (§7 — iso StateContract::RESOURCE_TYPES).
# Figes : on ne renomme JAMAIS, on deprecie + ajoute.
$script:ResourceTypes = @(
    'wallpaper', 'overlay', 'shortcuts', 'printers', 'drives',
    'associations', 'registry', 'app_config', 'applications'
)

# Statuts de conformite du rapport (§6 — iso App\Enums\AgentResourceStatus).
$script:ResourceStatuses = @('compliant', 'drift', 'drifted_allowed', 'error')

# Version de l'agent emetteur, declaree dans chaque rapport (`agent_version`).
# 1.0.0 pour le squelette MVP — a bumper a chaque release (Epic 25).
$script:AgentVersion = '1.0.0'

function Get-ContractSchema { return $script:ContractSchema }
function Get-ContractScopes { return $script:ContractScopes }
function Get-ContractResourceTypes { return $script:ResourceTypes }
function Get-AgentVersion { return $script:AgentVersion }

<#
.SYNOPSIS
    Valide le champ `schema` d'une enveloppe ou d'un rapport.
.NOTES
    Forward-compat §9 : seule la version MAJEURE est discriminante. Un
    `se5.desired-state/v1.1` (mineure) serait accepte ; `v2` est refuse.
#>
function Test-ContractSchema {
    param([Parameter(Mandatory = $true)][AllowEmptyString()][string]$Schema)

    if ($Schema -notmatch '^se5\.desired-state/v(\d+)') {
        return $false
    }

    return ([int]$Matches[1]) -eq $script:ContractMajor
}

<#
.SYNOPSIS
    Parse-State — decode et valide l'enveloppe `GET /state` (200).
.DESCRIPTION
    Valide `schema` (refus d'un major inconnu) et retourne les 3 portees.
    Les champs inconnus de l'enveloppe sont ignores (forward-compat §9).
    L'ETag n'est PAS gere ici (transport, pas contrat) — il reste opaque
    et stocke verbatim par la couche HTTP.
#>
function Parse-State {
    param([Parameter(Mandatory = $true)][string]$Json)

    $state = $Json | ConvertFrom-Json

    $schema = if ($state.PSObject.Properties['schema']) { [string]$state.schema } else { '' }
    if (-not (Test-ContractSchema -Schema $schema)) {
        throw "Schema inconnu ou major non supporte : '$schema' (attendu : $script:ContractSchema)"
    }

    # Portee absente (enveloppe tronquee) -> liste vide, jamais $null.
    $scopes = @{}
    foreach ($scope in $script:ContractScopes) {
        $items = if ($state.PSObject.Properties[$scope]) { @($state.$scope) } else { @() }
        $scopes[$scope] = $items
    }

    return [pscustomobject]@{
        Schema      = $schema
        GeneratedAt = [string]$state.generated_at
        TtlSeconds  = [int]$state.ttl_seconds
        Machine     = $scopes['machine']
        Session     = $scopes['session']
        MachineUser = $scopes['machine_user']
    }
}

<#
.SYNOPSIS
    Build-Report — construit le payload JSON de `POST /report`.
.DESCRIPTION
    Rapport minimal du contrat §6 : schema, generated_at (UTC ISO 8601),
    agent_version, workstation {hostname, uuid}, items.

    REGLE HOSTNAME (resolution defer review 24.1 #8) : $Hostname DOIT etre
    le nom COURT du poste (sans domaine) — `$env:COMPUTERNAME` — car le
    serveur le compare a `workstations.name` (nom court d'enrolement) et
    loggue un warning `agent.report.identity_mismatch` en cas de divergence.

    Pour la story 24.2 (squelette, aucun handler) : $Items est TOUJOURS @()
    — rapport vide valide cote serveur (200 {success: true}).
#>
function Build-Report {
    param(
        [Parameter(Mandatory = $true)][string]$Hostname,
        # AllowEmptyString : certains firmwares n'exposent pas d'UUID SMBIOS —
        # un UUID vide ne doit pas faire echouer le cycle (champ declaratif).
        [Parameter(Mandatory = $true)][AllowEmptyString()][string]$Uuid,
        [object[]]$Items = @()
    )

    $report = [ordered]@{
        schema        = $script:ContractSchema
        generated_at  = [DateTime]::UtcNow.ToString("yyyy-MM-dd'T'HH:mm:ssK")
        agent_version = $script:AgentVersion
        workstation   = [ordered]@{
            hostname = $Hostname
            uuid     = $Uuid
        }
        items         = @($Items)
    }

    # -Depth 10 : payloads d'items imbriques (24.4) sans troncature silencieuse.
    return ConvertTo-Json -InputObject $report -Depth 10 -Compress
}
