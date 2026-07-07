<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Parc;

use App\Models\Workstation;
use App\Services\Agent\Enrollment\TokenRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Traits\MocksAdminUser;

/**
 * Tests Feature du filtre d'état de présence (allumé / éteint) de l'onglet
 * machines de la page parc.
 *
 * Le filtre `presenceFilter` reproduit en SQL la dérivation de
 * {@see \App\Models\Workstation::agentPresence()} : `online` = allumé,
 * `off` = éteint (extinction signalée ∪ muet). Utilise `RefreshDatabase`
 * car le repository s'appuie sur le schéma réel (whereHas groups).
 */
class ParcPresenceFilterTest extends TestCase
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

    public function test_online_filter_keeps_only_recently_checked_in_workstations(): void
    {
        $online = $this->enrolled('pc-online');

        $silent = $this->enrolled('pc-silent');
        $silent->agent_last_checkin_at = now()->subSeconds(3 * 3600); // > 2×ttl
        $silent->save();

        $unenrolled = Workstation::factory()->create(['name' => 'pc-no-agent', 'status' => 'active']);

        $ids = Livewire::test('pages::parc.index', ['tab' => 'machines'])
            ->set('presenceFilter', 'online')
            ->get('machines')->pluck('id')->all();

        $this->assertContains($online->id, $ids);
        $this->assertNotContains($silent->id, $ids);
        $this->assertNotContains($unenrolled->id, $ids);
    }

    public function test_reported_off_workstation_is_off_even_with_recent_checkin(): void
    {
        // Extinction signalée ≥ dernier check-in → éteint immédiat, sans
        // attendre le seuil de silence (reported_off prime).
        $off = $this->enrolled('pc-reported-off');
        $off->agent_reported_offline_at = now()->addSecond();
        $off->save();

        $onlineIds = Livewire::test('pages::parc.index', ['tab' => 'machines'])
            ->set('presenceFilter', 'online')
            ->get('machines')->pluck('id')->all();
        $this->assertNotContains($off->id, $onlineIds);

        $offIds = Livewire::test('pages::parc.index', ['tab' => 'machines'])
            ->set('presenceFilter', 'off')
            ->get('machines')->pluck('id')->all();
        $this->assertContains($off->id, $offIds);
    }

    public function test_off_filter_unions_reported_off_and_silent(): void
    {
        $online = $this->enrolled('pc-up');

        $silent = $this->enrolled('pc-mute');
        $silent->agent_last_checkin_at = now()->subSeconds(3 * 3600);
        $silent->save();

        $reportedOff = $this->enrolled('pc-shutdown');
        $reportedOff->agent_reported_offline_at = now()->addSecond();
        $reportedOff->save();

        $unenrolled = Workstation::factory()->create(['name' => 'pc-ghost', 'status' => 'active']);

        $ids = Livewire::test('pages::parc.index', ['tab' => 'machines'])
            ->set('presenceFilter', 'off')
            ->get('machines')->pluck('id')->all();

        $this->assertContains($silent->id, $ids);
        $this->assertContains($reportedOff->id, $ids);
        $this->assertNotContains($online->id, $ids);
        // Un poste sans agent est en présence `unknown`, pas `off`.
        $this->assertNotContains($unenrolled->id, $ids);
    }

    public function test_reset_filters_clears_presence_filter(): void
    {
        Livewire::test('pages::parc.index', ['tab' => 'machines'])
            ->set('presenceFilter', 'off')
            ->call('resetMachineFilters')
            ->assertSet('presenceFilter', '');
    }
}
