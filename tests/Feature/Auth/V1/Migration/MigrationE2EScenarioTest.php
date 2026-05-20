<?php

declare(strict_types=1);

namespace Tests\Feature\Auth\V1\Migration;

use App\Auth\V1\Migration\Services\MigrationFragmentRenderer;
use App\Auth\V1\Models\WorkstationMigrationAttempt;
use App\Auth\V1\Models\WorkstationMigrationStatus;
use App\Auth\V1\Pki\CaInitializer;
use App\Auth\V1\Services\LegacyBootstrapTokenValidator;
use App\Models\Workstation;
use App\Services\AppCustomization\Contracts\AppContextWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\Concerns\SeedsWorkstationConfig;
use Tests\TestCase;

/**
 * Story 16.13bis — AC7.
 *
 * Tests E2E qui orchestrent le parcours complet :
 *   1. Appel legacy `gpo/*_out.php` sur un poste non-migré → fragment complet
 *      contenant `shutdown /r /t 30` et les URLs `/api/v1/workstation-config/*`.
 *   2. Simulation de l'effet poste : insertion d'une row
 *      `WorkstationMigrationStatus` (ce que ferait `EnrollController` après
 *      exécution du fragment côté poste).
 *   3. Émission d'un JWT pour le poste migré + appel `/api/v1/workstation-config/wallpaper`
 *      → 200 OK (non-régression 16.13).
 *   4. Re-jeu de l'appel legacy → fragment-noop.
 *
 * Les side-effects côté poste (DPAPI Win, update-ca-certificates Linux, etc.)
 * ne sont **pas** simulés — ils sont par nature hors-Laravel.
 */
final class MigrationE2EScenarioTest extends TestCase
{
    use IssuesWorkstationJwt;
    use RefreshDatabase;
    use SeedsWorkstationConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorkstationContextSchemas();
        $this->ensureAuthV1Tables();
        $this->configureTestKeyPair();
        MigrationFragmentRenderer::clearCache();

        // Mock CaInitializer pour PEM factice (parité EnrollControllerTest).
        $caMock = Mockery::mock(CaInitializer::class);
        $caMock->shouldReceive('getCaCertPem')->andReturn(
            "-----BEGIN CERTIFICATE-----\nFAKE-CA-FOR-TESTS-E2E-MIGRATION-LONG-ENOUGH-TO-BE-VALID-PADDING\n-----END CERTIFICATE-----\n",
        );
        $this->app->instance(CaInitializer::class, $caMock);

