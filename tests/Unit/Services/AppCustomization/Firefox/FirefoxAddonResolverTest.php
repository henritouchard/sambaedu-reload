<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AppCustomization\Firefox;

use App\Services\AppCustomization\Firefox\FirefoxAddonResolver;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests unit — FirefoxAddonResolver (API AMO v5).
 *
 * Story 4.8 — path API privilégié vs download XPI.
 */
class FirefoxAddonResolverTest extends TestCase
{
    #[Test]
    public function is_addon_page_url_accepts_canonical_paths(): void
    {
        $this->assertTrue(FirefoxAddonResolver::isAddonPageUrl('https://addons.mozilla.org/fr/firefox/addon/clearurls/'));
        $this->assertTrue(FirefoxAddonResolver::isAddonPageUrl('https://addons.mozilla.org/firefox/addon/ublock-origin/'));
        $this->assertTrue(FirefoxAddonResolver::isAddonPageUrl('https://addons.mozilla.org/fr/firefox/addon/clearurls/?utm_source=x&utm_medium=y'));
        $this->assertTrue(FirefoxAddonResolver::isAddonPageUrl('https://addons.mozilla.org/en-US/firefox/addon/privacy-badger17/versions/'));
    }

    #[Test]
    public function is_addon_page_url_refuses_non_amo_urls(): void
    {
        $this->assertFalse(FirefoxAddonResolver::isAddonPageUrl('http://addons.mozilla.org/fr/firefox/addon/clearurls/'));  // http
        $this->assertFalse(FirefoxAddonResolver::isAddonPageUrl('https://evil.addons.mozilla.org/fr/firefox/addon/clearurls/'));
        $this->assertFalse(FirefoxAddonResolver::isAddonPageUrl('https://example.com/firefox/addon/clearurls/'));
        $this->assertFalse(FirefoxAddonResolver::isAddonPageUrl('https://addons.mozilla.org/fr/firefox/search/?q=test'));
        $this->assertFalse(FirefoxAddonResolver::isAddonPageUrl('https://addons.mozilla.org/firefox/downloads/file/4432106/clearurls-1.27.3.xpi'));
    }

    #[Test]
    public function extract_slug_handles_locale_and_querystring(): void
    {
        $this->assertSame('clearurls', FirefoxAddonResolver::extractSlug('/fr/firefox/addon/clearurls/'));
        $this->assertSame('clearurls', FirefoxAddonResolver::extractSlug('/fr/firefox/addon/clearurls'));
        $this->assertSame('ublock-origin', FirefoxAddonResolver::extractSlug('/firefox/addon/ublock-origin/'));
        $this->assertSame('privacy-badger17', FirefoxAddonResolver::extractSlug('/en-US/firefox/addon/privacy-badger17/versions/'));
        $this->assertNull(FirefoxAddonResolver::extractSlug('/fr/firefox/search/'));
    }

    #[Test]
    public function resolve_from_url_returns_full_payload(): void
    {
        $apiResponse = json_encode([
            'guid' => '{74145f27-f039-47ce-a470-a662b129930a}',
            'slug' => 'clearurls',
            'name' => ['en-US' => 'ClearURLs', 'fr' => 'ClearURLs'],
            'current_version' => [
                'version' => '1.27.3',
                'file' => [
                    'url' => 'https://addons.mozilla.org/firefox/downloads/file/4432106/clearurls-1.27.3.xpi',
                    'hash' => 'sha256:54926b6e4274d5935a5fc0daa6320f1d371e3d2f1a5877467ca3ab22a65c4f20',
                ],
            ],
        ]);

        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], $apiResponse)]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $resolver = new FirefoxAddonResolver($client);
        $result = $resolver->resolveFromUrl('https://addons.mozilla.org/fr/firefox/addon/clearurls/?utm_source=x');

        $this->assertNotNull($result);
        $this->assertSame('{74145f27-f039-47ce-a470-a662b129930a}', $result['gecko_id']);
        $this->assertSame('https://addons.mozilla.org/firefox/downloads/file/4432106/clearurls-1.27.3.xpi', $result['install_url']);
        $this->assertStringStartsWith('sha256:', $result['hash']);
        $this->assertSame('1.27.3', $result['version']);
        $this->assertSame('ClearURLs', $result['name']);
    }

    #[Test]
    public function resolve_from_url_throws_on_non_amo_url(): void
    {
        $resolver = new FirefoxAddonResolver();
        $this->expectException(\InvalidArgumentException::class);
        $resolver->resolveFromUrl('https://evil.example.com/firefox/addon/clearurls/');
    }

    #[Test]
    public function resolve_from_url_throws_on_404_addon_not_found(): void
    {
        $mock = new MockHandler([
            new Response(404, [], json_encode(['detail' => 'Not found.'])),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $resolver = new FirefoxAddonResolver($client);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/introuvable/');
        $resolver->resolveFromUrl('https://addons.mozilla.org/fr/firefox/addon/does-not-exist/');
    }

    #[Test]
    public function resolve_returns_null_when_guid_missing(): void
    {
        $mock = new MockHandler([new Response(200, [], json_encode(['slug' => 'x']))]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $resolver = new FirefoxAddonResolver($client);
        $this->assertNull($resolver->resolveFromUrl('https://addons.mozilla.org/fr/firefox/addon/x/'));
    }
}
