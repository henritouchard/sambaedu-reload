<?php

declare(strict_types=1);

namespace App\Services\ControlHub;

use App\Enums\ControlHubContractTarget;
use App\Enums\ControlHubEnforcementState;
use App\Enums\ControlHubLinkState;
use App\Events\ControlHubContractChanged;
use App\Models\Application;
use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractItem;
use App\Models\ControlHubLinkAuditLog;
use App\Models\WorkstationGroup;
use App\Services\AppProfile\AppProfileService;
use App\Services\ControlHub\Data\ContractSeveranceResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Story 32.1 (FR7 + NFR5) — Réception du signal de RUPTURE du lien amont (controlHub).
 *
 * Service UNIQUE partagé par la commande artisan `controlhub:sever-link` et
 * l'endpoint controlHub authentifié (Q4). Il est le **miroir** de
 * {@see ControlHubContractIngestionService::ingest()} : là où l'ingestion pose
 * `link_state = active`, la rupture pose `link_state = severed`.
 *
 * **« Preuve + construction » (verdict d'investigation).** La LEVÉE des verrous et
 * la chute du bornage catalogue sont ACQUISES GRATUITEMENT : dès que
 * `link_state = severed`, {@see ControlHubContract::active()} renvoie `null` et TOUS
 * les consommateurs court-circuitent ({@see Resolution\UpstreamContractSource},
 * {@see UpstreamCatalogResolver}, {@see UpstreamLockResolver},
 * {@see \App\Policies\CapabilityPolicy}, tier `StateMaille::Upstream`). Ce service
 * ne RE-CONSTRUIT AUCUN déverrouillage ; il construit la VRAIE part de 32.1 :
 *   1. la **réception du signal** (`severed` n'était posé NULLE PART avant 32.1) ;
 *   2. la **conservation de l'état effectif COMPLET, déverrouillé** : à la rupture
 *      « l'état du parc reste identique à ce qu'il était avec le lien, en retirant
 *      les verrous » (Henri). On FIGE LOCALEMENT l'état effectif des canaux
 *      réellement imposés (cf. {@see self::materializeEffectiveValues()} et
 *      {@see self::materializeApplicationAssignments()}) AVANT de poser `severed` ;
 *   3. l'**audit NFR5** de la transition `active → severed` ;
 *   4. l'émission de {@see ControlHubContractChanged} (invalidation des
 *      mémoïsations par-conteneur, patron post-commit de l'ingestion).
 *
 * **M1 — matérialisation CAPABILITY-CENTRIC sur le VRAI canal `registry`.** Le SEUL
 * canal d'imposition câblé en prod est `registry` ({@see Resolution\RegistryUpstreamAdapter},
 * seul adaptateur enregistré dans `AgentServiceProvider`). Localement, tout le
 * registre dérive des CAPACITÉS (capability-first 27.12 — pas de store registre
 * brut). On NE matérialise donc PAS le pseudo-canal `type='capabilities'` (qui n'a
 * AUCUN adaptateur amont — canal mort) : pour chaque capacité VERROUILLÉE par
 * l'amont (détection par identité de clé registre, iso {@see UpstreamLockResolver}),
 * on recouvre la valeur de capacité imposée en INVERSANT la projection
 * {@see CapabilityProjection} (sens valeur-registre → valeur-capacité, à partir de
 * la valeur connue portée par l'item), et on l'écrit dans `capability_assignments`
 * à la maille du parc cible (patron `saveOverride()` 29.5). Le `permissif` est un
 * PLANCHER déjà battu par le défaut local → no-op (rien à conserver). Seuls les
 * `locked` sont matérialisés.
 *
 * **Apps — conservation de l'AFFECTATION (portée selon la cible, correctif #7).** Une
 * app `ordonnée` par l'amont (item `type='applications'`, 31.2) n'a, à la rupture,
 * qu'une ligne `Application` persistante (AC3) — son AFFECTATION venait de l'ordre
 * amont (levé via `active()` → null). On la conserve : pour un ordre `instance` on
 * pose `Application.is_parc_default` (défaut d'instance Broadcast 27.17 — couvre tous
 * les postes, même hors parc) ; pour un ordre `label` on projette une AFFECTATION
 * LOCALE par parc porteur (pivot `application_workstation_group`, via le chemin
 * canonique {@see AppProfileService::addApplicationsToWorkstationGroup()}). Un poste
 * qui ne recevait l'app QUE via l'ordre amont la CONSERVE après rupture.
 *
 * **Idempotence stricte (AC1/AC6)** : un signal sur une instance standalone (aucun
 * contrat actif) OU sur un contrat déjà `severed` est un **no-op total** — aucune
 * matérialisation, aucun audit, aucun event, aucune écriture.
 *
 * **NFR3 — standalone byte-identique** : sans contrat actif, `active()` → null,
 * retour {@see ContractSeveranceResult::noop()} immédiat (aucune table contrat
 * lue/écrite hors de ce no-op).
 *
 * **NFR7 — Postgres-only** : aucun AD / LdapRecord / samba-tool dans ce chemin.
 *
 * **Garde-fou (HORS scope)** : ce service NE touche JAMAIS `StateCompiler` /
 * `StateMaille` / le tier `Upstream`. La levée passe EXCLUSIVEMENT par
 * `active()` → null (déjà câblé partout).
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central ». Vocabulaire « amont » / `Upstream` /
 * `ControlHub*`. [Source: prd-contrat-manage-se5.md#R3]
 */
class ControlHubContractSeveranceService
{
    public function __construct(
        private readonly AppProfileService $appProfileService,
    ) {
    }

    /**
     * Reçoit le signal de rupture du lien amont et applique la transition
     * `active → severed` de façon TRACÉE et IDEMPOTENTE.
     *
     * @param  string  $origin  canal du signal ({@see ControlHubLinkAuditLog::ORIGIN_COMMAND}/`ORIGIN_API`)
     * @param  string|null  $actorLabel  acteur/origine dénormalisé (login refnum, clé controlHub, cli…)
     * @param  string|null  $reason  motif optionnel de la rupture
     */
    public function sever(
        string $origin,
        ?string $actorLabel = null,
        ?string $reason = null,
    ): ContractSeveranceResult {
        // NFR3 / idempotence : aucun contrat actif (standalone OU déjà severed) ⇒
        // no-op TOTAL (aucune écriture, aucun audit, aucun event). `active()` est le
        // chokepoint unique (filtre link_state = active) — un contrat déjà severed
        // n'est jamais retourné, donc un 2e signal est un no-op (AC1).
        $contract = ControlHubContract::active();
        if ($contract === null) {
            Log::info('ControlHubContractSeveranceService: no-op (aucun contrat amont actif)', [
                'origin' => $origin,
            ]);

            return ContractSeveranceResult::noop();
        }

        $result = DB::transaction(function () use ($contract, $origin, $actorLabel, $reason): ContractSeveranceResult {
            // 1. CONSERVATION de l'état effectif COMPLET, déverrouillé (M1), AVANT de
            //    poser `severed` : tant que `active()` voit encore le contrat, on FIGE
            //    LOCALEMENT (a) la valeur des capacités VERROUILLÉES via le vrai canal
            //    `registry`, (b) l'affectation des apps ORDONNÉES amont. Idempotent
            //    (un support local préexistant n'est jamais écrasé = AC3).
            $valuesMaterialized = $this->materializeEffectiveValues($contract);
            $applicationsAssigned = $this->materializeApplicationAssignments($contract);

            // 2. Compteurs récap pour l'audit (lus AVANT la bascule, sémantique « ce qui
            //    était imposé / conservé au moment de la rupture »). Correctif review #2 :
            //    `items_lifted` ne compte QUE les items réellement imposés (locked +
            //    permissive) — les `absent` n'imposent rien, ils sont exclus.
            $itemsLifted = $contract->items()
                ->whereIn('enforcement_state', [
                    ControlHubEnforcementState::Locked->value,
                    ControlHubEnforcementState::Permissive->value,
                ])
                ->count();
            $appsPreserved = Application::query()
                ->where('managed_by_control_hub', true)
                ->count();

            // 3. Transition d'état : le lien passe à `severed` (la levée des verrous +
            //    bornage catalogue tombe automatiquement via active() → null).
            $contract->link_state = ControlHubLinkState::Severed;
            $contract->save();

            // 4. AUDIT NFR5 (même transaction = atomicité acte ↔ trace, AC6). Une seule
            //    ligne par transition ; un re-signal ne réécrit RIEN (no-op en amont).
            ControlHubLinkAuditLog::log(
                contractId: $contract->id,
                fromState: ControlHubLinkState::Active->value,
                toState: ControlHubLinkState::Severed->value,
                origin: $origin,
                actorLabel: $actorLabel,
                reason: $reason,
                summary: [
                    'items_lifted' => $itemsLifted,
                    'apps_preserved' => $appsPreserved,
                    'values_materialized' => $valuesMaterialized,
                    'applications_assigned' => $applicationsAssigned,
                ],
            );

            return new ContractSeveranceResult(
                severed: true,
                contractId: (int) $contract->id,
                itemsLifted: $itemsLifted,
                appsPreserved: $appsPreserved,
                valuesMaterialized: $valuesMaterialized,
                applicationsAssigned: $applicationsAssigned,
            );
        });

        // 5. Event APRÈS commit (hors transaction) — patron `ingest()` : invalide les
        //    mémoïsations par-conteneur des résolveurs amont. Un listener queued ne peut
        //    pas s'exécuter avant que la bascule soit committée.
        ControlHubContractChanged::dispatch($contract->refresh());

        Log::info('ControlHubContractSeveranceService: lien amont rompu (active → severed)', [
            'origin' => $origin,
            'result' => $result->toArray(),
        ]);

        return $result;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // M1 — Conservation de la valeur effective : canal `registry` réel
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Matérialise dans le store local (`capability_assignments`) la valeur courante
     * effective des capacités VERROUILLÉES par l'amont via le VRAI canal `registry`,
     * pour que le refnum conserve EXACTEMENT ce qui tournait — désormais
     * éditable/supprimable (FR7).
     *
     * **Capability-centric (M1)** :
     *  - on lit les items `type='registry'`, `enforcement_state='locked'` (le
     *    `permissif` est un PLANCHER déjà battu par le défaut local → no-op ;
     *    l'`absent` n'impose rien) — cible `instance` ET `label` ;
     *  - pour chaque item, on retrouve par IDENTITÉ DE CLÉ registre
     *    (`strtolower(hive|path|name)`, iso {@see UpstreamLockResolver}) la/les
     *    capacité(s) locale(s) qui projettent cette clé ;
     *  - on RECOUVRE la valeur de capacité imposée en INVERSANT la map de projection
     *    de la clé ({@see CapabilityProjection::$spec}) à partir de la valeur
     *    registre CONNUE portée par l'item (Henri : toujours recouvrable en
     *    pratique) ;
     *  - on l'écrit en `capability_assignments` à la maille du parc cible (patron
     *    `saveOverride()` 29.5).
     *
     * **Cible (correctif review #7 — décision Henri 2026-06-30)** :
     *  - `target_type = instance` → on FIGE la valeur recouvrée dans le DÉFAUT
     *    D'INSTANCE de la capacité (`capabilities.default_value`, patron `saveDefault()`
     *    des parc-defaults). Une seule écriture, couvre UNIFORMÉMENT tous les postes
     *    (même hors de tout parc), éditable sur la page des défauts. Cohérent avec
     *    « c'était imposé partout ». PAS d'override par groupe (l'ancienne portée
     *    « tous les parcs actifs incl. salles physiques » était over-wide).
     *  - `target_type = label` → chaque `WorkstationGroup` portant ce
     *    `controlhub_label` (rattachement par NOM, iso 30.2/30.4) reçoit un override
     *    `capability_assignments` (patron `saveOverride()` 29.5). Inchangé.
     *
     * **Idempotent (AC3/AC4)** :
     *  - `instance` : poser le défaut est idempotent — si `default_value` vaut déjà la
     *    valeur imposée, no-op (sinon on l'écrit ; figer = poser ce défaut, même si un
     *    défaut différent était posé : sous le verrou la valeur effective d'instance
     *    ÉTAIT la valeur imposée partout). Les overrides locaux par parc PLUS
     *    SPÉCIFIQUES (`capability_assignments`) restent intacts et continuent de primer
     *    sur le défaut (`effective = assignment.value ?? default_value`) — c'est AC3.
     *  - `label` : une assignation locale préexistante (cap × parc) est un SUPPORT
     *    LOCAL conservé tel quel — JAMAIS écrasée. On n'écrit que pour les couples
     *    sans ligne locale.
     *
     * **Filet de sécurité (jamais censé se déclencher)** : si aucune capacité locale
     * ne projette la clé verrouillée, OU si la valeur de capacité n'est pas
     * recouvrable (clé à valeur littérale, non ambiguë) → `Log::warning` NOMINATIF +
     * `continue` (aucune exception, aucune rupture échouée). Limite documentée 32.x.
     *
     * @return int nombre de lignes `capability_assignments` matérialisées
     */
    private function materializeEffectiveValues(ControlHubContract $contract): int
    {
        /** @var Collection<int,ControlHubContractItem> $items */
        $items = $contract->items()
            ->where('type', CapabilityProjection::MECHANISM_REGISTRY)
            ->where('enforcement_state', ControlHubEnforcementState::Locked->value)
            ->whereNotNull('value')
            ->get();

        if ($items->isEmpty()) {
            return 0;
        }

        // Index `exclusiveKey` → list<{capability, specKey}> de TOUTES les capacités
        // projetant une clé registre (capability-first : seul store local du registre).
        $index = $this->buildRegistryKeyIndex();
        if ($index === []) {
            return 0;
        }

        $materialized = 0;

        foreach ($items as $item) {
            $exclusive = $this->normalizeItemKey((string) $item->key);
            $matches = $index[$exclusive] ?? [];

            if ($matches === []) {
                // Filet : aucune capacité locale ne projette cette clé verrouillée —
                // pas de store local où conserver la valeur (capability-first).
                Log::warning('ControlHubContractSeveranceService: item registry verrouillé sans capacité locale correspondante (valeur effective non matérialisée)', [
                    'item_key' => (string) $item->key,
                ]);

                continue;
            }

            // Correctif #7 : la portée diverge selon le type de cible. `instance` →
            // défaut d'instance (une écriture par capacité) ; `label` → override par
            // parc porteur (lus une seule fois, puis réutilisés pour chaque match).
            $isInstance = $item->target_type === ControlHubContractTarget::Instance;
            $groupIds = $isInstance ? [] : $this->targetGroupIds($item);

            if (! $isInstance && $groupIds === []) {
                // Label sans parc porteur : rien à matérialiser.
                continue;
            }

            foreach ($matches as $match) {
                /** @var Capability $capability */
                $capability = $match['capability'];
                $recovered = $this->recoverCapabilityValue($match['specKey'], (string) $item->value);

                if ($recovered === null) {
                    // Filet (Henri : la valeur amont correspond toujours à un état de
                    // capacité connu → jamais censé arriver). Clé à valeur littérale
                    // (non ambiguë) : la même valeur est de toute façon ré-émise par
                    // le défaut local, rien à conserver.
                    Log::warning('ControlHubContractSeveranceService: valeur de capacité non recouvrable depuis la valeur registre amont (matérialisation ignorée — filet de sécurité)', [
                        'capability_key' => (string) $capability->key,
                        'item_key' => (string) $item->key,
                        'registry_value' => (string) $item->value,
                    ]);

                    continue;
                }

                if ($isInstance) {
                    // Décision Henri : figer dans le DÉFAUT D'INSTANCE (couvre tous les
                    // postes, même hors parc). Les overrides par parc plus spécifiques
                    // priment toujours (AC3).
                    $materialized += $this->materializeInstanceDefault($capability, $recovered);

                    continue;
                }

                foreach ($groupIds as $groupId) {
                    $materialized += $this->materializeAssignment((int) $capability->id, $groupId, $recovered);
                }
            }
        }

        return $materialized;
    }

    /**
     * Écrit une assignation locale de capacité pour un parc, SANS jamais écraser un
     * support local préexistant (AC3).
     *
     * Correctif review #1 (TOCTOU) : `insertOrIgnore()` (robuste vs la contrainte
     * UNIQUE `capability_assignment_unique` en PG ; invisible en SQLite). Le compteur
     * est basé sur le nb de lignes RÉELLEMENT insérées.
     *
     * @return int 1 si une ligne a été insérée, 0 si support local conservé / course perdue
     */
    private function materializeAssignment(int $capabilityId, int $groupId, string $value): int
    {
        $hasLocalSupport = DB::table('capability_assignments')
            ->where('capability_id', $capabilityId)
            ->where('assignable_type', WorkstationGroup::class)
            ->where('assignable_id', $groupId)
            ->exists();

        if ($hasLocalSupport) {
            // Support local préexistant (override refnum) : conservé tel quel (AC3).
            return 0;
        }

        return DB::table('capability_assignments')->insertOrIgnore([
            'capability_id' => $capabilityId,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $groupId,
            'value' => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Fige la valeur recouvrée dans le DÉFAUT D'INSTANCE de la capacité
     * (`capabilities.default_value`, patron `saveDefault()` des parc-defaults) pour un
     * verrou `target_type = instance` (correctif review #7, décision Henri 2026-06-30).
     *
     * Couvre UNIFORMÉMENT tous les postes (même hors de tout parc), éditable sur la
     * page des défauts. Idempotent : si le défaut vaut déjà la valeur imposée → no-op
     * (0). Sinon on l'écrit (1) — figer = poser ce défaut (sous le verrou la valeur
     * effective d'instance ÉTAIT la valeur imposée partout). Les overrides locaux par
     * parc plus spécifiques (`capability_assignments`) restent intacts et continuent de
     * primer (`effective = assignment.value ?? default_value`) — AC3.
     *
     * @return int 1 si le défaut a été (re)posé, 0 si déjà égal (no-op idempotent)
     */
    private function materializeInstanceDefault(Capability $capability, string $value): int
    {
        if ((string) $capability->default_value === $value) {
            // Déjà figé à la valeur imposée : no-op idempotent.
            return 0;
        }

        $capability->default_value = $value;
        $capability->save();

        return 1;
    }

    /**
     * Index des clés registre projetées par les capacités locales :
     * `exclusiveKey(hive|path|name)` → list<{capability, specKey}>. Capability-first :
     * le registre n'a PAS de store brut, toute clé locale dérive d'une projection.
     *
     * @return array<string, list<array{capability: Capability, specKey: array<string,mixed>}>>
     */
    private function buildRegistryKeyIndex(): array
    {
        $capabilities = Capability::query()
            ->whereHas('projections', function ($q): void {
                $q->where('mechanism', CapabilityProjection::MECHANISM_REGISTRY);
            })
            ->with(['projections' => function ($q): void {
                $q->where('mechanism', CapabilityProjection::MECHANISM_REGISTRY);
            }])
            ->get();

        $index = [];

        foreach ($capabilities as $capability) {
            foreach ($capability->projections as $projection) {
                foreach ($this->specKeys($projection) as $specKey) {
                    $exclusive = $this->exclusiveKey(
                        (string) ($specKey['hive'] ?? ''),
                        (string) ($specKey['path'] ?? ''),
                        (string) ($specKey['name'] ?? ''),
                    );
                    $index[$exclusive][] = [
                        'capability' => $capability,
                        'specKey' => $specKey,
                    ];
                }
            }
        }

        return $index;
    }

    /**
     * Recouvre la valeur de CAPACITÉ imposée à partir de la valeur de REGISTRE connue
     * portée par l'item amont, en INVERSANT la map de projection de la clé
     * ({@see \App\Services\Agent\Providers\AbstractCapabilityStateProvider::resolveKeyValue()},
     * sens capacité→registre). La map est `{valeurCapacité: donnéeRegistre}`
     * (ex. `{on:1, off:0}`) ; l'inversion cherche la valeur de capacité dont la
     * donnée registre projetée égale la valeur de l'item.
     *
     * @param  array<string,mixed>  $specKey  clé concrète de la `spec` (hive/path/name/type/value)
     * @return string|null  valeur de capacité recouvrée, ou null si non recouvrable (filet)
     */
    private function recoverCapabilityValue(array $specKey, string $registryValue): ?string
    {
        $raw = $specKey['value'] ?? null;

        // Map valeur-capacité → donnée (objet associatif, NON liste) : on l'inverse.
        if (is_array($raw) && ! array_is_list($raw)) {
            foreach ($raw as $capabilityValue => $mappedRegistryValue) {
                // Correctif review #8 : garde `is_scalar` avant le cast — une valeur de
                // map non-scalaire (array/object dans une spec malformée) provoquerait
                // un TypeError fatal en PHP 8 strict. On ignore l'entrée et on continue.
                if (! is_scalar($mappedRegistryValue)) {
                    continue;
                }

                if ((string) $mappedRegistryValue === $registryValue) {
                    return (string) $capabilityValue;
                }
            }

            return null;
        }

        // Littéral (scalaire ou liste MULTI_SZ) : la clé est émise quelle que soit la
        // valeur de capacité → aucune valeur de capacité à recouvrer (et la même
        // valeur est de toute façon ré-émise par le défaut local → aucune perte).
        return null;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Apps — matérialisation de l'AFFECTATION locale (état identique conservé)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Projette chaque app ORDONNÉE par l'amont (item `type='applications'`, 31.2 —
     * `locked` ET `permissive` signifient « app présente », aggregate) en AFFECTATION
     * LOCALE par parc cible, pour qu'un poste qui ne la recevait QUE via l'ordre amont
     * la CONSERVE après rupture (l'ordre amont tombe via `active()` → null).
     *
     * Réutilise le chemin d'affectation CANONIQUE 15.4
     * ({@see AppProfileService::addApplicationsToWorkstationGroup()}, pivot
     * `application_workstation_group` lu par
     * {@see \App\Wpkg\Deployment\Services\WorkstationPackagesResolver}) :
     *  - idempotent (`syncWithoutDetaching` — pas de doublon si déjà affectée) ;
     *  - invalide le cache resolver par-hôte (`WorkstationGroupApplicationsChanged`) ;
     *  - ses gardes 29.1 (délégation) / 31.1 (bornage catalogue) sont des no-op hors
     *    session web authentifiée (`Auth::check()` false en commande / endpoint
     *    controlHub) — l'action est SYSTÈME, pas refnum.
     *
     * **Portée (correctif review #7 — décision Henri 2026-06-30)** : iso capacités, on
     * utilise le mécanisme « défaut d'instance » quand il existe.
     *  - `instance` → on pose `Application.is_parc_default = true` (couche Broadcast
     *    27.17, analogue du défaut de capacité : l'app est appliquée par défaut à TOUS
     *    les postes via {@see ApplicationsStateProvider}, même hors parc). UNE écriture,
     *    PAS d'affectation par groupe → on n'itère plus les salles physiques (ce qui
     *    gonflait le compteur et étalait des affectations over-wide, finding #7).
     *  - `label` → affectation locale par parc portant le `controlhub_label` (pivot
     *    `application_workstation_group`). Inchangé.
     *
     * Ne touche PAS le contrat agent figé (payload `applications {app_id,name}`
     * inchangé) ni la ligne `Application` au-delà du flag `is_parc_default` (déjà
     * conservée, AC3).
     *
     * @return int nombre d'affectations matérialisées (défauts d'instance posés +
     *             affectations app↔parc NOUVELLEMENT créées, sans double comptage)
     */
    private function materializeApplicationAssignments(ControlHubContract $contract): int
    {
        /** @var Collection<int,ControlHubContractItem> $items */
        $items = $contract->items()
            ->where('type', Application::TYPE_APPLICATIONS)
            ->whereIn('enforcement_state', [
                ControlHubEnforcementState::Locked->value,
                ControlHubEnforcementState::Permissive->value,
            ])
            ->get();

        if ($items->isEmpty()) {
            return 0;
        }

        $assigned = 0;

        foreach ($items as $item) {
            // `key == applications.app_id` (31.2, pont au niveau ENSEMBLE — jamais un
            // id de pivot/scope).
            $appId = (string) $item->key;
            $application = Application::query()->where('app_id', $appId)->first();

            if ($application === null) {
                // L'app ordonnée n'a pas (encore) de ligne Application locale : rien à
                // affecter. La matérialisation de la LIGNE relève de 31.3 /
                // `controlhub:provision-ordered-apps` (la rupture ne crée pas
                // l'inventaire) — filet nominatif.
                Log::warning("ControlHubContractSeveranceService: ordre d'install amont sans ligne Application locale (affectation non matérialisée)", [
                    'app_id' => $appId,
                ]);

                continue;
            }

            if ($item->target_type === ControlHubContractTarget::Instance) {
                // Correctif #7 : défaut d'instance app (Broadcast 27.17), pas une
                // affectation par groupe — couvre tous les postes (même hors parc).
                $assigned += $this->materializeInstanceAppDefault($application);

                continue;
            }

            foreach ($this->targetGroupIds($item) as $groupId) {
                $attached = $this->appProfileService->addApplicationsToWorkstationGroup(
                    $groupId,
                    [(int) $application->id],
                );
                $assigned += count($attached);
            }
        }

        return $assigned;
    }

    /**
     * Fige une app ORDONNÉE amont `target_type = instance` en DÉFAUT D'INSTANCE
     * (`Application.is_parc_default = true`, couche Broadcast 27.17) pour qu'elle reste
     * appliquée par défaut à TOUS les postes après rupture (correctif review #7).
     *
     * Idempotent : si l'app est déjà `is_parc_default` → no-op (0). N'altère AUCUN
     * autre champ de la ligne `Application` (status, recette, `managed_by_control_hub`).
     *
     * @return int 1 si le flag a été posé, 0 si déjà posé (no-op idempotent)
     */
    private function materializeInstanceAppDefault(Application $application): int
    {
        if ($application->is_parc_default === true) {
            return 0;
        }

        $application->is_parc_default = true;
        $application->save();

        return 1;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Ciblage commun (capacités + apps)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Ids des `WorkstationGroup` PORTEURS d'un item ciblé par LABEL (actifs, non
     * archivés, `controlhub_label` = label de l'item).
     *
     * **Correctif review #7** : ne traite QUE le cas `label`. Le cas `instance` est
     * désormais géré en amont par les appelants via le DÉFAUT D'INSTANCE
     * (`capabilities.default_value` / `Application.is_parc_default`) — il ne passe plus
     * jamais par un balayage de tous les parcs actifs (qui incluait les salles
     * physiques : portée over-wide + compteur gonflé). Un item `instance` reçu ici
     * (chemin non attendu) renvoie `[]` (défensif).
     *
     * @return list<int>
     */
    private function targetGroupIds(ControlHubContractItem $item): array
    {
        if ($item->target_type !== ControlHubContractTarget::Label) {
            return [];
        }

        $label = (string) $item->target_label;
        if ($label === '') {
            // Garde-fou (iso 30.4) : un label vide ne cible aucun parc identifiable.
            return [];
        }

        return WorkstationGroup::query()
            ->whereNull('archived_at')
            ->where('controlhub_label', $label)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Clés concrètes d'une projection registry (`spec.keys[]`), défensif (iso
     * {@see UpstreamLockResolver::specKeys()}).
     *
     * @return list<array<string,mixed>>
     */
    private function specKeys(CapabilityProjection $projection): array
    {
        $spec = $projection->spec;
        $keys = is_array($spec) && isset($spec['keys']) && is_array($spec['keys'])
            ? $spec['keys']
            : [];

        return array_values(array_filter($keys, 'is_array'));
    }

    /**
     * Normalise une clé d'item amont `hive|path|name[|type]` en `exclusiveKey`
     * (`strtolower(hive|path|name)`), iso {@see UpstreamLockResolver::normalizeItemKey()}.
     */
    private function normalizeItemKey(string $key): string
    {
        $segments = explode('|', $key);

        return $this->exclusiveKey(
            $segments[0] ?? '',
            $segments[1] ?? '',
            $segments[2] ?? '',
        );
    }

    /**
     * Identité de clé registre EXCLUSIVE, alignée à l'octet sur
     * {@see \App\Services\Agent\Providers\AbstractCapabilityStateProvider::exclusiveKey()} :
     * `strtolower(hive).'|'.strtolower(path).'|'.strtolower(name)`.
     */
    private function exclusiveKey(string $hive, string $path, string $name): string
    {
        return strtolower($hive).'|'.strtolower($path).'|'.strtolower($name);
    }
}
