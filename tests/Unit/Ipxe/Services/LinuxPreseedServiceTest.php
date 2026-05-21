<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Services;

use App\Ipxe\Enums\LinuxDesktopVariant;
use App\Ipxe\Enums\LinuxDistribution;
use App\Ipxe\Exceptions\PreseedGenerationException;
use App\Ipxe\Services\LinuxPreseedService;
use App\Models\Workstation;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\IpxeSchemaBootstrapper;
use Tests\TestCase;

/**
 * Story 3.4 — AC2.1 / AC2.2 / AC2.3.
 *
 * Tests unitaires de {@see LinuxPreseedService} — assemblage des fragments
 * + interpolation des placeholders + sécurité anti-injection + log audit.
 */
class LinuxPreseedServiceTest extends TestCase
{
    private LinuxPreseedService $service;

    protected function setUp(): void
    {
        parent::setUp();
        IpxeSchemaBootstrapper::bootstrap();

        // Force le chemin des fragments vers le repo (pas la VM).
        config([
            'ipxe.linux.preseed_fragments_path' => resource_path('ipxe/linux'),
            'sambaedu.linux.locale' => 'fr_FR',
            'sambaedu.linux.keyboard' => 'fr(latin9)',
            'sambaedu.linux.version_debian' => 'trixie',
            'sambaedu.linux.apt_proxy' => '',
            'sambaedu.linux.server_proxy' => '',
            'sambaedu.linux.commande_fin_preseed' => '',
            'sambaedu.domain' => 'example.org',
            'sambaedu.admin_passwd' => 'secret-admin-pwd',
            'sambaedu.ldap_admin_passwd' => 'secret-ldap-pwd',
            'sambaedu.linux.token' => 'secret-token',
        ]);

        $this->service = new LinuxPreseedService();
    }

    private function makeWorkstation(string $name = 'PC-101', string $uuid = '12345678-1234-1234-1234-aaaaaaaaaaaa'): Workstation
    {
        return Workstation::create([
            'name' => $name,
            'uuid' => $uuid,
            'mac' => 'aa:bb:cc:dd:ee:01',
            'status' => 'active',
        ]);
    }

    #[Test]
    public function it_assembles_debian_gnome_preseed_with_all_fragments(): void
    {
        $ws = $this->makeWorkstation();
        $preseed = $this->service->generate(
            $ws,
            LinuxDistribution::Debian,
            LinuxDesktopVariant::Gnome,
        );

        // debian.cfg ouvre le preseed.
        self::assertStringContainsString('### Fichier de réponses préconfigurées', $preseed);
        // debian_gnome.cfg apporte tasksel gnome.
        self::assertStringContainsString('gnome-desktop', $preseed);
        // sambaedu.cfg apporte sambaedu-ad-dc.
        self::assertStringContainsString('sambaedu-ad-dc', $preseed);
        // simple_boot.cfg apporte le partitionnement.
        self::assertStringContainsString('partman-auto/method string regular', $preseed);
    }

    #[Test]
    public function it_assembles_debian_lxde_preseed_swapping_variant_fragment(): void
    {
        $ws = $this->makeWorkstation();
        $preseed = $this->service->generate(
            $ws,
            LinuxDistribution::Debian,
            LinuxDesktopVariant::Lxde,
        );

        self::assertStringContainsString('lxde-desktop', $preseed);
        self::assertStringNotContainsString('gnome-desktop', $preseed);
    }

    #[Test]
    public function it_assembles_debian_base_preseed_without_desktop_fragment(): void
    {
        $ws = $this->makeWorkstation();
        $preseed = $this->service->generate(
            $ws,
            LinuxDistribution::Debian,
            LinuxDesktopVariant::Base,
        );

        // Base : tasksel sans desktop.
        self::assertStringContainsString('tasksel/first multiselect standard, ssh-server', $preseed);
        self::assertStringNotContainsString('gnome-desktop', $preseed);
    }

    #[Test]
    public function it_assembles_ubuntu_preseed_with_ubuntu_cfg(): void
    {
        $ws = $this->makeWorkstation();
        $preseed = $this->service->generate(
            $ws,
            LinuxDistribution::Ubuntu,
            LinuxDesktopVariant::Base,
        );

        self::assertStringContainsString('ubuntu-desktop', $preseed);
        self::assertStringContainsString('fr.archive.ubuntu.com', $preseed);
    }

    #[Test]
    public function it_assembles_nird_preseed_with_debian_perso_cfg(): void
    {
        $ws = $this->makeWorkstation();
        $preseed = $this->service->generate(
            $ws,
            LinuxDistribution::Nird,
            LinuxDesktopVariant::Base,
        );

        // debian_perso.cfg apporte allow-password-weak.
        self::assertStringContainsString('allow-password-weak', $preseed);
    }

