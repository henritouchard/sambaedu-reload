<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\StatsService;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests unitaires pour StatsService - Fonctionnalités de localisation
 * 
 * Ces tests documentent et protègent l'utilisation des méthodes existantes
 * réutilisées par les nouvelles fonctionnalités de localisation.
 */
class StatsServiceLocationTest extends TestCase
{
    private StatsService $statsService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->statsService = new StatsService();
    }

    // ===== TESTS DES MÉTHODES EXISTANTES RÉUTILISÉES =====

    /**
     * Test de getSystemStats() - Réutilisée dans getMetricsData()
     * 
     */
    #[Test]
    public function it_gets_system_stats_with_required_structure()
    {
        $stats = $this->statsService->getStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('system', $stats);
        
        $systemStats = $stats['system'];
        
        // Structure requise pour getMetricsData()
        $this->assertArrayHasKey('cpu_usage_percent', $systemStats);
        $this->assertArrayHasKey('memory_usage_percent', $systemStats);
        $this->assertArrayHasKey('load_average', $systemStats);
        $this->assertArrayHasKey('uptime_seconds', $systemStats);
        $this->assertArrayHasKey('network_io', $systemStats);
        
        // Types de données attendus
        $this->assertIsFloat($systemStats['cpu_usage_percent']);
        $this->assertIsFloat($systemStats['memory_usage_percent']);
        $this->assertIsArray($systemStats['load_average']);
        $this->assertIsInt($systemStats['uptime_seconds']);
        $this->assertIsArray($systemStats['network_io']);
        
        // Validation des valeurs
        $this->assertGreaterThanOrEqual(0, $systemStats['cpu_usage_percent']);
        $this->assertLessThanOrEqual(100, $systemStats['cpu_usage_percent']);
        $this->assertGreaterThanOrEqual(0, $systemStats['memory_usage_percent']);
        $this->assertLessThanOrEqual(100, $systemStats['memory_usage_percent']);
        $this->assertGreaterThanOrEqual(0, $systemStats['uptime_seconds']);
        
        // Structure load_average
        $this->assertCount(3, $systemStats['load_average']);
        foreach ($systemStats['load_average'] as $load) {
            $this->assertIsFloat($load);
            $this->assertGreaterThanOrEqual(0, $load);
        }
        
        // Structure network_io
        $this->assertArrayHasKey('bytes_in', $systemStats['network_io']);
        $this->assertArrayHasKey('bytes_out', $systemStats['network_io']);
        $this->assertIsInt($systemStats['network_io']['bytes_in']);
        $this->assertIsInt($systemStats['network_io']['bytes_out']);
    }

    /**
     * Test de getServiceStats() - Réutilisée dans getHealthCheck()
     * 
     */
    #[Test]
    public function it_gets_service_stats_with_required_structure()
    {
        $stats = $this->statsService->getStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('services', $stats);
        
        $serviceStats = $stats['services'];
        
        // Services requis pour getHealthCheck()
        $this->assertArrayHasKey('samba', $serviceStats);
        $this->assertArrayHasKey('ldap', $serviceStats);
        $this->assertArrayHasKey('web_server', $serviceStats);
        
        // États de service valides
        $validStates = ['running', 'stopped', 'failed', 'unknown'];
        
        $this->assertContains($serviceStats['samba'], $validStates);
        $this->assertContains($serviceStats['ldap'], $validStates);
        $this->assertContains($serviceStats['web_server'], $validStates);
    }

    /**
     * Test de getDiskUsage() - Réutilisée dans getQuickSystemCheck()
     * 
     */
    #[Test]
    public function it_gets_disk_usage_with_required_structure()
    {
        // Utilisation d'une méthode protégée via réflexion
        $reflection = new \ReflectionClass($this->statsService);
        $method = $reflection->getMethod('getDiskUsage');
        $method->setAccessible(true);
        
        $diskUsage = $method->invoke($this->statsService);
        
        $this->assertIsArray($diskUsage);
        
        // Structure requise pour getQuickSystemCheck()
        $this->assertArrayHasKey('percentage', $diskUsage);
        $this->assertArrayHasKey('total_gb', $diskUsage);
        $this->assertArrayHasKey('used_gb', $diskUsage);
        $this->assertArrayHasKey('available_gb', $diskUsage);
        
        // Types et valeurs
        $this->assertIsFloat($diskUsage['percentage']);
        $this->assertIsFloat($diskUsage['total_gb']);
        $this->assertIsFloat($diskUsage['used_gb']);
        $this->assertIsFloat($diskUsage['available_gb']);
        
        $this->assertGreaterThanOrEqual(0, $diskUsage['percentage']);
        $this->assertLessThanOrEqual(100, $diskUsage['percentage']);
        $this->assertGreaterThan(0, $diskUsage['total_gb']);
        $this->assertGreaterThanOrEqual(0, $diskUsage['used_gb']);
        $this->assertGreaterThanOrEqual(0, $diskUsage['available_gb']);
    }

    /**
     * Test de getMemoryUsage() - Réutilisée dans getQuickSystemCheck()
     * 
     */
    #[Test]
    public function it_gets_memory_usage_with_required_structure()
    {
        // Utilisation d'une méthode protégée via réflexion
        $reflection = new \ReflectionClass($this->statsService);
        $method = $reflection->getMethod('getMemoryUsage');
        $method->setAccessible(true);
        
        $memoryUsage = $method->invoke($this->statsService);
        
        $this->assertIsArray($memoryUsage);
        
        // Structure requise pour getQuickSystemCheck()
        $this->assertArrayHasKey('percentage', $memoryUsage);
        $this->assertIsFloat($memoryUsage['percentage']);
        $this->assertGreaterThanOrEqual(0, $memoryUsage['percentage']);
        $this->assertLessThanOrEqual(100, $memoryUsage['percentage']);
    }

    /**
     * Test de getCpuUsage() - Réutilisée dans getQuickSystemCheck()
     * 
     */
    #[Test]
    public function it_gets_cpu_usage_with_required_type()
    {
        // Utilisation d'une méthode protégée via réflexion
        $reflection = new \ReflectionClass($this->statsService);
        $method = $reflection->getMethod('getCpuUsage');
        $method->setAccessible(true);
        
        $cpuUsage = $method->invoke($this->statsService);
        
        $this->assertIsFloat($cpuUsage);
        $this->assertGreaterThanOrEqual(0, $cpuUsage);
        $this->assertLessThanOrEqual(100, $cpuUsage);
    }

    // ===== TESTS DES NOUVELLES MÉTHODES DE LOCALISATION =====

    /**
     * Test de getStaticData() - Collecte 1x
     * 
     */
    #[Test]
    public function it_gets_static_data_with_correct_structure()
    {
        // Configuration de test
        Config::set('se4fs.establishment.uai', '0751234A');
        Config::set('se4fs.establishment.name', 'Test Lycée');
        Config::set('se4fs.establishment.coordinates.lat', 48.8566);
        Config::set('se4fs.establishment.coordinates.lng', 2.3522);
        
        $staticData = $this->statsService->getStaticData();
        
        $this->assertIsArray($staticData);
        $this->assertTrue($staticData['success']);
        $this->assertArrayHasKey('timestamp', $staticData);
        $this->assertNull($staticData['collection_interval']);
        $this->assertEquals('Collect only once or when changed', $staticData['note']);
        
        // Structure instance
        $this->assertArrayHasKey('instance', $staticData);
        $instance = $staticData['instance'];
        $this->assertEquals('0751234A', $instance['uai']);
        $this->assertEquals('Test Lycée', $instance['name']);
        $this->assertArrayHasKey('coordinates', $instance);
        $this->assertEquals(48.8566, $instance['coordinates']['latitude']);
        $this->assertEquals(2.3522, $instance['coordinates']['longitude']);
        
        // Structure establishment
        $this->assertArrayHasKey('establishment', $staticData);
        $establishment = $staticData['establishment'];
        $this->assertArrayHasKey('stats', $establishment);
        $this->assertArrayHasKey('total_users', $establishment['stats']);
        $this->assertArrayHasKey('total_computers', $establishment['stats']);
        $this->assertArrayHasKey('total_classes', $establishment['stats']);
        
        // Structure network
        $this->assertArrayHasKey('network', $staticData);
        $network = $staticData['network'];
        $this->assertArrayHasKey('ip_addresses', $network);
        $this->assertArrayHasKey('se4fs', $network['ip_addresses']);
        $this->assertArrayHasKey('se4ad', $network['ip_addresses']);
    }

    /**
     * Test de getHealthCheck() - Collecte 30s
     * 
     */
    #[Test]
    public function it_gets_health_check_with_correct_structure()
    {
        Config::set('se4fs.establishment.uai', '0751234A');
        
        $healthData = $this->statsService->getHealthCheck();
        
        $this->assertIsArray($healthData);
        $this->assertTrue($healthData['success']);
        $this->assertArrayHasKey('timestamp', $healthData);
        $this->assertEquals(30, $healthData['collection_interval']);
        $this->assertEquals('0751234A', $healthData['uai']);
        $this->assertContains($healthData['status'], ['active', 'warning']);
        
        // Structure services
        $this->assertArrayHasKey('services', $healthData);
        $services = $healthData['services'];
        $this->assertArrayHasKey('samba', $services);
        $this->assertArrayHasKey('ldap', $services);
        $this->assertArrayHasKey('apache', $services);
        
        // Structure quick_check
        $this->assertArrayHasKey('quick_check', $healthData);
        $quickCheck = $healthData['quick_check'];
        $this->assertArrayHasKey('cpu_critical', $quickCheck);
        $this->assertArrayHasKey('memory_critical', $quickCheck);
        $this->assertArrayHasKey('disk_critical', $quickCheck);
        $this->assertArrayHasKey('services_ok', $quickCheck);
        $this->assertArrayHasKey('services_error', $quickCheck);
        
        $this->assertIsBool($quickCheck['cpu_critical']);
        $this->assertIsBool($quickCheck['memory_critical']);
        $this->assertIsBool($quickCheck['disk_critical']);
        $this->assertIsInt($quickCheck['services_ok']);
        $this->assertIsInt($quickCheck['services_error']);
        
        // Structure critical_alerts
        $this->assertArrayHasKey('critical_alerts', $healthData);
        $this->assertIsArray($healthData['critical_alerts']);
    }

    /**
     * Test de getMetricsData() - Collecte 5min
     * 
     */
    #[Test]
    public function it_gets_metrics_data_with_correct_structure()
    {
        Config::set('se4fs.establishment.uai', '0751234A');
        
        $metricsData = $this->statsService->getMetricsData();
        
        $this->assertIsArray($metricsData);
        $this->assertTrue($metricsData['success']);
        $this->assertArrayHasKey('timestamp', $metricsData);
        $this->assertEquals(300, $metricsData['collection_interval']);
        $this->assertEquals('0751234A', $metricsData['uai']);
        $this->assertEquals('active', $metricsData['status']);
        
        // Structure system
        $this->assertArrayHasKey('system', $metricsData);
        $system = $metricsData['system'];
        $this->assertArrayHasKey('cpu_usage', $system);
        $this->assertArrayHasKey('memory_usage', $system);
        $this->assertArrayHasKey('disk_usage', $system);
        $this->assertArrayHasKey('load_average', $system);
        $this->assertArrayHasKey('uptime', $system);
        $this->assertArrayHasKey('network_io', $system);
        
        // Validation disk_usage
        $this->assertArrayHasKey('home', $system['disk_usage']);
        $this->assertArrayHasKey('sambaedu', $system['disk_usage']);
        $this->assertIsFloat($system['disk_usage']['home']);
        $this->assertIsFloat($system['disk_usage']['sambaedu']);
        
        // Structure activity
        $this->assertArrayHasKey('activity', $metricsData);
        $activity = $metricsData['activity'];
        $this->assertArrayHasKey('users_connected', $activity);
        $this->assertArrayHasKey('active_sessions', $activity);
        $this->assertArrayHasKey('recent_logins', $activity);
        
        $this->assertIsInt($activity['users_connected']);
        $this->assertIsInt($activity['recent_logins']);
        $this->assertIsArray($activity['active_sessions']);
        $this->assertArrayHasKey('samba', $activity['active_sessions']);
        $this->assertArrayHasKey('ldap', $activity['active_sessions']);
    }

    /**
     * Test de getHistoricalData() - Collecte 1h
     * 
     */
    #[Test]
    public function it_gets_historical_data_with_correct_structure()
    {
        Config::set('se4fs.establishment.uai', '0751234A');
        
        $periods = ['1h', '24h', '7d', '30d'];
        
        foreach ($periods as $period) {
            $historicalData = $this->statsService->getHistoricalData($period);
            
            $this->assertIsArray($historicalData);
            $this->assertTrue($historicalData['success']);
            $this->assertArrayHasKey('timestamp', $historicalData);
            $this->assertEquals(3600, $historicalData['collection_interval']);
            $this->assertEquals('0751234A', $historicalData['uai']);
            $this->assertEquals($period, $historicalData['period']);
            
            // Structure de données
            $this->assertArrayHasKey('data_points', $historicalData);
            $this->assertArrayHasKey('sampling_interval', $historicalData);
            $this->assertIsInt($historicalData['data_points']);
            $this->assertIsInt($historicalData['sampling_interval']);
            $this->assertGreaterThan(0, $historicalData['data_points']);
            $this->assertGreaterThan(0, $historicalData['sampling_interval']);
            
            // Structure metrics
            $this->assertArrayHasKey('metrics', $historicalData);
            $metrics = $historicalData['metrics'];
            $this->assertArrayHasKey('cpu_usage', $metrics);
            $this->assertArrayHasKey('memory_usage', $metrics);
            $this->assertArrayHasKey('users_connected', $metrics);
            $this->assertArrayHasKey('disk_usage', $metrics);
            
            // Validation des points de données
            foreach ($metrics as $metricName => $metricData) {
                $this->assertIsArray($metricData);
                $this->assertCount($historicalData['data_points'], $metricData);
                
                foreach ($metricData as $dataPoint) {
                    $this->assertArrayHasKey('timestamp', $dataPoint);
                    $this->assertArrayHasKey('value', $dataPoint);
                    $this->assertIsString($dataPoint['timestamp']);
                    $this->assertIsNumeric($dataPoint['value']);
                }
            }
            
            // Structure summary
            $this->assertArrayHasKey('summary', $historicalData);
            $summary = $historicalData['summary'];
            $this->assertArrayHasKey('cpu_avg', $summary);
            $this->assertArrayHasKey('cpu_max', $summary);
            $this->assertArrayHasKey('memory_avg', $summary);
            $this->assertArrayHasKey('users_max', $summary);
            
            foreach ($summary as $key => $value) {
                $this->assertIsNumeric($value);
            }
        }
    }

    /**
     * Test de getLocationSummary() - Découverte publique
     * 
     */
    #[Test]
    public function it_gets_location_summary_with_correct_structure()
    {
        Config::set('se4fs.establishment.uai', '0751234A');
        Config::set('se4fs.establishment.name', 'Test Lycée');
        
        $summary = $this->statsService->getLocationSummary();
        
        $this->assertIsArray($summary);
        $this->assertTrue($summary['success']);
        $this->assertArrayHasKey('timestamp', $summary);
        
        // Structure instance
        $this->assertArrayHasKey('instance', $summary);
        $instance = $summary['instance'];
        $this->assertEquals('0751234A', $instance['uai']);
        $this->assertEquals('Test Lycée', $instance['name']);
        $this->assertArrayHasKey('version', $instance);
        $this->assertContains($instance['status'], ['active', 'degraded']);
        
        // Structure api_endpoints
        $this->assertArrayHasKey('api_endpoints', $instance);
        $endpoints = $instance['api_endpoints'];
        $this->assertArrayHasKey('discovery', $endpoints);
        $this->assertArrayHasKey('handshake', $endpoints);
        $this->assertArrayHasKey('authenticated_apis', $endpoints);
        $this->assertIsArray($endpoints['authenticated_apis']);
        
        // Validation des endpoints
        $expectedEndpoints = [
            '/api/v1/static',
            '/api/v1/health',
            '/api/v1/metrics',
            '/api/v1/historical/{period}'
        ];
        
        foreach ($expectedEndpoints as $expectedEndpoint) {
            $this->assertContains($expectedEndpoint, $endpoints['authenticated_apis']);
        }
    }

    // ===== TESTS DE RÉGRESSION POUR PROTECTION =====

    /**
     * Test de régression : getStats() doit continuer à fonctionner
     * 
     */
    #[Test]
    public function it_maintains_backward_compatibility_for_existing_get_stats()
    {
        $stats = $this->statsService->getStats();
        
        // Structure existante préservée
        $this->assertIsArray($stats);
        $this->assertTrue($stats['success']);
        $this->assertArrayHasKey('timestamp', $stats);
        $this->assertArrayHasKey('system', $stats);
        $this->assertArrayHasKey('users', $stats);
        $this->assertArrayHasKey('files', $stats);
        $this->assertArrayHasKey('services', $stats);
        
        // Les types doivent rester identiques
        $this->assertIsArray($stats['system']);
        $this->assertIsArray($stats['users']);
        $this->assertIsArray($stats['files']);
        $this->assertIsArray($stats['services']);
    }

    /**
     * Test de cohérence : Les nouvelles méthodes utilisent les mêmes sources
     * 
     */
    #[Test]
    public function it_maintains_consistency_between_old_and_new_methods()
    {
        $originalStats = $this->statsService->getStats();
        $metricsData = $this->statsService->getMetricsData();
        $healthData = $this->statsService->getHealthCheck();
        
        // CPU usage doit être cohérent (tolérance de 1% pour les variations)
        $this->assertEqualsWithDelta(
            $originalStats['system']['cpu_usage_percent'],
            $metricsData['system']['cpu_usage'],
            1.0
        );
        
        // Memory usage doit être cohérent (tolérance de 1% pour les variations)
        $this->assertEqualsWithDelta(
            $originalStats['system']['memory_usage_percent'],
            $metricsData['system']['memory_usage'],
            1.0
        );
        
        // Services doivent être cohérents
        $this->assertEquals(
            $originalStats['services']['samba'],
            $healthData['services']['samba']
        );
        
        $this->assertEquals(
            $originalStats['services']['ldap'],
            $healthData['services']['ldap']
        );
        
        $this->assertEquals(
            $originalStats['services']['web_server'],
            $healthData['services']['apache']
        );
    }

    // ===== TESTS DE VALIDATION DES CONFIGURATIONS =====

    /**
     * Test de validation : Configuration par défaut
     * 
     */
    #[Test]
    public function it_handles_missing_configuration_gracefully()
    {
        // Effacer toute configuration existante
        Config::set('se4fs', []);
        
        $staticData = $this->statsService->getStaticData();
        $healthData = $this->statsService->getHealthCheck();
        $metricsData = $this->statsService->getMetricsData();
        $summary = $this->statsService->getLocationSummary();
        
        // Doit fonctionner avec des valeurs par défaut
        $this->assertTrue($staticData['success']);
        $this->assertTrue($healthData['success']);
        $this->assertTrue($metricsData['success']);
        $this->assertTrue($summary['success']);
        
        // Valeurs par défaut attendues
        $this->assertEquals('0000000X', $staticData['instance']['uai']);
        $this->assertEquals('0000000X', $healthData['uai']);
        $this->assertEquals('0000000X', $metricsData['uai']);
        $this->assertEquals('0000000X', $summary['instance']['uai']);
    }

    /**
     * Test de performance : Les nouvelles méthodes ne doivent pas être trop lentes
     * 
     */
    #[Test]
    public function it_performs_within_acceptable_limits()
    {
        $startTime = microtime(true);
        
        // Exécuter toutes les nouvelles méthodes
        $this->statsService->getStaticData();
        $this->statsService->getHealthCheck();
        $this->statsService->getMetricsData();
        $this->statsService->getHistoricalData('24h');
        $this->statsService->getLocationSummary();
        
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        
        // Toutes les méthodes doivent s'exécuter en moins de 5 secondes
        $this->assertLessThan(5, $executionTime, 'Les nouvelles méthodes prennent trop de temps');
    }
} 