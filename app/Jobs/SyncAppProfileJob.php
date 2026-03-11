<?php

namespace App\Jobs;

use App\Models\AppProfile;
use App\Models\Application;
use App\Models\WorkstationGroup;
use Illuminate\Support\Facades\Log;

/**
 * Job pour synchroniser (upsert) un profil applicatif depuis le ControlHub.
 */
class SyncAppProfileJob extends BaseControlHubJob
{
    /**
     * @return array<string, mixed>
     */
    protected function execute(): array
    {
        $payload = $this->task->payload ?? [];

        Log::info('SyncAppProfileJob: Processing app profile sync', [
            'task_id' => $this->task->id,
            'controlhub_id' => $payload['controlhub_id'] ?? null,
        ]);

        $controlhubId = $payload['controlhub_id'] ?? null;
        if (!$controlhubId) {
            throw new \InvalidArgumentException('Le controlhub_id est requis');
        }

        if (empty($payload['name'])) {
            throw new \InvalidArgumentException('Le nom du profil applicatif est requis');
        }

        $controlhubVersion = $payload['controlhub_version'] ?? null;
        $existing = AppProfile::where('controlhub_id', (string) $controlhubId)->first();

        if ($existing && $this->isUpToDate($existing->controlhub_version, $controlhubVersion)) {
            $relationStats = $this->syncRelations($existing, $payload);

            return [
                'action' => 'unchanged',
                'controlhub_id' => $existing->controlhub_id,
                'profile_name' => $existing->name,
                'message' => 'Profil applicatif déjà à jour',
                'relations' => $relationStats,
            ];
        }

        if ($existing) {
            return $this->updateExisting($existing, $payload);
        }

        return $this->createNew($payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function updateExisting(AppProfile $profile, array $payload): array
    {
        $this->applyPayloadToProfile($profile, $payload);
        $profile->save();

        $relationStats = $this->syncRelations($profile, $payload);

        return [
            'action' => 'updated',
            'controlhub_id' => $profile->controlhub_id,
            'profile_name' => $profile->name,
            'message' => 'Profil applicatif mis à jour avec succès',
            'relations' => $relationStats,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function createNew(array $payload): array
    {
        $profile = new AppProfile();
        $profile->controlhub_id = (string) $payload['controlhub_id'];

        $this->applyPayloadToProfile($profile, $payload);
        $profile->save();

        $relationStats = $this->syncRelations($profile, $payload);

        return [
            'action' => 'created',
            'controlhub_id' => $profile->controlhub_id,
            'profile_name' => $profile->name,
            'message' => 'Profil applicatif créé avec succès',
            'relations' => $relationStats,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyPayloadToProfile(AppProfile $profile, array $payload): void
    {
        $profile->name = (string) $payload['name'];
        $profile->display_name = $payload['display_name'] ?? $profile->display_name;
        $profile->description = $payload['description'] ?? $profile->description;
        $profile->is_active = $payload['is_active'] ?? $profile->is_active ?? true;

        if (array_key_exists('controlhub_version', $payload)) {
            $profile->controlhub_version = $payload['controlhub_version'];
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function syncRelations(AppProfile $profile, array $payload): array
    {
        $applicationStats = $this->syncApplications($profile, $payload['applications'] ?? []);
        $groupStats = $this->syncWorkstationGroups($profile, $payload['workstation_groups'] ?? []);

        return [
            'applications' => $applicationStats,
            'workstation_groups' => $groupStats,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $applicationsPayload
     * @return array<string, mixed>
     */
    private function syncApplications(AppProfile $profile, array $applicationsPayload): array
    {
        $applicationIds = [];
        $created = 0;
        $updated = 0;
        $linked = 0;
        $missing = 0;

        foreach ($applicationsPayload as $applicationData) {
            $appId = $applicationData['app_id'] ?? null;
            if (!$appId) {
                continue;
            }

            $application = null;
            $applicationControlhubId = $applicationData['controlhub_id'] ?? null;

            if ($applicationControlhubId) {
                $application = Application::where('controlhub_id', (string) $applicationControlhubId)->first();
            }

            if (!$application) {
                $application = Application::where('app_id', (string) $appId)->first();
            }

            $attributes = [
                'app_id' => (string) $appId,
                'name' => $applicationData['name'] ?? $application?->name ?? (string) $appId,
                'version' => $applicationData['version'] ?? $application?->version,
                'category' => $applicationData['category'] ?? $application?->category,
                'compatibility' => $applicationData['compatibility'] ?? $application?->compatibility,
                'branch' => $applicationData['branch'] ?? $application?->branch,
                'xml' => $applicationData['xml'] ?? $application?->xml,
                'xml_url' => $applicationData['xml_url'] ?? $application?->xml_url,
                'xml_sha' => $applicationData['xml_sha'] ?? $application?->xml_sha,
                'log_url' => $applicationData['log_url'] ?? $application?->log_url,
            ];

            if ($applicationControlhubId) {
                $attributes['controlhub_id'] = (string) $applicationControlhubId;
                $attributes['controlhub_version'] = $applicationData['controlhub_version'] ?? null;
                $attributes['managed_by_control_hub'] = true;
            }

            if ($application) {
                $changed = false;
                foreach ($attributes as $key => $value) {
                    if ($application->{$key} != $value) {
                        $changed = true;
                        break;
                    }
                }

                if ($changed) {
                    $application->update($attributes);
                    $updated++;
                } else {
                    $linked++;
                }
            } else {
                $application = Application::create($attributes);
                $created++;
            }

            $applicationIds[] = $application->id;
        }

        if (!empty($applicationsPayload)) {
            $profile->applications()->sync($applicationIds);
            $missing = max(count($applicationsPayload) - count($applicationIds), 0);
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'linked' => $linked,
            'missing' => $missing,
            'synced' => count($applicationIds),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $groupsPayload
     * @return array<string, mixed>
     */
    private function syncWorkstationGroups(AppProfile $profile, array $groupsPayload): array
    {
        if (empty($groupsPayload)) {
            return [
                'attached' => 0,
                'detached' => 0,
                'resolved' => 0,
                'missing' => 0,
            ];
        }

        $groupIds = [];
        $missing = 0;

        foreach ($groupsPayload as $groupData) {
            $groupControlhubId = $groupData['controlhub_id'] ?? null;
            if (!$groupControlhubId) {
                continue;
            }

            $group = WorkstationGroup::where('controlhub_id', (string) $groupControlhubId)->first();
            if ($group) {
                $groupIds[] = $group->id;
            } else {
                $missing++;
            }
        }

        $changes = $profile->workstationGroups()->sync($groupIds);

        return [
            'attached' => count($changes['attached'] ?? []),
            'detached' => count($changes['detached'] ?? []),
            'resolved' => count($groupIds),
            'missing' => $missing,
        ];
    }

    /**
     * Vérifie si l'entité locale est à jour par rapport à la version ControlHub.
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
}
