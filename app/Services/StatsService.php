<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * Service de statistiques SE4FS
 * Collecte les métriques système et utilisateurs
 */
class StatsService
{
    /**
     * Récupère toutes les statistiques système
     */
    public function getStats(): array
    {
        return [
            'success' => true,
            'timestamp' => now()->toISOString(),
            'system' => $this->getSystemStats(),
            'users' => $this->getUserStats(),
            'files' => $this->getFileStats(),
            'services' => $this->getServiceStats()
        ];
    }
    
    /**
     * Statistiques système
     */
    private function getSystemStats(): array
    {
        $loadAverage = $this->getLoadAverage();
        $diskUsage = $this->getDiskUsage();
        $memoryUsage = $this->getMemoryUsage();
        
        return [
            'hostname' => gethostname(),
            'uptime_seconds' => $this->getUptime(),
            'cpu_usage_percent' => $this->getCpuUsage(),
            'memory_usage_percent' => $memoryUsage['percentage'],
            'load_average' => $loadAverage,
            'disk_usage' => $diskUsage,
            'network_io' => $this->getNetworkIO()
        ];
    }
    
    /**
     * Statistiques utilisateurs
     */
    private function getUserStats(): array
    {
        // TODO: Implémenter avec de vraies données LDAP
        return [
            'total' => 1250,
            'active_today' => $this->getUsersActiveToday(),
            'active_this_week' => $this->getUsersActiveThisWeek(),
            'new_this_month' => $this->getNewUsersThisMonth()
        ];
    }
    
    /**
     * Statistiques des fichiers
     */
    private function getFileStats(): array
    {
        // TODO: Implémenter avec de vraies données de fichiers
        return [
            'total_files' => 45678,
            'total_size_gb' => 856.4,
            'uploads_today' => $this->getUploadsToday(),
            'downloads_today' => $this->getDownloadsToday(),
            'shares_today' => $this->getSharesToday()
        ];
    }
    
    /**
     * État des services
     */
    private function getServiceStats(): array
    {
        return [
            'samba' => $this->checkServiceStatus('smbd'),
            'ldap' => $this->checkServiceStatus('slapd'),
            'web_server' => $this->checkServiceStatus('apache2'),
            'backup_service' => $this->checkServiceStatus('rsync')
        ];
    }
    
    /**
     * Usage disque
     */
    private function getDiskUsage(): array
    {
        $path = '/';
        $totalBytes = disk_total_space($path);
        $freeBytes = disk_free_space($path);
        $usedBytes = $totalBytes - $freeBytes;
        
        return [
            'total_gb' => round($totalBytes / (1024 ** 3), 2),
            'used_gb' => round($usedBytes / (1024 ** 3), 2),
            'available_gb' => round($freeBytes / (1024 ** 3), 2),
            'percentage' => round(($usedBytes / $totalBytes) * 100, 1)
        ];
    }
    
