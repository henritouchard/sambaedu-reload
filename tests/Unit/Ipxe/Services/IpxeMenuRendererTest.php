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
    public function it_renders_known_menu_with_login_chain_to_native_admin(): void
    {
        // Story 3.2 — AC4.4 — la chain `:login` cible maintenant la route
        // native `/ipxe/admin` (sans `.php`) au lieu du legacy
        // `/ipxe/admin.php`. Test mis à jour iso AC4.4.
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
        // Story 3.2 — la cible native :
        self::assertStringContainsString('http://se4fs.lan/ipxe/admin##params', $body);
        // Story 3.2 — l'ancienne cible legacy NE DOIT PLUS être présente :
        self::assertStringNotContainsString('/ipxe/admin.php##params', $body);
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

    /* ------------------------------------------------------------------
     * Story 3.2 — AC4.1 / AC4.2 / AC4.3 — handshake parametré + admin + maintenance
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_renders_handshake_without_target_iso_31(): void
    {
        // Non-régression critique : renderHandshake() sans param doit
        // continuer à rendre la chaîne `chain ... boot##params` iso-3.1.
        $body = $this->renderer->renderHandshake();

        self::assertStringContainsString('chain --replace --autofree boot##params', $body);
        self::assertStringNotContainsString('admin##params', $body);
        self::assertStringNotContainsString('maintenance##params', $body);
    }

    #[Test]
    public function it_renders_handshake_with_admin_target_chains_to_admin(): void
    {
        $body = $this->renderer->renderHandshake('admin');

        self::assertStringContainsString('chain --replace --autofree admin##params', $body);
        self::assertStringNotContainsString('boot##params', $body);
    }

    #[Test]
    public function it_renders_handshake_with_maintenance_target(): void
    {
        $body = $this->renderer->renderHandshake('maintenance');

        self::assertStringContainsString('chain --replace --autofree maintenance##params', $body);
    }

    #[Test]
    public function it_renders_handshake_with_action_target_chains_to_action_path(): void
    {
        $body = $this->renderer->renderHandshake('action/rescuecd');

        self::assertStringContainsString('chain --replace --autofree action/rescuecd##params', $body);
    }

    #[Test]
    public function it_renders_admin_menu_for_known_workstation(): void
    {
        $ws = Workstation::create([
            'name' => 'PC-SALLE-101',
            'uuid' => 'abcdef12-3456-7890-abcd-ef1234567890',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'status' => 'active',
        ]);

        $body = $this->renderer->renderAdminMenu($ws, '192.168.1.42', 'http://se4fs.lan');

        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('PC-SALLE-101', $body);
        self::assertStringContainsString('item --key m maintenance', $body);
        self::assertStringContainsString('item --key x exit', $body);
        self::assertStringContainsString('item --key r retour', $body);
        // Chain vers /ipxe/maintenance natif.
        self::assertStringContainsString(':maintenance', $body);
        self::assertStringContainsString('http://se4fs.lan/ipxe/maintenance##params', $body);
        // Chain retour vers /ipxe/boot.
        self::assertStringContainsString('http://se4fs.lan/ipxe/boot##params', $body);
        // Story 3.3 — AC6.6 — items enrollment activés pour poste connu.
        self::assertStringContainsString('item --key n set-name', $body);
        self::assertStringContainsString('item --key a salle', $body);
        self::assertStringContainsString('item --key p parcs', $body);
        self::assertStringContainsString('item --key e enleveparc', $body);
        // Sections de chain enrollment.
        self::assertStringContainsString('http://se4fs.lan/ipxe/enrollment/name##params', $body);
        self::assertStringContainsString('http://se4fs.lan/ipxe/enrollment/room##params', $body);
        self::assertStringContainsString('http://se4fs.lan/ipxe/enrollment/parc-add##params', $body);
        self::assertStringContainsString('http://se4fs.lan/ipxe/enrollment/parc-remove##params', $body);
    }

    #[Test]
    public function it_renders_admin_menu_minimal_for_unknown(): void
    {
        // Story 3.3 — AC6.6 / T6.8 — la branche poste inconnu rend l'item
        // `(n) set-name` (remplace le message neutre 3.2) + chain enrollment.
        $body = $this->renderer->renderAdminMenu(null, '192.168.1.42', 'http://se4fs.lan');

        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('item --key n set-name', $body);
        self::assertStringContainsString('item --key x exit', $body);
        self::assertStringContainsString('item --key r retour', $body);
        // Pas d'item maintenance ni salle/parc (poste inconnu — flow seulement
        // `name` ouvre l'enrollment initial).
        self::assertStringNotContainsString('item --key m maintenance', $body);
        self::assertStringNotContainsString('item --key a salle', $body);
        self::assertStringNotContainsString('item --key p parcs', $body);
        // Chain vers l'endpoint d'enrollment.
        self::assertStringContainsString('http://se4fs.lan/ipxe/enrollment/name##params', $body);
    }

    #[Test]
    public function it_renders_admin_menu_hides_enrollment_items_when_disabled(): void
    {
        // Story 3.3 — D11 — feature flag `ipxe.enrollment.enabled = false`
        // masque tous les items enrollment côté template admin.
        \Illuminate\Support\Facades\Config::set('ipxe.enrollment.enabled', false);

        $ws = Workstation::create([
            'name' => 'PC-DISABLED',
            'uuid' => 'cccc1111-bbbb-cccc-dddd-eeeeffff1111',
            'mac' => 'aa:bb:cc:dd:ee:de',
            'status' => 'active',
        ]);

        $body = $this->renderer->renderAdminMenu($ws, '192.168.1.42', 'http://se4fs.lan');

        self::assertStringNotContainsString('item --key n set-name', $body);
        self::assertStringNotContainsString('item --key a salle', $body);
        self::assertStringNotContainsString('item --key p parcs', $body);
        // Mais maintenance reste accessible (poste connu).
        self::assertStringContainsString('item --key m maintenance', $body);
    }

    #[Test]
    public function it_renders_admin_menu_with_timeout_from_config(): void
    {
        \Illuminate\Support\Facades\Config::set('ipxe.admin.menu_timeout_ms', 30000);

        $ws = Workstation::create([
            'name' => 'PC-X',
            'uuid' => 'aaaa1111-bbbb-cccc-dddd-eeeeffffaaaa',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'status' => 'active',
        ]);

        $body = $this->renderer->renderAdminMenu($ws, '192.168.1.42', 'http://se4fs.lan');

        self::assertStringContainsString('set menu-timeout 30000', $body);
    }

    #[Test]
    public function it_renders_maintenance_menu_with_all_items(): void
    {
        $ws = Workstation::create([
            'name' => 'PC-MNT-1',
            'uuid' => 'bbbbcccc-1111-2222-3333-444455556666',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'status' => 'active',
        ]);

        $body = $this->renderer->renderMaintenanceMenu($ws, '192.168.1.50', 'http://se4fs.lan');

        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('item --key c rescuecd', $body);
        self::assertStringContainsString('item --key w winpe', $body);
        self::assertStringContainsString('item --key f factory_reset', $body);
        self::assertStringContainsString('item --key s shell', $body);
        self::assertStringContainsString('item --key r retour', $body);
        self::assertStringContainsString('item --key x exit', $body);

        // Chains vers actions natives.
        self::assertStringContainsString('http://se4fs.lan/ipxe/action/rescuecd##params', $body);
        self::assertStringContainsString('http://se4fs.lan/ipxe/action/winpe##params', $body);
        self::assertStringContainsString('http://se4fs.lan/ipxe/action/factory_reset##params', $body);
        // Chain retour vers /ipxe/admin (pas /ipxe/boot).
        self::assertStringContainsString('http://se4fs.lan/ipxe/admin##params', $body);
    }

    #[Test]
    public function it_renders_maintenance_menu_uses_sysrescuecd_background(): void
    {
        $body = $this->renderer->renderMaintenanceMenu(null, '192.168.1.50', 'http://se4fs.lan');

        self::assertStringContainsString('png/sysrescuecd.png', $body);
    }

    #[Test]
    public function it_renders_maintenance_menu_serves_unknown_workstation(): void
    {
        // Parité legacy `maintenance.php:15` — un poste inconnu peut consulter
        // le menu maintenance (notamment factory_reset pour un poste neuf).
        $body = $this->renderer->renderMaintenanceMenu(null, '192.168.1.50', 'http://se4fs.lan');

        self::assertStringContainsString('item --key c rescuecd', $body);
        self::assertStringContainsString('item --key f factory_reset', $body);
    }

    #[Test]
    public function it_renders_admin_and_maintenance_templates_have_no_php_tags(): void
    {
        $ws = Workstation::create([
            'name' => 'PC-T',
            'uuid' => '11111111-1111-1111-1111-111111111111',
            'mac' => 'aa:bb:cc:dd:ee:f2',
            'status' => 'active',
        ]);

        $admin = $this->renderer->renderAdminMenu($ws, '192.168.1.10', 'http://se4fs.lan');
        $mnt = $this->renderer->renderMaintenanceMenu($ws, '192.168.1.10', 'http://se4fs.lan');

        foreach ([$admin, $mnt] as $body) {
            self::assertStringNotContainsString('<?php', $body);
            self::assertStringNotContainsString('<?=', $body);
            self::assertStringNotContainsString('?>', $body);
        }
    }

    /* ------------------------------------------------------------------
     * Story 3.2 — Correctif review #1 (params block iso-legacy)
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_renders_admin_menu_with_params_block_for_chain_namespace(): void
    {
        // Fix review #1 / pertinence 3 — sans bloc params en tête, les chain
        // ##params injectent un namespace vide → MachineBootLog audit cassé.
        // Iso-legacy `sambaedu/ipxe/admin.php:69-74`.
        //
        // Le bloc params utilise les variables iPXE SMBIOS (${net0/mac}/${uuid})
        // et NON les valeurs Laravel-rendues : fournies par le firmware à chaque
        // requête, donc robustes même si le poste a un mac/uuid vide/divergent en
        // SQL (sinon un uuid vide ferait basculer /ipxe/admin sur le préambule
        // handshake et perdrait l'auth au retour menu). Cf. known.blade.php.
        $ws = Workstation::create([
            'name' => 'PC-PARAMS-A',
            'uuid' => 'abcdef12-3456-7890-abcd-ef1234567890',
            'mac' => 'aa:bb:cc:dd:ee:ff',
            'status' => 'active',
        ]);

        $body = $this->renderer->renderAdminMenu($ws, '192.168.1.42', 'http://se4fs.lan');

        self::assertStringContainsString('param mac ', $body);
        self::assertStringContainsString('param uuid ', $body);
        // Variables iPXE SMBIOS dans le bloc params (pas les valeurs Laravel).
        self::assertStringContainsString('param mac ${net0/mac}', $body);
        self::assertStringContainsString('param uuid ${uuid}', $body);
    }

    #[Test]
    public function it_renders_maintenance_menu_with_params_block_for_chain_namespace(): void
    {
        // Fix review #1 — iso-legacy `sambaedu/ipxe/maintenance.php:19-22`.
        // Le bloc params utilise les variables iPXE SMBIOS (${net0/mac}/${uuid})
        // et NON les valeurs Laravel : un uuid SQL vide ferait basculer
        // /ipxe/action (sélectionné depuis ce menu) sur le préambule handshake.
        // Cf. known.blade.php / admin.blade.php.
        $ws = Workstation::create([
            'name' => 'PC-PARAMS-M',
            'uuid' => 'bbbbcccc-1111-2222-3333-444455556666',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'status' => 'active',
        ]);

        $body = $this->renderer->renderMaintenanceMenu($ws, '192.168.1.50', 'http://se4fs.lan');

        self::assertStringContainsString('param mac ', $body);
        self::assertStringContainsString('param uuid ', $body);
        // Variables iPXE SMBIOS dans le bloc params (pas les valeurs Laravel).
        self::assertStringContainsString('param mac ${net0/mac}', $body);
        self::assertStringContainsString('param uuid ${uuid}', $body);
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

    /* ------------------------------------------------------------------
     * Story 3.3 — AC5.1 — renderEnrollment*Menu (5 nouveaux templates)
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_renders_enrollment_name_menu_with_read_name_prompt(): void
    {
        $vars = [
            'mac' => 'aa:bb:cc:dd:ee:11',
            'uuid' => '11111111-1111-1111-1111-111111111111',
            'platform' => 'legacy',
            'ip' => '192.168.1.10',
            'currentName' => '',
            'serverBaseUrl' => 'http://se4fs.lan',
            'resolutionX' => 1024,
            'resolutionY' => 768,
            'resolutionPng' => 'png/ipxe-se4.png',
            'menuTimeoutMs' => 10000,
        ];

        $body = $this->renderer->renderEnrollmentNameMenu($vars, null);

        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('read name', $body);
        self::assertStringContainsString('ipxe/enrollment/name##params', $body);
    }

    #[Test]
    public function it_renders_enrollment_name_menu_with_success_message_when_created(): void
    {
        $ws = Workstation::create([
            'name' => 'pc-3-3',
            'uuid' => '22222222-2222-2222-2222-222222222222',
            'mac' => 'aa:bb:cc:dd:ee:22',
            'status' => 'active',
        ]);
        $result = \App\Ipxe\Support\EnrollNameResult::created($ws, 'pc-3-3', true);
        $vars = [
            'mac' => 'aa:bb:cc:dd:ee:22',
            'uuid' => '22222222-2222-2222-2222-222222222222',
            'platform' => 'legacy',
            'ip' => '192.168.1.11',
            'currentName' => 'pc-3-3',
            'serverBaseUrl' => 'http://se4fs.lan',
            'resolutionX' => 1024,
            'resolutionY' => 768,
            'resolutionPng' => 'png/ipxe-se4.png',
            'menuTimeoutMs' => 10000,
        ];

        $body = $this->renderer->renderEnrollmentNameMenu($vars, $result);

        self::assertStringContainsString('OK ! nom pc-3-3 reserve', $body);
        self::assertStringContainsString('/ipxe/admin##params', $body);
    }

    #[Test]
    public function it_renders_enrollment_name_menu_with_same_name_message(): void
    {
        $ws = Workstation::create([
            'name' => 'pc-idem',
            'uuid' => '33333333-3333-3333-3333-333333333333',
            'mac' => 'aa:bb:cc:dd:ee:33',
            'status' => 'active',
        ]);
        $result = \App\Ipxe\Support\EnrollNameResult::sameName($ws, 'pc-idem');
        $vars = [
            'mac' => 'aa:bb:cc:dd:ee:33',
            'uuid' => '33333333-3333-3333-3333-333333333333',
            'platform' => 'legacy',
            'ip' => '192.168.1.12',
            'currentName' => 'pc-idem',
            'serverBaseUrl' => 'http://se4fs.lan',
            'resolutionX' => 1024,
            'resolutionY' => 768,
            'resolutionPng' => 'png/ipxe-se4.png',
            'menuTimeoutMs' => 10000,
        ];

        $body = $this->renderer->renderEnrollmentNameMenu($vars, $result);

        self::assertStringContainsString('deja enregistree', $body);
    }

    #[Test]
    public function it_renders_enrollment_room_menu_with_available_rooms(): void
    {
        $vars = [
            'mac' => 'aa:bb:cc:dd:ee:44',
            'uuid' => '44444444-4444-4444-4444-444444444444',
            'workstationName' => 'pc-room',
            'availableRooms' => [
                ['id' => 1, 'name' => 'salle-A', 'display_name' => 'Salle A', 'is_current' => false],
                ['id' => 2, 'name' => 'salle-B', 'display_name' => 'Salle B', 'is_current' => true],
            ],
            'currentRoom' => ['id' => 2, 'name' => 'salle-B'],
            'serverBaseUrl' => 'http://se4fs.lan',
            'resolutionX' => 1024,
            'resolutionY' => 768,
            'resolutionPng' => 'png/ipxe-se4.png',
            'menuTimeoutMs' => 10000,
            'truncated' => false,
        ];

        $body = $this->renderer->renderEnrollmentRoomMenu($vars, null, false);

        self::assertStringContainsString('Enregistrement de la salle pour pc-room', $body);
        self::assertStringContainsString('item r-1', $body);
        self::assertStringContainsString('Salle A', $body);
        // salle-B est current → marquée `** deja dans **`.
        self::assertStringContainsString('** deja dans salle-B **', $body);
        self::assertStringContainsString('ipxe/enrollment/room##params', $body);
    }

    #[Test]
    public function it_renders_enrollment_parc_add_menu_with_available_parcs(): void
    {
        $vars = [
            'mac' => 'aa:bb:cc:dd:ee:55',
            'uuid' => '55555555-5555-5555-5555-555555555555',
            'workstationName' => 'pc-parc',
            'availableParcs' => [
                ['id' => 10, 'name' => 'parc-x', 'display_name' => 'Parc X', 'is_current' => false],
            ],
            'serverBaseUrl' => 'http://se4fs.lan',
            'resolutionX' => 1024,
            'resolutionY' => 768,
            'resolutionPng' => 'png/ipxe-se4.png',
            'menuTimeoutMs' => 10000,
            'truncated' => false,
        ];

        $body = $this->renderer->renderEnrollmentParcAddMenu($vars, null, false);

        self::assertStringContainsString("Ajout d'un parc pour pc-parc", $body);
        self::assertStringContainsString('item p-10', $body);
        self::assertStringContainsString('Parc X', $body);
        self::assertStringContainsString('ipxe/enrollment/parc-add##params', $body);
    }

    #[Test]
    public function it_renders_enrollment_parc_remove_menu_with_current_parcs_only(): void
    {
        $vars = [
            'mac' => 'aa:bb:cc:dd:ee:66',
            'uuid' => '66666666-6666-6666-6666-666666666666',
            'workstationName' => 'pc-rm-parc',
            'currentParcs' => [
                ['id' => 20, 'name' => 'parc-y', 'display_name' => 'Parc Y', 'is_current' => false],
            ],
            'serverBaseUrl' => 'http://se4fs.lan',
            'resolutionX' => 1024,
            'resolutionY' => 768,
            'resolutionPng' => 'png/ipxe-se4.png',
            'menuTimeoutMs' => 10000,
            'truncated' => false,
        ];

        $body = $this->renderer->renderEnrollmentParcRemoveMenu($vars, null, false);

        self::assertStringContainsString("Retrait d'un parc pour pc-rm-parc", $body);
        self::assertStringContainsString('item p-20', $body);
        self::assertStringContainsString('ipxe/enrollment/parc-remove##params', $body);
    }

    #[Test]
    public function it_renders_enrollment_unknown_workstation_error_chain_admin(): void
    {
        $body = $this->renderer->renderEnrollmentUnknownWorkstation('http://se4fs.lan');

        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('poste non encore enregistre', $body);
        self::assertStringContainsString('http://se4fs.lan/ipxe/admin##params', $body);
    }

    #[Test]
    public function it_renders_enrollment_byod_menu_with_success_when_logged(): void
    {
        $vars = [
            'mac' => 'aa:bb:cc:dd:ee:77',
            'uuid' => '77777777-7777-7777-7777-777777777777',
            'platform' => 'legacy',
            'ip' => '192.168.1.20',
            'currentName' => '',
            'serverBaseUrl' => 'http://se4fs.lan',
            'resolutionX' => 1024,
            'resolutionY' => 768,
            'resolutionPng' => 'png/ipxe-se4.png',
            'menuTimeoutMs' => 10000,
        ];

        $body = $this->renderer->renderEnrollmentByodMenu($vars, true, 'student-pc');

        self::assertStringContainsString('BYOD enregistre pour student-pc', $body);
        self::assertStringContainsString('/ipxe/admin##params', $body);
    }

    /* ------------------------------------------------------------------
     * Story 3.5 — AC6.1 — renderInstallationWindowsMenu().
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_renders_installation_windows_menu_for_known_workstation(): void
    {
        $ws = Workstation::create([
            'name' => 'PC-WIN-RENDER',
            'uuid' => '12345678-1234-1234-1234-aaaaaaaaaaaa',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'status' => 'active',
        ]);

        $body = $this->renderer->renderInstallationWindowsMenu($ws, '192.168.1.42', 'http://se4fs.lan');

        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('install_win10', $body);
        self::assertStringContainsString('install_win11', $body);
        self::assertStringContainsString('install_win11_perso', $body);
        // 7 sections de chain.
        self::assertStringContainsString(':install_win11', $body);
        self::assertStringContainsString('/ipxe/action/install_win11##params', $body);
        // Default = install_win11.
        self::assertStringContainsString('set menu-default install_win11', $body);
        // Exit + fallback boot disk.
        self::assertStringContainsString(':exit', $body);
        self::assertStringContainsString('sanboot', $body);
    }

    #[Test]
    public function it_renders_installation_windows_error_menu_for_unknown_workstation(): void
    {
        $body = $this->renderer->renderInstallationWindowsMenu(null, '192.168.1.42', 'http://se4fs.lan');

        self::assertStringStartsWith('#!ipxe', $body);
        self::assertStringContainsString('Erreur - poste non encore enregistre', $body);
        self::assertStringContainsString('chain --replace --autofree http://se4fs.lan/ipxe/admin##params', $body);
        // Pas d'items install_win* en mode erreur.
        self::assertStringNotContainsString(':install_win11', $body);
        self::assertStringNotContainsString(':menu', $body);
    }

    #[Test]
    public function it_renders_installation_windows_menu_ascii_strict(): void
    {
        $ws = Workstation::create([
            'name' => 'PC-WIN-ASCII',
            'uuid' => '12345678-1234-1234-1234-bbbbbbbbbbbb',
            'mac' => 'aa:bb:cc:dd:ee:02',
            'status' => 'active',
        ]);

        $body = $this->renderer->renderInstallationWindowsMenu($ws, '192.168.1.42', 'http://se4fs.lan');

        // ASCII strict (sauf TAB/newlines).
        self::assertSame(
            0,
            preg_match('/[^\x09\x0A\x0D\x20-\x7E]/', $body),
            'Le menu installation-windows ne doit contenir que de l\'ASCII printable.',
        );
        // Pas de balise PHP.
        self::assertStringNotContainsString('<?php', $body);
    }

    #[Test]
    public function it_renders_installation_windows_menu_with_7_items(): void
    {
        $ws = Workstation::create([
            'name' => 'PC-WIN-7ITEMS',
            'uuid' => '12345678-1234-1234-1234-cccccccccccc',
            'mac' => 'aa:bb:cc:dd:ee:03',
            'status' => 'active',
        ]);

        $body = $this->renderer->renderInstallationWindowsMenu($ws, '192.168.1.42', 'http://se4fs.lan');

        // 7 sections `:install_win*`.
        $matches = [];
        preg_match_all('/^:install_win\w+$/m', $body, $matches);
        self::assertCount(7, $matches[0], '7 sections install_win* attendues');
    }

    #[Test]
    public function it_renders_admin_menu_with_install_windows_url(): void
    {
        // Story 3.5 — non-régression menu admin : nouvelle var
        // `$installWindowsBaseUrl` exposée.
        $ws = Workstation::create([
            'name' => 'PC-ADMIN-WIN',
            'uuid' => '12345678-1234-1234-1234-dddddddddddd',
            'mac' => 'aa:bb:cc:dd:ee:04',
            'status' => 'active',
        ]);

        config(['ipxe.windows.enabled' => true]);
        $body = $this->renderer->renderAdminMenu($ws, '192.168.1.42', 'http://se4fs.lan');

        self::assertStringContainsString('item --key w install-windows', $body);
        self::assertStringContainsString(
            'chain --replace --autofree http://se4fs.lan/ipxe/installation-windows##params',
            $body,
        );
    }

    #[Test]
    public function it_renders_admin_menu_hides_install_windows_when_disabled(): void
    {
        $ws = Workstation::create([
            'name' => 'PC-ADMIN-NOWIN',
            'uuid' => '12345678-1234-1234-1234-eeeeeeeeeeee',
            'mac' => 'aa:bb:cc:dd:ee:05',
            'status' => 'active',
        ]);

        config(['ipxe.windows.enabled' => false]);
        $body = $this->renderer->renderAdminMenu($ws, '192.168.1.42', 'http://se4fs.lan');

        self::assertStringNotContainsString('item --key w install-windows', $body);
    }
}
