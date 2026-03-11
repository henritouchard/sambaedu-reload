<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\ControlHub\ControlHubApiClient;
use App\Services\ControlHub\ControlHubService;
use App\Repositories\ControlHubConnectionRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ControlHubServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register Repository
        $this->app->singleton(ControlHubConnectionRepository::class, function ($app) {
            return new ControlHubConnectionRepository();
        });

        // Register API Client
        $this->app->singleton(ControlHubApiClient::class, function ($app) {
            $baseUrl = config('controlHub.base_url', 'http://192.168.122.1:8080');
            $timeout = config('controlHub.api.timeout', 30);
            $connectTimeout = config('controlHub.api.connect_timeout', 10);

            return new ControlHubApiClient($baseUrl, $timeout, $connectTimeout);
        });

        // Register Service
        $this->app->singleton(ControlHubService::class, function ($app) {
            return new ControlHubService(
                $app->make(ControlHubApiClient::class),
                $app->make(ControlHubConnectionRepository::class)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->autoStartHeartbeat();
    }

    /**
     * Démarrer automatiquement le heartbeat si une connexion active existe
     */
    private function autoStartHeartbeat(): void
    {
        if ($this->app->runningInConsole() && !$this->app->runningUnitTests()) {
            return;
        }

        try {
            $repository = $this->app->make(ControlHubConnectionRepository::class);
            $connection = $repository->getCurrentConnection();

            if ($connection && !$connection->isExpired()) {
                if (!Cache::get('controlHub_heartbeat_active', false)) {
                    Cache::put('controlHub_instance_connected', true, now()->addHours(24));
                    Cache::put('controlHub_heartbeat_active', true, now()->addHours(24));

                    Log::info('ControlHub Heartbeat - Démarrage automatique au boot', [
                        'connection_id' => $connection->id,
                        'status' => $connection->status
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::debug('ControlHub Heartbeat - Auto-start ignoré', [
                'reason' => $e->getMessage()
            ]);
        }
    }
}

