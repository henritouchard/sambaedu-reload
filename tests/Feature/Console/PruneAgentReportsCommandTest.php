<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\AgentReportEvent;
use App\Models\AgentReportHistory;
use App\Models\Workstation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests `agent:reports:prune` — Story 24.1 (AC3).
 *
 * Les deux rétentions de `config/agent.php` (clés 23.5 consommées, pas
 * recréées) : events > `report_events_retention_days` (14 j) et history >
 * `report_history_retention_days` (30 j). La purge est INDIFFÉRENTE au flag
 * `report_history` (nettoie aussi les résidus d'un debug terminé).
 */
class PruneAgentReportsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Workstation $ws;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ws = Workstation::factory()->create();
    }

    private function makeEvent(int $daysAgo): AgentReportEvent
    {
        return AgentReportEvent::create([
            'workstation_id' => $this->ws->id,
            'type' => 'wallpaper',
            'previous_status' => null,
            'status' => 'drift',
            'hash' => str_repeat('a', 64),
            'created_at' => now()->subDays($daysAgo),
        ]);
    }

    private function makeHistory(int $daysAgo): AgentReportHistory
    {
        return AgentReportHistory::create([
            'workstation_id' => $this->ws->id,
            'payload' => ['schema' => 'se5.desired-state/v1', 'items' => []],
            'created_at' => now()->subDays($daysAgo),
        ]);
    }

    #[Test]
    public function prunes_old_events_and_keeps_recent_ones(): void
    {
        $old = $this->makeEvent(15);
        $recent = $this->makeEvent(1);

        $this->artisan('agent:reports:prune')->assertSuccessful();

        self::assertNull(AgentReportEvent::find($old->id), 'événement > 14 j purgé');
        self::assertNotNull(AgentReportEvent::find($recent->id), 'événement récent conservé');
    }

    #[Test]
    public function prunes_old_history_and_keeps_recent_ones(): void
    {
        $old = $this->makeHistory(31);
        $recent = $this->makeHistory(20);

        $this->artisan('agent:reports:prune')->assertSuccessful();

        self::assertNull(AgentReportHistory::find($old->id), 'history > 30 j purgé');
        self::assertNotNull(AgentReportHistory::find($recent->id), 'history < 30 j conservé (rétention longue ≠ events)');
    }

    #[Test]
    public function the_two_retentions_are_independent(): void
    {
        // 20 j : au-delà de la rétention events (14), en deçà de history (30).
        $event = $this->makeEvent(20);
        $history = $this->makeHistory(20);

        $this->artisan('agent:reports:prune')->assertSuccessful();

        self::assertNull(AgentReportEvent::find($event->id));
        self::assertNotNull(AgentReportHistory::find($history->id));
    }

    #[Test]
    public function history_purge_runs_even_with_flag_off(): void
    {
        config(['agent.report_history' => false]);
        $old = $this->makeHistory(31);

        $this->artisan('agent:reports:prune')->assertSuccessful();

        self::assertNull(AgentReportHistory::find($old->id), 'résidus d\'un debug terminé nettoyés flag off');
    }

    #[Test]
    public function custom_retentions_from_config_are_honored(): void
    {
        config(['agent.report_events_retention_days' => 2, 'agent.report_history_retention_days' => 5]);
        $event = $this->makeEvent(3);
        $history = $this->makeHistory(6);
        $keptEvent = $this->makeEvent(1);
        $keptHistory = $this->makeHistory(4);

        $this->artisan('agent:reports:prune')->assertSuccessful();

        self::assertNull(AgentReportEvent::find($event->id));
        self::assertNull(AgentReportHistory::find($history->id));
        self::assertNotNull(AgentReportEvent::find($keptEvent->id));
        self::assertNotNull(AgentReportHistory::find($keptHistory->id));
    }
}
