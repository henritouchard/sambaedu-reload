<?php

declare(strict_types=1);

namespace Tests\Feature\Wpkg\UI;

use App\Models\Workstation;
use App\Wpkg\Deployment\Events\WorkstationOptionsChanged;
use App\Wpkg\Deployment\Models\WpkgWorkstationOption;
use App\Wpkg\Deployment\Services\WorkstationOptionsService;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 15.4 / AC5, AC7.1 — Onglet Options .ini du poste.
 * Vérifie : (a) modif option persiste, (b) event WorkstationOptionsChanged
 * dispatché, (c) listener `RegenerateWorkstationIniOnOptionsChanged` régénère
 * le `.ini` (test feature live, pas Event::fake — on observe le disque).
 */
class WorkstationOptionsTabTest extends TestCase
{
    private string $iniDir;
    private Workstation $workstation;

    protected function setUp(): void
    {
        parent::setUp();
        WpkgSchemaBootstrapper::bootstrap();
        $this->iniDir = sys_get_temp_dir().'/wpkg-options-tab-'.bin2hex(random_bytes(4));
        @mkdir($this->iniDir, 0755, true);
        config(['sambaedu.wpkg.ini_path' => $this->iniDir]);

        $this->workstation = Workstation::create(['name' => 'PCT1', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->iniDir)) {
            foreach (glob($this->iniDir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->iniDir);
        }
        WpkgSchemaBootstrapper::tearDown();
        parent::tearDown();
    }

    #[Test]
    public function update_option_dispatches_event_and_persists_override(): void
    {
        Event::fake([WorkstationOptionsChanged::class]);

        $svc = new WorkstationOptionsService();
        $changed = $svc->update($this->workstation->id, ['debug' => true]);

        self::assertSame(['debug'], $changed);
        self::assertSame(1, WpkgWorkstationOption::where('workstation_id', $this->workstation->id)->count());
        Event::assertDispatched(WorkstationOptionsChanged::class);
    }

    #[Test]
    public function update_triggers_ini_regeneration_via_listener(): void
    {
        // Pas de Event::fake → le listener s'exécute réellement.
        $svc = new WorkstationOptionsService();
        $svc->update($this->workstation->id, ['debug' => true]);

        $iniFile = $this->iniDir.'/PCT1.ini';
        self::assertFileExists($iniFile);
        $content = file_get_contents($iniFile);
        self::assertStringContainsString("debug=true '", $content);
        self::assertStringContainsString("force=false '", $content); // défaut non override.
    }

    #[Test]
    public function reset_options_regenerates_ini_with_all_defaults(): void
    {
        // D'abord, un override.
        WpkgWorkstationOption::create([
            'workstation_id' => $this->workstation->id,
            'option_key' => 'debug',
            'option_value' => 'true',
        ]);

        $svc = new WorkstationOptionsService();
        $svc->resetToDefaults($this->workstation->id);

        $iniFile = $this->iniDir.'/PCT1.ini';
        self::assertFileExists($iniFile);
        $content = file_get_contents($iniFile);
        self::assertStringContainsString("debug=false '", $content);
        self::assertSame(0, WpkgWorkstationOption::count());
    }
}
