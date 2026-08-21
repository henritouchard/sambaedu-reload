<?php

declare(strict_types=1);

namespace App\Services\ControlHub;

use App\Enums\ControlHubArtifactPullStatus;
use App\Enums\ControlHubContractApplyStatus;
use App\Enums\ControlHubContractTarget;
use App\Enums\ControlHubEnforcementState;
use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\ControlHubConnection;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractItem;
use App\Models\WorkstationGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Story 39.2 (canal ③) — Émetteur de conformité SE5 → autorité amont (controlHub).
 *
 * PREMIER émetteur SE5 → amont du lien managé : construit et POST un rapport de
 * conformité **état-intégral** (`se5-contract-compliance/v1`) décrivant l'état
 * d'application de chaque item du contrat amont reçu (doctrine full-state, jamais
 * un delta). Ce service LIT l'état résolu (`controlhub_contracts` /
 * `controlhub_contract_items`, Epics 28-33) + les signaux d'override locaux
 * (`capability_assignments`, Epic 27/29).
 *
 * Il ne juge pas lui-même de l'application : le verdict est rendu à la réception,
 * par ceux qui ont essayé de poser l'item — `pull_status` pour le tirage du binaire,
 * `apply_status` pour l'assignation. Ce service les traduit dans le vocabulaire du
 * canal ③. Tant qu'il déduisait le statut du seul `enforcement_state`, il affirmait
 * à l'amont « appliqué » sans avoir rien vérifié.
 *
 * NE touche NI l'ingestion NI `StateCompiler` NI l'agent : chemin 100% SORTANT.
 *
 * **Découverte critique (mismatch de vocabulaire)** : le seul canal d'imposition
 * réellement câblé côté SE5 pour les capacités est `type='registry'` — la valeur
 * `type='capabilities'` documentée par la spec amont est un pseudo-canal MORT
 * sans adaptateur. Ce service **mirrore tel quel** le `type` stocké en base
 * (donc `'registry'`, lecture littérale de « clé naturelle miroir ») — le remap
 * éventuel `registry→capabilities` est un point de RATIFICATION à trancher avec le
 * BMAD controlHub, PAS un fix silencieux ici.
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central ». Vocabulaire « amont » / `Upstream` /
 * `ControlHub*`. [Source: prd-contrat-manage-se5.md#R3]
 */
final class ControlHubComplianceReportService
{
    /** Version de schéma du canal `se5-contract-compliance/v1` (égalité stricte côté amont). */
    private const SCHEMA_VERSION = '1.0';

    public function __construct(
        private readonly ControlHubApiClient $apiClient,
        private readonly ControlHubService $controlHubService,
        private readonly UpstreamLockResolver $lockResolver,
    ) {}

    /**
     * Construit l'enveloppe de conformité état-intégral, ou `null` s'il n'y a AUCUN
     * contrat amont actif (standalone OU lien `severed` — NFR-A1 : aucune émission
     * parasite). `items: []` (contrat actif mais 0 item non-`absent`) est un
     * résultat VALIDE, pas un cas d'annulation.
     *
     * @return array<string,mixed>|null
     */
    public function buildEnvelope(): ?array
    {
        $contract = ControlHubContract::active();

        if ($contract === null) {
            return null; // NFR-A1 : pas de contrat actif → pas de rapport.
        }

        // Horodatage unique du rapport : `reported_at` (garde de fraîcheur amont) et
        // `observed_at` de chaque item partagent le même instant de génération
        // (pas de grain d'observation par poste aujourd'hui — cf. Dev Notes).
        $now = now();
        $observedAt = $now->toIso8601String();

        $items = $contract->items()
            ->get()
            ->reject(fn (ControlHubContractItem $item): bool => $this->enforcementState($item) === ControlHubEnforcementState::Absent)
            ->map(fn (ControlHubContractItem $item): array => $this->mapItem($item, $observedAt))
            ->values()
            ->all();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'instance_id' => (string) config('controlHub.se4fs.instance_id'),
            'link_state' => 'active',
            'contract_received_at' => $contract->received_at?->toIso8601String(),
            'reported_at' => $now->toIso8601String(),
            'items' => $items,
        ];
    }

    /**
     * Construit puis émet le rapport vers l'amont (HTTPS, Bearer). Gardes (NFR-A1) :
     * aucun appel `ControlHubApiClient` si pas de contrat actif, pas de connexion
     * valide, ou pas de token. Le token n'apparaît JAMAIS dans un log.
     *
     * @return array{sent:bool,reason:string,http_status?:int,items?:int}
     */
    public function emit(): array
    {
        $envelope = $this->buildEnvelope();

        if ($envelope === null) {
            return ['sent' => false, 'reason' => 'no_active_contract'];
        }

        $connection = ControlHubConnection::current();

        if ($connection === null || ! $connection->isValid()) {
            return ['sent' => false, 'reason' => 'no_active_connection'];
        }

        $token = $this->controlHubService->getToken();

        if (empty($token)) {
            return ['sent' => false, 'reason' => 'no_token'];
        }

        // La BDD fait foi sur l'URL amont (saisie au handshake) : resync du client
        // au cas où le singleton porterait une URL obsolète (patron heartbeat).
        if ($connection->base_url) {
            $this->apiClient->setBaseUrl($connection->base_url);
        }

        $endpoint = $this->resolveEndpoint();
        $response = $this->apiClient->callEndpoint($endpoint, 'POST', $envelope, $token);

        // JAMAIS le token ni l'en-tête Authorization dans le log (NFR sécurité).
        Log::info('ControlHub Compliance — rapport de conformité émis', [
            'endpoint' => $endpoint,
            'items' => count($envelope['items']),
            'http_status' => $response->httpStatus,
            'success' => $response->isSuccessful(),
        ]);

        return [
            'sent' => $response->isSuccessful(),
            'reason' => $response->isSuccessful() ? 'ok' : 'http_error',
            'http_status' => $response->httpStatus,
            'items' => count($envelope['items']),
        ];
    }

    /**
     * Résout l'endpoint amont en substituant l'`instance_id` de l'instance.
     */
    private function resolveEndpoint(): string
    {
        $template = (string) config(
            'controlHub.endpoints.contract_compliance',
            '/api/sambaedu/contract-compliance/{instance_id}',
        );

        return str_replace('{instance_id}', (string) config('controlHub.se4fs.instance_id'), $template);
    }

    /**
     * Item du rapport — clé naturelle MIROIR stricte `(type, key, target_type,
     * target_label)` de l'item d'origine (aucune transformation de valeur), plus le
     * statut résolu et son `detail`.
     *
     * @return array<string,mixed>
     */
    private function mapItem(ControlHubContractItem $item, string $observedAt): array
    {
        [$status, $detail] = $this->resolveStatus($item);

        return [
            'type' => $item->type,
            'key' => $item->key,
            'target_type' => $this->targetType($item)->value,
            // `target_label` est TOUJOURS une chaîne ('' pour instance) — jamais null (NFR4).
            'target_label' => (string) ($item->target_label ?? ''),
            'status' => $status,
            'detail' => $detail,
            'observed_at' => $observedAt,
        ];
    }

    /**
     * Politique de mapping de statut par item.
     *
     * Le rapport dit d'abord ce qui BLOQUE, ensuite seulement ce qui s'applique :
     *   1. le pull du binaire (canal ④) a échoué ou n'a pas abouti → `error` / `pending` ;
     *   2. le réconciliateur d'assignations a rendu un verdict → il fait foi ;
     *   3. sinon, politique d'origine : `locked` → `applied` ; `permissive` +
     *      `registry` + instance → `overridden` s'il existe un override local actif ;
     *      tout autre `permissive` → `applied` (aucun mécanisme d'override câblé —
     *      pas de faux `overridden`).
     *
     * Le pull passe AVANT le verdict d'application parce qu'il en est la cause : un
     * fond d'écran non assigné parce que son image n'est pas arrivée doit rapporter
     * l'image manquante, pas l'assignation manquante.
     *
     * Un item dont `apply_status` est `null` relève d'un type qu'aucun
     * réconciliateur ne revendique (`agent_tools`, `registry`) : l'étape 3 s'applique
     * telle quelle, comme avant.
     *
     * @return array{0:string,1:?string} [status, detail]
     */
    private function resolveStatus(ControlHubContractItem $item): array
    {
        $pullVerdict = $this->pullVerdict($item);

        if ($pullVerdict !== null) {
            return $pullVerdict;
        }

        $applyStatus = $item->apply_status;

        if ($applyStatus instanceof ControlHubContractApplyStatus
            && $applyStatus !== ControlHubContractApplyStatus::Applied) {
            return [$applyStatus->value, $this->trimmedOrNull($item->apply_detail)];
        }

        $state = $this->enforcementState($item);

        if ($state === ControlHubEnforcementState::Locked) {
            return ['applied', null];
        }

        // À ce stade : Permissive (Absent déjà filtré en amont).
        $isRegistryInstance = $item->type === CapabilityProjection::MECHANISM_REGISTRY
            && $this->targetType($item) === ControlHubContractTarget::Instance;

        if ($isRegistryInstance) {
            $detail = $this->overrideDetail((string) $item->key);

            if ($detail !== null) {
                return ['overridden', $detail]; // override permissif local (FR24).
            }
        }

        return ['applied', null];
    }

    /**
     * Statut imposé par l'état du pull de binaire, ou `null` si le pull ne bloque rien
     * (binaire tiré, ou item sans artefact à tirer).
     *
     * @return array{0:string,1:?string}|null
     */
    private function pullVerdict(ControlHubContractItem $item): ?array
    {
        // Un item que le contrat ne décrit plus par un `artifact` n'a plus de binaire :
        // le `pull_status` qui traîne décrit une version antérieure de l'ordre, et
        // rapporter « en attente » d'un binaire que personne n'attend serait faux.
        if (($item->artifact_checksum ?? '') === '') {
            return null;
        }

        return match ($item->pull_status) {
            ControlHubArtifactPullStatus::Error => [
                'error',
                $this->trimmedOrNull($item->pull_error) ?? 'Échec du téléchargement du binaire imposé',
            ],
            ControlHubArtifactPullStatus::Pending => [
                'pending',
                'Binaire imposé pas encore tiré ni vérifié',
            ],
            default => null,
        };
    }

    /**
     * Chaîne non vide, ou `null` — le `detail` du rapport ne porte jamais de vide.
     */
    private function trimmedOrNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Détecte un override local ACTIF pour la clé registre d'un item `permissive`.
     * Retourne un libellé lisible (capacité + parc porteur) si au moins une ligne
     * `capability_assignments` existe pour une capacité dont une projection
     * `registry` matche la clé de l'item ; `null` sinon.
     *
     * Réutilise l'algorithme de normalisation de `UpstreamLockResolver` (aucune 3ᵉ
     * normalisation). `capability_assignments` n'a pas de modèle Eloquent → accès
     * `DB::table()`.
     */
    private function overrideDetail(string $itemKey): ?string
    {
        $capabilities = $this->lockResolver->capabilitiesForRegistryKey($itemKey);

        if ($capabilities->isEmpty()) {
            return null;
        }

        // Review 39.2 #3 — ordre DÉTERMINISTE : sans `ORDER BY`, `first()` dépend de
        // l'ordre physique de la table → le `detail` (donc un rapport état-intégral
        // censé être reproductible) pourrait varier d'un cycle à l'autre sans qu'aucun
        // état métier ne change, et une ligne non-`WorkstationGroup` « gagnante »
        // ferait perdre la mention du parc alors qu'un override par-parc coexiste. On
        // priorise donc `WorkstationGroup` (seul assignable porteur de libellé v1) puis
        // `id` pour une résolution stable.
        $assignment = DB::table('capability_assignments')
            ->whereIn('capability_id', $capabilities->pluck('id')->all())
            ->orderByRaw('CASE WHEN assignable_type = ? THEN 0 ELSE 1 END', [WorkstationGroup::class])
            ->orderBy('id')
            ->first();

        if ($assignment === null) {
            return null; // capacité candidate mais aucun override posé → pas d'override.
        }

        /** @var Capability|null $capability */
        $capability = $capabilities->firstWhere('id', (int) $assignment->capability_id)
            ?? $capabilities->first();

        $label = $capability !== null && $capability->label !== ''
            ? $capability->label
            : $itemKey;

        $target = $this->assignableLabel(
            (string) $assignment->assignable_type,
            (int) $assignment->assignable_id,
        );

        return $target !== null
            ? "Override permissif « {$label} » posé sur le parc « {$target} »"
            : "Override permissif « {$label} »";
    }

    /**
     * Libellé lisible d'une maille porteuse d'override. Seul `WorkstationGroup`
     * (geste UI v1 par-parc) est résolu ; les autres assignables (extensibles hors
     * UI v1) ne portent pas de libellé — le `detail` reste alors sans cible.
     */
    private function assignableLabel(string $assignableType, int $assignableId): ?string
    {
        if ($assignableType !== WorkstationGroup::class) {
            return null;
        }

        $group = WorkstationGroup::find($assignableId);

        if ($group === null) {
            return null;
        }

        $display = (string) ($group->display_name ?? '');

        return $display !== '' ? $display : (string) $group->name;
    }

    /**
     * `enforcement_state` normalisé en enum, que la valeur soit castée ou brute.
     */
    private function enforcementState(ControlHubContractItem $item): ControlHubEnforcementState
    {
        $raw = $item->enforcement_state;

        return $raw instanceof ControlHubEnforcementState
            ? $raw
            : ControlHubEnforcementState::from((string) $raw);
    }

    /**
     * `target_type` normalisé en enum, que la valeur soit castée ou brute.
     */
    private function targetType(ControlHubContractItem $item): ControlHubContractTarget
    {
        $raw = $item->target_type;

        return $raw instanceof ControlHubContractTarget
            ? $raw
            : ControlHubContractTarget::from((string) $raw);
    }
}
