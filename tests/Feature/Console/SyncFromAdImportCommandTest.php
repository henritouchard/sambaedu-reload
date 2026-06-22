<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\AppProfile\AppProfileAdImporter;
use App\Services\AppProfile\AppProfileLegacyApplicationLinker;
use App\Services\AppStore\LegacyWpkgImporter;
use App\Services\Network\DhcpService;
use App\Services\Parc\WorkstationGroupService;
use App\Services\PermissionService;
use App\Services\Permissions\RightsMigrationService;
use App\Services\ServiceCredentialTotpManager;
use App\Services\ShortcutsService;
use App\Services\UserGroupService;
use App\Services\UserSyncService;
use App\Services\WorkstationService;
use Closure;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pendant CLI de la page sync-from-ad : la commande `import:sync-from-ad`
 * doit rejouer les 12 imports dans le même ordre, avec la même sémantique
 * (skip/dry-run), et accepter sélection interactive comme arguments.
 *
 * Les 12 services sous-jacents sont remplacés par des fakes liés au conteneur
 * (anonymous class + __call) qui enregistrent l'ordre d'appel. Cette approche
 * fonctionne aussi pour les 3 services `final` (Mockery ne peut pas les mocker)
 * car app()->instance() n'impose pas le type.
 */
class SyncFromAdImportCommandTest extends TestCase
{
    /** @var list<string> Codes des imports effectivement exécutés, dans l'ordre. */
    private array $callOrder = [];

    private const CANONICAL_ORDER = [
        'users_establishment',
        'user_groups',
        'workstations',
        'physical_groups',
        'logical_groups',
        'wpkg_applications',
        'app_profiles',
        'shortcuts',
        'rights_profiles',
        'rights_migration',
        'dhcp_reservations',
        'se4install_totp',
    ];

