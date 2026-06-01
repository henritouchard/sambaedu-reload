<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Models\MachineBootLog;
use App\Models\Workstation;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;
use Tests\Support\IpxeAuthTestHelper;

/**
 * Story 3.3 — AC9.2 / T6.4.
 *
 * Tests Feature de la route native `GET|POST /ipxe/enrollment/byod`.
 *
 * Variant audit-only — pas de mock AD nécessaire (le BYOD ne crée pas de
 * compte AD).
 */
class IpxeEnrollmentByodEndpointTest extends TestCase
{
    use IpxeAuthTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bypassIpxeAuth();
        IpxeSchemaBootstrapper::bootstrap();
        config([
            'auth_v1.bootstrap.allowed_subnets' => '127.0.0.0/8,192.168.0.0/16,10.0.0.0/8',
        ]);
    }

    #[Test]
    public function it_returns_handshake_when_no_params(): void
    {
        $response = $this->get('/ipxe/enrollment/byod');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('ipxe/enrollment/byod##params', $body);
    }

    #[Test]
    public function it_logs_byod_enrollment_without_creating_workstation(): void
    {
        $countBefore = Workstation::count();

        $response = $this->post('/ipxe/enrollment/byod', [
            'mac' => 'aa:bb:cc:dd:ee:bb',
            'uuid' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            'new_name' => 'student-laptop',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('BYOD enregistre', $body);
        self::assertStringContainsString('chain --replace --autofree', $body);

        // Pas de Workstation créée (BYOD = audit-only).
        self::assertSame($countBefore, Workstation::count());

        // Une row MachineBootLog action=ipxe_enroll_byod.
        self::assertTrue(
            MachineBootLog::query()
                ->where('action', 'ipxe_enroll_byod')
                ->where('machine_name', 'byod:student-laptop')
                ->exists(),
        );
    }

    /**
     * Q1 (review 3.3) : iso-legacy `enregistrement_byod.php:72-81` — un poste
     * déjà connu en AD (présent dans `workstations`) ne peut PAS BYOD. On
     * doit retourner "ERREUR ! acces refuse" + chain boot, et NE PAS créer
     * de MachineBootLog (le rejet est audit-only via Log warning).
     */
    #[Test]
    public function it_denies_byod_for_already_known_workstation(): void
    {
        $ws = Workstation::query()->create([
            'name' => 'pc-known-001',
            'mac' => 'AA:BB:CC:DD:EE:CC',
            'uuid' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
            'status' => 'active',
        ]);

        $logsBefore = MachineBootLog::query()->where('action', 'ipxe_enroll_byod')->count();

        $response = $this->post('/ipxe/enrollment/byod', [
            'mac' => $ws->mac,
            'uuid' => $ws->uuid,
            'new_name' => 'doesnt-matter',
        ]);

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('ERREUR ! acces refuse', $body);
        self::assertStringContainsString('/ipxe/boot##params', $body);
        self::assertStringNotContainsString('BYOD enregistre', $body);

        // Aucun MachineBootLog créé pour ce rejet (audit-only via Log).
        self::assertSame(
            $logsBefore,
            MachineBootLog::query()->where('action', 'ipxe_enroll_byod')->count(),
        );
    }
}
