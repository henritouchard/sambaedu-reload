<?php

namespace App\Http\Controllers\Api\v1\ControlHub;

use App\Http\Controllers\Controller;
use App\Jobs\SnapshotJob;
use App\Models\AppProfile;
use App\Models\Application;
use App\Models\ControlHubTask;
use App\Models\Shortcut;
use App\Models\WorkstationGroup;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SnapshotController extends Controller
{
    /**
     * POST /api/v1/snapshot
     *
     * Mode asynchrone : dispatch un job qui calcule le snapshot
     * et envoie le résultat via callback au ControlHub.
     */
    public function snapshotAsync(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'task_id' => 'required|uuid',
                'task_name' => 'required|string|max:255',
                'task_type' => 'required|string|in:snapshot',
            ]);

            // Idempotence
            $existingTask = ControlHubTask::where('controlhub_task_id', $validated['task_id'])->first();
            if ($existingTask) {
                return response()->json([
                    'success' => true,
                    'message' => 'Task already received',
                    'task_id' => $existingTask->id,
                    'status' => $existingTask->status,
                ]);
            }

            $task = ControlHubTask::create([
                'controlhub_task_id' => $validated['task_id'],
                'name' => $validated['task_name'],
                'type' => $validated['task_type'],
                'payload' => [],
                'status' => ControlHubTask::STATUS_RECEIVED,
            ]);

            $task->markAsQueued();
            SnapshotJob::dispatch($task);

            Log::info('Snapshot task queued', [
                'controlhub_task_id' => $validated['task_id'],
                'local_id' => $task->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Snapshot task received and queued',
                'task_id' => $task->id,
                'status' => $task->status,
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (Exception $e) {
            Log::error('Failed to process snapshot task', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process task',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/v1/snapshot
     *
     * Mode synchrone : retourne l'inventaire léger immédiatement.
     * - snapshot_hash : hash global SHA-256 pour short-circuit rapide
     * - hashes : hash par type d'entité
     * - maps controlhub_id -> controlhub_version par type
     */
    public function snapshot(): JsonResponse
    {
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

        // Calcul des hashes déterministes (section 9 du doc)
        $hashApplications = $this->computeTypeHash('applications', $applicationsMap);
        $hashAppProfiles = $this->computeTypeHash('app_profiles', $appProfilesMap);
        $hashShortcuts = $this->computeTypeHash('shortcuts', $shortcutsMap);
        $hashWorkstationGroups = $this->computeTypeHash('workstation_groups', $workstationGroupsMap);

        // Hash global = sha256(hash_applications | hash_app_profiles | hash_shortcuts | hash_workstation_groups)
        // Ordre alphabétique des types
        $snapshotHash = hash('sha256', $hashApplications . '|' . $hashAppProfiles . '|' . $hashShortcuts . '|' . $hashWorkstationGroups);

        return response()->json([
            'instance_id' => config('controlHub.se4fs.instance_id'),
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
        ]);
    }

    /**
     * GET /api/v1/shortcuts/{controlhubId}
     */
    public function showShortcut(string $controlhubId): JsonResponse
    {
        $shortcut = Shortcut::where('controlhub_id', $controlhubId)->first();

        if (!$shortcut) {
            return response()->json([
                'success' => false,
                'message' => 'Shortcut non trouvé',
                'controlhub_id' => $controlhubId,
            ], 404);
        }

        // Relations : workstation_groups ayant un controlhub_id
        $groups = $shortcut->workstationGroups()
            ->whereNotNull('controlhub_id')
            ->select('workstation_groups.controlhub_id', 'workstation_groups.name')
            ->get()
            ->map(fn (WorkstationGroup $g) => [
                'controlhub_id' => $g->controlhub_id,
                'name' => $g->name,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'controlhub_id' => $shortcut->controlhub_id,
                'controlhub_version' => $this->formatTimestamp($shortcut->controlhub_version),
                'name' => $shortcut->name,
                'owner' => $shortcut->owner,
                'place' => $shortcut->place,
                'windows_link' => $shortcut->windows_link,
                'windows_args' => $shortcut->windows_args,
                'windows_path' => $shortcut->windows_path,
                'windows_icon' => $shortcut->windows_icon,
                'linux_link' => $shortcut->linux_link,
                'linux_args' => $shortcut->linux_args,
                'linux_path' => $shortcut->linux_path,
                'linux_icon' => $shortcut->linux_icon,
                'linux_startupwmclass' => $shortcut->linux_startupwmclass,
                'workstation_groups' => $groups,
            ],
        ]);
    }

    /**
     * GET /api/v1/workstation-groups/{controlhubId}
     */
    public function showWorkstationGroup(string $controlhubId): JsonResponse
    {
        $group = WorkstationGroup::where('controlhub_id', $controlhubId)->first();

        if (!$group) {
            return response()->json([
                'success' => false,
                'message' => 'Workstation group non trouvé',
                'controlhub_id' => $controlhubId,
            ], 404);
        }

        // Relations shortcuts ayant un controlhub_id
        $shortcuts = $group->shortcuts()
            ->whereNotNull('shortcuts.controlhub_id')
            ->select('shortcuts.controlhub_id', 'shortcuts.name')
            ->get()
            ->map(fn (Shortcut $s) => [
                'controlhub_id' => $s->controlhub_id,
                'name' => $s->name,
            ])
            ->values();

        // Relations app_profiles ayant un controlhub_id
        $appProfiles = $group->appProfiles()
            ->whereNotNull('app_profiles.controlhub_id')
            ->select('app_profiles.controlhub_id', 'app_profiles.name')
            ->get()
            ->map(fn (AppProfile $p) => [
                'controlhub_id' => $p->controlhub_id,
                'name' => $p->name,
            ])
            ->values();

        // Parent controlhub_id
        $parentControlhubId = null;
        if ($group->parent_id) {
            $parent = WorkstationGroup::find($group->parent_id);
            $parentControlhubId = $parent?->controlhub_id;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'controlhub_id' => $group->controlhub_id,
                'controlhub_version' => $this->formatTimestamp($group->controlhub_version),
                'name' => $group->name,
                'display_name' => $group->display_name,
                'description' => $group->description,
                'is_physical' => $group->is_physical,
                'is_active' => $group->is_active,
                'parent_controlhub_id' => $parentControlhubId,
                'app_profile_name' => $group->app_profile_name,
                'shortcuts' => $shortcuts,
                'app_profiles' => $appProfiles,
            ],
        ]);
    }

    /**
     * GET /api/v1/applications/{controlhubId}
     */
    public function showApplication(string $controlhubId): JsonResponse
    {
        $application = Application::where('controlhub_id', $controlhubId)->first();

        if (!$application) {
            return response()->json([
                'success' => false,
                'message' => 'Application non trouvée',
                'controlhub_id' => $controlhubId,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'controlhub_id' => $application->controlhub_id,
                'controlhub_version' => $this->formatTimestamp($application->controlhub_version),
                'app_id' => $application->app_id,
                'name' => $application->name,
                'version' => $application->version,
                'category' => $application->category,
                'compatibility' => $application->compatibility,
                'branch' => $application->branch,
                'xml' => $application->xml,
                'xml_url' => $application->xml_url,
                'xml_sha' => $application->xml_sha,
                'log_url' => $application->log_url,
                'status' => $application->status?->value,
                'managed_by_control_hub' => $application->managed_by_control_hub,
            ],
        ]);
    }

    /**
     * GET /api/v1/app-profiles/{controlhubId}
     */
    public function showAppProfile(string $controlhubId): JsonResponse
    {
        $profile = AppProfile::where('controlhub_id', $controlhubId)->first();

        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'App profile non trouvé',
                'controlhub_id' => $controlhubId,
            ], 404);
        }

        // Applications liées (par app_id, pas de controlhub_id sur Application)
        $applications = $profile->applications()
            ->select('applications.app_id', 'applications.name')
            ->get()
            ->map(fn ($app) => [
                'app_id' => $app->app_id,
                'name' => $app->name,
            ])
            ->values();

        // WorkstationGroups ayant un controlhub_id
        $groups = $profile->workstationGroups()
            ->whereNotNull('workstation_groups.controlhub_id')
            ->select('workstation_groups.controlhub_id', 'workstation_groups.name')
            ->get()
            ->map(fn (WorkstationGroup $g) => [
                'controlhub_id' => $g->controlhub_id,
                'name' => $g->name,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'controlhub_id' => $profile->controlhub_id,
                'controlhub_version' => $this->formatTimestamp($profile->controlhub_version),
                'name' => $profile->name,
                'description' => $profile->description,
                'applications' => $applications,
                'workstation_groups' => $groups,
            ],
        ]);
    }

    /**
     * Construit une map controlhub_id → controlhub_version (format normalisé UTC)
     *
     * @param Collection $entities
     * @return array<string, string|null>
     */
    private function buildTimestampMap(Collection $entities): array
    {
        $map = [];
        foreach ($entities as $entity) {
            $map[$entity->controlhub_id] = $this->formatTimestamp($entity->controlhub_version);
        }
        ksort($map);
        return $map;
    }

    /**
     * Calcule le hash SHA-256 déterministe pour un type d'entité.
     *
     * Algorithme (section 9 du doc) :
     * - Trier par controlhub_id (alphabétique)
     * - Concaténer : {type}:{controlhub_id}:{timestamp} séparés par |
     * - SHA-256
     *
     * @param string $type
     * @param array<string, string|null> $map (déjà trié par clé)
     * @return string
     */
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

    /**
     * Formate un timestamp en ISO 8601 UTC normalisé (YYYY-MM-DDTHH:MM:SSZ).
     * Pas de microsecondes, toujours UTC.
     *
     * @param mixed $timestamp
     * @return string|null
     */
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
