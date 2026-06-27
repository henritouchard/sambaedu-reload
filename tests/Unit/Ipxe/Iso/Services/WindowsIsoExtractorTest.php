<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Iso\Services;

use App\Ipxe\Iso\Exceptions\WindowsIsoExtractionException;
use App\Ipxe\Iso\Services\WindowsIsoExtractor;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests du port natif de l'extraction ISO Windows (ex `install-win-iso.sh`).
 *
 * On `Process::fake()` le montage/copie/démontage : on vérifie la SÉQUENCE et
 * l'ÉCHAPPEMENT des commandes + le mapping d'échec → exception (exit code +
 * stage). Les écritures filesystem natives (fichier `version`, chmod) sont
 * best-effort et non assertées ici (la copie réelle exige une vraie ISO).
 *
 * Patterns de fake NON chevauchants (`sudo -n mount*` ≠ `sudo -n umount*`) →
 * indépendants de l'ordre. Depuis Laravel 12.60, `Process::fake([...])`
 * n'auto-fake PLUS les commandes non matchées (elles s'exécuteraient pour de
 * vrai) → on ajoute un catch-all `'*'` APRÈS la clé spécifique.
 */
class WindowsIsoExtractorTest extends TestCase
{
    private string $isoPath;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ipxe.iso_management.deployed_os_base_path' => '/tmp/sambaedu-test/os',
            'ipxe.iso_management.extract_timeout_seconds' => 1800,
            'sambaedu.windows_iso.extract_mount_dir'    => sys_get_temp_dir(),
        ]);

        $this->isoPath = sys_get_temp_dir() . '/se5-extractor-test-' . getmypid() . '.iso';
        file_put_contents($this->isoPath, 'FAKE-ISO');
    }

    protected function tearDown(): void
    {
        @unlink($this->isoPath);
        parent::tearDown();
    }

    #[Test]
    public function it_mounts_copies_and_unmounts_on_nominal_path(): void
    {
        Process::fake();

        (new WindowsIsoExtractor())->extract('Win11', $this->isoPath, 60);

        // Montage loop ro de l'ISO source (chemin échappé).
        Process::assertRan(fn ($p) => str_starts_with($p->command, 'sudo -n mount -o loop,ro')
            && str_contains($p->command, "'" . $this->isoPath . "'"));

        // Copie vers le dossier de version dérivé (/os/Win11) — escapeshellarg.
        Process::assertRan(fn ($p) => str_contains($p->command, 'cp -R')
            && str_contains($p->command, "'/tmp/sambaedu-test/os/Win11/'"));

        // Réappropriation www-admin + démontage.
        Process::assertRan(fn ($p) => str_contains($p->command, 'chown -R www-admin:www-admin')
            && str_contains($p->command, "'/tmp/sambaedu-test/os/Win11'"));
        Process::assertRan(fn ($p) => str_starts_with($p->command, 'sudo -n umount'));

        // Seeding des helpers WinPE (wimboot/winpeshl.ini, hors ISO) sous le
        // dossier neutre `/os/winpe` — servis ensuite par `/ipxe/os/{path}`.
        Process::assertRan(fn ($p) => str_contains($p->command, 'cp -R')
            && str_contains($p->command, "'/tmp/sambaedu-test/os/winpe/'"));
    }

    #[Test]
    public function it_throws_with_exit_code_and_stage_when_mount_fails(): void
    {
        Process::fake([
            'sudo -n mount*' => Process::result(output: '', errorOutput: 'mount: loop device unavailable', exitCode: 1),
            // Laravel 12.60 : les commandes non matchées s'exécutent réellement
            // (plus d'auto-fake) → catch-all explicite après la clé spécifique.
            '*' => Process::result(exitCode: 0),
        ]);

        try {
            (new WindowsIsoExtractor())->extract('Win11', $this->isoPath, 60);
            self::fail('Une WindowsIsoExtractionException était attendue.');
        } catch (WindowsIsoExtractionException $e) {
            self::assertSame(1, $e->exitCode);
            self::assertStringContainsString('[mount]', $e->getMessage());
            self::assertStringContainsString('loop device unavailable', $e->getMessage());
        }
    }

    #[Test]
    public function it_unmounts_even_when_copy_fails(): void
    {
        Process::fake([
            'sudo -n cp*' => Process::result(output: '', errorOutput: 'cp: no space left on device', exitCode: 5),
            // Laravel 12.60 : catch-all (mount/rm/mkdir/umount) après la clé cp.
            '*' => Process::result(exitCode: 0),
        ]);

        try {
            (new WindowsIsoExtractor())->extract('Win11', $this->isoPath, 60);
            self::fail('Une WindowsIsoExtractionException était attendue.');
        } catch (WindowsIsoExtractionException $e) {
            self::assertSame(5, $e->exitCode);
            self::assertStringContainsString('[copy]', $e->getMessage());
        }

        // Le démontage doit avoir lieu dans le `finally` malgré l'échec copie.
        Process::assertRan(fn ($p) => str_starts_with($p->command, 'sudo -n umount'));
    }

    #[Test]
    public function it_rejects_an_unknown_version_without_running_any_command(): void
    {
        Process::fake();

        try {
            (new WindowsIsoExtractor())->extract('Win99', $this->isoPath, 60);
            self::fail('Une WindowsIsoExtractionException était attendue.');
        } catch (WindowsIsoExtractionException $e) {
            self::assertSame(-1, $e->exitCode);
            self::assertStringContainsString('Win99', $e->getMessage());
        }

        Process::assertNothingRan();
    }

    #[Test]
    public function it_throws_when_source_iso_is_missing(): void
    {
        Process::fake();

        $this->expectException(WindowsIsoExtractionException::class);

        (new WindowsIsoExtractor())->extract('Win11', '/tmp/does-not-exist-' . getmypid() . '.iso', 60);
    }

    /**
     * Story 3.10 — AC6.3 — Non-régression : avec un pack de pilotes ABSENT/vide,
     * l'extraction est strictement le comportement 3.6 (aucun appel
     * `wimlib-imagex`, boot.wim stock préservé). Zéro régression pour les parcs
     * à NIC inbox.
     */
    #[Test]
    public function it_does_not_run_wimlib_when_driver_pack_is_empty(): void
    {
        Process::fake();
        config([
            'ipxe.iso_management.winpe_drivers_path' => sys_get_temp_dir() . '/se5-no-pack-' . getmypid() . '-' . uniqid(),
        ]);

        (new WindowsIsoExtractor())->extract('Win11', $this->isoPath, 60);

        // Le comportement 3.6 est inchangé : aucune commande d'injection
        // wimlib n'est lancée quand le pack est vide.
        Process::assertNotRan(fn ($p) => str_starts_with($p->command, 'wimlib-imagex'));
    }
}
