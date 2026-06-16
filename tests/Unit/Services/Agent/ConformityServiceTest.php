<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\AgentResourceStatus;
use App\Models\AgentReportEvent;
use App\Models\AgentResourceState;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\Agent\Enrollment\TokenRotationService;
use App\Services\Agent\Reporting\ConformityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `ConformityService` — Story 24.7 (AC1, AC2, AC3).
 *
 * worst-status (précédence error > drift > compliant — Story 27.8 :
 * `drifted_allowed` retiré), compteurs par statut + dérivés (jamais rapporté /
 * muet), exceptions datées groupées par type, périmètre = postes ENRÔLÉS.
 * Lecture pure (aucun HTTP).
 */
class ConformityServiceTest extends TestCase
{
    use RefreshDatabase;

    private ConformityService $service;

    private TokenRotationService $tokens;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ConformityService();
        $this->tokens = app(TokenRotationService::class);
        config(['agent.ttl_seconds' => 3600]);

        // Neutralise WorkstationGroupObserver → WorkstationGroupAdSyncJob
        // (tente un LDAP réel sur groups()->attach()). Pattern GroupShowPageTest.
        Queue::fake();
    }

    private function enrolled(): Workstation
    {
        $ws = Workstation::factory()->create();
        $this->tokens->issueFor($ws);
        // Check-in frais par défaut (non muet).
        $ws->agent_last_checkin_at = now();
        $ws->save();

        return $ws->refresh();
    }

    private function state(Workstation $ws, string $type, AgentResourceStatus $status): AgentResourceState
    {
        return AgentResourceState::create([
            'workstation_id' => $ws->id,
            'type' => $type,
            'status' => $status,
            'hash' => str_repeat('a', 64),
            'detail' => $status === AgentResourceStatus::Error ? 'boom' : null,
            'reported_at' => now(),
        ]);
    }

    // ── worst-status ────────────────────────────────────────────────────

    #[Test]
    public function worst_status_applies_precedence_error_over_drift_over_compliant(): void
    {
        $ws = $this->enrolled();
        $this->state($ws, 'wallpaper', AgentResourceStatus::Drift);
        $this->state($ws, 'overlay', AgentResourceStatus::Error);
        $this->state($ws, 'shortcuts', AgentResourceStatus::Compliant);

        $worst = $this->service->worstStatusFor([$ws->id]);

        self::assertSame(AgentResourceStatus::Error->value, $worst[$ws->id]);
    }

    #[Test]
    public function worst_status_omits_workstation_without_any_state(): void
    {
        $ws = $this->enrolled();

        $worst = $this->service->worstStatusFor([$ws->id]);

        self::assertArrayNotHasKey($ws->id, $worst);
    }

    // ── summary counters + dérivés ──────────────────────────────────────

    #[Test]
    public function summary_counts_each_category_on_enrolled_perimeter(): void
    {
        // 1 exception (drift), 1 conforme, 1 jamais rapporté, 1 muet, 1 NON
        // enrôlé (hors périmètre). Story 27.8 : plus de catégorie « dérive tolérée ».
        $exc = $this->enrolled();
        $this->state($exc, 'wallpaper', AgentResourceStatus::Drift);

        $ok = $this->enrolled();
        $this->state($ok, 'wallpaper', AgentResourceStatus::Compliant);

        $never = $this->enrolled(); // aucune ligne d'état

        $silent = $this->enrolled();
        $this->state($silent, 'wallpaper', AgentResourceStatus::Compliant);
        $silent->agent_last_checkin_at = now()->subSeconds(2 * 3600 + 10);
        $silent->save();

        Workstation::factory()->create(); // non enrôlé

        $summary = $this->service->summary();

        self::assertSame(4, $summary['enrolled']);
        self::assertSame(1, $summary['exceptions']);
        self::assertSame(1, $summary['compliant']);
        self::assertSame(1, $summary['never_reported']);
        self::assertSame(1, $summary['silent']);
    }

    #[Test]
    public function summary_excludes_error_and_drift_from_compliant_and_counts_them_as_exceptions(): void
    {
        $err = $this->enrolled();
        $this->state($err, 'wallpaper', AgentResourceStatus::Error);

        $summary = $this->service->summary();

        self::assertSame(1, $summary['exceptions']);
        self::assertSame(0, $summary['compliant']);
    }

    // ── périmètre groupe ────────────────────────────────────────────────

    #[Test]
    public function summary_scopes_to_group_members(): void
    {
        $group = WorkstationGroup::factory()->create();
        $member = $this->enrolled();
        $member->groups()->attach($group->id);
        $this->state($member, 'wallpaper', AgentResourceStatus::Drift);

        $outsider = $this->enrolled();
        $this->state($outsider, 'wallpaper', AgentResourceStatus::Error);

        $summary = $this->service->summary($group);

        self::assertSame(1, $summary['enrolled']);
        self::assertSame(1, $summary['exceptions']);
    }

    // ── exceptionsFor : règles → exceptions seules, datées ──────────────

    #[Test]
    public function exceptions_for_lists_only_non_compliant_per_reported_type(): void
    {
        $group = WorkstationGroup::factory()->create();

        $a = $this->enrolled();
        $a->groups()->attach($group->id);
        $this->state($a, 'wallpaper', AgentResourceStatus::Compliant);

        $b = $this->enrolled();
        $b->groups()->attach($group->id);
        $this->state($b, 'wallpaper', AgentResourceStatus::Error);

        $byType = $this->service->exceptionsFor($group);

        // Un seul type rapporté (wallpaper).
        self::assertCount(1, $byType);
        $block = $byType[0];
        self::assertSame('wallpaper', $block['type']);
        self::assertSame(2, $block['total']);
        self::assertSame(1, $block['compliant']);
        // Seul le poste B (error) est listé — A (conforme) n'apparaît pas.
        self::assertCount(1, $block['exceptions']);
        self::assertSame($b->id, $block['exceptions'][0]['workstation_id']);
        self::assertSame(AgentResourceStatus::Error->value, $block['exceptions'][0]['status']);
        self::assertNotNull($block['exceptions'][0]['reported_at']);
    }

    #[Test]
    public function exceptions_for_marks_never_reported_workstation_per_type(): void
    {
        $group = WorkstationGroup::factory()->create();

        $reported = $this->enrolled();
        $reported->groups()->attach($group->id);
        $this->state($reported, 'wallpaper', AgentResourceStatus::Compliant);

        $never = $this->enrolled();
        $never->groups()->attach($group->id); // aucune ligne d'état

        $byType = $this->service->exceptionsFor($group);

        $block = $byType[0];
        $statuses = array_column($block['exceptions'], 'status', 'workstation_id');
        self::assertSame(ConformityService::DERIVED_NEVER_REPORTED, $statuses[$never->id]);
    }

    #[Test]
    public function exceptions_for_marks_silent_workstation(): void
    {
        $group = WorkstationGroup::factory()->create();

        $silent = $this->enrolled();
        $silent->groups()->attach($group->id);
        $this->state($silent, 'wallpaper', AgentResourceStatus::Compliant);
        $silent->agent_last_checkin_at = now()->subSeconds(2 * 3600 + 10);
        $silent->save();

        $byType = $this->service->exceptionsFor($group);

        $block = $byType[0];
        self::assertCount(1, $block['exceptions']);
        self::assertSame(ConformityService::DERIVED_SILENT, $block['exceptions'][0]['status']);
    }

    // ── statesFor / recentEventsFor ─────────────────────────────────────

    #[Test]
    public function states_for_returns_current_states_ordered_by_type(): void
    {
        $ws = $this->enrolled();
        $this->state($ws, 'wallpaper', AgentResourceStatus::Compliant);
        $this->state($ws, 'overlay', AgentResourceStatus::Drift);

        $states = $this->service->statesFor($ws);

        self::assertCount(2, $states);
        self::assertSame('overlay', $states->first()->type); // tri asc
    }

    #[Test]
    public function recent_events_for_returns_most_recent_first_limited(): void
    {
        $ws = $this->enrolled();

        AgentReportEvent::create([
            'workstation_id' => $ws->id,
            'type' => 'wallpaper',
            'previous_status' => null,
            'status' => AgentResourceStatus::Drift,
            'hash' => str_repeat('a', 64),
            'created_at' => Carbon::now()->subMinutes(5),
        ]);
        AgentReportEvent::create([
            'workstation_id' => $ws->id,
            'type' => 'wallpaper',
            'previous_status' => AgentResourceStatus::Drift,
            'status' => AgentResourceStatus::Compliant,
            'hash' => str_repeat('b', 64),
            'created_at' => Carbon::now(),
        ]);

        $events = $this->service->recentEventsFor($ws, 10);

        self::assertCount(2, $events);
        self::assertSame(AgentResourceStatus::Compliant, $events->first()->status); // plus récent d'abord
    }
}
