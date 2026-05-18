<?php

declare(strict_types=1);

namespace Tests\Feature\Auth\V1;

use App\Auth\V1\Models\WorkstationMigrationAttempt;
use App\Auth\V1\Pki\CaInitializer;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

/**
 * Story 16.11 — AC4.1 / AC4.2 / T4.5.
 *
 * Tests Feature endpoints `GET /api/v1/agent/bootstrap.{cmd,sh}`.
 */
class BootstrapScriptControllerTest extends TestCase
{
    use IssuesWorkstationJwt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureTestKeyPair();
        $this->ensureAuthV1Tables();

        // Mock CaInitializer pour retourner un PEM factice.
        $caMock = Mockery::mock(CaInitializer::class);
        $caMock->shouldReceive('getCaCertPem')->andReturn(
            "-----BEGIN CERTIFICATE-----\nFAKE-CA-FOR-TESTS\n-----END CERTIFICATE-----\n",
        );
        $this->app->instance(CaInitializer::class, $caMock);

        config([
            'sambaedu.se4fs_name' => 'se4fs-test001',
            'sambaedu.domain' => 'lab.local',
            'auth_v1.server.host_suffix' => 'lab.local',
            // LAN subnets : on autorise 127.0.0.0/8 pour que les tests passent
            // depuis testserver (loopback).
            'auth_v1.bootstrap.allowed_subnets' => '127.0.0.0/8,192.168.0.0/16,10.0.0.0/8',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function get_bootstrap_cmd_returns_200_text_plain_windows_script(): void
    {
        $res = $this->get('/api/v1/agent/bootstrap.cmd');

        $res->assertStatus(200);
        $contentType = (string) $res->headers->get('Content-Type');
        $this->assertStringStartsWith('text/plain', $contentType);
        // Symfony normalise (tri alpha) les directives Cache-Control — on assert
        // la présence individuelle plutôt qu'une string strictement égale.
        $cacheControl = (string) $res->headers->get('Cache-Control');
        foreach (['no-store', 'no-cache', 'must-revalidate', 'private'] as $directive) {
            $this->assertStringContainsString($directive, $cacheControl);
        }

        $body = $res->getContent();
        $this->assertStringContainsString('@echo off', $body);
        $this->assertStringContainsString('SambaEdu auto-bootstrap', $body);
        $this->assertStringContainsString('certutil.exe', $body);
        $this->assertStringContainsString('Invoke-RestMethod', $body);
        $this->assertStringContainsString('ProtectedData', $body);

        // Garde-fou : pas de tag PHP résiduel.
        $this->assertStringNotContainsString('<?php', $body);
        $this->assertStringNotContainsString('<?=', $body);

        // URL serveur substituée.
        $this->assertStringContainsString('https://se4fs-test001.lab.local', $body);
    }

    #[Test]
    public function get_bootstrap_sh_returns_200_text_plain_linux_script(): void
    {
        $res = $this->get('/api/v1/agent/bootstrap.sh');

        $res->assertStatus(200);
        $contentType = (string) $res->headers->get('Content-Type');
        $this->assertStringStartsWith('text/plain', $contentType);

        $body = $res->getContent();
        $this->assertStringContainsString('#!/bin/bash', $body);
        $this->assertStringContainsString('update-ca-certificates', $body);
        $this->assertStringContainsString('chmod 0600', $body);
        $this->assertStringContainsString('systemctl', $body);
        $this->assertStringContainsString('/var/lib/sambaedu/auth.json', $body);

        $this->assertStringNotContainsString('<?php', $body);
        $this->assertStringNotContainsString('<?=', $body);
    }

    #[Test]
    public function get_bootstrap_inserts_started_attempt(): void
    {
        $this->assertSame(0, WorkstationMigrationAttempt::query()->count());

        $this->get('/api/v1/agent/bootstrap.cmd')->assertStatus(200);

        $attempt = WorkstationMigrationAttempt::query()->first();
        $this->assertNotNull($attempt);
        $this->assertSame(WorkstationMigrationAttempt::STATUS_STARTED, $attempt->status);
        $this->assertSame('windows', $attempt->os);
        $this->assertNull($attempt->workstation_uuid);
        $this->assertNotEmpty($attempt->client_ip);
    }

    #[Test]
    public function get_bootstrap_sh_inserts_started_attempt_os_linux(): void
    {
        $this->get('/api/v1/agent/bootstrap.sh')->assertStatus(200);

        $attempt = WorkstationMigrationAttempt::query()->first();
        $this->assertNotNull($attempt);
        $this->assertSame('linux', $attempt->os);
    }

    #[Test]
    public function bootstrap_endpoint_blocked_for_non_lan_ip(): void
    {
        // Restrict aux subnets qui n'incluent pas 127.0.0.0/8 → testserver 127.0.0.1
        // sera rejeté.
        config([
            'auth_v1.bootstrap.allowed_subnets' => '192.168.99.0/24',
        ]);

        $res = $this->get('/api/v1/agent/bootstrap.cmd');

        $res->assertStatus(403);
        $res->assertJson([
            'success' => false,
            'error' => 'forbidden',
            'code' => 'bootstrap.not_lan',
        ]);
    }

    #[Test]
    public function ca_cert_pem_is_base64_encoded_in_script(): void
    {
        $res = $this->get('/api/v1/agent/bootstrap.cmd');
        $body = (string) $res->getContent();

        // Le PEM factice base64-encodé doit apparaître dans le body.
        $expectedB64 = base64_encode("-----BEGIN CERTIFICATE-----\nFAKE-CA-FOR-TESTS\n-----END CERTIFICATE-----\n");
        $this->assertStringContainsString($expectedB64, $body);
    }

    // ====================================================================
    // Q1.c — script refresh local déposé + tâche planifiée invoque le local
    // ====================================================================

    #[Test]
    public function bootstrap_cmd_contains_section_6_deploys_refresh_script_locally(): void
    {
        $body = (string) $this->get('/api/v1/agent/bootstrap.cmd')->getContent();

        // Section 6 déposer le script refresh local.
        $this->assertStringContainsString('SECTION 6', $body);
        $this->assertStringContainsString('REFRESH_SCRIPT', $body);
        $this->assertStringContainsString('%ProgramData%\SambaEdu\sambaedu-refresh.cmd', $body);
        // Heredoc-like via PowerShell.
        $this->assertStringContainsString('Set-Content -Path', $body);
        // Le script refresh contient l'appel à Unprotect / Protect pour DPAPI.
        $this->assertStringContainsString('ProtectedData', $body);
        $this->assertStringContainsString('Unprotect', $body);
    }

    #[Test]
    public function bootstrap_cmd_scheduled_task_invokes_local_refresh_script(): void
    {
        $body = (string) $this->get('/api/v1/agent/bootstrap.cmd')->getContent();

        // La tâche planifiée doit pointer vers le script LOCAL (pas un curl direct).
        $this->assertStringContainsString('schtasks /create /tn "SambaEdu-RefreshTokens"', $body);
        $this->assertStringContainsString('/tr "%REFRESH_SCRIPT%"', $body);
        // Anti-régression : pas de POST direct sur /refresh dans la définition de la tâche.
        $this->assertStringNotContainsString(
            'Invoke-RestMethod -Uri \'%REFRESH_ENDPOINT%\' -Method POST"\"',
            $body,
        );
    }

    #[Test]
    public function bootstrap_sh_contains_section_6_deploys_refresh_script_locally(): void
    {
        $body = (string) $this->get('/api/v1/agent/bootstrap.sh')->getContent();

        // Section 6 déposer le script refresh local.
        $this->assertStringContainsString('SECTION 6', $body);
        $this->assertStringContainsString('REFRESH_SCRIPT="/usr/local/lib/sambaedu/sambaedu-refresh.sh"', $body);
        // Heredoc qui dépose le script.
        $this->assertStringContainsString("cat > \"\$REFRESH_SCRIPT\" <<'REFRESH_EOF'", $body);
        // Le script refresh inline contient le POST avec body JSON refresh_token.
        $this->assertStringContainsString('refresh_token', $body);
        $this->assertStringContainsString('/api/v1/agent/refresh', $body);
    }

    #[Test]
    public function bootstrap_sh_systemd_timer_invokes_local_refresh_script(): void
    {
        $body = (string) $this->get('/api/v1/agent/bootstrap.sh')->getContent();

        // Le service systemd doit ExecStart le script local, pas un curl direct.
        $this->assertStringContainsString('ExecStart=$REFRESH_SCRIPT', $body);
        // Anti-régression : pas de curl direct dans la définition du service.
        $this->assertStringNotContainsString("ExecStart=/bin/bash -c 'curl", $body);
    }
}
