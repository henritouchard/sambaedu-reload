<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\UserSyncService;
use Mockery\MockInterface;
use Tests\TestCase;

class SyncUsersFromAdCommandTest extends TestCase
{
    /** @test */
    public function it_runs_users_sync_command_in_delta_mode_by_default(): void
    {
        $this->mock(UserSyncService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('importFromAdDelta')
                ->once()
                ->withArgs(static fn(callable $logger, string $scope): bool => $scope === 'all')
                ->andReturn([
                    'created' => 2,
                    'updated' => 3,
                    'skipped' => 1,
                    'errors' => 0,
                    'admin_granted' => true,
                    'total_ad' => 6,
                    'etab_tree' => 4,
                    'etab_member_of' => 2,
                    'etab_excluded' => 0,
                    'delta_mode' => true,
                    'delta_cursor_start' => '20260224090000.0Z',
                    'delta_cursor_end' => '20260224100000.0Z',
                ]);

            $mock->shouldNotReceive('importFromAd');
        });

        $this->artisan('users:sync-from-ad --scope=all')
            ->expectsOutputToContain('Démarrage de la synchronisation users AD -> SQL')
            ->expectsOutputToContain('created')
            ->expectsOutputToContain('updated')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_fails_with_invalid_scope(): void
    {
        $this->mock(UserSyncService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('importFromAd');
            $mock->shouldNotReceive('importFromAdDelta');
        });

        $this->artisan('users:sync-from-ad --scope=invalid')
            ->expectsOutputToContain('Option --scope invalide')
            ->assertExitCode(1);
    }

    /** @test */
    public function it_fails_with_invalid_mode(): void
    {
        $this->mock(UserSyncService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('importFromAd');
            $mock->shouldNotReceive('importFromAdDelta');
        });

        $this->artisan('users:sync-from-ad --scope=all --mode=invalid')
            ->expectsOutputToContain('Option --mode invalide')
            ->assertExitCode(1);
    }
}
