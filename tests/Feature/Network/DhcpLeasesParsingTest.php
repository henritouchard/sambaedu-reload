<?php

declare(strict_types=1);

namespace Tests\Feature\Network;

use App\Models\DhcpReservation;
use App\Services\Network\DhcpService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Support\FakeCommandRunner;
use Tests\TestCase;
use Tests\Traits\CreatesDhcpSchema;

/**
 * Story 8.1 — Test Feature parsing leases (`/var/lib/dhcp/dhcpd.leases`).
 *
 * Couvre :
 *  - parsing fixture réelle (binding state filter + dédup par IP) ;
 *  - exclusion des baux qui matchent une réservation existante (legacy
 *    `list_dhcp_leases`).
 *  - file introuvable / illisible : retourne collection vide (mode dégradé).
 */
class DhcpLeasesParsingTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesDhcpSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDhcpSchema();
        config(['sambaedu.dhcp.leases_file' => base_path('tests/Fixtures/dhcp/dhcpd.leases')]);
    }

    protected function tearDown(): void
    {
        $this->dropDhcpSchema();
        parent::tearDown();
    }

    public function test_it_lists_active_leases_from_fixture(): void
    {
        $service = new DhcpService(new FakeCommandRunner());
        $leases = $service->listActiveLeases();

        $this->assertGreaterThanOrEqual(2, $leases->count());
        $ips = $leases->pluck('ip')->all();
        $this->assertContains('10.0.0.100', $ips);
        $this->assertContains('10.0.0.101', $ips);
    }

    public function test_it_excludes_leases_matching_existing_reservation(): void
    {
        // Réservation pour 10.0.0.100 → doit disparaître des baux affichés
        DhcpReservation::create([
            'name' => 'reservedHost',
            'mac' => '00:11:22:aa:bb:01',
            'ip' => '10.0.0.100',
            'source' => 'manual',
        ]);

        $service = new DhcpService(new FakeCommandRunner());
        $leases = $service->listActiveLeases();

        $ips = $leases->pluck('ip')->all();
        $this->assertNotContains('10.0.0.100', $ips);
    }

    public function test_it_returns_empty_collection_when_file_missing(): void
    {
        config(['sambaedu.dhcp.leases_file' => '/nonexistent/path']);
        $service = new DhcpService(new FakeCommandRunner());
        $this->assertTrue($service->listActiveLeases()->isEmpty());
    }

    /**
     * Review code 8.1 #10 — un body de bail peut contenir une option ISC avec
     * des accolades imbriquées (`set` blocks, `vendor-class-identifier {…}`).
     * Le parseur doit compter les `{`/`}` pour trouver la fermeture au bon
     * niveau, plutôt que de couper sur le premier `}` rencontré.
     */
    public function test_it_parses_lease_body_with_nested_braces(): void
    {
        $content = <<<'LEASES'
        lease 10.0.0.150 {
          starts 4 2026/05/11 10:00:00;
          ends 4 2026/05/11 12:00:00;
          binding state active;
          hardware ethernet aa:bb:cc:dd:ee:50;
          client-hostname "nestedHost";
          set vendor-options = {
            option-42 "some-value";
          };
        }
        LEASES;

        $service = new DhcpService(new FakeCommandRunner());
        $leases = $service->parseLeasesContent($content);

        $this->assertCount(1, $leases, 'Un seul bail doit être extrait malgré les accolades imbriquées');
        $this->assertSame('10.0.0.150', $leases[0]['ip']);
        $this->assertSame('aa:bb:cc:dd:ee:50', $leases[0]['mac']);
        $this->assertSame('nestedHost', $leases[0]['hostname']);
        $this->assertSame('active', $leases[0]['state']);
    }
}
