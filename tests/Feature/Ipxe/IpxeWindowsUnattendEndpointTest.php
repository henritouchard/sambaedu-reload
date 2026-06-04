<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Models\MachineBootLog;
use App\Models\Workstation;
use DOMDocument;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.5 — AC5.2 / T6.3.
 *
 * Tests feature de la route native `GET|POST /ipxe/windows/unattend.xml`.
 */
class IpxeWindowsUnattendEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();
        config([
            'auth_v1.bootstrap.allowed_subnets' => '127.0.0.0/8,192.168.0.0/16,10.0.0.0/8',
            'ipxe.windows.unattend_template_path' => resource_path('ipxe/windows/unattend.xml'),
            'sambaedu.domain' => 'example.org',
            'sambaedu.se4fs_name' => 'se4fs.lan',
            'sambaedu.se4install_name' => 'se4install',
            'sambaedu.se4install_passwd' => 'install-secret',
            'sambaedu.windows.adminse_name' => 'adminse',
            'sambaedu.windows.adminse_passwd' => 'admin-secret',
            'sambaedu.windows.win_key' => 'VK7JG-NPHTM-C97JM-9MPGT-3V66T',
            'sambaedu.computers_rdn' => 'CN=Computers,DC=example,DC=org',
        ]);
    }

    private function seedWorkstation(string $mac, string $uuid, string $name): void
    {
        Workstation::create([
            'name' => $name,
            'uuid' => $uuid,
            'mac' => $mac,
            'status' => 'active',
        ]);
    }

    #[Test]
    public function it_serves_well_formed_xml_for_win11_uefi(): void
    {
        $this->seedWorkstation('aa:bb:cc:dd:ee:01', '12345678-1234-1234-1234-aaaaaaaaaaaa', 'pc-w11');

        $response = $this->get('/ipxe/windows/unattend.xml?mac=aa:bb:cc:dd:ee:01&uuid=12345678-1234-1234-1234-aaaaaaaaaaaa&version=Win11&bios=uefi&disk=0&perso=0');

        $response->assertStatus(200);
        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));

        $body = (string) $response->getContent();
        self::assertStringStartsWith('<?xml version="1.0"', $body);
        // Win11 → bypass TPM.
        self::assertStringContainsString('BypassTPMCheck', $body);
        // UEFI partition layout.
        self::assertStringContainsString('<Type>EFI</Type>', $body);
        // ComputerName interpolé.
        self::assertStringContainsString('pc-w11', $body);
        // Domain join.
        self::assertStringContainsString('Microsoft-Windows-UnattendedJoin', $body);
        // DOMDocument parsable.
        $dom = new DOMDocument();
        self::assertTrue(@$dom->loadXML($body));
    }

    #[Test]
    public function it_serves_xml_without_disk_config_when_disk_flag_set(): void
    {
        $this->seedWorkstation('aa:bb:cc:dd:ee:02', '12345678-1234-1234-1234-bbbbbbbbbbbb', 'pc-disk');

        $response = $this->get('/ipxe/windows/unattend.xml?mac=aa:bb:cc:dd:ee:02&uuid=12345678-1234-1234-1234-bbbbbbbbbbbb&version=Win11&bios=uefi&disk=1&perso=0');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringNotContainsString('<DiskConfiguration>', $body);
    }

    #[Test]
    public function it_serves_xml_with_local_account_when_perso_flag_set(): void
    {
        config([
            'sambaedu.windows.win_user' => 'perso-user',
            'sambaedu.windows.win_user_passwd' => 'perso-secret',
        ]);
        $this->seedWorkstation('aa:bb:cc:dd:ee:03', '12345678-1234-1234-1234-cccccccccccc', 'pc-perso');

        $response = $this->get('/ipxe/windows/unattend.xml?mac=aa:bb:cc:dd:ee:03&uuid=12345678-1234-1234-1234-cccccccccccc&version=Win11&bios=uefi&disk=0&perso=1');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        // PAS de UnattendedJoin.
        self::assertStringNotContainsString('Microsoft-Windows-UnattendedJoin', $body);
        // Username perso.
        self::assertMatchesRegularExpression('@<Username>perso-user</Username>@', $body);
    }

    #[Test]
    public function it_returns_404_for_unknown_workstation(): void
    {
        $response = $this->get('/ipxe/windows/unattend.xml?mac=aa:bb:cc:dd:ee:99&uuid=99999999-9999-9999-9999-999999999999&version=Win11&bios=uefi');

        $response->assertStatus(404);
    }

    #[Test]
    public function it_rejects_invalid_version_with_422(): void
    {
        $this->seedWorkstation('aa:bb:cc:dd:ee:04', '12345678-1234-1234-1234-dddddddddddd', 'pc-bad-ver');

        $response = $this->get('/ipxe/windows/unattend.xml?mac=aa:bb:cc:dd:ee:04&uuid=12345678-1234-1234-1234-dddddddddddd&version=Win99&bios=uefi');

        $response->assertStatus(422);
    }

    #[Test]
    public function it_persists_machine_boot_log_with_action_ipxe_win_unattend(): void
    {
        $this->seedWorkstation('aa:bb:cc:dd:ee:05', '12345678-1234-1234-1234-eeeeeeeeeeee', 'pc-mbl-unattend');

        $this->get('/ipxe/windows/unattend.xml?mac=aa:bb:cc:dd:ee:05&uuid=12345678-1234-1234-1234-eeeeeeeeeeee&version=Win11&bios=uefi');

        $log = MachineBootLog::where('action', 'ipxe_win_unattend')->first();
        self::assertNotNull($log);
        self::assertSame('ipxe', $log->initiated_by);
    }

    #[Test]
    public function it_serves_well_formed_xml_for_win10_legacy(): void
    {
        $this->seedWorkstation('aa:bb:cc:dd:ee:06', '12345678-1234-1234-1234-ffffffffffff', 'pc-w10-leg');

        $response = $this->get('/ipxe/windows/unattend.xml?mac=aa:bb:cc:dd:ee:06&uuid=12345678-1234-1234-1234-ffffffffffff&version=Win10&bios=legacy');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        // Win10 → pas de TPM bypass.
        self::assertStringNotContainsString('BypassTPMCheck', $body);
        // Legacy partition.
        self::assertStringContainsString('<Label>OS</Label>', $body);
        self::assertStringNotContainsString('<Type>EFI</Type>', $body);
    }

    #[Test]
    public function it_serves_text_plain_with_secure_headers(): void
    {
        $this->seedWorkstation('aa:bb:cc:dd:ee:07', '12345678-1234-1234-1234-9999fffffeee', 'pc-head');

        $response = $this->get('/ipxe/windows/unattend.xml?mac=aa:bb:cc:dd:ee:07&uuid=12345678-1234-1234-1234-9999fffffeee&version=Win11&bios=uefi');

        self::assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    /**
     * Post-review code-review #1 (Critique) — l'URL OOBE post-installation
     * dans le template unattend.xml doit pointer vers le endpoint natif
     * `/ipxe/windows/action` (SE5), PAS vers `/ipxe/Win10/action.php` (legacy
     * PHP). En SE5-only, l'URL legacy tombe en 404 → tracker OOBE jamais
     * appelé → `workstation.os` reste NULL indéfiniment.
     */
    #[Test]
    public function it_renders_native_action_url_not_legacy_php(): void
    {
        $this->seedWorkstation('aa:bb:cc:dd:ee:08', '12345678-1234-1234-1234-aaaabbbbcccc', 'pc-w11-url');

        $response = $this->get('/ipxe/windows/unattend.xml?mac=aa:bb:cc:dd:ee:08&uuid=12345678-1234-1234-1234-aaaabbbbcccc&version=Win11&bios=uefi');

        $response->assertStatus(200);
        $body = (string) $response->getContent();

        // L'URL OOBE callback doit pointer vers /ipxe/windows/action (SE5).
        self::assertStringContainsString('/ipxe/windows/action', $body);
        // Pas de référence au legacy PHP.
        self::assertStringNotContainsString('Win10/action.php', $body);
        self::assertStringNotContainsString('action.php', $body);
    }

    /**
     * Post-review code-review #N1 (Important) — l'OU AD du poste pour le join
     * domain est lue depuis `Workstation::physicalRoom()->ad_dn` (alimenté par
     * l'enrollment story 3-3) et NON parsée depuis `Workstation::ad_dn`.
     *
     * Si le poste est rattaché à une salle physique qui a son `ad_dn` rempli,
     * le `<MachineObjectOU>` du unattend doit refléter exactement cette OU
     * (pas le fallback `CN=Computers`).
     */
    #[Test]
    public function it_renders_machine_object_ou_from_physical_room_ad_dn(): void
    {
        $room = \App\Models\WorkstationGroup::create([
            'name' => 'salle-a',
            'is_physical' => true,
            'ad_dn' => 'OU=Salle-A,OU=Etab-XYZ,DC=example,DC=org',
            'is_active' => true,
        ]);

        $ws = Workstation::create([
            'name' => 'pc-w11-ou',
            'uuid' => '12345678-1234-1234-1234-aaaabbbb0001',
            'mac' => 'aa:bb:cc:dd:ee:09',
            'status' => 'active',
        ]);
        // Story 4.11 — la salle (source de l'OU) vit dans le pivot global.
        $ws->groups()->attach($room->id);

        $response = $this->get('/ipxe/windows/unattend.xml?mac=aa:bb:cc:dd:ee:09&uuid=12345678-1234-1234-1234-aaaabbbb0001&version=Win11&bios=uefi&perso=0');

        $response->assertStatus(200);
        $body = (string) $response->getContent();

        // L'OU du physicalRoom doit être présente.
        self::assertStringContainsString(
            '<MachineObjectOU>OU=Salle-A,OU=Etab-XYZ,DC=example,DC=org</MachineObjectOU>',
            $body,
        );
        // Pas de fallback CN=Computers.
        self::assertStringNotContainsString(
            '<MachineObjectOU>CN=Computers,DC=example,DC=org</MachineObjectOU>',
            $body,
        );
    }

    /**
     * Post-review code-review #N1 — quand le poste n'a pas de `physical_room_id`
     * (jamais enrôlé) OU que la salle n'a pas encore son `ad_dn` synchronisé,
     * le fallback `config('sambaedu.computers_rdn')` est utilisé.
     */
    #[Test]
    public function it_falls_back_to_computers_rdn_when_no_physical_room(): void
    {
        $this->seedWorkstation('aa:bb:cc:dd:ee:0a', '12345678-1234-1234-1234-aaaabbbb0002', 'pc-no-room');

        $response = $this->get('/ipxe/windows/unattend.xml?mac=aa:bb:cc:dd:ee:0a&uuid=12345678-1234-1234-1234-aaaabbbb0002&version=Win11&bios=uefi&perso=0');

        $response->assertStatus(200);
        $body = (string) $response->getContent();

        self::assertStringContainsString(
            '<MachineObjectOU>CN=Computers,DC=example,DC=org</MachineObjectOU>',
            $body,
        );
    }
}
