<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AppCustomization\Thunderbird;

use App\Services\AppCustomization\Thunderbird\ThunderbirdPolicyAdapter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests unit — ThunderbirdPolicyAdapter — parité `tb_import_policy` (AC 6).
 */
class ThunderbirdPolicyAdapterTest extends TestCase
{
    private ThunderbirdPolicyAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app-customizations.template_paths.thunderbird', [
            base_path('tests/fixtures/thunderbird/template.json'),
        ]);

        $this->adapter = new ThunderbirdPolicyAdapter();
    }

    #[Test]
    public function template_is_loaded(): void
    {
        $template = $this->adapter->getTemplate();
        $this->assertIsArray($template);
    }

    #[Test]
    public function apply_auto_uses_http_prefix_on_manual_proxy(): void
    {
        $template = ['policies' => []];
        $config = [
            'proxy_type' => 'manuel',
            'proxy_address' => '10.0.0.5',
            'proxy_port' => '8080',
        ];

        $result = $this->adapter->applyAuto($template, $config);

        // DIFFÉRENCE Firefox : préfixe http:// obligatoire
        $this->assertSame('http://10.0.0.5:8080', $result['policies']['Proxy']['HTTPProxy']);
        $this->assertSame('manual', $result['policies']['Proxy']['Mode']);
    }

    #[Test]
    public function apply_auto_does_not_inject_popup_blocking(): void
    {
        $template = ['policies' => []];
        $result = $this->adapter->applyAuto($template, ['proxy_type' => 'aucun']);

        $this->assertArrayNotHasKey('PopupBlocking', $result['policies']);
    }

    #[Test]
    public function apply_auto_injects_dns_over_https(): void
    {
        $template = ['policies' => []];
        $result = $this->adapter->applyAuto($template, ['proxy_type' => 'aucun']);

        $this->assertSame(['Enabled' => false, 'Locked' => true], $result['policies']['DNSOverHTTPS']);
    }

    #[Test]
    public function merge_overrides_is_recursive(): void
    {
        $base = ['policies' => ['Proxy' => ['Mode' => 'manual', 'Locked' => true]]];
        $ovr = ['policies' => ['Proxy' => ['HTTPProxy' => 'http://proxy:3128']]];
        $out = $this->adapter->mergeOverrides($base, $ovr);

        $this->assertSame('manual', $out['policies']['Proxy']['Mode']);
        $this->assertSame('http://proxy:3128', $out['policies']['Proxy']['HTTPProxy']);
    }

    #[Test]
    public function render_form_component_returns_thunderbird_form(): void
    {
        $this->assertSame('components::organisms.thunderbird.customize-form', $this->adapter->renderFormComponent());
    }

    #[Test]
    public function strip_non_whitelisted_keeps_proxy_only(): void
    {
        $clean = $this->adapter->stripNonWhitelistedOverrides([
            'policies' => [
                'Proxy' => ['Mode' => 'none'],
                'Homepage' => ['URL' => 'https://x/'], // hors whitelist Thunderbird MVP
            ],
        ]);

        $this->assertArrayHasKey('Proxy', $clean['policies']);
        $this->assertArrayNotHasKey('Homepage', $clean['policies']);
    }
}
