<?php

declare(strict_types=1);

namespace Tests\Feature\Wpkg\Deployment\Http;

use App\Models\AppProfile;
use App\Models\Application;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 15.2 / AC1.2, AC1.3, AC1.4, AC1.5, AC1.7, AC2.2 — Endpoint profiles.xml.
 */
class ProfilesXmlControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WpkgSchemaBootstrapper::bootstrap();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        WpkgSchemaBootstrapper::tearDown();
        parent::tearDown();
    }

    #[Test]
    public function unknown_hostname_returns_empty_profile_silently(): void
    {
        $response = $this->withoutMiddleware()->get('/wpkg/profiles.xml?poste=UNKNOWNHOST');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/xml; charset=UTF-8');
        $body = (string) $response->getContent();
        self::assertStringContainsString('<profiles>', $body);
        self::assertStringContainsString('<profile id="UNKNOWNHOST"/>', $body);
        self::assertStringNotContainsString('<package', $body);
    }

    #[Test]
    public function inactive_workstation_returns_xml_normally(): void
    {
        $w = Workstation::create(['name' => 'PCINACTIVE', 'status' => 'inactive']);
        $app = Application::create(['app_id' => 'firefox', 'name' => 'Firefox']);
        $w->applications()->attach($app);

        $response = $this->withoutMiddleware()->get('/wpkg/profiles.xml?poste=PCINACTIVE');

        $response->assertOk();
        self::assertStringContainsString('<package package-id="firefox"/>', (string) $response->getContent());
    }

    #[Test]
    public function unions_workstation_and_group_packages(): void
    {
        $w = Workstation::create(['name' => 'PCFULL', 'status' => 'active']);
        $g = WorkstationGroup::create(['name' => 'parc-x']);
        $w->groups()->attach($g);

        $appPoste = Application::create(['app_id' => 'firefox', 'name' => 'Firefox']);
        $appParc = Application::create(['app_id' => 'thunderbird', 'name' => 'TB']);

        $w->applications()->attach($appPoste);
        $g->applications()->attach($appParc);

        $response = $this->withoutMiddleware()->get('/wpkg/profiles.xml?poste=PCFULL');

        $body = (string) $response->getContent();
        self::assertStringContainsString('<package package-id="firefox"/>', $body);
        self::assertStringContainsString('<package package-id="thunderbird"/>', $body);
    }

    #[Test]
    public function output_is_valid_xml(): void
    {
        $response = $this->withoutMiddleware()->get('/wpkg/profiles.xml?poste=ANY');

        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadXML((string) $response->getContent());
        $errors = libxml_get_errors();
        libxml_clear_errors();

        self::assertSame([], $errors);
    }

    #[Test]
    public function no_auth_required(): void
    {
        $response = $this->get('/wpkg/profiles.xml?poste=PCNoAuth');

        $response->assertOk();
    }
}
