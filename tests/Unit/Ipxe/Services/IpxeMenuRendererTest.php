<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Services;

use App\Ipxe\Services\IpxeMenuRenderer;
use App\Models\Workstation;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.1 — AC3.1 / AC3.2 / T3.6.
 *
 * Tests unitaires du rendu des 3 templates Blade
 * (`handshake`, `default`, `known`) + helper `renderBootDiskFallback()`.
 */
class IpxeMenuRendererTest extends TestCase
{
    private IpxeMenuRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();
        $this->renderer = $this->app->make(IpxeMenuRenderer::class);
    }

    #[Test]
    public function it_renders_handshake_starts_with_shebang_ipxe(): void
    {
        $body = $this->renderer->renderHandshake();

        self::assertStringStartsWith('#!ipxe', $body);
    }

    #[Test]
    public function it_renders_handshake_contains_param_mac_and_uuid(): void
    {
        $body = $this->renderer->renderHandshake();

        self::assertStringContainsString('param mac ${net0/mac}', $body);
        self::assertStringContainsString('param uuid ${uuid}', $body);
        self::assertStringContainsString('param product ${product}', $body);
        self::assertStringContainsString('chain --replace --autofree boot##params', $body);
    }

    #[Test]
    public function it_renders_handshake_ends_with_newline(): void
    {
        $body = $this->renderer->renderHandshake();

        self::assertStringEndsWith("\n", $body);
    }

    #[Test]
    public function it_renders_unknown_menu_contains_only_default_item(): void
    {
        $body = $this->renderer->renderUnknown('192.168.1.42');

        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('192.168.1.42', $body);
        self::assertStringContainsString(':menu', $body);
        self::assertStringContainsString('item --key 0 exit', $body);
        // Pas d'item login/action (poste inconnu).
        self::assertStringNotContainsString('item --key 1 login', $body);
        self::assertStringNotContainsString('item --key 2 action', $body);
    }

    #[Test]
    public function it_renders_known_menu_contains_login_and_default_items(): void
    {
        $ws = Workstation::create([
            'name' => 'PC-SALLE-101',
            'uuid' => 'abcdef12-3456-7890-abcd-ef1234567890',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'ip' => '192.168.1.42',
            'status' => 'active',
        ]);

        $body = $this->renderer->renderKnown($ws, null, 'http://se4fs.lan');

        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('PC-SALLE-101', $body);
        self::assertStringContainsString('item --key 1 login', $body);
        self::assertStringContainsString('item --key 3 default', $body);
        self::assertStringContainsString(':login', $body);
        self::assertStringContainsString(':default', $body);
        self::assertStringContainsString('http://se4fs.lan/ipxe/admin.php', $body);
    }

    #[Test]
    public function it_renders_known_menu_includes_action_when_provided(): void
    {
        $ws = Workstation::create([
            'name' => 'PC-SALLE-101',
            'uuid' => 'abcdef12-3456-7890-abcd-ef1234567890',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'status' => 'active',
        ]);

        $action = ['name' => 'install_linux', 'label' => 'Install Debian 12'];
        $body = $this->renderer->renderKnown($ws, $action, 'http://se4fs.lan');

        self::assertStringContainsString('item --key 2 action', $body);
        self::assertStringContainsString('Install Debian 12', $body);
        self::assertStringContainsString(':action', $body);
    }

    #[Test]
    public function it_renders_boot_disk_fallback_with_uefi_branches(): void
    {
        $body = $this->renderer->renderBootDiskFallback();

        // Branchement UEFI initial.
        self::assertStringContainsString('iseq ${platform} efi && goto uefi || goto legacy', $body);
        self::assertStringContainsString(':uefi', $body);
        self::assertStringContainsString(':legacy', $body);
    }

    #[Test]
    public function it_renders_boot_disk_fallback_with_force_uefi_products(): void
    {
        $body = $this->renderer->renderBootDiskFallback();

        // Substitution espaces → ${sp} pour les models avec espace.
        self::assertStringContainsString('OptiPlex${sp}3050', $body);
        self::assertStringContainsString('HP${sp}280${sp}G2${sp}SFF', $body);
        // Models sans espace inchangés.
        self::assertStringContainsString('10M8S1B000', $body);
        // Sanboot final.
        self::assertStringContainsString('sanboot --no-describe --drive 0x80', $body);
    }

    #[Test]
    public function it_renders_output_does_not_contain_php_tags_in_handshake(): void
    {
        $body = $this->renderer->renderHandshake();

        self::assertStringNotContainsString('<?php', $body);
        self::assertStringNotContainsString('<?=', $body);
        self::assertStringNotContainsString('?>', $body);
    }

    #[Test]
    public function it_renders_output_does_not_contain_php_tags_in_unknown(): void
    {
        $body = $this->renderer->renderUnknown('192.168.1.42');

        self::assertStringNotContainsString('<?php', $body);
        self::assertStringNotContainsString('<?=', $body);
        self::assertStringNotContainsString('?>', $body);
    }

    #[Test]
    public function it_renders_output_is_ascii_only(): void
    {
        $ws = Workstation::create([
            'name' => 'PC-ÉCOLE-101',  // Accent fr volontaire en input
            'uuid' => 'abcdef12-3456-7890-abcd-ef1234567890',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'status' => 'active',
        ]);

        $body = $this->renderer->renderKnown($ws, null, 'http://se4fs.lan');

        // Tous les bytes doivent être ASCII (0-127). Le sanitizer doit avoir
        // remplacé les accents par `?`.
        for ($i = 0, $len = strlen($body); $i < $len; $i++) {
            $byte = ord($body[$i]);
            self::assertLessThan(
                128,
                $byte,
                "Caractère non-ASCII détecté à l'offset {$i} (byte=0x" . dechex($byte) . ') — '
                . 'le firmware iPXE rejette l\'ASCII étendu.',
            );
        }
    }
}
