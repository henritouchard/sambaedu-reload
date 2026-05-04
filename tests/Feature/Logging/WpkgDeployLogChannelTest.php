<?php

declare(strict_types=1);

namespace Tests\Feature\Logging;

use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 15.1 / AC1.1 — vérifie la configuration du channel `wpkg-deploy`.
 */
class WpkgDeployLogChannelTest extends TestCase
{
    #[Test]
    public function channel_wpkg_deploy_is_registered(): void
    {
        $config = config('logging.channels.wpkg-deploy');

        $this->assertIsArray($config, 'channel wpkg-deploy doit être défini');
        $this->assertSame('daily', $config['driver']);
        $this->assertStringContainsString('wpkg-deploy', $config['path']);
        $this->assertSame(30, $config['days']);
    }

    #[Test]
    public function channel_logs_to_dedicated_file_with_context(): void
    {
        $config = config('logging.channels.wpkg-deploy');
        $logsDir = dirname($config['path']);

        if (! is_dir($logsDir)) {
            mkdir($logsDir, 0755, true);
        }

        // Purge des fichiers de logs résiduels avant test.
        foreach (glob($logsDir . '/deploy-*.log') ?: [] as $f) {
            @unlink($f);
        }

        Log::channel('wpkg-deploy')->withContext([
            'deployment_id' => '00000000-0000-4000-8000-000000000001',
            'workstation_id' => 42,
        ])->info('test smoke wpkg-deploy');

        // Le driver `daily` génère un fichier `deploy-{date}.log`.
        $produced = glob($logsDir . '/deploy-*.log') ?: [];
        $this->assertNotEmpty($produced, 'Le channel doit produire un fichier deploy-*.log');

        $content = file_get_contents($produced[0]);
        $this->assertStringContainsString('test smoke wpkg-deploy', $content);
        $this->assertStringContainsString('deployment_id', $content);
        $this->assertStringContainsString('workstation_id', $content);

        @unlink($produced[0]);
    }

    #[Test]
    public function log_level_is_configurable_via_env(): void
    {
        // Le niveau passe par env('WPKG_DEPLOY_LOG_LEVEL', 'info'). On valide
        // simplement qu'une valeur lue depuis la config est bien chaîne.
        $level = config('logging.channels.wpkg-deploy.level');
        $this->assertIsString($level);
        $this->assertContains($level, ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency']);
    }
}
