<?php

namespace App\Http\Controllers\Api\v1\ControlHub;

use App\Http\Controllers\Controller;
use App\Jobs\SyncAppProfileJob;
use App\Models\ControlHubTask;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AppProfileController extends Controller
{
    /**
     * Synchronisation (upsert) d'un profil applicatif depuis ControlHub.
     *
     * POST /api/v1/app-profiles/sync
     */
    public function syncAppProfile(Request $request): JsonResponse
    {
        Log::debug('syncAppProfile', $request->all());

        try {
            $validated = $request->validate([
                'task_id' => 'required|uuid',
                'task_name' => 'required|string|max:255',
                'task_type' => 'required|string|in:sync_app_profile',
                'payload' => 'required|array',
                'payload.controlhub_id' => 'required|uuid',
                'payload.controlhub_version' => 'nullable|date',
                'payload.name' => 'required|string|max:255',
                'payload.display_name' => 'nullable|string|max:255',
                'payload.description' => 'nullable|string|max:1000',
                'payload.is_active' => 'nullable|boolean',
                'payload.applications' => 'nullable|array',
                'payload.applications.*.controlhub_id' => 'nullable|uuid',
                'payload.applications.*.controlhub_version' => 'nullable|date',
                'payload.applications.*.app_id' => 'required|string|max:255',
                'payload.applications.*.name' => 'nullable|string|max:255',
                'payload.applications.*.version' => 'nullable|string|max:100',
                'payload.applications.*.category' => 'nullable|string|max:100',
                'payload.applications.*.compatibility' => 'nullable|string|max:255',
                'payload.applications.*.branch' => 'nullable|string|max:50',
                'payload.applications.*.xml' => 'nullable|string',
                'payload.applications.*.xml_url' => 'nullable|string|max:512',
                'payload.applications.*.xml_sha' => 'nullable|string|max:128',
                'payload.applications.*.log_url' => 'nullable|string|max:512',
                'payload.workstation_groups' => 'nullable|array',
                'payload.workstation_groups.*.controlhub_id' => 'required|uuid',
                'scheduled_at' => 'nullable|date',
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
                'type' => 'sync_app_profile',
                'payload' => $validated['payload'],
                'status' => ControlHubTask::STATUS_RECEIVED,
                'scheduled_at' => $validated['scheduled_at'] ?? null,
            ]);

            Log::info('sync_app_profile task created', [
                'controlhub_task_id' => $validated['task_id'],
                'local_id' => $task->id,
                'controlhub_id' => $validated['payload']['controlhub_id'],
            ]);

            $task->markAsQueued();
            $this->dispatchWithDelay($task);

            return response()->json([
                'success' => true,
                'message' => 'Task received and queued',
                'task_id' => $task->id,
                'status' => $task->status,
            ]);

        } catch (ValidationException $e) {
            Log::warning('sync_app_profile validation failed', ['errors' => $e->errors()]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (Exception $e) {
            Log::error('Failed to process sync_app_profile task', [
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

    /**
     * Dispatche un SyncAppProfileJob avec délai optionnel.
     */
    private function dispatchWithDelay(ControlHubTask $task): void
    {
        $delay = 0;
        if ($task->scheduled_at && $task->scheduled_at->isFuture()) {
            $delay = now()->diffInSeconds($task->scheduled_at);
        }

        if ($delay > 0) {
            SyncAppProfileJob::dispatch($task)->delay($delay);
        } else {
            SyncAppProfileJob::dispatch($task);
        }
    }
}
