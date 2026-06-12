# =============================================================================
# ConvergenceEngine.ps1 — Moteur de convergence générique (Story 24.4)
# =============================================================================
# Module PARTAGEABLE (coeur cross-OS, contrainte n.5 du cahier des charges) :
# AUCUNE dépendance Windows ici — uniquement la machine d'états du contrat §5
# (strict/default/premier passage), l'isolation par item et les conventions de
# hash du rapport. Les handlers spécifiques OS (registre, SystemParametersInfo,
# chemins %LOCALAPPDATA%) vivent dans agent/windows/handlers/.
#
# Ce que fait Invoke-ConvergencePass :
#   - itère les items DANS L'ORDRE du payload serveur (AC epic / FR18 — jamais
#     d'ordre inventé, jamais de parallélisme), groupés par type (un type = un
#     verdict, le rapport exige des types UNIQUES — contrat §6) ;
#   - dispatch vers le handler enregistré par type ; type sans handler =
#     ignoré silencieusement + log DEBUG (contrat §8 : « ne touche pas ») ;
#   - try/catch PAR type : un échec produit {status: error, detail} et la
#     passe CONTINUE (AC epic isolation) ;
#   - applique la machine d'états §5 VERBATIM (cf. Resolve-ItemStatus) avec le
#     store applied-state injecté par l'appelant (per-user pour le compagnon) ;
#   - produit les items de rapport {type, status, hash[, detail]}.
#
# Conventions de hash du rapport (décision n° 7 / piège n° 6) :
#   - type `exclusive` : le hash d'item opaque du serveur, VERBATIM ;
#   - type `aggregate` : le serveur ne fournit PAS de hash d'ensemble — l'agent
#     construit une EMPREINTE déterministe : SHA-256 hex de la concaténation
#     des hashes opaques des items du type, dans l'ordre du payload serveur.
#     Ce n'est PAS un recalcul de hash d'item (interdit — le hash serveur reste
#     opaque) : c'est une empreinte d'agrégat sur des chaînes opaques, que le
#     serveur ne compare qu'au rapport PRÉCÉDENT (jamais à l'état compilé).
# =============================================================================

#Requires -Version 5.1
Set-StrictMode -Version Latest

# Borne du contrat §6 : `detail` ≤ 2000 caractères (report-endpoint.md).
$script:ConvergenceDetailMaxLength = 2000

<#
.SYNOPSIS
    Empreinte d'agrégat d'un type `aggregate` : SHA-256 hex (minuscules) de la
    concaténation des hashes opaques des items, dans l'ordre serveur.
.NOTES
    Déterministe par construction : mêmes items dans le même ordre = même
    empreinte — le serveur compare cette chaîne au rapport précédent
    (égalité opaque), `rapport identique = zéro événement` est préservé.
#>
function Get-AggregateHash {
    param([Parameter(Mandatory = $true)][AllowEmptyCollection()][object[]]$Items)

    $concatenated = (@($Items) | ForEach-Object { [string]$_.hash }) -join ''
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try {
        $bytes = $sha.ComputeHash([System.Text.Encoding]::ASCII.GetBytes($concatenated))
    } finally {
        $sha.Dispose()
    }

    return ([System.BitConverter]::ToString($bytes) -replace '-', '').ToLowerInvariant()
}

<#
.SYNOPSIS
    Machine d'états du contrat §5 — implémentée UNE fois, VERBATIM.
.DESCRIPTION
    Entrées : conformité réelle (le `test` du handler), mode de l'item,
    dernier-appliqué mémorisé (hash opaque ou $null = premier passage).
    Sortie : @{ Status; ShouldApply; ShouldPersist }.

    mode = strict :
      réel = cible → compliant ; réel ≠ cible → APPLIQUE → drift.
    mode = default :
      réel = cible                              → compliant ;
      réel ≠ cible ∧ dernier-appliqué = cible   → DÉRIVE HUMAINE → ne
                                                  réapplique PAS → drifted_allowed ;
      réel ≠ cible ∧ dernier-appliqué ≠ cible   → la cible a bougé → APPLIQUE → drift.
    Premier passage (dernier-appliqué $null) : traité comme
    `dernier-appliqué ≠ cible` — JAMAIS drifted_allowed sans mémoire (§5).

    ShouldPersist : la cible devient le dernier-appliqué après compliant ou
    apply réussi (§5 « persiste ») — y compris en strict (l'empreinte est
    persistée pour la traçabilité, décision n° 9, sans incidence de verdict).
    En drifted_allowed : rien à persister (dernier-appliqué = cible déjà).
