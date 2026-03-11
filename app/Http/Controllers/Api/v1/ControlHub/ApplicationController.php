<?php

namespace App\Http\Controllers\Api\v1\ControlHub;

use App\Http\Controllers\Controller;
use App\Jobs\SyncApplicationJob;
use App\Models\ControlHubTask;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ApplicationController extends Controller
{
    /**
     * Synchronisation (upsert) d'une application depuis ControlHub.
     *
     * POST /api/v1/applications/sync
     *
     * Si l'application existe et est à jour → rien.
     * Si l'application existe et n'est pas à jour → mise à jour.
     * Si l'application n'existe pas → création.
     */
    public function syncApplication(Request $request): JsonResponse
    {
        Log::debug('syncApplication', $request->all());

        try {
            $validated = $request->validate([
                'task_id' => 'required|uuid',
                'task_name' => 'required|string|max:255',
                'task_type' => 'required|string|in:sync_application',
                'payload' => 'required|array',
                'payload.controlhub_id' => 'required|uuid',
                'payload.controlhub_version' => 'nullable|date',
                'payload.app_id' => 'required|string|max:255',
                'payload.name' => 'required|string|max:255',
                'payload.version' => 'nullable|string|max:100',
                'payload.category' => 'nullable|string|max:100',
                'payload.compatibility' => 'nullable|string|max:255',
                'payload.branch' => 'nullable|string|max:50',
                'payload.xml' => 'nullable|string',
                'payload.xml_url' => 'nullable|string|max:512',
                'payload.xml_sha' => 'nullable|string|max:128',
                'payload.log_url' => 'nullable|string|max:512',
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
                'type' => 'sync_application',
                'payload' => $validated['payload'],
                'status' => ControlHubTask::STATUS_RECEIVED,
                'scheduled_at' => $validated['scheduled_at'] ?? null,
            ]);

            Log::info('sync_application task created', [
                'controlhub_task_id' => $validated['task_id'],
                'local_id' => $task->id,
                'controlhub_id' => $validated['payload']['controlhub_id'],
                'app_id' => $validated['payload']['app_id'],
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
            Log::warning('sync_application validation failed', ['errors' => $e->errors()]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (Exception $e) {
            Log::error('Failed to process sync_application task', [
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
     * Dispatche un SyncApplicationJob avec délai optionnel.
     */
    private function dispatchWithDelay(ControlHubTask $task): void
    {
        $delay = 0;
        if ($task->scheduled_at && $task->scheduled_at->isFuture()) {
            $delay = now()->diffInSeconds($task->scheduled_at);
        }

        if ($delay > 0) {
            SyncApplicationJob::dispatch($task)->delay($delay);
        } else {
            SyncApplicationJob::dispatch($task);
        }
    }
}
