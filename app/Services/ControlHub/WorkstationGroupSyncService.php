<?php

namespace App\Services\ControlHub;

use App\Enums\LockReason;
use App\Models\AppProfile;
use App\Models\Application;
use App\Models\Depot;
use App\Models\Shortcut;
use App\Models\WorkstationGroup;
use App\Services\ControlHub\Data\WorkstationGroupSyncResult;
use App\Services\Parc\WorkstationGroupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service de synchronisation des WorkstationGroups depuis le ControlHub.
 *
 * Deux modes :
 * - Groupe logique (flat) : un seul noeud avec shortcuts et app_profiles
 * - Groupe physique (tree) : arborescence parent → enfants récursive
 *
 * Logique d'upsert par controlhub_id + controlhub_version :
 * - Existe et à jour (même version) → rien
 * - Existe et pas à jour → mise à jour
 * - N'existe pas → création
 *
 * Parcours parent → enfants pour garantir que le parent_id est résolu.
 */
class WorkstationGroupSyncService
{
    /**
     * Synchronise un groupe logique (flat, sans arborescence).
     *
     * @param array<string, mixed> $payload Le payload du groupe logique
     * @return WorkstationGroupSyncResult
     */
    public function syncLogicalGroup(array $payload): WorkstationGroupSyncResult
    {
        $result = new WorkstationGroupSyncResult();

        Log::info('WorkstationGroupSyncService: Starting logical group sync', [
            'controlhub_id' => $payload['controlhub_id'] ?? null,
            'name' => $payload['name'] ?? null,
        ]);

        DB::transaction(function () use ($payload, $result) {
            // Pass 1 : Upsert entités
            $this->upsertGroupNode($payload, null, $result);
            $this->upsertShortcuts($payload['shortcuts'] ?? [], $result);
            $this->upsertAppProfiles($payload['app_profiles'] ?? [], $result);

            // Pass 2 : Relations
            $this->syncShortcutsToGroups($payload['shortcuts'] ?? [], $result);
            $this->syncGroupToAppProfiles($payload, $result);
            $this->syncAppProfilesToApplications($payload['app_profiles'] ?? [], $result);
        });

        Log::info('WorkstationGroupSyncService: Logical group sync completed', [
            'result' => $result->toArray(),
        ]);

        return $result;
    }

    /**
     * Synchronise une arborescence de groupes physiques (tree).
     *
     * @param array<string, mixed> $tree Le noeud racine de l'arborescence
     * @return WorkstationGroupSyncResult
     */
    public function syncPhysicalTree(array $tree): WorkstationGroupSyncResult
    {
        $result = new WorkstationGroupSyncResult();

        Log::info('WorkstationGroupSyncService: Starting physical tree sync', [
            'root_controlhub_id' => $tree['controlhub_id'] ?? null,
            'root_name' => $tree['name'] ?? null,
        ]);

        DB::transaction(function () use ($tree, $result) {
            // Collecter tous les noeuds, shortcuts et app_profiles de l'arbre
            $allShortcuts = [];
            $allAppProfiles = [];
            $this->collectEntitiesFromTree($tree, $allShortcuts, $allAppProfiles);

            // Pass 1 : Upsert toutes les entités (shortcuts, app_profiles, applications)
            $this->upsertShortcuts($allShortcuts, $result);
            $this->upsertAppProfiles($allAppProfiles, $result);

            // Pass 1b : Upsert les groupes récursivement (parent → enfants)
            $this->upsertTreeRecursive($tree, null, $result);

            // Pass 2 : Relations pour chaque noeud
            $this->syncTreeRelationsRecursive($tree, $result);
        });

        Log::info('WorkstationGroupSyncService: Physical tree sync completed', [
            'result' => $result->toArray(),
        ]);

        return $result;
    }

    // ═══════════════════════════════════════════════════════════════
    // Upsert des entités
    // ═══════════════════════════════════════════════════════════════

