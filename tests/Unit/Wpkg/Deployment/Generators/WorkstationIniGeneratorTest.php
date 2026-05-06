<?php

declare(strict_types=1);

namespace Tests\Unit\Wpkg\Deployment\Generators;

use App\Models\Workstation;
use App\Wpkg\Deployment\Generators\WorkstationIniGenerator;
use App\Wpkg\Deployment\Models\WpkgWorkstationOption;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 15.2 / AC5.3-AC5.7 — Tests unit du `WorkstationIniGenerator`.
 */
class WorkstationIniGeneratorTest extends TestCase
{
    private string $iniDir;

    protected function setUp(): void
    {
        parent::setUp();
        WpkgSchemaBootstrapper::bootstrap();
        $this->iniDir = sys_get_temp_dir() . '/wpkg-ini-test-' . bin2hex(random_bytes(4));
        @mkdir($this->iniDir, 0755, true);
        config(['sambaedu.wpkg.ini_path' => $this->iniDir]);
    }

    protected function tearDown(): void
    {
        // Clean files
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
    public function defaults_are_all_false_when_no_overrides(): void
    {
        $w = Workstation::create(['name' => 'PCT1', 'status' => 'active']);
        $generator = new WorkstationIniGenerator();
        $w->load('wpkgOptions');

        $content = $generator->renderContent($w);

        // 8 lignes, 8 \r\n
        self::assertSame(8, substr_count($content, "\r\n"));
        // Toutes les lignes finissent en =false
        foreach (WorkstationIniGenerator::LEGACY_OPTIONS as $opt) {
            self::assertStringContainsString(
                sprintf("%s=false ' %s\r\n", $opt['name'], $opt['description']),
                $content,
            );
        }
    }

    #[Test]
    public function override_via_wpkg_workstation_options_takes_precedence(): void
    {
        $w = Workstation::create(['name' => 'PCT1', 'status' => 'active']);
        WpkgWorkstationOption::create([
            'workstation_id' => $w->id,
            'option_key' => 'debug',
            'option_value' => 'true',
        ]);

        $generator = new WorkstationIniGenerator();
        $w->load('wpkgOptions');

        $content = $generator->renderContent($w);

        self::assertStringContainsString("debug=true ' Permet d'avoir des logs plus détaillés.\r\n", $content);
        self::assertStringContainsString("logdebug=false", $content);
    }

    #[Test]
    public function generate_writes_atomic_file_at_hostname_ini(): void
    {
        $w = Workstation::create(['name' => 'PCT1', 'status' => 'active']);
        $generator = new WorkstationIniGenerator();
        $w->load('wpkgOptions');

        $ok = $generator->generate($w);

        self::assertTrue($ok);
        $expectedPath = $this->iniDir . '/PCT1.ini';
        self::assertFileExists($expectedPath);
        $content = (string) file_get_contents($expectedPath);
        self::assertSame(8, substr_count($content, "\r\n"));
        // Première ligne = debug=false
        self::assertStringStartsWith("debug=false ' ", $content);
    }

    #[Test]
    public function idempotent_byte_for_byte_on_consecutive_generates(): void
    {
        $w = Workstation::create(['name' => 'PCT1', 'status' => 'active']);
        WpkgWorkstationOption::create([
            'workstation_id' => $w->id,
            'option_key' => 'force',
            'option_value' => 'true',
        ]);
        $generator = new WorkstationIniGenerator();
        $w->load('wpkgOptions');

        $generator->generate($w);
        $first = (string) file_get_contents($this->iniDir . '/PCT1.ini');

        $generator->generate($w);
        $second = (string) file_get_contents($this->iniDir . '/PCT1.ini');

        self::assertSame($first, $second, 'Generator must be byte-identical between consecutive calls');
        self::assertSame(8, substr_count($first, "\r\n"));
    }
}