        // LAN whitelist : loopback 127.0.0.1 (les tests Feature partent de
        // 127.0.0.1).
        config([
            'sambaedu.se4fs_name' => 'se4fs-test001',
            'auth_v1.server.host_suffix' => 'lab.local',
            'auth_v1.bootstrap.allowed_subnets' => '127.0.0.0/8,192.168.0.0/16,10.0.0.0/8',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Story 16.13bis — Correction #2 (2026-05-20).
     *
     * Helper test : mock `LegacyBootstrapTokenValidator` pour valider
     * n'importe quel token (l'enroll passe le middleware). On utilise ça
     * dans les étapes 2 du parcours E2E pour ne pas dépendre d'APCu live.
     */
    private function bypassBootstrapTokenValidator(): void
    {
        $mock = Mockery::mock(LegacyBootstrapTokenValidator::class);
        $mock->shouldReceive('isValid')->andReturn(true);
        $mock->shouldReceive('checkMismatch')->andReturn(false);
        $this->app->instance(LegacyBootstrapTokenValidator::class, $mock);
    }

    /**
     * Story 16.13bis — Correction #2 (2026-05-20).
     *
     * Extrait le `BOOTSTRAP_TOKEN` minté côté serveur dans un fragment.
     * Lève une assertion en cas d'absence (le fragment doit toujours en
     * contenir un après Correction Q1 Option A).
     */
    private function extractBootstrapTokenFromFragment(string $body, string $os): string
    {
        $pattern = $os === 'linux'
            ? '/export BOOTSTRAP_TOKEN="([a-f0-9]{32})"/i'
            : '/set "BOOTSTRAP_TOKEN=([a-f0-9]{32})"/i';

        self::assertSame(
            1,
            preg_match($pattern, $body, $matches),
            'Le fragment doit contenir un BOOTSTRAP_TOKEN minté (32 chars hex).',
        );

        return $matches[1];
    }

    #[Test]
    public function windows_workstation_migrates_via_fragment_then_consumes_api_v1(): void
    {
        $uuid = 'e2e11111-1111-4111-8111-111111111111';
        Workstation::create(['name' => 'e2e-win-01', 'uuid' => $uuid, 'os' => 'windows']);

        // Étape 1 — fragment complet (poste non-migré).
        $resp1 = $this->get('/gpo/wallpaper_out.php?os=windows&uuid=' . $uuid);
        $resp1->assertOk();
        $resp1->assertHeader('X-Migration-Fragment', 'full');
        $body1 = (string) $resp1->getContent();
        self::assertStringContainsString('@echo off', $body1);
        self::assertStringContainsString('shutdown /r /t 30', $body1);
        // Le fragment doit pointer vers /api/v1/workstation-config/* (D6).
        self::assertStringContainsString('/api/v1/workstation-config/', $body1);

        // Story 16.13bis — Correction Q1 Option A : récupérer le token minté
        // dans le fragment (parse hex 32 chars) pour le rejouer à l'enroll.
        $bootstrapToken = $this->extractBootstrapTokenFromFragment($body1, 'windows');

        // Étape 2 — POST réel /api/v1/agent/enroll avec le token extrait.
        // Story 16.13bis — Correction #2 (2026-05-20) : on ne court-circuite
        // plus l'enroll via `WorkstationMigrationStatus::create()` direct,
        // on appelle le vrai endpoint. Le LegacyBootstrapTokenValidator est
        // mocké pour valider le token (APCu live non garanti en CI).
        $this->bypassBootstrapTokenValidator();

        $enrollResp = $this->postJson(
            '/api/v1/agent/enroll',
            [
                'uuid' => $uuid,
                'mac' => 'AA:BB:CC:DD:EE:01',
                'hostname' => 'e2e-win-01',
                'os' => 'windows',
            ],
            [
                'X-Bootstrap-Token' => $bootstrapToken,
            ],
        );

        $enrollResp->assertOk();
        $enrollResp->assertJsonStructure([
            'success',
            'access_token',
            'refresh_token',
            'ca_cert_pem',
            'server_base_url',
        ]);
        self::assertTrue($enrollResp->json('success'));

        // Vérifier que `WorkstationMigrationStatus` a bien été posé par
        // l'enroll réel (parité 16.11).
        self::assertNotNull(
            WorkstationMigrationStatus::where('workstation_uuid', $uuid)->first(),
            'L\'enroll réel doit poser une row workstations_migration_status.',
        );

        // Étape 3 — Story 16.13bis Correction #10 (2026-05-20) : GET réel
        // sur /api/v1/workstation-config/wallpaper avec un JWT workstation
        // émis pour l'UUID enrôlé. On vérifie 200 (non-régression 16.13).
        $jwt = $this->issueTestJwt(['sub' => $uuid]);
        $configResp = $this->getJson(
            '/api/v1/workstation-config/wallpaper',
            ['Authorization' => 'Bearer ' . $jwt['token']],
        );

        // 200 (image servie) ou 404 (pas de wallpaper configuré pour ce
        // workstation_uuid — c'est OK, le seul check critique est que la
        // route n'est plus 401/403 sous JWT valide).
        self::assertContains(
            $configResp->status(),
            [200, 404],
            'GET /api/v1/workstation-config/wallpaper avec JWT valide doit retourner 200 ou 404, pas 401/403/500. Status réel = ' . $configResp->status(),
        );

        // Étape 4 — re-jeu legacy → fragment-noop.
        $resp4 = $this->get('/gpo/wallpaper_out.php?os=windows&uuid=' . $uuid);
        $resp4->assertOk();
        $resp4->assertHeader('X-Migration-Fragment', 'noop');
        $body4 = (string) $resp4->getContent();
        self::assertStringContainsString('deja migre', $body4);
        self::assertStringContainsString('exit /b 0', $body4);
        self::assertStringNotContainsString('shutdown', $body4);
    }

    #[Test]
    public function linux_workstation_migrates_via_fragment_then_receives_noop(): void
    {
        $uuid = 'e2e22222-2222-4222-8222-222222222222';
        Workstation::create(['name' => 'e2e-lnx-01', 'uuid' => $uuid, 'os' => 'linux']);

        // Étape 1 — fragment Linux complet.
        $resp1 = $this->get('/gpo/network_out.php?os=linux&uuid=' . $uuid);
        $resp1->assertOk();
        $body1 = (string) $resp1->getContent();
        self::assertStringContainsString('#!/bin/bash', $body1);
        self::assertStringContainsString('update-ca-certificates', $body1);
        self::assertStringContainsString('(sleep 30 && /sbin/shutdown -r now) &', $body1);
        self::assertStringContainsString('/etc/sambaedu/endpoints.conf', $body1);

        // Story 16.13bis — Correction Q1 Option A : extraire le token.
        $bootstrapToken = $this->extractBootstrapTokenFromFragment($body1, 'linux');

        // Étape 2 — POST réel /api/v1/agent/enroll (Correction #2).
        $this->bypassBootstrapTokenValidator();

        $enrollResp = $this->postJson(
            '/api/v1/agent/enroll',
            [
                'uuid' => $uuid,
                'mac' => 'AA:BB:CC:DD:EE:02',
                'hostname' => 'e2e-lnx-01.lab.local',
                'os' => 'linux',
            ],
            [
                'X-Bootstrap-Token' => $bootstrapToken,
            ],
        );

        $enrollResp->assertOk();
        self::assertNotNull(
            WorkstationMigrationStatus::where('workstation_uuid', $uuid)->first(),
            'L\'enroll réel doit poser une row workstations_migration_status.',
        );

        // Étape 3 — Correction #10 : GET réel /api/v1/workstation-config/wallpaper
        // avec JWT (linux). On asserte la non-régression 16.13 sur l'OS Linux
        // également.
        $jwt = $this->issueTestJwt(['sub' => $uuid]);
        $configResp = $this->getJson(
            '/api/v1/workstation-config/wallpaper',
            ['Authorization' => 'Bearer ' . $jwt['token']],
        );

        self::assertContains(
            $configResp->status(),
            [200, 404],
            'GET /api/v1/workstation-config/wallpaper (linux) avec JWT valide doit retourner 200 ou 404. Status réel = ' . $configResp->status(),
        );

        // Étape 4 — re-jeu → fragment-noop Linux.
        $resp4 = $this->get('/gpo/network_out.php?os=linux&uuid=' . $uuid);
        $resp4->assertOk();
        $body4 = (string) $resp4->getContent();
        self::assertStringContainsString('#!/bin/bash', $body4);
        self::assertStringContainsString('déjà migré', $body4);
        self::assertStringContainsString('exit 0', $body4);
        self::assertStringNotContainsString('shutdown', $body4);
    }

    #[Test]
    public function migration_attempt_is_logged_on_fragment_request(): void
    {
        $uuid = 'e2e33333-3333-4333-8333-333333333333';
        $before = WorkstationMigrationAttempt::query()->count();

        $this->get('/gpo/wallpaper_out.php?os=windows&uuid=' . $uuid);
        $this->get('/gpo/firefox_out.php?os=windows&uuid=' . $uuid);

        $after = WorkstationMigrationAttempt::query()->count();
        self::assertSame($before + 2, $after, 'Chaque requête legacy doit créer un attempt status=started.');

        $attempts = WorkstationMigrationAttempt::query()
            ->where('workstation_uuid', $uuid)
            ->get();
        self::assertCount(2, $attempts);
        foreach ($attempts as $attempt) {
            self::assertSame(WorkstationMigrationAttempt::STATUS_STARTED, $attempt->status);
            self::assertSame('windows', $attempt->os);
        }
    }

    #[Test]
    public function applications_endpoint_no_longer_sets_apcu_context(): void
    {
        // Story 16.13bis D13 — option β : `gpo/applications.php` transformé
        // en fragment, plus de pose APCu côté legacy.
        //
        // Story 16.13bis — Correction Opus-E (2026-05-20) : on vérifie
        // désormais le **side-effect réel** via un mock de
        // `AppContextWriter` (qui ne doit JAMAIS recevoir `write()` sur la
        // route legacy migration). C'est plus fort que les anciennes
        // assertions textuelles `assertStringNotContainsString('"apps":')`
        // qui ne couvraient que le markup de réponse.
        $writerMock = Mockery::mock(AppContextWriter::class);
        $writerMock->shouldNotReceive('write');
        // `forget` peut éventuellement être appelé — on n'est pas strict.
        $writerMock->shouldReceive('forget')->withAnyArgs();
        $this->app->instance(AppContextWriter::class, $writerMock);

        $uuid = 'e2e44444-4444-4444-8444-444444444444';

        $response = $this->get('/gpo/applications.php?os=linux&uuid=' . $uuid);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $body = (string) $response->getContent();

        // Doit être un fragment shell, pas du JSON.
        self::assertStringContainsString('#!/bin/bash', $body);
        self::assertStringContainsString('update-ca-certificates', $body);

        // Sanity check legacy (conservé) : pas de marqueur du legacy
        // `applications.php` 16.7.
        self::assertStringNotContainsString('"apps":', $body);
        self::assertStringNotContainsString('apcu_store', $body);

        // Mockery::close() via tearDown verifiera l'expectation
        // `shouldNotReceive('write')`.
    }
}