#>
function Resolve-ItemStatus {
    param(
        [Parameter(Mandatory = $true)][bool]$IsCompliant,
        [Parameter(Mandatory = $true)][ValidateSet('strict', 'default')][string]$Mode,
        [AllowNull()][AllowEmptyString()][string]$LastAppliedHash,
        [Parameter(Mandatory = $true)][string]$TargetHash
    )

    if ($IsCompliant) {
        return @{ Status = 'compliant'; ShouldApply = $false; ShouldPersist = $true }
    }

    $humanDrift = ($Mode -eq 'default') -and
        (-not [string]::IsNullOrEmpty($LastAppliedHash)) -and
        ($LastAppliedHash -eq $TargetHash)

    if ($humanDrift) {
        return @{ Status = 'drifted_allowed'; ShouldApply = $false; ShouldPersist = $false }
    }

    return @{ Status = 'drift'; ShouldApply = $true; ShouldPersist = $true }
}

<#
.SYNOPSIS
    Une passe de convergence : items (ordre serveur) × handlers → items de
    rapport. Mute $AppliedState (le persister est la responsabilité de
    l'appelant — écriture atomique per-user côté compagnon).
.PARAMETER Items
    Items du contrat (PSCustomObject {type, semantics, mode, payload, hash}),
    DANS L'ORDRE du payload serveur (FR18).
.PARAMETER Handlers
    hashtable type → @{ Test = scriptblock(items[]) -> bool ;
                        Apply = scriptblock(items[]) }.
    `Test` répond « le réel correspond-il à la cible ? » ; `Apply` converge
    (idempotent). L'un comme l'autre peuvent lever — l'erreur est capturée
    PAR type et devient {status: error, detail}.
.PARAMETER AppliedState
    hashtable type → @{ hash; applied_at } (dernier-appliqué, contrat §5).
    Muté en place.
.PARAMETER LogAction
    scriptblock(level, message) optionnel — le moteur ne connaît aucun
    logger concret (portabilité).
#>
function Invoke-ConvergencePass {
    param(
        [AllowEmptyCollection()][object[]]$Items = @(),
        [Parameter(Mandatory = $true)][hashtable]$Handlers,
        [Parameter(Mandatory = $true)][hashtable]$AppliedState,
        [scriptblock]$LogAction = $null
    )

    $log = {
        param($Level, $Message)
        if ($null -ne $LogAction) { & $LogAction $Level $Message }
    }

    # Groupement par type en PRÉSERVANT l'ordre de première occurrence (ordre
    # serveur). Un type = un groupe = un verdict (types uniques au rapport §6).
    $groups = [ordered]@{}
    foreach ($item in @($Items)) {
        if ($null -eq $item -or
            -not $item.PSObject.Properties['type'] -or
            -not $item.PSObject.Properties['hash']) {
            & $log 'WARNING' 'Item sans type ou sans hash dans le payload : ignoré (enveloppe inattendue).'
            continue
        }
        $type = [string]$item.type
        if (-not $groups.Contains($type)) {
            $groups[$type] = [System.Collections.ArrayList]::new()
        }
        [void]$groups[$type].Add($item)
    }

    $reportItems = @()

    foreach ($type in @($groups.Keys)) {
        $typeItems = @($groups[$type])

        if (-not $Handlers.ContainsKey($type)) {
            # Contrat §8 : type sans handler = l'agent NE TOUCHE PAS à la
            # ressource et n'émet AUCUN statut pour elle.
            & $log 'DEBUG' "Type '$type' sans handler enregistré : ignoré (aucun statut émis)."
            continue
        }

        $first = $typeItems[0]
        $semantics = if ($first.PSObject.Properties['semantics']) { [string]$first.semantics } else { 'exclusive' }
        $mode = if ($first.PSObject.Properties['mode']) { [string]$first.mode } else { 'strict' }
        if ($mode -notin @('strict', 'default')) {
            # Mode inconnu (contrat futur ?) : posture sûre = strict.
            & $log 'WARNING' "Mode '$mode' inconnu pour le type '$type' : traité en strict."
            $mode = 'strict'
        }

        # Hash rapporté (décision n° 7) : exclusive = hash d'item verbatim ;
        # aggregate = empreinte déterministe des hashes opaques, ordre serveur.
        if ($semantics -eq 'aggregate') {
            $targetHash = Get-AggregateHash -Items $typeItems
        } else {
            if ($typeItems.Count -gt 1) {
                # Le compilateur serveur garantit UN item par type exclusive —
                # défense : le DERNIER fait foi (§3.1 « l'unique / le dernier »).
                & $log 'WARNING' "Type exclusif '$type' avec $($typeItems.Count) items : seul le dernier fait foi (contrat §3.1)."
            }
            $targetHash = [string]$typeItems[-1].hash
        }

        # Dernier-appliqué : entrée hydratée du JSON (PSCustomObject) ou posée
        # par une passe précédente du même process — les deux formes sont lues.
        $lastApplied = $null
        if ($AppliedState.ContainsKey($type) -and $null -ne $AppliedState[$type]) {
            $entry = $AppliedState[$type]
            if ($entry -is [hashtable]) {
                if ($entry.ContainsKey('hash')) { $lastApplied = [string]$entry['hash'] }
            } elseif ($entry.PSObject.Properties['hash']) {
                $lastApplied = [string]$entry.hash
            }
        }

        $handler = $Handlers[$type]
        $reportItem = $null
        try {
            # Contrat handler : Test retourne un booleen. Si un futur handler
            # pollue le pipeline avant son return (cmdlet sans | Out-Null), on
            # ne retient que le DERNIER objet emis — un cast [bool] direct sur
            # un array multi-elements leverait (review 24.4 #4).
            $isCompliant = [bool]((@(& $handler.Test $typeItems))[-1])
            $verdict = Resolve-ItemStatus -IsCompliant $isCompliant -Mode $mode `
                -LastAppliedHash $lastApplied -TargetHash $targetHash

            if ($verdict.ShouldApply) {
                & $handler.Apply $typeItems
            }
            if ($verdict.ShouldPersist) {
                $AppliedState[$type] = [pscustomobject]@{
                    hash       = $targetHash
                    applied_at = [DateTime]::UtcNow.ToString("yyyy-MM-dd'T'HH:mm:ssK")
                }
            }

            $reportItem = [ordered]@{
                type   = $type
                status = $verdict.Status
                hash   = $targetHash
            }
            & $log 'INFO' "Convergence '$type' (mode=$mode) : $($verdict.Status)."
        } catch {
            # AC epic isolation : un échec → error + detail pour CE type, la
            # passe et le rapport CONTINUENT. `detail` obligatoire non vide
            # sur error (contrat §6) — fallback explicite si le message est vide.
            $detail = [string]$_.Exception.Message
            if ([string]::IsNullOrWhiteSpace($detail)) {
                $detail = "echec du handler '$type' sans message"
            }
            if ($detail.Length -gt $script:ConvergenceDetailMaxLength) {
                $detail = $detail.Substring(0, $script:ConvergenceDetailMaxLength)
            }
            $reportItem = [ordered]@{
                type   = $type
                status = 'error'
                hash   = $targetHash
                detail = $detail
            }
            & $log 'ERROR' "Convergence '$type' en echec : $detail"
        }

        $reportItems += [pscustomobject]$reportItem
    }

    return @($reportItems)
}
