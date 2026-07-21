<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Network\Data\DnsUpdateOutcome;
use App\Services\Network\DnsRecordService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 8.4 / AC1 — Endpoint DDNS natif servi AVANT le catchall.
 *
 * Le service est mocké : ici on vérifie le contrat HTTP (chemins servis,
 * paramètres transmis, réponse inerte), pas la logique DNS (couverte par
 * `DnsRecordServiceTest`).
 */
class DhcpDnsUpdateEndpointTest extends TestCase
{
    /**
     * Attend un appel `apply()` avec les arguments donnés et renvoie `$outcome`.
     */
    private function expectApply(string $action, string $name, string $ip, DnsUpdateOutcome $outcome): void
    {
        $service = Mockery::mock(DnsRecordService::class);
        $service->shouldReceive('apply')
            ->once()
            ->with($action, $name, $ip)
            ->andReturn($outcome);

        $this->app->instance(DnsRecordService::class, $service);
    }

    private function expectNoApply(): void
    {
        $service = Mockery::mock(DnsRecordService::class);
        $service->shouldNotReceive('apply');

        $this->app->instance(DnsRecordService::class, $service);
    }

    #[Test]
    public function native_path_is_served_and_delegates_to_service(): void
    {
        $this->expectApply('add', 'pc-salle-01', '192.168.122.103', DnsUpdateOutcome::UNCHANGED);

        $this->post('/dhcp/dnsupdate', [
            'action' => 'add',
            'name' => 'pc-salle-01',
            'ip' => '192.168.122.103',
            'mac' => '00:11:22:33:44:55',
        ])
            ->assertOk()
            ->assertSee('unchanged');
    }

    /**
     * Le chemin legacy reste servi NATIVEMENT : une instance dont
     * `dhcp-dyndns.sh` n'a pas encore été redéployé par `update.sh` ne doit ni
     * tomber sur le catchall (proxy vers un vhost legacy mort), ni polluer le
     * verdict legacy de `se4:status` (38.6).
     */
    #[Test]
    public function legacy_php_path_is_served_natively(): void
    {
        $this->expectApply('add', 'pc-salle-01', '192.168.122.103', DnsUpdateOutcome::CREATED);

        $response = $this->post('/dhcp/dnsupdate.php', [
            'se4_key' => 'cle-legacy-ignoree',
            'action' => 'add',
            'name' => 'pc-salle-01',
            'ip' => '192.168.122.103',
        ]);

        $response->assertOk()->assertSee('created');
        // Le catchall aurait renvoyé du HTML/erreur, jamais ce corps typé.
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    }

    /** `se4_key` n'est plus une auth : sa présence ou son absence est sans effet. */
    #[Test]
    public function missing_se4_key_is_accepted(): void
    {
        $this->expectApply('add', 'pc-salle-01', '192.168.122.103', DnsUpdateOutcome::UNCHANGED);

        $this->post('/dhcp/dnsupdate', [
            'action' => 'add',
            'name' => 'pc-salle-01',
            'ip' => '192.168.122.103',
        ])->assertOk();
    }

    /**
     * Release/expiry : dhcpd envoie un nom vide — le contrôleur doit
     * transmettre tel quel (le service résout le porteur de l'IP).
     */
    #[Test]
    public function delete_without_name_is_forwarded(): void
    {
        $this->expectApply('delete', '', '192.168.122.104', DnsUpdateOutcome::DELETED);

        $this->post('/dhcp/dnsupdate', [
            'action' => 'delete',
            'name' => '',
            'ip' => '192.168.122.104',
        ])->assertOk()->assertSee('deleted');
    }

    /** Paramètres absents : pas de 500, le service tranche (skipped). */
    #[Test]
    public function missing_parameters_do_not_error(): void
    {
        $this->expectApply('', '', '', DnsUpdateOutcome::SKIPPED);

        $this->post('/dhcp/dnsupdate', [])->assertOk()->assertSee('skipped');
    }

