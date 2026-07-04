<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Events\ControlHubContractChanged;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractCatalogApp;
use App\Models\ControlHubContractImposedGroup;
use App\Models\ControlHubContractItem;
use App\Models\ControlHubContractLabel;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 39.1 (FR-A1 + NFR-A1..A3) — Endpoint HTTP de RÉCEPTION du contrat amont
 * (canal ① du lien managé), `POST /api/v1/controlhub/contract`.
 *
 * Câblage pur : le contrôleur délègue à `ControlHubContractIngestionService::
 * ingest()` (Epics 28/33, déjà testé unitairement dans
 * `ControlHubContractIngestionTest`/`ControlHubContractSchemaVersionTest`/
 * `UnsupportedSchemaVersionRejectionTest`). Cette suite prouve la ROUTE : 200
 * nominal (résumé complet), no-op idempotent (AC #4), 422 sur les deux
 * exceptions de domaine (AC #5, état inchangé), 403 auth (AC #6, patron
 * `ContractSeveranceChannelsTest`).
 *
 * Tests HÔTE (php8.4 + pdo_sqlite), `RefreshDatabase`. ⚠️ GARDE-FOU R3 : aucun
 * « central » ; vocabulaire « amont » / `ControlHub*`.
 */
class ContractIngestionEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_KEY = 'instance-key-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
        $this->withoutVite();
        WorkstationGroupObserver::disableSync();
        Event::fake([ControlHubContractChanged::class]);
        config(['controlHub.se4fs.instance_api_key' => self::VALID_KEY]);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        parent::tearDown();
    }

    /**
     * Payload de référence conforme `se5-contract/v1` (4 agrégats).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'schema_version' => '1.0',
            'items' => [
                ['type' => 'capabilities', 'key' => 'cap_show_ext', 'value' => 'on', 'enforcement_state' => 'locked', 'target_type' => 'instance'],
                ['type' => 'wallpapers', 'key' => 'wp_default', 'value' => 'corp.jpg', 'enforcement_state' => 'permissive', 'target_type' => 'label', 'target_label' => 'salle-info'],
            ],
            'labels' => [
                ['name' => 'salle-info', 'mode' => 'reserved'],
                ['name' => 'nomade', 'mode' => 'free'],
            ],
            'imposed_groups' => [
                ['name' => 'parc-terminales', 'label_name' => 'salle-info'],
            ],
            'catalog_apps' => [
                ['app_key' => 'firefox', 'display_name' => 'Firefox'],
                ['app_key' => 'libreoffice', 'display_name' => 'LibreOffice'],
            ],
        ], $overrides);
    }

    private function postContract(array $payload, ?string $bearer = self::VALID_KEY): \Illuminate\Testing\TestResponse
    {
        $headers = $bearer !== null ? ['Authorization' => 'Bearer '.$bearer] : [];

        return $this->withHeaders($headers)->postJson('/api/v1/controlhub/contract', $payload);
    }

    // ── 200 nominal ─────────────────────────────────────────────────────────

    #[Test]
    public function a_conformant_payload_is_ingested_and_returns_the_full_summary(): void
    {
        $response = $this->postContract($this->payload());

        $response->assertOk()->assertJson([
            'success' => true,
            'contract_created' => true,
            'mutated' => true,
            'schema_version' => '1.0',
            'items' => ['created' => 2, 'updated' => 0, 'deleted' => 0],
            'labels' => ['created' => 2, 'updated' => 0, 'deleted' => 0],
            'imposed_groups' => ['created' => 1, 'updated' => 0, 'deleted' => 0],
            'catalog_apps' => ['created' => 2, 'updated' => 0, 'deleted' => 0],
        ]);
        $response->assertJsonStructure(['contract_id']);

        $this->assertDatabaseCount('controlhub_contracts', 1);
        $this->assertSame(2, ControlHubContractItem::count());
        $this->assertSame(2, ControlHubContractLabel::count());
        $this->assertSame(1, ControlHubContractImposedGroup::count());
        $this->assertSame(2, ControlHubContractCatalogApp::count());

        Event::assertDispatchedTimes(ControlHubContractChanged::class, 1);
    }

    // ── No-op idempotent (NFR-A2 / AC #4) ──────────────────────────────────

    #[Test]
    public function an_identical_second_post_is_a_noop_and_does_not_redispatch_the_event(): void
    {
        $this->postContract($this->payload())->assertOk();

        $contract = ControlHubContract::firstOrFail();
        $receivedAtBefore = $contract->received_at->toISOString();

        $response = $this->postContract($this->payload());

        $response->assertOk()->assertJson([
            'success' => true,
            'mutated' => false,
            'items' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
            'labels' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
            'imposed_groups' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
            'catalog_apps' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
        ]);

        $this->assertSame($receivedAtBefore, $contract->fresh()->received_at->toISOString());
        $this->assertSame('1.0', $contract->fresh()->schema_version);
        $this->assertDatabaseCount('controlhub_contracts', 1);

        // Événement dispatché UNE SEULE fois au total, malgré les 2 POST identiques.
        Event::assertDispatchedTimes(ControlHubContractChanged::class, 1);
    }

    // ── 422 version non supportée (AC #5) ──────────────────────────────────

    #[Test]
    public function an_unsupported_schema_version_is_rejected_with_422_and_writes_nothing(): void
    {
        $response = $this->postContract($this->payload(['schema_version' => '99.0']));

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'error' => 'unsupported_schema_version',
        ]);
        $response->assertJsonStructure(['message']);

        $this->assertDatabaseCount('controlhub_contracts', 0);
        $this->assertDatabaseCount('controlhub_contract_items', 0);
        Event::assertNotDispatched(ControlHubContractChanged::class);
    }

    // ── 422 contenu hors domaine (AC #5) ────────────────────────────────────

    #[Test]
    public function an_out_of_domain_payload_is_rejected_with_422_and_writes_nothing(): void
    {
        $response = $this->postContract($this->payload([
            'items' => [
                ['type' => 'capabilities', 'key' => 'cap_x', 'value' => 'on', 'enforcement_state' => 'bogus', 'target_type' => 'instance'],
            ],
        ]));

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'error' => 'invalid_upstream_contract',
        ]);
        $response->assertJsonStructure(['message']);

        $this->assertDatabaseCount('controlhub_contracts', 0);
        $this->assertDatabaseCount('controlhub_contract_items', 0);
        Event::assertNotDispatched(ControlHubContractChanged::class);
    }

    // ── 422 corps illisible / non-contrat (review opus #1, NFR-A3) ──────────

    /**
     * Un corps AUTHENTIFIÉ sans aucune clé d'enveloppe (`{}`, tronqué, scalaire)
     * ne doit PAS être coercé en « contrat vide » (prune destructif + ré-activation
     * silencieuse du lien) : il est rejeté en 422 AVANT toute écriture ou événement.
     * Un contrat vide EXPLICITE (agrégats déclarés vides) reste, lui, légitime.
     *
     * @param  array<string, mixed>  $body
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('nonContractBodies')]
    public function an_authenticated_non_contract_body_is_rejected_with_422_and_writes_nothing(array $body): void
    {
        $response = $this->postContract($body);

        $response->assertStatus(422)->assertJson([
            'success' => false,
            'error' => 'invalid_upstream_contract',
        ]);
        $response->assertJsonStructure(['message']);

        $this->assertDatabaseCount('controlhub_contracts', 0);
        $this->assertDatabaseCount('controlhub_contract_items', 0);
        Event::assertNotDispatched(ControlHubContractChanged::class);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function nonContractBodies(): array
    {
        return [
            'objet vide {}' => [[]],
            'clés hors enveloppe' => [['foo' => 'bar', 'link_state' => 'active']],
        ];
    }

    /**
     * Contre-épreuve : un contrat vide EXPLICITE (au moins une clé d'enveloppe,
     * agrégats vides) reste accepté — purge légitime post-release, pas un rejet.
     */
    #[Test]
    public function an_explicitly_empty_contract_is_accepted(): void
    {
        $response = $this->postContract([
            'schema_version' => '1.0',
            'items' => [],
            'labels' => [],
            'imposed_groups' => [],
            'catalog_apps' => [],
        ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseCount('controlhub_contracts', 1);
    }

    // ── 403 auth (AC #6) ────────────────────────────────────────────────────

    #[Test]
    public function a_request_without_an_authorization_header_is_forbidden(): void
    {
        $response = $this->postContract($this->payload(), bearer: null);

        $response->assertForbidden();
        $this->assertDatabaseCount('controlhub_contracts', 0);
    }

    #[Test]
    public function a_request_with_an_invalid_bearer_token_is_forbidden(): void
    {
        $response = $this->postContract($this->payload(), bearer: 'not-the-instance-key-999999');

        $response->assertForbidden();
        $this->assertDatabaseCount('controlhub_contracts', 0);
    }
}
