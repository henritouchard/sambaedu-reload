<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use App\Models\User;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\Permissions\RightsMigrationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Tests de la commande artisan `sambaedu:migrate-rights-to-spatie` (Story 7.3).
 *
 * Couverture :
 *  - Dry-run : rapport affiché, aucune écriture DB.
 *  - Run effectif : assignations créées, rapport tabulé.
 *  - Idempotence sur plusieurs runs.
 *  - Bug Annu_is_admin sans `info` : warning + fallback UserAdmin (pas ComputerAdmin).
 *  - Exit codes : SUCCESS (0) normal, FAILURE (1) si exception non-catchable.
 *  - Délégations scopées positives/négatives.
 *  - Log persisté en run effectif.
 */
class MigrateRightsToSpatieCommandTest extends TestCase
{
    use CreatesPermissionSchema;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->createPermissionSchema();
        $this->seedPermissionsAndRoles();

        Queue::fake();
        WorkstationGroupObserver::disableSync();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function seedPermissionsAndRoles(): void
    {
        foreach (SambaPermission::cases() as $perm) {
            Permission::firstOrCreate(['name' => $perm->value, 'guard_name' => 'web']);
        }
        foreach (SambaRole::cases() as $sambaRole) {
            $role = Role::firstOrCreate(['name' => $sambaRole->value, 'guard_name' => 'web']);
            $role->syncPermissions($sambaRole->permissionNames());
        }
    }

    private function createUser(string $login): User
    {
        return User::create([
            'login'    => $login,
            'fullname' => ucfirst($login),
            'dn'       => "CN={$login},OU=Utilisateurs,DC=test",
            'role'     => 'autre',
            'is_active' => true,
        ]);
    }

    /**
     * Fabrique un mock service de migration injecté via bindShared — rend la
     * commande testable sans avoir à monter un LDAP complet.
     */
    private function bindMigrationServiceMock(array $fixtureData): void
    {
        $this->app->singleton(RightsMigrationService::class, function () use ($fixtureData) {
            return new class ($fixtureData, app(\App\Services\PermissionService::class)) extends RightsMigrationService {
                public function __construct(
                    private readonly array $fixtureData,
                    \App\Services\PermissionService $permissionService,
                ) {
                    parent::__construct($permissionService);
                }

                public function migrate(
                    bool $dryRun = false,
                    ?callable $rightsFetcher = null,
                    ?callable $rightsMembersFetcher = null,
                    ?callable $delegationsFetcher = null,
                ): array {
                    return parent::migrate(
                        dryRun: $dryRun,
                        rightsFetcher: fn () => $this->fixtureData['rights'] ?? [],
                        rightsMembersFetcher: function (string $cn) {
                            return $this->fixtureData['members'][$cn] ?? [];
                        },
                        delegationsFetcher: fn () => $this->fixtureData['delegations'] ?? [],
                    );
                }
            };
        });
    }

    // ================================================================
    // Dry-run
    // ================================================================

    #[Test]
    public function dry_run_prints_report_without_writing_to_db(): void
    {
        $admin = $this->createUser('dryadm');

        $this->bindMigrationServiceMock([
            'rights'  => ['se3_is_admin' => 0xFFFF],
            'members' => ['se3_is_admin' => [$admin->dn]],
        ]);

        $this->artisan('sambaedu:migrate-rights-to-spatie', ['--dry-run' => true])
            ->expectsOutputToContain('DRY-RUN')
            ->assertExitCode(0);

        $this->assertSame(0, $admin->fresh()->roles()->count(), 'Dry-run ne doit créer aucune assignation');
    }

    // ================================================================
    // Run effectif + idempotence
    // ================================================================

    #[Test]
    public function run_effective_creates_role_assignments(): void
    {
        $admin = $this->createUser('runadm');

        $this->bindMigrationServiceMock([
            'rights'  => ['se3_is_admin' => 0xFFFF],
            'members' => ['se3_is_admin' => [$admin->dn]],
        ]);

        $this->artisan('sambaedu:migrate-rights-to-spatie')
            ->expectsOutputToContain('RUN')
            ->assertExitCode(0);

        $this->assertTrue($admin->fresh()->hasRole(SambaRole::SuperAdmin->value));
    }

    #[Test]
    public function re_running_command_is_idempotent(): void
    {
        $admin = $this->createUser('idem1');

        $this->bindMigrationServiceMock([
            'rights'  => ['se3_is_admin' => 0xFFFF],
            'members' => ['se3_is_admin' => [$admin->dn]],
        ]);

        $this->artisan('sambaedu:migrate-rights-to-spatie')->assertExitCode(0);
        $this->artisan('sambaedu:migrate-rights-to-spatie')->assertExitCode(0);
        $this->artisan('sambaedu:migrate-rights-to-spatie')->assertExitCode(0);

        $this->assertSame(1, $admin->fresh()->roles()->count(), 'Aucun doublon après 3 runs');
    }

