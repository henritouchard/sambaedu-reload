<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Network;

use App\Models\DhcpReservation;
use App\Services\Network\DhcpService;
use App\Services\Network\Exceptions\DhcpCommandException;
use App\Services\Network\Exceptions\DhcpValidationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeCommandRunner;
use Tests\TestCase;
use Tests\Traits\CreatesDhcpSchema;

/**
 * Story 8.1 — Tests Unit du Service `DhcpService`.
 *
 * Couvre :
 *  - Validations (name regex, MAC normalisation multi-format, IP IPv4).
 *  - Génération `reservations.inc` (snapshot — format legacy strict).
 *  - Statut service (`systemctl is-active`).
 *  - Parsing leases (fixture réelle) : dédup IP + filtre binding state +
 *    exclusion réservations existantes.
 *  - Unicité métier (DhcpValidationException avant SQL UNIQUE).
 *
 * Pas d'I/O fichier réel : les écritures `reservations.inc` sont couvertes
 * par les tests Feature avec config pointant vers un tmp.
 */
class DhcpServiceTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesDhcpSchema;

    private FakeCommandRunner $runner;
    private DhcpService $service;

    private string $tmpReservationsFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDhcpSchema();
        $this->runner = new FakeCommandRunner();
        $this->service = new DhcpService($this->runner);

        // Redirige les écritures fichier vers un tmp test (pas d'I/O `/etc`).
        $this->tmpReservationsFile = sys_get_temp_dir() . '/dhcp_reservations_test_' . uniqid() . '.inc';
        config(['sambaedu.dhcp.reservations_file' => $this->tmpReservationsFile]);
        config(['sambaedu.dhcp.reload_command' => '/usr/share/sambaedu/sbin/make_dhcpd_conf.sh']);
        config(['sambaedu.dhcp.service_name' => 'isc-dhcp-server.service']);
        config(['sambaedu.dhcp.leases_file' => base_path('tests/Fixtures/dhcp/dhcpd.leases')]);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpReservationsFile);
        $this->dropDhcpSchema();
        parent::tearDown();
    }

    // ========================================================================
    // VALIDATION
    // ========================================================================

    #[Test]
    public function it_accepts_valid_names(): void
    {
        $this->service->validateName('poste01');
        $this->service->validateName('a');
        $this->service->validateName('imp_3-a');
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    #[DataProvider('invalidNames')]
    public function it_rejects_invalid_names(string $name): void
    {
        $this->expectException(DhcpValidationException::class);
        $this->service->validateName($name);
    }

    public static function invalidNames(): array
    {
        return [
            'empty' => [''],
            'space' => ['poste 1'],
            'starts_with_dash' => ['-poste'],
            'too_long' => [str_repeat('a', 64)],
            'special' => ['poste@1'],
            'semicolon' => ['poste;1'],
            'slash' => ['../etc'],
        ];
    }

    #[Test]
    #[DataProvider('macFormatsAccepted')]
    public function it_normalizes_mac_to_canonical_format(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->service->validateMac($input));
    }

    public static function macFormatsAccepted(): array
    {
        return [
            'canonical' => ['aa:bb:cc:dd:ee:ff', 'aa:bb:cc:dd:ee:ff'],
            'uppercase' => ['AA:BB:CC:DD:EE:FF', 'aa:bb:cc:dd:ee:ff'],
            'dash_sep' => ['aa-bb-cc-dd-ee-ff', 'aa:bb:cc:dd:ee:ff'],
            'no_sep' => ['aabbccddeeff', 'aa:bb:cc:dd:ee:ff'],
            'cisco' => ['aabb.ccdd.eeff', 'aa:bb:cc:dd:ee:ff'],
            'mixed_case_with_dashes' => ['Aa-Bb-Cc-Dd-Ee-Ff', 'aa:bb:cc:dd:ee:ff'],
        ];
    }

    #[Test]
    #[DataProvider('macInvalidPayloads')]
    public function it_rejects_invalid_macs(string $payload): void
    {
        $this->expectException(DhcpValidationException::class);
        $this->service->validateMac($payload);
    }

    public static function macInvalidPayloads(): array
    {
        return [
            'empty' => [''],
            'too_short' => ['aa:bb:cc'],
            'too_long' => ['aa:bb:cc:dd:ee:ff:00'],
            'non_hex' => ['gg:hh:ii:jj:kk:ll'],
            'extra_chars' => ['aa:bb:cc:dd:ee:zz'],
        ];
    }

    #[Test]
    public function it_accepts_valid_ipv4(): void
    {
        $this->service->validateIp('10.0.0.1');
        $this->service->validateIp('192.168.1.255');
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    #[DataProvider('invalidIps')]
    public function it_rejects_invalid_ips(string $ip): void
    {
        $this->expectException(DhcpValidationException::class);
        $this->service->validateIp($ip);
    }

    public static function invalidIps(): array
    {
        return [
            'empty' => [''],
            'not_ip' => ['not-an-ip'],
            'too_many_octets' => ['1.2.3.4.5'],
            'octet_too_big' => ['256.1.1.1'],
            // IPv6 — explicitement out of scope IPv4 only.
            'ipv6' => ['::1'],
        ];
    }

    // ========================================================================
    // RENDER reservations.inc
    // ========================================================================

    #[Test]
    public function it_renders_reservations_file_in_legacy_format(): void
    {
        $reservations = collect([
            DhcpReservation::factory()->make([
                'name' => 'poste01',
                'mac' => 'aa:bb:cc:dd:ee:01',
                'ip' => '10.0.0.10',
            ]),
            DhcpReservation::factory()->make([
                'name' => 'poste02',
                'mac' => 'aa:bb:cc:dd:ee:02',
                'ip' => '10.0.0.11',
            ]),
        ]);

        $content = $this->service->renderReservationsFile($reservations);

        $this->assertStringContainsString('host poste01 { hardware ethernet aa:bb:cc:dd:ee:01; fixed-address 10.0.0.10; }', $content);
        $this->assertStringContainsString('host poste02 { hardware ethernet aa:bb:cc:dd:ee:02; fixed-address 10.0.0.11; }', $content);
        // Header de provenance
        $this->assertStringContainsString('NE PAS éditer manuellement', $content);
    }

    // ========================================================================
    // SERVICE STATUS
    // ========================================================================

    #[Test]
    public function service_status_returns_active_when_systemctl_returns_zero_and_active(): void
    {
        $this->runner->whenContains('systemctl is-active', 'active', returnCode: 0);
        $status = $this->service->serviceStatus();
        $this->assertTrue($status['active']);
    }

    #[Test]
    public function service_status_returns_inactive_when_systemctl_returns_non_zero(): void
    {
        $this->runner->whenContains('systemctl is-active', 'inactive', returnCode: 3);
        $status = $this->service->serviceStatus();
        $this->assertFalse($status['active']);
        $this->assertSame('inactive', $status['details']);
    }

    // ========================================================================
    // RELOAD (DhcpCommandException si returnCode != 0)
    // ========================================================================

    #[Test]
    public function reload_service_throws_when_script_fails(): void
    {
        $this->runner->whenContains('make_dhcpd_conf.sh', '', returnCode: 1, stderr: 'sudo: command not allowed');
        $this->expectException(DhcpCommandException::class);
        $this->service->reloadService();
    }

    #[Test]
    public function reload_service_succeeds_when_script_returns_zero(): void
    {
        $this->runner->whenContains('make_dhcpd_conf.sh', '', returnCode: 0);
        $this->service->reloadService();
        $this->assertNotEmpty($this->runner->executed);
        $this->assertStringContainsString('sudo', $this->runner->lastCommand());
        // Vérifie l'escape de la commande
        $this->assertStringContainsString("'/usr/share/sambaedu/sbin/make_dhcpd_conf.sh'", $this->runner->lastCommand());
    }

    // ========================================================================
    // PARSING LEASES (fixture réelle)
    // ========================================================================

    #[Test]
    public function it_parses_leases_with_dedup_by_ip_and_state_filter(): void
    {
        $content = file_get_contents(base_path('tests/Fixtures/dhcp/dhcpd.leases'));
        $leases = $this->service->parseLeasesContent($content);

        // 10.0.0.100 (dédup → conserve le plus récent), 10.0.0.101 (free),
        // 10.0.0.103 (active sans hostname).
        // 10.0.0.102 (expired) et 10.0.0.104 (MAC invalide) sont exclus.
        $ips = array_column($leases, 'ip');
        $this->assertContains('10.0.0.100', $ips);
        $this->assertContains('10.0.0.101', $ips);
        $this->assertContains('10.0.0.103', $ips);
        $this->assertNotContains('10.0.0.102', $ips);
        $this->assertNotContains('10.0.0.104', $ips);

        // Dédup : sur 10.0.0.100 on doit avoir `client-recent` (deuxième bloc)
        $lease100 = collect($leases)->firstWhere('ip', '10.0.0.100');
        $this->assertSame('client-recent', $lease100['hostname']);
    }

    // ========================================================================
    // UNICITÉ MÉTIER
    // ========================================================================

    #[Test]
    public function create_throws_validation_exception_on_duplicate_mac(): void
    {
        $this->runner->whenContains('make_dhcpd_conf.sh', '', returnCode: 0);
        $this->runner->whenContains('systemctl', 'active', returnCode: 0);

        $this->service->createReservation([
            'name' => 'poste01',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'ip' => '10.0.0.10',
        ]);

        $this->expectException(DhcpValidationException::class);
        $this->service->createReservation([
            'name' => 'poste02',
            'mac' => 'aa:bb:cc:dd:ee:01',  // doublon
            'ip' => '10.0.0.11',
        ]);
    }

    #[Test]
    public function create_throws_validation_exception_on_duplicate_ip(): void
    {
        $this->runner->whenContains('make_dhcpd_conf.sh', '', returnCode: 0);
        $this->service->createReservation([
            'name' => 'poste01',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'ip' => '10.0.0.10',
        ]);

        $this->expectException(DhcpValidationException::class);
        $this->service->createReservation([
            'name' => 'poste02',
            'mac' => 'aa:bb:cc:dd:ee:02',
            'ip' => '10.0.0.10',  // doublon
        ]);
    }

    #[Test]
    public function create_throws_validation_exception_on_duplicate_name(): void
    {
        $this->runner->whenContains('make_dhcpd_conf.sh', '', returnCode: 0);
        $this->service->createReservation([
            'name' => 'poste01',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'ip' => '10.0.0.10',
        ]);

        $this->expectException(DhcpValidationException::class);
        $this->service->createReservation([
            'name' => 'poste01',  // doublon
            'mac' => 'aa:bb:cc:dd:ee:02',
            'ip' => '10.0.0.11',
        ]);
    }

    // ========================================================================
    // Garde plage dynamique (symétrique de assertNoRangeCoversReservation)
    // ========================================================================

    /**
     * Bind un SambaEduConfig factice exposant la plage du sous-réseau par
     * défaut (lue depuis sambaedu.conf en prod).
     *
     * @param  array<string,string>  $keys
     */
    private function fakeSambaEduDefaultRange(array $keys): void
    {
        $this->app->instance(\App\Config\SambaEduConfig::class, new class($keys) extends \App\Config\SambaEduConfig {
            /** @param array<string,string> $keys */
            public function __construct(private array $keys) {}

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->keys[$key] ?? $default;
            }
        });
    }

    #[Test]
    public function reservation_ip_inside_default_subnet_range_is_refused(): void
    {
        $this->runner->whenContains('make_dhcpd_conf.sh', '', returnCode: 0);
        $this->fakeSambaEduDefaultRange([
            'dhcp_begin_range' => '192.168.122.100',
            'dhcp_end_range' => '192.168.122.200',
        ]);

        $this->expectException(DhcpValidationException::class);
        $this->expectExceptionMessageMatches('/plage dynamique 192\.168\.122\.100.*défaut/');

        $this->service->createReservation([
            'name' => 'imprimante-cdi',
            'mac' => 'aa:bb:cc:dd:ee:10',
            'ip' => '192.168.122.150', // dans la plage
        ]);
    }

    #[Test]
    public function reservation_ip_outside_ranges_is_accepted(): void
    {
        $this->runner->whenContains('make_dhcpd_conf.sh', '', returnCode: 0);
        $this->fakeSambaEduDefaultRange([
            'dhcp_begin_range' => '192.168.122.100',
            'dhcp_end_range' => '192.168.122.200',
        ]);

        $r = $this->service->createReservation([
            'name' => 'imprimante-cdi',
            'mac' => 'aa:bb:cc:dd:ee:11',
            'ip' => '192.168.122.55', // hors plage
        ]);

        $this->assertSame('192.168.122.55', $r->ip);
    }

    #[Test]
    public function reservation_ip_inside_managed_vlan_range_is_refused(): void
    {
        $this->runner->whenContains('make_dhcpd_conf.sh', '', returnCode: 0);
        \App\Models\DhcpSubnet::factory()->create([
            'vlan_id' => 30,
            'network' => '192.168.30.0/24',
            'ranges' => [['begin' => '192.168.30.50', 'end' => '192.168.30.150']],
        ]);

        $this->expectException(DhcpValidationException::class);
        $this->expectExceptionMessageMatches('/VLAN 30/');

        $this->service->createReservation([
            'name' => 'nas-svt',
            'mac' => 'aa:bb:cc:dd:ee:12',
            'ip' => '192.168.30.100', // dans la plage du VLAN 30
        ]);
    }

    #[Test]
    public function malformed_default_range_does_not_block_reservation(): void
    {
        $this->runner->whenContains('make_dhcpd_conf.sh', '', returnCode: 0);
        // Conf bancale (bornes vides) : la garde ne doit PAS faire échouer une
        // réservation légitime.
        $this->fakeSambaEduDefaultRange(['dhcp_begin_range' => '', 'dhcp_end_range' => '']);

        $r = $this->service->createReservation([
            'name' => 'poste-x',
            'mac' => 'aa:bb:cc:dd:ee:13',
            'ip' => '192.168.122.150',
        ]);

        $this->assertSame('192.168.122.150', $r->ip);
    }
}
