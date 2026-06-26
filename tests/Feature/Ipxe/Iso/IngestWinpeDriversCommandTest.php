<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe\Iso;

use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 3.10 — AC6.2 — Tests de la commande `ipxe:winpe-drivers:ingest`
 * (qui délègue au service partagé {@see \App\Ipxe\Iso\Services\WinpeDriverIngestor}).
 *
 * Couvre : dispatch `innoextract` vs `unzip` selon l'extension, échec sur
 * archive inconnue, échec si aucun `.inf`, message clair si binaire absent.
 * `Process::fake()` pour les chemins de dispatch ; un `.zip` RÉEL (unzip présent
 * sur l'hôte) pour le chemin nominal succès.
 */
class IngestWinpeDriversCommandTest extends TestCase
{
    private string $packPath;

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->packPath = sys_get_temp_dir() . '/se5-winpe-cmd-pack-' . getmypid() . '-' . uniqid();
        $this->workDir = sys_get_temp_dir() . '/se5-winpe-cmd-work-' . getmypid() . '-' . uniqid();
        mkdir($this->packPath, 0755, true);
        mkdir($this->workDir, 0755, true);

        config([
            'ipxe.iso_management.winpe_drivers_path'      => $this->packPath,
            'ipxe.iso_management.extract_timeout_seconds' => 60,
        ]);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->packPath);
        $this->rrmdir($this->workDir);
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

    /**
     * Construit un `.zip` réel via le binaire `zip` (l'extension PHP ZipArchive
     * n'est pas chargée sur l'hôte de test — cf. ToolsCatalogSurfaceTest).
     */
    private function makeRealZip(): string
    {
        if (\Symfony\Component\Process\ExecutableFinder::class
            && (new \Symfony\Component\Process\ExecutableFinder())->find('zip') === null) {
            self::markTestSkipped('binaire `zip` absent — chemin succès réel non testable.');
        }

        $src = $this->workDir . '/zipsrc-' . uniqid();
        mkdir($src . '/intel-i219', 0755, true);
        file_put_contents($src . '/intel-i219/e1d68x64.inf', "[Version]\nClass=Net\n");
        file_put_contents($src . '/intel-i219/e1d68x64.sys', "BIN\x00");
        file_put_contents($src . '/intel-i219/e1d68x64.cat', "CAT\x00");

        $path = $this->workDir . '/intel-pack-' . uniqid() . '.zip';
        $result = Process::path($src)->run('zip -r -q ' . escapeshellarg($path) . ' intel-i219');
        if (! $result->successful()) {
            self::markTestSkipped('création du zip de test impossible : ' . $result->errorOutput());
        }

        return $path;
    }

    #[Test]
    public function it_ingests_a_real_zip_via_unzip_and_copies_inf_files(): void
    {
        $zip = $this->makeRealZip();

        $this->artisan('ipxe:winpe-drivers:ingest', ['famille' => 'intel-i219', 'archive' => $zip])
            ->assertExitCode(0);

        self::assertFileExists($this->packPath . '/intel-i219/e1d68x64.inf');
        self::assertFileExists($this->packPath . '/intel-i219/e1d68x64.sys');
        self::assertFileExists($this->packPath . '/intel-i219/e1d68x64.cat');
    }

    #[Test]
    public function it_dispatches_unzip_for_zip_extension(): void
    {
        Process::fake();
        // Archive .zip "vide" du point de vue de l'extraction fake → aucun .inf
        // → échec, mais on assert que la commande unzip a bien été dispatchée.
        $fakeZip = $this->workDir . '/empty.zip';
        file_put_contents($fakeZip, 'PK');

        $this->artisan('ipxe:winpe-drivers:ingest', ['famille' => 'intel-i219', 'archive' => $fakeZip])
            ->assertExitCode(1);

        Process::assertRan(fn ($p) => str_starts_with($p->command, 'unzip -o -q'));
    }

    #[Test]
    public function it_dispatches_innoextract_for_exe_extension(): void
    {
        Process::fake();
        $fakeExe = $this->workDir . '/lenovo.exe';
        file_put_contents($fakeExe, 'MZ');

        $this->artisan('ipxe:winpe-drivers:ingest', ['famille' => 'lenovo-nic', 'archive' => $fakeExe])
            ->assertExitCode(1); // extraction fake → aucun .inf

        Process::assertRan(fn ($p) => str_starts_with($p->command, 'innoextract '));
    }

    #[Test]
    public function it_fails_clearly_when_innoextract_binary_is_missing(): void
    {
        Process::fake([
            'command -v*' => Process::result(output: '', errorOutput: '', exitCode: 1),
        ]);
        $fakeExe = $this->workDir . '/lenovo.exe';
        file_put_contents($fakeExe, 'MZ');

        $this->artisan('ipxe:winpe-drivers:ingest', ['famille' => 'lenovo-nic', 'archive' => $fakeExe])
            ->expectsOutputToContain('innoextract')
            ->assertExitCode(1);

        // L'extraction ne doit PAS être tentée si le binaire est absent.
        Process::assertNotRan(fn ($p) => str_starts_with($p->command, 'innoextract '));
    }

    #[Test]
    public function it_fails_on_unknown_archive_extension(): void
    {
        Process::fake();
        $fakeTar = $this->workDir . '/pack.tar';
        file_put_contents($fakeTar, 'tar');

        $this->artisan('ipxe:winpe-drivers:ingest', ['famille' => 'intel-i219', 'archive' => $fakeTar])
            ->assertExitCode(1);

        Process::assertNotRan(fn ($p) => str_starts_with($p->command, 'unzip')
            || str_starts_with($p->command, 'innoextract'));
    }

    #[Test]
    public function it_fails_when_archive_has_no_inf(): void
    {
        Process::fake(); // unzip fake → tmp reste vide → aucun .inf
        $fakeZip = $this->workDir . '/no-inf.zip';
        file_put_contents($fakeZip, 'PK');

        $this->artisan('ipxe:winpe-drivers:ingest', ['famille' => 'intel-i219', 'archive' => $fakeZip])
            ->expectsOutputToContain('.inf')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_fails_on_invalid_family_name(): void
    {
        Process::fake();
        $fakeZip = $this->workDir . '/x.zip';
        file_put_contents($fakeZip, 'PK');

        $this->artisan('ipxe:winpe-drivers:ingest', ['famille' => '../evil', 'archive' => $fakeZip])
            ->assertExitCode(1);

        Process::assertNothingRan();
    }

    /**
     * M1 (review 3.10) — path traversal `.`/`..` : la regex anti-traversal
     * bloque le `/` mais accepte `..` (= parent du pack `storage/install`), que
     * `removeDirectory()` effacerait récursivement. Doit être rejeté AVANT toute
     * suppression. `..` n'a aucun caractère alphanumérique → invalide.
     */
    #[Test]
    #[DataProvider('dotOnlyFamilyNames')]
    public function it_rejects_dot_only_family_names(string $famille): void
    {
        Process::fake();
        $fakeZip = $this->workDir . '/x.zip';
        file_put_contents($fakeZip, 'PK');

        $this->artisan('ipxe:winpe-drivers:ingest', ['famille' => $famille, 'archive' => $fakeZip])
            ->assertExitCode(1);

        Process::assertNothingRan();
    }

    /** @return array<string, array{string}> */
    public static function dotOnlyFamilyNames(): array
    {
        return [
            'parent dir'   => ['..'],
            'current dir'  => ['.'],
            'triple dot'   => ['...'],
            'dashes only'  => ['--'],
            'underscores'  => ['__'],
        ];
    }
}
