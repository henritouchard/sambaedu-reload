<?php

declare(strict_types=1);

namespace Tests\Unit\Ldap;

use App\Gpo\Support\SambaToolRunner;
use App\Ldap\AdMachineManager;
use App\Repositories\WorkstationRepository;
use Illuminate\Support\Facades\Process;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 16.7 — AC3.3.
 *
 * Tests Unit `AdMachineManager` : utilise `Process::fake()` Laravel pour
 * simuler les appels `samba-tool` sans nécessiter Mockery sur `SambaToolRunner`
 * (final, non-mockable sans uopz/runkit en CI).
 *
 * Vérifie :
 *  - regex stricte (machine, user) → rejet AVANT exec
 *  - exit code 0 → success, non-zero → false
 *  - idempotence (`already exists`, `already a member`)
 *  - branches Guacamole (config absente → '' direct, shim si présente)
 */
class AdMachineManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sambaedu.gpo.bin_path', '/usr/bin/samba-tool');
        config()->set('sambaedu.gpo.kerb_option', '');
        config()->set('sambaedu.gpo.samba_tool_timeout', 30);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeRunner(): SambaToolRunner
    {
        return new SambaToolRunner();
    }

    private function makeRepo(?\App\LdapModels\MachineModel $machine = null): WorkstationRepository
    {
        $repo = Mockery::mock(WorkstationRepository::class);
        $repo->shouldReceive('findByName')->andReturn($machine)->byDefault();
        /** @var WorkstationRepository $repo */
        return $repo;
    }

    // ────────────────────────── check() ──────────────────────────

    #[Test]
    public function check_returns_true_when_machine_already_exists_in_ldap(): void
    {
        Process::fake(); // pas d'appel attendu
        $machine = Mockery::mock(\App\LdapModels\MachineModel::class);
        $manager = new AdMachineManager($this->makeRunner(), $this->makeRepo($machine));
        self::assertTrue($manager->check('PC-001'));
        Process::assertNothingRan();
    }

    #[Test]
    public function check_returns_true_for_se4fs_servers_skip(): void
    {
        Process::fake();
        $manager = new AdMachineManager($this->makeRunner(), $this->makeRepo());
        self::assertTrue($manager->check('se4fs-master'));
        self::assertTrue($manager->check('se4ad01'));
        self::assertTrue($manager->check('SE4FS'));
        Process::assertNothingRan();
    }

    #[Test]
    public function check_creates_machine_when_absent(): void
    {
        Process::fake([
            '*' => Process::result(output: '', exitCode: 0),
        ]);
        $manager = new AdMachineManager($this->makeRunner(), $this->makeRepo(null));
        self::assertTrue($manager->check('PC-NEW-42'));
        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? $process->command : [];
            // Le poste doit être créé dans l'OU SambaEdu (`--computerou=ou=computers`),
            // pas le conteneur AD par défaut `CN=Computers` — sinon invisible du
            // checker de divergence (AdSyncChecker n'énumère que cette OU).
            $hasComputerOu = (bool) array_filter(
                $cmd,
                fn ($a) => is_string($a) && str_starts_with($a, '--computerou=') && str_contains(strtolower($a), 'ou=computers'),
            );

            return in_array('computer', $cmd, true)
                && in_array('create', $cmd, true)
                && in_array('PC-NEW-42', $cmd, true)
                && $hasComputerOu;
        });
    }

    #[Test]
    public function check_treats_already_exists_as_success(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'ldb: Already exists in DB.', exitCode: 1),
        ]);
        $manager = new AdMachineManager($this->makeRunner(), $this->makeRepo(null));
        self::assertTrue($manager->check('PC-RACE'));
    }

    #[Test]
    public function check_returns_false_on_other_exec_failure(): void
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'access denied', exitCode: 1),
        ]);
        $manager = new AdMachineManager($this->makeRunner(), $this->makeRepo(null));
        self::assertFalse($manager->check('PC-DENIED'));
    }

    #[Test]
    public function check_rejects_invalid_machine_name_no_exec(): void
    {
        Process::fake();
        $manager = new AdMachineManager($this->makeRunner(), $this->makeRepo());
        self::assertFalse($manager->check('; rm -rf /'));
        self::assertFalse($manager->check(''));
        self::assertFalse($manager->check(str_repeat('a', 65)));
        Process::assertNothingRan();
    }

    // ────────────────────────── registerHardware() ──────────────────────────

    #[Test]
    public function register_hardware_writes_netbootguid_via_ldap(): void
    {
        // Plus de samba-tool pour registerHardware : `computer edit` n'a pas
        // d'option `--set-attribute` (samba 4.22). On écrit via LdapRecord.
        Process::fake();

        // `$machine->netbootguid = …` route via __set → setAttribute().
        // L'expectation `with()` sert de garde-fou sur la valeur binaire :
        // GUID Microsoft (little-endian sur les 3 premiers champs), identique
        // au legacy `from_guid()` → indispensable pour la reconnaissance PXE.
        $machine = Mockery::mock(\App\LdapModels\MachineModel::class);
        $machine->shouldReceive('setAttribute')->once()
            ->with('netbootguid', hex2bin('67452301ab89efcd0123456789abcdef'))
            ->andReturnSelf();
        $machine->shouldReceive('save')->once()->andReturnTrue();

        $manager = new AdMachineManager($this->makeRunner(), $this->makeRepo($machine));
        self::assertTrue($manager->registerHardware('PC-001', '01234567-89ab-cdef-0123-456789abcdef'));
        Process::assertNothingRan();
    }

    #[Test]
    public function register_hardware_rejects_invalid_uuid(): void
    {
        Process::fake();
        $manager = new AdMachineManager($this->makeRunner(), $this->makeRepo());
        self::assertFalse($manager->registerHardware('PC-001', 'not-a-uuid'));
        self::assertFalse($manager->registerHardware('PC-001', ''));
        self::assertFalse($manager->registerHardware('PC-001', 'short'));
        Process::assertNothingRan();
    }

    #[Test]
    public function register_hardware_returns_false_when_machine_absent(): void
    {
        Process::fake();
        // repo->findByName retourne null → machine introuvable dans l'AD.
        $manager = new AdMachineManager($this->makeRunner(), $this->makeRepo(null));
        self::assertFalse($manager->registerHardware('PC-001', '01234567-89ab-cdef-0123-456789abcdef'));
    }

    #[Test]
    public function register_hardware_returns_false_when_ldap_save_throws(): void
    {
        Process::fake();
        $machine = Mockery::mock(\App\LdapModels\MachineModel::class);
        $machine->shouldReceive('setAttribute')->andReturnSelf();
        $machine->shouldReceive('save')->once()->andThrow(new \RuntimeException('ldap write failed'));
        $manager = new AdMachineManager($this->makeRunner(), $this->makeRepo($machine));
        self::assertFalse($manager->registerHardware('PC-001', '01234567-89ab-cdef-0123-456789abcdef'));
    }

    // ────────────────────────── setOs() ──────────────────────────

    #[Test]
    public function set_os_invokes_group_addmembers_with_machine_suffix(): void
    {
        Process::fake([
            '*' => Process::result(exitCode: 0),
        ]);
        $manager = new AdMachineManager($this->makeRunner(), $this->makeRepo());
        self::assertTrue($manager->setOs('PC-001', 'linux'));
        Process::assertRan(function ($p) {
            $cmd = is_array($p->command) ? $p->command : [];
            return in_array('group', $cmd, true) && in_array('addmembers', $cmd, true);
        });
    }

    #[Test]
    public function set_os_idempotent_when_already_member(): void
    {
        Process::fake([
            '*' => Process::result(errorOutput: 'object is already a member of group', exitCode: 1),
        ]);
        $manager = new AdMachineManager($this->makeRunner(), $this->makeRepo());
        self::assertTrue($manager->setOs('PC-001', 'windows'));
    }

    #[Test]
    public function set_os_rejects_invalid_os(): void
    {
        Process::fake();
        $manager = new AdMachineManager($this->makeRunner(), $this->makeRepo());
        self::assertFalse($manager->setOs('PC-001', 'macos'));
        self::assertFalse($manager->setOs('PC-001', ''));
        self::assertFalse($manager->setOs('PC-001', 'LINUX')); // case-sensitive
        Process::assertNothingRan();
    }

    #[Test]
    public function set_os_rejects_invalid_machine_name(): void
    {
        Process::fake();
        $manager = new AdMachineManager($this->makeRunner(), $this->makeRepo());
        self::assertFalse($manager->setOs('; rm -rf /', 'linux'));
        Process::assertNothingRan();
    }

    // ────────────────────────── listRemoteConnexion() ──────────────────────────

    #[Test]
    public function list_remote_returns_empty_when_guacamole_disabled(): void
    {
        config()->set('sambaedu.guacamole_url', '');
        Process::fake();
        $manager = new AdMachineManager($this->makeRunner(), $this->makeRepo());
        self::assertSame('', $manager->listRemoteConnexion('PC-001', 'jdoe'));
        Process::assertNothingRan();
    }

    #[Test]
    public function list_remote_returns_empty_when_guacamole_set_shim_fallback(): void
    {
        // Shim fallback gracieux : pas de portage natif du repo Guacamole en 16.7.
        config()->set('sambaedu.guacamole_url', 'http://localhost/guacamole/');
        Process::fake();
        $manager = new AdMachineManager($this->makeRunner(), $this->makeRepo());
        self::assertSame('', $manager->listRemoteConnexion('PC-001', 'jdoe'));
    }

    #[Test]
    public function list_remote_rejects_invalid_user(): void
    {
        config()->set('sambaedu.guacamole_url', 'http://localhost/guacamole/');
        Process::fake();
        $manager = new AdMachineManager($this->makeRunner(), $this->makeRepo());
        self::assertSame('', $manager->listRemoteConnexion('PC-001', '; rm -rf /'));
    }

    // ────────────────────────── renameComputer() — Story 3.3 / D14 / AC3.1 ──────────────────────────

    #[Test]
    public function rename_computer_runs_delete_then_create_via_samba_tool(): void
    {
        Process::fake([
            '*' => Process::result(exitCode: 0),
        ]);
        $manager = new AdMachineManager($this->makeRunner(), $this->makeRepo());

        self::assertTrue($manager->renameComputer('PC-OLD', 'PC-NEW'));

        // Vérifie qu'un appel `computer delete PC-OLD` ET `computer create PC-NEW`
        // ont bien été lancés (séquentialité best-effort, parité D14 plan B).
        $sawDelete = false;
        $sawCreate = false;
        Process::assertRan(function ($process) use (&$sawDelete, &$sawCreate) {
            $cmd = is_array($process->command) ? $process->command : [];
            if (in_array('computer', $cmd, true) && in_array('delete', $cmd, true) && in_array('PC-OLD', $cmd, true)) {
                $sawDelete = true;
            }
            if (in_array('computer', $cmd, true) && in_array('create', $cmd, true) && in_array('PC-NEW', $cmd, true)) {
                $sawCreate = true;
            }
            return true;
        });
        self::assertTrue($sawDelete, 'samba-tool computer delete PC-OLD attendu');
        self::assertTrue($sawCreate, 'samba-tool computer create PC-NEW attendu');
    }

    #[Test]
    public function rename_computer_returns_true_when_old_name_already_absent(): void
    {
        // Delete retourne exit 1 + "no such object", create retourne 0.
        // Iso-legacy : tolérance — le rename est satisfait si le nouveau compte existe.
        Process::fake([
            '*' => Process::sequence()
                ->push(Process::result(errorOutput: 'no such object', exitCode: 1))
                ->push(Process::result(exitCode: 0)),
        ]);
        $manager = new AdMachineManager($this->makeRunner(), $this->makeRepo());

        self::assertTrue($manager->renameComputer('PC-MISSING', 'PC-NEW'));
    }

    #[Test]
    public function rename_computer_returns_true_when_new_name_already_exists(): void
    {
        // Delete OK, create retourne "already exists" → idempotent succès.
        Process::fake([
            '*' => Process::sequence()
                ->push(Process::result(exitCode: 0))
                ->push(Process::result(errorOutput: 'ldb: Already exists in DB.', exitCode: 1)),
        ]);
        $manager = new AdMachineManager($this->makeRunner(), $this->makeRepo());

        self::assertTrue($manager->renameComputer('PC-OLD', 'PC-RACE'));
    }

    #[Test]
    public function rename_computer_returns_false_when_create_fails_with_other_error(): void
    {
        Process::fake([
            '*' => Process::sequence()
                ->push(Process::result(exitCode: 0))
                ->push(Process::result(errorOutput: 'access denied', exitCode: 1)),
        ]);
        $manager = new AdMachineManager($this->makeRunner(), $this->makeRepo());

        self::assertFalse($manager->renameComputer('PC-OLD', 'PC-DENIED'));
    }

    #[Test]
    public function rename_computer_rejects_invalid_names_without_exec(): void
    {
        Process::fake();
        $manager = new AdMachineManager($this->makeRunner(), $this->makeRepo());

        self::assertFalse($manager->renameComputer('; rm -rf /', 'PC-NEW'));
        self::assertFalse($manager->renameComputer('PC-OLD', '; rm -rf /'));
        self::assertFalse($manager->renameComputer('', 'PC-NEW'));
        self::assertFalse($manager->renameComputer('PC-OLD', ''));
        Process::assertNothingRan();
    }

    #[Test]
    public function rename_computer_is_noop_when_old_and_new_are_identical(): void
    {
        Process::fake();
        $manager = new AdMachineManager($this->makeRunner(), $this->makeRepo());

        self::assertTrue($manager->renameComputer('PC-SAME', 'PC-SAME'));
        // Case-insensitive (parité legacy).
        self::assertTrue($manager->renameComputer('pc-same', 'PC-SAME'));
        Process::assertNothingRan();
    }

    #[Test]
    public function rename_computer_skips_se4fs_se4ad_servers(): void
    {
        Process::fake();
        $manager = new AdMachineManager($this->makeRunner(), $this->makeRepo());

        self::assertTrue($manager->renameComputer('se4fs-master', 'se4fs-new'));
        self::assertTrue($manager->renameComputer('se4ad01', 'se4ad02'));
        Process::assertNothingRan();
    }
}
