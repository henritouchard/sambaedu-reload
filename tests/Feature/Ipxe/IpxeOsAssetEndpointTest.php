<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Ipxe\Http\Controllers\IpxeOsAssetController;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Tests de la route unique de service des assets d'install OS
 * `GET /ipxe/os/{path}` ({@see IpxeOsAssetController}).
 */
class IpxeOsAssetEndpointTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/ipxe-os-asset-' . uniqid('', true);
        @mkdir($this->root . '/debian-installer/amd64', 0775, true);
        file_put_contents($this->root . '/debian-installer/amd64/linux', 'KERNEL-BYTES');

        config([
            // LAN-only (iso autres endpoints iPXE).
            'auth_v1.bootstrap.allowed_subnets' => '127.0.0.0/8,192.168.0.0/16,10.0.0.0/8',
            'ipxe.actions.os_assets.roots' => [$this->root],
            'ipxe.actions.os_assets.xsendfile_enabled' => false,
            'ipxe.actions.os_assets.xsendfile_header' => 'X-Sendfile',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/debian-installer/amd64/linux');
        @rmdir($this->root . '/debian-installer/amd64');
        @rmdir($this->root . '/debian-installer');
        @rmdir($this->root);

        parent::tearDown();
    }

    #[Test]
    public function it_serves_an_existing_os_asset_via_binary_file_response(): void
    {
        $response = $this->get('/ipxe/os/debian-installer/amd64/linux');

        $response->assertOk();
        self::assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        self::assertSame(
            realpath($this->root . '/debian-installer/amd64/linux'),
            $response->baseResponse->getFile()->getRealPath(),
        );
        self::assertStringContainsString(
            'application/octet-stream',
            (string) $response->headers->get('Content-Type'),
        );
    }

    #[Test]
    public function it_returns_404_for_a_missing_asset(): void
    {
        $this->get('/ipxe/os/debian-installer/amd64/does-not-exist')->assertStatus(404);
    }

    #[Test]
    public function it_emits_xsendfile_header_when_enabled_instead_of_streaming(): void
    {
        config(['ipxe.actions.os_assets.xsendfile_enabled' => true]);

        $response = $this->get('/ipxe/os/debian-installer/amd64/linux');

        $response->assertOk();
        // Apache (mod_xsendfile) servira le fichier ; pas de BinaryFileResponse.
        self::assertNotInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $response->assertHeader('X-Sendfile', realpath($this->root . '/debian-installer/amd64/linux'));
    }

    #[Test]
    public function it_blocks_path_traversal_outside_the_roots(): void
    {
        // Teste directement le garde anti-traversal (les `..` sont normalises
        // par le client HTTP avant le routing, donc on invoque le controller).
        $this->expectException(NotFoundHttpException::class);

        (new IpxeOsAssetController())->handle(
            Request::create('/'),
            '../../../../etc/passwd',
        );
    }

    #[Test]
    public function it_returns_404_when_no_root_is_configured(): void
    {
        config(['ipxe.actions.os_assets.roots' => []]);

        $this->get('/ipxe/os/debian-installer/amd64/linux')->assertStatus(404);
    }
}
