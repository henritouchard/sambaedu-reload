<?php

namespace App\Jobs;

use App\Models\AppProfile;
use App\Models\Application;
use App\Models\Shortcut;
use App\Models\WorkstationGroup;
use Illuminate\Support\Facades\Log;

/**
 * Job asynchrone pour calculer le snapshot et envoyer le résultat via callback.
 * Hérite de BaseControlHubJob pour la gestion automatique des statuts et callbacks.
 */
class SnapshotJob extends BaseControlHubJob
{
    public int $timeout = 60;

    protected function execute(): array
    {
        Log::info('SnapshotJob: Computing snapshot', [
            'task_id' => $this->task->id,
        ]);

        $shortcuts = Shortcut::whereNotNull('controlhub_id')
            ->select('controlhub_id', 'controlhub_version')
            ->get();

        $appProfiles = AppProfile::whereNotNull('controlhub_id')
            ->select('controlhub_id', 'controlhub_version')
            ->get();

        $workstationGroups = WorkstationGroup::whereNotNull('controlhub_id')
            ->select('controlhub_id', 'controlhub_version')
            ->get();

        $applications = Application::whereNotNull('controlhub_id')
            ->select('controlhub_id', 'controlhub_version')
            ->get();

        $shortcutsMap = $this->buildTimestampMap($shortcuts);
        $appProfilesMap = $this->buildTimestampMap($appProfiles);
        $workstationGroupsMap = $this->buildTimestampMap($workstationGroups);
        $applicationsMap = $this->buildTimestampMap($applications);

        $hashApplications = $this->computeTypeHash('applications', $applicationsMap);
        $hashAppProfiles = $this->computeTypeHash('app_profiles', $appProfilesMap);
        $hashShortcuts = $this->computeTypeHash('shortcuts', $shortcutsMap);
        $hashWorkstationGroups = $this->computeTypeHash('workstation_groups', $workstationGroupsMap);

        $snapshotHash = hash('sha256', $hashApplications . '|' . $hashAppProfiles . '|' . $hashShortcuts . '|' . $hashWorkstationGroups);

        return [
            'snapshot_at' => now()->toIso8601ZuluString(),
            'snapshot_hash' => $snapshotHash,
            'hashes' => [
                'applications' => $hashApplications,
                'app_profiles' => $hashAppProfiles,
                'shortcuts' => $hashShortcuts,
                'workstation_groups' => $hashWorkstationGroups,
            ],
            'applications' => $applicationsMap,
            'shortcuts' => $shortcutsMap,
            'app_profiles' => $appProfilesMap,
            'workstation_groups' => $workstationGroupsMap,
        ];
    }

    /**
     * @param \Illuminate\Support\Collection $entities
     * @return array<string, string|null>
     */
    private function buildTimestampMap($entities): array
    {
        $map = [];
        foreach ($entities as $entity) {
            $map[$entity->controlhub_id] = $this->formatTimestamp($entity->controlhub_version);
        }
        ksort($map);
        return $map;
    }

    private function computeTypeHash(string $type, array $map): string
    {
        if (empty($map)) {
            return hash('sha256', $type . ':empty');
        }

        $parts = [];
        foreach ($map as $controlhubId => $timestamp) {
            $parts[] = $type . ':' . $controlhubId . ':' . ($timestamp ?? 'null');
        }

        return hash('sha256', implode('|', $parts));
    }

    private function formatTimestamp(mixed $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        if ($timestamp instanceof \DateTimeInterface) {
            return $timestamp->format('Y-m-d\TH:i:s\Z');
        }

        try {
            return (new \DateTimeImmutable($timestamp))->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception) {
            return (string) $timestamp;
        }
    }
}
