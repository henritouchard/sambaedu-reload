<?php

namespace App\Services\ControlHub\Data;

/**
 * DTO pour le rapport de synchronisation d'un WorkstationGroup (physique ou logique).
 *
 * Retourné par WorkstationGroupSyncService et envoyé au ControlHub via callback.
 */
class WorkstationGroupSyncResult
{
    /** @var array{created: int, updated: int, unchanged: int} */
    public array $groupsStats = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

    /** @var array{created: int, updated: int, unchanged: int} */
    public array $shortcutsStats = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

    /** @var array{created: int, updated: int, unchanged: int} */
    public array $appProfilesStats = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

    /** @var array{created: int, updated: int, unchanged: int} */
    public array $applicationsStats = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

    /** @var array{attached: int, detached: int} */
    public array $shortcutsToGroups = ['attached' => 0, 'detached' => 0];

    /** @var array{attached: int, detached: int} */
    public array $groupsToAppProfiles = ['attached' => 0, 'detached' => 0];

    /** @var array{resolved: int, missing: int} */
    public array $appProfilesToApplications = ['resolved' => 0, 'missing' => 0];

    public int $groupsParentResolved = 0;

    /** @var string[] */
    public array $warnings = [];

    public function addWarning(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pass1_entities' => [
                'groups' => $this->groupsStats,
                'shortcuts' => $this->shortcutsStats,
                'app_profiles' => $this->appProfilesStats,
                'applications' => $this->applicationsStats,
            ],
            'pass2_relations' => [
                'shortcuts_to_groups' => $this->shortcutsToGroups,
                'groups_to_app_profiles' => $this->groupsToAppProfiles,
                'groups_parent_resolved' => $this->groupsParentResolved,
                'app_profiles_to_applications' => $this->appProfilesToApplications,
            ],
            'warnings' => $this->warnings,
        ];
    }
}
