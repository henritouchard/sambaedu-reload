<?php

namespace App\Http\Controllers\Api\v1\ControlHub;

use App\Http\Controllers\Controller;
use App\Jobs\SyncManifestJob;
use App\Models\ControlHubTask;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SyncManifestController extends Controller
{
    /**
     * POST /api/v1/sync-manifest
     *
     * Reçoit un manifeste déclaratif complet et dispatch un job de convergence.
     */
    public function syncManifest(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'task_id' => 'required|uuid',
                'task_name' => 'required|string|max:255',
                'task_type' => 'required|string|in:sync_manifest',
                'manifest_version' => 'required|string',
                'payload' => 'required|array',
                // Shortcuts
                'payload.shortcuts' => 'nullable|array',
                'payload.shortcuts.*.controlhub_id' => 'required|uuid',
                'payload.shortcuts.*.controlhub_version' => 'nullable|date',
                'payload.shortcuts.*.name' => 'required|string|max:255',
                'payload.shortcuts.*.owner' => 'nullable|string|max:255',
                'payload.shortcuts.*.place' => 'nullable|string|in:desktop,startup,taskbar',
                // App profiles
                'payload.app_profiles' => 'nullable|array',
                'payload.app_profiles.*.controlhub_id' => 'required|uuid',
                'payload.app_profiles.*.controlhub_version' => 'nullable|date',
                'payload.app_profiles.*.name' => 'required|string|max:255',
                'payload.app_profiles.*.description' => 'nullable|string|max:1000',
                'payload.app_profiles.*.applications' => 'nullable|array',
                'payload.app_profiles.*.applications.*.controlhub_id' => 'nullable|uuid',
                'payload.app_profiles.*.applications.*.controlhub_version' => 'nullable|date',
                'payload.app_profiles.*.applications.*.app_id' => 'required|string',
                'payload.app_profiles.*.applications.*.name' => 'nullable|string|max:255',
                'payload.app_profiles.*.applications.*.version' => 'nullable|string|max:100',
                'payload.app_profiles.*.applications.*.category' => 'nullable|string|max:100',
                'payload.app_profiles.*.applications.*.compatibility' => 'nullable|string|max:255',
                'payload.app_profiles.*.applications.*.branch' => 'nullable|string|max:50',
                'payload.app_profiles.*.applications.*.xml' => 'nullable|string',
                'payload.app_profiles.*.applications.*.xml_url' => 'nullable|string|max:512',
                'payload.app_profiles.*.applications.*.xml_sha' => 'nullable|string|max:128',
                'payload.app_profiles.*.applications.*.log_url' => 'nullable|string|max:512',
                // Workstation groups
                'payload.workstation_groups' => 'nullable|array',
                'payload.workstation_groups.*.controlhub_id' => 'required|uuid',
                'payload.workstation_groups.*.controlhub_version' => 'nullable|date',
                'payload.workstation_groups.*.name' => 'required|string|max:255',
                'payload.workstation_groups.*.display_name' => 'nullable|string|max:255',
                'payload.workstation_groups.*.description' => 'nullable|string|max:1000',
                'payload.workstation_groups.*.is_physical' => 'nullable|boolean',
                'payload.workstation_groups.*.parent_controlhub_id' => 'nullable|uuid',
                'payload.workstation_groups.*.shortcuts' => 'nullable|array',
                'payload.workstation_groups.*.shortcuts.*.controlhub_id' => 'required|uuid',
                'payload.workstation_groups.*.app_profiles' => 'nullable|array',
                'payload.workstation_groups.*.app_profiles.*.controlhub_id' => 'required|uuid',
            ]);

            // Idempotence
            $existingTask = ControlHubTask::where('controlhub_task_id', $validated['task_id'])->first();
            if ($existingTask) {
                Log::info('sync_manifest task already exists', [
                    'task_id' => $validated['task_id'],
                    'local_id' => $existingTask->id,
                    'status' => $existingTask->status,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Task already received',
                    'task_id' => $existingTask->id,
                    'status' => $existingTask->status,
                ]);
            }

            // Injecter manifest_version dans le payload pour le job
            $validated['payload']['manifest_version'] = $validated['manifest_version'];

            // Enregistrer la tâche
            $task = ControlHubTask::create([
                'controlhub_task_id' => $validated['task_id'],
                'name' => $validated['task_name'],
                'type' => $validated['task_type'],
                'payload' => $validated['payload'],
                'status' => ControlHubTask::STATUS_RECEIVED,
            ]);

            Log::info('sync_manifest task created', [
                'controlhub_task_id' => $validated['task_id'],
                'local_id' => $task->id,
                'manifest_version' => $validated['manifest_version'],
                'shortcuts_count' => count($validated['payload']['shortcuts'] ?? []),
                'app_profiles_count' => count($validated['payload']['app_profiles'] ?? []),
                'workstation_groups_count' => count($validated['payload']['workstation_groups'] ?? []),
            ]);

            $task->markAsQueued();
            SyncManifestJob::dispatch($task);

            return response()->json([
                'success' => true,
                'message' => 'Task received and queued',
                'task_id' => $task->id,
                'status' => $task->status,
            ]);

        } catch (ValidationException $e) {
            Log::warning('sync_manifest validation failed', [
                'errors' => $e->errors(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (Exception $e) {
            Log::error('Failed to process sync_manifest task', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process task',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
