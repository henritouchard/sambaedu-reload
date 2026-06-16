<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\AgentResourceStatus;
use App\Models\AgentReportEvent;
use App\Models\AgentReportHistory;
use App\Models\AgentResourceState;
use App\Models\Workstation;
use App\Services\Agent\Reporting\ReportIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `ReportIngestService` — Story 24.1 (AC1, AC2, AC3, AC5).
 *
 * Matrice COMPLÈTE de la règle d'événement (décision n° 2), upsert borné,
 * fraîcheur `reported_at`, flag history, comptes retournés — sans HTTP
 * (le contrat HTTP vit dans `ReportEndpointTest`).
 */
class ReportIngestServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReportIngestService $service;

    private Workstation $ws;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ReportIngestService();
        $this->ws = Workstation::factory()->create();
    }

    private const HASH_A = '1111111111111111111111111111111111111111111111111111111111111111';

    private const HASH_B = '2222222222222222222222222222222222222222222222222222222222222222';

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function report(array $items): array
    {
        return [
            'schema' => 'se5.desired-state/v1',
            'generated_at' => now()->toIso8601String(),
            'agent_version' => '1.0.0',
            'workstation' => ['hostname' => $this->ws->name, 'uuid' => $this->ws->uuid],
            'items' => $items,
        ];
    }

    /** @return array<string, mixed> */
    private function item(string $status, string $hash = self::HASH_A, ?string $detail = null, string $type = 'wallpaper'): array
    {
        $item = ['type' => $type, 'status' => $status, 'hash' => $hash];
        if ($detail !== null) {
            $item['detail'] = $detail;
        }

        return $item;
    }

    private function ingest(array $items): array
    {
        return $this->service->ingest($this->ws, $this->report($items));
    }

    // ── Matrice des événements (décision n° 2) ───────────────────────────

    #[Test]
    public function first_compliant_report_creates_state_but_no_event(): void
    {
        $this->ingest([$this->item('compliant')]);

        self::assertSame(1, AgentResourceState::query()->count());
        self::assertSame(0, AgentReportEvent::query()->count(), 'un premier « tout va bien » n\'est pas un changement');
    }

    #[Test]
    public function first_drift_report_creates_event_with_null_previous_status(): void
    {
        $this->ingest([$this->item('drift')]);

        $event = AgentReportEvent::query()->sole();
        self::assertNull($event->previous_status);
        self::assertSame(AgentResourceStatus::Drift, $event->status);
        self::assertSame(self::HASH_A, $event->hash);
    }

    #[Test]
    public function first_error_and_drift_reports_create_events(): void
    {
        $this->ingest([
            $this->item('error', self::HASH_A, 'boom', 'printers'),
            $this->item('drift', self::HASH_A, null, 'shortcuts'),
        ]);

        self::assertSame(2, AgentReportEvent::query()->count());
        $error = AgentReportEvent::query()->where('type', 'printers')->sole();
        self::assertSame(AgentResourceStatus::Error, $error->status);
        self::assertSame('boom', $error->detail);
    }

    #[Test]
    public function identical_status_and_hash_creates_no_event_but_refreshes_reported_at(): void
    {
        $this->ingest([$this->item('drift')]);
        $state = AgentResourceState::query()->sole();
        $before = $state->reported_at;

        $this->travel(1)->hour();
        $this->ingest([$this->item('drift')]);

        self::assertSame(1, AgentReportEvent::query()->count(), 'identique = aucun événement');
        self::assertTrue($state->refresh()->reported_at->gt($before), 'reported_at rafraîchi même si identique');
    }

    #[Test]
    public function compliant_to_compliant_with_changed_hash_creates_no_event_but_updates_hash(): void
    {
        // La cible a bougé et l'agent a convergé silencieusement : pas une
        // dérive — le hash de la ligne d'état suffit (décision n° 2).
        $this->ingest([$this->item('compliant', self::HASH_A)]);

        $this->ingest([$this->item('compliant', self::HASH_B)]);

        self::assertSame(0, AgentReportEvent::query()->count());
        self::assertSame(self::HASH_B, AgentResourceState::query()->sole()->hash);
    }

    #[Test]
    public function drift_to_compliant_creates_corrected_event_with_previous_status(): void
    {
        $this->ingest([$this->item('drift')]);

        $this->ingest([$this->item('compliant')]);

        // Dérive CORRIGÉE = un changement (D3) — même hash, statut changé.
        self::assertSame(2, AgentReportEvent::query()->count());
        $corrected = AgentReportEvent::query()->orderByDesc('id')->first();
        self::assertSame(AgentResourceStatus::Drift, $corrected->previous_status);
        self::assertSame(AgentResourceStatus::Compliant, $corrected->status);
    }

    #[Test]
    public function compliant_to_drift_and_drift_to_error_create_events(): void
    {
        $this->ingest([$this->item('compliant')]);
        self::assertSame(0, AgentReportEvent::query()->count());

        $this->ingest([$this->item('drift')]);
        self::assertSame(1, AgentReportEvent::query()->count());

        $this->ingest([$this->item('error', self::HASH_A, 'apply KO')]);
        self::assertSame(2, AgentReportEvent::query()->count());
        $last = AgentReportEvent::query()->orderByDesc('id')->first();
        self::assertSame(AgentResourceStatus::Drift, $last->previous_status);
        self::assertSame(AgentResourceStatus::Error, $last->status);
        self::assertSame('apply KO', $last->detail);
    }

    // ── Upsert borné + détail ─────────────────────────────────────────────

    #[Test]
    public function state_is_upserted_per_workstation_and_type_never_duplicated(): void
    {
        $this->ingest([$this->item('compliant')]);
        $this->ingest([$this->item('drift', self::HASH_B)]);
        $this->ingest([$this->item('compliant', self::HASH_B)]);

        self::assertSame(1, AgentResourceState::query()->count(), 'N rapports = 1 ligne par (poste, type)');
        $state = AgentResourceState::query()->sole();
        self::assertSame(AgentResourceStatus::Compliant, $state->status);
        self::assertSame(self::HASH_B, $state->hash);
    }

    #[Test]
    public function detail_is_stored_then_cleared_when_status_recovers(): void
    {
        $this->ingest([$this->item('error', self::HASH_A, 'spooler KO', 'printers')]);
        self::assertSame('spooler KO', AgentResourceState::query()->sole()->detail);

        $this->ingest([$this->item('compliant', self::HASH_A, null, 'printers')]);

        self::assertNull(AgentResourceState::query()->sole()->detail, 'le détail d\'erreur ne survit pas à la guérison');
    }

    #[Test]
    public function two_workstations_have_independent_states_for_the_same_type(): void
    {
        $other = Workstation::factory()->create();
        $this->ingest([$this->item('compliant')]);

        $this->service->ingest($other, [
            'schema' => 'se5.desired-state/v1',
            'items' => [$this->item('drift')],
        ]);

        self::assertSame(2, AgentResourceState::query()->count());
        self::assertSame(1, AgentResourceState::query()->where('workstation_id', $other->id)->count());
    }

    // ── Comptes retournés ─────────────────────────────────────────────────

    #[Test]
    public function ingest_returns_counts_for_every_status_with_zero_defaults(): void
    {
        $counts = $this->ingest([
            $this->item('compliant', self::HASH_A, null, 'wallpaper'),
            $this->item('compliant', self::HASH_A, null, 'overlay'),
            $this->item('drift', self::HASH_A, null, 'shortcuts'),
        ]);

        self::assertSame(
            ['compliant' => 2, 'drift' => 1, 'error' => 0],
            $counts,
        );
    }

    #[Test]
    public function empty_items_returns_all_zero_counts_and_writes_nothing(): void
    {
        $counts = $this->ingest([]);

        self::assertSame(['compliant' => 0, 'drift' => 0, 'error' => 0], $counts);
        self::assertSame(0, AgentResourceState::query()->count());
        self::assertSame(0, AgentReportEvent::query()->count());
    }

    // ── Flag history (AC3) ────────────────────────────────────────────────

    #[Test]
    public function history_is_skipped_when_flag_off_and_appended_when_on(): void
    {
        $this->ingest([$this->item('compliant')]);
        self::assertSame(0, AgentReportHistory::query()->count(), 'flag off (défaut) = aucune ligne');

        config(['agent.report_history' => true]);
        $report = $this->report([$this->item('drift')]);
        $this->service->ingest($this->ws, $report);

        $history = AgentReportHistory::query()->sole();
        self::assertSame($this->ws->id, $history->workstation_id);
        // assertEquals (==) et non assertSame : jsonb Postgres réordonne les
        // clés — l'égalité de contenu suffit (review 24.1 #7).
        self::assertEquals($report, $history->payload, 'payload validé complet conservé');
    }

    #[Test]
    public function history_is_append_only_one_row_per_report(): void
    {
        config(['agent.report_history' => true]);

        $this->ingest([$this->item('compliant')]);
        $this->ingest([$this->item('compliant')]);

        self::assertSame(2, AgentReportHistory::query()->count());
    }
}