    /**
     * Upsert un noeud de groupe (sans ses enfants).
     *
     * @param array<string, mixed> $nodeData
     * @param int|null $parentId ID local du parent (null pour racine)
     */
    private function upsertGroupNode(array $nodeData, ?int $parentId, WorkstationGroupSyncResult $result): WorkstationGroup
    {
        $controlhubId = $nodeData['controlhub_id'];
        $controlhubVersion = $nodeData['controlhub_version'] ?? null;

        $existing = WorkstationGroup::where('controlhub_id', $controlhubId)->first();

        if ($existing) {
            // Vérifier si à jour
            if ($this->isUpToDate($existing->controlhub_version, $controlhubVersion)) {
                // Même version → on met juste à jour le parent_id si nécessaire
                if ($existing->parent_id !== $parentId) {
                    $existing->update(['parent_id' => $parentId]);
                    $result->groupsParentResolved++;
                }
                $result->groupsStats['unchanged']++;

                return $existing;
            }

            // Mise à jour
            $existing->update([
                'name' => $nodeData['name'],
                'display_name' => $nodeData['display_name'] ?? $existing->display_name,
                'description' => $nodeData['description'] ?? $existing->description,
                'is_physical' => $nodeData['is_physical'] ?? $existing->is_physical,
                'controlhub_version' => $controlhubVersion,
                'parent_id' => $parentId,
                'locked' => LockReason::CONTROL_HUB->value,
                'managed_by_control_hub' => true,
            ]);
            $result->groupsStats['updated']++;

            Log::debug('WorkstationGroupSyncService: Group updated', [
                'controlhub_id' => $controlhubId,
                'name' => $nodeData['name'],
            ]);

            return $existing->fresh();
        }

        // Création
        $service = app(WorkstationGroupService::class);
        $group = $service->createGroup([
            'name' => $nodeData['name'],
            'display_name' => $nodeData['display_name'] ?? null,
            'description' => $nodeData['description'] ?? null,
            'is_physical' => $nodeData['is_physical'] ?? true,
            'is_active' => true,
            'parent_id' => $parentId,
            'controlhub_id' => $controlhubId,
            'controlhub_version' => $controlhubVersion,
            'locked' => LockReason::CONTROL_HUB->value,
            'managed_by_control_hub' => true,
        ]);
        $result->groupsStats['created']++;

        Log::debug('WorkstationGroupSyncService: Group created', [
            'controlhub_id' => $controlhubId,
            'name' => $nodeData['name'],
            'local_id' => $group->id,
        ]);

        return $group;
    }

    /**
     * Upsert une liste de shortcuts.
     *
     * @param array<int, array<string, mixed>> $shortcutsData
     */
    private function upsertShortcuts(array $shortcutsData, WorkstationGroupSyncResult $result): void
    {
        foreach ($shortcutsData as $data) {
            $controlhubId = $data['controlhub_id'] ?? null;
            if (! $controlhubId) {
                $result->addWarning('Shortcut sans controlhub_id ignoré');

                continue;
            }

            $controlhubVersion = $data['controlhub_version'] ?? null;
            $existing = Shortcut::where('controlhub_id', $controlhubId)->first();

            if ($existing) {
                if ($this->isUpToDate($existing->controlhub_version, $controlhubVersion)) {
                    $result->shortcutsStats['unchanged']++;

                    continue;
                }

                $existing->update($this->buildShortcutAttributes($data, $existing));
                $result->shortcutsStats['updated']++;

                continue;
            }

            // Création
            $attributes = $this->buildShortcutAttributes($data, null);
            $attributes['key'] = $data['name'] ?? uniqid();
            Shortcut::create($attributes);
            $result->shortcutsStats['created']++;
        }
    }

