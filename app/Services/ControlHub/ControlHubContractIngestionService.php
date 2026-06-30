<?php

declare(strict_types=1);

namespace App\Services\ControlHub;

use App\Enums\ControlHubContractTarget;
use App\Enums\ControlHubEnforcementState;
use App\Enums\ControlHubLabelMode;
use App\Enums\ControlHubLinkState;
use App\Events\ControlHubContractChanged;
use App\Exceptions\ControlHub\InvalidUpstreamContractException;
use App\Exceptions\ControlHub\UnsupportedSchemaVersionException;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractCatalogApp;
use App\Models\ControlHubContractImposedGroup;
use App\Models\ControlHubContractItem;
use App\Models\ControlHubContractLabel;
use App\Services\ControlHub\Data\ContractIngestionResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Story 28.2 — Ingestion idempotente du contrat amont (controlHub).
 * Story 33.1 — Schéma d'ÉCHANGE versionné : le payload déclare une `schema_version`
 * (racine) ; l'ingestion la **négocie** ({@see ControlHubContractSchema::negotiate()}) et
 * **enregistre** la version retenue sur le contrat (colonne `schema_version`). Format figé
 * dans l'artefact partagé _bmad-output/planning-artifacts/schema-echange-controlhub-se5.md
 * (source unique pointée par les deux BMAD — R2). Un payload **conforme** (version supportée) ou
 * **sans version** (défaut = version courante, rétro-compat 28.2) est accepté ; une version
 * **déclarée non supportée** est **rejetée** (Story 33.2 — {@see UnsupportedSchemaVersionException}
 * propagée par la négociation, en phase de validation pure ⇒ zéro écriture, état inchangé).
 *
 * Reçoit un payload de contrat (émis par l'autorité amont) et le persiste de façon idempotente :
 *
 * - **Upsert** des 4 agrégats enfants (items, labels, groupes imposés, apps catalogue)
 *   sur les clés naturelles de la Story 28.1, puis **prune** des enfants disparus
 *   (réconciliation « désir d'état » : full replace par contrat).
 * - **Normalisation `null → ''`** de `target_label` avant écriture : la clé naturelle 28.1
 *   repose sur `target_label NOT NULL DEFAULT ''` ; sans normalisation, le cas dominant
 *   `target_type=instance` recasse l'idempotence (NULL ≠ NULL en PG/SQLite). [HANDOFF 28.1 #1]
 * - **Validation des enums + cohérence de cible** avant toute écriture ⇒ rollback total
 *   en cas de payload invalide (il n'existe aucun `CHECK` DB). [HANDOFF 28.1 #3]
 * - **Intégrité référentielle** `imposed_groups.label_name → label déclaré` avant écriture
 *   (un `label_name` non-nul orphelin est rejeté ; rollback total). [Story 30.1, FR9]
 * - **Singleton** « au plus un contrat actif par instance » : réutilise le contrat actif
 *   existant plutôt que d'en créer un second. [HANDOFF 28.1 #2]
 * - Passage du lien à `active` + `received_at = now()` **uniquement** sur création ou mutation.
 * - **No-op fonctionnel** (NFR4) : une réception identique n'écrit rien et n'émet aucun
 *   événement {@see ControlHubContractChanged}.
 *
 * Ce service est le **seul** écrivain des tables `controlhub_contract_*` (NFR3). Il ne lit
 * ni n'écrit aucune autre table, ne touche pas `StateCompiler` (→ Story 28.3), et l'événement
 * émis reste **sans listener** en 28.2 (comportement standalone strictement inchangé).
 *
 * Format de payload accepté (schéma d'échange versionné — Story 33.1) :
 * <code>
 * [
 *   'schema_version' => '1.0', // optionnel ; absent ⇒ version courante (rétro-compat 28.2)
 *   'items'          => [['type'=>'capabilities','key'=>'cap_x','value'=>'on',
 *                         'enforcement_state'=>'locked','target_type'=>'instance',
 *                         'target_label'=>null], ...],
 *   'labels'         => [['name'=>'salle-info','mode'=>'free'], ...],
 *   'imposed_groups' => [['name'=>'parc-term','label_name'=>'salle-info'], ...],
 *   'catalog_apps'   => [['app_key'=>'firefox','display_name'=>'Firefox'], ...],
 * ]
 * </code>
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » dans ce service, ses méthodes, ses messages.
 * Vocabulaire imposé : « amont » / `ControlHub*` / `authority` / `upstream`.
 * [Source: prd-contrat-manage-se5.md#R3]
 */
class ControlHubContractIngestionService
{
    /**
     * Ingère un payload de contrat amont de façon idempotente.
     *
     * Le `link_state` n'est **jamais** lu du payload : à la réception, le lien passe à
     * `active` par définition. La rupture (`severed`) relève d'Epic 32 (hors scope).
     *
     * Story 33.1 — La `schema_version` racine est négociée et enregistrée sur le contrat. Elle
     * participe au calcul de mutation : réception identique (même version) = no-op total (NFR4) ;
     * changement de version supportée sur contenu sinon identique = mutation (event émis 1×).
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidUpstreamContractException si le payload est hors domaine (rollback total)
     * @throws UnsupportedSchemaVersionException si la `schema_version` déclarée est non supportée
     *                                           (Story 33.2 — rejet en validation pure, état inchangé)
     */
    public function ingest(array $payload): ContractIngestionResult
    {
        // 0. Négociation de la VERSION du schéma d'ÉCHANGE — AVANT toute validation de CONTENU.
        // Story 33.1/33.2 — phase de validation PURE (aucune écriture, AVANT DB::transaction,
        // cohérent avec le rollback total 28.2) : version supportée → elle-même ; absente → version
        // courante (Q1=A, rétro-compat 28.2) ; version DÉCLARÉE non supportée →
        // UnsupportedSchemaVersionException (Story 33.2) LAISSÉE SE PROPAGER (pas de try/catch) — la
        // levée précède toute écriture ⇒ état inchangé.
        // Review 33.2 (#2) — la version est négociée AVANT les `normalizeX()` : un payload émis sous
        // une version non supportée ne doit PAS être interprété sous les règles de la version
        // courante. Sinon un contenu légal dans une future version mais hors-domaine en v1.0 lèverait
        // `InvalidUpstreamContractException` (CONTENU) au lieu d'`UnsupportedSchemaVersionException`
        // (VERSION), masquant la vraie cause (AC#5). La négociation est pure (O(1), zéro DB).
        // Review 33.2 (#5) — tout scalaire numérique DÉCLARÉ est coercé en chaîne pour être négocié :
        // un `schema_version` float JSON (ex. 2.0) ne doit PAS retomber sur `null`→version courante
        // (fausse ACCEPTATION silencieuse d'une version incompatible, viole AC#1). Coercé, il est
        // rejeté en égalité stricte comme un int/string non supporté. (array/bool/objet restent
        // traités comme absents — non couverts par 33.2.)
        // Cf. artefact partagé _bmad-output/planning-artifacts/schema-echange-controlhub-se5.md.
        $declaredVersion = $payload['schema_version'] ?? null;
        $declaredVersion = is_string($declaredVersion) || is_int($declaredVersion) || is_float($declaredVersion)
            ? (string) $declaredVersion
            : null;
        $schemaVersion = ControlHubContractSchema::negotiate($declaredVersion);

        // 1. Normalisation + validation PURE du CONTENU (aucune écriture) — garantit le rollback total
        //    (AC #6) : une valeur hors domaine lève l'exception AVANT d'ouvrir la transaction.
        $items = $this->normalizeItems($payload['items'] ?? []);
        $labels = $this->normalizeLabels($payload['labels'] ?? []);
        $imposedGroups = $this->normalizeImposedGroups($payload['imposed_groups'] ?? []);
        $catalogApps = $this->normalizeCatalogApps($payload['catalog_apps'] ?? []);

        // Story 30.1 — Durcissement réception (intégrité référentielle) : un groupe imposé
        // « avec son label associé » (FR9) présuppose que ce label fait partie du vocabulaire
        // reçu. Le cross-check exige l'ensemble des labels normalisés ; il s'exécute donc ICI,
        // après normalisation et AVANT la transaction (calque du patron cohérence-cible de
        // normalizeItems) → un payload incohérent ne provoque AUCUNE écriture partielle.
        $this->assertImposedGroupLabelsDeclared($labels, $imposedGroups);

        $result = new ContractIngestionResult();
        $result->schemaVersion = $schemaVersion;

        Log::info('ControlHubContractIngestionService: ingestion started', [
            'items' => count($items),
            'labels' => count($labels),
            'imposed_groups' => count($imposedGroups),
            'catalog_apps' => count($catalogApps),
        ]);

        // 2. Écritures dans une seule transaction (rollback en cas d'erreur DB).
        DB::transaction(function () use (
            $items,
            $labels,
            $imposedGroups,
            $catalogApps,
            $schemaVersion,
            $result,
        ): void {
            $contract = $this->resolveActiveContract($result, $schemaVersion);
            $mutated = $result->contractCreated;

            $mutated = $this->reconcileChildren(
                $contract->id,
                ControlHubContractItem::class,
                $items,
                $result->items,
            ) || $mutated;

            $mutated = $this->reconcileChildren(
                $contract->id,
                ControlHubContractLabel::class,
                $labels,
                $result->labels,
            ) || $mutated;

            $mutated = $this->reconcileChildren(
                $contract->id,
                ControlHubContractImposedGroup::class,
                $imposedGroups,
                $result->imposedGroups,
            ) || $mutated;

            $mutated = $this->reconcileChildren(
                $contract->id,
                ControlHubContractCatalogApp::class,
                $catalogApps,
                $result->catalogApps,
            ) || $mutated;

            // Story 33.1 — La version de schéma fait partie de l'état du contrat racine : sur un
            // contrat RÉUTILISÉ, un changement de version (contenu sinon identique) est une mutation
            // légitime (AC #5). À l'identique (même version), la comparaison est fausse ⇒ aucune
            // écriture déclenchée par la version (no-op 28.2 préservé — AC #4 / NFR4). On n'écrit
            // donc JAMAIS schema_version inconditionnellement : il est intégré au calcul de $mutated.
            $versionChanged = ! $result->contractCreated && $contract->schema_version !== $schemaVersion;
            $mutated = $mutated || $versionChanged;

            // Le lien, received_at et schema_version ne sont rafraîchis QUE sur mutation d'un
            // contrat réutilisé (no-op préservé : une réception identique ne touche aucun timestamp
            // ni la version). À la création, la version a déjà été posée par resolveActiveContract().
            if ($mutated && ! $result->contractCreated) {
                $contract->link_state = ControlHubLinkState::Active;
                $contract->received_at = now();
                $contract->schema_version = $schemaVersion;
                $contract->save();
            }

            $result->mutated = $mutated;
            $result->contractId = $contract->id;
        });

        // Événement de changement émis EXACTEMENT une fois sur mutation (NFR4 : jamais sur no-op),
        // APRÈS le commit (hors transaction) : un futur listener synchrone (28.3, StateCompiler) ne
        // peut donc pas faire rollback de l'ingestion validée, ni un listener queued s'exécuter avant
        // que les écritures soient committées.
        if ($result->mutated) {
            ControlHubContractChanged::dispatch(ControlHubContract::find($result->contractId));
        }

        Log::info('ControlHubContractIngestionService: ingestion completed', [
            'result' => $result->toArray(),
        ]);

        return $result;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Résolution du contrat racine (singleton « ≤ 1 contrat actif »)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Résout le contrat à mettre à jour selon l'invariant singleton (HANDOFF 28.1 #2) :
     * SE5 ↔ une seule autorité amont à la fois. S'il existe déjà un contrat `active`, on le
     * **réutilise** ; sinon on en crée **un seul**. Conséquence : une 2e réception ne crée JAMAIS
     * un 2e contrat actif. L'invariant « ≤ 1 actif » est tenu ici, en code (modèle mono-autorité :
     * aucune référence d'émetteur n'est stockée — cf. migration 28.1).
     *
     * Exécuté dans la transaction de {@see ingest()} (cohérence avec la réconciliation enfants).
     *
     * ⚠️ Le SELECT-then-INSERT suppose une réception **sérialisée** par instance : sous deux
     * ingestions concurrentes (PostgreSQL READ COMMITTED), les deux peuvent voir « aucun contrat
     * actif » et en créer deux. controlHub diffuse une réception à la fois, donc le risque est
     * théorique ; la défense DB (index partiel `WHERE link_state='active'`, non portable SQLite)
     * a été délibérément différée par la Story 28.2 (Task 3 optionnelle).
     */
    private function resolveActiveContract(ContractIngestionResult $result, string $schemaVersion): ControlHubContract
    {
        $contract = ControlHubContract::query()
            ->where('link_state', ControlHubLinkState::Active->value)
            ->first();

        if ($contract === null) {
            $contract = new ControlHubContract();
            $contract->link_state = ControlHubLinkState::Active;
            $contract->received_at = now();
            // Story 33.1 — version de schéma posée d'emblée à la création (création = mutation).
            $contract->schema_version = $schemaVersion;
            $contract->save();

            $result->contractCreated = true;

            return $contract;
        }

        // Réutilisation du contrat actif existant (singleton).
        return $contract;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Réconciliation générique (upsert sur clé naturelle + prune des disparus)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Réconcilie un agrégat enfant vers le désir d'état du payload :
     * upsert de chaque ligne présente (sur sa clé naturelle 28.1) + suppression des absentes.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<int, array{key: array<string, mixed>, attrs: array<string, mixed>}>  $rows
     * @param  array{created: int, updated: int, deleted: int}  $stats
     * @return bool true si au moins une création / mise à jour / suppression a eu lieu
     */
    private function reconcileChildren(int $contractId, string $modelClass, array $rows, array &$stats): bool
    {
        $mutated = false;
        $keptIds = [];

        foreach ($rows as $row) {
            // Le contrat id n'est connu qu'après résolution du singleton : on l'injecte ici
            // dans la clé naturelle (placeholder null posé à la normalisation).
            $row['key']['controlhub_contract_id'] = $contractId;

            /** @var Model $model */
            $model = $modelClass::updateOrCreate($row['key'], $row['attrs']);

            if ($model->wasRecentlyCreated) {
                $stats['created']++;
                $mutated = true;
            } elseif ($model->wasChanged()) {
                $stats['updated']++;
                $mutated = true;
            }

            $keptIds[] = $model->getKey();
        }

        // Prune : ce qui n'est plus dans le payload est supprimé (désir d'état).
        // Bulk delete via QueryBuilder : ne déclenche PAS les observers Eloquent deleting/deleted.
        // Acceptable en 28.2 (NFR3 : aucun observer sur ces modèles enfants). Si un observer est
        // ajouté plus tard, repasser par ->get()->each->delete() pour les pruned rows.
        $deleted = $modelClass::query()
            ->where('controlhub_contract_id', $contractId)
            ->whereNotIn('id', $keptIds)
            ->delete();

        if ($deleted > 0) {
            $stats['deleted'] += $deleted;
            $mutated = true;
        }

        return $mutated;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Normalisation + validation du payload (PURE — aucune écriture)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Normalise + valide les items. Construit pour chacun {clé naturelle, attributs}.
     *
     * @param  mixed  $items
     * @return array<int, array{key: array<string, mixed>, attrs: array<string, mixed>}>
     *
     * @throws InvalidUpstreamContractException
     */
    private function normalizeItems(mixed $items): array
    {
        $items = $this->ensureList($items, 'items');
        $rows = [];

        foreach ($items as $item) {
            $type = $this->requireString($item['type'] ?? null, 'items.type');
            $key = $this->requireString($item['key'] ?? null, 'items.key');

            // Enum : enforcement_state (locked|permissive|absent).
            $enforcementRaw = (string) ($item['enforcement_state'] ?? '');
            if (ControlHubEnforcementState::tryFrom($enforcementRaw) === null) {
                throw InvalidUpstreamContractException::for(
                    "items.enforcement_state ({$key})",
                    "valeur hors domaine « {$enforcementRaw} » (attendu : locked|permissive|absent)",
                );
            }

            // Enum : target_type (instance|label), défaut instance.
            $targetRaw = (string) ($item['target_type'] ?? ControlHubContractTarget::Instance->value);
            $targetType = ControlHubContractTarget::tryFrom($targetRaw);
            if ($targetType === null) {
                throw InvalidUpstreamContractException::for(
                    "items.target_type ({$key})",
                    "valeur hors domaine « {$targetRaw} » (attendu : instance|label)",
                );
            }

            // Cohérence de cible — contrôlée sur la valeur BRUTE (avant normalisation null→'').
            $rawLabel = $item['target_label'] ?? null;
            $rawLabel = $rawLabel === null ? '' : (string) $rawLabel;

            if ($targetType === ControlHubContractTarget::Label && $rawLabel === '') {
                throw InvalidUpstreamContractException::for(
                    "items.target_label ({$key})",
                    'target_type=label exige un target_label non vide',
                );
            }

            if ($targetType === ControlHubContractTarget::Instance && $rawLabel !== '') {
                throw InvalidUpstreamContractException::for(
                    "items.target_label ({$key})",
                    'target_type=instance exige un target_label vide',
                );
            }

            // Normalisation null → '' (HANDOFF 28.1 #1) : le cas instance écrit '' ; la clé
            // naturelle reste effective ⇒ idempotence préservée sur le cas dominant.
            $targetLabel = $targetType === ControlHubContractTarget::Instance ? '' : $rawLabel;

            $value = $item['value'] ?? null;

            $rows[] = [
                'key' => [
                    'controlhub_contract_id' => null, // injecté à la réconciliation
                    'type' => $type,
                    'key' => $key,
                    'target_type' => $targetType->value,
                    'target_label' => $targetLabel,
                ],
                'attrs' => [
                    'value' => $value === null ? null : (string) $value,
                    'enforcement_state' => $enforcementRaw,
                ],
            ];
        }

        return $rows;
    }

    /**
     * @param  mixed  $labels
     * @return array<int, array{key: array<string, mixed>, attrs: array<string, mixed>}>
     *
     * @throws InvalidUpstreamContractException
     */
    private function normalizeLabels(mixed $labels): array
    {
        $labels = $this->ensureList($labels, 'labels');
        $rows = [];

        foreach ($labels as $label) {
            $name = $this->requireString($label['name'] ?? null, 'labels.name');

            $modeRaw = (string) ($label['mode'] ?? '');
            if (ControlHubLabelMode::tryFrom($modeRaw) === null) {
                throw InvalidUpstreamContractException::for(
                    "labels.mode ({$name})",
                    "valeur hors domaine « {$modeRaw} » (attendu : free|reserved)",
                );
            }

            $rows[] = [
                'key' => [
                    'controlhub_contract_id' => null,
                    'name' => $name,
                ],
                'attrs' => [
                    'mode' => $modeRaw,
                ],
            ];
        }

        return $rows;
    }

    /**
     * @param  mixed  $groups
     * @return array<int, array{key: array<string, mixed>, attrs: array<string, mixed>}>
     *
     * @throws InvalidUpstreamContractException
     */
    private function normalizeImposedGroups(mixed $groups): array
    {
        $groups = $this->ensureList($groups, 'imposed_groups');
        $rows = [];

        foreach ($groups as $group) {
            $name = $this->requireString($group['name'] ?? null, 'imposed_groups.name');
            $labelName = $group['label_name'] ?? null;

            $rows[] = [
                'key' => [
                    'controlhub_contract_id' => null,
                    'name' => $name,
                ],
                'attrs' => [
                    'label_name' => $labelName === null || $labelName === '' ? null : (string) $labelName,
                ],
            ];
        }

        return $rows;
    }

    /**
     * Story 30.1 — Durcissement réception : intégrité référentielle `imposed_groups.label_name`.
     *
     * Un groupe imposé dont `label_name` est NON-NUL désigne un label « associé » : ce label
     * DOIT être déclaré dans le même contrat (`imposed_groups[].label_name ∈ labels[].name`).
     * Sinon le payload est incohérent (« groupe imposé avec son label associé » — FR9, label
     * absent du vocabulaire reçu) et l'ingestion est refusée AVANT toute écriture (rollback total).
     *
     * Règle MINIMALE et suffisante : le label doit être DÉCLARÉ. On n'exige PAS qu'il soit en
     * mode `reserved` (l'enum dit « *typiquement* » porté par un groupe imposé — pas une obligation,
     * sur-contrainte spéculative écartée). Un groupe sans label associé (`label_name` nul) reste
     * légitime : aucun cross-check ne s'y applique.
     *
     * @param  array<int, array{key: array<string, mixed>, attrs: array<string, mixed>}>  $labels         labels normalisés (clé naturelle dans `key.name`)
     * @param  array<int, array{key: array<string, mixed>, attrs: array<string, mixed>}>  $imposedGroups  groupes imposés normalisés (`key.name` + `attrs.label_name`)
     *
     * @throws InvalidUpstreamContractException si un `label_name` non-nul ne référence aucun label déclaré
     */
    private function assertImposedGroupLabelsDeclared(array $labels, array $imposedGroups): void
    {
        $declaredLabels = [];
        foreach ($labels as $label) {
            $declaredLabels[$label['key']['name']] = true;
        }

        foreach ($imposedGroups as $group) {
            $labelName = $group['attrs']['label_name'];

            // Seuls les label_name non-nuls sont contraints (un groupe sans label est légitime).
            if ($labelName === null) {
                continue;
            }

            if (! isset($declaredLabels[$labelName])) {
                $groupName = $group['key']['name'];

                throw InvalidUpstreamContractException::for(
                    "imposed_groups.label_name ({$groupName})",
                    "label associé « {$labelName} » non déclaré dans le contrat",
                );
            }
        }
    }

    /**
     * @param  mixed  $apps
     * @return array<int, array{key: array<string, mixed>, attrs: array<string, mixed>}>
     *
     * @throws InvalidUpstreamContractException
     */
    private function normalizeCatalogApps(mixed $apps): array
    {
        $apps = $this->ensureList($apps, 'catalog_apps');
        $rows = [];

        foreach ($apps as $app) {
            $appKey = $this->requireString($app['app_key'] ?? null, 'catalog_apps.app_key');
            $displayName = $app['display_name'] ?? null;

            // Story 31.3 — référence de source du dépôt SambaEdu (« Option B par-app », D1).
            // Champs OPTIONNELS (rétrocompat NFR3 : un contrat sans source reste accepté) ;
            // normalisation null/'' → null, à l'identique de display_name. La clé naturelle
            // (controlhub_contract_id, app_key) reste INCHANGÉE (idempotence 28.2/NFR4).
            $sourceXmlUrl = $app['source_xml_url'] ?? null;
            $sourceXmlSha = $app['source_xml_sha'] ?? null;

            $rows[] = [
                'key' => [
                    'controlhub_contract_id' => null,
                    'app_key' => $appKey,
                ],
                'attrs' => [
                    'display_name' => $displayName === null || $displayName === '' ? null : (string) $displayName,
                    'source_xml_url' => $sourceXmlUrl === null || $sourceXmlUrl === '' ? null : (string) $sourceXmlUrl,
                    'source_xml_sha' => $sourceXmlSha === null || $sourceXmlSha === '' ? null : (string) $sourceXmlSha,
                ],
            ];
        }

        return $rows;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Helpers de validation
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @param  mixed  $value
     * @return array<int, array<string, mixed>>
     *
     * @throws InvalidUpstreamContractException
     */
    private function ensureList(mixed $value, string $field): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw InvalidUpstreamContractException::for($field, 'doit être une liste');
        }

        return $value;
    }

    /**
     * @param  mixed  $value
     *
     * @throws InvalidUpstreamContractException
     */
    private function requireString(mixed $value, string $field): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw InvalidUpstreamContractException::for($field, 'champ requis manquant');
        }

        $value = (string) $value;

        if ($value === '') {
            throw InvalidUpstreamContractException::for($field, 'champ requis vide');
        }

        return $value;
    }
}
