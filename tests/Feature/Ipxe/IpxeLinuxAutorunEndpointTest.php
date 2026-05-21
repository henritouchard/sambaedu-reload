<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Models\Workstation;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.4 — AC5.4 / D15 / T6.3.
 *
 * Tests feature de la route native `GET|POST /ipxe/linux/autorun` (stub).
 */
class IpxeLinuxAutorunEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();
        config([
            'auth_v1.bootstrap.allowed_subnets' => '127.0.0.0/8,192.168.0.0/16,10.0.0.0/8',
        ]);
    }

    #[Test]
    public function it_returns_bash_stub_script(): void
    {
        $response = $this->get('/ipxe/linux/autorun?mac=aa:bb:cc:dd:ee:ff&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa&name=pc-stub');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringStartsWith("#!/bin/bash\n", $body);
        self::assertStringContainsString('install Linux completed', $body);
        self::assertStringEndsWith("exit 0\n", $body);
    }

    #[Test]
    public function it_returns_text_plain_no_store_headers(): void
    {
        $response = $this->get('/ipxe/linux/autorun');

        $response->assertStatus(200);
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    #[Test]
    public function it_sanitizes_shell_injection_in_name(): void
    {
        $response = $this->get('/ipxe/linux/autorun?name=pc; rm -rf /');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        // Le `;` et l'espace doivent avoir été remplacés par `?` (sanitization).
        self::assertStringNotContainsString('rm -rf /', $body);
    }

    /* ------------------------------------------------------------------
     * Post-review #M7 — Spoofing du paramètre `name`.
     *
     * Le controller préfère `$workstation->name` (DB) sur `name` payload
     * pour empêcher un attaquant LAN d'injecter un nom usurpé dans le script
     * bash servi. Ce test gèle ce comportement.
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_uses_workstation_name_from_db_not_payload(): void
    {
        Workstation::create([
            'name' => 'real-workstation-name',
            'uuid' => '12345678-1234-1234-1234-bbbbbbbbbbbb',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'status' => 'active',
        ]);

        $response = $this->post('/ipxe/linux/autorun', [
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'name' => 'spoof-name',
            'uuid' => '12345678-1234-1234-1234-bbbbbbbbbbbb',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('real-workstation-name', $body);
        self::assertStringNotContainsString('spoof-name', $body);
    }
}
