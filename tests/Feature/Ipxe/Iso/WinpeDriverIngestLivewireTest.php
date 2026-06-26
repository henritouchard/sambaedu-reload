<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe\Iso;

use App\Ipxe\Iso\Services\WindowsIsoSourcesReader;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;
use Tests\Traits\CreatesWindowsIsoSchema;

/**
 * Story 3.10 — AC4.4 / AC6.2b — Upload UI Livewire des pilotes NIC.
 *
 * Le composant délègue à la MÊME logique d'ingestion que la commande artisan
 * (service partagé {@see \App\Ipxe\Iso\Services\WinpeDriverIngestor}). On teste
 * la surface : succès via `.zip` réel (unzip présent sur l'hôte), erreurs
 * (extension inconnue, famille invalide, aucun `.inf`) → toast. On vérifie
 * aussi les invariants [[project_livewire_reserved_upload_method]] : l'action
 * s'appelle `ingestDrivers` (PAS `upload`) et le composant n'appelle JAMAIS
 * `move()` sur le fichier uploadé.
 */
class WinpeDriverIngestLivewireTest extends TestCase
{
    use CreatesPermissionSchema;
    use CreatesWindowsIsoSchema;
    use DatabaseTransactions;

    private const COMPONENT = 'pages::admin.ipxe.iso-windows.index';

    private string $packPath;

    private string $workDir;

    private string $deployBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPermissionSchema();
        $this->createWindowsIsoSchema();
        (new PermissionSeeder())->run();

        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->packPath = sys_get_temp_dir() . '/se5-winpe-lw-pack-' . getmypid() . '-' . uniqid();
        $this->workDir = sys_get_temp_dir() . '/se5-winpe-lw-work-' . getmypid() . '-' . uniqid();
        $this->deployBase = sys_get_temp_dir() . '/se5-winpe-lw-os-' . getmypid() . '-' . uniqid();
        mkdir($this->packPath, 0755, true);
        mkdir($this->workDir, 0755, true);
        mkdir($this->deployBase, 0755, true);

        config([
            'ipxe.iso_management.winpe_drivers_path'      => $this->packPath,
            'ipxe.iso_management.deployed_os_base_path'   => $this->deployBase,
            'ipxe.iso_management.extract_timeout_seconds' => 60,
        ]);

