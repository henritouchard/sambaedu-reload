<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\V1\Migration;

use App\Auth\V1\Migration\Services\MigrationFragmentRenderer;
use App\Auth\V1\Pki\CaInitializer;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Story 16.13bis — Tests unitaires `MigrationFragmentRenderer`.
 *
 * Couvre `detectOs()` (3 chemins : query, UA, default) + `renderFullFragment()`
 * + `renderNoopFragment()` (cas CA absent — fallback à noop).
 */
final class MigrationFragmentRendererTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MigrationFragmentRenderer::clearCache();
    }

    #[Test]
    public function detect_os_returns_windows_when_query_os_is_windows(): void
    {
        $request = Request::create('/gpo/wallpaper_out.php', 'GET', ['os' => 'windows']);
        $renderer = $this->makeRenderer();

        self::assertSame('windows', $renderer->detectOs($request));
    }

    #[Test]
    public function detect_os_returns_linux_when_query_os_is_linux(): void
    {
        $request = Request::create('/gpo/wallpaper_out.php', 'GET', ['os' => 'linux']);
        $renderer = $this->makeRenderer();

        self::assertSame('linux', $renderer->detectOs($request));
    }

    #[Test]
    public function detect_os_falls_back_to_user_agent_when_query_missing(): void
    {
        $renderer = $this->makeRenderer();

        $linuxReq = Request::create('/gpo/wallpaper_out.php', 'GET');
        $linuxReq->headers->set('User-Agent', 'Mozilla/5.0 (X11; Linux x86_64)');
        self::assertSame('linux', $renderer->detectOs($linuxReq));

        $winReq = Request::create('/gpo/wallpaper_out.php', 'GET');
        $winReq->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64)');
        self::assertSame('windows', $renderer->detectOs($winReq));
    }

    #[Test]
    public function detect_os_defaults_to_windows_when_no_clue(): void
    {
        $request = Request::create('/gpo/wallpaper_out.php', 'GET');
        $request->headers->set('User-Agent', '');

        self::assertSame('windows', $this->makeRenderer()->detectOs($request));
    }

    #[Test]
    public function render_full_fragment_windows_contains_required_directives(): void
    {
        $renderer = $this->makeRenderer();

        $body = $renderer->renderFullFragment('windows');

        self::assertStringContainsString('@echo off', $body);
        self::assertStringContainsString('chcp 65001', $body);
        self::assertStringContainsString('HKLM\\SOFTWARE\\SambaEdu\\AuthV1', $body);
        self::assertStringContainsString('certutil', $body);
        self::assertStringContainsString('Invoke-RestMethod', $body);
        self::assertStringContainsString('ProtectedData', $body);
        self::assertStringContainsString('shutdown /r /t 30', $body);
        self::assertStringContainsString('SambaEdu', $body);
    }

    #[Test]
    public function render_full_fragment_linux_contains_required_directives(): void
    {
        $renderer = $this->makeRenderer();

        $body = $renderer->renderFullFragment('linux');

        self::assertStringContainsString('#!/bin/bash', $body);
        self::assertStringContainsString('set -e', $body);
        self::assertStringContainsString('/var/lib/sambaedu/migrated', $body);
        self::assertStringContainsString('update-ca-certificates', $body);
        self::assertStringContainsString('curl -fsS -X POST', $body);
        self::assertStringNotContainsString('curl -k', $body);
        self::assertStringNotContainsString('--insecure', $body);
        self::assertStringContainsString('chmod 0600', $body);
        self::assertStringContainsString('chown root:root', $body);
        self::assertStringContainsString('/etc/sambaedu/endpoints.conf', $body);
        self::assertStringContainsString('(sleep 30 && /sbin/shutdown -r now) &', $body);
    }

    #[Test]
    public function render_noop_cmd_short_and_uses_exit_b_0(): void
    {
        $renderer = $this->makeRenderer();
        $body = $renderer->renderNoopFragment('windows');

        self::assertStringContainsString('@echo off', $body);
        self::assertStringContainsString('deja migre', $body);
        self::assertStringContainsString('exit /b 0', $body);
        self::assertStringNotContainsString('shutdown', $body);
        self::assertStringNotContainsString('certutil', $body);
    }

    #[Test]
    public function render_noop_sh_short_and_uses_exit_0(): void
    {
        $renderer = $this->makeRenderer();
        $body = $renderer->renderNoopFragment('linux');

        self::assertStringContainsString('#!/bin/bash', $body);
        self::assertStringContainsString('déjà migré', $body);
        self::assertStringContainsString('exit 0', $body);
        self::assertStringNotContainsString('shutdown', $body);
        self::assertStringNotContainsString('update-ca-certificates', $body);
    }

    #[Test]
    public function render_full_fragment_uses_empty_ca_when_pki_missing(): void
    {
        // Le helper makeRenderer pose un CaInitializer mock qui lance
        // RuntimeException — le renderer doit renvoyer un fragment quand
        // même (ca_cert_pem_b64 vide), pas crasher.
        $renderer = $this->makeRenderer(caThrowsMissing: true);

        $body = $renderer->renderFullFragment('linux');

        self::assertStringContainsString('#!/bin/bash', $body);
        // Pas de crash, fragment toujours rendu.
        self::assertNotEmpty($body);
    }

    /**
     * Story 16.13bis — Correction Opus-B (2026-05-20).
     *
     * En environment production, si la PKI est absente, le renderer doit
     * lever une CaUnavailableException pour que le controller renvoie 503.
     */
    #[Test]
    public function render_full_fragment_throws_in_production_when_ca_missing(): void
    {
        $this->app['env'] = 'production';

        $renderer = $this->makeRenderer(caThrowsMissing: true);

        $this->expectException(\App\Auth\V1\Migration\Exceptions\CaUnavailableException::class);
        $renderer->renderFullFragment('windows');
    }

    /**
     * Story 16.13bis — Correction Q1 Option A (2026-05-20).
     *
     * Le fragment Windows complet doit contenir un `set "BOOTSTRAP_TOKEN=...`
     * avec une valeur hex 32 chars (parité regex `auth_v1.bootstrap_token.token_regex`).
     */
    #[Test]
    public function render_full_fragment_windows_injects_bootstrap_token(): void
    {
        $renderer = $this->makeRenderer();
        $uuid = '11111111-1111-4111-8111-111111111111';

        $body = $renderer->renderFullFragment('windows', $uuid);

        // Le `set` doit apparaître AVANT l'appel Invoke-RestMethod enroll.
        self::assertStringContainsString('set "BOOTSTRAP_TOKEN=', $body);

        // Le token doit être un hex 32 chars (parité regex APCu legacy 16.10).
        self::assertMatchesRegularExpression(
            '/set "BOOTSTRAP_TOKEN=[a-f0-9]{32}"/i',
            $body,
        );

        // Ordre : set BOOTSTRAP_TOKEN doit précéder Invoke-RestMethod.
        $tokenPos = strpos($body, 'set "BOOTSTRAP_TOKEN=');
        $enrollPos = strpos($body, 'Invoke-RestMethod');
        self::assertNotFalse($tokenPos);
        self::assertNotFalse($enrollPos);
        self::assertLessThan($enrollPos, $tokenPos);
    }

    /**
     * Story 16.13bis — Correction Q1 Option A (2026-05-20).
     *
     * Le fragment Linux complet doit contenir un `export BOOTSTRAP_TOKEN="..."`
     * avant l'appel curl enroll.
     */
    #[Test]
    public function render_full_fragment_linux_injects_bootstrap_token(): void
    {
        $renderer = $this->makeRenderer();
        $uuid = '22222222-2222-4222-8222-222222222222';

        $body = $renderer->renderFullFragment('linux', $uuid);

        self::assertStringContainsString('export BOOTSTRAP_TOKEN="', $body);
        self::assertMatchesRegularExpression(
            '/export BOOTSTRAP_TOKEN="[a-f0-9]{32}"/i',
            $body,
        );

        // Ordre : export doit précéder l'appel curl enroll.
        $tokenPos = strpos($body, 'export BOOTSTRAP_TOKEN=');
        $curlPos = strpos($body, 'curl -fsS -X POST');
        self::assertNotFalse($tokenPos);
        self::assertNotFalse($curlPos);
        self::assertLessThan($curlPos, $tokenPos);
    }

    /**
     * Story 16.13bis — Correction Q1 Option A (2026-05-20).
     *
     * `mintBootstrapToken()` doit poser une clé APCu `apps.<token>` avec le
     * payload `['uuid' => $uuid, 'time' => time()]` (parité 16.7 / 16.11).
     *
     * Skipped si APCu non chargée en CI (worktree).
     */
    #[Test]
    public function mint_bootstrap_token_stores_apcu_entry_when_extension_available(): void
    {
        if (! function_exists('apcu_enabled') || ! @apcu_enabled()) {
            self::markTestSkipped('APCu non chargée dans cet environnement test.');
        }

        $renderer = $this->makeRenderer();
        $uuid = '33333333-3333-4333-8333-333333333333';

        $token = $renderer->mintBootstrapToken($uuid);

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);

        $success = false;
        $payload = apcu_fetch('apps.' . $token, $success);

        self::assertTrue($success, 'La clé APCu `apps.<token>` doit être posée.');
        self::assertIsArray($payload);
        self::assertArrayHasKey('uuid', $payload);
        self::assertArrayHasKey('time', $payload);
        self::assertSame($uuid, $payload['uuid']);
    }

    /**
     * Story 16.13bis — Correction Q1 Option A (2026-05-20).
     *
     * Le token minté doit être unique entre deux appels successifs (entropie
     * `random_bytes`).
     */
    #[Test]
    public function mint_bootstrap_token_returns_unique_tokens(): void
    {
        $renderer = $this->makeRenderer();

        $token1 = $renderer->mintBootstrapToken(null);
        $token2 = $renderer->mintBootstrapToken(null);

        self::assertNotSame($token1, $token2);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token1);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token2);
    }

    private function makeRenderer(bool $caThrowsMissing = false): MigrationFragmentRenderer
    {
        $ca = $this->createMock(CaInitializer::class);

        if ($caThrowsMissing) {
            $ca->method('getCaCertPem')
                ->willThrowException(new RuntimeException('CA not initialized'));
        } else {
            $ca->method('getCaCertPem')
                ->willReturn("-----BEGIN CERTIFICATE-----\nFAKECERTBASE64==\n-----END CERTIFICATE-----\n");
        }

        return new MigrationFragmentRenderer($ca);
    }
}
