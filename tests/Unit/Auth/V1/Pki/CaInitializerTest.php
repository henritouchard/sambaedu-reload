<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\V1\Pki;

use App\Auth\V1\Pki\CaInitializer;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Story 16.10 — AC7.1.
 *
 * Tests unit de `CaInitializer` (PKI locale).
 *
 * **Stratégie** : utilise un dossier temp dédié au test (`sys_get_temp_dir()`)
 * via override `config('auth_v1.pki.*')`. Permet :
 *
 *  - Idempotence (relance sans `--force` = no-op)
 *  - `--force` régénère + backup
 *  - `--regenerate-server-only` ne touche pas le CA
 *  - Permissions correctes (0600 / 0644)
 *  - CN du cert serveur contient bien le hostname FQDN
 *  - Validité dates plausibles (CA = 5 ans, server = 1 an)
 *
 * Tests sont écrits en mode déterministe sans dépendance à la VM.
 */
class CaInitializerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/auth_v1_test_' . bin2hex(random_bytes(6));
        @mkdir($this->tmpDir, 0700, true);
        @mkdir($this->tmpDir . '/pki', 0700, true);
        @mkdir($this->tmpDir . '/jwt', 0700, true);

        $kid = 'test-kid';
        config([
            'sambaedu.se4fs_name' => 'se4fs-test001',
            'auth_v1.server.host_suffix' => 'lab.local',
            'auth_v1.pki.ca_root_key' => $this->tmpDir . '/pki/ca-root.key',
            'auth_v1.pki.ca_root_crt' => $this->tmpDir . '/pki/ca-root.crt',
            'auth_v1.pki.server_key' => $this->tmpDir . '/pki/server.key',
            'auth_v1.pki.server_crt' => $this->tmpDir . '/pki/server.crt',
            'auth_v1.pki.ca_validity_days' => 1825,
            'auth_v1.pki.server_validity_days' => 365,
            // Réduit la taille de clés pour accélérer les tests (sécurité non
            // pertinente côté test, 1024 reste rejeté par certaines libs mais
            // on garde 2048 ici pour éviter les bizarreries openssl).
            'auth_v1.pki.ca_key_bits' => 2048,
            'auth_v1.pki.server_key_bits' => 2048,
            'auth_v1.pki.jwt_key_bits' => 2048,
            'auth_v1.pki.subject_organization' => 'SambaEdu Test',
            'auth_v1.pki.subject_country' => 'FR',
            'auth_v1.jwt.active_kid' => $kid,
            'auth_v1.jwt.keys' => [
                $kid => [
                    'private' => $this->tmpDir . '/jwt/private.pem',
                    'public' => $this->tmpDir . '/jwt/public.pem',
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        // Nettoyage récursif du tmp test
        if (is_dir($this->tmpDir)) {
            $this->rmrf($this->tmpDir);
        }
        parent::tearDown();
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && ! is_link($path)) {
                $this->rmrf($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    #[Test]
    public function it_generates_the_full_pki_when_missing(): void
    {
        $initializer = new CaInitializer();
        $report = $initializer->initIfMissing();

        $this->assertSame('initialized', $report['status']);
        $this->assertContains('ca-root', $report['regenerated']);
        $this->assertContains('server', $report['regenerated']);
        $this->assertContains('jwt-keys', $report['regenerated']);

        $this->assertFileExists($this->tmpDir . '/pki/ca-root.key');
        $this->assertFileExists($this->tmpDir . '/pki/ca-root.crt');
        $this->assertFileExists($this->tmpDir . '/pki/server.key');
        $this->assertFileExists($this->tmpDir . '/pki/server.crt');
        $this->assertFileExists($this->tmpDir . '/jwt/private.pem');
        $this->assertFileExists($this->tmpDir . '/jwt/public.pem');
    }

    #[Test]
    public function it_is_idempotent_on_second_call(): void
    {
        $initializer = new CaInitializer();
        $initializer->initIfMissing();

        // Snapshot du mtime du CA pour vérifier non-régénération
        $caMtime = filemtime($this->tmpDir . '/pki/ca-root.key');
        $this->assertNotFalse($caMtime);

        clearstatcache();
        sleep(1); // garantit que mtime changerait si fichier ré-écrit

        $report = $initializer->initIfMissing();

        $this->assertSame('already_initialized', $report['status']);
        $this->assertSame([], $report['regenerated']);

        clearstatcache();
        $this->assertSame($caMtime, filemtime($this->tmpDir . '/pki/ca-root.key'));
    }

    #[Test]
    public function force_regen_creates_backups_and_new_files(): void
    {
        $initializer = new CaInitializer();
        $initializer->initIfMissing();

        $firstCaContent = (string) file_get_contents($this->tmpDir . '/pki/ca-root.crt');
        $this->assertNotEmpty($firstCaContent);

        $report = $initializer->forceRegen();

        $this->assertSame('force_regenerated', $report['status']);
        $this->assertContains('ca-root', $report['regenerated']);
        $this->assertContains('server', $report['regenerated']);
        $this->assertContains('jwt-keys', $report['regenerated']);

        // Backups présents
        $backupsCount = count(glob($this->tmpDir . '/pki/ca-root.crt.bak-*') ?: []);
        $this->assertGreaterThanOrEqual(1, $backupsCount, 'ca-root.crt backup missing');

        $newCaContent = (string) file_get_contents($this->tmpDir . '/pki/ca-root.crt');
        $this->assertNotSame($firstCaContent, $newCaContent, 'CA was supposed to be regenerated');
    }

    #[Test]
    public function regenerate_server_only_leaves_ca_intact(): void
    {
        $initializer = new CaInitializer();
        $initializer->initIfMissing();

        $caBefore = (string) file_get_contents($this->tmpDir . '/pki/ca-root.crt');
        $serverBefore = (string) file_get_contents($this->tmpDir . '/pki/server.crt');

        $report = $initializer->regenerateServerOnly();

        $this->assertSame('server_regenerated', $report['status']);
        $this->assertSame(['server'], $report['regenerated']);

        $this->assertSame(
            $caBefore,
            (string) file_get_contents($this->tmpDir . '/pki/ca-root.crt'),
            'CA root must remain unchanged on --regenerate-server-only',
        );

        $this->assertNotSame(
            $serverBefore,
            (string) file_get_contents($this->tmpDir . '/pki/server.crt'),
            'Server cert must be regenerated',
        );
    }

    #[Test]
    public function regenerate_server_only_requires_ca_present(): void
    {
        $initializer = new CaInitializer();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CA root absent');

        $initializer->regenerateServerOnly();
    }

    #[Test]
    public function private_keys_are_chmod_0600(): void
    {
        if (str_starts_with(strtolower(PHP_OS_FAMILY), 'win')) {
            $this->markTestSkipped('chmod semantics not portable on Windows');
        }

        $initializer = new CaInitializer();
        $initializer->initIfMissing();

        $caPerm = fileperms($this->tmpDir . '/pki/ca-root.key') & 0777;
        $serverPerm = fileperms($this->tmpDir . '/pki/server.key') & 0777;
        $jwtPerm = fileperms($this->tmpDir . '/jwt/private.pem') & 0777;

        $this->assertSame(0600, $caPerm, sprintf('ca-root.key perms = %o, expected 0600', $caPerm));
        $this->assertSame(0600, $serverPerm, sprintf('server.key perms = %o, expected 0600', $serverPerm));
        $this->assertSame(0600, $jwtPerm, sprintf('jwt private perms = %o, expected 0600', $jwtPerm));
    }

    #[Test]
    public function server_cert_cn_matches_se4fs_fqdn(): void
    {
        $initializer = new CaInitializer();
        $initializer->initIfMissing();

        $crtPem = (string) file_get_contents($this->tmpDir . '/pki/server.crt');
        $info = openssl_x509_parse($crtPem);
        $this->assertIsArray($info);
        $this->assertSame('se4fs-test001.lab.local', $info['subject']['CN'] ?? null);
    }

    #[Test]
    public function ca_cert_validity_is_about_five_years(): void
    {
        $initializer = new CaInitializer();
        $initializer->initIfMissing();

        $crtPem = (string) file_get_contents($this->tmpDir . '/pki/ca-root.crt');
        $info = openssl_x509_parse($crtPem);
        $this->assertIsArray($info);

        $validFrom = (int) $info['validFrom_time_t'];
        $validTo = (int) $info['validTo_time_t'];
        $deltaDays = (int) round(($validTo - $validFrom) / 86400);

        // Tolère ±1j de drift (cf. heure UTC arrondie)
        $this->assertGreaterThanOrEqual(1820, $deltaDays);
        $this->assertLessThanOrEqual(1830, $deltaDays);
    }

    #[Test]
    public function server_cert_validity_is_about_one_year(): void
    {
        $initializer = new CaInitializer();
        $initializer->initIfMissing();

        $crtPem = (string) file_get_contents($this->tmpDir . '/pki/server.crt');
        $info = openssl_x509_parse($crtPem);
        $this->assertIsArray($info);

        $validFrom = (int) $info['validFrom_time_t'];
        $validTo = (int) $info['validTo_time_t'];
        $deltaDays = (int) round(($validTo - $validFrom) / 86400);

        $this->assertGreaterThanOrEqual(360, $deltaDays);
        $this->assertLessThanOrEqual(370, $deltaDays);
    }

    #[Test]
    public function get_ca_cert_pem_returns_string(): void
    {
        $initializer = new CaInitializer();
        $initializer->initIfMissing();

        $pem = $initializer->getCaCertPem();
        $this->assertStringStartsWith('-----BEGIN CERTIFICATE-----', $pem);
        $this->assertStringContainsString('-----END CERTIFICATE-----', $pem);
    }

    #[Test]
    public function render_server_blocks_returns_apache_and_nginx(): void
    {
        $initializer = new CaInitializer();
        $blocks = $initializer->renderServerBlocks();

        $this->assertArrayHasKey('apache', $blocks);
        $this->assertArrayHasKey('nginx', $blocks);
        $this->assertStringContainsString('SSLCertificateFile', $blocks['apache']);
        $this->assertStringContainsString('ssl_certificate', $blocks['nginx']);
    }
}
