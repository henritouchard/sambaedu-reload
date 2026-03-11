<?php

namespace App\Http\Controllers\Api\v1\ControlHub;

use App\Http\Controllers\Controller;
use App\Services\HealthCheckService;
use App\Services\StatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Contrôleur pour l'état et les métriques de l'instance SE4FS
 * 
 * Gère les informations de santé, métriques système et données statiques
 * de l'instance pour ControlHub et autres systèmes de monitoring.
 */
class InstanceStatusController extends Controller
{
    private HealthCheckService $healthCheckService;
    private StatsService $statsService;

    public function __construct(
        HealthCheckService $healthCheckService,
        StatsService $statsService
    ) {
        $this->healthCheckService = $healthCheckService;
        $this->statsService = $statsService;
    }

    /**
     * Vérification rapide de l'état de santé de l'instance
     * 
     * @return JsonResponse État de santé basique
     */
    public function check(): JsonResponse
    {
        $health = $this->healthCheckService->check();

        return response()->json($health, $health['status'] ? 200 : 503);
    }

    /**
     * Statistiques complètes de l'instance
     * 
     * Retourne les statistiques complètes du système : système, utilisateurs, fichiers et services.
     * Vue d'ensemble complète de l'instance.
     * 
     * @param Request $request
     * @return JsonResponse Statistiques complètes
     */
    public function getStats(Request $request): JsonResponse
    {
        try {
            $stats = $this->statsService->getStats();

            Log::info('Instance Stats request', [
                'system_hostname' => $stats['system']['hostname'] ?? 'unknown',
                'users_total' => $stats['users']['total'] ?? 0,
                'disk_usage_percent' => $stats['system']['disk_usage']['percentage'] ?? 0,
                'ip' => $request->ip()
            ]);

            return response()->json($stats, 200);

        } catch (Exception $e) {
            Log::error('Instance Stats error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
                'error_code' => 'SERVER_ERROR'
            ], 500);
        }
    }

    /**
     * Données statiques de l'instance
     * 
     * Informations qui ne changent pas fréquemment (UAI, nom, coordonnées, version).
     * À collecter une seule fois ou lors de changements.
     * 
     * @param Request $request
     * @return JsonResponse Données statiques de l'instance
     */
    public function getStaticData(Request $request): JsonResponse
    {
        try {
            $staticData = $this->statsService->getStaticData();

            Log::info('Instance Static Data request', [
                'uai' => $staticData['instance']['uai'] ?? 'unknown',
                'coordinates' => $staticData['instance']['coordinates'] ?? null,
                'ip' => $request->ip()
            ]);

            return response()->json($staticData, 200);

        } catch (Exception $e) {
            Log::error('Instance Static Data error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
                'error_code' => 'SERVER_ERROR'
            ], 500);
        }
    }

    /**
     * Contrôle de santé rapide de l'instance
     * 
     * État de santé système et services pour détection rapide des pannes.
     * À collecter toutes les 30 secondes.
     * 
     * @param Request $request
     * @return JsonResponse État de santé avec temps de réponse
     */
    public function getHealthCheck(Request $request): JsonResponse
    {
        try {
            $startTime = microtime(true);
            $healthData = $this->statsService->getHealthCheck();
            $responseTime = round((microtime(true) - $startTime) * 1000);

            $healthData['response_time'] = $responseTime;

            Log::info('Instance Health Check request', [
                'status' => $healthData['status'] ?? 'unknown',
                'response_time' => $responseTime,
                'services_error' => $healthData['quick_check']['services_error'] ?? 0,
                'ip' => $request->ip()
            ]);

            return response()->json($healthData, 200);

        } catch (Exception $e) {
            Log::error('Instance Health Check error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => false,
                'timestamp' => now()->toISOString(),
                'uai' => config('se4fs.establishment.uai', 'unknown'),
                'status' => 'error',
                'response_time' => 2500,
                'critical_alerts' => [
                    [
                        'type' => 'system_error',
                        'message' => 'Unable to retrieve health data',
                        'since' => now()->toISOString()
                    ]
                ]
            ], 500);
        }
    }

    /**
     * Métriques détaillées de l'instance
     * 
     * Métriques système détaillées (CPU, RAM, utilisateurs connectés).
     * À collecter toutes les 5 minutes.
     * 
     * @param Request $request
     * @return JsonResponse Métriques système et activité
     */
    public function getMetricsData(Request $request): JsonResponse
    {
        try {
            $metricsData = $this->statsService->getMetricsData();

            Log::info('Instance Metrics request', [
                'cpu_usage' => $metricsData['system']['cpu_usage'] ?? 0,
                'memory_usage' => $metricsData['system']['memory_usage'] ?? 0,
                'users_connected' => $metricsData['activity']['users_connected'] ?? 0,
                'ip' => $request->ip()
            ]);

            return response()->json($metricsData, 200);

        } catch (Exception $e) {
            Log::error('Instance Metrics error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
                'error_code' => 'SERVER_ERROR'
            ], 500);
        }
    }

    /**
     * Données historiques de l'instance
     * 
     * Données historiques et tendances sur une période donnée.
     * À collecter une fois par heure.
     * 
     * @param Request $request
     * @param string $period Période (1h, 24h, 7d, 30d)
     * @return JsonResponse Données historiques avec résumé
     */
    public function getHistoricalData(Request $request, string $period): JsonResponse
    {
        try {
            $allowedPeriods = ['1h', '24h', '7d', '30d'];

            if (!in_array($period, $allowedPeriods)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid period. Supported: ' . implode(', ', $allowedPeriods),
                    'error_code' => 'INVALID_PERIOD'
                ], 400);
            }

            $historicalData = $this->statsService->getHistoricalData($period);

            Log::info('Instance Historical Data request', [
                'period' => $period,
                'data_points' => $historicalData['data_points'] ?? 0,
                'sampling_interval' => $historicalData['sampling_interval'] ?? 0,
                'ip' => $request->ip()
            ]);

            return response()->json($historicalData, 200);

        } catch (Exception $e) {
            Log::error('Instance Historical Data error', [
                'period' => $period,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
                'error_code' => 'SERVER_ERROR'
            ], 500);
        }
    }
}

