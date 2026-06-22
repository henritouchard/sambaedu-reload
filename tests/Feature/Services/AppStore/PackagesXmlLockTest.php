<?php

declare(strict_types=1);

namespace Tests\Feature\Services\AppStore;

use App\Services\AppStore\AppStoreService;
use App\Services\AppStore\PackagesXmlService;
use App\Wpkg\Deployment\Services\WpkgBundleGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesAppStoreSchema;

/**
 * Story 8.2.7 (AC8) — Sérialisation de la régénération packages.xml.
 *
 * `updateLocalPackagesXml()` enveloppe `regenerate()` + génération du bundle
 * dans un `Cache::lock(...)->block(...)`. On vérifie ici que :
 *  - le flow nominal n'est PAS cassé par l'introduction du lock (le driver
 *    cache de test `array` supporte bien lock()/block()) ;
 *  - le lock est bien relâché après l'appel (réacquisition immédiate possible)
 *    — preuve qu'aucun lock zombi ne reste accroché.
 */
class PackagesXmlLockTest extends TestCase
{
    use CreatesAppStoreSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAppStoreSchema();
        config(['cache.default' => 'array']);
        Cache::lock('appstore.packages-xml.regenerate')->forceRelease();
    }

    protected function tearDown(): void
    {
        Cache::lock('appstore.packages-xml.regenerate')->forceRelease();
        Mockery::close();
        $this->dropAppStoreSchema();
        parent::tearDown();
    }

    #[Test]
    public function update_local_packages_xml_runs_regenerate_under_lock(): void
    {
        $xmlService = Mockery::mock(PackagesXmlService::class);
        $xmlService->shouldReceive('regenerate')->once();

        $bundle = Mockery::mock(WpkgBundleGenerator::class);
        $bundle->shouldReceive('generate')->once();

        $service = new AppStoreService(
            app(\App\Services\AppStore\DepotSyncService::class),
            $xmlService,
            app(\App\Services\AppStore\PackageInstallerService::class),
            $bundle,
        );

        $service->updateLocalPackagesXml();

        // Le lock doit être libre après l'appel (pas de zombi).
        $lock = Cache::lock('appstore.packages-xml.regenerate', 5);
        self::assertTrue($lock->get(), 'Le lock packages.xml doit être relâché après updateLocalPackagesXml().');
        $lock->release();
    }

    #[Test]
    public function update_local_packages_xml_keeps_catalog_when_bundle_generation_fails(): void
    {
        // Le catch du bundle loggue sur le channel `wpkg-deploy`. Sous
        // Log::spy(), Log::channel() renvoie null (limite connue du spy) ; on
        // stub donc channel()->error() explicitement pour ce test.
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('error')->andReturnNull();

        // D4 résilience : un échec du bundle ne casse PAS l'ajout au catalogue.
        $xmlService = Mockery::mock(PackagesXmlService::class);
        $xmlService->shouldReceive('regenerate')->once();

        $bundle = Mockery::mock(WpkgBundleGenerator::class);
        $bundle->shouldReceive('generate')->once()->andThrow(new \RuntimeException('bundle KO'));

        $service = new AppStoreService(
            app(\App\Services\AppStore\DepotSyncService::class),
            $xmlService,
            app(\App\Services\AppStore\PackageInstallerService::class),
            $bundle,
        );

        // Ne doit PAS propager l'exception du bundle (catch + log wpkg-deploy).
        $service->updateLocalPackagesXml();

        // Lock relâché malgré l'échec du bundle.
        $lock = Cache::lock('appstore.packages-xml.regenerate', 5);
        self::assertTrue($lock->get());
        $lock->release();
    }
}
