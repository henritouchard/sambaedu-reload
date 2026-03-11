<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Log;

/**
 * Job pour exécuter la tâche "greetme" ordonnée par le ControlHub.
 * Simule un délai aléatoire entre 10 et 20 secondes,
 * puis retourne "Bonjour grand maître".
 * 
 * Exemple de Job minimal héritant de BaseControlHubJob.
 */
class ExecuteGreetmeJob extends BaseControlHubJob
{
    /**
     * Exécute la logique métier spécifique à la tâche greetme.
     * 
     * @return array Le résultat de l'exécution
     */
    protected function execute(): array
    {
        // Délai aléatoire entre 10 et 20 secondes (pour simulation)
        $delay = rand(10, 20);

        Log::info('Greetme task sleeping', [
            'task_id' => $this->task->id,
            'delay_seconds' => $delay,
        ]);

        sleep($delay);

        // Retourner le résultat (sera enrichi automatiquement par BaseControlHubJob)
        return [
            'message' => 'Bonjour grand maître',
            'delay_seconds' => $delay,
        ];
    }
}