    /**
     * Usage mémoire
     */
    private function getMemoryUsage(): array
    {
        if (!file_exists('/proc/meminfo')) {
            return ['percentage' => 0.0];
        }
        
        $meminfo = file_get_contents('/proc/meminfo');
        preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $totalMatches);
        preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $availableMatches);
        
        if (!$totalMatches || !$availableMatches) {
            return ['percentage' => 0.0];
        }
        
        $total = (int) $totalMatches[1];
        $available = (int) $availableMatches[1];
        $used = $total - $available;
        
        return [
            'percentage' => (float) round(($used / $total) * 100, 1)
        ];
    }
    
    /**
     * Usage CPU
     */
    private function getCpuUsage(): float
    {
        // Méthode simplifiée - en production utiliser une méthode plus précise
        if (!file_exists('/proc/loadavg')) {
            return 0.0;
        }
        
        $loadavg = file_get_contents('/proc/loadavg');
        $load1min = (float) explode(' ', trim($loadavg))[0];
        
        // Estimer le pourcentage CPU basé sur la charge (approximatif)
        $cpuPercent = $load1min * 100.0;
        return $cpuPercent > 100.0 ? 100.0 : $cpuPercent;
    }
    
    /**
     * Charge système
     */
    private function getLoadAverage(): array
    {
        if (!file_exists('/proc/loadavg')) {
            return [0.0, 0.0, 0.0];
        }
        
        $loadavg = file_get_contents('/proc/loadavg');
        $parts = explode(' ', trim($loadavg));
        
        return [
            (float) ($parts[0] ?? 0),
            (float) ($parts[1] ?? 0),
            (float) ($parts[2] ?? 0)
        ];
    }
    
    /**
     * Uptime du système
     */
    private function getUptime(): int
    {
        if (!file_exists('/proc/uptime')) {
            return 0;
        }
        
        $uptime = file_get_contents('/proc/uptime');
        return (int) floatval(explode(' ', $uptime)[0]);
    }
    
    /**
     * I/O réseau
     */
    private function getNetworkIO(): array
    {
        // TODO: Implémenter la lecture des stats réseau
        return [
            'bytes_in' => 1234567890,
            'bytes_out' => 987654321
        ];
    }
    
    /**
     * Vérifie l'état d'un service
     */
    private function checkServiceStatus(string $service): string
    {
        // Vérifie via systemctl si disponible
        $output = null;
        $returnCode = null;
        exec("systemctl is-active {$service} 2>/dev/null", $output, $returnCode);
        
        return $returnCode === 0 ? 'running' : 'stopped';
    }
    
    /**
     * Utilisateurs actifs aujourd'hui
     */
    private function getUsersActiveToday(): int
    {
        // TODO: Implémenter avec de vraies données
        return rand(80, 120);
    }
    
    /**
     * Utilisateurs actifs cette semaine
     */
    private function getUsersActiveThisWeek(): int
    {
        // TODO: Implémenter avec de vraies données
        return rand(400, 600);
    }
    
    /**
     * Nouveaux utilisateurs ce mois
     */
    private function getNewUsersThisMonth(): int
    {
        // TODO: Implémenter avec de vraies données
        return rand(15, 35);
    }
    
    /**
     * Uploads aujourd'hui
     */
    private function getUploadsToday(): int
    {
        // TODO: Implémenter avec de vraies données
        return rand(15, 35);
    }
    
    /**
     * Téléchargements aujourd'hui
     */
    private function getDownloadsToday(): int
    {
        // TODO: Implémenter avec de vraies données
        return rand(100, 200);
    }
    
    /**
     * Partages aujourd'hui
     */
    private function getSharesToday(): int
    {
        // TODO: Implémenter avec de vraies données
        return rand(5, 20);
    }

    // ===== NOUVELLES MÉTHODES POUR COLLECTE DIFFÉRENCIÉE =====

    /**
     * Données statiques de l'instance SE4FS
     * À collecter une seule fois ou lors de changements
     */
    public function getStaticData(): array
    {
        return [
            'success' => true,
            'timestamp' => now()->toISOString(),
            'collection_interval' => null,
            'note' => 'Collect only once or when changed',
            'instance' => [
                'uai' => config('se4fs.establishment.uai', '0000000X'),
                'name' => config('se4fs.establishment.name', 'SE4FS Instance'),
                'coordinates' => [
                    'latitude' => (float) config('se4fs.establishment.coordinates.lat', 0.0),
                    'longitude' => (float) config('se4fs.establishment.coordinates.lng', 0.0)
                ],
                'version' => $this->getSE4FSVersion(),
                'install_date' => config('se4fs.establishment.install_date', '2024-01-01'),
                'last_update' => config('se4fs.establishment.last_update', now()->format('Y-m-d'))
            ],
            'establishment' => [
                'type' => config('se4fs.establishment.type', 'lycee'),
                'academie' => config('se4fs.establishment.academie', 'Unknown'),
                'address' => [
                    'street' => config('se4fs.establishment.address.street', ''),
                    'city' => config('se4fs.establishment.address.city', ''),
                    'postal_code' => config('se4fs.establishment.address.postal_code', '')
                ],
                'contact' => [
                    'phone' => config('se4fs.establishment.contact.phone', ''),
                    'email' => config('se4fs.establishment.contact.email', '')
                ],
                'stats' => [
                    'total_users' => $this->getTotalUsers(),
                    'total_computers' => $this->getTotalComputers(),
                    'total_classes' => $this->getTotalClasses()
                ]
            ],
            'network' => [
                'ip_addresses' => [
                    'se4fs' => $this->getSE4FSIpAddress(),
                    'se4ad' => $this->getSE4ADIpAddress()
                ],
                'domain' => config('se4fs.establishment.domain', gethostname())
            ]
        ];
    }

    /**
     * Contrôle de santé rapide SE4FS
     * À collecter toutes les 30 secondes
     */
    public function getHealthCheck(): array
    {
        $services = $this->getServiceStats();
        $quickCheck = $this->getQuickSystemCheck();
        $criticalAlerts = $this->getCriticalAlerts($services, $quickCheck);

        return [
            'success' => true,
            'timestamp' => now()->toISOString(),
            'collection_interval' => 30,
            'uai' => config('se4fs.establishment.uai', '0000000X'),
            'status' => empty($criticalAlerts) ? 'active' : 'warning',
            'services' => [
                'samba' => $services['samba'],
                'ldap' => $services['ldap'],
                'apache' => $services['web_server']
            ],
            'quick_check' => $quickCheck,
            'critical_alerts' => $criticalAlerts
        ];
    }

    /**
     * Métriques détaillées SE4FS
     * À collecter toutes les 5 minutes
     */
    public function getMetricsData(): array
    {
        $systemStats = $this->getSystemStats();
        
        return [
            'success' => true,
            'timestamp' => now()->toISOString(),
            'collection_interval' => 300,
            'uai' => config('se4fs.establishment.uai', '0000000X'),
            'status' => 'active',
            'system' => [
                'cpu_usage' => $systemStats['cpu_usage_percent'],
                'memory_usage' => $systemStats['memory_usage_percent'],
                'disk_usage' => [
                    'home' => $this->getDiskUsageForPath('/home'),
                    'sambaedu' => $this->getDiskUsageForPath('/sambaedu')
                ],
                'load_average' => $systemStats['load_average'],
                'uptime' => $systemStats['uptime_seconds'],
                'network_io' => $systemStats['network_io']
            ],
            'activity' => [
                'users_connected' => $this->getConnectedUsers(),
                'active_sessions' => [
                    'samba' => $this->getSambaSessions(),
                    'ldap' => $this->getLdapConnections()
                ],
                'recent_logins' => $this->getRecentLogins()
            ]
        ];
    }

    /**
     * Données historiques SE4FS
     * À collecter une fois par heure
     */
    public function getHistoricalData(string $period): array
    {
        $periodConfig = $this->getHistoricalPeriodConfig($period);
        
        return [
            'success' => true,
            'timestamp' => now()->toISOString(),
            'collection_interval' => 3600,
            'uai' => config('se4fs.establishment.uai', '0000000X'),
            'period' => $period,
            'data_points' => $periodConfig['data_points'],
            'sampling_interval' => $periodConfig['sampling_interval'],
            'metrics' => [
                'cpu_usage' => $this->getHistoricalMetric('cpu_usage', $period),
                'memory_usage' => $this->getHistoricalMetric('memory_usage', $period),
                'users_connected' => $this->getHistoricalMetric('users_connected', $period),
                'disk_usage' => $this->getHistoricalMetric('disk_usage', $period)
            ],
            'summary' => [
                'cpu_avg' => $this->getMetricAverage('cpu_usage', $period),
                'cpu_max' => $this->getMetricMax('cpu_usage', $period),
                'memory_avg' => $this->getMetricAverage('memory_usage', $period),
                'users_max' => $this->getMetricMax('users_connected', $period)
            ]
        ];
    }

    /**
     * Résumé de localisation pour découverte automatique (public)
     */
    public function getLocationSummary(): array
    {
        $services = $this->getServiceStats();
        $isHealthy = $services['samba'] === 'running' && 
                    $services['ldap'] === 'running' && 
                    $services['web_server'] === 'running';

        return [
            'success' => true,
            'timestamp' => now()->toISOString(),
            'instance' => [
                'uai' => config('se4fs.establishment.uai', '0000000X'),
                'name' => config('se4fs.establishment.name', 'SE4FS Instance'),
                'version' => $this->getSE4FSVersion(),
                'status' => $isHealthy ? 'active' : 'degraded',
                'api_endpoints' => [
                    'discovery' => '/api/v1/discovery',
                    'handshake' => '/api/v1/handshake',
                    'authenticated_apis' => [
                        '/api/v1/static',
                        '/api/v1/health',
                        '/api/v1/metrics',
                        '/api/v1/historical/{period}'
                    ]
                ]
            ]
        ];
    }

    // ===== MÉTHODES PRIVÉES AUXILIAIRES =====

    /**
     * Version SE4FS
     */
    private function getSE4FSVersion(): string
    {
        // TODO: Récupérer la vraie version depuis le système
        return config('app.version', '4.2.1');
    }

    /**
     * Contrôle rapide du système pour alertes critiques
     */
    private function getQuickSystemCheck(): array
    {
        $cpuUsage = $this->getCpuUsage();
        $memoryUsage = $this->getMemoryUsage();
        $diskUsage = $this->getDiskUsage();
        $services = $this->getServiceStats();

        $servicesRunning = array_filter($services, fn($status) => $status === 'running');

        return [
            'cpu_critical' => $cpuUsage > 90,
            'memory_critical' => $memoryUsage['percentage'] > 90,
            'disk_critical' => $diskUsage['percentage'] > 90,
            'services_ok' => count($servicesRunning),
            'services_error' => count($services) - count($servicesRunning)
        ];
    }

    /**
     * Alertes critiques
     */
    private function getCriticalAlerts(array $services, array $quickCheck): array
    {
        $alerts = [];

        if ($quickCheck['cpu_critical']) {
            $alerts[] = [
                'type' => 'cpu_critical',
                'message' => 'CPU usage above 90%',
                'since' => now()->toISOString()
            ];
        }

        if ($quickCheck['memory_critical']) {
            $alerts[] = [
                'type' => 'memory_critical',
                'message' => 'Memory usage above 90%',
                'since' => now()->toISOString()
            ];
        }

        if ($quickCheck['disk_critical']) {
            $alerts[] = [
                'type' => 'disk_critical',
                'message' => 'Disk usage above 90%',
                'since' => now()->toISOString()
            ];
        }

        foreach ($services as $serviceName => $status) {
            if ($status !== 'running') {
                $alerts[] = [
                    'type' => 'service_down',
                    'service' => $serviceName,
                    'since' => now()->toISOString()
                ];
            }
        }

        return $alerts;
    }

    /**
     * Usage disque pour un chemin spécifique
     */
    private function getDiskUsageForPath(string $path): float
    {
        if (!is_dir($path)) {
            return 0.0;
        }

        $totalBytes = disk_total_space($path);
        $freeBytes = disk_free_space($path);
        
        if ($totalBytes === false || $freeBytes === false) {
            return 0.0;
        }

        $usedBytes = $totalBytes - $freeBytes;
        return (float) round(($usedBytes / $totalBytes) * 100, 1);
    }

    /**
     * Utilisateurs connectés actuellement
     */
    private function getConnectedUsers(): int
    {
        // TODO: Implémenter via who/w ou smbstatus
        return rand(15, 35);
    }

    /**
     * Sessions Samba actives
     */
    private function getSambaSessions(): int
    {
        // TODO: Implémenter via smbstatus
        return rand(15, 30);
    }

    /**
     * Connexions LDAP actives
     */
    private function getLdapConnections(): int
    {
        // TODO: Implémenter via logs LDAP
        return rand(10, 25);
    }

    /**
     * Connexions récentes (dernière heure)
     */
    private function getRecentLogins(): int
    {
        // TODO: Implémenter via last/wtmp
        return rand(3, 10);
    }

    /**
     * Configuration pour les périodes historiques
     */
    private function getHistoricalPeriodConfig(string $period): array
    {
        $configs = [
            '1h' => ['data_points' => 12, 'sampling_interval' => 300],   // 5min
            '24h' => ['data_points' => 144, 'sampling_interval' => 600], // 10min
            '7d' => ['data_points' => 168, 'sampling_interval' => 3600], // 1h
            '30d' => ['data_points' => 180, 'sampling_interval' => 14400] // 4h
        ];

        return $configs[$period] ?? $configs['24h'];
    }

    /**
     * Données historiques pour une métrique
     */
    private function getHistoricalMetric(string $metric, string $period): array
    {
        // TODO: Implémenter avec InfluxDB
        $config = $this->getHistoricalPeriodConfig($period);
        $dataPoints = [];
        
        $baseValue = match($metric) {
            'cpu_usage' => 45,
            'memory_usage' => 65,
            'users_connected' => 20,
            'disk_usage' => 40,
            default => 50
        };

        $now = now();
        for ($i = 0; $i < $config['data_points']; $i++) {
            $timestamp = $now->copy()->subSeconds($i * $config['sampling_interval']);
            $value = $baseValue + rand(-10, 10);
            $dataPoints[] = [
                'timestamp' => $timestamp->toISOString(),
                'value' => max(0, min(100, $value))
            ];
        }

        return array_reverse($dataPoints);
    }

    /**
     * Moyenne d'une métrique sur une période
     */
    private function getMetricAverage(string $metric, string $period): float
    {
        // TODO: Implémenter avec InfluxDB
        return match($metric) {
            'cpu_usage' => 43.5,
            'memory_usage' => 65.2,
            'users_connected' => 22.5,
            'disk_usage' => 38.7,
            default => 50.0
        };
    }

    /**
     * Maximum d'une métrique sur une période
     */
    private function getMetricMax(string $metric, string $period): float
    {
        // TODO: Implémenter avec InfluxDB
        return match($metric) {
            'cpu_usage' => 67.8,
            'memory_usage' => 82.1,
            'users_connected' => 45,
            'disk_usage' => 52.3,
            default => 75.0
        };
    }

    /**
     * Nombre total d'utilisateurs
     */
    private function getTotalUsers(): int
    {
        // TODO: Implémenter avec LDAP
        return 1250;
    }

    /**
     * Nombre total d'ordinateurs
     */
    private function getTotalComputers(): int
    {
        // TODO: Implémenter avec inventory
        return 150;
    }

    /**
     * Nombre total de classes
     */
    private function getTotalClasses(): int
    {
        // TODO: Implémenter avec LDAP OU groups
        return 45;
    }

    /**
     * Adresse IP SE4FS
     */
    private function getSE4FSIpAddress(): string
    {
        // TODO: Déterminer l'IP principale
        return $_SERVER['SERVER_ADDR'] ?? '192.168.1.10';
    }

    /**
     * Adresse IP SE4AD
     */
    private function getSE4ADIpAddress(): string
    {
        // TODO: Récupérer depuis config ou DNS
        return config('se4fs.se4ad.ip', '192.168.1.11');
    }
} 