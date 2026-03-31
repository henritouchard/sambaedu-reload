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

        $primaryGroupName = $this->resolvePrimaryGroupName($payload['name'], $payload['type']);

        if (count($selectedUserIds) > 0) {
            $this->syncAdGroupMembersByUserIds($primaryGroupName, $selectedUserIds);
        }

        $this->syncFromAd();

        $primaryGroup = UserGroup::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($primaryGroupName)])
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
            $this->syncAdGroupMembersByUserIds($newName, $selectedUserIds);
        }

        $this->syncFromAd();

        $updatedGroup = UserGroup::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($newName)])
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
     *   total_groups_detected:int
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
     *   total_groups_detected:int
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
            'total_groups_detected' => 0,
        ];

        $log = $logger ?? fn(string $level, string $message) => Log::log($level, "[UserGroupService] {$message}");

        $eligibleGroups = $this->fetchEligibleAdGroups();

        if (count($onlyGroupNames) > 0) {
            $allowed = array_flip(array_map(
                static fn(string $name): string => mb_strtolower(trim($name)),
                $onlyGroupNames
            ));

            $eligibleGroups = array_values(array_filter(
                $eligibleGroups,
                static fn(array $group): bool => isset($allowed[mb_strtolower(trim((string) ($group['cn'] ?? '')))])
            ));
        }

        $stats['total_groups_detected'] = count($eligibleGroups);
        $log('info', $stats['total_groups_detected'] . ' groupe(s) utilisateur détecté(s) depuis AD');

        UserGroupObserver::disableSync();

        try {
            DB::transaction(function () use (&$stats, $eligibleGroups, $onlyGroupNames): void {
                $detectedNames = [];

                foreach ($eligibleGroups as $groupData) {
                    try {
                        $groupName = trim((string) ($groupData['cn'] ?? ''));
                        if ($groupName === '') {
                            continue;
                        }

                        $detectedNames[] = mb_strtolower($groupName);
                        $adGuid = $this->convertAdGuidToString($groupData['objectguid'] ?? null);
                        $adDn = trim((string) ($groupData['dn'] ?? ''));

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

                        $detectedType = $this->detectTypeFromAdGroupName($groupName);
                        $adDescription = trim((string) ($groupData['description'] ?? ''));
                        $displayName = $adDescription !== '' ? $adDescription : $groupName;

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

                        $memberIds = $this->resolveMemberUserIdsFromAdGroup($groupName);

                        $syncChanges = $group->users()->sync(array_values(array_unique(array_map('intval', $memberIds))));
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
}
