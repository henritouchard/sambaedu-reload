<?php

namespace App\Http\Controllers\Api\v1\Tasks;

use App\Http\Controllers\Controller;
use App\Models\ControlHubTask;
use App\Jobs\ExecuteGreetmeJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Contrôleur pour les tâches génériques ControlHub (greetme, etc.)
 * Les tâches métier spécifiques sont dans leurs controllers respectifs
 * (ex: ShortcutController pour create_shortcut)
 */
class TaskController extends Controller
{
    /**
     * Réception de la tâche greetme (tâche de test)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function greetme(Request $request): JsonResponse
    {
        Log::debug('Received greetme task from ControlHub', [
            'task_id' => $request->input('task_id'),
            'task_name' => $request->input('task_name'),
        ]);

        try {
            $validated = $request->validate([
                'task_id' => 'required|uuid',
                'task_name' => 'required|string|max:255',
                'task_type' => 'required|string|in:greetme',
                'payload' => 'nullable|array',
                'scheduled_at' => 'nullable|date',
            ]);

            Log::info('Received greetme task from ControlHub', [
                'task_id' => $validated['task_id'],
                'task_name' => $validated['task_name'],
            ]);

            // Vérifier si la tâche existe déjà (idempotence)
            $existingTask = ControlHubTask::where('controlhub_task_id', $validated['task_id'])->first();
            if ($existingTask) {
                Log::info('Task already exists, returning existing task', [
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

            // Enregistrer la tâche en base
            $task = ControlHubTask::create([
                'controlhub_task_id' => $validated['task_id'],
                'name' => $validated['task_name'],
                'type' => $validated['task_type'],
                'payload' => $validated['payload'] ?? [],
                'status' => ControlHubTask::STATUS_RECEIVED,
                'scheduled_at' => $validated['scheduled_at'] ?? null,
            ]);

            Log::info('Task created locally', [
                'controlhub_task_id' => $validated['task_id'],
                'local_id' => $task->id,
            ]);

            // Dispatcher le job (avec délai si scheduled_at)
            $delay = 0;
            if ($task->scheduled_at && $task->scheduled_at->isFuture()) {
                $delay = now()->diffInSeconds($task->scheduled_at);
            }

            $task->markAsQueued();

            if ($delay > 0) {
                ExecuteGreetmeJob::dispatch($task)->delay($delay);
                Log::info('Task scheduled for later execution', [
                    'task_id' => $task->id,
                    'delay_seconds' => $delay,
                ]);
            } else {
                ExecuteGreetmeJob::dispatch($task);
                Log::info('Task dispatched for immediate execution', [
                    'task_id' => $task->id,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Task received and queued',
                'task_id' => $task->id,
                'status' => $task->status,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Greetme task validation failed', [
                'errors' => $e->errors(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('Failed to process greetme task', [
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
