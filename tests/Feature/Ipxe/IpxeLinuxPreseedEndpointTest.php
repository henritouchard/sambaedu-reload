<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Models\MachineBootLog;
use App\Models\Workstation;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.4 — AC5.2 / T6.3.
 *
 * Tests feature de la route native `GET|POST /ipxe/linux/preseed`.
 */
class IpxeLinuxPreseedEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();
        config([
            'auth_v1.bootstrap.allowed_subnets' => '127.0.0.0/8,192.168.0.0/16,10.0.0.0/8',
            'ipxe.linux.preseed_fragments_path' => resource_path('ipxe/linux'),
            'sambaedu.linux.locale' => 'fr_FR',
            'sambaedu.linux.keyboard' => 'fr(latin9)',
            'sambaedu.linux.version_debian' => 'trixie',
            'sambaedu.domain' => 'example.org',
            'sambaedu.admin_passwd' => 'admin-secret-test',
        ]);
    }

    private function seedWorkstation(): Workstation
    {
        return Workstation::create([
            'name' => 'PC-PRESEED',
            'uuid' => '12345678-1234-1234-1234-dddddddddddd',
            'mac' => 'aa:bb:cc:dd:ee:d1',
            'status' => 'active',
        ]);
    }

    #[Test]
    public function it_generates_preseed_for_debian_gnome(): void
    {
        $this->seedWorkstation();

        $response = $this->get('/ipxe/linux/preseed?mac=aa:bb:cc:dd:ee:d1&uuid=12345678-1234-1234-1234-dddddddddddd&os=trixie&type=gnome');

        $response->assertStatus(200);
        self::assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
        $body = (string) $response->getContent();
        self::assertStringContainsString('### Fichier de réponses préconfigurées', $body);
        self::assertStringContainsString('gnome-desktop', $body);
        self::assertStringContainsString('hostname string pc-preseed', $body);
    }

    #[Test]
    public function it_returns_404_for_unknown_workstation(): void
    {
        $response = $this->get('/ipxe/linux/preseed?mac=aa:bb:cc:dd:ee:99&uuid=99999999-9999-9999-9999-999999999999&os=trixie&type=gnome');

        $response->assertStatus(404);
    }

    #[Test]
    public function it_returns_422_for_invalid_os(): void
    {
        $this->seedWorkstation();

        $response = $this->getJson('/ipxe/linux/preseed?mac=aa:bb:cc:dd:ee:d1&uuid=12345678-1234-1234-1234-dddddddddddd&os=macos&type=gnome');

        $response->assertStatus(422);
    }

    #[Test]
    public function it_returns_422_for_invalid_type(): void
    {
        $this->seedWorkstation();

        $response = $this->getJson('/ipxe/linux/preseed?mac=aa:bb:cc:dd:ee:d1&uuid=12345678-1234-1234-1234-dddddddddddd&os=trixie&type=unity');

        $response->assertStatus(422);
    }

    #[Test]
    public function it_persists_machine_boot_log_with_action_ipxe_linux_preseed(): void
    {
        $ws = $this->seedWorkstation();

        $this->get('/ipxe/linux/preseed?mac=aa:bb:cc:dd:ee:d1&uuid=12345678-1234-1234-1234-dddddddddddd&os=trixie&type=gnome');

        $count = MachineBootLog::query()
            ->where('action', 'ipxe_linux_preseed')
            ->where('workstation_id', $ws->id)
            ->count();

        self::assertSame(1, $count);
    }

    #[Test]
    public function it_returns_no_store_headers(): void
    {
        $this->seedWorkstation();

        $response = $this->get('/ipxe/linux/preseed?mac=aa:bb:cc:dd:ee:d1&uuid=12345678-1234-1234-1234-dddddddddddd&os=trixie&type=gnome');

        $response->assertStatus(200);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }

    #[Test]
    public function it_generates_preseed_for_ubuntu(): void
    {
        Workstation::create([
            'name' => 'PC-UB',
            'uuid' => '12345678-1234-1234-1234-eeeeeeeeeeee',
            'mac' => 'aa:bb:cc:dd:ee:e1',
            'status' => 'active',
        ]);

        $response = $this->get('/ipxe/linux/preseed?mac=aa:bb:cc:dd:ee:e1&uuid=12345678-1234-1234-1234-eeeeeeeeeeee&os=ubuntu&type=base');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('fr.archive.ubuntu.com', $body);
    }

    #[Test]
    public function it_rejects_path_traversal_in_os(): void
    {
        $this->seedWorkstation();

        $response = $this->getJson('/ipxe/linux/preseed?mac=aa:bb:cc:dd:ee:d1&uuid=12345678-1234-1234-1234-dddddddddddd&os=../../etc/passwd&type=gnome');

        $response->assertStatus(422);
    }

    /* ------------------------------------------------------------------
     * Post-review #1 — `late_command` doit pointer sur la route native SE5
     * `/ipxe/linux/action` (sans `.php`) et JAMAIS sur `action.php` legacy.
     * Si le bug repasse, `LinuxPostInstallTracker::record()` n'est plus
     * appelé en prod (le callback tombe sur le catchall legacy).
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_renders_debian_preseed_with_native_action_url(): void
    {
        $this->seedWorkstation();

        $response = $this->get('/ipxe/linux/preseed?mac=aa:bb:cc:dd:ee:d1&uuid=12345678-1234-1234-1234-dddddddddddd&os=trixie&type=gnome');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        // Le late_command doit pointer sur la route native SE5 (sans `.php`).
        self::assertStringContainsString('/ipxe/linux/action ', $body);
        // Aucun reliquat de l'URL legacy `.php` ne doit subsister.
        self::assertStringNotContainsString('action.php', $body);
    }

    #[Test]
    public function it_renders_ubuntu_preseed_with_native_action_url(): void
    {
        Workstation::create([
            'name' => 'PC-UB-LATE',
            'uuid' => '12345678-1234-1234-1234-eeeeeeeeee01',
            'mac' => 'aa:bb:cc:dd:ee:e2',
            'status' => 'active',
        ]);

        $response = $this->get('/ipxe/linux/preseed?mac=aa:bb:cc:dd:ee:e2&uuid=12345678-1234-1234-1234-eeeeeeeeee01&os=ubuntu&type=base');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        self::assertStringContainsString('/ipxe/linux/action ', $body);
        self::assertStringNotContainsString('action.php', $body);
    }

    #[Test]
    public function it_renders_nird_preseed_with_native_action_url(): void
    {
        Workstation::create([
            'name' => 'PC-NIRD-LATE',
            'uuid' => '12345678-1234-1234-1234-aaaaaaaaaa02',
            'mac' => 'aa:bb:cc:dd:ee:f0',
            'status' => 'active',
        ]);

        $response = $this->get('/ipxe/linux/preseed?mac=aa:bb:cc:dd:ee:f0&uuid=12345678-1234-1234-1234-aaaaaaaaaa02&os=nird&type=base');

        $response->assertStatus(200);
        $body = (string) $response->getContent();
        // Nird utilise `debian_perso.cfg` qui doit aussi avoir le callback natif.
        self::assertStringContainsString('/ipxe/linux/action ', $body);
        self::assertStringNotContainsString('action.php', $body);
    }
}
