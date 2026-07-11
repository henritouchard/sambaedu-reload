<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Parc;

use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\Agent\Enrollment\TokenRotationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Traits\MocksAdminUser;

/**
 * Cartes-filtres cliquables de l'onglet Postes.
 *
 * Chaque tuile de statistique déclenche `filterByCard($card)` qui bascule le
 * filtre rapide `cardFilter` (mutuellement exclusif — « montre uniquement ce
 * type »). On couvre ici le toggle et les catégories non encore testées
 * ailleurs (active, enrolled, without_group, silent ⊇ never_reported).
 * `ParcConformityTest` couvre déjà compliant/exceptions/silent.
 */
class ParcCardFilterTest extends TestCase
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

    private function enrolled(string $name, ?\Carbon\CarbonInterface $checkin = null): Workstation
    {
        $ws = Workstation::factory()->create(['name' => $name, 'status' => 'active']);
        $this->tokens->issueFor($ws);
        $ws->agent_last_checkin_at = $checkin ?? now();
        $ws->save();

        return $ws->refresh();
    }

    public function test_filter_by_card_toggles_and_clears_on_invalid_key(): void
    {
        $component = Livewire::test('pages::parc.index', ['tab' => 'machines'])
            ->call('filterByCard', 'enrolled')
            ->assertSet('cardFilter', 'enrolled')
            // Re-clic sur la carte active → désélection (retour à « tous »).
            ->call('filterByCard', 'enrolled')
            ->assertSet('cardFilter', '')
            // Clé hors allow-list → jamais appliquée.
            ->call('filterByCard', 'bogus')
            ->assertSet('cardFilter', '');

        $this->assertNotNull($component);
    }

    public function test_card_active_isolates_active_status(): void
    {
        $active = Workstation::factory()->create(['name' => 'pc-on', 'status' => 'active']);
        $inactive = Workstation::factory()->create(['name' => 'pc-off', 'status' => 'inactive']);

        $ids = Livewire::test('pages::parc.index', ['tab' => 'machines'])
            ->set('cardFilter', 'active')
            ->get('machines')->pluck('id')->all();

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_card_enrolled_isolates_agent_workstations(): void
    {
        $withAgent = $this->enrolled('pc-agent');
        $bare = Workstation::factory()->create(['name' => 'pc-bare', 'status' => 'active']);

        $ids = Livewire::test('pages::parc.index', ['tab' => 'machines'])
            ->set('cardFilter', 'enrolled')
            ->get('machines')->pluck('id')->all();

        $this->assertContains($withAgent->id, $ids);
        $this->assertNotContains($bare->id, $ids);
    }

    public function test_card_without_group_isolates_ungrouped_workstations(): void
    {
        // withoutEvents : évite l'observer WorkstationGroup → job AD sync (LDAP).
        $group = WorkstationGroup::withoutEvents(fn () => WorkstationGroup::factory()->create());
        $grouped = Workstation::factory()->create(['name' => 'pc-grouped', 'status' => 'active']);
        $grouped->groups()->attach($group->id);
        $orphan = Workstation::factory()->create(['name' => 'pc-orphan', 'status' => 'active']);

        $ids = Livewire::test('pages::parc.index', ['tab' => 'machines'])
            ->set('cardFilter', 'without_group')
            ->get('machines')->pluck('id')->all();

        $this->assertContains($orphan->id, $ids);
        $this->assertNotContains($grouped->id, $ids);
    }

    public function test_card_silent_includes_never_reported(): void
    {
        // Union alignée sur le compteur de la carte « Muets » (silent +
        // never_reported), plus large que conformityFilter='silent' seul.
        $silent = $this->enrolled('pc-silent', now()->subSeconds(3 * 3600)); // > 2×ttl
        $neverReported = Workstation::factory()->create(['name' => 'pc-never', 'status' => 'active']);
        $this->tokens->issueFor($neverReported); // enrôlé mais jamais de check-in
        $talking = $this->enrolled('pc-talking'); // check-in récent

        $ids = Livewire::test('pages::parc.index', ['tab' => 'machines'])
            ->set('cardFilter', 'silent')
            ->get('machines')->pluck('id')->all();

        $this->assertContains($silent->id, $ids);
        $this->assertContains($neverReported->id, $ids);
        $this->assertNotContains($talking->id, $ids);
    }

    public function test_reset_filters_clears_card_filter(): void
    {
        Livewire::test('pages::parc.index', ['tab' => 'machines'])
            ->set('cardFilter', 'without_group')
            ->call('resetMachineFilters')
            ->assertSet('cardFilter', '');
    }
}
