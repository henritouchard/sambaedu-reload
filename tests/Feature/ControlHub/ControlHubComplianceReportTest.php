<?php

declare(strict_types=1);

namespace Tests\Feature\ControlHub;

use App\Enums\ControlHubContractTarget;
use App\Enums\ControlHubEnforcementState;
use App\Jobs\ControlHubReportComplianceJob;
use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\ControlHubConnection;
use App\Models\ControlHubContract;
use App\Models\ControlHubContractItem;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\ControlHub\ControlHubApiClient;
use App\Services\ControlHub\ControlHubComplianceReportService;
use App\Services\ControlHub\ControlHubService;
use App\Services\ControlHub\Data\ApiResponse;
use App\Services\ControlHub\UpstreamLockResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 39.2 (canal ③) — Émetteur de conformité `se5-contract-compliance/v1`.
 *
 * Tests HÔTE (php8.4 + pdo_sqlite), `RefreshDatabase`. On teste des VALEURS
 * résolues (enveloppe, mapping de statut, gardes NFR-A1), jamais des bornes de
 * colonne (SQLite n'applique pas varchar/enum PG).
 */
class ControlHubComplianceReportTest extends TestCase
{
    use RefreshDatabase;

    private const INSTANCE_ID = 'test-instance-9d3f5c2a';

    protected function setUp(): void
    {
        parent::setUp();

        WorkstationGroupObserver::disableSync();

        // Catalogue de capacités VIDE : le lot iso seedé par migration brouillerait
        // la détection d'override (clés/assignations parasites).
        DB::table('capability_assignments')->delete();
        DB::table('capability_projections')->delete();
        DB::table('capabilities')->delete();

        config(['controlHub.se4fs.instance_id' => self::INSTANCE_ID]);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        parent::tearDown();
    }

    // ── AC 1 — enveloppe conforme ─────────────────────────────────────────────

    #[Test]
    public function envelope_is_schema_conformant_with_all_top_level_and_item_keys(): void
    {
        $contract = ControlHubContract::factory()->create(['received_at' => now()]);
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKLM|Software\\Test|Foo|REG_DWORD',
            'enforcement_state' => ControlHubEnforcementState::Locked,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'applications',
            'key' => 'firefox',
            'enforcement_state' => ControlHubEnforcementState::Permissive,
            'target_type' => ControlHubContractTarget::Label,
            'target_label' => 'compta',
        ]);

        $envelope = $this->service()->buildEnvelope();

        self::assertIsArray($envelope);
        self::assertSame('1.0', $envelope['schema_version']);
        self::assertSame(self::INSTANCE_ID, $envelope['instance_id']);
        self::assertSame('active', $envelope['link_state']);
        self::assertIsString($envelope['contract_received_at']);
        self::assertIsString($envelope['reported_at']);
        self::assertCount(2, $envelope['items']);

        foreach ($envelope['items'] as $item) {
            self::assertSame(
                ['type', 'key', 'target_type', 'target_label', 'status', 'detail', 'observed_at'],
                array_keys($item),
            );
            self::assertIsString($item['type']);
            self::assertIsString($item['key']);
            self::assertContains($item['target_type'], ['instance', 'label']);
            self::assertIsString($item['target_label'], 'target_label est TOUJOURS une chaîne (jamais null)');
            self::assertContains($item['status'], ['applied', 'pending', 'error', 'overridden']);
            self::assertIsString($item['observed_at']);
        }
    }

    // ── AC 2 — gardes NFR-A1 : aucune émission parasite ───────────────────────

    #[Test]
    public function build_envelope_returns_null_without_active_contract(): void
    {
        // Aucun contrat (RefreshDatabase) → null.
        self::assertNull($this->service()->buildEnvelope());

        // Lien severed → active() renvoie null aussi.
        ControlHubContract::factory()->severed()->create();
        self::assertNull($this->service()->buildEnvelope());
    }

    #[Test]
    public function emit_does_not_call_api_client_without_active_contract(): void
    {
        $apiClient = Mockery::mock(ControlHubApiClient::class);
        $apiClient->shouldNotReceive('callEndpoint');

        $result = $this->service($apiClient)->emit();

        self::assertSame(['sent' => false, 'reason' => 'no_active_contract'], $result);
    }

    #[Test]
    public function emit_does_not_call_api_client_without_valid_connection(): void
    {
        // Contrat actif mais AUCUNE connexion (current() null).
        ControlHubContract::factory()->create();

        $apiClient = Mockery::mock(ControlHubApiClient::class);
        $apiClient->shouldNotReceive('callEndpoint');

        $result = $this->service($apiClient)->emit();

        self::assertFalse($result['sent']);
        self::assertSame('no_active_connection', $result['reason']);
    }

    #[Test]
    public function emit_does_not_call_api_client_without_token(): void
    {
        // 3ᵉ garde NFR-A1 (AC2, review 39.2 #2) : contrat actif + connexion valide,
        // mais token amont vide → aucune émission réseau.
        ControlHubContract::factory()->create();
        $this->validConnection();

        $controlHubService = Mockery::mock(ControlHubService::class);
        $controlHubService->shouldReceive('getToken')->andReturn('');

        $apiClient = Mockery::mock(ControlHubApiClient::class);
        $apiClient->shouldNotReceive('callEndpoint');

        $result = $this->service($apiClient, $controlHubService)->emit();

        self::assertFalse($result['sent']);
        self::assertSame('no_token', $result['reason']);
    }

    // ── AC 3 — items:[] valide + filtrage absent ──────────────────────────────

    #[Test]
    public function empty_items_is_a_valid_report_and_is_emitted(): void
    {
        // Contrat actif SANS item non-absent : items:[] est un résultat valide.
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->absent()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
        ]);
        $this->validConnection();

        $apiClient = Mockery::mock(ControlHubApiClient::class);
        $apiClient->shouldReceive('setBaseUrl')->andReturnNull();
        $apiClient->shouldReceive('callEndpoint')
            ->once()
            ->with(Mockery::type('string'), 'POST', Mockery::on(function (array $envelope): bool {
                return $envelope['items'] === [];
            }), Mockery::type('string'))
            ->andReturn(ApiResponse::success(['created' => 0], 200));

        $result = $this->service($apiClient)->emit();

        self::assertTrue($result['sent']);
        self::assertSame(0, $result['items']);
    }

    #[Test]
    public function absent_items_are_excluded_from_the_report(): void
    {
        $contract = ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'registry',
            'key' => 'HKLM|A|B|REG_DWORD',
            'enforcement_state' => ControlHubEnforcementState::Locked,
        ]);
        ControlHubContractItem::factory()->absent()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => 'wallpapers',
            'key' => 'fond-absent',
        ]);

        $envelope = $this->service()->buildEnvelope();

        self::assertCount(1, $envelope['items']);
        self::assertSame('HKLM|A|B|REG_DWORD', $envelope['items'][0]['key']);
    }

    // ── AC 4 — mapping de statut ──────────────────────────────────────────────

    #[Test]
    public function locked_item_maps_to_applied(): void
    {
        $status = $this->statusFor($this->item(ControlHubEnforcementState::Locked, 'registry'));

        self::assertSame('applied', $status['status']);
        self::assertNull($status['detail']);
    }

    #[Test]
    public function permissive_registry_instance_without_override_maps_to_applied(): void
    {
        // Une capacité registre matche la clé MAIS aucun override posé.
        $this->registryCapability('HKCU', 'Software\\X', 'Foo');

        $status = $this->statusFor(
            $this->item(ControlHubEnforcementState::Permissive, 'registry', 'HKCU|Software\\X|Foo|REG_DWORD'),
        );

        self::assertSame('applied', $status['status'], 'permissif sans override → applied (pas de faux overridden)');
        self::assertNull($status['detail']);
    }

    #[Test]
    public function permissive_registry_instance_with_override_maps_to_overridden(): void
    {
        $cap = $this->registryCapability('HKCU', 'Software\\Explorer', 'Hidden', 'Afficher fichiers cachés');
        $group = WorkstationGroup::factory()->logical()->create(['display_name' => 'Salle B']);
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $group->id,
            'value' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $status = $this->statusFor(
            // Casse/segment `type` distincts : la normalisation doit matcher quand même.
            $this->item(ControlHubEnforcementState::Permissive, 'registry', 'hkcu|software\\explorer|hidden|REG_DWORD'),
        );

        self::assertSame('overridden', $status['status']);
        self::assertNotEmpty($status['detail']);
        self::assertStringContainsString('Afficher fichiers cachés', $status['detail']);
        self::assertStringContainsString('Salle B', $status['detail']);
    }

    #[Test]
    public function permissive_non_registry_type_maps_to_applied_without_false_overridden(): void
    {
        // Type sans mécanisme d'override câblé (wallpapers) : jamais overridden.
        $status = $this->statusFor(
            $this->item(ControlHubEnforcementState::Permissive, 'wallpapers', 'fond-college'),
        );

        self::assertSame('applied', $status['status']);
        self::assertNull($status['detail']);
    }

    // ── AC 4 — reported_at monotone (NFR-A2) ──────────────────────────────────

    #[Test]
    public function reported_at_is_monotonic_between_successive_reports(): void
    {
        ControlHubContract::factory()->create();

        Carbon::setTestNow('2026-07-04T10:00:00Z');
        $first = $this->service()->buildEnvelope()['reported_at'];

        Carbon::setTestNow('2026-07-04T10:05:00Z');
        $second = $this->service()->buildEnvelope()['reported_at'];

        self::assertTrue(
            Carbon::parse($second)->greaterThan(Carbon::parse($first)),
            'reported_at doit croître entre deux rapports successifs',
        );

        Carbon::setTestNow();
    }

    // ── AC 5 — émission authentifiée sans fuite de token ──────────────────────

    #[Test]
    public function emit_posts_envelope_with_bearer_token_and_never_logs_it(): void
    {
        $secretToken = 'tok_super_secret_DEADBEEF';
        ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => ControlHubContract::active()->id,
            'type' => 'registry',
            'key' => 'HKLM|A|B|REG_DWORD',
            'enforcement_state' => ControlHubEnforcementState::Locked,
        ]);
        $this->validConnection($secretToken);

        Log::spy();

        $apiClient = Mockery::mock(ControlHubApiClient::class);
        $apiClient->shouldReceive('setBaseUrl')->andReturnNull();
        $apiClient->shouldReceive('callEndpoint')
            ->once()
            ->with(
                Mockery::pattern('#/api/sambaedu/contract-compliance/' . self::INSTANCE_ID . '$#'),
                'POST',
                Mockery::type('array'),
                $secretToken,
            )
            ->andReturn(ApiResponse::success(['created' => 1], 200));

        $result = $this->service($apiClient)->emit();

        self::assertTrue($result['sent']);
        self::assertSame(1, $result['items']);

        // L'émission a bien loggé (la non-fuite du token est couverte par le test dédié).
        Log::shouldHaveReceived('info')->atLeast()->once();
    }

    #[Test]
    public function token_never_appears_in_logs(): void
    {
        $secretToken = 'tok_leak_canary_0BADF00D';
        ControlHubContract::factory()->create();
        $this->validConnection($secretToken);

        $captured = [];
        Log::listen(function ($message) use (&$captured): void {
            $captured[] = json_encode([$message->message, $message->context]);
        });

        $apiClient = Mockery::mock(ControlHubApiClient::class);
        $apiClient->shouldReceive('setBaseUrl')->andReturnNull();
        $apiClient->shouldReceive('callEndpoint')->andReturn(ApiResponse::success([], 200));

        $this->service($apiClient)->emit();

        foreach ($captured as $line) {
            self::assertStringNotContainsString($secretToken, (string) $line, 'le token ne doit JAMAIS apparaître dans un log');
        }
    }

    // ── AC 7 — command dispatch conditionnel ──────────────────────────────────

    #[Test]
    public function command_dispatches_job_when_contract_and_connection_are_valid(): void
    {
        Queue::fake();
        ControlHubContract::factory()->create();
        $this->validConnection();

        $this->artisan('controlhub:report-compliance')->assertExitCode(0);

        Queue::assertPushed(ControlHubReportComplianceJob::class);
    }

    #[Test]
    public function command_does_not_dispatch_job_without_active_contract(): void
    {
        Queue::fake();
        $this->validConnection();

        $this->artisan('controlhub:report-compliance')->assertExitCode(0);

        Queue::assertNotPushed(ControlHubReportComplianceJob::class);
    }

    #[Test]
    public function command_does_not_dispatch_job_without_valid_connection(): void
    {
        Queue::fake();
        ControlHubContract::factory()->create();

        $this->artisan('controlhub:report-compliance')->assertExitCode(0);

        Queue::assertNotPushed(ControlHubReportComplianceJob::class);
    }

    // ── Review #1 — le job RELÈVE sur échec HTTP (retry AC7 réellement armé) ──

    #[Test]
    public function job_throws_on_http_error_so_laravel_retry_engages(): void
    {
        ControlHubContract::factory()->create();
        ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => ControlHubContract::active()->id,
            'type' => 'registry',
            'key' => 'HKLM|A|B|REG_DWORD',
            'enforcement_state' => ControlHubEnforcementState::Locked,
        ]);
        $this->validConnection();

        $apiClient = Mockery::mock(ControlHubApiClient::class);
        $apiClient->shouldReceive('setBaseUrl')->andReturnNull();
        $apiClient->shouldReceive('callEndpoint')->andReturn(ApiResponse::failed('upstream 503', 503));
        $this->app->instance(ControlHubApiClient::class, $apiClient);

        // `emit()` renvoie reason='http_error' → le job doit RELEVER pour armer $tries
        // (sans relever, Laravel considérerait le job réussi : retry mort).
        $this->expectException(\RuntimeException::class);

        (new ControlHubReportComplianceJob())->handle(app(ControlHubComplianceReportService::class));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function service(
        ?ControlHubApiClient $apiClient = null,
        ?ControlHubService $controlHubService = null,
    ): ControlHubComplianceReportService {
        return new ControlHubComplianceReportService(
            $apiClient ?? Mockery::mock(ControlHubApiClient::class),
            $controlHubService ?? app(ControlHubService::class),
            app(UpstreamLockResolver::class),
        );
    }

    private function validConnection(string $token = 'tok_default_secret'): ControlHubConnection
    {
        return ControlHubConnection::create([
            'base_url' => 'https://amont.example',
            'api_token' => $token,
            'se4fs_api_token' => 'se4fs_token_local',
            'heartbeat_interval' => 300,
            'is_active' => true,
            'last_handshake_at' => now(),
            'expires_at' => now()->addDay(),
            'status' => 'online',
        ]);
    }

    private function registryCapability(
        string $hive,
        string $path,
        string $name,
        string $label = 'Capacité de test',
    ): Capability {
        $cap = Capability::factory()->create(['label' => $label]);
        CapabilityProjection::factory()->for($cap)->keys([
            ['hive' => $hive, 'path' => $path, 'name' => $name, 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
        ])->create();

        return $cap;
    }

    private function item(ControlHubEnforcementState $state, string $type, string $key = 'k'): ControlHubContractItem
    {
        $contract = ControlHubContract::active() ?? ControlHubContract::factory()->create();

        return ControlHubContractItem::factory()->create([
            'controlhub_contract_id' => $contract->id,
            'type' => $type,
            'key' => $key,
            'enforcement_state' => $state,
            'target_type' => ControlHubContractTarget::Instance,
            'target_label' => '',
        ]);
    }

    /**
     * Résout le statut d'un unique item via l'enveloppe construite.
     *
     * @return array{status:string,detail:?string}
     */
    private function statusFor(ControlHubContractItem $item): array
    {
        $envelope = $this->service()->buildEnvelope();
        $mapped = collect($envelope['items'])->firstWhere('key', $item->key);

        self::assertNotNull($mapped, 'item introuvable dans le rapport');

        return ['status' => $mapped['status'], 'detail' => $mapped['detail']];
    }
}