    /**
     * Upsert une liste d'app_profiles (et leurs applications imbriquées).
     *
     * @param array<int, array<string, mixed>> $appProfilesData
     */
    private function upsertAppProfiles(array $appProfilesData, WorkstationGroupSyncResult $result): void
    {
        foreach ($appProfilesData as $data) {
            $controlhubId = $data['controlhub_id'] ?? null;
            if (! $controlhubId) {
                $result->addWarning('AppProfile sans controlhub_id ignoré');

                continue;
            }

            $controlhubVersion = $data['controlhub_version'] ?? null;
            $existing = AppProfile::where('controlhub_id', $controlhubId)->first();

            if ($existing) {
                if ($this->isUpToDate($existing->controlhub_version, $controlhubVersion)) {
                    $result->appProfilesStats['unchanged']++;
                } else {
                    $existing->update([
                        'controlhub_version' => $controlhubVersion,
                        'name' => $data['name'] ?? $existing->name,
                        'display_name' => $data['display_name'] ?? $existing->display_name,
                        'description' => $data['description'] ?? $existing->description,
                        'is_active' => $data['is_active'] ?? $existing->is_active,
                    ]);
                    $result->appProfilesStats['updated']++;
                }
            } else {
                AppProfile::create([
                    'controlhub_id' => $controlhubId,
                    'controlhub_version' => $controlhubVersion,
                    'name' => $data['name'] ?? 'unnamed',
                    'display_name' => $data['display_name'] ?? null,
                    'description' => $data['description'] ?? null,
                    'is_active' => $data['is_active'] ?? true,
                ]);
                $result->appProfilesStats['created']++;
            }

            // Upsert les applications imbriquées
            $this->upsertApplications($data['applications'] ?? [], $result);
        }
    }

    /**
     * Upsert une liste d'applications.
     *
     * La table applications a une contrainte unique sur (depot_id, app_id).
     * Les applications venant du ControlHub sont rattachées à un dépôt "ControlHub" dédié.
     *
     * @param array<int, array<string, mixed>> $applicationsData
     */
    private function upsertApplications(array $applicationsData, WorkstationGroupSyncResult $result): void
    {
        if (empty($applicationsData)) {
            return;
        }

        $depot = $this->getOrCreateControlHubDepot();

        foreach ($applicationsData as $data) {
            $appId = $data['app_id'] ?? null;
            if (! $appId) {
                $result->addWarning('Application sans app_id ignorée');

                continue;
            }

            // Chercher d'abord dans le dépôt ControlHub, sinon dans tous les dépôts
            $existing = Application::where('depot_id', $depot->id)
                ->where('app_id', $appId)
                ->first();

            if (! $existing) {
                $existing = Application::where('app_id', $appId)->first();
            }

            $attributes = [
                'app_id' => $appId,
                'name' => $data['name'] ?? $existing?->name ?? $appId,
                'version' => $data['version'] ?? $existing?->version,
                'category' => $data['category'] ?? $existing?->category,
                'compatibility' => $data['compatibility'] ?? $existing?->compatibility,
                'branch' => $data['branch'] ?? $existing?->branch,
                'xml' => $data['xml'] ?? $existing?->xml,
                'xml_url' => $data['xml_url'] ?? $existing?->xml_url,
                'xml_sha' => $data['xml_sha'] ?? $existing?->xml_sha,
                'log_url' => $data['log_url'] ?? $existing?->log_url,
            ];

            if ($existing) {
                $changed = false;
                foreach ($attributes as $key => $value) {
                    if ($existing->{$key} != $value) {
                        $changed = true;
                        break;
                    }
                }

                if ($changed) {
                    $existing->update($attributes);
                    $result->applicationsStats['updated']++;
                } else {
                    $result->applicationsStats['unchanged']++;
                }
            } else {
                $attributes['depot_id'] = $depot->id;
                Application::create($attributes);
                $result->applicationsStats['created']++;
            }
        }
    }

    /**
     * Récupère ou crée le dépôt dédié aux applications ControlHub.
     *
     * Story 51.1 — DÉLÈGUE au point d'entrée UNIQUE
     * {@see ImposedDepotReconciler::getOrCreateImposedDepot()} : plus de définition
     * divergente du dépôt imposé (l'ancien stub posait `is_primary => false` ; la
     * définition canonique le promeut `is_imposed => true, is_primary => true`).
     */
    private function getOrCreateControlHubDepot(): Depot
    {
        return ImposedDepotReconciler::getOrCreateImposedDepot();
    }

    // ═══════════════════════════════════════════════════════════════
    // Résolution des relations
    // ═══════════════════════════════════════════════════════════════