    #[Test]
    public function it_lists_available_imports(): void
    {
        $this->artisan('import:sync-from-ad --list')
            ->expectsOutputToContain('users_establishment')
            ->expectsOutputToContain('rights_migration')
            ->expectsOutputToContain('se4install_totp')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_fails_on_unknown_import_code(): void
    {
        $this->artisan('import:sync-from-ad does_not_exist --no-interaction')
            ->expectsOutputToContain('Import(s) inconnu(s)')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_requires_a_selection_when_non_interactive_and_no_args(): void
    {
        $this->artisan('import:sync-from-ad --no-interaction')
            ->expectsOutputToContain('Précisez')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_runs_all_imports_in_canonical_ux_order(): void
    {
        $this->bindAllFakes();

        $this->artisan('import:sync-from-ad --all --no-interaction')
            ->assertExitCode(0);

        $this->assertSame(self::CANONICAL_ORDER, $this->callOrder);
    }

    #[Test]
    public function it_runs_only_the_selected_subset_in_canonical_order(): void
    {
        $this->bindAllFakes();

        // Arguments donnés dans le désordre : la commande réordonne en ordre UX.
        $this->artisan('import:sync-from-ad shortcuts users_establishment --no-interaction')
            ->assertExitCode(0);

        $this->assertSame(['users_establishment', 'shortcuts'], $this->callOrder);
    }

    #[Test]
    public function it_marks_se4install_totp_as_skipped_when_nothing_imported(): void
    {
        $this->mockService(
            ServiceCredentialTotpManager::class,
            ['importSe4installFromLegacyHashes' => 'se4install_totp'],
            ['importSe4installFromLegacyHashes' => ['imported' => false]],
        );

        $this->artisan('import:sync-from-ad se4install_totp --no-interaction')
            ->expectsOutputToContain('Sauté')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_runs_rights_migration_in_dry_run_by_default(): void
    {
        $dryRun = $this->bindRightsMigrationCapture();

        $this->artisan('import:sync-from-ad rights_migration --no-interaction')
            ->expectsOutputToContain('Aperçu (dry-run)')
            ->assertExitCode(0);

        $this->assertTrue($dryRun());
    }

    #[Test]
    public function it_applies_rights_migration_with_the_execute_flag(): void
    {
        $dryRun = $this->bindRightsMigrationCapture();

        $this->artisan('import:sync-from-ad rights_migration --rights-execute --no-interaction')
            ->assertExitCode(0);

        $this->assertFalse($dryRun());
    }

    #[Test]
    public function it_returns_failure_when_an_import_throws(): void
    {
        $this->bindAllFakes();
        // Le 2e import (user_groups) jette : la commande doit s'arrêter et échouer.
        $this->mock(UserGroupService::class, function (MockInterface $m): void {
            $m->shouldReceive('importFromUsersAdGroups')->andThrow(new \RuntimeException('boom AD'));
        });

        $this->artisan('import:sync-from-ad --all --no-interaction')
            ->expectsOutputToContain('boom AD')
            ->assertExitCode(1);

        // S'est arrêté après l'échec : seul le 1er import a tourné.
        $this->assertSame(['users_establishment'], $this->callOrder);
    }

    #[Test]
    public function it_continues_past_errors_with_continue_on_error(): void
    {
        $this->bindAllFakes();
        $this->mock(UserGroupService::class, function (MockInterface $m): void {
            $m->shouldReceive('importFromUsersAdGroups')->andThrow(new \RuntimeException('boom AD'));
        });

        $this->artisan('import:sync-from-ad --all --no-interaction --continue-on-error')
            ->assertExitCode(1); // échec global car une étape a échoué…

        // …mais les imports suivants ont tout de même tourné.
        $this->assertContains('se4install_totp', $this->callOrder);
        $this->assertNotContains('user_groups', $this->callOrder);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Mocke un service NON-final via Mockery (sous-classe → passe instanceof partout,
     * y compris quand le kernel console construit les autres commandes au boot).
     *
     * @param  array<string, string|null>  $methodMap  méthode => code à enregistrer (null = ne pas enregistrer)
     * @param  array<string, array<string, mixed>>  $returns  méthode => valeur de retour
     */
    private function mockService(string $class, array $methodMap, array $returns = []): void
    {
        $this->mock($class, function (MockInterface $mock) use ($methodMap, $returns): void {
            foreach ($methodMap as $method => $code) {
                $return = $returns[$method] ?? [];
                $mock->shouldReceive($method)->andReturnUsing(function () use ($code, $return) {
                    if ($code !== null) {
                        $this->callOrder[] = $code;
                    }

                    return $return;
                });
            }
        });
    }

    /**
     * Fabrique un fake de service FINAL (non mockable par Mockery) enregistrant
     * l'ordre d'appel via __call. Sûr car ces services ne sont injectés dans aucun
     * constructeur — seule la commande testée les résout via app().
     *
     * @param  array<string, string|null>  $methodMap  méthode => code à enregistrer (null = ne pas enregistrer)
     * @param  array<string, array<string, mixed>>  $returns  méthode => valeur de retour
     */
    private function fake(array $methodMap, array $returns = []): object
    {
        $recorder = function (string $code): void {
            $this->callOrder[] = $code;
        };

        return new class($recorder, $methodMap, $returns) {
            /**
             * @param  array<string, string|null>  $methodMap
             * @param  array<string, array<string, mixed>>  $returns
             */
            public function __construct(
                private Closure $recorder,
                private array $methodMap,
                private array $returns,
            ) {}

            /**
             * @param  array<int|string, mixed>  $arguments
             * @return array<string, mixed>
             */
            public function __call(string $name, array $arguments): array
            {
                $code = array_key_exists($name, $this->methodMap) ? $this->methodMap[$name] : $name;
                if ($code !== null) {
                    ($this->recorder)($code);
                }

                return $this->returns[$name] ?? [];
            }
        };
    }

    /**
     * Lie les 12 services (13 bindings, le linker est compté avec app_profiles)
     * à des fakes enregistreurs avec les formes de retour minimales attendues.
     */
    private function bindAllFakes(): void
    {
        // Services NON-final → Mockery (instanceof-safe au boot du kernel console).
        $this->mockService(UserSyncService::class, ['importFromAd' => 'users_establishment']);
        $this->mockService(UserGroupService::class, ['importFromUsersAdGroups' => 'user_groups']);
        $this->mockService(WorkstationService::class, ['importFromAd' => 'workstations']);
        $this->mockService(WorkstationGroupService::class, [
            'importFromAd' => 'physical_groups',
            'importLogicalGroupsFromAd' => 'logical_groups',
        ]);
        $this->mockService(
            ShortcutsService::class,
            ['importFromJson' => 'shortcuts'],
            ['importFromJson' => ['created' => 0, 'updated' => 0, 'errors' => 0]],
        );
        $this->mockService(PermissionService::class, ['importCustomProfilesFromAd' => 'rights_profiles']);
        $this->mockService(
            RightsMigrationService::class,
            ['migrate' => 'rights_migration'],
            ['migrate' => $this->emptyRightsReport()],
        );
        $this->mockService(DhcpService::class, ['importFromLegacyFile' => 'dhcp_reservations']);
        $this->mockService(
            ServiceCredentialTotpManager::class,
            ['importSe4installFromLegacyHashes' => 'se4install_totp'],
            ['importSe4installFromLegacyHashes' => ['imported' => true]],
        );

        // Services FINAL → fakes anonymes (non injectés dans aucun constructeur, vérifié).
        $this->app->instance(LegacyWpkgImporter::class, $this->fake(['importFromLegacy' => 'wpkg_applications']));
        $this->app->instance(AppProfileAdImporter::class, $this->fake(['importFromAd' => 'app_profiles']));
        $this->app->instance(AppProfileLegacyApplicationLinker::class, $this->fake(
            ['linkFromLegacy' => null],
            ['linkFromLegacy' => [
                'applications_linked' => 0,
                'profiles_linked' => 0,
                'profiles_without_legacy_parc' => 0,
                'applications_missing' => 0,
                'legacy_unavailable' => false,
            ]],
        ));
    }

    /**
     * Mocke RightsMigrationService en capturant l'argument dryRun de migrate().
     *
     * @return Closure(): ?bool  retourne la dernière valeur de dryRun captée
     */
    private function bindRightsMigrationCapture(): Closure
    {
        $captured = new \stdClass();
        $captured->dryRun = null;
        $report = $this->emptyRightsReport();

        $this->mock(RightsMigrationService::class, function (MockInterface $mock) use ($captured, $report): void {
            $mock->shouldReceive('migrate')->andReturnUsing(function (bool $dryRun = false) use ($captured, $report) {
                $captured->dryRun = $dryRun;

                return $report;
            });
        });

        return fn (): ?bool => $captured->dryRun;
    }

    /** @return array<string, mixed> */
    private function emptyRightsReport(): array
    {
        return [
            'users_scanned' => 0,
            'roles_assigned' => 0,
            'delegations_created' => 0,
            'negatives_created' => 0,
            'fallbacks_ignored' => 0,
            'unmappable' => [],
            'warnings' => [],
        ];
    }
}
