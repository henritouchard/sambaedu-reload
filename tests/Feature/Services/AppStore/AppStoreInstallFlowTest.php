<?php

declare(strict_types=1);

namespace Tests\Feature\Services\AppStore;

use App\Enums\ApplicationStatus;
use App\Enums\InstallationStatus;
use App\Models\Application;
use App\Models\Depot;
use App\Models\DepotApplication;
use App\Models\InstallationLog;
use App\Services\AppStore\AppStoreService;
use App\Services\AppStore\PackagesXmlService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests d'intégration pour le flow complet d'installation (Story 8.2.6)
 *
 * Couvre : download XML recipe → vérif hash → download fichiers →
 * post-traitement → finalisation → packages.xml → nettoyage tmp2/.
 *
 * Convention :
 * - DatabaseTransactions (rollback auto après chaque test)
 * - Http::fake() pour les appels HTTP
 * - Filesystem réel dans sys_get_temp_dir() (nettoyé dans tearDown)
 */
class AppStoreInstallFlowTest extends TestCase
{
    use DatabaseTransactions;

    private string $tmpDir;
    private Depot $depot;
    private DepotApplication $depotApp;

    protected function setUp(): void
    {
        parent::setUp();

        // Répertoire temporaire isolé pour ce test
        $this->tmpDir = sys_get_temp_dir() . '/appstore_flow_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        mkdir($this->tmpDir . '/wpkg/tmp2', 0755, true);

        // Pointer la config WPKG vers notre tmpDir
        config(['sambaedu.wpkg.storage_path' => $this->tmpDir]);
        config(['sambaedu.wpkg.sync_timeout' => 5]);
        config(['sambaedu.wpkg.download_timeout' => 10]);

        // Désactiver le Log pour ne pas polluer la sortie
        Log::spy();

        // Créer un dépôt et une application de dépôt pour les tests
        $this->depot = Depot::create([
            'name' => 'Test Depot',
            'url' => 'http://test.example.com/wpkg',
            'is_primary' => false,
            'is_active' => true,
        ]);

        $this->depotApp = DepotApplication::create([
            'depot_id' => $this->depot->id,
            'app_id' => 'test-app-' . uniqid(),
            'name' => 'Test Application',
            'version' => '1.0.0',
            'category' => 'Test',
            'compatibility' => '10',
            'branch' => 'stable',
            'xml_url' => 'http://test.example.com/wpkg/stable/test-app.xml',
            'xml_sha' => null, // Hash vérifié séparément
            'log_url' => null,
            'icon_url' => null,
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanDir($this->tmpDir);
        Mockery::close();
        parent::tearDown();
    }

    private function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->cleanDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Construit un XML recipe minimal valide
     */
    private function buildXmlRecipe(string $appId = 'test-app', string $fileUrl = ''): string
    {
        $downloadBlock = $fileUrl
            ? "<download url=\"{$fileUrl}\" saveto=\"wpkg/packages/{$appId}/setup.exe\" sha256sum=\"\" md5sum=\"\"/>"
            : '';

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<packages>
    <package id="{$appId}" name="Test App" revision="1.0.0">
        {$downloadBlock}
    </package>
</packages>
XML;
    }

    // ========================================
    // Tests : flow succès
    // ========================================

    #[Test]
    public function install_flow_sets_application_status_to_installed(): void
    {
        $xmlContent = $this->buildXmlRecipe($this->depotApp->app_id);

        Http::fake([
            $this->depotApp->xml_url => Http::response($xmlContent, 200),
        ]);

        // Mocker PackagesXmlService pour éviter l'accès au vrai packages.xml
        $this->mock(PackagesXmlService::class, function ($mock) {
            $mock->shouldReceive('regenerate')->once();
        });

        $service = app(AppStoreService::class);
        $log = $service->installApplication($this->depotApp, 'test-user');

        $application = Application::where('app_id', $this->depotApp->app_id)->first();

        $this->assertNotNull($application);
        $this->assertEquals(ApplicationStatus::Installed, $application->status);
    }

    #[Test]
    public function install_flow_sets_installed_at(): void
    {
        $xmlContent = $this->buildXmlRecipe($this->depotApp->app_id);

        Http::fake([
            $this->depotApp->xml_url => Http::response($xmlContent, 200),
        ]);

        $this->mock(PackagesXmlService::class, function ($mock) {
            $mock->shouldReceive('regenerate')->once();
        });

        $before = now()->subSecond();
        $service = app(AppStoreService::class);
        $service->installApplication($this->depotApp, 'test-user');

        $application = Application::where('app_id', $this->depotApp->app_id)->first();

        $this->assertNotNull($application->installed_at);
        $this->assertTrue($application->installed_at->gte($before));
    }

    #[Test]
    public function install_flow_sets_installed_version(): void
    {
        $xmlContent = $this->buildXmlRecipe($this->depotApp->app_id);

        Http::fake([
            $this->depotApp->xml_url => Http::response($xmlContent, 200),
        ]);

        $this->mock(PackagesXmlService::class, function ($mock) {
            $mock->shouldReceive('regenerate')->once();
        });

        $service = app(AppStoreService::class);
        $service->installApplication($this->depotApp, 'test-user');

        $application = Application::where('app_id', $this->depotApp->app_id)->first();

        $this->assertEquals($this->depotApp->version, $application->installed_version);
    }

    #[Test]
    public function install_flow_stores_xml_content_in_application(): void
    {
        $xmlContent = $this->buildXmlRecipe($this->depotApp->app_id);

        Http::fake([
            $this->depotApp->xml_url => Http::response($xmlContent, 200),
        ]);

        $this->mock(PackagesXmlService::class, function ($mock) {
            $mock->shouldReceive('regenerate')->once();
        });

        $service = app(AppStoreService::class);
        $service->installApplication($this->depotApp, 'test-user');

        $application = Application::where('app_id', $this->depotApp->app_id)->first();

        $this->assertNotNull($application->xml);
        $this->assertStringContainsString($this->depotApp->app_id, $application->xml);
    }

    #[Test]
    public function install_flow_sets_local_xml_path_to_null_after_cleanup(): void
    {
        $xmlContent = $this->buildXmlRecipe($this->depotApp->app_id);

        Http::fake([
            $this->depotApp->xml_url => Http::response($xmlContent, 200),
        ]);

        $this->mock(PackagesXmlService::class, function ($mock) {
            $mock->shouldReceive('regenerate')->once();
        });

        $service = app(AppStoreService::class);
        $service->installApplication($this->depotApp, 'test-user');

        $application = Application::where('app_id', $this->depotApp->app_id)->first();

        // local_xml_path doit être null — le XML recipe temporaire est supprimé après succès
        $this->assertNull($application->local_xml_path);
    }

    #[Test]
    public function install_flow_cleans_up_tmp2_xml_recipe_after_success(): void
    {
        $xmlContent = $this->buildXmlRecipe($this->depotApp->app_id);

        Http::fake([
            $this->depotApp->xml_url => Http::response($xmlContent, 200),
        ]);

        $this->mock(PackagesXmlService::class, function ($mock) {
            $mock->shouldReceive('regenerate')->once();
        });

        $service = app(AppStoreService::class);
        $service->installApplication($this->depotApp, 'test-user');

        // Vérifier que tmp2/ ne contient plus de fichier XML recipe
        $tmp2Dir = $this->tmpDir . '/wpkg/tmp2';
        $xmlFiles = glob($tmp2Dir . '/*.xml');

        $this->assertEmpty($xmlFiles, 'Le XML recipe temporaire doit être supprimé de tmp2/ après succès');
    }

    #[Test]
    public function install_flow_returns_success_log(): void
    {
        $xmlContent = $this->buildXmlRecipe($this->depotApp->app_id);

        Http::fake([
            $this->depotApp->xml_url => Http::response($xmlContent, 200),
        ]);

        $this->mock(PackagesXmlService::class, function ($mock) {
            $mock->shouldReceive('regenerate')->once();
        });

        $service = app(AppStoreService::class);
        $log = $service->installApplication($this->depotApp, 'test-user');

        $this->assertInstanceOf(InstallationLog::class, $log);
        $this->assertEquals(InstallationStatus::Success, $log->fresh()->status);
        $this->assertEquals(100, $log->fresh()->progress);
        $this->assertNotNull($log->fresh()->completed_at);
    }

    #[Test]
    public function install_flow_calls_regenerate_packages_xml(): void
    {
        $xmlContent = $this->buildXmlRecipe($this->depotApp->app_id);

        Http::fake([
            $this->depotApp->xml_url => Http::response($xmlContent, 200),
        ]);

        $mockXmlService = $this->mock(PackagesXmlService::class, function ($mock) {
            $mock->shouldReceive('regenerate')->once();
        });

        $service = app(AppStoreService::class);
        $service->installApplication($this->depotApp, 'test-user');

        // L'assertion est portée par ->once() dans le mock
        $this->assertTrue(true);
    }

    // ========================================
    // Tests : flow erreur
    // ========================================

    #[Test]
    public function install_flow_on_download_error_sets_application_status_to_error(): void
    {
        Http::fake([
            $this->depotApp->xml_url => Http::response('Server Error', 500),
        ]);

        $service = app(AppStoreService::class);

        try {
            $service->installApplication($this->depotApp, 'test-user');
            $this->fail('Une exception devrait être levée');
        } catch (\Exception $e) {
            // Exception attendue
        }

        $application = Application::where('app_id', $this->depotApp->app_id)->first();

        $this->assertNotNull($application);
        $this->assertEquals(ApplicationStatus::Error, $application->status);
    }

    #[Test]
    public function install_flow_on_download_error_sets_log_status_to_failed(): void
    {
        Http::fake([
            $this->depotApp->xml_url => Http::response('Server Error', 500),
        ]);

        $service = app(AppStoreService::class);

        try {
            $service->installApplication($this->depotApp, 'test-user');
            $this->fail('Une exception devrait être levée');
        } catch (\Exception $e) {
            // Exception attendue
        }

        $log = InstallationLog::whereHas('application', fn ($q) => $q->where('app_id', $this->depotApp->app_id))
            ->latest()
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals(InstallationStatus::Failed, $log->status);
        $this->assertNotNull($log->message);
        $this->assertStringContainsString('Erreur', $log->message);
    }

    #[Test]
    public function install_flow_on_error_preserves_tmp2_files_for_diagnosis(): void
    {
        Http::fake([
            $this->depotApp->xml_url => Http::response('not-valid-xml-at-all!!!', 200),
        ]);

        $service = app(AppStoreService::class);

        try {
            $service->installApplication($this->depotApp, 'test-user');
        } catch (\Exception $e) {
            // Exception attendue (XML invalide)
        }

        // Le XML a été téléchargé (HTTP 200) mais parseDirectives échoue (XML invalide)
        // Les fichiers tmp2/ doivent persister pour diagnostic
        $tmp2Dir = $this->tmpDir . '/wpkg/tmp2';
        $xmlFiles = glob($tmp2Dir . '/*.xml');
        $this->assertNotEmpty($xmlFiles, 'Les fichiers tmp2/ doivent persister en cas d\'erreur pour diagnostic');
    }

    // ========================================
    // Tests : suppression code mort (8.2.6 AC:5)
    // ========================================

    #[Test]
    public function app_store_service_does_not_have_download_package_xml_method(): void
    {
        $service = app(AppStoreService::class);
        $this->assertFalse(method_exists($service, 'downloadPackageXml'));
    }

    #[Test]
    public function install_flow_with_valid_sha_hash_succeeds(): void
    {
        $xmlContent = $this->buildXmlRecipe($this->depotApp->app_id);
        $sha512 = hash('sha512', $xmlContent);

        $this->depotApp->update(['xml_sha' => $sha512]);

        Http::fake([
            $this->depotApp->xml_url => Http::response($xmlContent, 200),
        ]);

        $this->mock(PackagesXmlService::class, function ($mock) {
            $mock->shouldReceive('regenerate')->once();
        });

        $service = app(AppStoreService::class);
        $log = $service->installApplication($this->depotApp, 'test-user');

        $application = Application::where('app_id', $this->depotApp->app_id)->first();

        $this->assertEquals(ApplicationStatus::Installed, $application->status);
        $this->assertEquals(InstallationStatus::Success, $log->fresh()->status);
    }
}