    /**
     * Sync shortcuts ↔ workstation_groups via les workstation_groups déclarés dans chaque shortcut.
     *
     * @param array<int, array<string, mixed>> $shortcutsData
     */
    private function syncShortcutsToGroups(array $shortcutsData, WorkstationGroupSyncResult $result): void
    {
        foreach ($shortcutsData as $data) {
            $controlhubId = $data['controlhub_id'] ?? null;
            if (! $controlhubId || ! array_key_exists('workstation_groups', $data)) {
                continue;
            }

            $shortcut = Shortcut::where('controlhub_id', $controlhubId)->first();
            if (! $shortcut) {
                continue;
            }

            $groupIds = [];
            foreach ($data['workstation_groups'] as $groupRef) {
                $groupChId = $groupRef['controlhub_id'] ?? $groupRef ?? null;
                if (! $groupChId) {
                    continue;
                }
                $group = WorkstationGroup::where('controlhub_id', $groupChId)->first();
                if ($group) {
                    $groupIds[] = $group->id;
                } else {
                    $result->addWarning("Shortcut '{$data['name']}': groupe controlhub_id '{$groupChId}' non trouvé localement");
                }
            }

            $changes = $shortcut->workstationGroups()->sync($groupIds);
            $result->shortcutsToGroups['attached'] += count($changes['attached'] ?? []);
            $result->shortcutsToGroups['detached'] += count($changes['detached'] ?? []);
        }
    }

    /**
     * Sync un groupe ↔ ses app_profiles.
     *
     * @param array<string, mixed> $nodeData Données d'un noeud de groupe
     */
    private function syncGroupToAppProfiles(array $nodeData, WorkstationGroupSyncResult $result): void
    {
        $controlhubId = $nodeData['controlhub_id'] ?? null;
        if (! $controlhubId || ! array_key_exists('app_profiles', $nodeData)) {
            return;
        }

        $group = WorkstationGroup::where('controlhub_id', $controlhubId)->first();
        if (! $group) {
            return;
        }

        $profileIds = [];
        foreach ($nodeData['app_profiles'] as $profileData) {
            $profileChId = $profileData['controlhub_id'] ?? null;
            if (! $profileChId) {
                continue;
            }
            $profile = AppProfile::where('controlhub_id', $profileChId)->first();
            if ($profile) {
                $profileIds[] = $profile->id;
            } else {
                $result->addWarning("Groupe '{$nodeData['name']}': app_profile controlhub_id '{$profileChId}' non trouvé localement");
            }
        }

        $changes = $group->appProfiles()->sync($profileIds);
        $result->groupsToAppProfiles['attached'] += count($changes['attached'] ?? []);
        $result->groupsToAppProfiles['detached'] += count($changes['detached'] ?? []);
    }

