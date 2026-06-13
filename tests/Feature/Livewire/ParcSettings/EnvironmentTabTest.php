<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\ParcSettings;

use App\Enums\WorkstationEnvironment;
use App\Models\User;
use App\Models\WorkstationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 26.1 — AC5 : onglet « Environnement » de parc-settings.
 *
 * Sélection → persistance via le modèle (cast enum) → toast de succès. La
 * remise à « Non déclaré » écrit null (distinction D2). Gate
 * `update-workstationGroup` sur l'action : les cas nominaux l'autorisent via
 * `Gate::before` (iso GroupShowPageTest, appelé explicitement par les tests qui
 * écrivent) ; le REFUS est couvert par `save_is_forbidden_without_permission`
 * (sans `Gate::before` → la policy refuse réellement). Une valeur hors liste
 * fermée est refusée (toast d'erreur, aucune écriture) — `invalid_value_*`.
 */
class EnvironmentTabTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::parc-settings._partials.environment-tab';

    protected function setUp(): void
    {
        parent::setUp();

        // Un user authentifié est requis pour que les hooks Gate::before
        // s'exécutent (Laravel les saute pour les invités). L'autorisation de la
        // gate parc est posée à la demande par les tests qui en ont besoin
        // (cf. allowParcGate) pour laisser le test de refus la voir échouer.
        $admin = User::query()->create(['login' => 'env-admin', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($admin);
    }

    /** Autorise la gate parc sans monter de permissions Spatie (iso GroupShowPageTest). */
    private function allowParcGate(): void
    {
        Gate::before(fn ($user, string $ability) => $ability === 'update-workstationGroup' ? true : null);
    }

    #[Test]
    public function selecting_an_environment_persists_it_and_toasts_success(): void
    {
        $this->allowParcGate();
        $group = WorkstationGroup::factory()->create(['name' => 'parc-perdir']);

        Livewire::test(self::COMPONENT)
            ->set("selection.{$group->id}", 'nomade')
            ->call('save', $group->id)
            ->assertDispatched('toastMagic', fn ($event, $params) => ($params['status'] ?? null) === 'success');

        self::assertSame(
            WorkstationEnvironment::Nomade,
            WorkstationGroup::query()->findOrFail($group->id)->environment,
        );
    }

    #[Test]
    public function selecting_empty_value_resets_environment_to_null(): void
    {
        $this->allowParcGate();
        $group = WorkstationGroup::factory()->create([
            'environment' => WorkstationEnvironment::PersonalLocal,
        ]);

        Livewire::test(self::COMPONENT)
            ->set("selection.{$group->id}", '')
            ->call('save', $group->id)
            ->assertDispatched('toastMagic', fn ($event, $params) => ($params['status'] ?? null) === 'success');

        self::assertNull(WorkstationGroup::query()->findOrFail($group->id)->environment);
    }

    #[Test]
    public function mount_prefills_selection_from_persisted_values(): void
    {
        $logical = WorkstationGroup::factory()->logical()->create([
            'environment' => WorkstationEnvironment::PersonalLocal,
        ]);
        $physical = WorkstationGroup::factory()->create(['environment' => null]);

        Livewire::test(self::COMPONENT)
            ->assertSet("selection.{$logical->id}", 'personal_local')
            ->assertSet("selection.{$physical->id}", '');
    }

    #[Test]
    public function unknown_group_id_toasts_error_and_writes_nothing(): void
    {
        $this->allowParcGate();

        Livewire::test(self::COMPONENT)
            ->call('save', 999999)
            ->assertDispatched('toastMagic', fn ($event, $params) => ($params['status'] ?? null) === 'error');
    }

    #[Test]
    public function invalid_value_toasts_error_and_writes_nothing(): void
    {
        // Requête Livewire forgée : valeur hors liste fermée. Doit être refusée
        // (toast erreur, aucune écriture) — review P2 : pas de null silencieux.
        $this->allowParcGate();
        $group = WorkstationGroup::factory()->create([
            'environment' => WorkstationEnvironment::PersonalLocal,
        ]);

        Livewire::test(self::COMPONENT)
            ->set("selection.{$group->id}", 'bogus')
            ->call('save', $group->id)
            ->assertDispatched('toastMagic', fn ($event, $params) => ($params['status'] ?? null) === 'error');

        // La valeur initiale n'a pas été écrasée.
        self::assertSame(
            WorkstationEnvironment::PersonalLocal,
            WorkstationGroup::query()->findOrFail($group->id)->environment,
        );
    }

    #[Test]
    public function save_is_forbidden_without_permission(): void
    {
        // Pas de allowParcGate ici : l'admin de setUp (prof sans computer.install)
        // doit être réellement refusé par la policy (review P3 : la double
        // protection est prouvée, pas seulement annoncée).
        $group = WorkstationGroup::factory()->create([
            'environment' => WorkstationEnvironment::SharedLocal,
        ]);

        // Sans handler d'exception (iso EnrollmentRequestsSurfaceTest) : la
        // conversion en page 403 exigerait le manifest Vite ; on vérifie ici
        // directement que le Gate lève AVANT toute écriture.
        $this->withoutExceptionHandling();
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        Livewire::test(self::COMPONENT)
            ->set("selection.{$group->id}", 'nomade')
            ->call('save', $group->id);
    }
}
