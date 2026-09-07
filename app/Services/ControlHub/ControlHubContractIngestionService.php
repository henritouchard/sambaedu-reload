<?php

declare(strict_types=1);

namespace App\Services\ControlHub;

use App\Enums\ControlHubArtifactPullStatus;
use App\Enums\ControlHubContractTarget;
use App\Enums\ControlHubEnforcementState;
use App\Enums\ControlHubLabelMode;
use App\Enums\ControlHubLinkState;
use App\Events\ControlHubContractChanged;
use App\Exceptions\ControlHub\InvalidUpstreamContractException;
use App\Exceptions\ControlHub\UnsupportedSchemaVersionException;
use App\Jobs\ControlHub\PullContractArtifactJob;
use App\Models\AgentTool;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractCatalogApp;
use App\Models\ControlHubContractImposedGroup;
use App\Models\ControlHubContractItem;
use App\Models\ControlHubContractLabel;
use App\Models\Shortcut;
use App\Models\WallpaperAsset;
use App\Services\ControlHub\Data\ContractIngestionResult;
use App\Services\Shortcuts\ShortcutIconAssetService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ingestion idempotente du contrat amont (controlHub).
 * Schéma d'ÉCHANGE versionné : le payload déclare une `schema_version`
 * (racine) ; l'ingestion la **négocie** ({@see ControlHubContractSchema::negotiate()}) et
 * **enregistre** la version retenue sur le contrat (colonne `schema_version`). Format figé
 * dans l'artefact partagé docs/controlhub-schema-echange.md
 * (source unique partagée par SE5 et l'amont). Un payload **conforme** (version supportée) ou
 * **sans version** (défaut = version courante, rétro-compat) est accepté ; une version
 * **déclarée non supportée** est **rejetée** ({@see UnsupportedSchemaVersionException}
 * propagée par la négociation, en phase de validation pure ⇒ zéro écriture, état inchangé).
 *
 * Reçoit un payload de contrat (émis par l'autorité amont) et le persiste de façon idempotente :
 *
 * - **Upsert** des 4 agrégats enfants (items, labels, groupes imposés, apps catalogue)
 *   sur leurs clés naturelles, puis **prune** des enfants disparus
 *   (réconciliation « désir d'état » : full replace par contrat).
 * - **Normalisation `null → ''`** de `target_label` avant écriture : la clé naturelle
 *   repose sur `target_label NOT NULL DEFAULT ''` ; sans normalisation, le cas dominant
 *   `target_type=instance` recasse l'idempotence (NULL ≠ NULL en PG/SQLite).
 * - **Validation des enums + cohérence de cible** avant toute écriture ⇒ rollback total
 *   en cas de payload invalide (il n'existe aucun `CHECK` DB).
 * - **Intégrité référentielle** `imposed_groups.label_name → label déclaré` avant écriture
 *   (un `label_name` non-nul orphelin est rejeté ; rollback total).
 * - **Singleton** « au plus un contrat actif par instance » : réutilise le contrat actif
 *   existant plutôt que d'en créer un second.
 * - Passage du lien à `active` + `received_at = now()` **uniquement** sur création ou mutation.
 * - **No-op fonctionnel** : une réception identique n'écrit rien et n'émet aucun
 *   événement {@see ControlHubContractChanged}.
 *
 * Ce service est le **seul** écrivain des tables `controlhub_contract_*`. Il ne lit
 * ni n'écrit aucune autre table, ne touche pas `StateCompiler`, et l'événement
 * émis reste **sans listener** (comportement standalone strictement inchangé).
 *
 * Format de payload accepté (schéma d'échange versionné) :
 * <code>
 * [
 *  'schema_version' => '1.0', // optionnel ; absent ⇒ version courante (rétro-compat)
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
 */
class ControlHubContractIngestionService
{
    /** Emplacements de pose qu'un raccourci peut réclamer (domaine de `spec.place`). */
    private const SHORTCUT_PLACES = [
        Shortcut::PLACE_DESKTOP,
        Shortcut::PLACE_STARTUP,
        Shortcut::PLACE_TASKBAR,
    ];

    /**
     * Ingère un payload de contrat amont de façon idempotente.
     *
     * Le `link_state` n'est **jamais** lu du payload : à la réception, le lien passe à
     * `active` par définition. La rupture (`severed`) relève de
     * {@see ControlHubContractSeveranceService}.
     *
     * La `schema_version` racine est négociée et enregistrée sur le contrat. Elle
     * participe au calcul de mutation : réception identique (même version) = no-op total ;
     * changement de version supportée sur contenu sinon identique = mutation (event émis 1×).
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidUpstreamContractException si le payload est hors domaine (rollback total)
     * @throws UnsupportedSchemaVersionException si la `schema_version` déclarée est non supportée
     * (rejet en validation pure, état inchangé)
     */
    public function ingest(array $payload): ContractIngestionResult
    {
        // 0. Négociation de la VERSION du schéma d'ÉCHANGE — AVANT toute validation de CONTENU.
        // Phase de validation PURE (aucune écriture, AVANT DB::transaction,
        // cohérent avec le rollback total) : version supportée → elle-même ; absente → version
        // courante (rétro-compat) ; version DÉCLARÉE non supportée →
        // unsupportedSchemaVersionException LAISSÉE SE PROPAGER (pas de try/catch) — la
        // levée précède toute écriture ⇒ état inchangé.
        // La version est négociée AVANT les `normalizeX()` : un payload émis sous
        // une version non supportée ne doit PAS être interprété sous les règles de la version
        // courante. Sinon un contenu légal dans une future version mais hors-domaine en v1.0 lèverait
        // `InvalidUpstreamContractException` (CONTENU) au lieu d'`UnsupportedSchemaVersionException`
        // (VERSION), masquant la vraie cause. La négociation est pure (O(1), zéro DB).
        // Tout scalaire numérique DÉCLARÉ est coercé en chaîne pour être négocié :
        // un `schema_version` float JSON (ex.) ne doit PAS retomber sur `null`→version courante,
        // ce qui accepterait silencieusement une version incompatible. Coercé, il est
        // rejeté en égalité stricte comme un int/string non supporté. (array/bool/objet restent
        // traités comme absents.)
        // Cf. artefact partagé docs/controlhub-schema-echange.md.
        $declaredVersion = $payload['schema_version'] ?? null;
        $declaredVersion = is_string($declaredVersion) || is_int($declaredVersion) || is_float($declaredVersion)
            ? (string) $declaredVersion
            : null;
        $schemaVersion = ControlHubContractSchema::negotiate($declaredVersion);

        // 1. Normalisation + validation PURE du CONTENU (aucune écriture) — garantit le rollback
        //    total : une valeur hors domaine lève l'exception AVANT d'ouvrir la transaction.
        $items = $this->normalizeItems($payload['items'] ?? []);
        $labels = $this->normalizeLabels($payload['labels'] ?? []);
        $imposedGroups = $this->normalizeImposedGroups($payload['imposed_groups'] ?? []);
        $catalogApps = $this->normalizeCatalogApps($payload['catalog_apps'] ?? []);

        // Durcissement réception (intégrité référentielle) : un groupe imposé
        // « avec son label associé » présuppose que ce label fait partie du vocabulaire
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

            // La version de schéma fait partie de l'état du contrat racine : sur un
            // contrat RÉUTILISÉ, un changement de version (contenu sinon identique) est une mutation
            // légitime. À l'identique (même version), la comparaison est fausse ⇒ aucune
            // écriture déclenchée par la version (no-op préservé). On n'écrit
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

        // Événement de changement émis EXACTEMENT une fois sur mutation (jamais sur no-op),
        // APRÈS le commit (hors transaction) : un futur listener synchrone (StateCompiler) ne
        // peut donc pas faire rollback de l'ingestion validée, ni un listener queued s'exécuter avant
        // que les écritures soient committées.
        if ($result->mutated) {
            ControlHubContractChanged::dispatch(ControlHubContract::find($result->contractId));

            // Canal ④ : déclenchement du pull des binaires imposés, au MÊME point que
            // l'événement (hors transaction, uniquement sur mutation). Sur un no-op (mutated=false —
            // Ex. ré-réception identique dont SEULE artifact.url diffère), rien n'est dispatché :
            // ni événement, ni job de pull. Le pull est STRICTEMENT asynchrone (jamais un
            // téléchargement synchrone dans la requête HTTP d'ingestion).
            $this->dispatchArtifactPulls((int) $result->contractId, $items);
        }

        Log::info('ControlHubContractIngestionService: ingestion completed', [
            'result' => $result->toArray(),
        ]);

        return $result;
    }

    // Résolution du contrat racine (singleton « ≤ 1 contrat actif »)

    /**
     * Résout le contrat à mettre à jour selon l'invariant singleton :
     * SE5 ↔ une seule autorité amont à la fois. S'il existe déjà un contrat `active`, on le
     * **réutilise** ; sinon on en crée **un seul**. Conséquence : une 2e réception ne crée JAMAIS
     * un 2e contrat actif. L'invariant « ≤ 1 actif » est tenu ici, en code (modèle mono-autorité :
     * aucune référence d'émetteur n'est stockée — cf. migration).
     *
     * Exécuté dans la transaction de {@see ingest()} (cohérence avec la réconciliation enfants).
     *
     * ⚠️ Le SELECT-then-INSERT suppose une réception **sérialisée** par instance : sous deux
     * ingestions concurrentes (PostgreSQL READ COMMITTED), les deux peuvent voir « aucun contrat
     * actif » et en créer deux. controlHub diffuse une réception à la fois, donc le risque est
     * théorique ; la défense DB (index partiel `WHERE link_state='active'`, non portable SQLite)
     * A été délibérément différée par la (Task 3 optionnelle).
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
            // Version de schéma posée d'emblée à la création (création = mutation).
            $contract->schema_version = $schemaVersion;
            $contract->save();

            $result->contractCreated = true;

            return $contract;
        }

        // Réutilisation du contrat actif existant (singleton).
        return $contract;
    }

    // Canal ④ : déclenchement du pull des binaires imposés

    /**
     * Déclenche le pull ASYNCHRONE des binaires imposés, APRÈS le commit de
     * l'ingestion (hors transaction, uniquement sur mutation). Seuls les items
     * `type ∈ {wallpapers, lockscreens, agent_tools}` porteurs d'un `artifact` complet (checksum + url
     * non vides) sont candidats.
     *
     * **Précédence locale stricte** (le pull COMBLE l'absence, ne REMPLACE jamais une source locale) :
     *  - `wallpapers` / `lockscreens` : présent si un {@see WallpaperAsset} existe pour ce `checksum`
     *    (identité par contenu, cohérente avec la bibliothèque content-addressée — les deux types
     *    puisent dans la MÊME bibliothèque d'images, seule l'assignation aval diffère) ;
     *  - `agent_tools` : présent si un {@see AgentTool} existe pour cette `key` (identité par clé
     *  fonctionnelle, mono-version, cohérente avec `AgentToolService::registerEmbedded()`).
     *
     * Si l'asset est présent localement → AUCUN pull, `pull_status` laissé inchangé (rien à faire ;
     * ce n'est pas un « pending »). Si absent → `pull_status = pending` puis dispatch du job avec
     * L'URL signée EN ARGUMENT (jamais en colonne —).
     *
     * @param  array<int, array{key: array<string, mixed>, attrs: array<string, mixed>, artifact_url?: string|null}>  $items  items normalisés (portent l'URL volatile hors key/attrs)
     */
    private function dispatchArtifactPulls(int $contractId, array $items): void
    {
        $pullableTypes = ['wallpapers', 'lockscreens', 'agent_tools', Shortcut::TYPE_SHORTCUTS];

        foreach ($items as $row) {
            $type = (string) $row['key']['type'];
            if (! in_array($type, $pullableTypes, true)) {
                continue;
            }

            $checksum = $row['attrs']['artifact_checksum'] ?? null;
            $url = $row['artifact_url'] ?? null;

            // Un artefact « complet » exige au minimum checksum (identité stable) + url (pull).
            // Un checksum sans url reste persisté mais NON pullable (rien à tirer).
            if ($checksum === null || $checksum === '' || $url === null || $url === '') {
                if ($checksum === null || $checksum === '') {
                    $this->forgetPullStatusOfDescriptorlessItem($contractId, $row);
                }

                continue;
            }

            $itemKey = (string) $row['key']['key'];

            // Précédence locale : le pull ne se déclenche QUE si l'asset est absent localement.
            $presentLocally = match ($type) {
                'wallpapers', 'lockscreens' => WallpaperAsset::query()->where('checksum', $checksum)->exists(),
                'agent_tools' => AgentTool::query()->where('key', $itemKey)->exists(),
                // Icône de raccourci : content-adressée sur disque, jamais en table.
                Shortcut::TYPE_SHORTCUTS => is_file(
                    app(ShortcutIconAssetService::class)->servedDir().DIRECTORY_SEPARATOR.$checksum.'.ico'
                ),
                default => true,
            };
            if ($presentLocally) {
                continue;
            }

            // Retrouve l'item persisté (par clé naturelle) pour porter son id + pull_status.
            /** @var ControlHubContractItem|null $item */
            $item = ControlHubContractItem::query()
                ->where('controlhub_contract_id', $contractId)
                ->where('type', $type)
                ->where('key', $itemKey)
                ->where('target_type', (string) $row['key']['target_type'])
                ->where('target_label', (string) $row['key']['target_label'])
                ->first();

            if ($item === null) {
                continue;
            }

            // Isolation d'erreur PAR ITEM : sans try/catch, un dispatch en échec
            // (backend de queue transitoirement indisponible) avorterait la boucle → les items
            // SUIVANTS ne seraient ni marqués `pending` ni dispatchés, et comme `dispatchArtifactPulls`
            // n'est rappelée que sur mutation du contrat, un item identique en ré-émission resterait
            // bloqué sans signal. On log + continue pour ne pas contaminer les autres items ; l'item
            // fautif reste marquable/récupérable (pull_status non figé à Pending si le save a échoué).
            try {
                $item->pull_status = ControlHubArtifactPullStatus::Pending;
                $item->pull_error = null;
                $item->save();

                PullContractArtifactJob::dispatch(
                    $item->id,
                    $type,
                    $itemKey,
                    (string) $url,
                    (string) $checksum,
                    $row['attrs']['artifact_filename'] ?? null,
                    $row['attrs']['artifact_size'] ?? null,
                );

                Log::info('ControlHubContractIngestionService: artifact pull dispatched', [
                    'item_id' => $item->id,
                    'type' => $type,
                    'key' => $itemKey,
                    // Jamais l'URL signée en clair : elle peut porter un secret de signature.
                    'checksum' => $checksum,
                ]);
            } catch (\Throwable $e) {
                Log::error('ControlHubContractIngestionService: artifact pull dispatch failed', [
                    'item_id' => $item->id,
                    'type' => $type,
                    'key' => $itemKey,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
                // continue : ne pas priver les autres items imposés de leur pull.
            }
        }
    }

    // Réconciliation générique (upsert sur clé naturelle + prune des disparus)

    /**
     * Réconcilie un agrégat enfant vers le désir d'état du payload :
     * upsert de chaque ligne présente (sur sa clé naturelle) + suppression des absentes.
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
        // Acceptable : aucun observer n'est enregistré sur ces modèles enfants. Si un observer est
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

    // Normalisation + validation du payload (PURE — aucune écriture)

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

            // Normalisation null → '' : le cas instance écrit '' ; la clé
            // naturelle reste effective ⇒ idempotence préservée sur le cas dominant.
            $targetLabel = $targetType === ControlHubContractTarget::Instance ? '' : $rawLabel;

            $value = $item['value'] ?? null;

            // Canal ④ (lecture ADDITIVE, iso source_xml_url/sha) :
            // - `delivery_mode` : capturé tel quel, non arbitré (aucun domaine fermé,
            //    aucun rejet ; un payload qui ne le porte pas reste accepté à l'identique).
            //  - `artifact.{checksum,filename,size}` : identité STABLE du binaire imposé, écrite en
            // colonnes. `artifact.url` est LU mais JAMAIS écrit en colonne (piège
            //    d'idempotence) : il ne sert qu'à alimenter, EN MÉMOIRE, le déclenchement du pull
            // hors transaction. On l'attache donc au niveau de la row (clé `artifact_url`,
            //  sœur de key/attrs) — reconcileChildren() n'utilise QUE key/attrs, l'URL ne peut
            //  donc structurellement pas polluer wasChanged() (pas de colonne = pas de churn).
            $deliveryMode = $item['delivery_mode'] ?? null;
            $artifact = is_array($item['artifact'] ?? null) ? $item['artifact'] : [];
            $artifactChecksum = $artifact['checksum'] ?? null;
            $artifactFilename = $artifact['filename'] ?? null;
            $artifactSize = $artifact['size'] ?? null;
            $artifactUrl = $artifact['url'] ?? null;

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
                    'spec' => $this->normalizeSpec($item['spec'] ?? null, $type, $key),
                    'enforcement_state' => $enforcementRaw,
                    // Additifs — normalisation '' → null (iso source_xml_*). pull_status /
                    // pull_error NE sont PAS dans les attrs : ils sont pilotés par le flux de pull
                    // (post-commit + job), jamais par le payload ⇒ jamais réécrits/écrasés par une
                    // ré-ingestion (no-op préservé sur un item déjà téléchargé/en erreur).
                    'delivery_mode' => $deliveryMode === null || $deliveryMode === '' ? null : (string) $deliveryMode,
                    // Checksum NORMALISÉ en minuscule au point CANONIQUE (ingestion),
                    // iso `hash_file()` de WallpaperUploadService/AgentToolService qui écrit toujours en
                    // minuscule. Sans ça, un `checksum` amont en MAJUSCULE échoue la dédup content-adressée
                    // en Postgres (comparaison `=` sensible à la casse) → doublon de bibliothèque + pull
                    // inutile. Colonne DB + tous les lookups (precedence, materialize) deviennent cohérents.
                    'artifact_checksum' => $artifactChecksum === null || $artifactChecksum === '' ? null : strtolower((string) $artifactChecksum),
                    'artifact_filename' => $artifactFilename === null || $artifactFilename === '' ? null : (string) $artifactFilename,
                    'artifact_size' => is_numeric($artifactSize) ? (int) $artifactSize : null,
                ],
                // Hors key/attrs : URL signée volatile (jamais persistée —).
                'artifact_url' => $artifactUrl === null || $artifactUrl === '' ? null : (string) $artifactUrl,
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
                    'is_physical' => $this->normalizeOptionalBool(
                        $group['is_physical'] ?? null,
                        'imposed_groups.is_physical',
                    ),
                ],
            ];
        }

        return $rows;
    }

    /**
     * Efface le verdict de pull d'un item qui ne décrit plus aucun binaire.
     *
     * Un item peut perdre son descripteur d'artefact d'une émission à l'autre. Sans
     * cet effacement, il resterait `downloaded` (ou `error`) tout en n'ayant plus
     * ni checksum ni fichier à désigner — le rapport de conformité affirmerait alors
     * un téléchargement qui ne correspond à rien.
     *
     * @param  array{key: array<string, mixed>, attrs: array<string, mixed>}  $row
     */
    private function forgetPullStatusOfDescriptorlessItem(int $contractId, array $row): void
    {
        ControlHubContractItem::query()
            ->where('controlhub_contract_id', $contractId)
            ->where('type', (string) $row['key']['type'])
            ->where('key', (string) $row['key']['key'])
            ->where('target_type', (string) $row['key']['target_type'])
            ->where('target_label', (string) $row['key']['target_label'])
            ->whereNotNull('pull_status')
            ->update(['pull_status' => null, 'pull_error' => null]);
    }

    /**
     * Attributs typés d'un item, triés pour que deux réceptions identiques
     * produisent le même JSON (sans quoi le no-op serait cassé par l'ordre des clés).
     *
     * Le contenu n'est PAS un domaine fermé : chaque type y met son vocabulaire, et
     * un type que SE5 ne consomme pas encore reste stocké tel quel. Seul `place`
     * est contrôlé — l'agent rejette en bloc un raccourci dont la place est
     * inconnue, autant refuser à la porte plutôt que servir un poste cassé.
     *
     * @return array<string, mixed>|null
     */
    private function normalizeSpec(mixed $spec, string $type, string $key): ?array
    {
        if (! is_array($spec) || $spec === []) {
            return null;
        }

        if ($type === Shortcut::TYPE_SHORTCUTS && isset($spec['place'])) {
            $place = (string) $spec['place'];

            if (! in_array($place, self::SHORTCUT_PLACES, true)) {
                throw InvalidUpstreamContractException::for(
                    "items.spec.place ({$key})",
                    "valeur hors domaine « {$place} » (attendu : ".implode('|', self::SHORTCUT_PLACES).')',
                );
            }
        }

        ksort($spec);

        return $spec;
    }

    /**
     * Booléen optionnel du payload amont : `null` quand l'autorité ne se prononce pas.
     *
     * JSON transporte parfois un booléen en chaîne ou en entier selon le sérialiseur
     * amont ; les deux formes sont admises. Toute autre valeur est hors domaine et
     * rejetée comme n'importe quel champ invalide (rollback total, aucune écriture).
     */
    private function normalizeOptionalBool(mixed $value, string $field): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['true', '1'], true)) {
                return true;
            }

            if (in_array($normalized, ['false', '0'], true)) {
                return false;
            }
        }

        throw InvalidUpstreamContractException::for($field, 'booléen attendu');
    }

    /**
     * Durcissement réception : intégrité référentielle `imposed_groups.label_name`.
     *
     * Un groupe imposé dont `label_name` est NON-NUL désigne un label « associé » : ce label
     * DOIT être déclaré dans le même contrat (`imposed_groups[].label_name ∈ labels[].name`).
     * Sinon le payload est incohérent (un groupe imposé désigne un label absent du vocabulaire
     * reçu) et l'ingestion est refusée AVANT toute écriture (rollback total).
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

            // Référence de source du dépôt SambaEdu, déclarée par app.
            // Champs OPTIONNELS (un contrat sans source reste accepté) ;
            // normalisation null/'' → null, à l'identique de display_name. La clé naturelle
            // (controlhub_contract_id, app_key) reste INCHANGÉE, donc l'ingestion reste idempotente.
            $sourceXmlUrl = $app['source_xml_url'] ?? null;
            $sourceXmlSha = $app['source_xml_sha'] ?? null;

            // Champs d'AFFICHAGE du dépôt imposé. Additifs OPTIONNELS
            // (absence tolérée) ; normalisation null/'' → null, à
            // l'identique de display_name/source_xml_*. Champs STABLES (pas d'URL volatile
            // qui casserait l'idempotence) — la clé naturelle reste INCHANGÉE.
            $version = $app['version'] ?? null;
            $category = $app['category'] ?? null;
            $iconUrl = $app['icon_url'] ?? null;

            // Canal ④, `executable` : PERSISTANCE SEULE. On lit et stocke
            // `checksum`/`filename`/`size` (mêmes normalisations que source_xml_*), mais AUCUN pull
            // n'est déclenché ici (pas de dispatch de job pour catalog_apps — cf. dispatchArtifactPulls,
            // limité à wallpapers/agent_tools). `executable.url` n'est PAS lu en colonne (même piège
            // D'idempotence que artifact.url). Ce champ recouvre un mécanisme SE5 déjà tenté et
            // abandonné (`applications.installer_*`, destruction séparée planifiée) : on résiste
            // délibérément à matérialiser par mimétisme avec `artifact` : le pull
            // reste le point d'extension propre si un besoin réel émerge.
            $executable = is_array($app['executable'] ?? null) ? $app['executable'] : [];
            $executableChecksum = $executable['checksum'] ?? null;
            $executableFilename = $executable['filename'] ?? null;
            $executableSize = $executable['size'] ?? null;

            $rows[] = [
                'key' => [
                    'controlhub_contract_id' => null,
                    'app_key' => $appKey,
                ],
                'attrs' => [
                    'display_name' => $displayName === null || $displayName === '' ? null : (string) $displayName,
                    'source_xml_url' => $sourceXmlUrl === null || $sourceXmlUrl === '' ? null : (string) $sourceXmlUrl,
                    'source_xml_sha' => $sourceXmlSha === null || $sourceXmlSha === '' ? null : (string) $sourceXmlSha,
                    // Champs d'affichage du dépôt imposé (null/'' → null).
                    'version' => $version === null || $version === '' ? null : (string) $version,
                    'category' => $category === null || $category === '' ? null : (string) $category,
                    'icon_url' => $iconUrl === null || $iconUrl === '' ? null : (string) $iconUrl,
                    // Checksum normalisé minuscule (cohérence avec artifact_checksum ;
                    // persistance seule pour executable, mais on garde l'identité stable homogène).
                    'executable_checksum' => $executableChecksum === null || $executableChecksum === '' ? null : strtolower((string) $executableChecksum),
                    'executable_filename' => $executableFilename === null || $executableFilename === '' ? null : (string) $executableFilename,
                    'executable_size' => is_numeric($executableSize) ? (int) $executableSize : null,
                ],
            ];
        }

        return $rows;
    }

    // Helpers de validation

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
