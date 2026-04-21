<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AppCustomization\Firefox;

use App\Services\AppCustomization\Firefox\FirefoxPolicyAdapter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests unit — FirefoxPolicyAdapter — parité avec `ff_import_policy` legacy (AC 5).
 */
class FirefoxPolicyAdapterTest extends TestCase
{
    private FirefoxPolicyAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app-customizations.template_paths.firefox', [
            base_path('tests/fixtures/firefox/template.json'),
        ]);

        $this->adapter = new FirefoxPolicyAdapter();
    }

    #[Test]
    public function template_is_loaded_from_configured_path(): void
    {
        $template = $this->adapter->getTemplate();
        $this->assertIsArray($template);
        $this->assertArrayHasKey('policies', $template);
    }

    #[Test]
    public function apply_auto_injects_proxy_manual_with_http_address(): void
    {
        $template = ['policies' => []];
        $config = [
            'se4_url' => 'http://se4fs',
            'se4fs_name' => 'se4fs',
            'proxy_type' => 'manuel',
            'proxy_address' => '192.168.1.10',
            'proxy_port' => '3128',
        ];

        $result = $this->adapter->applyAuto($template, $config);

        $this->assertSame('manual', $result['policies']['Proxy']['Mode']);
        $this->assertSame('192.168.1.10:3128', $result['policies']['Proxy']['HTTPProxy']);
        $this->assertTrue($result['policies']['Proxy']['Locked']);
        $this->assertSame(['http://se4fs'], $result['policies']['PopupBlocking']['Allow']);
        $this->assertSame(['Enabled' => false, 'Locked' => true], $result['policies']['DNSOverHTTPS']);
        $this->assertTrue($result['policies']['Preferences']['security.ssl.enable_ocsp_stapling']);
    }

    #[Test]
    public function apply_auto_maps_automatic_mode_to_auto_config(): void
    {
        $template = ['policies' => []];
        $config = [
            'se4_url' => 'http://fs.example.org',
            'se4fs_name' => 'fs.example.org',
            'proxy_type' => 'automatique',
            'proxy_url' => 'http://wpad.example.org/wpad.dat',
        ];

        $result = $this->adapter->applyAuto($template, $config);

        $this->assertSame('autoConfig', $result['policies']['Proxy']['Mode']);
        $this->assertSame('http://wpad.example.org/wpad.dat', $result['policies']['Proxy']['AutoConfigURL']);
    }

    #[Test]
    public function apply_auto_maps_aucun_mode_to_none(): void
    {
        $template = ['policies' => []];
        $config = [
            'se4_url' => 'http://se4',
            'se4fs_name' => 'se4',
            'proxy_type' => 'aucun',
        ];

        $result = $this->adapter->applyAuto($template, $config);
        $this->assertSame('none', $result['policies']['Proxy']['Mode']);
        $this->assertArrayNotHasKey('HTTPProxy', $result['policies']['Proxy']);
    }

    #[Test]
    public function merge_overrides_is_recursive(): void
    {
        $base = ['policies' => ['Homepage' => ['URL' => 'https://a/', 'Locked' => false]]];
        $ovr  = ['policies' => ['Homepage' => ['URL' => 'https://b/']]];
        $out = $this->adapter->mergeOverrides($base, $ovr);
        $this->assertSame('https://b/', $out['policies']['Homepage']['URL']);
        $this->assertFalse($out['policies']['Homepage']['Locked']);
    }

    #[Test]
    public function validate_policies_flags_bad_homepage_url(): void
    {
        $errors = $this->adapter->validatePolicies([
            'policies' => [
                'Homepage' => ['URL' => 'not-a-url'],
            ],
        ]);
        $this->assertArrayHasKey('Homepage.URL', $errors);
    }

    #[Test]
    public function validate_policies_accepts_valid_whitelisted_keys(): void
    {
        $errors = $this->adapter->validatePolicies([
            'policies' => [
                'Homepage' => ['URL' => 'https://example.fr/'],
                'Bookmarks' => [],
                'ExtensionSettings' => [],
            ],
        ]);
        $this->assertSame([], $errors);
    }

    #[Test]
    public function strip_non_whitelisted_overrides_filters_out_unknown_keys(): void
    {
        $clean = $this->adapter->stripNonWhitelistedOverrides([
            'policies' => [
                'Homepage' => ['URL' => 'https://a/'],
                'Proxy' => ['Mode' => 'manual'], // hors whitelist
                'UnknownKey' => 'whatever',
            ],
        ]);

        $this->assertArrayHasKey('Homepage', $clean['policies']);
        $this->assertArrayNotHasKey('Proxy', $clean['policies']);
        $this->assertArrayNotHasKey('UnknownKey', $clean['policies']);
    }

    #[Test]
    public function export_to_fs_writes_atomically(): void
    {
        $tmpDir = sys_get_temp_dir() . '/ff-export-' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0755, true);
        $path = $tmpDir . '/default.json';

        $policies = ['policies' => ['Homepage' => ['URL' => 'https://x/']]];
        $this->assertTrue($this->adapter->exportToFs($policies, $path));
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertSame('https://x/', $decoded['policies']['Homepage']['URL']);

        // Cleanup
        @unlink($path);
        @rmdir($tmpDir);
    }

    #[Test]
    public function render_form_component_returns_firefox_form(): void
    {
        $this->assertSame('components::organisms.firefox.customize-form', $this->adapter->renderFormComponent());
    }
}
