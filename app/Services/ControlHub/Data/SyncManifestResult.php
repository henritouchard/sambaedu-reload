<?php

namespace App\Services\ControlHub\Data;

/**
 * DTO pour le rapport de convergence du Sync Manifest.
 * Retourné par SyncManifestService::apply() et envoyé au ControlHub via callback.
 */
class SyncManifestResult
{
    public string $manifestVersion;

    /** @var array{created: int, updated: int, unchanged: int} */
    public array $shortcutsStats = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

    /** @var array{created: int, updated: int, unchanged: int} */
    public array $appProfilesStats = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

    /** @var array{created: int, updated: int, unchanged: int} */
    public array $applicationsStats = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

    /** @var array{created: int, updated: int, unchanged: int} */
    public array $workstationGroupsStats = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

    /** @var array{attached: int, detached: int} */
    public array $shortcutsToGroups = ['attached' => 0, 'detached' => 0];

    /** @var array{attached: int, detached: int} */
    public array $groupsToAppProfiles = ['attached' => 0, 'detached' => 0];

    public int $groupsParentResolved = 0;

    /** @var array{resolved: int, missing: int} */
    public array $appProfilesToApplications = ['resolved' => 0, 'missing' => 0];

    /** @var array{shortcuts_deleted: int, app_profiles_deleted: int, workstation_groups_deleted: int} */
    public array $cleanup = [
        'shortcuts_deleted' => 0,
        'app_profiles_deleted' => 0,
        'workstation_groups_deleted' => 0,
    ];

    /** @var string[] */
    public array $warnings = [];

    public function __construct(string $manifestVersion)
    {
        $this->manifestVersion = $manifestVersion;
    }

    public function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    /**
     * Convertit en tableau pour le callback ControlHub.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'manifest_version' => $this->manifestVersion,
            'pass1_entities' => [
                'applications' => $this->applicationsStats,
                'shortcuts' => $this->shortcutsStats,
                'app_profiles' => $this->appProfilesStats,
                'workstation_groups' => $this->workstationGroupsStats,
            ],
            'pass2_relations' => [
                'shortcuts_to_groups' => $this->shortcutsToGroups,
                'groups_to_app_profiles' => $this->groupsToAppProfiles,
                'groups_parent_resolved' => $this->groupsParentResolved,
                'app_profiles_to_applications' => $this->appProfilesToApplications,
            ],
            'pass3_cleanup' => $this->cleanup,
            'warnings' => $this->warnings,
        ];
    }
}
