<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Models\MachineBootLog;
use App\Models\Workstation;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.1 — AC4.1 / AC5.1 / AC5.2 / AC8.2 / T6.4.
 *
 * Tests feature de l'endpoint `GET|POST /ipxe/boot` :
 *
 *  - Handshake (sans params) → 200 + préambule iPXE.
 *  - Locate unknown → 200 + menu default.
 *  - Locate known (via UUID) → 200 + menu known.
 *  - Locate known (via MAC fallback) → 200 + menu known.
 *  - Content-Type text/plain charset=utf-8.
 *  - MachineBootLog persisté à chaque call non-handshake.
 *  - Validation FormRequest : mac > 64 chars → 422.
 *  - Accepte uppercase MAC.
 */
class IpxeBootEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();
        // Décision DO-9 : LAN whitelist override pour autoriser 127.0.0.1
        // (le test server tourne en loopback).
        config([
            'auth_v1.bootstrap.allowed_subnets' => '127.0.0.0/8,192.168.0.0/16,10.0.0.0/8',
        ]);
    }

    #[Test]
    public function it_returns_handshake_when_no_params(): void
    {
        $response = $this->get('/ipxe/boot');

        $response->assertStatus(200);
        $contentType = (string) $response->headers->get('Content-Type');
        self::assertStringStartsWith('text/plain', $contentType);
        self::assertStringContainsString('charset=utf-8', $contentType);

        $body = $response->getContent();
        self::assertStringStartsWith('#!ipxe', (string) $body);
        self::assertStringContainsString('param mac ${net0/mac}', (string) $body);
        self::assertStringContainsString('chain --replace --autofree boot##params', (string) $body);
    }

    #[Test]
    public function it_accepts_empty_mac_and_uuid_for_handshake(): void
    {
        // POST avec params vides → handshake (iso `boot.php:26-35` qui teste
        // `empty($mac) || empty($uuid)` — fix review #1/#10 Q1 Henri : `||`
        // restauré dans IpxeService::handleBoot pour parité legacy stricte).
        $response = $this->post('/ipxe/boot', [
            'mac' => '',
            'uuid' => '',
            'product' => '',
        ]);

        $response->assertStatus(200);
        self::assertStringContainsString(
            'param mac ${net0/mac}',
            (string) $response->getContent(),
        );
    }

    #[Test]
    public function it_returns_unknown_menu_when_workstation_not_found(): void
    {
        $response = $this->post('/ipxe/boot', [
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '99999999-9999-9999-9999-999999999999',
            'product' => 'Unknown',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('item --key 0 exit', $body);
        // Pas d'item login (poste inconnu only).
        self::assertStringNotContainsString('item --key 1 login', $body);
    }

    #[Test]
    public function it_returns_known_menu_when_workstation_found_by_uuid(): void
    {
        Workstation::create([
            'name' => 'PC-SALLE-101',
            'uuid' => 'abcdef12-3456-7890-abcd-ef1234567890',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'status' => 'active',
        ]);

        $response = $this->post('/ipxe/boot', [
            'mac' => '00:00:00:00:00:00',
            'uuid' => 'abcdef12-3456-7890-abcd-ef1234567890',
            'product' => 'OptiPlex 3050',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('PC-SALLE-101', $body);
        self::assertStringContainsString('item --key 1 login', $body);
    }

    #[Test]
    public function it_returns_known_menu_when_workstation_found_by_mac_fallback(): void
    {
        Workstation::create([
            'name' => 'PC-MAC-FALLBACK',
            'uuid' => '11111111-1111-1111-1111-111111111111',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'status' => 'active',
        ]);

        $response = $this->post('/ipxe/boot', [
            'mac' => 'AA-BB-CC-DD-EE-FF',  // séparateur dash + uppercase
            'uuid' => '99999999-9999-9999-9999-999999999999',  // UUID inconnu
            'product' => 'OptiPlex 3050',
        ]);

        $response->assertStatus(200);
        self::assertStringContainsString(
            'PC-MAC-FALLBACK',
            (string) $response->getContent(),
        );
    }

    #[Test]
    public function it_responds_with_text_plain_content_type(): void
    {
        $response = $this->get('/ipxe/boot');
        self::assertSame(
            'text/plain; charset=utf-8',
            $response->headers->get('Content-Type'),
        );

        // Cache-Control no-store
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        // X-Robots-Tag noindex
        self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
    }

    #[Test]
    public function it_persists_machine_boot_log_row(): void
    {
        // Fix review #5 — discriminant unique au test (machine_name) pour
        // garantir l'isolation : la base SQLite :memory: est partagée entre
        // les tests de la classe (pas de RefreshDatabase / pas de truncate
        // dans IpxeSchemaBootstrapper qui est idempotent), donc les rows
        // créées par les tests précédents (`unknown`, `known_uuid`,
        // `known_mac_fallback`) cohabitent. Filtrer par `machine_name`
        // unique au test évite l'instabilité d'ordre PHPUnit.
        $uniqueName = 'pc-logged-' . substr(bin2hex(random_bytes(4)), 0, 8);

        Workstation::create([
            'name' => $uniqueName,
            'uuid' => '11111111-1111-1111-1111-111111111111',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'status' => 'active',
        ]);

        $this->post('/ipxe/boot', [
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'uuid' => '11111111-1111-1111-1111-111111111111',
            'product' => 'OptiPlex 3050',
        ]);

        $count = MachineBootLog::query()
            ->where('action', 'ipxe_boot')
            ->where('initiated_by', 'ipxe')
            ->where('machine_name', $uniqueName)
            ->count();

        self::assertSame(1, $count);
    }

    #[Test]
    public function it_serves_boot_ipxe_alias_with_same_content(): void
    {
        $response = $this->get('/ipxe/boot.ipxe');

        $response->assertStatus(200);
        self::assertStringStartsWith('#!ipxe', (string) $response->getContent());
    }

    #[Test]
    public function it_rejects_oversize_mac_with_422(): void
    {
        // Force Accept: application/json pour obtenir 422 plutôt que 302
        // (le FormRequest sans Accept JSON redirige vers la previous URL en
        // mode web — comportement Laravel standard).
        $response = $this->postJson('/ipxe/boot', [
            'mac' => str_repeat('a', 65),  // > 64 chars
            'uuid' => '11111111-1111-1111-1111-111111111111',
        ]);

        $response->assertStatus(422);
    }
}
