<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Depot;
use App\Models\DepotApplication;
use App\Services\AppStore\DepotSyncService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DepotSyncServiceTest extends TestCase
{
    use DatabaseTransactions;

    private DepotSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DepotSyncService::class);
    }

    /** @test */
    public function it_is_instantiable(): void
    {
        $this->assertInstanceOf(DepotSyncService::class, $this->service);
    }

    /** @test */
    public function it_has_sync_depot_method(): void
    {
        $this->assertTrue(method_exists($this->service, 'syncDepot'));
    }

    /** @test */
    public function it_has_sync_all_depots_method(): void
    {
        $this->assertTrue(method_exists($this->service, 'syncAllDepots'));
    }

    /** @test */
    public function sync_depot_fetches_xml_and_upserts_applications(): void
    {
        $depot = Depot::create([
            'name' => 'Test Depot',
            'url' => 'http://test.example.com/wpkg',
            'is_primary' => true,
            'is_active' => true,
            'xml_hash' => null,
        ]);

        Http::fake([
            'test.example.com/wpkg/packages.xml' => Http::response($this->getSampleXml(), 200),
        ]);

        $result = $this->service->syncDepot($depot);

        $this->assertArrayHasKey('new', $result);
        $this->assertArrayHasKey('updated', $result);
        $this->assertEquals(2, $result['new']);

        // Verify depot_applications were created
        $this->assertEquals(2, DepotApplication::where('depot_id', $depot->id)->count());

        $app7zip = DepotApplication::where('depot_id', $depot->id)->where('app_id', '7zip')->first();
        $this->assertNotNull($app7zip);
        $this->assertEquals('7-Zip', $app7zip->name);
        $this->assertEquals('24.09', $app7zip->version);
        $this->assertEquals('Bureautique', $app7zip->category);
        $this->assertEquals('stable', $app7zip->branch);
    }

    /** @test */
    public function sync_depot_skips_unchanged_xml(): void
    {
        $xmlContent = $this->getSampleXml();

        $depot = Depot::create([
            'name' => 'Test Depot',
            'url' => 'http://test.example.com/wpkg',
            'is_primary' => true,
            'is_active' => true,
            'xml_hash' => hash('sha256', $xmlContent),
        ]);

        Http::fake([
            'test.example.com/wpkg/packages.xml' => Http::response($xmlContent, 200),
        ]);

        $result = $this->service->syncDepot($depot);

        $this->assertEquals(0, $result['new']);
        $this->assertEquals(0, $result['updated']);
    }

    /** @test */
    public function sync_depot_throws_on_http_error(): void
    {
        $depot = Depot::create([
            'name' => 'Bad Depot',
            'url' => 'http://bad.example.com/wpkg',
            'is_primary' => false,
            'is_active' => true,
            'xml_hash' => null,
        ]);

        Http::fake([
            'bad.example.com/wpkg/packages.xml' => Http::response('', 500),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->service->syncDepot($depot);
    }

    /** @test */
    public function sync_all_depots_collects_stats(): void
    {
        Depot::query()->delete();

        $depot = Depot::create([
            'name' => 'Active Depot',
            'url' => 'http://test.example.com/wpkg',
            'is_primary' => true,
            'is_active' => true,
            'xml_hash' => null,
        ]);

        Http::fake([
            'test.example.com/wpkg/packages.xml' => Http::response($this->getSampleXml(), 200),
        ]);

        $stats = $this->service->syncAllDepots();

        $this->assertArrayHasKey('synced', $stats);
        $this->assertArrayHasKey('new', $stats);
        $this->assertArrayHasKey('updated', $stats);
        $this->assertArrayHasKey('errors', $stats);
        $this->assertEquals(1, $stats['synced']);
        $this->assertEmpty($stats['errors']);
    }

    /** @test */
    public function sync_all_depots_collects_errors_without_throwing(): void
    {
        Depot::query()->delete();

        Depot::create([
            'name' => 'Bad Depot',
            'url' => 'http://bad.example.com/wpkg',
            'is_primary' => false,
            'is_active' => true,
            'xml_hash' => null,
        ]);

        Http::fake([
            'bad.example.com/wpkg/packages.xml' => Http::response('', 500),
        ]);

        $stats = $this->service->syncAllDepots();

        $this->assertEquals(0, $stats['synced']);
        $this->assertNotEmpty($stats['errors']);
    }

    /** @test */
    public function extract_icon_url_from_attribute(): void
    {
        $method = new \ReflectionMethod(DepotSyncService::class, 'extractIconUrl');

        $depot = new Depot(['url' => 'http://depot.example.com/wpkg']);

        $xml = new \DOMDocument();
        $xml->loadXML('<package icon="http://cdn.example.com/icon.png"/>');

        $result = $method->invoke($this->service, $depot, $xml->documentElement);
        $this->assertEquals('http://cdn.example.com/icon.png', $result);
    }

    /** @test */
    public function extract_icon_url_builds_relative(): void
    {
        $method = new \ReflectionMethod(DepotSyncService::class, 'extractIconUrl');

        $depot = new Depot(['url' => 'http://depot.example.com/wpkg']);

        $xml = new \DOMDocument();
        $xml->loadXML('<package icon="icons/test.png"/>');

        $result = $method->invoke($this->service, $depot, $xml->documentElement);
        $this->assertEquals('http://depot.example.com/wpkg/icons/test.png', $result);
    }

    /** @test */
    public function extract_icon_url_convention_from_app_id(): void
    {
        $method = new \ReflectionMethod(DepotSyncService::class, 'extractIconUrl');

        $depot = new Depot(['url' => 'http://depot.example.com/wpkg']);

        $xml = new \DOMDocument();
        $xml->loadXML('<package id="firefox"/>');

        $result = $method->invoke($this->service, $depot, $xml->documentElement);
        $this->assertEquals('http://depot.example.com/wpkg/firefox/icon.png', $result);
    }

    private function getSampleXml(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<packages>
    <branch id="stable">
        <package id="7zip"
            name="7-Zip"
            revision="24.09"
            category="Bureautique"
            compatibilite="7"
            url="http://test.example.com/wpkg/stable/7zip.xml"
            hash="abc123def456"/>
        <package id="vlc"
            name="VLC Media Player"
            revision="3.0.20"
            category="Multimedia"
            compatibilite="7"
            url="http://test.example.com/wpkg/stable/vlc.xml"
            hash="def789ghi012"/>
    </branch>
</packages>
XML;
    }
}