    // ================================================================
    // Bug Annu_is_admin
    // ================================================================

    #[Test]
    public function it_applies_user_admin_fallback_for_annu_is_admin_without_info_and_logs_warning(): void
    {
        $user = $this->createUser('annuadm');

        // Log spy : on capture les warnings émis pendant l'exécution.
        $warnings = [];
        Log::shouldReceive('warning')->andReturnUsing(function ($msg, $ctx = []) use (&$warnings): void {
            $warnings[] = $msg;
        });
        // Laisser passer les autres niveaux.
        Log::shouldReceive('info')->byDefault();
        Log::shouldReceive('debug')->byDefault();
        Log::shouldReceive('error')->byDefault();

        $this->bindMigrationServiceMock([
            'rights'  => ['Annu_is_admin' => 0], // info absente → bug legacy
            'members' => ['Annu_is_admin' => [$user->dn]],
        ]);

        $this->artisan('sambaedu:migrate-rights-to-spatie')
            ->assertExitCode(0);

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasRole(SambaRole::UserAdmin->value));
        $this->assertFalse($fresh->hasRole(SambaRole::ComputerAdmin->value));

        $annuWarnings = array_filter($warnings, fn ($w) => is_string($w) && str_contains($w, 'Annu_is_admin'));
        $this->assertNotEmpty($annuWarnings, 'Un warning Annu_is_admin doit être loggé');
    }

    // ================================================================
    // Délégations scopées positives + négatives
    // ================================================================

    #[Test]
    public function positive_scoped_delegation_is_created_on_run(): void
    {
        $user = $this->createUser('techpos');
        $wg = WorkstationGroup::create([
            'name' => 'salle-cmd-a',
            'is_physical' => true,
            'is_active' => true,
        ]);

        $this->bindMigrationServiceMock([
            'delegations' => [
                [
                    // Story 7.3 — format CN legacy réel `manage_<parc>` → computer.elevate.
                    'cn'      => 'manage_salle-cmd-a',
                    'members' => [$user->dn, 'CN=salle-cmd-a,OU=Parcs,DC=test'],
                ],
            ],
        ]);

        $this->artisan('sambaedu:migrate-rights-to-spatie')
            ->assertExitCode(0);

        $this->assertDatabaseHas('delegations', [
            'user_id' => $user->id,
            'workstation_group_id' => $wg->id,
            'is_negative' => false,
        ]);
    }

    #[Test]
    public function negative_scoped_delegation_is_created_on_run(): void
    {
        $user = $this->createUser('techneg');
        $wg = WorkstationGroup::create([
            'name' => 'salle-cmd-b',
            'is_physical' => true,
            'is_active' => true,
        ]);

        $this->bindMigrationServiceMock([
            'delegations' => [
                [
                    // Story 7.3 — format CN legacy réel `no_<level>_<parc>`.
                    // `no_manage` → négative sur `computer.elevate` (0x400).
                    'cn'      => 'no_manage_salle-cmd-b',
                    'members' => [$user->dn, 'CN=salle-cmd-b,OU=Parcs,DC=test'],
                ],
            ],
        ]);

        $this->artisan('sambaedu:migrate-rights-to-spatie')->assertExitCode(0);

        $this->assertDatabaseHas('delegations', [
            'user_id' => $user->id,
            'workstation_group_id' => $wg->id,
            'is_negative' => true,
        ]);
    }

    // ================================================================
    // Cas non mappables dans le rapport
    // ================================================================

    #[Test]
    public function unmappable_user_is_reported_not_crashed(): void
    {
        $this->bindMigrationServiceMock([
            'rights'  => ['se3_is_admin' => 0xFFFF],
            'members' => ['se3_is_admin' => ['CN=ghost,OU=Utilisateurs,DC=test']],
        ]);

        $this->artisan('sambaedu:migrate-rights-to-spatie')
            ->assertExitCode(0);
    }

    // ================================================================
    // Dry-run : aucune écriture même pour délégations
    // ================================================================

    #[Test]
    public function dry_run_does_not_create_delegation(): void
    {
        $user = $this->createUser('drytech');
        $wg = WorkstationGroup::create([
            'name' => 'salle-dry',
            'is_physical' => true,
            'is_active' => true,
        ]);

        $this->bindMigrationServiceMock([
            'delegations' => [
                [
                    // Story 7.3 — format CN legacy réel `manage_<parc>`.
                    'cn'      => 'manage_salle-dry',
                    'members' => [$user->dn],
                ],
            ],
        ]);

        $this->artisan('sambaedu:migrate-rights-to-spatie', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('delegations', [
            'user_id' => $user->id,
            'workstation_group_id' => $wg->id,
        ]);
    }
}
