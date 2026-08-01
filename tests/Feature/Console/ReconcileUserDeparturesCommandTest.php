<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\ReconcileUserDepartures;
use App\Models\User;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Services\GroupRightsProfileService;
use App\Services\UserSyncService;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 49.3 (AC9 / AC10) — orchestration de `users:reconcile-departures`.
 *
 * `UserSyncService` est mocké au niveau SERVICE (pattern
 * `SyncUsersFromAdCommandTest`) : la commande est testée sur ce qui lui
 * appartient — le choix du chemin de balayage, les codes de sortie et le
 * compte-rendu. Le service de réconciliation, lui, est RÉEL : c'est le
 * comportement de la garde qu'on veut voir de bout en bout.
 */
class ReconcileUserDeparturesCommandTest extends TestCase
{
    use CreatesPermissionSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPermissionSchema();
        Queue::fake();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();

        config()->set('sambaedu.user_sync.reconcile.max_disable_ratio', 0.10);
        config()->set('sambaedu.user_sync.reconcile.max_disable_floor', 5);
    }

    protected function tearDown(): void
    {
        UserGroupUserPivotObserver::enableSync();
        UserGroupObserver::enableSync();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function user(string $login, string $source = 'ad'): User
    {
        $user = User::create(['login' => $login, 'role' => 'eleve', 'is_active' => true]);
        $user->source = $source;
        $user->save();

        return $user;
    }

    /**
     * @param string[] $presentLogins
     */
    private function importStats(array $presentLogins, int $groupsFailed = 0, int $mainGroups = 3): array
    {
        return [
            'created' => 0,
            'updated' => count($presentLogins),
            'skipped' => 0,
            'errors' => 0,
            'reactivated' => 1,
            'admin_granted' => true,
            'total_ad' => count($presentLogins),
            'etab_tree' => 0,
            'etab_ou_tree' => 0,
            'etab_member_of' => 0,
            'etab_excluded' => 0,
            'fetch_groups_failed' => $groupsFailed,
            'main_groups_found' => $mainGroups,
            'present_guids' => [],
            'present_logins' => $presentLogins,
            'delta_mode' => false,
            'delta_cursor_start' => null,
            'delta_cursor_end' => null,
        ];
    }

    // ========================================================================

    #[Test]
    public function it_runs_a_full_import_then_disables_the_absentees(): void
    {
        $gone = $this->user('parti');
        $this->user('reste');

        $this->mock(UserSyncService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('importFromAd')
                ->once()
                ->withArgs(static fn(?callable $logger, string $scope): bool => $scope === 'all')
                ->andReturn($this->importStats(['reste']));

            $mock->shouldNotReceive('fetchPresence');
        });

        $this->artisan('users:reconcile-departures')
            ->expectsOutputToContain('Réconciliation des départs terminée.')
            ->assertExitCode(0);

        self::assertFalse((bool) User::find($gone->id)->is_active);
    }

    #[Test]
    public function it_aborts_with_exit_code_2_when_a_main_group_failed(): void
    {
        $gone = $this->user('parti');
        $this->user('reste');

        $this->mock(UserSyncService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('importFromAd')
                ->once()
                ->andReturn($this->importStats(['reste'], groupsFailed: 1));
        });

        $this->artisan('users:reconcile-departures')
            ->expectsOutputToContain('ABANDONNÉE')
            ->assertExitCode(ReconcileUserDepartures::EXIT_GUARD_ABORTED);

        self::assertTrue((bool) User::find($gone->id)->is_active, 'Aucune désactivation sur balayage douteux.');
    }

    #[Test]
    public function it_aborts_with_exit_code_2_when_the_import_throws(): void
    {
        $gone = $this->user('parti');

        $this->mock(UserSyncService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('importFromAd')
                ->once()
                ->andThrow(new \RuntimeException('annuaire injoignable'));
        });

        $this->artisan('users:reconcile-departures')
            ->expectsOutputToContain('Échec du balayage AD')
            ->assertExitCode(ReconcileUserDepartures::EXIT_GUARD_ABORTED);

        self::assertTrue((bool) User::find($gone->id)->is_active);
    }

    #[Test]
    public function force_lifts_the_threshold_but_the_health_guards_stay(): void
    {
        config()->set('sambaedu.user_sync.reconcile.max_disable_floor', 1);

        $a = $this->user('parti-1');
        $b = $this->user('parti-2');
        $this->user('reste');

        $this->mock(UserSyncService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('importFromAd')
                ->twice()
                ->andReturn(
                    $this->importStats(['reste']),
                    $this->importStats(['reste'], groupsFailed: 1),
                );
        });

        // Sans --force : seuil dépassé → abandon.
        $this->artisan('users:reconcile-departures')
            ->assertExitCode(ReconcileUserDepartures::EXIT_GUARD_ABORTED);
        self::assertTrue((bool) User::find($a->id)->is_active);

        // Avec --force mais un groupe en échec : la garde de SANTÉ tient.
        $this->artisan('users:reconcile-departures --force')
            ->assertExitCode(ReconcileUserDepartures::EXIT_GUARD_ABORTED);
        self::assertTrue((bool) User::find($a->id)->is_active);
        self::assertTrue((bool) User::find($b->id)->is_active);
    }

    #[Test]
    public function force_disables_a_legitimate_mass_departure(): void
    {
        config()->set('sambaedu.user_sync.reconcile.max_disable_floor', 1);

        $a = $this->user('parti-1');
        $b = $this->user('parti-2');
        $this->user('reste');

        $this->mock(UserSyncService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('importFromAd')
                ->once()
                ->andReturn($this->importStats(['reste']));
        });

        $this->artisan('users:reconcile-departures --force')
            ->assertExitCode(0);

        self::assertFalse((bool) User::find($a->id)->is_active);
        self::assertFalse((bool) User::find($b->id)->is_active);
    }

    #[Test]
    public function dry_run_uses_the_fetch_only_path_and_writes_nothing(): void
    {
        $gone = $this->user('parti');
        $this->user('reste');

        $this->mock(UserSyncService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('fetchPresence')
                ->once()
                ->withArgs(static fn(?callable $logger, string $scope): bool => $scope === 'tree')
                ->andReturn([
                    'total_ad' => 1,
                    'fetch_groups_failed' => 0,
                    'main_groups_found' => 3,
                    'present_guids' => [],
                    'present_logins' => ['reste'],
                ]);

            // Le dry-run n'écrit RIEN : pas même les upserts de l'import.
            $mock->shouldNotReceive('importFromAd');
            $mock->shouldNotReceive('importFromAdDelta');
        });

        $this->artisan('users:reconcile-departures --dry-run --scope=tree')
            ->expectsOutputToContain('Simulation terminée.')
            ->assertExitCode(0);

        self::assertTrue((bool) User::find($gone->id)->is_active);
    }

    #[Test]
    public function per_user_errors_yield_exit_code_1(): void
    {
        $this->app->bind(GroupRightsProfileService::class, fn() => new class extends GroupRightsProfileService {
            public function reconcile(User $user, array $extraRevocableRoleIds = []): array
            {
                throw new \RuntimeException('échec simulé');
            }
        });

        $gone = $this->user('parti');
        $this->user('reste');

        $this->mock(UserSyncService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('importFromAd')
                ->once()
                ->andReturn($this->importStats(['reste']));
        });

        $this->artisan('users:reconcile-departures')
            ->assertExitCode(1);

        self::assertTrue((bool) User::find($gone->id)->is_active, 'La transaction du user en erreur est annulée.');
    }

    #[Test]
    public function it_refuses_an_invalid_scope_without_touching_the_directory(): void
    {
        $this->mock(UserSyncService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('importFromAd');
            $mock->shouldNotReceive('fetchPresence');
        });

        $this->artisan('users:reconcile-departures --scope=invalide')
            ->expectsOutputToContain('Option --scope invalide')
            ->assertExitCode(1);
    }
}