    #[Test]
    public function it_interpolates_hostname_from_workstation_name(): void
    {
        $ws = $this->makeWorkstation('PC-101');
        $preseed = $this->service->generate(
            $ws,
            LinuxDistribution::Debian,
            LinuxDesktopVariant::Gnome,
        );

        self::assertStringContainsString('hostname string pc-101', $preseed);
        // No leftover ###_HOSTNAME_### placeholder.
        self::assertStringNotContainsString('###_HOSTNAME_###', $preseed);
    }

    #[Test]
    public function it_interpolates_uuid_from_workstation(): void
    {
        $ws = $this->makeWorkstation('PC-101', '11111111-2222-3333-4444-555555555555');
        $preseed = $this->service->generate(
            $ws,
            LinuxDistribution::Debian,
            LinuxDesktopVariant::Gnome,
        );

        self::assertStringContainsString('11111111-2222-3333-4444-555555555555', $preseed);
        self::assertStringNotContainsString('###_UUID_###', $preseed);
    }

    #[Test]
    public function it_interpolates_admin_passwd_from_config(): void
    {
        $ws = $this->makeWorkstation();
        $preseed = $this->service->generate(
            $ws,
            LinuxDistribution::Debian,
            LinuxDesktopVariant::Gnome,
        );

        self::assertStringContainsString('secret-admin-pwd', $preseed);
        self::assertStringNotContainsString('###_ADMINSE_PASSWD_###', $preseed);
        self::assertStringNotContainsString('###_ADMIN_PASSWD_###', $preseed);
    }

    #[Test]
    public function it_includes_aptcache_when_apt_proxy_configured(): void
    {
        config(['sambaedu.linux.apt_proxy' => 'http://proxy.example.org:3142']);
        $ws = $this->makeWorkstation();
        $preseed = $this->service->generate(
            $ws,
            LinuxDistribution::Debian,
            LinuxDesktopVariant::Gnome,
        );

        // aptcache.cfg contains `d-i mirror/http/proxy string ###_APT_PROXY_###`.
        self::assertStringContainsString('proxy.example.org:3142', $preseed);
    }

    #[Test]
    public function it_includes_nocache_when_apt_proxy_empty(): void
    {
        config(['sambaedu.linux.apt_proxy' => '', 'sambaedu.linux.server_proxy' => '']);
        $ws = $this->makeWorkstation();
        $preseed = $this->service->generate(
            $ws,
            LinuxDistribution::Debian,
            LinuxDesktopVariant::Gnome,
        );

        // nocache.cfg ne contient que des commentaires — pas de proxy ligne.
        self::assertStringNotContainsString('mirror/http/proxy', $preseed);
    }

    #[Test]
    public function it_throws_preseed_generation_exception_when_fragment_missing(): void
    {
        // Force fragment path vers un dossier inexistant.
        config(['ipxe.linux.preseed_fragments_path' => '/tmp/nonexistent-' . uniqid()]);
        $ws = $this->makeWorkstation();

        $this->expectException(PreseedGenerationException::class);
        $this->service->generate(
            $ws,
            LinuxDistribution::Debian,
            LinuxDesktopVariant::Gnome,
        );
    }

