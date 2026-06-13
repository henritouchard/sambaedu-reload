<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Agent;

use App\Models\AgentResourceState;
use App\Models\Workstation;
use App\Services\Agent\Enrollment\TokenRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 25.5 — greffe de persistance de la version rapportée (AC4).
 *
 * `agent_version` était validé puis SILENCIEUSEMENT jeté : la greffe l'écrit
 * désormais dans `workstations.agent_reported_version` (+ `_at`) au fil du
 * report, dans `ReportController::store()` (hors transaction D3,
 * `ReportIngestService` toujours read-only sur `workstations`). Le contrat de
 * report (golden) est inchangé : la colonne ne modifie pas le payload.
 */
final class ReportedVersionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const REPORT_ROUTE = '/api/v1/agent/report';

    private TokenRotationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TokenRotationService::class);
    }

    /** @param array<string, mixed> $payload */
    private function report(string $token, array $payload): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson(self::REPORT_ROUTE, $payload);
    }

    /** @return array{0: Workstation, 1: string} */
    private function enrolledWorkstation(): array
    {
        $ws = Workstation::factory()->create();
        $token = $this->service->issueFor($ws);

        return [$ws->refresh(), $token];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function payload(Workstation $ws, string $version = '2.1.2', array $items = []): array
    {
        return [
            'schema' => 'se5.desired-state/v1',
            'generated_at' => now()->toIso8601String(),
            'agent_version' => $version,
            'workstation' => ['hostname' => $ws->name, 'uuid' => $ws->uuid],
            'items' => $items,
        ];
    }

    #[Test]
    public function report_persists_the_agent_reported_version_on_the_workstation(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();
        self::assertNull($ws->agent_reported_version);
        self::assertNull($ws->agent_reported_version_at);

        $this->report($token, $this->payload($ws, '2.1.2'))->assertOk();

        $ws->refresh();
        self::assertSame('2.1.2', $ws->agent_reported_version);
        self::assertNotNull($ws->agent_reported_version_at);
    }

    #[Test]
    public function reported_version_is_updated_on_each_report_and_refreshes_freshness(): void
    {
        [$ws, $token] = $this->enrolledWorkstation();

        $this->report($token, $this->payload($ws, '2.1.1'))->assertOk();
        $firstAt = $ws->refresh()->agent_reported_version_at;
        self::assertSame('2.1.1', $ws->agent_reported_version);

        $this->travel(2)->hours();
        $this->report($token, $this->payload($ws, '2.1.2'))->assertOk();

        $ws->refresh();
        self::assertSame('2.1.2', $ws->agent_reported_version);
        self::assertTrue($ws->agent_reported_version_at->gt($firstAt));
    }

    #[Test]
    public function empty_items_report_still_persists_the_version(): void
    {
        // La greffe est indépendante du stockage des items (hors transaction D3).
        [$ws, $token] = $this->enrolledWorkstation();

        $this->report($token, $this->payload($ws, '2.1.2', []))->assertOk();

        self::assertSame(0, AgentResourceState::query()->where('workstation_id', $ws->id)->count());
        self::assertSame('2.1.2', $ws->refresh()->agent_reported_version);
    }

    #[Test]
    public function reported_version_column_is_not_mass_assignable(): void
    {
        // Iso colonnes `agent_*` : hors $fillable, seule la greffe (forceFill) écrit.
        $ws = Workstation::factory()->create();
        $ws->fill(['agent_reported_version' => 'forged']);

        self::assertNull($ws->agent_reported_version);
    }

    #[Test]
    public function golden_report_payload_remains_accepted_verbatim(): void
    {
        // Non-régression contrat : le golden FIGÉ passe toujours, la colonne ne
        // change pas le payload ni la réponse.
        [$ws, $token] = $this->enrolledWorkstation();
        $golden = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/Agent/report.v1.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->report($token, $golden)->assertOk()->assertJson(['success' => true]);

        // La version du golden est persistée (greffe transparente au contrat).
        self::assertSame($golden['agent_version'], $ws->refresh()->agent_reported_version);
    }
}
