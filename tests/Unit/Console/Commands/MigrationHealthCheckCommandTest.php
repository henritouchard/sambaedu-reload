<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands;

use App\Auth\V1\Models\WorkstationMigrationAttempt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

/**
 * Story 16.11 — AC7.1 / T7.3.
 *
 * Tests commande `migration:health-check`.
 */
class MigrationHealthCheckCommandTest extends TestCase
{
    use IssuesWorkstationJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureAuthV1Tables();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function table_vide_retourne_ok_sans_log_critical(): void
    {
        // Aucun row → ratio 0 conceptuel → exit 0 + output OK + pas de critical.
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('critical')->never();

        $this->artisan('migration:health-check')
            ->expectsOutputToContain('[OK] No attempts')
            ->assertExitCode(0);
    }

    #[Test]
    public function ratio_sous_seuil_retourne_ok_sans_log_critical(): void
    {
        // 10 attempts, 0 failed → ratio 0% sous seuil 5%.
        WorkstationMigrationAttempt::factory()->succeeded()->count(10)->create();

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('critical')->never();
        // Les warnings/info éventuels ne nous intéressent pas — on les accept.
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('warning')->andReturnNull();

        $this->artisan('migration:health-check')
            ->expectsOutputToContain('[OK] Failure ratio')
            ->assertExitCode(0);
    }

    #[Test]
    public function ratio_au_dessus_du_seuil_loggue_critical(): void
    {
        // 100 attempts, 10 failed → ratio 10% > seuil 5%.
        WorkstationMigrationAttempt::factory()->succeeded()->count(90)->create();
        WorkstationMigrationAttempt::factory()->failed()->count(10)->create();

        Log::shouldReceive('channel')
            ->with('auth-v1')
            ->andReturnSelf();
        Log::shouldReceive('critical')
            ->once()
            ->with('auth.migration.health.alert', Mockery::on(function (array $ctx): bool {
                return $ctx['action_type'] === 'auth.migration.health.alert'
                    && $ctx['total'] === 100
                    && $ctx['failed'] === 10
                    && abs($ctx['ratio'] - 0.10) < 0.001
                    && abs($ctx['threshold'] - 0.05) < 0.001;
            }));

        $this->artisan('migration:health-check')
            ->expectsOutputToContain('[CRITICAL] Failure ratio')
            ->assertExitCode(0);
    }

    #[Test]
    public function override_days_et_threshold_fonctionne(): void
    {
        // 5 attempts récents (3j), 1 failed → ratio 20% > 10% threshold.
        WorkstationMigrationAttempt::factory()->succeeded()->count(4)->create([
            'started_at' => Carbon::now()->subDays(3),
        ]);
        WorkstationMigrationAttempt::factory()->failed()->create([
            'started_at' => Carbon::now()->subDays(2),
        ]);

        // 1 attempt en dehors de la fenêtre 5j (ne doit pas compter).
        WorkstationMigrationAttempt::factory()->failed()->create([
            'started_at' => Carbon::now()->subDays(10),
        ]);

        Log::shouldReceive('channel')->with('auth-v1')->andReturnSelf();
        Log::shouldReceive('critical')->once();

        $this->artisan('migration:health-check', ['--days' => 5, '--threshold' => 0.10])
            ->expectsOutputToContain('[CRITICAL]')
            ->assertExitCode(0);
    }

    #[Test]
    public function ne_comptabilise_pas_les_attempts_hors_fenetre_days(): void
    {
        // Tous les attempts sont vieux (>7j) → table vide pour la fenêtre.
        WorkstationMigrationAttempt::factory()->failed()->count(50)->create([
            'started_at' => Carbon::now()->subDays(30),
        ]);

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('critical')->never();

        $this->artisan('migration:health-check')
            ->expectsOutputToContain('[OK] No attempts')
            ->assertExitCode(0);
    }
}