    #[Test]
    public function it_preserves_fragment_order_for_debian_gnome(): void
    {
        // Post-review #M1 — Gel de l'ordre des fragments contre régression
        // silencieuse. Ordre attendu pour Debian/Gnome (parité legacy
        // `preseed.php:86-159`) :
        //   nocache.cfg → debian_gnome.cfg → debian.cfg → sambaedu.cfg
        //   → simple_boot.cfg.
        //
        // On utilise des marqueurs DISTINCTIFS choisis pour leur présence
        // dans un seul fragment.
        config(['sambaedu.linux.apt_proxy' => '', 'sambaedu.linux.server_proxy' => '']);

        $ws = $this->makeWorkstation();
        $preseed = $this->service->generate(
            $ws,
            LinuxDistribution::Debian,
            LinuxDesktopVariant::Gnome,
        );

        // Marqueurs uniques par fragment (vérifiés par grep — un seul fragment
        // les contient).
        $posNocache = strpos($preseed, '# sans cache apt');
        $posGnome = strpos($preseed, 'gnome-desktop, print-server, ssh-server');
        // debian.cfg ouvre par "### Fichier de réponses préconfigurées" — mais
        // ce header existe aussi dans debian_gnome.cfg ? Non, seul debian.cfg
        // a le header. On utilise plutôt la section 6 root-login string.
        $posDebian = strpos($preseed, 'passwd/root-login boolean true');
        $posSambaedu = strpos($preseed, 'sambaedu-ad-dc sambaedu/ldap_port');
        $posSimpleBoot = strpos($preseed, 'partman-auto/method string regular');

        self::assertNotFalse($posNocache, 'Marqueur nocache.cfg absent');
        self::assertNotFalse($posGnome, 'Marqueur debian_gnome.cfg absent');
        self::assertNotFalse($posDebian, 'Marqueur debian.cfg absent');
        self::assertNotFalse($posSambaedu, 'Marqueur sambaedu.cfg absent');
        self::assertNotFalse($posSimpleBoot, 'Marqueur simple_boot.cfg absent');

        // Ordre relatif strict iso-legacy.
        self::assertLessThan($posGnome, $posNocache, 'nocache.cfg doit precede debian_gnome.cfg');
        self::assertLessThan($posDebian, $posGnome, 'debian_gnome.cfg doit precede debian.cfg');
        self::assertLessThan($posSambaedu, $posDebian, 'debian.cfg doit precede sambaedu.cfg');
        self::assertLessThan($posSimpleBoot, $posSambaedu, 'sambaedu.cfg doit precede simple_boot.cfg');
    }

    #[Test]
    public function it_sanitizes_hostname_before_interpolation(): void
    {
        // Workstation name avec newline + injection ROOT_PASSWD.
        $ws = $this->makeWorkstation("PC-101\nROOT_PASSWD=evil");
        $preseed = $this->service->generate(
            $ws,
            LinuxDistribution::Debian,
            LinuxDesktopVariant::Gnome,
        );

        // L'injection NE DOIT PAS apparaître sur sa propre ligne (le newline a
        // été sanitizé). On ne doit jamais voir `ROOT_PASSWD=evil` au début
        // d'une ligne (= injection réussie).
        self::assertStringNotContainsString("\nROOT_PASSWD=evil", $preseed);
    }

    #[Test]
    public function it_logs_preseed_generated_with_sha256_only(): void
    {
        // Post-review #4 — Réécriture du test tautologique : on injecte
        // des CANARY secrets connus dans la config, on capture tous les events
        // Monolog via TestHandler, et on assert qu'AUCUN log ne contient une
        // canary. On vérifie aussi positivement que le sha256 attendu est loggé.
        config([
            'sambaedu.admin_passwd' => 'CANARY-admin-pwd-123',
            'sambaedu.ldap_admin_passwd' => 'CANARY-ldap-pwd-456',
            'sambaedu.linux.user_passwd' => 'CANARY-user-pwd-789',
            'sambaedu.linux.token' => 'CANARY-token-xyz',
        ]);

        // Push un TestHandler Monolog sur le logger existant du channel `ipxe`
        // (configuré en `driver: daily` dans config/logging.php — `Log::extend`
        // ne fonctionne pas dans ce cas car Laravel résout via le driver).
        $handler = new \Monolog\Handler\TestHandler();
        $logger = \Illuminate\Support\Facades\Log::channel('ipxe');
        $logger->getLogger()->pushHandler($handler);

        $ws = $this->makeWorkstation();
        $preseed = $this->service->generate(
            $ws,
            LinuxDistribution::Debian,
            LinuxDesktopVariant::Gnome,
        );

        $expectedSha = hash('sha256', $preseed);

        // Assertion 1 — aucun secret ne fuite dans les logs.
        $records = $handler->getRecords();
        self::assertNotEmpty($records, 'Aucun log capturé — le channel `ipxe` n\'a pas été appelé.');
        foreach ($records as $record) {
            $payload = json_encode([
                'message' => $record['message'] ?? '',
                'context' => $record['context'] ?? [],
            ]);
            self::assertStringNotContainsString('CANARY-admin-pwd-123', (string) $payload);
            self::assertStringNotContainsString('CANARY-ldap-pwd-456', (string) $payload);
            self::assertStringNotContainsString('CANARY-user-pwd-789', (string) $payload);
            self::assertStringNotContainsString('CANARY-token-xyz', (string) $payload);
        }

        // Assertion 2 — le sha256 attendu est bien présent dans un record info.
        $shaFound = false;
        foreach ($records as $record) {
            $context = $record['context'] ?? [];
            if (($context['preseed_sha256'] ?? null) === $expectedSha) {
                $shaFound = true;
                break;
            }
        }
        self::assertTrue($shaFound, 'Le sha256 du preseed n\'est pas présent dans les contexts de log.');
    }
}
