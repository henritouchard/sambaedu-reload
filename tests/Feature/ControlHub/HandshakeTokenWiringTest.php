<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Events\ControlHubContractChanged;
use App\Models\ControlHubConnection;
use App\Observers\WorkstationGroupObserver;
use App\Repositories\ControlHubConnectionRepository;
use App\Services\ControlHub\ControlHubApiClient;
use App\Services\ControlHub\ControlHubService;
use App\Services\ControlHub\Data\ApiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 39.5 (couture E10) — CÂBLAGE du credential d'auth entrante CH→SE5.
 *
 * Le controlHub authentifie ses appels entrants (ingestion de contrat, rupture
 * de lien) avec le token qu'il FRAPPE et nous renvoie AU handshake (`api_token`).
 * Ce test prouve que `performHandshake()` alimente la colonne `se4fs_api_token`
 * — celle que valide `ControlHubAuth` via `validateSE4FSToken()` — avec CE token,
 * et NON avec notre clé d'instance statique locale (le bug d'origine, qui aurait
 * renvoyé 403 sur tous les appels entrants une fois le CH basculé E10).
 *
 * Tests HÔTE (php8.4 + pdo_sqlite), `RefreshDatabase`.
 */
class HandshakeTokenWiringTest extends TestCase
{
    use RefreshDatabase;

    /** Notre clé d'instance statique locale — volontairement ≠ du token amont. */
    private const LOCAL_INSTANCE_KEY = 'se4fs_instance_local_static_key_0';

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
        $this->withoutVite();
        WorkstationGroupObserver::disableSync();
        Event::fake([ControlHubContractChanged::class]);
        config(['controlHub.se4fs.instance_api_key' => self::LOCAL_INSTANCE_KEY]);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        parent::tearDown();
    }

    /**
     * `ControlHubService` dont le client de handshake est simulé : `sendHandshake`
     * renvoie une réponse portant `instance.api_token = $receivedToken`, sans réseau
     * (le seam `makeHandshakeClient()` est surchargé — le `new Guzzle` réel n'est
     * pas interceptable autrement).
     */
    private function serviceWithReceivedToken(string $receivedToken): ControlHubService
    {
        $handshakeClient = Mockery::mock(ControlHubApiClient::class);
        $handshakeClient->shouldReceive('sendHandshake')->once()->andReturn(
            ApiResponse::success([
                'data' => [
                    'instance' => [
                        'api_token' => $receivedToken,
                        'heartbeat_interval' => 120,
                    ],
                ],
            ], 200)
        );

        $mainClient = new ControlHubApiClient('https://placeholder.invalid');
        $repository = new ControlHubConnectionRepository();

        return new class($mainClient, $repository, $handshakeClient) extends ControlHubService {
            public function __construct($apiClient, $repository, private ControlHubApiClient $fakeHandshakeClient)
            {
                parent::__construct($apiClient, $repository);
            }

            protected function makeHandshakeClient(string $baseUrl): ControlHubApiClient
            {
                return $this->fakeHandshakeClient;
            }
        };
    }

    #[Test]
    public function handshake_stores_the_received_token_as_the_ingress_credential(): void
    {
        $received = 'irundo_'.str_repeat('a', 32);

        $response = $this->serviceWithReceivedToken($received)
            ->performHandshake('master-registration-key', 'https://amont.example');

        $this->assertTrue($response->success);

        $connection = ControlHubConnection::current();
        $this->assertNotNull($connection);

        // Option A — la colonne validée par le middleware porte le token de handshake…
        $this->assertSame($received, $connection->se4fs_api_token);
        // …et surtout PAS notre clé d'instance statique locale (le bug d'origine).
        $this->assertNotSame(self::LOCAL_INSTANCE_KEY, $connection->se4fs_api_token);
        // Cohérence : le token sortant (chiffré) porte la même valeur (bearer commun).
        $this->assertSame($received, $connection->api_token);

        // validateSE4FSToken() valide bien le token de handshake, pas la clé locale.
        $this->assertTrue($connection->validateSE4FSToken($received));
        $this->assertFalse($connection->validateSE4FSToken(self::LOCAL_INSTANCE_KEY));
    }

    #[Test]
    public function the_handshake_token_authenticates_an_ingress_post_end_to_end(): void
    {
        $received = 'irundo_'.str_repeat('b', 32);
        $this->serviceWithReceivedToken($received)
            ->performHandshake('master-registration-key', 'https://amont.example');

        $emptyContract = [
            'schema_version' => '1.0',
            'items' => [],
            'labels' => [],
            'imposed_groups' => [],
            'catalog_apps' => [],
        ];

        // Boucle E10 réelle (middleware ControlHubAuth + route) : le token frappé au
        // handshake authentifie l'ingestion.
        $this->withHeaders(['Authorization' => 'Bearer '.$received])
            ->postJson('/api/v1/controlhub/contract', $emptyContract)
            ->assertOk()
            ->assertJson(['success' => true]);

        // Un token inconnu (ni handshake, ni clé d'instance) reste refusé — 403.
        $this->withHeaders(['Authorization' => 'Bearer irundo_'.str_repeat('c', 32)])
            ->postJson('/api/v1/controlhub/contract', $emptyContract)
            ->assertForbidden();
    }
}
