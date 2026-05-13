<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Config\PasswordPolicyConfig;
use App\Config\SambaEduConfig;
use App\Dto\AppCustomization\AppContext;
use App\Gpo\Services\NetworkScriptGenerator;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `NetworkScriptGenerator` — Story 16.3b AC5.3.
 *
 * Le pdbedit ssh n'est pas couvert ici (dépend de l'environnement réseau et
 * d'un binaire externe — testé en smoke VM via `docs/qa/domains/gpo.md`).
 */
class NetworkScriptGeneratorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeConfig(array $kv): SambaEduConfig
    {
        /** @var SambaEduConfig&\Mockery\MockInterface $mock */
        $mock = Mockery::mock(SambaEduConfig::class);
        $mock->shouldReceive('get')
            ->andReturnUsing(function (string $key, mixed $default = null) use ($kv): mixed {
                return $kv[$key] ?? $default;
            });
        $mock->shouldReceive('has')
            ->andReturnUsing(fn(string $key) => array_key_exists($key, $kv));
        return $mock;
    }

    private function makeContext(array $raw = []): AppContext
    {
        return AppContext::fromApcuArray($raw + [
            'user' => ['cn' => 'jdoe'],
            'machine' => ['cn' => 'post01', 'samaccountname' => 'POST01$'],
            'salle' => 'salle1',
            'list_u' => [],
            'os' => 'linux',
            'time' => time(),
        ]);
    }

    #[Test]
    public function it_builds_startup_linux_header(): void
    {
        $generator = new NetworkScriptGenerator($this->makeConfig(['proxy_type' => 'aucun']));
        $out = $generator->buildStartup($this->makeContext(), 'linux');

        $this->assertStringStartsWith("#!/bin/bash\n#startup\n# script de configuration du reseau Linux\n", $out);
        $this->assertStringNotContainsString("\r\n", $out, 'pas de CRLF iso-bytes');
    }

    #[Test]
    public function it_generates_wpa_psk_block_when_configured(): void
    {
        $generator = new NetworkScriptGenerator($this->makeConfig([
            'wpa_ssid' => 'MyWifi',
            'wpa_password' => 'secret123',
            'proxy_type' => 'aucun',
        ]));
        $out = $generator->buildStartup($this->makeContext(), 'linux');

        $this->assertStringContainsString('"$ssid" == "MyWifi"', $out);
        $this->assertStringContainsString('802-11-wireless-security.psk secret123', $out);
        $this->assertStringContainsString('wpa-psk', $out);
    }

    #[Test]
    public function it_skips_wpa_block_when_not_configured(): void
    {
        $generator = new NetworkScriptGenerator($this->makeConfig(['proxy_type' => 'aucun']));
        $out = $generator->buildStartup($this->makeContext(), 'linux');

        $this->assertStringNotContainsString('wpa-psk', $out);
        $this->assertStringNotContainsString('802-11-wireless-security.psk', $out);
    }

    #[Test]
    public function it_generates_8021x_wired_block_when_configured(): void
    {
        $generator = new NetworkScriptGenerator($this->makeConfig([
            '802_1x_wired' => '1',
            'proxy_type' => 'aucun',
            // se4ad_name/domain vides → fetchMachineKey retourne '' sans exec
            'se4ad_name' => '',
            'domain' => '',
        ]));
        $out = $generator->buildStartup($this->makeContext(), 'linux');

        $this->assertStringContainsString('802-1x.eap peap', $out);
        // identity = host/MACHINE_UPPER (cf. legacy network.inc.php:29)
        $this->assertStringContainsString('802-1x.identity host/POST01$', $out);
        $this->assertStringContainsString('802-1x.phase2-auth mschapv2', $out);
    }

    #[Test]
    public function it_emits_system_proxy_aucun_block(): void
    {
        $generator = new NetworkScriptGenerator($this->makeConfig(['proxy_type' => 'aucun']));
        $out = $generator->systemProxy();

        $this->assertStringContainsString('profile_file="/etc/profile"', $out);
        // Mode aucun → on sed -i /no_proxy=/d.
        $this->assertStringContainsString("sed -i '/no_proxy=/d' \$profile_file", $out);
        $this->assertStringContainsString("rm -f /etc/apt/apt.conf.d/99proxy", $out);
        $this->assertStringNotContainsString('Acquire::http::proxy', $out);
    }

    #[Test]
    public function it_emits_system_proxy_manuel_block_with_apt(): void
    {
        $generator = new NetworkScriptGenerator($this->makeConfig([
            'proxy_type' => 'manuel',
            'proxy_address' => '10.0.0.42',
            'proxy_port' => '8080',
            'domain' => 'example.local',
            'se4fs_name' => 'se4fs',
            'apt_proxy' => 'http://apt.proxy.local:3142',
        ]));
        $out = $generator->systemProxy();

        $this->assertStringContainsString('http_proxy="http://10.0.0.42:8080"', $out);
        $this->assertStringContainsString('https_proxy = http://10.0.0.42:8080', $out);
        // apt_proxy explicite est utilisé prioritairement.
        $this->assertStringContainsString('Acquire::http::proxy "http://apt.proxy.local:3142";', $out);
        // no_proxy fallback = .domain,se4fs_name
        $this->assertStringContainsString('no_proxy="' . '.example.local,se4fs' . '"', $out);
    }

    #[Test]
    public function it_emits_gnome_proxy_manuel_block(): void
    {
        $generator = new NetworkScriptGenerator($this->makeConfig([
            'proxy_type' => 'manuel',
            'proxy_address' => '10.0.0.42',
            'proxy_port' => '8080',
            'domain' => 'example.local',
        ]));
        $out = $generator->gnomeProxy();

        $this->assertStringContainsString("gsettings set org.gnome.system.proxy mode 'manual'", $out);
        $this->assertStringContainsString("gsettings set org.gnome.system.proxy.http host '10.0.0.42'", $out);
        $this->assertStringContainsString('gsettings set org.gnome.system.proxy.http port 8080', $out);
        $this->assertStringContainsString('"localhost", "127.0.0.0/8", ".example.local", "::1"', $out);
    }

    #[Test]
    public function it_emits_gnome_proxy_aucun_block(): void
    {
        $generator = new NetworkScriptGenerator($this->makeConfig(['proxy_type' => 'aucun']));
        $out = $generator->gnomeProxy();

        $this->assertStringContainsString("gsettings set org.gnome.system.proxy mode 'none'", $out);
    }

    #[Test]
    public function it_returns_empty_for_non_linux_os(): void
    {
        // AC1.6 — os=windows body vide iso-legacy (bug legacy reproduit).
        $generator = new NetworkScriptGenerator($this->makeConfig(['proxy_type' => 'aucun']));
        $this->assertSame('', $generator->buildStartup($this->makeContext(), 'windows'));
        $this->assertSame('', $generator->buildLogon($this->makeContext(), 'windows'));
    }

    #[Test]
    public function it_builds_logon_header_with_gnome_proxy(): void
    {
        $generator = new NetworkScriptGenerator($this->makeConfig([
            'proxy_type' => 'aucun',
        ]));
        $out = $generator->buildLogon($this->makeContext(), 'linux');

        $this->assertStringStartsWith("#!/bin/bash\n#logon\n# script de configuration du reseau Linux\n", $out);
        $this->assertStringContainsString("gsettings set org.gnome.system.proxy mode 'none'", $out);
    }
}
