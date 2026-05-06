<?php

declare(strict_types=1);

namespace Tests\Feature\Wpkg\Deployment\Listeners;

use App\Models\Workstation;
use App\Wpkg\Deployment\Events\WorkstationOptionsChanged;
use App\Wpkg\Deployment\Models\WpkgWorkstationOption;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 15.2 / AC4.3, AC7.5 — Listener regen `.ini` sur WorkstationOptionsChanged.
 */
class RegenerateWorkstationIniOnOptionsChangedTest extends TestCase
{
    private string $iniDir;

    protected function setUp(): void
    {
        parent::setUp();
        WpkgSchemaBootstrapper::bootstrap();
        $this->iniDir = sys_get_temp_dir() . '/wpkg-ini-listener-' . bin2hex(random_bytes(4));
        @mkdir($this->iniDir, 0755, true);
        config(['sambaedu.wpkg.ini_path' => $this->iniDir]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->iniDir)) {
            foreach (glob($this->iniDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->iniDir);
        }
        WpkgSchemaBootstrapper::tearDown();
        parent::tearDown();
    }

    #[Test]
    public function dispatching_options_changed_regenerates_ini_with_new_value(): void
    {
        $w = Workstation::create(['name' => 'PCRegen', 'status' => 'active']);

        // 1er dispatch : pas d'override, valeur par défaut
        event(new WorkstationOptionsChanged($w->id, ['debug']));
        $first = (string) file_get_contents($this->iniDir . '/PCRegen.ini');
        self::assertStringContainsString("debug=false ' ", $first);

        // On modifie l'option en BDD
        WpkgWorkstationOption::create([
            'workstation_id' => $w->id,
            'option_key' => 'debug',
            'option_value' => 'true',
        ]);

        // 2e dispatch : reflet du changement
        event(new WorkstationOptionsChanged($w->id, ['debug']));
        $second = (string) file_get_contents($this->iniDir . '/PCRegen.ini');
        self::assertStringContainsString("debug=true ' ", $second);
        self::assertNotSame($first, $second);
    }
}
