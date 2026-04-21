<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AppCustomization\Firefox;

use App\Services\AppCustomization\Firefox\FirefoxAddonDiscovery;
use App\Services\AppCustomization\Firefox\FirefoxAddonResolver;
use App\Services\AppCustomization\Firefox\FirefoxExtensionResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests unit — dispatcher qui route URL AMO → API vs XPI → download.
 *
 * Story 4.8 — rétrocompat addons custom (hors AMO).
 */
class FirefoxAddonDiscoveryTest extends TestCase
{
    #[Test]
    public function amo_page_url_is_routed_to_addon_resolver(): void
    {
        $addon = $this->createMock(FirefoxAddonResolver::class);
        $addon->expects($this->once())
            ->method('resolveFromUrl')
            ->with('https://addons.mozilla.org/fr/firefox/addon/clearurls/')
            ->willReturn([
                'gecko_id' => '{abcd}',
                'install_url' => 'https://addons.mozilla.org/firefox/downloads/file/1/a.xpi',
                'hash' => 'sha256:xx',
                'name' => 'ClearURLs',
                'version' => '1.27.3',
            ]);

        $ext = $this->createMock(FirefoxExtensionResolver::class);
        $ext->expects($this->never())->method('resolveFromUrl');

        $discovery = new FirefoxAddonDiscovery($addon, $ext);
        $result = $discovery->resolveFromUrl('https://addons.mozilla.org/fr/firefox/addon/clearurls/');

        $this->assertNotNull($result);
        $this->assertSame('amo', $result['source']);
        $this->assertSame('{abcd}', $result['gecko_id']);
        $this->assertSame('1.27.3', $result['version']);
    }

    #[Test]
    public function non_amo_xpi_url_falls_back_to_extension_resolver(): void
    {
        $addon = $this->createMock(FirefoxAddonResolver::class);
        $addon->expects($this->never())->method('resolveFromUrl');

        $ext = $this->createMock(FirefoxExtensionResolver::class);
        $ext->expects($this->once())
            ->method('resolveFromUrl')
            ->with('https://mon-depot.etab.local/custom-addon.xpi')
            ->willReturn('custom@etab.local');

        // Permettre au domain custom d'être accepté par l'allowlist extension.
        config()->set('app-customizations.firefox.extension_resolver.allowed_domains', [
            'mon-depot.etab.local',
        ]);

        $discovery = new FirefoxAddonDiscovery($addon, $ext);
        $result = $discovery->resolveFromUrl('https://mon-depot.etab.local/custom-addon.xpi');

        $this->assertNotNull($result);
        $this->assertSame('xpi', $result['source']);
        $this->assertSame('custom@etab.local', $result['gecko_id']);
        $this->assertSame('https://mon-depot.etab.local/custom-addon.xpi', $result['install_url']);
        $this->assertNull($result['hash']);
        $this->assertNull($result['name']);
    }

    #[Test]
    public function xpi_resolver_returning_null_propagates_null(): void
    {
        $addon = $this->createMock(FirefoxAddonResolver::class);

        $ext = $this->createMock(FirefoxExtensionResolver::class);
        $ext->method('resolveFromUrl')->willReturn(null);

        $discovery = new FirefoxAddonDiscovery($addon, $ext);
        $this->assertNull($discovery->resolveFromUrl('https://mon-depot.etab.local/broken.xpi'));
    }
}
