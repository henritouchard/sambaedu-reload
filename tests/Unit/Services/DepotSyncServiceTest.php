<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Depot;
use App\Models\DepotApplication;
use App\Services\AppStore\DepotSyncService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\CreatesAppStoreSchema;
use PHPUnit\Framework\Attributes\Test;

class DepotSyncServiceTest extends TestCase
{
    use CreatesAppStoreSchema;

    private DepotSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAppStoreSchema();
        $this->service = app(DepotSyncService::class);
    }

    protected function tearDown(): void
    {
        $this->dropAppStoreSchema();
        parent::tearDown();
    }

    #[Test]
    public function it_is_instantiable(): void
    {
        $this->assertInstanceOf(DepotSyncService::class, $this->service);
    }

    #[Test]
    public function it_has_sync_depot_method(): void
    {
        $this->assertTrue(method_exists($this->service, 'syncDepot'));
    }

    #[Test]
    public function it_has_sync_all_depots_method(): void
    {
        $this->assertTrue(method_exists($this->service, 'syncAllDepots'));
    }

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
    public function extract_icon_url_from_attribute(): void
    {
        $method = new \ReflectionMethod(DepotSyncService::class, 'extractIconUrl');

        $depot = new Depot(['url' => 'http://depot.example.com/wpkg']);

        $xml = new \DOMDocument();
        $xml->loadXML('<package icon="http://cdn.example.com/icon.png"/>');

        $result = $method->invoke($this->service, $depot, $xml->documentElement);
        $this->assertEquals('http://cdn.example.com/icon.png', $result);
    }

    #[Test]
    public function extract_icon_url_builds_relative(): void
    {
        $method = new \ReflectionMethod(DepotSyncService::class, 'extractIconUrl');

        $depot = new Depot(['url' => 'http://depot.example.com/wpkg']);

        $xml = new \DOMDocument();
        $xml->loadXML('<package icon="icons/test.png"/>');

        $result = $method->invoke($this->service, $depot, $xml->documentElement);
        $this->assertEquals('http://depot.example.com/wpkg/icons/test.png', $result);
    }

    #[Test]
    public function extract_icon_url_convention_from_app_id(): void
    {
        $method = new \ReflectionMethod(DepotSyncService::class, 'extractIconUrl');

        $depot = new Depot(['url' => 'http://depot.example.com/wpkg']);

        $xml = new \DOMDocument();
        $xml->loadXML('<package id="firefox"/>');

        $result = $method->invoke($this->service, $depot, $xml->documentElement);
        $this->assertEquals('http://depot.example.com/wpkg/firefox/icon.png', $result);
    }

    #[Test]
    public function sync_depot_purges_removed_applications(): void
    {
        $depot = Depot::create([
            'name' => 'Purge Depot',
            'url' => 'http://test.example.com/wpkg',
            'is_primary' => true,
            'is_active' => true,
            'xml_hash' => null,
        ]);

        // Pre-create 3 depot applications
        DepotApplication::create(['depot_id' => $depot->id, 'app_id' => '7zip', 'name' => '7-Zip', 'branch' => 'stable']);
        DepotApplication::create(['depot_id' => $depot->id, 'app_id' => 'vlc', 'name' => 'VLC', 'branch' => 'stable']);
        DepotApplication::create(['depot_id' => $depot->id, 'app_id' => 'obsolete-app', 'name' => 'Obsolete', 'branch' => 'stable']);

        // XML distant ne contient que 7zip et vlc
        Http::fake([
            'test.example.com/wpkg/packages.xml' => Http::response($this->getSampleXml(), 200),
        ]);

        $result = $this->service->syncDepot($depot);

        $this->assertEquals(1, $result['purged']);
        $this->assertNull(DepotApplication::where('depot_id', $depot->id)->where('app_id', 'obsolete-app')->first());
        $this->assertEquals(2, DepotApplication::where('depot_id', $depot->id)->count());
    }

    #[Test]
    public function sync_depot_purges_by_branch(): void
    {
        $depot = Depot::create([
            'name' => 'Branch Depot',
            'url' => 'http://test.example.com/wpkg',
            'is_primary' => true,
            'is_active' => true,
            'xml_hash' => null,
        ]);

        // App exists in both stable and testing branches
        DepotApplication::create(['depot_id' => $depot->id, 'app_id' => '7zip', 'name' => '7-Zip', 'branch' => 'stable']);
        DepotApplication::create(['depot_id' => $depot->id, 'app_id' => '7zip', 'name' => '7-Zip', 'branch' => 'testing']);

        // XML only has stable branch
        Http::fake([
            'test.example.com/wpkg/packages.xml' => Http::response($this->getSampleXml(), 200),
        ]);

        $result = $this->service->syncDepot($depot);

        // testing branch entry should be purged
        $this->assertNull(
            DepotApplication::where('depot_id', $depot->id)->where('app_id', '7zip')->where('branch', 'testing')->first()
        );
        // stable branch entry should remain
        $this->assertNotNull(
            DepotApplication::where('depot_id', $depot->id)->where('app_id', '7zip')->where('branch', 'stable')->first()
        );
    }

    #[Test]
    public function sync_depot_does_not_purge_on_parse_failure(): void
    {
        $depot = Depot::create([
            'name' => 'Invalid Depot',
            'url' => 'http://test.example.com/wpkg',
            'is_primary' => true,
            'is_active' => true,
            'xml_hash' => null,
        ]);

        DepotApplication::create(['depot_id' => $depot->id, 'app_id' => 'existing', 'name' => 'Existing', 'branch' => 'stable']);

        Http::fake([
            'test.example.com/wpkg/packages.xml' => Http::response('<<<INVALID XML>>>', 200),
        ]);

        try {
            $this->service->syncDepot($depot);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            // Expected
        }

        // Existing application should still be there
        $this->assertEquals(1, DepotApplication::where('depot_id', $depot->id)->count());
    }

    #[Test]
    public function sync_depot_zero_purge_when_all_present(): void
    {
        $depot = Depot::create([
            'name' => 'Full Depot',
            'url' => 'http://test.example.com/wpkg',
            'is_primary' => true,
            'is_active' => true,
            'xml_hash' => null,
        ]);

        Http::fake([
            'test.example.com/wpkg/packages.xml' => Http::response($this->getSampleXml(), 200),
        ]);

        $result = $this->service->syncDepot($depot);
        $this->assertEquals(0, $result['purged']);
    }

    #[Test]
    public function sync_depot_skips_purge_when_xml_has_no_valid_packages(): void
    {
        $depot = Depot::create([
            'name' => 'Empty Depot',
            'url' => 'http://test.example.com/wpkg',
            'is_primary' => true,
            'is_active' => true,
            'xml_hash' => null,
        ]);

        // Pre-create an existing application
        DepotApplication::create(['depot_id' => $depot->id, 'app_id' => 'existing', 'name' => 'Existing', 'branch' => 'stable']);

        // Valid XML but with no exploitable packages (empty id/name)
        $emptyXml = '<?xml version="1.0" encoding="UTF-8"?><packages><branch id="stable"><package id="" name=""/></branch></packages>';

        Http::fake([
            'test.example.com/wpkg/packages.xml' => Http::response($emptyXml, 200),
        ]);

        $result = $this->service->syncDepot($depot);

        $this->assertEquals(0, $result['purged']);
        // Existing application must NOT be purged
        $this->assertEquals(1, DepotApplication::where('depot_id', $depot->id)->count());
    }

    #[Test]
    public function sync_all_depots_skips_imposed_depots_without_any_http_call(): void
    {
        Depot::query()->delete();

        // Un dépôt imposé (URL controlhub:// non joignable) + un dépôt classique.
        Depot::create([
            'name' => 'ControlHub',
            'url' => 'controlhub://managed',
            'is_primary' => true,
            'is_active' => true,
            'is_imposed' => true,
        ]);
        Depot::create([
            'name' => 'Classique',
            'url' => 'http://test.example.com/wpkg',
            'is_primary' => false,
            'is_active' => true,
            'is_imposed' => false,
        ]);

        Http::fake([
            'test.example.com/wpkg/packages.xml' => Http::response($this->getSampleXml(), 200),
        ]);

        $stats = $this->service->syncAllDepots();

        // Seul le dépôt classique est synchronisé ; JAMAIS de HTTP vers le dépôt imposé.
        $this->assertEquals(1, $stats['synced']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'test.example.com'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'controlhub'));
    }

    #[Test]
    public function sync_depot_skips_an_imposed_depot(): void
    {
        $imposed = Depot::create([
            'name' => 'ControlHub',
            'url' => 'controlhub://managed',
            'is_primary' => true,
            'is_active' => true,
            'is_imposed' => true,
        ]);

        Http::fake();

        $result = $this->service->syncDepot($imposed);

        $this->assertEquals(['new' => 0, 'updated' => 0, 'purged' => 0], $result);
        Http::assertNothingSent();
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