    /**
     * Sync app_profiles ↔ applications.
     *
     * @param array<int, array<string, mixed>> $appProfilesData
     */
    private function syncAppProfilesToApplications(array $appProfilesData, WorkstationGroupSyncResult $result): void
    {
        foreach ($appProfilesData as $data) {
            $controlhubId = $data['controlhub_id'] ?? null;
            if (! $controlhubId || ! array_key_exists('applications', $data)) {
                continue;
            }

            $profile = AppProfile::where('controlhub_id', $controlhubId)->first();
            if (! $profile) {
                continue;
            }

            $applicationIds = [];
            foreach ($data['applications'] as $appRef) {
                $appId = $appRef['app_id'] ?? null;
                if (! $appId) {
                    continue;
                }
                $application = Application::where('app_id', $appId)->first();
                if ($application) {
                    $applicationIds[] = $application->id;
                    $result->appProfilesToApplications['resolved']++;
                } else {
                    $result->appProfilesToApplications['missing']++;
                    $result->addWarning("AppProfile '{$data['name']}': application '{$appId}' non trouvée localement");
                }
            }

            $profile->applications()->sync($applicationIds);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers pour le mode tree (physique)
    // ═══════════════════════════════════════════════════════════════

    /**
     * Collecte tous les shortcuts et app_profiles uniques de l'arbre entier.
     * Déduplique par controlhub_id.
     *
     * @param array<string, mixed> $node
     * @param array<int, array<string, mixed>> &$allShortcuts
     * @param array<int, array<string, mixed>> &$allAppProfiles
     */
    private function collectEntitiesFromTree(array $node, array &$allShortcuts, array &$allAppProfiles): void
    {
        foreach ($node['shortcuts'] ?? [] as $shortcut) {
            $chId = $shortcut['controlhub_id'] ?? null;
            if ($chId) {
                $allShortcuts[$chId] = $shortcut;
            }
        }

        foreach ($node['app_profiles'] ?? [] as $profile) {
            $chId = $profile['controlhub_id'] ?? null;
            if ($chId) {
                $allAppProfiles[$chId] = $profile;
            }
        }

        foreach ($node['children'] ?? [] as $child) {
            $this->collectEntitiesFromTree($child, $allShortcuts, $allAppProfiles);
        }
    }

    /**
     * Upsert récursif des noeuds de l'arbre (parent → enfants).
     *
     * @param array<string, mixed> $node
     * @param int|null $parentId ID local du parent
     */
    private function upsertTreeRecursive(array $node, ?int $parentId, WorkstationGroupSyncResult $result): void
    {
        $group = $this->upsertGroupNode($node, $parentId, $result);

        foreach ($node['children'] ?? [] as $child) {
            $this->upsertTreeRecursive($child, $group->id, $result);
        }
    }

    /**
     * Sync récursif des relations pour chaque noeud de l'arbre.
     *
     * @param array<string, mixed> $node
     */
    private function syncTreeRelationsRecursive(array $node, WorkstationGroupSyncResult $result): void
    {
        // Shortcuts de ce noeud
        $this->syncShortcutsToGroups($node['shortcuts'] ?? [], $result);

        // AppProfiles de ce noeud
        $this->syncGroupToAppProfiles($node, $result);

        // AppProfiles → Applications de ce noeud
        $this->syncAppProfilesToApplications($node['app_profiles'] ?? [], $result);

        // Récurse sur les enfants
        foreach ($node['children'] ?? [] as $child) {
            $this->syncTreeRelationsRecursive($child, $result);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Utilitaires
    // ═══════════════════════════════════════════════════════════════

    /**
     * Vérifie si l'entité locale est à jour par rapport à la version ControlHub.
     *
     * @param \DateTimeInterface|string|null $localVersion
     * @param string|null $remoteVersion
     */
    private function isUpToDate(\DateTimeInterface|string|null $localVersion, ?string $remoteVersion): bool
    {
        if ($remoteVersion === null || $localVersion === null) {
            return false;
        }

        $local = $localVersion instanceof \DateTimeInterface
            ? $localVersion->format('Y-m-d\TH:i:s\Z')
            : $localVersion;

        return $local === $remoteVersion;
    }

    /**
     * Construit les attributs d'un shortcut depuis les données du payload.
     *
     * @param array<string, mixed> $data
     */
    private function buildShortcutAttributes(array $data, ?Shortcut $existing): array
    {
        return [
            'controlhub_id' => $data['controlhub_id'],
            'controlhub_version' => $data['controlhub_version'] ?? null,
            'name' => $data['name'] ?? $existing?->name ?? 'unnamed',
            'owner' => $data['owner'] ?? $existing?->owner ?? '',
            'place' => $data['place'] ?? $existing?->place ?? 'desktop',
            'is_global' => true,
            'windows_link' => $data['windows']['link'] ?? $existing?->windows_link ?? '',
            'windows_args' => $data['windows']['args'] ?? $existing?->windows_args ?? '',
            'windows_path' => $data['windows']['path'] ?? $existing?->windows_path ?? '',
            'windows_icon' => $data['windows']['icon'] ?? $existing?->windows_icon ?? '',
            'linux_link' => $data['linux']['link'] ?? $existing?->linux_link ?? '',
            'linux_args' => $data['linux']['args'] ?? $existing?->linux_args ?? '',
            'linux_path' => $data['linux']['path'] ?? $existing?->linux_path ?? '',
            'linux_icon' => $data['linux']['icon'] ?? $existing?->linux_icon ?? '',
            'linux_startupwmclass' => $data['linux']['startupwmclass'] ?? $existing?->linux_startupwmclass ?? '',
        ];
    }
}
