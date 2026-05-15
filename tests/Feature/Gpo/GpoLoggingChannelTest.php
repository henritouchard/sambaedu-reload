<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use Illuminate\Log\LogManager;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Test d'intégration légère : vérifie que le channel `gpo` est configuré
 * conformément à AC1.1 (driver `daily`, path sous `storage/logs/gpo/`,
 * niveau pilotable par env `GPO_LOG_LEVEL` / rétention `GPO_LOG_DAYS`).
 *
 * Pas de test d'écriture réelle sur disque ici — le test resterait fragile
 * en CI (storage/logs non rotaté, conflits d'instance). Le contrat de
 * configuration suffit pour Story 16.1.
 */
class GpoLoggingChannelTest extends TestCase
{
    #[Test]
    public function gpo_channel_is_configured_with_daily_driver_in_storage_logs(): void
    {
        $cfg = config('logging.channels.gpo');

        $this->assertIsArray($cfg, 'Le channel logging.channels.gpo doit être déclaré dans config/logging.php');
        $this->assertSame('daily', $cfg['driver'] ?? null);

        // Path attendu : storage/logs/gpo/gpo.log (rotation daily ajoute la date).
        // En environnement de test, Laravel override `storage_path()` vers
        // `storage/testing/logs/...` — on tolère donc les 2 formes.
        $this->assertIsString($cfg['path'] ?? null);
        $normalized = str_replace('\\', '/', $cfg['path']);
        $this->assertMatchesRegularExpression(
            '#storage/(?:testing/)?logs/gpo/gpo\.log$#',
            $normalized,
            "Le path doit se terminer par storage/[testing/]logs/gpo/gpo.log, reçu : {$normalized}"
        );
    }

    #[Test]
    public function gpo_channel_resolves_via_log_manager(): void
    {
        $manager = $this->app->make('log');
        $this->assertInstanceOf(LogManager::class, $manager);

        // Doit pouvoir résoudre le channel sans exception.
        $channel = $manager->channel('gpo');
        $this->assertNotNull($channel);
    }

    #[Test]
    public function gpo_channel_default_level_is_debug_during_epic_16_transition(): void
    {
        // GPO_LOG_LEVEL est attendu par defaut à `debug` (cf. AC1.1 — verbosité
        // élevée volontaire pendant la phase de transition Epic 16).
        $cfg = config('logging.channels.gpo');
        $this->assertSame('debug', $cfg['level'] ?? null);
    }

    #[Test]
    public function gpo_channel_default_retention_is_30_days(): void
    {
        $cfg = config('logging.channels.gpo');
        $this->assertSame(30, $cfg['days'] ?? null);
    }
}
