<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Network;

use App\Config\SambaEduConfig;
use App\Models\DhcpReservation;
use App\Models\DhcpSubnet;
use App\Services\Network\DhcpService;
use App\Services\Network\DhcpSubnetService;
use App\Services\Network\Exceptions\DhcpValidationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeCommandRunner;
use Tests\TestCase;
use Tests\Traits\CreatesDhcpSchema;

/**
 * Story 8.3 — Tests Unit du service `DhcpSubnetService`.
 *
 * Couvre :
 *  - matrice de validations (CIDR, vlan_id bornes/unicité, gateway/plages
 *    hors réseau, begin>end, chevauchements inter-VLAN + vs défaut + vs
 *    réservation) ;
 *  - snapshot `renderSubnetsConfFile` (multi-plages, suffixes `_N_j`, tri
 *    stable, extra_option) ;
 *  - transaction tout-ou-rien (aucune écriture partielle en cas de rejet).
 */
class DhcpSubnetServiceTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesDhcpSchema;

    private FakeCommandRunner $runner;
    private DhcpSubnetService $service;
    private string $tmpSubnetsFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDhcpSchema();

        $this->runner = new FakeCommandRunner();
        $this->runner->whenContains('make_dhcpd_conf.sh', '', returnCode: 0);
        $this->runner->whenContains('systemctl', 'active', returnCode: 0);

        $this->service = $this->makeService();

        $this->tmpSubnetsFile = sys_get_temp_dir() . '/dhcp_subnets_test_' . uniqid() . '.conf';
        config(['sambaedu.dhcp.subnets_file' => $this->tmpSubnetsFile]);
        config(['sambaedu.dhcp.reload_command' => '/usr/share/sambaedu/sbin/make_dhcpd_conf.sh']);
        config(['sambaedu.dhcp.service_name' => 'isc-dhcp-server.service']);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpSubnetsFile);
        $this->dropDhcpSchema();
        parent::tearDown();
    }

    /**
     * Service avec un sous-réseau par défaut optionnel (mock SambaEduConfig).
     *
     * @param  array<string,string>  $defaultKeys
     */
    private function makeService(array $defaultKeys = []): DhcpSubnetService
    {
        $config = new class($defaultKeys) extends SambaEduConfig {
            /** @param array<string,string> $keys */
            public function __construct(private array $keys)
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->keys[$key] ?? $default;
            }
        };

        return new DhcpSubnetService(new DhcpService($this->runner), $config);
    }

    // ========================================================================
    // CIDR
    // ========================================================================

    #[Test]
    public function validate_cidr_normalizes_to_network_base(): void
    {
        $result = $this->service->validateCidr('192.168.20.5/24');
        $this->assertSame('192.168.20.0/24', $result['network']);
        $this->assertSame(24, $result['prefix']);
    }

    #[Test]
    #[DataProvider('invalidCidrs')]
    public function validate_cidr_rejects_invalid(string $cidr): void
    {
        $this->expectException(DhcpValidationException::class);
        $this->service->validateCidr($cidr);
    }

    public static function invalidCidrs(): array
    {
        return [
            'no_slash' => ['192.168.20.0'],
            'bad_prefix' => ['192.168.20.0/33'],
            'zero_prefix' => ['192.168.20.0/0'],
            'non_numeric_prefix' => ['192.168.20.0/ab'],
            'bad_ip' => ['300.1.1.0/24'],
            'empty' => [''],
        ];
    }

    // ========================================================================
    // VLAN ID
    // ========================================================================

    #[Test]
    #[DataProvider('invalidVlanIds')]
    public function validate_vlan_id_rejects_out_of_bounds(int $vlanId): void
    {
        $this->expectException(DhcpValidationException::class);
        $this->service->validateVlanId($vlanId);
    }

    public static function invalidVlanIds(): array
    {
        return [
            'zero' => [0],
            'negative' => [-5],
            'too_high' => [1000],
        ];
    }

    #[Test]
    public function validate_vlan_id_rejects_duplicate(): void
    {
        DhcpSubnet::factory()->create(['vlan_id' => 20, 'network' => '192.168.20.0/24']);

        $this->expectException(DhcpValidationException::class);
        $this->service->validateVlanId(20);
    }

    #[Test]
    public function validate_vlan_id_allows_same_id_on_update(): void
    {
        $subnet = DhcpSubnet::factory()->create(['vlan_id' => 20, 'network' => '192.168.20.0/24']);
        $this->service->validateVlanId(20, $subnet->id);
        $this->expectNotToPerformAssertions();
    }

    // ========================================================================
    // EXTRA_OPTION (sécurité — review 8.3 #1 : injection shell via config.inc.sh eval)
    // ========================================================================

    #[Test]
    #[DataProvider('validExtraOptions')]
    public function validate_extra_option_accepts_absolute_paths(string $path): void
    {
        $this->assertSame($path, $this->service->validateExtraOption($path));
    }

    public static function validExtraOptions(): array
    {
        return [
            'simple' => ['/etc/dhcp/vlan20.conf'],
            'nested' => ['/etc/sambaedu/dhcp.d/extra-20.conf'],
            'dashes_dots' => ['/opt/se5/vlan-20_extra.d/file.conf'],
        ];
    }

    #[Test]
    #[DataProvider('maliciousExtraOptions')]
    public function validate_extra_option_rejects_injection_payloads(string $payload): void
    {
        $this->expectException(DhcpValidationException::class);
        $this->service->validateExtraOption($payload);
    }

    /**
     * Payloads d'attaque : la valeur est réinjectée entre apostrophes simples
     * dans un `eval` root côté VM (config.inc.sh). Aucun de ces payloads ne doit
     * passer la liste blanche « chemin absolu ».
     */
    public static function maliciousExtraOptions(): array
    {
        return [
            // Injection par apostrophe simple + brace expansion (SANS espace).
            'single_quote_brace_rce' => ["/a';{touch,/tmp/pwned};x='"],
            'single_quote' => ["/etc/x'"],
            'semicolon' => ['/etc/x;reboot'],
            'backtick' => ['/etc/`id`'],
            'dollar_subshell' => ['/etc/$(id)'],
            'braces' => ['/etc/{a,b}'],
            'pipe' => ['/etc/x|y'],
            'ampersand' => ['/etc/x&y'],
            'space' => ['/etc/dhcp file.conf'],
            'double_quote' => ['/etc/x"y'],
            'not_absolute' => ['relative/path.conf'],
        ];
    }

    #[Test]
    public function validate_extra_option_treats_empty_as_null(): void
    {
        $this->assertNull($this->service->validateExtraOption(null));
        $this->assertNull($this->service->validateExtraOption(''));
        $this->assertNull($this->service->validateExtraOption('   '));
    }

    // ========================================================================
    // CRUD + validations composites
    // ========================================================================

    #[Test]
    public function create_subnet_persists_and_reloads(): void
    {
        $subnet = $this->service->createSubnet([
            'vlan_id' => 20,
            'network' => '192.168.20.0/24',
            'gateway' => '192.168.20.254',
            'ranges' => [['begin' => '192.168.20.10', 'end' => '192.168.20.100']],
        ]);

        $this->assertDatabaseHas('dhcp_subnets', ['vlan_id' => 20, 'network' => '192.168.20.0/24']);
        $this->assertSame('192.168.20.254', $subnet->gateway);

        $reload = collect($this->runner->executed)->contains(fn ($c) => str_contains($c, 'make_dhcpd_conf.sh'));
        $this->assertTrue($reload, 'Le reload doit être déclenché après création.');

        // AC2 (review 8.3 #4) — vérification bout-en-bout : le fichier de params
        // sur disque reflète réellement la mutation (SQL → AtomicFileWriter → disque),
        // pas seulement le rendu en mémoire.
        $this->assertFileExists($this->tmpSubnetsFile);
        $exported = file_get_contents($this->tmpSubnetsFile);
        $this->assertStringContainsString('dhcp_reseau_20 = "192.168.20.0"', $exported);
        $this->assertStringContainsString('dhcp_begin_range_20 = "192.168.20.10"', $exported);
    }

    #[Test]
    public function create_rejects_gateway_outside_network(): void
    {
        $this->expectException(DhcpValidationException::class);
        $this->service->createSubnet([
            'vlan_id' => 20,
            'network' => '192.168.20.0/24',
            'gateway' => '10.0.0.1', // hors réseau
            'ranges' => [['begin' => '192.168.20.10', 'end' => '192.168.20.100']],
        ]);
    }

    #[Test]
    public function create_rejects_range_outside_network(): void
    {
        $this->expectException(DhcpValidationException::class);
        $this->service->createSubnet([
            'vlan_id' => 20,
            'network' => '192.168.20.0/24',
            'gateway' => '192.168.20.254',
            'ranges' => [['begin' => '192.168.21.10', 'end' => '192.168.21.100']], // hors réseau
        ]);
    }

    #[Test]
    public function create_rejects_begin_greater_than_end(): void
    {
        $this->expectException(DhcpValidationException::class);
        $this->service->createSubnet([
            'vlan_id' => 20,
            'network' => '192.168.20.0/24',
            'gateway' => '192.168.20.254',
            'ranges' => [['begin' => '192.168.20.200', 'end' => '192.168.20.10']],
        ]);
    }

    #[Test]
    public function create_rejects_empty_ranges(): void
    {
        $this->expectException(DhcpValidationException::class);
        $this->service->createSubnet([
            'vlan_id' => 20,
            'network' => '192.168.20.0/24',
            'gateway' => '192.168.20.254',
            'ranges' => [['begin' => '', 'end' => '']],
        ]);
    }

    #[Test]
    public function create_rejects_overlap_between_vlans(): void
    {
        $this->service->createSubnet([
            'vlan_id' => 20,
            'network' => '192.168.20.0/24',
            'gateway' => '192.168.20.254',
            'ranges' => [['begin' => '192.168.20.10', 'end' => '192.168.20.100']],
        ]);

        $this->expectException(DhcpValidationException::class);
        // /23 englobe 192.168.20.0/24 → chevauchement
        $this->service->createSubnet([
            'vlan_id' => 21,
            'network' => '192.168.20.0/23',
            'gateway' => '192.168.20.254',
            'ranges' => [['begin' => '192.168.21.10', 'end' => '192.168.21.100']],
        ]);
    }

    #[Test]
    public function create_rejects_overlap_with_default_subnet(): void
    {
        $service = $this->makeService([
            'dhcp_reseau' => '192.168.20.0',
            'dhcp_masque' => '255.255.255.0',
        ]);

        $this->expectException(DhcpValidationException::class);
        $service->createSubnet([
            'vlan_id' => 20,
            'network' => '192.168.20.0/24',
            'gateway' => '192.168.20.254',
            'ranges' => [['begin' => '192.168.20.10', 'end' => '192.168.20.100']],
        ]);
    }

    #[Test]
    public function create_rejects_range_covering_existing_reservation(): void
    {
        DhcpReservation::create([
            'name' => 'poste01',
            'mac' => 'aa:bb:cc:dd:ee:01',
            'ip' => '192.168.20.50',
            'source' => 'manual',
        ]);

        $this->expectException(DhcpValidationException::class);
        $this->service->createSubnet([
            'vlan_id' => 20,
            'network' => '192.168.20.0/24',
            'gateway' => '192.168.20.254',
            'ranges' => [['begin' => '192.168.20.10', 'end' => '192.168.20.100']], // recouvre .50
        ]);
    }

    #[Test]
    public function update_and_delete_subnet(): void
    {
        $subnet = $this->service->createSubnet([
            'vlan_id' => 20,
            'network' => '192.168.20.0/24',
            'gateway' => '192.168.20.254',
            'ranges' => [['begin' => '192.168.20.10', 'end' => '192.168.20.100']],
        ]);

        $this->service->updateSubnet($subnet, ['gateway' => '192.168.20.1']);
        $this->assertSame('192.168.20.1', $subnet->fresh()->gateway);

        $this->service->deleteSubnet($subnet);
        $this->assertDatabaseMissing('dhcp_subnets', ['id' => $subnet->id]);
    }

    // ========================================================================
    // TRANSACTION TOUT-OU-RIEN
    // ========================================================================

    #[Test]
    public function invalid_create_writes_nothing(): void
    {
        $this->service->createSubnet([
            'vlan_id' => 20,
            'network' => '192.168.20.0/24',
            'gateway' => '192.168.20.254',
            'ranges' => [['begin' => '192.168.20.10', 'end' => '192.168.20.100']],
        ]);

        try {
            $this->service->createSubnet([
                'vlan_id' => 20, // doublon → rejet AVANT toute écriture
                'network' => '192.168.30.0/24',
                'gateway' => '192.168.30.254',
                'ranges' => [['begin' => '192.168.30.10', 'end' => '192.168.30.100']],
            ]);
            $this->fail('Une DhcpValidationException était attendue.');
        } catch (DhcpValidationException) {
            // attendu
        }

        $this->assertSame(1, DhcpSubnet::query()->count());
        $this->assertDatabaseMissing('dhcp_subnets', ['network' => '192.168.30.0/24']);
    }

    // ========================================================================
    // RENDER (snapshot)
    // ========================================================================

    #[Test]
    public function render_emits_multi_ranges_with_contiguous_suffixes_and_stable_sort(): void
    {
        // Volontairement dans le désordre pour vérifier le tri par vlan_id.
        $subnets = collect([
            DhcpSubnet::factory()->make([
                'vlan_id' => 30,
                'network' => '192.168.30.0/24',
                'gateway' => '192.168.30.254',
                'ranges' => [['begin' => '192.168.30.10', 'end' => '192.168.30.50']],
                'extra_option' => '/etc/dhcp/vlan30.conf',
            ]),
            DhcpSubnet::factory()->make([
                'vlan_id' => 20,
                'network' => '192.168.20.0/24',
                'gateway' => '192.168.20.254',
                'ranges' => [
                    ['begin' => '192.168.20.10', 'end' => '192.168.20.50'],
                    ['begin' => '192.168.20.100', 'end' => '192.168.20.150'],
                    ['begin' => '192.168.20.200', 'end' => '192.168.20.250'],
                ],
            ]),
        ]);

        $out = $this->service->renderSubnetsConfFile($subnets);

        // Tri stable : VLAN 20 émis avant VLAN 30.
        $this->assertLessThan(strpos($out, 'dhcp_reseau_30'), strpos($out, 'dhcp_reseau_20'));

        // VLAN 20 : réseau + masque dérivé + gateway.
        $this->assertStringContainsString('dhcp_reseau_20 = "192.168.20.0"', $out);
        $this->assertStringContainsString('dhcp_masque_20 = "255.255.255.0"', $out);
        $this->assertStringContainsString('dhcp_gateway_20 = "192.168.20.254"', $out);

        // 1re plage sans suffixe.
        $this->assertStringContainsString('dhcp_begin_range_20 = "192.168.20.10"', $out);
        $this->assertStringContainsString('dhcp_end_range_20 = "192.168.20.50"', $out);

        // Plages 2+ : suffixe _j contigu dès 1.
        $this->assertStringContainsString('dhcp_begin_range_20_1 = "192.168.20.100"', $out);
        $this->assertStringContainsString('dhcp_end_range_20_1 = "192.168.20.150"', $out);
        $this->assertStringContainsString('dhcp_begin_range_20_2 = "192.168.20.200"', $out);
        $this->assertStringContainsString('dhcp_end_range_20_2 = "192.168.20.250"', $out);

        // Pas de suffixe _0 ni _3.
        $this->assertStringNotContainsString('dhcp_begin_range_20_0', $out);
        $this->assertStringNotContainsString('dhcp_begin_range_20_3', $out);

        // extra_option seulement quand présent.
        $this->assertStringContainsString('dhcp_extra_option_30 = "/etc/dhcp/vlan30.conf"', $out);
        $this->assertStringNotContainsString('dhcp_extra_option_20', $out);

        // En-tête de provenance.
        $this->assertStringContainsString('NE PAS éditer manuellement', $out);
    }
}
