<?php

declare(strict_types=1);

namespace Tests\Feature\Auth\V1\Migration;

use App\Auth\V1\Migration\Services\MigrationFragmentRenderer;
use App\Auth\V1\Models\WorkstationMigrationAttempt;
use App\Auth\V1\Models\WorkstationMigrationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

/**
 * Story 16.13bis — AC6.
 *
 * Tests Feature `MigrationController::serveFragment` couvrant les 8 endpoints
 * legacy transformés, la détection OS, l'idempotence (noop), les headers
 * de réponse et l'insertion `WorkstationMigrationAttempt`.
 */
final class MigrationControllerTest extends TestCase
{
    use IssuesWorkstationJwt;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureAuthV1Tables();
        MigrationFragmentRenderer::clearCache();
    }

    #[Test]
    public function serve_fragment_returns_cmd_for_windows_query_param(): void
    {
        $response = $this->get('/gpo/wallpaper_out.php?os=windows&uuid=11111111-1111-4111-8111-111111111111');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $response->assertHeader('X-Migration-Fragment', 'full');
        $body = $response->getContent();
        self::assertStringContainsString('@echo off', $body);
        self::assertStringContainsString('shutdown /r /t 30', $body);
    }

    #[Test]
    public function serve_fragment_returns_sh_for_linux_query_param(): void
    {
        $response = $this->get('/gpo/network_out.php?os=linux&uuid=22222222-2222-4222-8222-222222222222');

        $response->assertOk();
        $body = $response->getContent();
        self::assertStringContainsString('#!/bin/bash', $body);
        self::assertStringContainsString('(sleep 30 && /sbin/shutdown -r now) &', $body);
    }

    #[Test]
    public function serve_fragment_falls_back_to_windows_when_os_param_missing(): void
    {
        // Pas de ?os= → fallback Windows (default parité legacy).
        $response = $this->get('/gpo/wallpaper_out.php');

        $response->assertOk();
        $body = $response->getContent();
        self::assertStringContainsString('@echo off', $body);
    }

    #[Test]
    public function serve_fragment_returns_noop_when_workstation_already_migrated(): void
    {
        $uuid = '33333333-3333-4333-8333-333333333333';
        WorkstationMigrationStatus::create([
            'workstation_uuid' => $uuid,
            'migrated_at' => now(),
            'os' => 'windows',
        ]);

        $response = $this->get('/gpo/wallpaper_out.php?os=windows&uuid=' . $uuid);

        $response->assertOk();
        $response->assertHeader('X-Migration-Fragment', 'noop');
        $body = $response->getContent();
        self::assertStringContainsString('deja migre', $body);
        self::assertStringContainsString('exit /b 0', $body);
        self::assertStringNotContainsString('shutdown /r /t 30', $body);
    }

    #[Test]
    public function serve_fragment_creates_migration_attempt_row(): void
    {
        $uuid = '44444444-4444-4444-8444-444444444444';
        $before = WorkstationMigrationAttempt::query()->count();

        $this->get('/gpo/firefox_out.php?os=linux&uuid=' . $uuid);

        $after = WorkstationMigrationAttempt::query()->count();
        self::assertSame($before + 1, $after);

        $attempt = WorkstationMigrationAttempt::query()
            ->where('workstation_uuid', $uuid)
            ->first();
        self::assertNotNull($attempt);
        self::assertSame('started', $attempt->status);
        self::assertSame('linux', $attempt->os);
    }

    #[Test]
    public function serve_fragment_uses_text_plain_no_store_headers(): void
    {
        $response = $this->get('/gpo/thunderbird_out.php?os=linux');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
    }

    #[Test]
    public function serve_fragment_works_for_all_8_endpoints(): void
    {
        $endpoints = [
            ['path' => '/gpo/shortcuts_out.php', 'method' => 'GET'],
            ['path' => '/gpo/wallpaper_out.php', 'method' => 'GET'],
            ['path' => '/gpo/firefox_out.php', 'method' => 'GET'],
            ['path' => '/gpo/thunderbird_out.php', 'method' => 'GET'],
            ['path' => '/gpo/network_out.php', 'method' => 'GET'],
            ['path' => '/gpo/veyon_out.php', 'method' => 'GET'],
            ['path' => '/gpo/associations_out.php', 'method' => 'POST'],
            ['path' => '/gpo/applications.php', 'method' => 'GET'],
        ];

        foreach ($endpoints as $endpoint) {
            $resp = $endpoint['method'] === 'POST'
                ? $this->post($endpoint['path'] . '?os=windows', [])
                : $this->get($endpoint['path'] . '?os=windows');
            self::assertSame(200, $resp->status(), 'Endpoint ' . $endpoint['path'] . ' should return 200');
            self::assertStringContainsString('@echo off', (string) $resp->getContent(), 'Endpoint ' . $endpoint['path'] . ' should return a Windows fragment');
        }
    }

    #[Test]
    public function serve_fragment_response_contains_no_curl_k_or_skip_tls(): void
    {
        // Garde-fou D5/D10 : ni `-k` ni `-SkipCertificateCheck` dans le
        // fragment (TLS strict iso 16.12 Q5).
        $linuxBody = (string) $this->get('/gpo/wallpaper_out.php?os=linux')->getContent();
        $winBody = (string) $this->get('/gpo/wallpaper_out.php?os=windows')->getContent();

        self::assertStringNotContainsString('curl -k', $linuxBody);
        self::assertStringNotContainsString('--insecure', $linuxBody);
        self::assertStringNotContainsString('-SkipCertificateCheck', $winBody);
    }

    /**
     * Story 16.13bis — Correction Q1 Option A (2026-05-20).
     *
     * Le fragment cmd retourné doit contenir `set "BOOTSTRAP_TOKEN=<hex32>"`
     * minté côté serveur (parité 16.11 + reprise du couple token↔UUID).
     */
    #[Test]
    public function serve_fragment_cmd_contains_minted_bootstrap_token(): void
    {
        $uuid = '55555555-5555-4555-8555-555555555555';
        $resp = $this->get('/gpo/wallpaper_out.php?os=windows&uuid=' . $uuid);

        $resp->assertOk();
        $body = (string) $resp->getContent();

        self::assertMatchesRegularExpression(
            '/set "BOOTSTRAP_TOKEN=[a-f0-9]{32}"/i',
            $body,
            'Le fragment cmd doit injecter un BOOTSTRAP_TOKEN minté (32 chars hex).',
        );
    }

    /**
     * Story 16.13bis — Correction Q1 Option A (2026-05-20).
     *
     * Le fragment sh retourné doit contenir `export BOOTSTRAP_TOKEN="<hex32>"`
     * minté côté serveur.
     */
    #[Test]
    public function serve_fragment_sh_contains_minted_bootstrap_token(): void
    {
        $uuid = '66666666-6666-4666-8666-666666666666';
        $resp = $this->get('/gpo/network_out.php?os=linux&uuid=' . $uuid);

        $resp->assertOk();
        $body = (string) $resp->getContent();

        self::assertMatchesRegularExpression(
            '/export BOOTSTRAP_TOKEN="[a-f0-9]{32}"/i',
            $body,
            'Le fragment sh doit injecter un BOOTSTRAP_TOKEN minté (32 chars hex).',
        );
    }

    /**
     * Story 16.13bis — Correction Q1 Option A (2026-05-20).
     *
     * Si APCu disponible, le token minté dans le fragment doit être présent
     * en APCu (clé `apps.<token>`) avec le payload `['uuid' => ..., 'time' => ...]`.
     * Skipped si APCu non chargée en CI.
     */
    #[Test]
    public function served_fragment_token_is_actually_stored_in_apcu(): void
    {
        if (! function_exists('apcu_enabled') || ! @apcu_enabled()) {
            self::markTestSkipped('APCu non chargée dans cet environnement test.');
        }

        $uuid = '77777777-7777-4777-8777-777777777777';
        $resp = $this->get('/gpo/firefox_out.php?os=windows&uuid=' . $uuid);
        $resp->assertOk();
        $body = (string) $resp->getContent();

        // Extraire le token minté du body.
        self::assertSame(1, preg_match('/set "BOOTSTRAP_TOKEN=([a-f0-9]{32})"/i', $body, $matches));
        $token = $matches[1];

        $success = false;
        $payload = apcu_fetch('apps.' . $token, $success);

        self::assertTrue($success);
        self::assertIsArray($payload);
        self::assertSame(strtolower($uuid), strtolower((string) $payload['uuid']));
    }
}
