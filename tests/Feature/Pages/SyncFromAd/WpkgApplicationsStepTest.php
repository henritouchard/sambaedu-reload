<?php

declare(strict_types=1);

namespace Tests\Feature\Pages\SyncFromAd;

use App\Models\Application;
use App\Models\User;
use App\Services\AppStore\AppStoreService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Traits\CreatesAppStoreSchema;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Étape « 6. Importer les applications WPKG » de la page Livewire SFC
 * `/sync-from-ad`. Vérifie le câblage : exécution via `runStep`, stats en state,
 * et ordonnancement AVANT l'étape « Importer les profils applicatifs ».
 */
class WpkgApplicationsStepTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesAppStoreSchema;
    use CreatesPermissionSchema;

    private string $storageDir;

    private string $legacyRoot;

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->createPermissionSchema();
        $this->createAppStoreSchema();
        (new PermissionSeeder())->run();

        $uniq = uniqid('wpkg_step_', true);
        $this->storageDir = sys_get_temp_dir() . '/' . $uniq . '_storage';
        $this->legacyRoot = sys_get_temp_dir() . '/' . $uniq . '_legacy';
        @mkdir($this->storageDir . '/wpkg', 0755, true);
        @mkdir($this->legacyRoot . '/wpkg', 0755, true);

        config([
            'sambaedu.wpkg.storage_path' => $this->storageDir,
            'sambaedu.wpkg.packages_xml_path' => $this->storageDir . '/wpkg/packages.xml',
            'sambaedu.wpkg.legacy_packages_xml_path' => $this->legacyRoot . '/wpkg/packages.xml',
            'sambaedu.wpkg.legacy_install_root' => $this->storageDir,
        ]);

        file_put_contents($this->legacyRoot . '/wpkg/packages.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<packages>
  <package id="firefox" name="Mozilla Firefox" revision="115.0" category2="Internet" compatibilite="7" />
</packages>
XML);

        // Isole la régénération catalogue/bundle (testée ailleurs).
        $this->mock(AppStoreService::class, function ($mock): void {
            $mock->shouldReceive('updateLocalPackagesXml')->andReturnNull();
        });
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->storageDir);
        $this->rrmdir($this->legacyRoot);
        $this->dropAppStoreSchema();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $f) {
            $path = $dir . '/' . $f;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function makeAdmin(): User
    {
        $u = User::query()->create(['login' => 'sm-admin-' . uniqid(), 'role' => 'prof', 'is_active' => true]);
        $u->givePermissionTo('server.admin');

        return $u;
    }

    public function test_step_imports_wpkg_catalog_from_legacy(): void
    {
        $this->actingAs($this->makeAdmin());

        Livewire::test('pages::sync-from-ad.index')
            ->call('runStep', 'wpkg_applications')
            ->assertSet('steps.wpkg_applications.status', 'success')
            ->assertSet('steps.wpkg_applications.stats.created', 1);

        $this->assertNotNull(Application::where('app_id', 'firefox')->first());
    }

    public function test_wpkg_applications_step_precedes_app_profiles(): void
    {
        $this->actingAs($this->makeAdmin());

        $component = Livewire::test('pages::sync-from-ad.index');
        $keys = array_keys($component->get('steps'));

        $this->assertContains('wpkg_applications', $keys);
        $this->assertContains('app_profiles', $keys);
        $this->assertLessThan(
            array_search('app_profiles', $keys, true),
            array_search('wpkg_applications', $keys, true),
            'L\'étape wpkg_applications doit précéder app_profiles.',
        );
    }
}