    /** L'action est normalisée (casse/espaces) avant d'atteindre le service. */
    #[Test]
    public function action_is_normalized(): void
    {
        $this->expectApply('add', 'pc-salle-01', '192.168.122.103', DnsUpdateOutcome::UNCHANGED);

        $this->post('/dhcp/dnsupdate', [
            'action' => '  ADD ',
            'name' => 'pc-salle-01',
            'ip' => '192.168.122.103',
        ])->assertOk();
    }

    /**
     * Un échec DNS reste un 200 : l'appelant est un `curl` détaché dont dhcpd
     * ignore le code de sortie — l'erreur est tracée côté channel `network`.
     */
    #[Test]
    public function failure_still_returns_200(): void
    {
        $this->expectApply('add', 'pc-salle-01', '192.168.122.103', DnsUpdateOutcome::FAILED);

        $this->post('/dhcp/dnsupdate', [
            'action' => 'add',
            'name' => 'pc-salle-01',
            'ip' => '192.168.122.103',
        ])->assertOk()->assertSee('failed');
    }

    /** Appel machine : aucune session/CSRF requise (`withoutMiddleware(['web'])`). */
    #[Test]
    public function post_without_csrf_token_is_accepted(): void
    {
        $this->expectApply('add', 'pc-salle-01', '192.168.122.103', DnsUpdateOutcome::UNCHANGED);

        $this->post('/dhcp/dnsupdate', [
            'action' => 'add',
            'name' => 'pc-salle-01',
            'ip' => '192.168.122.103',
        ])->assertOk();
    }

    /** Le corps est inerte : ni HTML, ni écho des paramètres reçus. */
    #[Test]
    public function response_body_is_inert(): void
    {
        $this->expectApply('add', 'pc-salle-01', '192.168.122.103', DnsUpdateOutcome::UNCHANGED);

        $body = $this->post('/dhcp/dnsupdate', [
            'action' => 'add',
            'name' => 'pc-salle-01',
            'ip' => '192.168.122.103',
        ])->getContent();

        $this->assertSame("unchanged\n", $body);
        $this->assertStringNotContainsString('<', $body);
        $this->assertStringNotContainsString('192.168.122.103', $body);
    }

    /**
     * Une requête venue d'un POSTE DU PARC est refusée : la garde n'autorise
     * que le serveur lui-même (dhcpd est co-localisé). C'est ce qui empêche
     * n'importe quelle machine du LAN de supprimer l'enregistrement A du DC ou
     * de détourner un nom vers son IP — l'endpoint n'authentifie pas
     * l'appelant.
     */
    #[Test]
    public function lan_workstation_is_rejected_by_server_guard(): void
    {
        $this->expectNoApply();

        $response = $this->call(
            'POST',
            '/dhcp/dnsupdate',
            ['action' => 'delete', 'ip' => '192.168.122.60'],
            [],
            [],
            ['REMOTE_ADDR' => '192.168.122.42'],
        );

        $response->assertForbidden();
    }

    /** L'adresse propre du serveur (`se4fs_ip`) est acceptée. */
    #[Test]
    public function server_own_ip_is_accepted_by_guard(): void
    {
        $this->mock(\App\Config\SambaEduConfig::class, function ($mock) {
            $mock->shouldReceive('get')->with('se4fs_ip', '')->andReturn('192.168.122.50');
            $mock->shouldReceive('get')->andReturnUsing(
                fn (string $key, mixed $default = null): mixed => $default,
            );
        });

        $this->call(
            'POST',
            '/dhcp/dnsupdate',
            ['action' => 'add', 'name' => 'pc-salle-01', 'ip' => '192.168.122.103'],
            [],
            [],
            ['REMOTE_ADDR' => '192.168.122.50'],
        )->assertOk();
    }

    /**
     * En GET, une balise `<img>` sur une page hostile suffirait à déclencher
     * une suppression DNS depuis un navigateur du serveur.
     */
    #[Test]
    public function get_is_not_routable(): void
    {
        $this->expectNoApply();

        $this->get('/dhcp/dnsupdate')->assertNotFound();
    }
}
