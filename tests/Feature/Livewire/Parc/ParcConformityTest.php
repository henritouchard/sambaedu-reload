<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Parc;

use App\Enums\AgentResourceStatus;
use App\Models\AgentResourceState;
use App\Models\Workstation;
use App\Services\Agent\Reporting\ReportIngestService;
use App\Services\Agent\Enrollment\TokenRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Traits\MocksAdminUser;

/**
 * Tests Feature de la page parc (onglet machines) — Story 24.7 (AC1, AC4).
 *
 * Compteurs de conformité (stats-cards), badge worst-status par poste (1
 * requête agrégée), filtre `conformityFilter` (#[Url], reset), retour auto à
 * compliant. Utilise `RefreshDatabase` (schéma réel + migration 24.7) car la
 * page parc s'appuie sur le repository complet (whereHas groups, etc.).
 */
class ParcConformityTest extends TestCase
{
    use RefreshDatabase;
    use MocksAdminUser;

    private TokenRotationService $tokens;

    protected function setUp(): void
    {
        parent::setUp();

        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->withoutVite();
        $this->tokens = app(TokenRotationService::class);
        config(['agent.ttl_seconds' => 3600]);

        // Page parc scope par user — un admin global voit tout.
        $this->actAsAdmin();
        Gate::before(fn ($user, string $ability) => true);
    }

    private function enrolled(string $name): Workstation
    {
        $ws = Workstation::factory()->create(['name' => $name, 'status' => 'active']);
        $this->tokens->issueFor($ws);
        $ws->agent_last_checkin_at = now();
        $ws->save();

        return $ws->refresh();
    }

    private function seedState(Workstation $ws, string $type, AgentResourceStatus $status): void
    {
        AgentResourceState::create([
            'workstation_id' => $ws->id,
            'type' => $type,
            'status' => $status,
            'hash' => str_repeat('b', 64),
            'reported_at' => now(),
        ]);
    }

    public function test_conformity_counters_are_computed_on_enrolled_perimeter(): void
    {
        // AC1 — compteurs : 1 en écart, 1 dérive tolérée, 1 conforme.
        $exc = $this->enrolled('pc-exc');
        $this->seedState($exc, 'wallpaper', AgentResourceStatus::Drift);
        $allowed = $this->enrolled('pc-allowed');
        $this->seedState($allowed, 'wallpaper', AgentResourceStatus::DriftedAllowed);
        $ok = $this->enrolled('pc-ok');
        $this->seedState($ok, 'wallpaper', AgentResourceStatus::Compliant);

        $component = Livewire::test('pages::parc.index', ['tab' => 'machines'])
            ->call('loadStats');

        $stats = $component->get('conformityStats');
        $this->assertSame(3, $stats['enrolled']);
        $this->assertSame(1, $stats['exceptions']);
        $this->assertSame(1, $stats['drifted_allowed']);
        $this->assertSame(1, $stats['compliant']);
    }

    public function test_machine_conformity_badge_map_uses_worst_status(): void
    {
        // AC1 — badge worst-status par poste (1 requête agrégée).
        $ws = $this->enrolled('pc-worst');
        $this->seedState($ws, 'wallpaper', AgentResourceStatus::Compliant);
        $this->seedState($ws, 'overlay', AgentResourceStatus::Error);

        $component = Livewire::test('pages::parc.index', ['tab' => 'machines']);

        $map = $component->get('machineConformity');
        $this->assertSame(AgentResourceStatus::Error->value, $map[$ws->id] ?? null);
    }

    public function test_conformity_filter_isolates_exceptions(): void
    {
        // AC1 — le filtre conformityFilter='exceptions' ne montre que les
        // postes en écart.
        $exc = $this->enrolled('pc-drift');
        $this->seedState($exc, 'wallpaper', AgentResourceStatus::Drift);
        $ok = $this->enrolled('pc-clean');
        $this->seedState($ok, 'wallpaper', AgentResourceStatus::Compliant);

        $component = Livewire::test('pages::parc.index', ['tab' => 'machines'])
            ->set('conformityFilter', 'exceptions');

        $ids = $component->get('machines')->pluck('id')->all();
        $this->assertContains($exc->id, $ids);
        $this->assertNotContains($ok->id, $ids);
    }

    public function test_silent_workstation_with_drift_stays_out_of_exceptions_filter(): void
    {
        // Review 24.7 #2 — le « muet » prime (décision n° 7) : un poste muet
        // avec un drift rapporté sort dans le filtre `silent`, PAS dans
        // `exceptions` — même sémantique que badge et compteurs.
        $silent = $this->enrolled('pc-silent-drift');
        $this->seedState($silent, 'wallpaper', AgentResourceStatus::Drift);
        $silent->agent_last_checkin_at = now()->subSeconds(3 * 3600); // > 2×ttl
        $silent->save();

        $talking = $this->enrolled('pc-talking-drift');
        $this->seedState($talking, 'wallpaper', AgentResourceStatus::Drift);

        $exceptions = Livewire::test('pages::parc.index', ['tab' => 'machines'])
            ->set('conformityFilter', 'exceptions')
            ->get('machines')->pluck('id')->all();
        $this->assertNotContains($silent->id, $exceptions);
        $this->assertContains($talking->id, $exceptions);

        $silents = Livewire::test('pages::parc.index', ['tab' => 'machines'])
            ->set('conformityFilter', 'silent')
            ->get('machines')->pluck('id')->all();
        $this->assertContains($silent->id, $silents);

        // Cohérence compteurs : le muet n'est compté qu'en `silent`.
        $stats = Livewire::test('pages::parc.index', ['tab' => 'machines'])
            ->call('loadStats')
            ->get('conformityStats');
        $this->assertSame(1, $stats['exceptions']);
        $this->assertSame(1, $stats['silent']);
    }

    public function test_reset_filters_clears_conformity_filter(): void
    {
        Livewire::test('pages::parc.index', ['tab' => 'machines'])
            ->set('conformityFilter', 'silent')
            ->call('resetMachineFilters')
            ->assertSet('conformityFilter', '');
    }

    public function test_drift_returns_to_compliant_on_reingest(): void
    {
        // AC4 — deux ingestions successives (drift puis compliant) via le
        // ReportIngestService réel → le filtre exceptions cesse de retourner
        // le poste.
        $ws = $this->enrolled('pc-converge');
        $ingest = app(ReportIngestService::class);
        $report = fn (string $status, string $hash) => [
            'items' => [['type' => 'wallpaper', 'status' => $status, 'hash' => $hash]],
        ];

        $ingest->ingest($ws, $report('drift', str_repeat('d', 64)));
        $exceptions = Livewire::test('pages::parc.index', ['tab' => 'machines'])
            ->set('conformityFilter', 'exceptions')
            ->get('machines')->pluck('id')->all();
        $this->assertContains($ws->id, $exceptions);

        $ingest->ingest($ws, $report('compliant', str_repeat('e', 64)));
        $exceptions = Livewire::test('pages::parc.index', ['tab' => 'machines'])
            ->set('conformityFilter', 'exceptions')
            ->get('machines')->pluck('id')->all();
        $this->assertNotContains($ws->id, $exceptions);
    }
}
