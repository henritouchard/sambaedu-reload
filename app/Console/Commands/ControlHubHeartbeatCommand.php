<?php

namespace App\Console\Commands;

use App\Services\ControlHub\ControlHubService;
use App\Models\ControlHubConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ControlHubHeartbeatCommand extends Command
{
    protected $signature = 'controlhub:heartbeat';
    protected $description = 'Execute ControlHub heartbeat if conditions are met';

    public function handle()
    {
        if (!config('controlHub.heartbeat.enabled', true)) {
            $this->info('ControlHub Heartbeat désactivé via configuration');
            return 0;
        }

        // Vérifier directement en BDD s'il y a une connexion active (pas de dépendance au cache)
        $connection = ControlHubConnection::current();
        if (!$connection) {
            $this->info('ControlHub Heartbeat ignoré : aucune connexion active en BDD');
            return 0;
        }

        // Vérifier si le heartbeat est activé pour cette connexion
        if (!$connection->heartbeat_enabled) {
            $this->info('ControlHub Heartbeat ignoré : heartbeat désactivé pour cette connexion');
            return 0;
        }

        // Utiliser l'intervalle de la connexion ou la config par défaut
        $interval = ($connection->heartbeat_interval ?? config('controlHub.heartbeat.interval', 5) * 60);

        // Vérifier le dernier heartbeat depuis la BDD
        $lastHeartbeat = $connection->last_heartbeat_at;
        if ($lastHeartbeat && $lastHeartbeat->diffInSeconds(now()) < $interval) {
            $this->info('ControlHub Heartbeat ignoré : intervalle non écoulé');
            $this->line('Dernier heartbeat: ' . $lastHeartbeat->toDateTimeString());
            $this->line('Prochain autorisé: ' . $lastHeartbeat->addSeconds($interval)->toDateTimeString());
            return 0;
        }

        try {
            $service = app(ControlHubService::class);

            $this->info('Exécution du heartbeat ControlHub...');
            Log::info('ControlHub Heartbeat automatique - Début (via scheduler)');
            $service->performHeartbeat();

            $this->info('Heartbeat ControlHub exécuté avec succès');
            Log::info('ControlHub Heartbeat automatique - Succès (via scheduler)');

            return 0;
        } catch (\Exception $e) {
            $this->error('Erreur lors du heartbeat ControlHub: ' . $e->getMessage());
            Log::error('ControlHub Heartbeat automatique - Échec (via scheduler)', [
                'error' => $e->getMessage()
            ]);

            // Incrémenter le compteur d'échecs en BDD
            $connection->increment('heartbeat_failures');

            if ($connection->heartbeat_failures >= config('controlHub.heartbeat.max_failures', 5)) {
                $this->error('Trop d\'échecs consécutifs, passage en statut erreur');
                Log::critical('ControlHub Heartbeat automatique - Statut erreur après échecs répétés', [
                    'failures' => $connection->heartbeat_failures
                ]);

                $connection->updateStatus('error', 'heartbeat');
            }

            return 1;
        }
    }
}


