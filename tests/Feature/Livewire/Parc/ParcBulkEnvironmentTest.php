<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Parc;

use App\Enums\WorkstationEnvironment;
use App\Models\User;
use App\Models\WorkstationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Action groupée « Définir l'environnement » sur la multi-sélection de groupes
 * (onglet Groupes de /parc) — l'autre moitié du déménagement de l'ancien onglet
 * « Environnement » de parc-settings. La gate `update-workstationGroup`
 * (= computer.install) est vérifiée PAR groupe, car la route /parc n'exige que
 * la lecture : un groupe non autorisé est ignoré, jamais écrit.
 */
class ParcBulkEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::parc.index';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();

        $admin = User::query()->create(['login' => 'parc-admin', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($admin);
    }

    /** Autorise la gate parc sans monter de permissions Spatie (iso EnvironmentTab d'origine). */
    private function allowParcGate(): void
    {
        Gate::before(fn ($user, string $ability) => $ability === 'update-workstationGroup' ? true : null);
    }

    #[Test]
    public function applies_the_environment_to_every_selected_group(): void
    {
        $this->allowParcGate();
        $a = WorkstationGroup::factory()->create(['environment' => null]);
        $b = WorkstationGroup::factory()->logical()->create(['environment' => null]);

        Livewire::test(self::COMPONENT)
            ->set('selectedGroups', [$a->id, $b->id])
            ->call('setGroupsEnvironment', 'nomade')
            ->assertDispatched('toastMagic', fn ($event, $params) => ($params['status'] ?? null) === 'success')
            ->assertSet('selectedGroups', []);

        self::assertSame(WorkstationEnvironment::Nomade, $a->refresh()->environment);
        self::assertSame(WorkstationEnvironment::Nomade, $b->refresh()->environment);
    }

    #[Test]
    public function empty_value_resets_the_environment_to_null(): void
    {
        $this->allowParcGate();
        $group = WorkstationGroup::factory()->create([
            'environment' => WorkstationEnvironment::PersonalLocal,
        ]);

        Livewire::test(self::COMPONENT)
            ->set('selectedGroups', [$group->id])
            ->call('setGroupsEnvironment', '')
            ->assertDispatched('toastMagic', fn ($event, $params) => ($params['status'] ?? null) === 'success');

        self::assertNull($group->refresh()->environment);
    }

    #[Test]
    public function invalid_value_is_refused_and_writes_nothing(): void
    {
        $this->allowParcGate();
        $group = WorkstationGroup::factory()->create([
            'environment' => WorkstationEnvironment::SharedLocal,
        ]);

        Livewire::test(self::COMPONENT)
            ->set('selectedGroups', [$group->id])
            ->call('setGroupsEnvironment', 'bogus')
            ->assertDispatched('toastMagic', fn ($event, $params) => ($params['status'] ?? null) === 'error');

        self::assertSame(WorkstationEnvironment::SharedLocal, $group->refresh()->environment);
    }

    #[Test]
    public function unauthorized_groups_are_skipped_not_written(): void
    {
        // Pas de allowParcGate : le user de setUp (prof sans computer.install) est
        // réellement refusé par la policy → aucun groupe modifié, toast d'erreur.
        $group = WorkstationGroup::factory()->create(['environment' => null]);

        Livewire::test(self::COMPONENT)
            ->set('selectedGroups', [$group->id])
            ->call('setGroupsEnvironment', 'nomade')
            ->assertDispatched('toastMagic', fn ($event, $params) => ($params['status'] ?? null) === 'error');

        self::assertNull($group->refresh()->environment);
    }
}
