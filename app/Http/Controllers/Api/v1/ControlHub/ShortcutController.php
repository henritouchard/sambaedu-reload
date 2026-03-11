<?php

namespace App\Http\Controllers\Api\v1\ControlHub;

use App\Http\Controllers\Controller;
use App\Jobs\DeleteShortcutJob;
use App\Jobs\SyncShortcutJob;
use App\Models\ControlHubTask;
use App\Models\Shortcut;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ShortcutController extends Controller
{
    /**
     * Normalise le payload depuis le format nested (ControlHub) vers le format flat (interne).
     *
     * ControlHub envoie :
     *   windows: { link, args, path, icon: { data, mime, filename } }
     *   linux:   { link, args, path, startupwmclass, icon: { data, mime, filename } }
     *
     * On normalise en :
     *   windows_link, windows_args, windows_path, windows_icon: { data, mime, filename }
     *   linux_link, linux_args, linux_path, linux_startupwmclass, linux_icon: { data, mime, filename }
     */
    private function normalizePayload(array $payload): array
    {
        // Normaliser le bloc windows nested → flat
        if (isset($payload['windows']) && is_array($payload['windows'])) {
            $win = $payload['windows'];
            if (isset($win['link']) && ! isset($payload['windows_link'])) {
                $payload['windows_link'] = $win['link'];
            }
            if (isset($win['args']) && ! isset($payload['windows_args'])) {
                $payload['windows_args'] = $win['args'];
            }
            if (isset($win['path']) && ! isset($payload['windows_path'])) {
                $payload['windows_path'] = $win['path'];
            }
            if (isset($win['workdir']) && ! isset($payload['windows_workdir'])) {
                $payload['windows_workdir'] = $win['workdir'];
            }
            unset($payload['windows']);
        }

        // Normaliser le bloc linux nested → flat
        if (isset($payload['linux']) && is_array($payload['linux'])) {
            $lin = $payload['linux'];
            if (isset($lin['link']) && ! isset($payload['linux_link'])) {
                $payload['linux_link'] = $lin['link'];
            }
            if (isset($lin['args']) && ! isset($payload['linux_args'])) {
                $payload['linux_args'] = $lin['args'];
            }
            if (isset($lin['path']) && ! isset($payload['linux_path'])) {
                $payload['linux_path'] = $lin['path'];
            }
            if (isset($lin['startupwmclass']) && ! isset($payload['linux_startupwmclass'])) {
                $payload['linux_startupwmclass'] = $lin['startupwmclass'];
            }
            if (isset($lin['workdir']) && ! isset($payload['linux_workdir'])) {
                $payload['linux_workdir'] = $lin['workdir'];
            }
            unset($payload['linux']);
        }

        return $payload;
    }

    /**
     * Synchronisation (upsert) d'un raccourci depuis ControlHub.
     *
     * Si le raccourci existe et est à jour → rien.
     * Si le raccourci existe et n'est pas à jour → mise à jour.
     * Si le raccourci n'existe pas → création.
     */
    public function syncShortcut(Request $request): JsonResponse
    {
        Log::debug('syncShortcut', $request->all());

        try {
            $validated = $request->validate([
                'task_id' => 'required|uuid',
                'task_name' => 'required|string|max:255',
                'task_type' => 'required|string|in:sync_shortcut',
                'payload' => 'required|array',
                'payload.controlhub_id' => 'required',
                'payload.controlhub_version' => 'nullable|date',
                'payload.key' => 'nullable|string|max:100',
                'payload.name' => 'required|string|max:255',
                'payload.owner' => 'nullable|string|max:255',
                'payload.place' => 'nullable|string|in:desktop,startup,taskbar',
                'payload.icon' => 'nullable|string',
                'payload.category' => 'nullable|string|max:100',
                'payload.description' => 'nullable|string|max:1000',
                'payload.is_active' => 'nullable|boolean',
                'payload.is_url' => 'nullable|boolean',
                'payload.metadata' => 'nullable',
                // Windows
                'payload.windows' => 'nullable|array',
                'payload.windows.link' => 'nullable|string',
                'payload.windows.args' => 'nullable|string',
                'payload.windows.path' => 'nullable|string',
                'payload.windows.workdir' => 'nullable|string',
                // Linux
                'payload.linux' => 'nullable|array',
                'payload.linux.link' => 'nullable|string',
                'payload.linux.args' => 'nullable|string',
                'payload.linux.path' => 'nullable|string',
                'payload.linux.startupwmclass' => 'nullable|string',
                'payload.linux.workdir' => 'nullable|string',
                // Workstation groups
                'payload.workstation_groups' => 'nullable|array',
                'payload.workstation_groups.*' => 'required',
                'scheduled_at' => 'nullable|date',
            ]);

            // Normaliser le payload nested → flat avant stockage
            $validated['payload'] = $this->normalizePayload($validated['payload']);

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
                'type' => 'sync_shortcut',
                'payload' => $validated['payload'],
                'status' => ControlHubTask::STATUS_RECEIVED,
                'scheduled_at' => $validated['scheduled_at'] ?? null,
            ]);

            Log::info('sync_shortcut task created', [
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
            Log::warning('sync_shortcut validation failed', ['errors' => $e->errors()]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (Exception $e) {
            Log::error('Failed to process sync_shortcut task', [
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
     * Suppression d'un raccourci via controlhub_id.
     */
    public function deleteShortcut(Request $request): JsonResponse
    {
        Log::debug('deleteShortcut called', $request->all());
        try {
            $validated = $request->validate([
                'task_id' => 'required|uuid',
                'task_name' => 'required|string|max:255',
                'task_type' => 'required|string|in:delete_shortcut',
                'payload' => 'required|array',
                'payload.controlhub_id' => 'required|uuid',
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

            $controlhubId = $validated['payload']['controlhub_id'];
            $shortcut = Shortcut::where('controlhub_id', $controlhubId)->first();

            if (! $shortcut) {
                return response()->json([
                    'success' => false,
                    'message' => 'Raccourci non trouvé',
                    'controlhub_id' => $controlhubId,
                ], 404);
            }

            if (! $shortcut->is_global) {
                return response()->json([
                    'success' => false,
                    'message' => 'Suppression refusée',
                    'error' => "Le raccourci n'est pas un raccourci ControlHub.",
                ], 403);
            }

            $task = ControlHubTask::create([
                'controlhub_task_id' => $validated['task_id'],
                'name' => $validated['task_name'],
                'type' => $validated['task_type'],
                'payload' => $validated['payload'],
                'status' => ControlHubTask::STATUS_RECEIVED,
            ]);

            $task->markAsQueued();
            DeleteShortcutJob::dispatch($task);

            return response()->json([
                'success' => true,
                'message' => 'Task received and queued',
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
            Log::error('Failed to process delete_shortcut task', [
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
     * Dispatche un SyncShortcutJob avec délai optionnel.
     */
    private function dispatchWithDelay(ControlHubTask $task): void
    {
        $delay = 0;
        if ($task->scheduled_at && $task->scheduled_at->isFuture()) {
            $delay = now()->diffInSeconds($task->scheduled_at);
        }

        if ($delay > 0) {
            SyncShortcutJob::dispatch($task)->delay($delay);
        } else {
            SyncShortcutJob::dispatch($task);
        }
    }
}
