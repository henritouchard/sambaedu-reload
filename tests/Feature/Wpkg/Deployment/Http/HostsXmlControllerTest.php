<?php

declare(strict_types=1);

namespace Tests\Feature\Wpkg\Deployment\Http;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WpkgSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 15.2 / AC1.1, AC1.5, AC1.6, AC1.7 — Endpoint hosts.xml.
 */
class HostsXmlControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WpkgSchemaBootstrapper::bootstrap();
    }

    protected function tearDown(): void
    {
        WpkgSchemaBootstrapper::tearDown();
        parent::tearDown();
    }

    #[Test]
    public function returns_xml_for_known_hostname(): void
    {
        $response = $this->withoutMiddleware()->get('/wpkg/hosts.xml?poste=PCEXEMPLE');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/xml; charset=UTF-8');
        $body = $response->getContent();
        self::assertStringContainsString('<wpkg>', (string) $body);
        self::assertStringContainsString('<host name="PCEXEMPLE" profile-id="PCEXEMPLE"/>', (string) $body);
        self::assertStringContainsString('Fichier genere par SambaEdu', (string) $body);
    }

    #[Test]
    public function returns_xml_for_unknown_hostname_parity_legacy(): void
    {
        // Pas de poste créé en BDD : le legacy renvoie quand même le host.
        $response = $this->withoutMiddleware()->get('/wpkg/hosts.xml?poste=NOPOSTEDB');

        $response->assertOk();
        self::assertStringContainsString('<host name="NOPOSTEDB" profile-id="NOPOSTEDB"/>', (string) $response->getContent());
    }

    #[Test]
    public function no_auth_required(): void
    {
        // Pas de middleware web/auth/sambaedu.admin appliqué.
        $response = $this->get('/wpkg/hosts.xml?poste=PCT');

        $response->assertOk();
    }

    #[Test]
    public function output_is_valid_xml(): void
    {
        $response = $this->withoutMiddleware()->get('/wpkg/hosts.xml?poste=VALIDPOSTE');

        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadXML((string) $response->getContent());
        $errors = libxml_get_errors();
        libxml_clear_errors();

        self::assertSame([], $errors);
    }
}
