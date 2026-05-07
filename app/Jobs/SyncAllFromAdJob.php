<?php

declare(strict_types=1);

namespace App\Jobs;

use App\LdapModels\DeviceGroupModel;
use App\LdapModels\DeviceGroupTagModel;
use App\LdapModels\MachineModel;
use App\Models\AppProfile;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\AppProfileObserver;
use App\Observers\WorkstationGroupObserver;
use App\Observers\WorkstationObserver;
use App\Services\ErrorLoggerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

/**
 * Story 15.3 — Outil de remédiation drift AD → Eloquent.
 *
 * **Direction d'écriture canonique** : Eloquent → AD via observers.
 * Ce job est **l'unique outil de remédiation entrant** : il est déclenché
 * humainement (UI `/admin/sync-from-ad` ou `php artisan sync:from-ad`) et
 * **n'est jamais cronné** (cf. décision de cadrage 2026-05-05/06 — race
 * silencieuse avec les jobs `*AdSyncJob` sortants pilotés par observers).
 *
 * Garanties (cf. AC3.x de la story) :
 * - **Mode dry-run** (AC3.1) : `new SyncAllFromAdJob(dryRun: true)` ; lecture
 *   AD complète, diff vs Eloquent, rapport stats, **0 écriture DB**.
 * - **Lock anti-double-clic** (AC3.2) : `Cache::lock('wpkg:sync-all-from-ad', $ttl)`.
 *   2e exécution concurrente → log info skip + return rapport vide
 *   `idempotent=true,skipped_lock=true`. Lock libéré en `finally`.
 * - **Logs structurés channel `wpkg-deploy`** (AC3.3) : `Log::channel('wpkg-deploy')`
 *   avec contexte `job`, `dry_run`, `run_id` (UUID).
 * - **Archivage `archived_at`** (AC3.4) : entité absente AD mais présente
 *   en SQL → on pose `archived_at=now()` au lieu de `DELETE`.
 * - **2 passes strictes** (AC3.5) : passe 1 = lecture AD complète, passe 2 =
 *   écriture sous `DB::transaction`. Si la passe 1 échoue → 0 écriture.
 * - **Idempotence** (AC3.6) : 2e exécution sur état identique = no-op
 *   silencieux (`idempotent=true`). Le `last_seen_at` a été retiré du
 *   scope (cf. décision Q1 review) : plus de `saveQuietly` parasite.
 * - **Match strict premier run** (AC3.7) : rapprochement par `name`
 *   lower-case **+ scope OU précis**, jamais d'écrasement d'un GUID
 *   déjà posé. Pour les profils, on compare au DN « OU=<name>,OU=Parcs »
 *   (legacy). Pour les groupes, scope « OU=Computers ».
 *
 * **Invariants à NE PAS casser** :
 * - `WorkstationGroupObserver::disableSync()` / `WorkstationObserver::disableSync()`
 *   / `AppProfileObserver::disableSync()` en début de passe 2 et
 *   `enableSync()` en `finally` — sinon les écritures de la passe 2
 *   déclenchent des `*AdSyncJob` sortants qui réécriraient en AD ce qu'on
 *   vient juste de lire.
 * - `DB::transaction(...)` enveloppe l'ensemble de la passe 2.
 *
 * **Décisions post-review (2026-05-06)** :
 * - Q1 — `last_seen_at` abandonné (option C2) : aucun besoin métier réel,
 *   l'archivage des orphans est calculé dans le même run.
 * - Q2 — Renommage AD : Eloquent garde la souveraineté sur `name` SQL ;
 *   en cas de divergence on log `info` + entrée `error_logs` (source
 *   `wpkg`) + compteur `name_divergences`. Cf. `detectNameDivergence()`.
 * - Q3 — Commande artisan reste `sync:from-ad` (nom historique préservé).
 *
 * Note : le bloc d'import des `Workstation` (machines AD) reste désactivé
 * (problème RAM serveur, cf. H3 de la story / audit T0 §2.1). Si un drift
 * machines fiable est requis par 15.5, escalation Henri.
 *
 * @see _bmad-output/implementation-artifacts/15-3-modele-eloquent-suffisant-pour-deploiement-wpkg.md
 * @see _bmad-output/planning-artifacts/audit-wpkg-eloquent-schema.md §6
 * @see _bmad-output/codeReviews/15-3.md
 */
class SyncAllFromAdJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    /** Identifiant unique de l'exécution (corrélation logs). */
    private string $runId;

    /** Stats détaillées par entité (rapport final). */
    private array $stats;

    /**
     * Logger contextualisé (`wpkg-deploy` + run_id + dry_run). Initialisé
     * dans `handle()` pour propagation cohérente via toutes les méthodes
     * (correctif review #6 — plus de `Log::channel('wpkg-deploy')` direct).
     */
    private ?LoggerInterface $logger = null;

    public function __construct(public bool $dryRun = false)
    {
        $this->runId = (string) Str::uuid();
        $this->stats = $this->emptyStats();
    }

    /**
     * Skeleton stats vide — recréé à chaque appel à `handle()` pour
     * garantir l'idempotence si le job est ré-instancié.
     */
    private function emptyStats(): array
    {
        return [
            'run_id' => $this->runId,
            'dry_run' => $this->dryRun,
            'workstation_groups' => [
                'total_ad' => 0,
                'total_db' => 0,
                'total_db_archived' => 0,
                'created' => 0,
                'updated' => 0,
                'restored' => 0,
                'archived' => 0,
                'skipped' => 0,
                'conflicts' => 0,
                'name_divergences' => 0,
            ],
            'app_profiles' => [
                'total_ad' => 0,
                'total_db' => 0,
                'total_db_archived' => 0,
                'created' => 0,
                'updated' => 0,
                'restored' => 0,
                'archived' => 0,
                'skipped' => 0,
                'conflicts' => 0,
                'name_divergences' => 0,
            ],
            'profile_group_links' => ['created' => 0, 'skipped' => 0],
            'workstations' => [
                'total_ad' => 0,
                'total_db' => 0,
                'total_db_archived' => 0,
                'created' => 0,
                'updated' => 0,
                'restored' => 0,
                'archived' => 0,
                'skipped' => 0,
                'conflicts' => 0,
                'name_divergences' => 0,
            ],
            'workstation_links' => ['group' => 0, 'profile' => 0],
            'idempotent' => false,
            'skipped_lock' => false,
            'aborted_reason' => null,
        ];
    }

    /**
     * Execute the job.
     *
     * @return array Rapport stats détaillé.
     */
    public function handle(): array
    {
        // Reset stats au cas où le job est ré-handle().
        $this->stats = $this->emptyStats();

        $this->logger = Log::channel('wpkg-deploy')->withContext([
            'job' => 'SyncAllFromAdJob',
            'run_id' => $this->runId,
            'dry_run' => $this->dryRun,
        ]);

        $lockTtl = (int) config('sambaedu.wpkg.sync.lock_ttl_seconds', 600);
        $lock = Cache::lock('wpkg:sync-all-from-ad', $lockTtl);

        if (! $lock->get()) {
            $this->logger->info('[SyncAllFromAd] Lock non acquis — synchronisation déjà en cours, skip.');
            $this->stats['skipped_lock'] = true;
            $this->stats['idempotent'] = true;
            $this->stats['aborted_reason'] = 'lock_not_acquired';

            return $this->stats;
        }

        try {
            $this->logger->info('[SyncAllFromAd] Démarrage de la synchronisation', [
                'lock_ttl' => $lockTtl,
            ]);

            // ============================================================
            // PASSE 1 — Lecture AD complète (aucune écriture DB)
            // ============================================================
            try {
                $parcsAd = $this->fetchParcsFromAd();
                $groupesAd = $this->fetchGroupesFromAd();
            } catch (\Throwable $e) {
                $this->logger->warning('[SyncAllFromAd] Passe 1 partielle, abandon de la sync (atomicité stricte).', [
                    'error' => $e->getMessage(),
                ]);
                $this->stats['aborted_reason'] = 'pass1_failed: ' . $e->getMessage();

                return $this->stats;
            }

            $this->stats['workstation_groups']['total_ad'] = count($groupesAd);
            $this->stats['app_profiles']['total_ad'] = count($parcsAd);
            // Correctif review #M1 : `total_db` exclut les rows archivées
            // (sinon confusion opérateur). Compteur dédié `total_db_archived`.
            $this->stats['workstation_groups']['total_db'] = WorkstationGroup::query()->whereNull('archived_at')->count();
            $this->stats['workstation_groups']['total_db_archived'] = WorkstationGroup::query()->whereNotNull('archived_at')->count();
            $this->stats['app_profiles']['total_db'] = AppProfile::query()->whereNull('archived_at')->count();
            $this->stats['app_profiles']['total_db_archived'] = AppProfile::query()->whereNotNull('archived_at')->count();

            $this->logger->info('[SyncAllFromAd] Passe 1 terminée', [
                'parcs_ad' => count($parcsAd),
                'groupes_ad' => count($groupesAd),
                'groupes_db' => $this->stats['workstation_groups']['total_db'],
                'profils_db' => $this->stats['app_profiles']['total_db'],
            ]);

            // ============================================================
            // PASSE 1.5 — Pré-détection des conflits GUID DB (correctif #2)
            // ------------------------------------------------------------
            // Les conflits sont détectés AVANT `DB::beginTransaction` pour
            // que l'écriture `error_logs` soit persistée même si la passe
            // 2 rollbacke (sinon le log dispatcher serait emporté par le
            // rollback de transaction global). Halte propre via exception
            // remontée par `raiseGuidConflict`.
            // ============================================================
            $this->detectGuidConflictsOrAbort();

            // ============================================================
            // PASSE 2 — Écriture DB (transactionnelle, observers désactivés)
            // ============================================================
            if ($this->dryRun) {
                $this->runPass2Diff($parcsAd, $groupesAd);
            } else {
                $this->runPass2Apply($parcsAd, $groupesAd);
            }

            $this->stats['idempotent'] = $this->computeIdempotent();

            $this->logger->info('[SyncAllFromAd] Synchronisation terminée', $this->stats);

            return $this->stats;
        } catch (\Throwable $e) {
            $this->logger->error('[SyncAllFromAd] Erreur : ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        } finally {
            try {
                $lock->release();
            } catch (\Throwable) {
                // Lock peut déjà être expiré côté driver — ignorer.
            }
        }
    }

    /**
     * Passe 2 — mode dry-run : exécute le diff sans aucune écriture DB.
     * Réutilise le même algo que `runPass2Apply()` mais avec un flag
     * propagé qui skip les `save()` / `archive()`. Les counters stats
     * reflètent ce qui **aurait été** écrit.
     */
    private function runPass2Diff(array $parcsAd, array $groupesAd): void
    {
        $this->logger->info('[SyncAllFromAd] Dry-run : diff sans écriture');

        $this->syncWorkstationGroups($groupesAd, applyWrites: false);
        $this->syncAppProfiles($parcsAd, applyWrites: false);
        $this->syncProfileGroupLinks(applyWrites: false);
    }

    /**
     * Passe 2 — mode apply : transaction globale + observers désactivés.
     *
     * Correctif review #1 : `AppProfileObserver::disableSync()` ajouté
     * (manquant en v1, faisait fuir des `AppProfileAdSyncJob` sortants
     * pendant la passe 2 → violation invariant).
     */
    private function runPass2Apply(array $parcsAd, array $groupesAd): void
    {
        $this->logger->info('[SyncAllFromAd] Apply : écritures sous transaction');

        DB::beginTransaction();
        WorkstationGroupObserver::disableSync();
        WorkstationObserver::disableSync();
        AppProfileObserver::disableSync();

        try {
            $this->syncWorkstationGroups($groupesAd, applyWrites: true);
            $this->syncAppProfiles($parcsAd, applyWrites: true);
            $this->syncProfileGroupLinks(applyWrites: true);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        } finally {
            // Invariant fort : observers TOUJOURS réactivés, même si la
            // transaction a échoué. Sinon les mutations Eloquent ultérieures
            // ne propageraient plus vers AD (race silencieuse perte d'écritures).
            WorkstationGroupObserver::enableSync();
            WorkstationObserver::enableSync();
            AppProfileObserver::enableSync();
        }
    }

    /**
     * Calcule si l'exécution est idempotente : aucune écriture pour
     * aucune entité (rapport « vrai no-op »).
     */
    private function computeIdempotent(): bool
    {
        foreach (['workstation_groups', 'app_profiles', 'workstations'] as $entity) {
            foreach (['created', 'updated', 'restored', 'archived'] as $action) {
                if (($this->stats[$entity][$action] ?? 0) > 0) {
                    return false;
                }
            }
        }
        if (($this->stats['profile_group_links']['created'] ?? 0) > 0) {
            return false;
        }

        return true;
    }

    // ========================================================================
    // PASSE 1 — Récupération des données AD (lecture seule)
    // ========================================================================

    /**
     * Récupère les parcs (groupes de sécurité) depuis OU=Parcs.
     *
     * `protected` plutôt que `private` : permet aux tests feature
     * (`SyncAllFromAdJobTest`) de fournir des fixtures via une sous-classe
     * sans avoir à mocker LdapRecord. Pas une API publique.
     */
    protected function fetchParcsFromAd(): array
    {
        $parcs = [];
        $dnHelper = app(\App\Config\LdapDnHelper::class);
        $parcsDn = $dnHelper->parcsDn();
        $results = DeviceGroupTagModel::in($parcsDn)->get();

        foreach ($results as $parc) {
            $name = $parc->getParcName();
            if (empty($name)) {
                continue;
            }

            $rawGuid = $parc->getFirstAttribute('objectguid');

            $parcs[] = [
                'name' => $name,
                'description' => $parc->getDescription(),
                'dn' => $parc->getDn(),
                'uuid' => $rawGuid ? $this->convertGuidToString($rawGuid) : null,
            ];
        }

        return $parcs;
    }

    /**
     * Récupère les groupes (OU) depuis OU=Computers.
     *
     * `protected` pour permettre l'injection de fixtures en test.
     */
    protected function fetchGroupesFromAd(): array
    {
        $groupes = [];
        $dnHelper = app(\App\Config\LdapDnHelper::class);
        $computersDn = $dnHelper->computers();
        $results = DeviceGroupModel::in($computersDn)->get();

        foreach ($results as $groupe) {
            $name = $groupe->getGroupName();
            if (empty($name) || strtolower($name) === 'computers') {
                continue;
            }

            $rawGuid = $groupe->getFirstAttribute('objectguid');

            $groupes[] = [
                'name' => $name,
                'description' => $groupe->getGroupDescription(),
                'dn' => $groupe->getDn(),
                'uuid' => $rawGuid ? $this->convertGuidToString($rawGuid) : null,
            ];
        }

        return $groupes;
    }

    /**
     * @deprecated désactivé pour 15.3 (problème RAM serveur, cf. H3).
     *             Conservé pour réactivation conditionnelle ultérieure.
     */
    private function fetchMachinesFromAd(): array
    {
        $machines = [];

        try {
            $results = MachineModel::select([
                'cn', 'objectguid', 'iphostnumber', 'networkaddress', 'operatingsystem', 'description',
            ])->get();

            foreach ($results as $machine) {
                $name = $machine->getFirstAttribute('cn');
                if (empty($name)) {
                    continue;
                }

                $dn = $machine->getDn();
                $rawGuid = $machine->getFirstAttribute('objectguid');
                $parentGroup = $this->extractParentGroupFromDn($dn);

                $machines[] = [
                    'name' => $name,
                    'dn' => $dn,
                    'uuid' => $rawGuid ? $this->convertGuidToString($rawGuid) : null,
                    'parent_group' => $parentGroup,
                    'ip' => $machine->getFirstAttribute('iphostnumber'),
                    'mac' => $machine->getFirstAttribute('networkaddress'),
                    'os' => $machine->getFirstAttribute('operatingsystem'),
                    'description' => $machine->getFirstAttribute('description'),
                ];
            }
        } catch (\Throwable $e) {
            // Correctif review #6 : on passe par $this->logger pour
            // conserver le contexte (run_id, dry_run).
            if ($this->logger !== null) {
                $this->logger->warning('[SyncAllFromAd] fetchMachinesFromAd échec : ' . $e->getMessage());
            } else {
                Log::channel('wpkg-deploy')->warning('[SyncAllFromAd] fetchMachinesFromAd échec : ' . $e->getMessage());
            }
        }

        return $machines;
    }

    // ========================================================================
    // PASSE 2 — Synchronisation Eloquent (transactionnelle)
    // ========================================================================

    /**
     * Synchronise les `WorkstationGroup` depuis OU=Computers.
     *
     * Match strict premier run (AC3.7) : par `objectGUID` en priorité (clé
     * immutable AD), fallback `name` lower-case **+ scope OU précis**
     * (`,OU=Computers,` dans le DN AD). Jamais d'écrasement d'un GUID
     * déjà posé.
     *
     * Correctif review #2 : détection préalable des doublons GUID côté DB
     * (corruption « 2 rows pour 1 GUID ») → halte propre + log + entrée
     * `error_logs`. Évite l'écrasement silencieux par `keyBy`.
     */
    private function syncWorkstationGroups(array $groupesAd, bool $applyWrites): void
    {
        // Note : la pré-détection de conflits GUID est faite hors transaction
        // (cf. `detectGuidConflictsOrAbort()`), donc ici on peut keyBy
        // sereinement.
        $byGuid = WorkstationGroup::query()
            ->whereNotNull('ad_guid')
            ->get()
            ->keyBy('ad_guid');

        // Match « premier run » : on n'utilise le fallback nom QUE pour les
        // rows DB sans GUID. Les rows DB déjà GUID-ées doivent matcher leur
        // tuple AD par GUID — sinon archivage propre.
        $byNameNoGuid = WorkstationGroup::query()
            ->whereNull('ad_guid')
            ->get()
            ->keyBy(fn ($g) => strtolower($g->name));

        // Liste des IDs de rows DB GUID-ées **avant** la passe écriture :
        // sert à délimiter le périmètre d'archivage (les rows créées
        // pendant cette passe ne doivent jamais être archivées dans la
        // foulée — bug détecté en T4).
        $preExistingGuidIds = $byGuid->pluck('id')->all();

        $matchedDbIds = [];

        foreach ($groupesAd as $groupeAd) {
            $name = $groupeAd['name'];
            $guid = $groupeAd['uuid'] ?? null;
            $dn = $groupeAd['dn'] ?? null;

            $isComputerScope = is_string($dn) && stripos($dn, ',OU=Computers,') !== false;

            $group = null;

            if ($guid && $byGuid->has($guid)) {
                $group = $byGuid->get($guid);
            } elseif ($isComputerScope) {
                // Match strict : seulement si le DN AD est bien dans
                // OU=Computers — évite les faux positifs si un parc et un
                // groupe physique partagent le même nom (cf. AC3.7 R6).
                $group = $byNameNoGuid->get(strtolower($name));
            }

            if ($group) {
                $matchedDbIds[$group->id] = true;
                $updated = false;
                $restored = false;

                if (empty($group->ad_guid) && ! empty($guid)) {
                    $group->ad_guid = $guid;
                    $updated = true;
                }
                if (empty($group->ad_dn) && ! empty($dn)) {
                    $group->ad_dn = $dn;
                    $updated = true;
                }
                // Correctif Q2 review : Eloquent reste souverain sur le
                // `name` SQL. Une divergence AD/SQL est tracée mais
                // n'écrase rien.
                $this->detectNameDivergence('workstation_group', $group->ad_guid ?: $guid ?: '', $group->name, $name);

                // Si la row était archivée mais réapparaît AD → restauration.
                if ($group->archived_at !== null) {
                    $group->archived_at = null;
                    $updated = true;
                    $restored = true;
                }

                if ($updated) {
                    if ($applyWrites) {
                        $group->save();
                    }
                    if ($restored) {
                        $this->stats['workstation_groups']['restored']++;
                        $this->logger->info('[SyncAllFromAd] WorkstationGroup restauré (archived_at=null)', [
                            'id' => $group->id,
                            'name' => $group->name,
                            'ad_guid' => $group->ad_guid,
                        ]);
                    } else {
                        $this->stats['workstation_groups']['updated']++;
                    }
                } else {
                    // Idempotent : aucune écriture, aucun saveQuietly
                    // (correctif review #3 / Q1 — last_seen_at supprimé).
                    $this->stats['workstation_groups']['skipped']++;
                }
            } else {
                if ($applyWrites) {
                    WorkstationGroup::create([
                        'name' => $name,
                        'display_name' => $groupeAd['description'] ?? $name,
                        'description' => $groupeAd['description'] ?? null,
                        'is_physical' => true,
                        'ad_dn' => $dn,
                        'ad_guid' => $guid,
                        'is_active' => true,
                    ]);
                }
                $this->stats['workstation_groups']['created']++;
            }
        }

        // Archivage des rows DB GUID-ées dont l'AD ne renvoie plus rien.
        // On limite explicitement le scope aux rows GUID-ées **avant** la
        // passe : les rows créées pendant cette passe sont matchées par
        // construction, et certains drivers SQLite peuvent renvoyer des
        // résultats avec lecture après écriture qui les ferait apparaître
        // comme orphelines à tort.
        $orphanIds = array_diff($preExistingGuidIds, array_keys($matchedDbIds));
        if ($orphanIds === []) {
            return;
        }

        $orphans = WorkstationGroup::query()
            ->whereIn('id', $orphanIds)
            ->whereNull('archived_at')
            ->get();

        foreach ($orphans as $orphan) {
            if ($applyWrites) {
                $orphan->archived_at = now();
                $orphan->save();
            }
            $this->stats['workstation_groups']['archived']++;
            // Correctif review #6 : passage par $this->logger (avec context
            // run_id/dry_run) plutôt que `Log::channel(...)` direct.
            $this->logger->warning('[SyncAllFromAd] WorkstationGroup archivé', [
                'id' => $orphan->id,
                'name' => $orphan->name,
                'ad_guid' => $orphan->ad_guid,
            ]);
        }
    }

    /**
     * Synchronise les `AppProfile` depuis OU=Parcs.
     *
     * Match strict premier run : par GUID en priorité, fallback nom
     * lower-case **+ scope OU=Parcs précis** (jamais cross-OU).
     */
    private function syncAppProfiles(array $parcsAd, bool $applyWrites): void
    {
        $byGuid = AppProfile::query()
            ->whereNotNull('ad_guid')
            ->get()
            ->keyBy('ad_guid');

        $byNameNoGuid = AppProfile::query()
            ->whereNull('ad_guid')
            ->get()
            ->keyBy(fn ($p) => strtolower($p->name));

        $preExistingGuidIds = $byGuid->pluck('id')->all();

        $matchedDbIds = [];

        foreach ($parcsAd as $parcAd) {
            $name = $parcAd['name'];
            $guid = $parcAd['uuid'] ?? null;
            $dn = $parcAd['dn'] ?? null;

            $isParcsScope = is_string($dn) && stripos($dn, ',OU=Parcs,') !== false;

            $profile = null;

            if ($guid && $byGuid->has($guid)) {
                $profile = $byGuid->get($guid);
            } elseif ($isParcsScope) {
                $profile = $byNameNoGuid->get(strtolower($name));
            }

            if ($profile) {
                $matchedDbIds[$profile->id] = true;
                $updated = false;
                $restored = false;

                if (empty($profile->ad_guid) && ! empty($guid)) {
                    $profile->ad_guid = $guid;
                    $updated = true;
                }
                if (empty($profile->ad_dn) && ! empty($dn)) {
                    $profile->ad_dn = $dn;
                    $updated = true;
                }
                // Q2 : divergence de nom tracée sans écraser.
                $this->detectNameDivergence('app_profile', $profile->ad_guid ?: $guid ?: '', $profile->name, $name);

                if ($profile->archived_at !== null) {
                    $profile->archived_at = null;
                    $updated = true;
                    $restored = true;
                }

                if ($updated) {
                    if ($applyWrites) {
                        $profile->save();
                    }
                    if ($restored) {
                        $this->stats['app_profiles']['restored']++;
                        $this->logger->info('[SyncAllFromAd] AppProfile restauré (archived_at=null)', [
                            'id' => $profile->id,
                            'name' => $profile->name,
                            'ad_guid' => $profile->ad_guid,
                        ]);
                    } else {
                        $this->stats['app_profiles']['updated']++;
                    }
                } else {
                    $this->stats['app_profiles']['skipped']++;
                }
            } else {
                if ($applyWrites) {
                    AppProfile::create([
                        'name' => $name,
                        'display_name' => $parcAd['description'] ?? $name,
                        'description' => $parcAd['description'] ?? null,
                        'ad_guid' => $guid,
                        'ad_dn' => $dn,
                        'is_active' => true,
                    ]);
                }
                $this->stats['app_profiles']['created']++;
            }
        }

        $orphanIds = array_diff($preExistingGuidIds, array_keys($matchedDbIds));
        if ($orphanIds === []) {
            return;
        }

        $orphans = AppProfile::query()
            ->whereIn('id', $orphanIds)
            ->whereNull('archived_at')
            ->get();

        foreach ($orphans as $orphan) {
            if ($applyWrites) {
                $orphan->archived_at = now();
                $orphan->save();
            }
            $this->stats['app_profiles']['archived']++;
            $this->logger->warning('[SyncAllFromAd] AppProfile archivé', [
                'id' => $orphan->id,
                'name' => $orphan->name,
                'ad_guid' => $orphan->ad_guid,
            ]);
        }
    }

    /**
     * Crée les liens entre `AppProfile` et `WorkstationGroup` ayant le
     * même nom. Idempotent : skip si lien déjà présent.
     */
    private function syncProfileGroupLinks(bool $applyWrites): void
    {
        $profiles = AppProfile::query()->whereNull('archived_at')->get();
        $groups = WorkstationGroup::query()
            ->whereNull('archived_at')
            ->get()
            ->keyBy(fn ($g) => strtolower($g->name));

        $existingLinks = DB::table('app_profile_workstation_group')
            ->select('app_profile_id', 'workstation_group_id')
            ->get()
            ->groupBy('app_profile_id')
            ->map(fn ($rows) => array_flip($rows->pluck('workstation_group_id')->map(fn ($id) => (int) $id)->all()));

        foreach ($profiles as $profile) {
            $nameLower = strtolower($profile->name);

            if (! $groups->has($nameLower)) {
                continue;
            }

            $group = $groups->get($nameLower);
            $linkedGroupIds = $existingLinks->get($profile->id, []);

            if (! isset($linkedGroupIds[$group->id])) {
                if ($applyWrites) {
                    $profile->workstationGroups()->attach($group->id);
                }
                $this->stats['profile_group_links']['created']++;
            } else {
                $this->stats['profile_group_links']['skipped']++;
            }
        }
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    /**
     * Q2 (review post 15.3) — Détecte une divergence de nom entre l'AD et
     * la DB pour un GUID matché. Eloquent reste souverain : on ne réécrit
     * pas le `name` SQL ; on log un `info` (corrélation pipeline) **et**
     * on persiste un `error_logs` source `wpkg` pour visibilité opérateur.
     * Incrémente le compteur `name_divergences` du rapport stats.
     */
    private function detectNameDivergence(string $entityKind, string $guid, string $dbName, string $adName): void
    {
        if ($dbName === $adName || strtolower($dbName) === strtolower($adName)) {
            return;
        }

        $statsKey = $entityKind === 'workstation_group' ? 'workstation_groups' : 'app_profiles';
        $this->stats[$statsKey]['name_divergences']++;

        $this->logger?->info('[SyncAllFromAd] Divergence nom AD/SQL pour GUID', [
            'guid' => $guid,
            'db_name' => $dbName,
            'ad_name' => $adName,
            'entity' => $entityKind,
        ]);

        try {
            app(ErrorLoggerService::class)->log(
                'wpkg',
                "Divergence nom AD/SQL — GUID={$guid} entité={$entityKind} SQL='{$dbName}' AD='{$adName}' — Eloquent reste source de vérité, vérifier intervention manuelle dans AD"
            );
        } catch (\Throwable $e) {
            // ErrorLoggerService est silencieux par contrat ; on protège
            // tout de même contre une indispo container exotique en test.
            $this->logger?->warning('[SyncAllFromAd] ErrorLoggerService indisponible : ' . $e->getMessage());
        }
    }

    /**
     * Correctif review #2 — Pré-détection des conflits GUID en DB
     * **avant** `DB::beginTransaction` pour que l'écriture `error_logs`
     * survive au rollback. Halte propre par exception (rattrapée par le
     * `try/catch/finally` de `handle()` qui libère le lock).
     */
    private function detectGuidConflictsOrAbort(): void
    {
        foreach ([
            ['kind' => 'workstation_group', 'model' => WorkstationGroup::class],
            ['kind' => 'app_profile', 'model' => AppProfile::class],
        ] as $entity) {
            /** @var class-string<\Illuminate\Database\Eloquent\Model> $modelClass */
            $modelClass = $entity['model'];
            $grouped = $modelClass::query()
                ->whereNotNull('ad_guid')
                ->get()
                ->groupBy('ad_guid');

            $conflicts = $grouped->filter(fn ($g) => $g->count() > 1);
            if ($conflicts->isNotEmpty()) {
                $this->raiseGuidConflict($entity['kind'], $conflicts->keys()->all());
            }
        }
    }

    /**
     * #2 review — Halte propre sur conflit GUID (corruption DB :
     * plusieurs rows pour le même `ad_guid`). Log error + entrée
     * `error_logs` + RuntimeException pour halte propre.
     *
     * @param  string  $entityKind  workstation_group|app_profile
     * @param  array<int,string>  $conflictGuids  liste des GUIDs en conflit
     */
    private function raiseGuidConflict(string $entityKind, array $conflictGuids): void
    {
        $statsKey = $entityKind === 'workstation_group' ? 'workstation_groups' : 'app_profiles';
        $this->stats[$statsKey]['conflicts'] = count($conflictGuids);
        $this->stats['aborted_reason'] = 'conflict_guid';

        $guidList = implode(',', $conflictGuids);

        $this->logger?->error('[SyncAllFromAd] Conflit GUID détecté', [
            'conflicts' => $conflictGuids,
            'entity' => $entityKind,
        ]);

        try {
            app(ErrorLoggerService::class)->log(
                'wpkg',
                "Conflit GUID {$entityKind} — GUIDs dupliqués: {$guidList} — halte propre, intervention manuelle requise"
            );
        } catch (\Throwable) {
            // Silencieux — on lève quand même l'exception pour halte propre.
        }

        throw new \RuntimeException("Conflit GUID {$entityKind} — halte propre, intervention manuelle requise");
    }

    /**
     * Extrait le nom du groupe parent depuis le DN AD.
     * Ex. : `CN=PC01,OU=info1,OU=Computers,DC=…` → `info1`.
     */
    private function extractParentGroupFromDn(string $dn): ?string
    {
        if (preg_match('/^CN=[^,]+,OU=([^,]+),/i', $dn, $matches)) {
            $parent = $matches[1];
            if (strtolower($parent) !== 'computers') {
                return $parent;
            }
        }

        return null;
    }

    /**
     * Convertit un `objectGUID` binaire AD en string dashed lisible.
     * Format de sortie : `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx`.
     */
    private function convertGuidToString(string $binaryGuid): string
    {
        $hex = bin2hex($binaryGuid);

        if (strlen($hex) !== 32) {
            return $hex;
        }

        return sprintf(
            '%s%s%s%s-%s%s-%s%s-%s-%s',
            substr($hex, 6, 2),
            substr($hex, 4, 2),
            substr($hex, 2, 2),
            substr($hex, 0, 2),
            substr($hex, 10, 2),
            substr($hex, 8, 2),
            substr($hex, 14, 2),
            substr($hex, 12, 2),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::channel('wpkg-deploy')->error('[SyncAllFromAd] Job échoué définitivement', [
            'error' => $exception->getMessage(),
            'run_id' => $this->runId,
            'dry_run' => $this->dryRun,
        ]);
    }
}