        $this->app->singleton(WindowsIsoSourcesReader::class, fn () => new WindowsIsoSourcesReader(new Filesystem()));
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->packPath);
        $this->rrmdir($this->workDir);
        $this->rrmdir($this->deployBase);
        $this->dropWindowsIsoSchema();
        $this->dropPermissionSchema();
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

    private function makeAdmin(): User
    {
        $u = User::query()->create([
            'login'     => 'admin-winpe-' . uniqid(),
            'role'      => 'prof',
            'is_active' => true,
        ]);
        $u->givePermissionTo('server.admin');

        return $u;
    }

    private function requireZipBinary(): void
    {
        if ((new ExecutableFinder())->find('zip') === null) {
            self::markTestSkipped('binaire `zip` absent — ingestion réelle non testable.');
        }
    }

    /**
     * Construit un `.zip` réel via le binaire `zip` (l'extension PHP ZipArchive
     * n'est pas chargée sur l'hôte de test) et le renvoie en `UploadedFile`.
     *
     * @param  array<string, string>  $entries  chemin relatif → contenu
     */
    private function makeUploadedZip(string $name, array $entries): UploadedFile
    {
        $this->requireZipBinary();

        $src = $this->workDir . '/zsrc-' . uniqid();
        mkdir($src, 0755, true);
        $tops = [];
        foreach ($entries as $rel => $content) {
            $full = $src . '/' . $rel;
            @mkdir(dirname($full), 0755, true);
            file_put_contents($full, $content);
            $tops[explode('/', $rel)[0]] = true;
        }

        $path = $this->workDir . '/' . $name;
        $result = Process::path($src)->run('zip -r -q ' . escapeshellarg($path) . ' ' . implode(' ', array_map('escapeshellarg', array_keys($tops))));
        if (! $result->successful()) {
            self::markTestSkipped('création du zip de test impossible : ' . $result->errorOutput());
        }

        return UploadedFile::fake()->createWithContent($name, (string) file_get_contents($path));
    }

    private function zipWithInf(): UploadedFile
    {
        return $this->makeUploadedZip('intel-i219.zip', [
            'intel-i219/e1d68x64.inf' => "[Version]\nClass=Net\n",
            'intel-i219/e1d68x64.sys' => "BIN\x00",
            'intel-i219/e1d68x64.cat' => "CAT\x00",
        ]);
    }

    private function zipWithoutInf(): UploadedFile
    {
        return $this->makeUploadedZip('noinf.zip', [
            'readme.txt' => 'no driver here',
        ]);
    }

    #[Test]
    public function it_ingests_a_zip_through_the_shared_service_and_toasts_success(): void
    {
        $this->actingAs($this->makeAdmin());

        Livewire::test(self::COMPONENT)
            ->set('driverFamily', 'intel-i219')
            ->set('driverArchive', $this->zipWithInf())
            ->call('ingestDrivers')
            ->assertDispatched('toastMagic', status: 'success');

        self::assertFileExists($this->packPath . '/intel-i219/e1d68x64.inf');
    }

    #[Test]
    public function it_lists_present_families_read_only(): void
    {
        // Pré-seed une famille dans le pack.
        mkdir($this->packPath . '/intel-i219', 0755, true);
        file_put_contents($this->packPath . '/intel-i219/x.inf', "[Version]\n");

        $this->actingAs($this->makeAdmin());

        Livewire::test(self::COMPONENT)
            ->assertSet('driverFamilies', ['intel-i219' => 1])
            ->assertSee('intel-i219');
    }

    #[Test]
    public function it_rejects_an_unknown_archive_extension_with_a_toast(): void
    {
        $this->actingAs($this->makeAdmin());

        Livewire::test(self::COMPONENT)
            ->set('driverFamily', 'intel-i219')
            ->set('driverArchive', UploadedFile::fake()->create('drivers.txt', 10))
            ->call('ingestDrivers')
            ->assertDispatched('toastMagic', status: 'error');

        self::assertFalse(is_dir($this->packPath . '/intel-i219'));
    }

    #[Test]
    public function it_validates_the_family_name(): void
    {
        $this->actingAs($this->makeAdmin());

        Livewire::test(self::COMPONENT)
            ->set('driverFamily', '../evil')
            ->set('driverArchive', $this->zipWithInf())
            ->call('ingestDrivers')
            ->assertHasErrors(['driverFamily']);
    }

    #[Test]
    public function it_toasts_error_when_zip_has_no_inf(): void
    {
        $this->actingAs($this->makeAdmin());

        Livewire::test(self::COMPONENT)
            ->set('driverFamily', 'intel-i219')
            ->set('driverArchive', $this->zipWithoutInf())
            ->call('ingestDrivers')
            ->assertDispatched('toastMagic', status: 'error');

        self::assertFalse(is_dir($this->packPath . '/intel-i219'));
    }

    #[Test]
    public function the_component_uses_ingest_drivers_action_and_never_moves_the_upload(): void
    {
        $source = (string) file_get_contents(
            __DIR__ . '/../../../../resources/views/pages/admin/ipxe/iso-windows/index.blade.php',
        );

        // Invariants project_livewire_reserved_upload_method.
        self::assertStringContainsString('public function ingestDrivers(', $source);
        self::assertStringNotContainsString('public function upload(', $source);
        self::assertStringContainsString('->getRealPath()', $source);
        self::assertStringNotContainsString('->move(', $source);
    }

    /**
     * #3 (review 3.10) — AC4.4 « gate server.admin effective » : un utilisateur
     * sans la permission ne peut même pas monter le composant (abort 403 dans
     * mount()), donc ne peut pas atteindre `ingestDrivers`. Preuve par test
     * négatif (la couverture partait toujours d'un admin auparavant).
     */
    #[Test]
    public function it_forbids_the_component_for_a_non_admin(): void
    {
        $user = User::query()->create([
            'login'     => 'eleve-winpe-' . uniqid(),
            'role'      => 'eleve',
            'is_active' => true,
        ]);
        $this->actingAs($user);

        // mount() fait `abort_unless(...can('server.admin'))` → HttpException 403.
        // `withoutExceptionHandling()` empêche le handler de RENDRE la page
        // d'erreur (qui échouerait sur le Vite manifest absent de l'hôte) et
        // laisse l'HttpException remonter brute.
        $this->withoutExceptionHandling();

        try {
            Livewire::test(self::COMPONENT);
            self::fail('Le composant aurait dû abort 403 pour un non-admin.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->getStatusCode());
        }
    }
}
