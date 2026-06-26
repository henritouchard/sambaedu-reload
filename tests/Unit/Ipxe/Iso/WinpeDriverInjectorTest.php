<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Iso;

use App\Ipxe\Iso\Exceptions\WinpeDriverInjectionException;
use App\Ipxe\Iso\Services\WinpeDriverInjector;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 3.10 — AC6.1 — Tests unitaires de {@see WinpeDriverInjector}.
 *
 * `Process::fake()` partout (jamais de vrai `wimlib-imagex` — cf.
 * [[project_phpunit_test_env_host_vs_vm]]). On vérifie : no-op pack vide
 * (AUCUNE commande wimlib lancée), construction de la commande `wimlib-imagex
 * update` sur l'INDEX 2 (add par famille à `/drivers/<famille>`), injection de
 * `nicload.cmd` à `/Windows/System32/`, et mapping d'échec → exception (exit +
 * stderr).
 */
class WinpeDriverInjectorTest extends TestCase
{
    private string $packPath;

    private string $bootWim;

    private string $winpeSource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packPath = sys_get_temp_dir() . '/se5-winpe-pack-' . getmypid() . '-' . uniqid();
        mkdir($this->packPath, 0755, true);

        // Un boot.wim cible factice (l'injecteur vérifie son existence dès lors
        // qu'un pack non vide doit être injecté).
        $this->bootWim = sys_get_temp_dir() . '/se5-bootwim-' . getmypid() . '-' . uniqid() . '.wim';
        file_put_contents($this->bootWim, 'FAKE-WIM');

        // Source des helpers WinPE (où vit nicload.cmd versionné).
        $this->winpeSource = sys_get_temp_dir() . '/se5-winpe-src-' . getmypid() . '-' . uniqid();
        mkdir($this->winpeSource, 0755, true);
        file_put_contents($this->winpeSource . '/nicload.cmd', "for /r X:\\drivers %%f in (*.inf) do drvload \"%%f\"\r\n");

        config([
            'ipxe.iso_management.winpe_drivers_path'       => $this->packPath,
            'ipxe.iso_management.winpe_boot_wim_image_index' => 2,
            'ipxe.iso_management.extract_timeout_seconds'  => 60,
            'ipxe.windows.assets_paths.winpe_source_path'  => $this->winpeSource,
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->bootWim);
        $this->rrmdir($this->packPath);
        $this->rrmdir($this->winpeSource);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? $this->rrmdir($f) : @unlink($f);
        }
        @rmdir($dir);
    }

    private function seedFamily(string $family, string $infName = 'driver.inf'): void
    {
        $dir = $this->packPath . '/' . $family;
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/' . $infName, "[Version]\n");
        file_put_contents($dir . '/' . str_replace('.inf', '.sys', $infName), 'BIN');
        file_put_contents($dir . '/' . str_replace('.inf', '.cat', $infName), 'CAT');
    }

    #[Test]
    public function it_is_a_clean_noop_when_pack_is_empty(): void
    {
        Process::fake();

        // Pack présent mais vide (aucune famille / aucun .inf).
        (new WinpeDriverInjector())->inject($this->bootWim);

        Process::assertNothingRan();
    }

    #[Test]
    public function it_is_a_clean_noop_when_pack_path_is_absent(): void
    {
        Process::fake();
        config(['ipxe.iso_management.winpe_drivers_path' => $this->packPath . '/does-not-exist']);

        (new WinpeDriverInjector())->inject($this->bootWim);

        Process::assertNothingRan();
    }

    #[Test]
    public function it_is_a_clean_noop_when_family_has_no_inf(): void
    {
        Process::fake();
        // Sous-dossier sans aucun .inf → ignoré.
        mkdir($this->packPath . '/empty-family', 0755, true);
        file_put_contents($this->packPath . '/empty-family/readme.txt', 'no inf here');

        (new WinpeDriverInjector())->inject($this->bootWim);

        Process::assertNothingRan();
    }

    #[Test]
    public function it_runs_wimlib_add_on_image_index_2_for_each_family(): void
    {
        Process::fake();
        $this->seedFamily('intel-i219');

        (new WinpeDriverInjector())->inject($this->bootWim);

        // wimlib-imagex update <bootwim> 2 --command='add <pack>/intel-i219 /drivers/intel-i219'
        Process::assertRan(fn ($p) => str_starts_with($p->command, 'wimlib-imagex update')
            && str_contains($p->command, "'" . $this->bootWim . "'")
            && str_contains($p->command, ' 2 --command=')
            && str_contains($p->command, 'add ' . $this->packPath . '/intel-i219 /drivers/intel-i219'));
    }

    #[Test]
    public function it_injects_nicload_cmd_into_system32(): void
    {
        Process::fake();
        $this->seedFamily('intel-i219');

        (new WinpeDriverInjector())->inject($this->bootWim);

        Process::assertRan(fn ($p) => str_starts_with($p->command, 'wimlib-imagex update')
            && str_contains($p->command, ' 2 --command=')
            && str_contains($p->command, $this->winpeSource . '/nicload.cmd /Windows/System32/nicload.cmd'));
    }

    #[Test]
    public function it_never_targets_index_1(): void
    {
        Process::fake();
        $this->seedFamily('intel-i219');

        (new WinpeDriverInjector())->inject($this->bootWim);

        // Aucune commande wimlib ne doit cibler l'index 1 (piège Setup vs WinPE).
        Process::assertNotRan(fn ($p) => str_starts_with($p->command, 'wimlib-imagex update')
            && str_contains($p->command, ' 1 --command='));
    }

    #[Test]
    public function it_throws_with_exit_code_and_stderr_when_wimlib_fails(): void
    {
        Process::fake([
            'wimlib-imagex*' => Process::result(output: '', errorOutput: 'wimlib: invalid image', exitCode: 1),
        ]);
        $this->seedFamily('intel-i219');

        try {
            (new WinpeDriverInjector())->inject($this->bootWim);
            self::fail('Une WinpeDriverInjectionException était attendue.');
        } catch (WinpeDriverInjectionException $e) {
            self::assertSame(1, $e->exitCode);
            self::assertStringContainsString('winpe-drivers', $e->getMessage());
            self::assertStringContainsString('invalid image', $e->getMessage());
        }
    }

    #[Test]
    public function it_throws_when_boot_wim_is_missing_but_pack_is_non_empty(): void
    {
        Process::fake();
        $this->seedFamily('intel-i219');

        try {
            (new WinpeDriverInjector())->inject($this->bootWim . '.nope');
            self::fail('Une WinpeDriverInjectionException était attendue.');
        } catch (WinpeDriverInjectionException $e) {
            self::assertStringContainsString('boot.wim cible introuvable', $e->getMessage());
        }

        Process::assertNothingRan();
    }
}
