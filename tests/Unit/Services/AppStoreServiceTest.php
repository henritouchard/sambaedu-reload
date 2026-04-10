<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AppStore\AppStoreService;
use App\Services\AppStore\DepotSyncService;
use App\Services\AppStore\PackageInstallerService;
use App\Services\AppStore\PackagesXmlService;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests unitaires pour AppStoreService
 * 
 * Ces tests vérifient le parsing XML et la logique métier
 */
class AppStoreServiceTest extends TestCase
{
    private AppStoreService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AppStoreService::class);
    }

    #[Test]
    public function it_can_parse_xml_with_branches(): void
    {
        $xmlContent = $this->getSamplePackagesXml();
        
        $xml = new \DOMDocument();
        $xml->loadXML($xmlContent);
        
        $branches = $xml->getElementsByTagName('branch');
        $this->assertEquals(3, $branches->length);
        
        // Vérifier les IDs de branche
        $branchIds = [];
        foreach ($branches as $branch) {
            $branchIds[] = $branch->getAttribute('id');
        }
        $this->assertContains('stable', $branchIds);
        $this->assertContains('testing', $branchIds);
        $this->assertContains('manuel', $branchIds);
    }

    #[Test]
    public function it_extracts_packages_from_branches(): void
    {
        $xmlContent = $this->getSamplePackagesXml();
        
        $xml = new \DOMDocument();
        $xml->loadXML($xmlContent);
        
        $branches = $xml->getElementsByTagName('branch');
        $packagesByBranch = [];
        
        foreach ($branches as $branch) {
            $branchId = $branch->getAttribute('id');
            $packages = $branch->getElementsByTagName('package');
            $packagesByBranch[$branchId] = $packages->length;
        }
        
        $this->assertEquals(2, $packagesByBranch['stable']);
        $this->assertEquals(1, $packagesByBranch['testing']);
        $this->assertEquals(1, $packagesByBranch['manuel']);
    }

    #[Test]
    public function it_extracts_package_attributes_correctly(): void
    {
        $xmlContent = $this->getSamplePackagesXml();
        
        $xml = new \DOMDocument();
        $xml->loadXML($xmlContent);
        
        $packages = $xml->getElementsByTagName('package');
        $firstPackage = $packages->item(0);
        
        $this->assertEquals('7zip', $firstPackage->getAttribute('id'));
        $this->assertEquals('7-Zip', $firstPackage->getAttribute('name'));
        $this->assertEquals('24.09', $firstPackage->getAttribute('revision'));
        $this->assertEquals('Bureautique', $firstPackage->getAttribute('category'));
        $this->assertEquals('7', $firstPackage->getAttribute('compatibilite'));
    }

    #[Test]
    public function it_uses_revision_as_version(): void
    {
        $xmlContent = $this->getSamplePackagesXml();
        
        $xml = new \DOMDocument();
        $xml->loadXML($xmlContent);
        
        $packages = $xml->getElementsByTagName('package');
        
        foreach ($packages as $package) {
            $version = $package->getAttribute('revision') ?: $package->getAttribute('version');
            $this->assertNotEmpty($version, "Package {$package->getAttribute('id')} should have a version");
        }
    }

    #[Test]
    public function it_extracts_xml_url_and_hash(): void
    {
        $xmlContent = $this->getSamplePackagesXml();
        
        $xml = new \DOMDocument();
        $xml->loadXML($xmlContent);
        
        $packages = $xml->getElementsByTagName('package');
        $firstPackage = $packages->item(0);
        
        $this->assertEquals('http://test.example.com/wpkg/stable/7zip.xml', $firstPackage->getAttribute('url'));
        $this->assertEquals('abc123def456', $firstPackage->getAttribute('hash'));
        $this->assertEquals('http://test.example.com/wpkg/logs/7zip.log', $firstPackage->getAttribute('log'));
    }

    #[Test]
    public function it_handles_empty_xml(): void
    {
        $xmlContent = '<?xml version="1.0" encoding="UTF-8"?><packages></packages>';
        
        $xml = new \DOMDocument();
        $xml->loadXML($xmlContent);
        
        $branches = $xml->getElementsByTagName('branch');
        $this->assertEquals(0, $branches->length);
        
        $packages = $xml->getElementsByTagName('package');
        $this->assertEquals(0, $packages->length);
    }

    #[Test]
    public function it_handles_xml_without_branches(): void
    {
        $xmlContent = '<?xml version="1.0" encoding="UTF-8"?>
        <packages>
            <package id="test" name="Test" revision="1.0"/>
        </packages>';
        
        $xml = new \DOMDocument();
        $xml->loadXML($xmlContent);
        
        $branches = $xml->getElementsByTagName('branch');
        $this->assertEquals(0, $branches->length);
        
        // Les packages directs sont toujours accessibles
        $packages = $xml->getElementsByTagName('package');
        $this->assertEquals(1, $packages->length);
    }

    #[Test]
    public function service_is_instantiable(): void
    {
        $this->assertInstanceOf(AppStoreService::class, $this->service);
    }

    #[Test]
    public function it_injects_sub_services_via_constructor(): void
    {
        $service = app(AppStoreService::class);
        $this->assertInstanceOf(AppStoreService::class, $service);

        // Verify sub-services are resolvable (DI works)
        $this->assertInstanceOf(DepotSyncService::class, app(DepotSyncService::class));
        $this->assertInstanceOf(PackagesXmlService::class, app(PackagesXmlService::class));
        $this->assertInstanceOf(PackageInstallerService::class, app(PackageInstallerService::class));
    }

    #[Test]
    public function it_still_exposes_sync_methods(): void
    {
        $this->assertTrue(method_exists($this->service, 'syncDepot'));
        $this->assertTrue(method_exists($this->service, 'syncAllDepots'));
    }

    #[Test]
    public function it_still_exposes_update_local_packages_xml(): void
    {
        $this->assertTrue(method_exists($this->service, 'updateLocalPackagesXml'));
    }

    #[Test]
    public function it_still_exposes_consultation_methods(): void
    {
        $this->assertTrue(method_exists($this->service, 'listDepots'));
        $this->assertTrue(method_exists($this->service, 'getDefaultDepot'));
        $this->assertTrue(method_exists($this->service, 'listDepotApplications'));
        $this->assertTrue(method_exists($this->service, 'getDepotStats'));
        $this->assertTrue(method_exists($this->service, 'getDepotCategories'));
        $this->assertTrue(method_exists($this->service, 'getDepotBranches'));
        $this->assertTrue(method_exists($this->service, 'getStats'));
    }

    #[Test]
    public function it_still_exposes_install_uninstall_methods(): void
    {
        $this->assertTrue(method_exists($this->service, 'installApplication'));
        $this->assertTrue(method_exists($this->service, 'uninstallApplication'));
    }

    /**
     * Génère un XML de test avec plusieurs branches
     */
    private function getSamplePackagesXml(): string
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
            hash="abc123def456"
            log="http://test.example.com/wpkg/logs/7zip.log"/>
        <package id="vlc" 
            name="VLC Media Player" 
            revision="3.0.20" 
            category="Multimédia" 
            compatibilite="7"
            url="http://test.example.com/wpkg/stable/vlc.xml"
            hash="def789ghi012"/>
    </branch>
    <branch id="testing">
        <package id="firefox" 
            name="Firefox" 
            revision="125.0" 
            category="Internet" 
            compatibilite="7"
            url="http://test.example.com/wpkg/testing/firefox.xml"
            hash="jkl345mno678"/>
    </branch>
    <branch id="manuel">
        <package id="custom-app" 
            name="Custom Application" 
            revision="1.0.0" 
            category="Système" 
            compatibilite="7"
            url="http://test.example.com/wpkg/manuel/custom-app.xml"
            hash="xyz999abc111"/>
    </branch>
</packages>
XML;
    }
}
