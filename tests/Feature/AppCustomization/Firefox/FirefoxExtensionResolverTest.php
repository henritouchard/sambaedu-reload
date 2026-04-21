<?php

declare(strict_types=1);

namespace Tests\Feature\AppCustomization\Firefox;

use App\Services\AppCustomization\Firefox\FirefoxExtensionResolver;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests feature — FirefoxExtensionResolver (SSRF guard, AC 14).
 */
class FirefoxExtensionResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app-customizations.firefox.extension_resolver.allowed_domains', ['addons.mozilla.org']);
        config()->set('app-customizations.firefox.extension_resolver.max_size', 10_485_760);
        config()->set('app-customizations.firefox.extension_resolver.timeout', 5);
        // Désactivé en test pour éviter DNS réel avec mocks Guzzle.
        // Activé par défaut en prod (cf. config/app-customizations.php).
        config()->set('app-customizations.firefox.extension_resolver.dns_rebinding_guard', false);
    }

    private function buildZipWithManifest(array $manifest): string
    {
        if (! class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive PHP extension indisponible.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'xpi');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();
        return $tmp;
    }

    private function resolverWithMockedClient(string $xpiContent, ?string $contentLength = null): FirefoxExtensionResolver
    {
        $headers = ['Content-Type' => 'application/x-xpinstall'];
        if ($contentLength !== null) {
            $headers['Content-Length'] = $contentLength;
        }

        $mock = new MockHandler([
            new Response(200, $headers, ''),           // HEAD
            new Response(200, $headers, $xpiContent),  // GET
        ]);
        $stack = HandlerStack::create($mock);
        $client = new Client(['handler' => $stack]);

        return new FirefoxExtensionResolver($client);
    }

    #[Test]
    public function url_outside_allowlist_throws_invalid_argument(): void
    {
        $resolver = new FirefoxExtensionResolver();
        $this->expectException(\InvalidArgumentException::class);
        $resolver->resolveFromUrl('https://evil.example.com/ext.xpi');
    }

    #[Test]
    public function http_scheme_is_refused(): void
    {
        $resolver = new FirefoxExtensionResolver();
        $this->expectException(\InvalidArgumentException::class);
        $resolver->resolveFromUrl('http://addons.mozilla.org/ext.xpi');
    }

    #[Test]
    public function file_scheme_is_refused(): void
    {
        $resolver = new FirefoxExtensionResolver();
        $this->expectException(\InvalidArgumentException::class);
        $resolver->resolveFromUrl('file:///etc/passwd');
    }

    #[Test]
    public function oversized_content_length_is_refused(): void
    {
        $resolver = $this->resolverWithMockedClient('payload', '20000000');
        $this->expectException(\InvalidArgumentException::class);
        $resolver->resolveFromUrl('https://addons.mozilla.org/ext.xpi');
    }

    #[Test]
    public function valid_xpi_with_gecko_id_returns_id(): void
    {
        $tmp = $this->buildZipWithManifest([
            'applications' => ['gecko' => ['id' => 'addon@example.com']],
        ]);
        $xpiContent = (string) file_get_contents($tmp);
        @unlink($tmp);

        $resolver = $this->resolverWithMockedClient($xpiContent);
        $id = $resolver->resolveFromUrl('https://addons.mozilla.org/ext.xpi');

        $this->assertSame('addon@example.com', $id);
    }

    #[Test]
    public function dns_rebinding_guard_refuses_loopback_ip(): void
    {
        config()->set('app-customizations.firefox.extension_resolver.dns_rebinding_guard', true);
        config()->set('app-customizations.firefox.extension_resolver.allowed_domains', ['127.0.0.1']);

        $resolver = new FirefoxExtensionResolver();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/IP privée\/loopback/');
        $resolver->resolveFromUrl('https://127.0.0.1/ext.xpi');
    }

    #[Test]
    public function dns_rebinding_guard_refuses_rfc1918_ip(): void
    {
        config()->set('app-customizations.firefox.extension_resolver.dns_rebinding_guard', true);
        config()->set('app-customizations.firefox.extension_resolver.allowed_domains', ['192.168.122.50']);

        $resolver = new FirefoxExtensionResolver();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/IP privée\/loopback/');
        $resolver->resolveFromUrl('https://192.168.122.50/ext.xpi');
    }

    #[Test]
    public function xpi_without_manifest_returns_null(): void
    {
        if (! class_exists('ZipArchive')) {
            $this->markTestSkipped('ZipArchive PHP extension indisponible.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'xpi');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('other.txt', 'nope');
        $zip->close();
        $xpiContent = (string) file_get_contents($tmp);
        @unlink($tmp);

        $resolver = $this->resolverWithMockedClient($xpiContent);
        $this->assertNull($resolver->resolveFromUrl('https://addons.mozilla.org/ext.xpi'));
    }
}
