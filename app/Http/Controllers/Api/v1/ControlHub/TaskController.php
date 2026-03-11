<?php

namespace App\Http\Controllers\Api\v1\ControlHub;

use App\Http\Controllers\Controller;
use App\Models\ControlHubTask;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Contrôleur pour la gestion des tâches ControlHub
 */
class TaskController extends Controller
{
    /**
     * Annule une tâche si elle n'a pas encore débuté
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function cancel(Request $request): JsonResponse
    {
        Log::debug('Received task cancel request from ControlHub', [
            'task_id' => $request->input('task_id'),
        ]);

        try {
            $validated = $request->validate([
                'task_id' => 'required|uuid',
            ]);

            return DB::transaction(function () use ($validated) {
                // Verrouillage pessimiste pour éviter les race conditions
                $task = ControlHubTask::where('controlhub_task_id', $validated['task_id'])
                    ->lockForUpdate()
                    ->first();

                if (!$task) {
                    Log::warning('Task not found for cancellation', [
                        'task_id' => $validated['task_id'],
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Task not found',
                    ], 404);
                }

                if (!$task->canBeCanceled()) {
                    Log::info('Task cannot be canceled - already started or completed', [
                        'task_id' => $validated['task_id'],
                        'local_id' => $task->id,
                        'status' => $task->status,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Task cannot be canceled',
                        'reason' => 'Task is already ' . $task->status,
                        'task_id' => $task->id,
                        'status' => $task->status,
                    ], 409);
                }

                $task->markAsCanceled();

                Log::info('Task canceled successfully', [
                    'task_id' => $validated['task_id'],
                    'local_id' => $task->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Task canceled successfully',
                    'task_id' => $task->id,
                    'status' => $task->status,
                ]);
            });

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Task cancel validation failed', [
                'errors' => $e->errors(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('Failed to cancel task', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel task',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
