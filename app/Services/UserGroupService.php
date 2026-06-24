<?php

declare(strict_types=1);

namespace App\Services;

use App\Facades\SEConfig;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Constants\Ldap\FunctionGroups;
use App\Constants\Ldap\MainGroups;
use App\Repositories\GroupRepository;
use App\Repositories\RightRepository;
use App\Repositories\UserGroupRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class UserGroupService
{
    public function __construct(
        private UserGroupRepository $repository,
        private GroupRepository $groupRepository,
        private RightRepository $rightRepository,
    ) {
    }

    public function listPaginated(?string $search, int $perPage = 20): LengthAwarePaginator
    {
        return $this->repository->paginate($search, $perPage);
    }

    public function getById(int $id): ?UserGroup
    {
        return $this->repository->findById($id);
    }

    public function getAssignableUsers(): Collection
    {
        return User::query()
            ->select(['id', 'login', 'fullname', 'lastname', 'firstname'])
            ->orderByRaw("COALESCE(lastname, '')")
            ->orderByRaw("COALESCE(firstname, '')")
            ->orderBy('login')
            ->get();
    }

    public function createGroup(array $data): UserGroup
    {
        $payload = $this->validateData($data);

        $this->guardReservedPrefixOnCreate($payload['name'], $payload['type']);

        $selectedUserIds = !empty($data['user_ids']) && is_array($data['user_ids'])
            ? array_values(array_unique(array_map('intval', $data['user_ids'])))
            : [];

        $created = $this->groupRepository->createGroup(
            name: $payload['name'],
            description: $payload['display_name'] ?? $payload['name'],
            type: $this->mapTypeToLdap($payload['type']),
        );

        if (!$created) {
            throw new RuntimeException("Création AD impossible pour le groupe '{$payload['name']}'.");
        }

        // Sélecteur SQL post-syncFromAd : depuis 4.13, les variantes de classe/
        // équipe foldent en UNE ligne au NOM NU. Le payload `name` est déjà nu
        // (garanti sans préfixe réservé par guardReservedPrefixOnCreate) ; pour
        // les autres types, c'est le CN brut résolu (Cours_X, Matiere_X@Y, …).
        $lookupName = $this->resolveSqlLookupName($payload['name'], $payload['type']);

        if (count($selectedUserIds) > 0) {
            $this->syncRoleAwareAdGroupMembers($payload['name'], $payload['type'], $selectedUserIds);
        }

        $this->syncFromAd();

        $primaryGroup = UserGroup::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($lookupName)])
            ->first();

        if ($primaryGroup === null) {
            throw new RuntimeException('Le groupe AD a été créé mais introuvable après synchronisation SQL.');
        }

        return $primaryGroup;
    }

    public function updateGroup(int $id, array $data): UserGroup
    {
        $group = $this->repository->findById($id);

        if ($group === null) {
            throw new \InvalidArgumentException('Groupe introuvable.');
        }

        $payload = $this->validateData($data, $group);
        $oldName = $group->name;
        $newName = $payload['name'];

        if ($oldName !== $newName) {
            $renamed = $this->groupRepository->renameGroup($oldName, $newName);

            if (!$renamed) {
                throw new RuntimeException("Renommage AD impossible pour le groupe '{$oldName}' -> '{$newName}'.");
            }
        } else {
            $updated = $this->groupRepository->updateGroupDescription(
                cn: $newName,
                description: $payload['display_name'] ?? $newName,
            );

            if (!$updated) {
                throw new RuntimeException("Mise à jour AD impossible pour le groupe '{$newName}'.");
            }
        }

        if (array_key_exists('user_ids', $data) && is_array($data['user_ids'])) {
            $selectedUserIds = array_values(array_unique(array_map('intval', $data['user_ids'])));
            $this->syncRoleAwareAdGroupMembers($newName, $payload['type'], $selectedUserIds);
        }

        $this->syncFromAd();

        // 4.13 — lookup post-sync au NOM NU pour les classes/équipes foldées :
        // l'edit-form peut renvoyer le CN stocké (`Classe_3A`) alors que la ligne
        // foldée est persistée au nom nu (`3A`). Les autres types restent au CN.
        $lookupName = $this->resolveSqlLookupName($newName, $payload['type']);

        $updatedGroup = UserGroup::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($lookupName)])
            ->with('users')
            ->first();

        if ($updatedGroup === null) {
            throw new RuntimeException('Le groupe AD a été mis à jour mais introuvable après synchronisation SQL.');
        }

        return $updatedGroup;
    }

    public function deleteGroup(int $id): void
    {
        $group = $this->repository->findById($id);

        if ($group === null) {
            throw new \InvalidArgumentException('Groupe introuvable.');
        }

        $deleted = $this->groupRepository->deleteGroup($group->name);

        if (!$deleted) {
            throw new RuntimeException("Suppression AD impossible pour le groupe '{$group->name}'.");
        }

        $this->syncFromAd();
    }

    public function bulkDelete(array $groupIds): int
    {
        $ids = collect($groupIds)
            ->map(fn(mixed $id): int => (int) $id)
            ->filter(fn(int $id): bool => $id > 0)
            ->unique()
            ->values();

        $deletedCount = 0;

        DB::transaction(function () use ($ids, &$deletedCount): void {
            foreach ($ids as $id) {
                $this->deleteGroup($id);
                $deletedCount++;
            }
        });

        return $deletedCount;
    }

    public function syncGroupsWithAd(array $groupIds): int
    {
        $ids = collect($groupIds)
            ->map(fn(mixed $id): int => (int) $id)
            ->filter(fn(int $id): bool => $id > 0)
            ->unique()
            ->values();

        $groupNames = UserGroup::query()
            ->whereIn('id', $ids)
            ->pluck('name')
            ->map(fn(mixed $name): string => (string) $name)
            ->filter(fn(string $name): bool => $name !== '')
            ->values()
            ->all();

        if (count($groupNames) === 0) {
            return 0;
        }

        $this->syncFromAd(onlyGroupNames: $groupNames);

        return count($groupNames);
    }

    /**
     * Alias de compatibilité legacy.
     *
     * @param callable|null $logger fn(string $level, string $message)
     * @return array{
     *   created:int,
     *   updated:int,
     *   skipped:int,
     *   linked_users:int,
     *   detached_users:int,
     *   deleted:int,
     *   errors:int,
     *   total_groups_detected:int,
     *   total_cn_detected:int,
     *   total_groups_folded:int
     * }
     */
    public function importFromUsersAdGroups(?callable $logger = null): array
    {
        return $this->syncFromAd($logger);
    }

    /**
     * Synchronise les groupes utilisateurs AD -> SQL (SQL = cache)
     *
     * @param callable|null $logger fn(string $level, string $message)
     * @param array<int,string> $onlyGroupNames
     * @return array{
     *   created:int,
     *   updated:int,
     *   skipped:int,
     *   linked_users:int,
     *   detached_users:int,
     *   deleted:int,
     *   errors:int,
     *   total_groups_detected:int,
     *   total_cn_detected:int,
     *   total_groups_folded:int
     * }
     */
    public function syncFromAd(?callable $logger = null, array $onlyGroupNames = []): array
    {
        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'linked_users' => 0,
            'detached_users' => 0,
            'deleted' => 0,
            'errors' => 0,
            // total_groups_detected : conservé pour compat (= nb de CN bruts AD).
            // total_cn_detected : alias explicite des CN bruts.
            // total_groups_folded : nb de LIGNES SQL réellement projetées (après
            // fold) — la vraie « unité métier » présentée dans l'UI.
            'total_groups_detected' => 0,
            'total_cn_detected' => 0,
            'total_groups_folded' => 0,
        ];

        $log = $logger ?? fn(string $level, string $message) => Log::log($level, "[UserGroupService] {$message}");

        $eligibleGroups = $this->fetchEligibleAdGroups();

        if (count($onlyGroupNames) > 0) {
            $allowed = array_flip(array_map(
                static fn(string $name): string => mb_strtolower(trim($name)),
                $onlyGroupNames
            ));

            // 4.13 — Les `name` SQL des classes/équipes sont désormais NUS
            // (`3A`), mais les CN AD restent préfixés (`Classe_3A`/`Equipe_3A`/
            // `PP_3A`). `syncGroupsWithAd` passe les noms NUS persistés ; il faut
            // donc faire matcher chaque CN AD sur sa base nue AUTANT que sur le
            // CN brut, sinon le filtre vide tout et la sync ciblée est un no-op
            // (le bouton « Synchroniser avec AD » ne ferait plus rien).
            $eligibleGroups = array_values(array_filter(
                $eligibleGroups,
                function (array $group) use ($allowed): bool {
                    $cn = trim((string) ($group['cn'] ?? ''));
                    if ($cn === '') {
                        return false;
                    }

                    if (isset($allowed[mb_strtolower($cn)])) {
                        return true;
                    }

                    // CN de classe/équipe (Classe_/Equipe_/PP_) → matcher aussi
                    // sa base nue contre les noms NUS demandés.
                    if ($this->foldPrefixOf($cn) !== null) {
                        return isset($allowed[mb_strtolower($this->stripClasseLikePrefix($cn))]);
                    }

                    return false;
                }
            ));
        }

        // 4.13 — Fold import : on replie les variantes AD d'une même base
        // (Classe_X / Equipe_X / PP_X) en UNE seule projection SQL au nom nu
        // (X). On regroupe AVANT toute écriture pour faire un seul
        // users()->sync() par ligne avec l'UNION des membres des CN — sinon un
        // sync() par CN écraserait les membres déjà posés.
        $foldedGroups = $this->buildFoldedGroups($eligibleGroups);

        // Correction review #6 — distinguer les deux compteurs. Avant 4.13 une
        // classe = 3 CN = 3 lignes ; après fold, 3 CN → 1 ligne. Compter les CN
        // bruts comme « groupes détectés » est trompeur dans l'UI. On expose
        // donc explicitement les CN bruts (`total_cn_detected`) ET les lignes
        // réellement projetées (`total_groups_folded`). `total_groups_detected`
        // (compat) reste l'alias des CN bruts.
        $stats['total_cn_detected'] = count($eligibleGroups);
        $stats['total_groups_detected'] = count($eligibleGroups);
        $stats['total_groups_folded'] = count($foldedGroups);
        $log(
            'info',
            sprintf(
                '%d CN AD détecté(s) → %d groupe(s) projeté(s) après fold',
                $stats['total_cn_detected'],
                $stats['total_groups_folded']
            )
        );

        UserGroupObserver::disableSync();

        try {
            DB::transaction(function () use (&$stats, $foldedGroups, $onlyGroupNames): void {
                $detectedNames = [];

                foreach ($foldedGroups as $folded) {
                    try {
                        $groupName = $folded['name'];
                        if ($groupName === '') {
                            continue;
                        }

                        $detectedNames[] = mb_strtolower($groupName);
                        $adGuid = $folded['ad_guid'];
                        $adDn = $folded['ad_dn'];

                        $group = null;

                        if ($adGuid !== null) {
                            $group = UserGroup::query()->where('ad_guid', $adGuid)->first();
                        }

                        if ($group === null) {
                            $group = UserGroup::query()->where('name', $groupName)->first();
                        }

                        if ($group === null && $adDn !== '') {
                            $group = UserGroup::query()->where('ad_dn', $adDn)->first();
                        }

                        if ($adGuid !== null && $group !== null) {
                            $conflict = UserGroup::query()
                                ->where('ad_guid', $adGuid)
                                ->where('id', '!=', $group->id)
                                ->first();

                            if ($conflict !== null) {
                                throw new RuntimeException(sprintf(
                                    'Conflit ad_guid %s entre groupes SQL "%s" et "%s"',
                                    $adGuid,
                                    $group->name,
                                    $conflict->name
                                ));
                            }
                        }

                        $detectedType = $folded['type'];
                        $displayName = $folded['display_name'];

                        if ($group === null) {
                            $group = UserGroup::query()->create([
                                'name' => $groupName,
                                'display_name' => $displayName,
                                'type' => $detectedType,
                                'ad_dn' => $adDn !== '' ? $adDn : null,
                                'ad_guid' => $adGuid,
                            ]);
                            $stats['created']++;
                        } else {
                            $updated = false;

                            if (($group->name ?? '') !== $groupName) {
                                $group->name = $groupName;
                                $updated = true;
                            }

                            if (($group->display_name ?? '') !== $displayName) {
                                $group->display_name = $displayName;
                                $updated = true;
                            }

                            // Le type n'existe pas dans l'AD, il est inféré depuis le nom du groupe.
                            // On le recalcule systématiquement pour corriger tout écart SQL.
                            $group->type = $detectedType;
                            if ($group->isDirty('type')) {
                                $updated = true;
                            }

                            if (($group->ad_dn ?? '') !== ($adDn !== '' ? $adDn : null)) {
                                $group->ad_dn = $adDn !== '' ? $adDn : null;
                                $updated = true;
                            }

                            if (($group->ad_guid ?? null) !== $adGuid) {
                                $group->ad_guid = $adGuid;
                                $updated = true;
                            }

                            if ($updated) {
                                $group->save();
                                $stats['updated']++;
                            } else {
                                $stats['skipped']++;
                            }
                        }

                        // Union des membres des CN du groupe foldé (un seul sync()).
                        $memberIds = [];
                        foreach ($folded['cns'] as $cn) {
                            foreach ($this->resolveMemberUserIdsFromAdGroup($cn) as $memberId) {
                                $memberIds[] = (int) $memberId;
                            }
                        }

                        $syncChanges = $group->users()->sync(array_values(array_unique($memberIds)));
                        $stats['linked_users'] += count($syncChanges['attached'] ?? []);
                        $stats['detached_users'] += count($syncChanges['detached'] ?? []);
                    } catch (\Throwable $e) {
                        Log::warning('[UserGroupService] Erreur sync group AD -> SQL', [
                            'error' => $e->getMessage(),
                        ]);
                        $stats['errors']++;
                    }
                }

                if (count($onlyGroupNames) === 0) {
                    // Comparer aux noms NUS effectivement persistés (pas les CN
                    // bruts d'origine) : sinon la ligne foldée `3A` tomberait dans
                    // le whereNotIn (les CN `classe_3a`/`equipe_3a` ne sont plus des
                    // `name` SQL) et serait supprimée à chaque sync.
                    $deleted = UserGroup::query()
                        ->whereNotIn(DB::raw('LOWER(name)'), $detectedNames)
                        ->delete();

                    $stats['deleted'] += $deleted;
                }
            });
        } finally {
            UserGroupObserver::enableSync();
        }

        $log(
            'info',
            "Import groupes utilisateurs terminé: {$stats['created']} créés, {$stats['updated']} mis à jour, " .
                "{$stats['skipped']} inchangés, {$stats['linked_users']} liaison(s) ajoutée(s), " .
                "{$stats['detached_users']} liaison(s) retirée(s), {$stats['deleted']} supprimé(s), {$stats['errors']} erreur(s)"
        );

        return $stats;
    }

    /**
     * @return array<int,array{cn:string,dn:string,description:string,objectguid:mixed}>
     */
    private function fetchEligibleAdGroups(): array
    {
        try {
            $knownRightsGroupNames = array_map(
                static fn(string $name): string => mb_strtolower(trim($name)),
                array_keys($this->rightRepository->getAllRightsValues())
            );

            $excludedRdns = array_values(array_filter([
                mb_strtolower(trim((string) SEConfig::get('rights_rdn', ''))),
                mb_strtolower(trim((string) SEConfig::get('delegations_rdn', ''))),
            ], static fn(string $rdn): bool => $rdn !== ''));
            $groupsRdn = mb_strtolower(trim((string) SEConfig::get('groups_rdn', '')));

            return $this->groupRepository
                ->getGroupsWithMemberCount()
                ->filter(function (array $group) use ($knownRightsGroupNames, $excludedRdns, $groupsRdn): bool {
                    $cn = trim((string) ($group['cn'] ?? ''));
                    $dn = mb_strtolower(trim((string) ($group['dn'] ?? '')));

                    if ($cn === '') {
                        return false;
                    }

                    if ($dn === '') {
                        return false;
                    }

                    if ($groupsRdn !== '' && preg_match('/(^|,)' . preg_quote($groupsRdn, '/') . '(,|$)/i', $dn) !== 1) {
                        return false;
                    }

                    foreach ($excludedRdns as $rdn) {
                        if (preg_match('/(^|,)' . preg_quote($rdn, '/') . '(,|$)/i', $dn) === 1) {
                            return false;
                        }
                    }

                    $cnLower = mb_strtolower($cn);

                    if (in_array($cnLower, $knownRightsGroupNames, true)) {
                        return false;
                    }

                    return true;
                })
                ->map(function (array $group): array {
                    return [
                        'cn' => trim((string) ($group['cn'] ?? '')),
                        'dn' => trim((string) ($group['dn'] ?? '')),
                        'description' => trim((string) ($group['description'] ?? '')),
                        'objectguid' => $group['objectguid'] ?? null,
                    ];
                })
                ->filter(fn(array $group): bool => ($group['cn'] ?? '') !== '')
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable $exception) {
            Log::warning('[UserGroupService] Impossible de charger les groupes AD pour l\'import SQL', [
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Préfixes des variantes AD d'une même classe (foldables ensemble).
     * Le CN canonique est toujours le premier disponible dans cet ordre
     * (D2 : `Classe_` > `Equipe_` > `PP_`).
     *
     * @var array<int,string>
     */
    private const FOLD_PREFIXES = ['Classe_', 'Equipe_', 'PP_'];

    /**
     * 4.13 — Replie les CN AD éligibles en projections SQL « nom nu ».
     *
     * Pour les variantes de classe/équipe (`Classe_X`/`Equipe_X`/`PP_X`) d'une
     * même base `X`, produit UNE seule entrée au nom nu `X` avec :
     * - `cns` : la liste des CN AD à unir pour les membres (T1.4) ;
     * - `ad_guid`/`ad_dn` du CN canonique (D2 : `Classe_` > `Equipe_` > `PP_`) ;
     * - `type = 'classe'` (D3) ;
     * - `display_name` = description du CN canonique (fallback nom nu).
     *
     * Règle D1 (`Equipe_` orphelin) : un `Equipe_Y` ne fold avec sa base que si
     * un `Classe_Y`/`PP_Y` est présent dans le lot OU si une ligne nue `Y` de
     * type classe/équipe préexiste en SQL. Sinon il reste sa propre projection
     * (nom nu `Y`, type `equipe`) — il ne fold jamais avec un `Cours_Y`.
     *
     * Les autres CN (`Cours_`, `Projet_`, `Matiere_`, `Matiere_@`, rôle/
     * fonction/custom) restent 1 CN = 1 projection (comportement inchangé) ;
     * leur `name` reste le CN brut.
     *
     * @param array<int,array{cn:string,dn:string,description:string,objectguid:mixed}> $eligibleGroups
     * @return array<int,array{name:string, cns:array<int,string>, ad_guid:?string, ad_dn:string, type:string, display_name:string}>
     */
    private function buildFoldedGroups(array $eligibleGroups): array
    {
        // 1) Recenser les bases qui possèdent au moins un CN « ancre » de classe
        //    (Classe_ ou PP_) dans le lot — elles autorisent le fold d'un Equipe_.
        $foldAnchorBases = [];
        foreach ($eligibleGroups as $groupData) {
            $cn = trim((string) ($groupData['cn'] ?? ''));
            if ($cn === '') {
                continue;
            }
            $prefix = $this->foldPrefixOf($cn);
            if ($prefix === 'Classe_' || $prefix === 'PP_') {
                $foldAnchorBases[mb_strtolower($this->stripClasseLikePrefix($cn))] = true;
            }
        }

        /** @var array<string,array{name:string, cns:array<int,string>, byPrefix:array<string,array{ad_guid:?string,ad_dn:string,description:string}>}> $folds */
        $folds = [];
        /** @var array<int,array{name:string, cns:array<int,string>, ad_guid:?string, ad_dn:string, type:string, display_name:string}> $standalone */
        $standalone = [];

        foreach ($eligibleGroups as $groupData) {
            $cn = trim((string) ($groupData['cn'] ?? ''));
            if ($cn === '') {
                continue;
            }

            $adGuid = $this->convertAdGuidToString($groupData['objectguid'] ?? null);
            $adDn = trim((string) ($groupData['dn'] ?? ''));
            $description = trim((string) ($groupData['description'] ?? ''));

            $prefix = $this->foldPrefixOf($cn);
            $base = $prefix !== null ? $this->stripClasseLikePrefix($cn) : $cn;
            $baseKey = mb_strtolower($base);

            $foldable = $prefix !== null && $this->shouldFold($prefix, $baseKey, $foldAnchorBases);

            if (!$foldable) {
                // Cas 1 — `Equipe_` orphelin (D1) : pas d'ancre Classe_/PP_ ni de
                // ligne nue classe/équipe préexistante. Il ne fold pas avec un
                // éventuel `Cours_Y`, mais devient quand même SA PROPRE ligne au
                // NOM NU `Y` de type `equipe` (AC6) — pas le CN brut.
                if ($prefix === 'Equipe_') {
                    $standalone[] = [
                        'name' => $base,
                        'cns' => [$cn],
                        'ad_guid' => $adGuid,
                        'ad_dn' => $adDn,
                        'type' => 'equipe',
                        'display_name' => $description !== '' ? $description : $base,
                    ];
                    continue;
                }

                // Cas 2 — CN non foldable (Cours_, Projet_, Matiere_, Matiere_@,
                // rôle/fonction/custom) : 1 CN = 1 projection (nom = CN brut,
                // type détecté à l'identique).
                $standalone[] = [
                    'name' => $cn,
                    'cns' => [$cn],
                    'ad_guid' => $adGuid,
                    'ad_dn' => $adDn,
                    'type' => $this->detectTypeFromAdGroupName($cn),
                    'display_name' => $description !== '' ? $description : $cn,
                ];
                continue;
            }

            $folds[$baseKey] ??= [
                'name' => $base,
                'cns' => [],
                'byPrefix' => [],
            ];
            $folds[$baseKey]['cns'][] = $cn;
            $folds[$baseKey]['byPrefix'][$prefix] = [
                'ad_guid' => $adGuid,
                'ad_dn' => $adDn,
                'description' => $description,
            ];
        }

        $result = [];

        foreach ($folds as $fold) {
            // CN canonique = premier prefix disponible dans l'ordre D2.
            $canonical = null;
            foreach (self::FOLD_PREFIXES as $prefix) {
                if (isset($fold['byPrefix'][$prefix])) {
                    $canonical = $fold['byPrefix'][$prefix];
                    break;
                }
            }

            // Garde-fou défensif (jamais atteint : un fold a toujours ≥ 1 prefix).
            if ($canonical === null) {
                $canonical = reset($fold['byPrefix']);
            }

            $result[] = [
                'name' => $fold['name'],
                'cns' => array_values(array_unique($fold['cns'])),
                'ad_guid' => $canonical['ad_guid'],
                'ad_dn' => $canonical['ad_dn'],
                'type' => 'classe',
                'display_name' => $canonical['description'] !== '' ? $canonical['description'] : $fold['name'],
            ];
        }

        return array_merge($result, $standalone);
    }

    /**
     * Retourne le préfixe de fold ({@see FOLD_PREFIXES}) du CN, ou null si le CN
     * n'est pas une variante de classe/équipe. Casse stricte (CN AD réels =
     * `Classe_`/`Equipe_`/`PP_`, cf. GroupRepository::createGroup).
     */
    private function foldPrefixOf(string $cn): ?string
    {
        foreach (self::FOLD_PREFIXES as $prefix) {
            if (str_starts_with($cn, $prefix)) {
                return $prefix;
            }
        }

        return null;
    }

    /**
     * D1 — Décide si une variante doit folder vers le nom nu de sa base.
     *
     * `Classe_`/`PP_` foldent toujours. `Equipe_Y` ne fold que si la base `Y`
     * possède un `Classe_`/`PP_` dans le LOT AD COURANT (`$foldAnchorBases`) —
     * sinon il reste autonome (cas `Cours_Y` + `Equipe_Y` : pas d'ancre →
     * l'équipe du cours ne fold pas, elle devient sa propre ligne `equipe`).
     *
     * Story 4.13 (correction review #4/#5) — la décision repose UNIQUEMENT sur
     * le lot AD courant. L'ancienne dépendance à l'état SQL (`EXISTS` sur la
     * ligne nue déjà persistée) était (a) NON IDEMPOTENTE — au 1er run un
     * `Equipe_Y` orphelin se persistait en `type='equipe'`, puis au 2e run ce
     * `EXISTS` matchait sa propre ligne et le faisait basculer en `type='classe'`
     * (viole AC6) — et (b) une requête SQL par variante `Equipe_` (N requêtes).
     * Décider sur le seul lot AD corrige idempotence ET perf.
     *
     * @param array<string,bool> $foldAnchorBases bases (lower) avec un Classe_/PP_ dans le lot AD
     */
    private function shouldFold(string $prefix, string $baseKey, array $foldAnchorBases): bool
    {
        if ($prefix === 'Classe_' || $prefix === 'PP_') {
            return true;
        }

        // $prefix === 'Equipe_' : fold seulement si une ancre Classe_/PP_ de la
        // même base est présente dans le LOT AD courant (jamais l'état SQL).
        return isset($foldAnchorBases[$baseKey]);
    }

    /**
     * @return array<int,int>
     */
    private function resolveMemberUserIdsFromAdGroup(string $groupName): array
    {
        $members = $this->groupRepository->getGroupMembers($groupName);

        if (!$members instanceof Collection) {
            return [];
        }

        $logins = $members
            ->map(static fn(array $member): string => trim((string) ($member['cn'] ?? '')))
            ->filter(static fn(string $login): bool => $login !== '')
            ->values()
            ->all();

        if (count($logins) === 0) {
            return [];
        }

        return User::query()
            ->whereIn('login', $logins)
            ->pluck('id')
            ->map(static fn(mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Projette les membres SQL sélectionnés vers le(s) groupe(s) AD cible(s),
     * en partitionnant par rôle pour les groupes de classe/équipe (parité SE4).
     *
     * - `type ∈ {classe, equipe}` : les profs (`User::isProf()`) vont dans
     *   `Equipe_<name>`, tout le reste (élèves/admin/autre) dans `Classe_<name>`.
     *   Chaque cible est synchronisée par le diff idempotent fail-soft de
     *   {@see syncAdGroupMembersByUserIds()}. `PP_<name>` n'est pas peuplé
     *   (rôle par-arête non capturé par SE5 — limite connue documentée).
     * - autres types (`cours`, `matiere`, `projet`, `custom`…) : une seule
     *   cible résolue via {@see resolvePrimaryGroupName()} — comportement inchangé.
     *
     * Le nom reçu peut être NU (`3A`, depuis `createGroup`) ou déjà le CN
     * primaire stocké en SQL (`Classe_3A`, depuis `updateGroup` dont le form
     * renvoie `group->name`). Pour les classes/équipes on dérive donc la base
     * nue (suppression d'un éventuel préfixe `Classe_`/`Equipe_`/`PP_`) avant
     * de partitionner — c'est exactement la dé-duplication de la résolution
     * exigée par la story (createGroup résolu vs updateGroup brut).
     *
     * Bypass CN legacy préfixé d'un AUTRE type : un nom déjà préfixé par une
     * autre catégorie (`Matiere_*@*`, `Cours_*`, `Projet_*`, `Matiere_*`) n'est
     * jamais ré-expansé — on synchronise exactement ce groupe (1 SQL = 1 AD).
     *
     * @param array<int,int> $selectedUserIds
     */
    private function syncRoleAwareAdGroupMembers(string $rawName, string $type, array $selectedUserIds): void
    {
        $normalizedType = mb_strtolower(trim($type));
        $isClasseLike = in_array($normalizedType, ['class', 'classe', 'equipe'], true);

        // Type non classe/équipe : un nom déjà préfixé (CN legacy d'une autre
        // catégorie) ne doit jamais être ré-expansé — cible unique résolue.
        if (!$isClasseLike) {
            $this->syncAdGroupMembersByUserIds(
                $this->resolvePrimaryGroupName($rawName, $type),
                $selectedUserIds
            );

            return;
        }

        // Classe/équipe : dériver la base nue en retirant un éventuel préfixe
        // de classe/équipe déjà présent (CN primaire stocké en SQL).
        $baseName = $this->stripClasseLikePrefix($rawName);

        $profIds = [];
        $nonProfIds = [];

        if (count($selectedUserIds) > 0) {
            $usersById = User::query()
                ->whereIn('id', $selectedUserIds)
                ->get()
                ->keyBy(static fn(User $user): int => (int) $user->id);

            foreach ($selectedUserIds as $userId) {
                $user = $usersById->get($userId);

                if ($user !== null && $user->isProf()) {
                    $profIds[] = $userId;
                } else {
                    $nonProfIds[] = $userId;
                }
            }
        }

        // Toujours synchroniser les DEUX cibles (même avec une partition vide)
        // pour que le retrait/bascule de rôle retire bien du groupe d'origine.
        $this->syncAdGroupMembersByUserIds("Equipe_{$baseName}", $profIds);
        $this->syncAdGroupMembersByUserIds("Classe_{$baseName}", $nonProfIds);
    }

    /**
     * Empêche la création d'un groupe classe/équipe dont le nom NU porte déjà un
     * préfixe réservé géré par le serveur (`Classe_`/`Equipe_`/`PP_`). Sans ce
     * garde-fou, l'expansion (`Equipe_<name>`/`Classe_<name>`) viserait des CN
     * fantômes : les membres seraient écrits sur des groupes AD inexistants
     * (add LDAP fail-soft → échec SILENCIEUX) et le sélecteur SQL post-sync
     * lèverait « introuvable après synchronisation ». On bloque dès la saisie.
     *
     * Ne concerne QUE les types classe/équipe : les types à CN préfixé légitime
     * (`matiere_classe` → `Matiere_…`) ne passent jamais par cette expansion.
     */
    private function guardReservedPrefixOnCreate(string $rawName, string $type): void
    {
        $normalizedType = mb_strtolower(trim($type));

        if (!in_array($normalizedType, ['class', 'classe', 'equipe', 'équipe'], true)) {
            return;
        }

        foreach (['Classe_', 'Equipe_', 'PP_'] as $prefix) {
            if (str_starts_with($rawName, $prefix)) {
                throw new \InvalidArgumentException(
                    "Le nom « {$rawName} » ne peut pas commencer par le préfixe réservé « {$prefix} » pour un groupe de type classe/équipe."
                );
            }
        }
    }

    /**
     * Retire un éventuel préfixe de classe/équipe (`Classe_`, `Equipe_`, `PP_`)
     * pour retrouver la base nue. Idempotent sur un nom déjà nu.
     */
    private function stripClasseLikePrefix(string $name): string
    {
        foreach (['Classe_', 'Equipe_', 'PP_'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return substr($name, strlen($prefix));
            }
        }

        return $name;
    }

    /**
     * @param array<int,int> $selectedUserIds
     */
    private function syncAdGroupMembersByUserIds(string $groupName, array $selectedUserIds): void
    {
        $desiredDns = User::query()
            ->whereIn('id', $selectedUserIds)
            ->whereNotNull('dn')
            ->pluck('dn')
            ->map(static fn(mixed $dn): string => trim((string) $dn))
            ->filter(static fn(string $dn): bool => $dn !== '')
            ->unique()
            ->values();

        $members = $this->groupRepository->getGroupMembers($groupName);

        if (!$members instanceof Collection) {
            return;
        }

        $currentDns = $members
            ->map(static fn(array $member): string => trim((string) ($member['dn'] ?? '')))
            ->filter(static fn(string $dn): bool => $dn !== '')
            ->unique()
            ->values();

        foreach ($desiredDns->diff($currentDns) as $memberDn) {
            $this->groupRepository->addMember($groupName, (string) $memberDn);
        }

        $sqlKnownDns = User::query()
            ->whereNotNull('dn')
            ->pluck('dn')
            ->map(static fn(mixed $dn): string => trim((string) $dn))
            ->filter(static fn(string $dn): bool => $dn !== '')
            ->unique()
            ->values();

        foreach ($currentDns->intersect($sqlKnownDns)->diff($desiredDns) as $memberDn) {
            $this->groupRepository->removeMember($groupName, (string) $memberDn);
        }
    }

    private function mapTypeToLdap(string $type): string
    {
        return match (mb_strtolower(trim($type))) {
            'class', 'classe' => 'classe',
            'cours' => 'cours',
            'matiere', 'matière' => 'matiere',
            'projet' => 'projet',
            'equipe', 'équipe' => 'equipe',
            'matiere_classe', 'matiere-classe' => 'matiere',
            default => 'other_group',
        };
    }

    /**
     * 4.13 — Nom SQL attendu après `syncFromAd` pour le sélecteur post-sync.
     *
     * Pour les classes/équipes (foldées au nom nu depuis 4.13), c'est la base
     * nue (`Classe_3A`/`Equipe_3A`/`3A` → `3A`). Pour les autres types, c'est le
     * CN brut tel que stocké en SQL (`Cours_X`, `Matiere_X@Y`, …) — résolu via
     * {@see resolvePrimaryGroupName()} pour conserver le comportement existant.
     */
    private function resolveSqlLookupName(string $rawName, string $type): string
    {
        $normalizedType = mb_strtolower(trim($type));

        if (in_array($normalizedType, ['class', 'classe', 'equipe', 'équipe'], true)) {
            return $this->stripClasseLikePrefix($rawName);
        }

        return $this->resolvePrimaryGroupName($rawName, $type);
    }

    private function resolvePrimaryGroupName(string $rawName, string $type): string
    {
        $normalizedType = mb_strtolower(trim($type));

        return match ($normalizedType) {
            'class', 'classe', 'equipe' => "Classe_{$rawName}",
            'cours' => "Cours_{$rawName}",
            'projet' => "Projet_{$rawName}",
            'matiere', 'matière' => "Matiere_{$rawName}",
            'matiere_classe', 'matiere-classe' => str_starts_with($rawName, 'Matiere_') ? $rawName : "Matiere_{$rawName}",
            default => $rawName,
        };
    }

    /**
     * @return array{name:string, display_name:?string, type:string, ad_dn:?string}
     */
    private function validateData(array $data, ?UserGroup $existing = null): array
    {
        $name = trim((string) ($data['name'] ?? $existing?->name ?? ''));
        $displayNameRaw = trim((string) ($data['display_name'] ?? $existing?->display_name ?? ''));
        $type = trim((string) ($data['type'] ?? $existing?->type ?? 'custom'));

        if ($name === '') {
            throw new \InvalidArgumentException('Le nom technique du groupe est obligatoire.');
        }

        if (!preg_match('/^[a-zA-Z0-9._@-]+$/', $name)) {
            throw new \InvalidArgumentException('Le nom technique contient des caractères invalides.');
        }

        if ($type === '') {
            throw new \InvalidArgumentException('Le type du groupe est obligatoire.');
        }

        $displayName = $displayNameRaw !== '' ? $displayNameRaw : null;

        return [
            'name' => $name,
            'display_name' => $displayName,
            'type' => $type,
            'ad_dn' => $existing?->ad_dn,
        ];
    }

    private function detectTypeFromAdGroupName(string $groupName): string
    {
        if (str_starts_with($groupName, 'Matiere_') && str_contains($groupName, '@')) {
            return 'matiere_classe';
        }

        if (str_starts_with($groupName, 'Classe_')) {
            return 'classe';
        }

        if (str_starts_with($groupName, 'Equipe_') || str_starts_with($groupName, 'PP_')) {
            return 'equipe';
        }

        if (str_starts_with($groupName, 'Cours_')) {
            return 'cours';
        }

        if (str_starts_with($groupName, 'Projet_')) {
            return 'projet';
        }

        if (str_starts_with($groupName, 'Matiere_')) {
            return 'matiere';
        }

        if (MainGroups::isMainGroup($groupName)) {
            return 'role';
        }

        if (FunctionGroups::isFunctionGroup($groupName)) {
            return 'function';
        }

        return 'custom';
    }

    private function convertAdGuidToString(mixed $rawGuid): ?string
    {
        if (!is_string($rawGuid) || $rawGuid === '') {
            return null;
        }

        if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $rawGuid) === 1) {
            return strtolower($rawGuid);
        }

        $hex = bin2hex($rawGuid);
        if (strlen($hex) !== 32) {
            return null;
        }

        return strtolower(sprintf(
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
            substr($hex, 20, 12)
        ));
    }

    /**
     * Résout les membres directs (non récursif) d'une liste de groupes SQL
     * pour une réinitialisation bulk. Reproduit la sémantique legacy
     * `search_people_group($config, $groupsam, $recurse=false)` — si un groupe
     * contient un sous-groupe, les utilisateurs du sous-groupe ne sont PAS
     * ramenés (idempotence stricte vs `sambaedu/includes/ldap.inc.php:5872`).
     *
     * La source de vérité ici est l'AD (cf. mémoire projet
     * `feedback_gpo_real_ad_not_eloquent.md`). Pour chaque groupe on
     * interroge l'AD via {@see GroupRepository::getGroupMembers()} puis on
     * valide l'existence SQL (sync si besoin) — sans synchro on perdrait
     * les users AD jamais connectés via SER.
     *
     * @param array<int|string> $groupIds identifiants SQL des UserGroup
     * @return array{
     *     users: \Illuminate\Support\Collection<\App\Models\User>,
     *     login_to_source_group: array<string, array{id: int, name: string}>
     * }
     */
    public function getDirectMembersForBulkReset(array $groupIds): array
    {
        /** @var array<string, User> $byLogin */
        $byLogin = [];
        /** @var array<string, array{id:int, name:string}> $sourceGroup */
        $sourceGroup = [];

        $ids = collect($groupIds)
            ->map(fn(mixed $id): int => (int) $id)
            ->filter(fn(int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if (count($ids) === 0) {
            return [
                'users' => collect([]),
                'login_to_source_group' => [],
            ];
        }

        $groups = UserGroup::query()->whereIn('id', $ids)->get();

        foreach ($groups as $group) {
            try {
                $members = $this->groupRepository->getGroupMembers($group->name);
            } catch (\Throwable $e) {
                Log::warning('[UserGroupService] Lecture membres AD impossible pour bulk reset', [
                    'group' => $group->name,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (!$members instanceof Collection || $members->isEmpty()) {
                Log::info('[UserGroupService] Groupe sans membres directs', [
                    'group' => $group->name,
                ]);
                continue;
            }

            foreach ($members as $member) {
                // En AD, CN peut différer du sAMAccountName si le user a été renommé —
                // on préfère samaccountname (login AD canonique) avec fallback sur cn.
                $login = trim((string) ($member['samaccountname'] ?? $member['cn'] ?? ''));
                if ($login === '') {
                    Log::warning('[UserGroupService] Membre AD sans login résolvable', [
                        'group' => $group->name,
                        'member_keys' => array_keys((array) $member),
                    ]);
                    continue;
                }

                if (array_key_exists($login, $byLogin)) {
                    // Dédup : on conserve le premier groupe qui a ramené ce user
                    // (ordre d'itération sur $ids = ordre de la requête bulk).
                    continue;
                }

                $sqlUser = User::query()->where('login', $login)->first();

                if ($sqlUser === null) {
                    // L'user AD n'est pas encore dans la table SQL : on tente
                    // une synchro minimale. Si cela échoue on ignore ce user
                    // (il sera remonté en erreur par la phase de validation
                    // de UserService::bulkResetPasswords).
                    Log::info('[UserGroupService] User AD absent en SQL, skip sync inline', [
                        'login' => $login,
                        'group' => $group->name,
                    ]);
                    continue;
                }

                $byLogin[$login] = $sqlUser;
                $sourceGroup[$login] = [
                    'id' => (int) $group->id,
                    'name' => (string) ($group->display_name ?? $group->name),
                ];
            }
        }

        return [
            'users' => collect(array_values($byLogin)),
            'login_to_source_group' => $sourceGroup,
        ];
    }
}
