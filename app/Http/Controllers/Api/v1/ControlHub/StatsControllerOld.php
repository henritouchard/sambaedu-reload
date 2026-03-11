<?php

namespace App\Http\Controllers\Api\v1\SE4FS;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\StatsService;
use OpenApi\Attributes as OA;
use Illuminate\Support\Facades\Log;

/**
 * Cette classe n'esst plus utilisée, elle est juste conservée pour ces méthodes qui m'intéresseront bientôt.
 */
class StatsControllerOld extends Controller
{
    private StatsService $statsService;

    public function __construct(StatsService $statsService)
    {
        $this->statsService = $statsService;
    }

    #[OA\Get(
        path: "/api/v1/stats",
        summary: "Statistiques système SE4FS",
        description: "Retourne les statistiques complètes du système SE4FS : système, utilisateurs, fichiers et services. Endpoint authentifié par token API.",
        security: [["se4fs_auth" => []]],
        tags: ["SE4FS"]
    )]
    #[OA\Response(
        response: 200,
        description: "Statistiques récupérées avec succès",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                "success" => new OA\Property(property: "success", type: "boolean", example: true),
                "timestamp" => new OA\Property(property: "timestamp", type: "string", format: "date-time"),
                "system" => new OA\Property(
                    property: "system",
                    type: "object",
                    properties: [
                        "hostname" => new OA\Property(property: "hostname", type: "string", example: "se4fs-prod"),
                        "uptime_seconds" => new OA\Property(property: "uptime_seconds", type: "integer", example: 1234567),
                        "cpu_usage_percent" => new OA\Property(property: "cpu_usage_percent", type: "number", example: 45.2),
                        "memory_usage_percent" => new OA\Property(property: "memory_usage_percent", type: "number", example: 68.1),
                        "load_average" => new OA\Property(
                            property: "load_average",
                            type: "array",
                            items: new OA\Items(type: "number"),
                            example: [0.85, 0.92, 0.78]
                        ),
                        "disk_usage" => new OA\Property(
                            property: "disk_usage",
                            type: "object",
                            properties: [
                                "total_gb" => new OA\Property(property: "total_gb", type: "number", example: 2000),
                                "used_gb" => new OA\Property(property: "used_gb", type: "number", example: 856.4),
                                "available_gb" => new OA\Property(property: "available_gb", type: "number", example: 1143.6),
                                "percentage" => new OA\Property(property: "percentage", type: "number", example: 42.8)
                            ]
                        ),
                        "network_io" => new OA\Property(
                            property: "network_io",
                            type: "object",
                            properties: [
                                "bytes_in" => new OA\Property(property: "bytes_in", type: "integer", example: 1234567890),
                                "bytes_out" => new OA\Property(property: "bytes_out", type: "integer", example: 987654321)
                            ]
                        )
                    ]
                ),
                "users" => new OA\Property(
                    property: "users",
                    type: "object",
                    properties: [
                        "total" => new OA\Property(property: "total", type: "integer", example: 1250),
                        "active_today" => new OA\Property(property: "active_today", type: "integer", example: 89),
                        "active_this_week" => new OA\Property(property: "active_this_week", type: "integer", example: 456),
                        "new_this_month" => new OA\Property(property: "new_this_month", type: "integer", example: 23)
                    ]
                ),
                "files" => new OA\Property(
                    property: "files",
                    type: "object",
                    properties: [
                        "total_files" => new OA\Property(property: "total_files", type: "integer", example: 45678),
                        "total_size_gb" => new OA\Property(property: "total_size_gb", type: "number", example: 856.4),
                        "uploads_today" => new OA\Property(property: "uploads_today", type: "integer", example: 23),
                        "downloads_today" => new OA\Property(property: "downloads_today", type: "integer", example: 156),
                        "shares_today" => new OA\Property(property: "shares_today", type: "integer", example: 12)
                    ]
                ),
                "services" => new OA\Property(
                    property: "services",
                    type: "object",
                    properties: [
                        "samba" => new OA\Property(property: "samba", type: "string", example: "running"),
                        "ldap" => new OA\Property(property: "ldap", type: "string", example: "running"),
                        "web_server" => new OA\Property(property: "web_server", type: "string", example: "running"),
                        "backup_service" => new OA\Property(property: "backup_service", type: "string", example: "running")
                    ]
                )
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: "Token API invalide",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                "success" => new OA\Property(property: "success", type: "boolean", example: false),
                "message" => new OA\Property(property: "message", type: "string", example: "Invalid API token"),
                "error_code" => new OA\Property(property: "error_code", type: "string", example: "AUTH_REQUIRED")
            ]
        )
    )]
    #[OA\Response(
        response: 500,
        description: "Erreur serveur",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                "success" => new OA\Property(property: "success", type: "boolean", example: false),
                "message" => new OA\Property(property: "message", type: "string", example: "Internal server error"),
                "error_code" => new OA\Property(property: "error_code", type: "string", example: "SERVER_ERROR")
            ]
        )
    )]

    public function index(Request $request): JsonResponse
    {
        try {
            // Récupération des statistiques
            $stats = $this->statsService->getStats();

            Log::info('SE4FS Stats request', [
                'system_hostname' => $stats['system']['hostname'] ?? 'unknown',
                'users_total' => $stats['users']['total'] ?? 0,
                'disk_usage_percent' => $stats['system']['disk_usage']['percentage'] ?? 0,
                'token' => $request->attributes->get('se4fs_token'),
                'ip' => $request->ip()
            ]);

            return response()->json($stats, 200);

        } catch (\Exception $e) {
            Log::error('SE4FS Stats error', [
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

    #[OA\Get(
        path: "/api/v1/static",
        summary: "Données statiques de l'instance SE4FS",
        description: "Retourne les données statiques (coordonnées, établissement, infos qui ne changent pas). À collecter une seule fois ou lors de changements.",
        security: [["se4fs_auth" => []]],
        tags: ["SE4FS"]
    )]
    #[OA\Response(
        response: 200,
        description: "Données statiques récupérées avec succès",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                "success" => new OA\Property(property: "success", type: "boolean", example: true),
                "timestamp" => new OA\Property(property: "timestamp", type: "string", format: "date-time"),
                "collection_interval" => new OA\Property(property: "collection_interval", type: "string", nullable: true, example: null),
                "note" => new OA\Property(property: "note", type: "string", example: "Collect only once or when changed"),
                "instance" => new OA\Property(
                    property: "instance",
                    type: "object",
                    properties: [
                        "uai" => new OA\Property(property: "uai", type: "string", example: "0751234A"),
                        "name" => new OA\Property(property: "name", type: "string", example: "Lycée Jean Moulin"),
                        "coordinates" => new OA\Property(
                            property: "coordinates",
                            type: "object",
                            properties: [
                                "latitude" => new OA\Property(property: "latitude", type: "number", example: 48.8566),
                                "longitude" => new OA\Property(property: "longitude", type: "number", example: 2.3522)
                            ]
                        ),
                        "version" => new OA\Property(property: "version", type: "string", example: "4.2.1"),
                        "install_date" => new OA\Property(property: "install_date", type: "string", format: "date", example: "2024-09-15"),
                        "last_update" => new OA\Property(property: "last_update", type: "string", format: "date", example: "2024-12-01")
                    ]
                )
            ]
        )
    )]
    public function getStaticData(Request $request): JsonResponse
    {
        try {
            $staticData = $this->statsService->getStaticData();

            Log::info('SE4FS Static Data request', [
                'uai' => $staticData['instance']['uai'] ?? 'unknown',
                'coordinates' => $staticData['instance']['coordinates'] ?? null,
                'token' => $request->attributes->get('se4fs_token'),
                'ip' => $request->ip()
            ]);

            return response()->json($staticData, 200);

        } catch (\Exception $e) {
            Log::error('SE4FS Static Data error', [
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

    #[OA\Get(
        path: "/api/v1/health",
        summary: "Contrôle de santé rapide SE4FS",
        description: "Retourne l'état de santé système et services. À collecter toutes les 30 secondes pour détection rapide des pannes.",
        security: [["se4fs_auth" => []]],
        tags: ["SE4FS"]
    )]
    #[OA\Response(
        response: 200,
        description: "État de santé récupéré avec succès",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                "success" => new OA\Property(property: "success", type: "boolean", example: true),
                "timestamp" => new OA\Property(property: "timestamp", type: "string", format: "date-time"),
                "collection_interval" => new OA\Property(property: "collection_interval", type: "integer", example: 30),
                "uai" => new OA\Property(property: "uai", type: "string", example: "0751234A"),
                "status" => new OA\Property(property: "status", type: "string", example: "active"),
                "response_time" => new OA\Property(property: "response_time", type: "integer", example: 145),
                "services" => new OA\Property(
                    property: "services",
                    type: "object",
                    properties: [
                        "samba" => new OA\Property(property: "samba", type: "string", example: "running"),
                        "ldap" => new OA\Property(property: "ldap", type: "string", example: "running"),
                        "apache" => new OA\Property(property: "apache", type: "string", example: "running")
                    ]
                ),
                "quick_check" => new OA\Property(
                    property: "quick_check",
                    type: "object",
                    properties: [
                        "cpu_critical" => new OA\Property(property: "cpu_critical", type: "boolean", example: false),
                        "memory_critical" => new OA\Property(property: "memory_critical", type: "boolean", example: false),
                        "disk_critical" => new OA\Property(property: "disk_critical", type: "boolean", example: false),
                        "services_ok" => new OA\Property(property: "services_ok", type: "integer", example: 3),
                        "services_error" => new OA\Property(property: "services_error", type: "integer", example: 0)
                    ]
                ),
                "critical_alerts" => new OA\Property(
                    property: "critical_alerts",
                    type: "array",
                    items: new OA\Items(type: "object")
                )
            ]
        )
    )]
    public function getHealthCheck(Request $request): JsonResponse
    {
        try {
            $startTime = microtime(true);
            $healthData = $this->statsService->getHealthCheck();
            $responseTime = round((microtime(true) - $startTime) * 1000);

            $healthData['response_time'] = $responseTime;

            Log::info('SE4FS Health Check request', [
                'status' => $healthData['status'] ?? 'unknown',
                'response_time' => $responseTime,
                'services_error' => $healthData['quick_check']['services_error'] ?? 0,
                'token' => $request->attributes->get('se4fs_token'),
                'ip' => $request->ip()
            ]);

            return response()->json($healthData, 200);

        } catch (\Exception $e) {
            Log::error('SE4FS Health Check error', [
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

    #[OA\Get(
        path: "/api/v1/metrics",
        summary: "Métriques détaillées SE4FS",
        description: "Retourne les métriques système détaillées (CPU, RAM, utilisateurs). À collecter toutes les 5 minutes.",
        security: [["se4fs_auth" => []]],
        tags: ["SE4FS"]
    )]
    #[OA\Response(
        response: 200,
        description: "Métriques détaillées récupérées avec succès",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                "success" => new OA\Property(property: "success", type: "boolean", example: true),
                "timestamp" => new OA\Property(property: "timestamp", type: "string", format: "date-time"),
                "collection_interval" => new OA\Property(property: "collection_interval", type: "integer", example: 300),
                "uai" => new OA\Property(property: "uai", type: "string", example: "0751234A"),
                "status" => new OA\Property(property: "status", type: "string", example: "active"),
                "system" => new OA\Property(
                    property: "system",
                    type: "object",
                    properties: [
                        "cpu_usage" => new OA\Property(property: "cpu_usage", type: "number", example: 45.2),
                        "memory_usage" => new OA\Property(property: "memory_usage", type: "number", example: 68.1),
                        "disk_usage" => new OA\Property(
                            property: "disk_usage",
                            type: "object",
                            properties: [
                                "home" => new OA\Property(property: "home", type: "number", example: 42.8),
                                "sambaedu" => new OA\Property(property: "sambaedu", type: "number", example: 15.3)
                            ]
                        ),
                        "load_average" => new OA\Property(
                            property: "load_average",
                            type: "array",
                            items: new OA\Items(type: "number"),
                            example: [0.85, 0.92, 0.78]
                        ),
                        "uptime" => new OA\Property(property: "uptime", type: "integer", example: 86400),
                        "network_io" => new OA\Property(
                            property: "network_io",
                            type: "object",
                            properties: [
                                "bytes_in" => new OA\Property(property: "bytes_in", type: "integer", example: 1234567890),
                                "bytes_out" => new OA\Property(property: "bytes_out", type: "integer", example: 987654321)
                            ]
                        )
                    ]
                ),
                "activity" => new OA\Property(
                    property: "activity",
                    type: "object",
                    properties: [
                        "users_connected" => new OA\Property(property: "users_connected", type: "integer", example: 23),
                        "active_sessions" => new OA\Property(
                            property: "active_sessions",
                            type: "object",
                            properties: [
                                "samba" => new OA\Property(property: "samba", type: "integer", example: 23),
                                "ldap" => new OA\Property(property: "ldap", type: "integer", example: 15)
                            ]
                        ),
                        "recent_logins" => new OA\Property(property: "recent_logins", type: "integer", example: 5)
                    ]
                )
            ]
        )
    )]
    public function getMetricsData(Request $request): JsonResponse
    {
        try {
            $metricsData = $this->statsService->getMetricsData();

            Log::info('SE4FS Metrics request', [
                'cpu_usage' => $metricsData['system']['cpu_usage'] ?? 0,
                'memory_usage' => $metricsData['system']['memory_usage'] ?? 0,
                'users_connected' => $metricsData['activity']['users_connected'] ?? 0,
                'token' => $request->attributes->get('se4fs_token'),
                'ip' => $request->ip()
            ]);

            return response()->json($metricsData, 200);

        } catch (\Exception $e) {
            Log::error('SE4FS Metrics error', [
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

    #[OA\Get(
        path: "/api/v1/historical/{period}",
        summary: "Données historiques SE4FS",
        description: "Retourne les données historiques et tendances. À collecter une fois par heure. Périodes supportées: 1h, 24h, 7d, 30d",
        security: [["se4fs_auth" => []]],
        tags: ["SE4FS"]
    )]
    #[OA\Parameter(
        name: "period",
        description: "Période pour les données historiques",
        in: "path",
        required: true,
        schema: new OA\Schema(
            type: "string",
            enum: ["1h", "24h", "7d", "30d"]
        )
    )]
    #[OA\Response(
        response: 200,
        description: "Données historiques récupérées avec succès",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                "success" => new OA\Property(property: "success", type: "boolean", example: true),
                "timestamp" => new OA\Property(property: "timestamp", type: "string", format: "date-time"),
                "collection_interval" => new OA\Property(property: "collection_interval", type: "integer", example: 3600),
                "uai" => new OA\Property(property: "uai", type: "string", example: "0751234A"),
                "period" => new OA\Property(property: "period", type: "string", example: "24h"),
                "data_points" => new OA\Property(property: "data_points", type: "integer", example: 144),
                "sampling_interval" => new OA\Property(property: "sampling_interval", type: "integer", example: 600),
                "summary" => new OA\Property(
                    property: "summary",
                    type: "object",
                    properties: [
                        "cpu_avg" => new OA\Property(property: "cpu_avg", type: "number", example: 43.5),
                        "cpu_max" => new OA\Property(property: "cpu_max", type: "number", example: 67.8),
                        "memory_avg" => new OA\Property(property: "memory_avg", type: "number", example: 65.2),
                        "users_max" => new OA\Property(property: "users_max", type: "integer", example: 45)
                    ]
                )
            ]
        )
    )]
    #[OA\Response(
        response: 400,
        description: "Période invalide",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                "success" => new OA\Property(property: "success", type: "boolean", example: false),
                "message" => new OA\Property(property: "message", type: "string", example: "Invalid period. Supported: 1h, 24h, 7d, 30d"),
                "error_code" => new OA\Property(property: "error_code", type: "string", example: "INVALID_PERIOD")
            ]
        )
    )]
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

            Log::info('SE4FS Historical Data request', [
                'period' => $period,
                'data_points' => $historicalData['data_points'] ?? 0,
                'sampling_interval' => $historicalData['sampling_interval'] ?? 0,
                'token' => $request->attributes->get('se4fs_token'),
                'ip' => $request->ip()
            ]);

            return response()->json($historicalData, 200);

        } catch (\Exception $e) {
            Log::error('SE4FS Historical Data error', [
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

    #[OA\Get(
        path: "/api/v1/public/location/summary",
        summary: "Résumé de localisation SE4FS (public)",
        description: "Retourne un résumé simplifié pour découverte automatique d'instances sur le réseau. Endpoint public sans authentification.",
        tags: ["SE4FS"]
    )]
    #[OA\Response(
        response: 200,
        description: "Résumé de localisation récupéré avec succès",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                "success" => new OA\Property(property: "success", type: "boolean", example: true),
                "timestamp" => new OA\Property(property: "timestamp", type: "string", format: "date-time"),
                "instance" => new OA\Property(
                    property: "instance",
                    type: "object",
                    properties: [
                        "uai" => new OA\Property(property: "uai", type: "string", example: "0751234A"),
                        "name" => new OA\Property(property: "name", type: "string", example: "Lycée Jean Moulin"),
                        "version" => new OA\Property(property: "version", type: "string", example: "4.2.1"),
                        "status" => new OA\Property(property: "status", type: "string", example: "active"),
                        "api_endpoints" => new OA\Property(
                            property: "api_endpoints",
                            type: "object",
                            properties: [
                                "discovery" => new OA\Property(property: "discovery", type: "string", example: "/api/v1/discovery"),
                                "handshake" => new OA\Property(property: "handshake", type: "string", example: "/api/v1/handshake"),
                                "authenticated_apis" => new OA\Property(
                                    property: "authenticated_apis",
                                    type: "array",
                                    items: new OA\Items(type: "string"),
                                    example: ["/api/v1/static", "/api/v1/health", "/api/v1/metrics"]
                                )
                            ]
                        )
                    ]
                )
            ]
        )
    )]
    public function getLocationSummary(Request $request): JsonResponse
    {
        try {
            $summary = $this->statsService->getLocationSummary();

            Log::info('SE4FS Location Summary request (public)', [
                'uai' => $summary['instance']['uai'] ?? 'unknown',
                'status' => $summary['instance']['status'] ?? 'unknown',
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            return response()->json($summary, 200);

        } catch (\Exception $e) {
            Log::error('SE4FS Location Summary error', [
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