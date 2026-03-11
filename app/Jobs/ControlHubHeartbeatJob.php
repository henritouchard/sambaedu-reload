<?php

namespace App\Jobs;

use App\Services\ControlHub\ControlHubService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ControlHubHeartbeatJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 3;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->tries = config('controlHub.heartbeat.max_retries', 3);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Vérifier si le heartbeat est activé
        if (!config('controlHub.heartbeat.enabled', true)) {
            Log::info('ControlHub Heartbeat désactivé via configuration');
            return;
        }

        // Vérifier si l'instance est connectée et le heartbeat actif
        if (!$this->isInstanceConnected() || !Cache::get('controlHub_heartbeat_active', false)) {
            Log::warning('ControlHub Heartbeat arrêté : instance non connectée ou heartbeat inactif');
            Cache::forget('controlHub_heartbeat_active');
            return;
        }

        // Vérifier si assez de temps s'est écoulé depuis le dernier heartbeat
        $lastHeartbeat = Cache::get('controlHub_last_heartbeat_time');
        $interval = config('controlHub.heartbeat.interval', 5) * 60; // en secondes

        if ($lastHeartbeat && (time() - $lastHeartbeat) < $interval) {
            Log::info('ControlHub Heartbeat ignoré : intervalle non écoulé', [
                'last_heartbeat' => date('Y-m-d H:i:s', $lastHeartbeat),
                'next_allowed' => date('Y-m-d H:i:s', $lastHeartbeat + $interval)
            ]);
            return;
        }

        try {
            $controlHubService = app(ControlHubService::class);

            Log::info('ControlHub Heartbeat automatique - Début');

            // Exécuter le heartbeat
            $controlHubService->performHeartbeat();

            // Marquer le dernier heartbeat réussi
            Cache::put('controlHub_last_heartbeat', now(), now()->addHours(24));
            Cache::put('controlHub_last_heartbeat_time', time(), now()->addHours(24));
            Cache::forget('controlHub_heartbeat_failures');

            Log::info('ControlHub Heartbeat automatique - Succès');

            // Programmer le prochain heartbeat
            $this->scheduleNext();

        } catch (\Exception $e) {
            Log::error('ControlHub Heartbeat automatique - Échec', [
                'error' => $e->getMessage(),
                'attempt' => $this->attempts()
            ]);

            // Incrémenter le compteur d'échecs
            $failures = Cache::get('controlHub_heartbeat_failures', 0) + 1;
            Cache::put('controlHub_heartbeat_failures', $failures, now()->addHours(24));

            // Si c'est le dernier essai et qu'on a trop d'échecs, arrêter
            if ($this->attempts() >= $this->tries && $failures >= 5) {
                Log::critical('ControlHub Heartbeat automatique - Arrêt définitif après échecs répétés', [
                    'failures' => $failures
                ]);
                Cache::forget('controlHub_instance_connected');
                Cache::forget('controlHub_heartbeat_active');
                return;
            }

            // Si ce n'est pas le dernier essai, on laisse Laravel retry
            if ($this->attempts() < $this->tries) {
                throw $e;
            }

            // Programmer le prochain heartbeat même après échec (avec délai plus long)
            $this->scheduleNext(true);
        }
    }

    /**
     * Vérifier si l'instance ControlHub est connectée
     */
    private function isInstanceConnected(): bool
    {
        return Cache::get('controlHub_instance_connected', false);
    }

    /**
     * Programmer le prochain heartbeat
     */
    private function scheduleNext(bool $afterFailure = false): void
    {
        $interval = config('controlHub.heartbeat.interval', 5); // minutes

        // En cas d'échec, doubler l'intervalle
        if ($afterFailure) {
            $interval *= 2;
        }

        $nextRunTimestamp = time() + ($interval * 60);
        $nextRun = now()->addMinutes($interval);

        // Stocker le timestamp du prochain heartbeat prévu
        Cache::put('controlHub_next_heartbeat_time', $nextRunTimestamp, now()->addHours(24));

        Log::info('ControlHub Heartbeat automatique - Prochain heartbeat programmé', [
            'next_run' => $nextRun->toDateTimeString(),
            'interval_minutes' => $interval,
            'next_timestamp' => $nextRunTimestamp
        ]);

        // Programmer le prochain job avec un délai en minutes
        static::dispatch()->delay($nextRun);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ControlHub Heartbeat Job - Échec définitif', [
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);
    }
}
