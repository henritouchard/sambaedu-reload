<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Parc;

use App\Enums\FilePolicyMode;
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
 * L'édition de la nature des postes (`environment`, Story 26.1) a quitté
 * l'onglet « Environnement » de parc-settings pour rejoindre le formulaire
 * d'édition d'UN groupe — là où l'on gère déjà ses propriétés. Ces tests
 * couvrent la persistance via `updateGroup` (cast enum), la remise à « non
 * déclaré » (null) et le refus d'une valeur hors enum fermé.
 */
class GroupEditEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::parc.groups.[id].edit.index';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // L'observer WorkstationGroup dispatche un job de sync AD à chaque write :
        // on le neutralise (pas de LDAP en test).
        Queue::fake();

        $admin = User::query()->create(['login' => 'grp-admin', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($admin);

        // Story 30.2 : la page d'édition autorise désormais explicitement la gate
        // `update-workstationGroup` en tête de save() (défense en profondeur, le
        // mapping de label EST une modification du parc). On l'accorde ici sans
        // monter de permissions Spatie (iso ParcBulkEnvironmentTest).
        Gate::before(fn ($user, string $ability) => $ability === 'update-workstationGroup' ? true : null);
    }

    #[Test]
    public function the_form_prefills_the_persisted_environment(): void
    {
        $group = WorkstationGroup::factory()->create([
            'environment' => WorkstationEnvironment::PersonalLocal,
        ]);

        Livewire::test(self::COMPONENT, ['id' => $group->id])
            ->assertSet('environment', 'personal_local');
    }

    #[Test]
    public function saving_persists_the_selected_environment(): void
    {
        $group = WorkstationGroup::factory()->create(['environment' => null]);

        Livewire::test(self::COMPONENT, ['id' => $group->id])
            ->set('environment', 'nomade')
            ->call('save')
            ->assertHasNoErrors();

        self::assertSame(
            WorkstationEnvironment::Nomade,
            WorkstationGroup::query()->findOrFail($group->id)->environment,
        );
    }

    #[Test]
    public function saving_empty_value_resets_environment_to_null(): void
    {
        $group = WorkstationGroup::factory()->create([
            'environment' => WorkstationEnvironment::PersonalLocal,
        ]);

        Livewire::test(self::COMPONENT, ['id' => $group->id])
            ->set('environment', '')
            ->call('save');

        self::assertNull(WorkstationGroup::query()->findOrFail($group->id)->environment);
    }

    #[Test]
    public function the_form_prefills_the_persisted_files_policy_override(): void
    {
        $group = WorkstationGroup::factory()->create([
            'files_policy_mode' => FilePolicyMode::AutreWeb,
        ]);

        Livewire::test(self::COMPONENT, ['id' => $group->id])
            ->assertSet('files_policy_mode', 'autre_web');
    }

    #[Test]
    public function saving_persists_the_files_policy_override(): void
    {
        $group = WorkstationGroup::factory()->create(['files_policy_mode' => null]);

        Livewire::test(self::COMPONENT, ['id' => $group->id])
            ->set('files_policy_mode', 'nextcloud_desktop')
            ->set('files_nextcloud_server_url', 'https://cloud.parc.fr')
            ->call('save')
            ->assertHasNoErrors();

        $fresh = WorkstationGroup::query()->findOrFail($group->id);
        self::assertSame(FilePolicyMode::NextcloudDesktop, $fresh->files_policy_mode);
        self::assertSame('https://cloud.parc.fr', $fresh->files_nextcloud_server_url);
    }

    #[Test]
    public function saving_empty_files_policy_resets_override_to_null(): void
    {
        // '' = hérite du défaut global → colonne null.
        $group = WorkstationGroup::factory()->create([
            'files_policy_mode' => FilePolicyMode::AutreWeb,
        ]);

        Livewire::test(self::COMPONENT, ['id' => $group->id])
            ->set('files_policy_mode', '')
            ->call('save');

        self::assertNull(WorkstationGroup::query()->findOrFail($group->id)->files_policy_mode);
    }

    #[Test]
    public function saving_an_invalid_value_is_refused_and_writes_nothing(): void
    {
        // Requête Livewire forgée : valeur hors liste fermée → toast d'erreur,
        // aucune écriture (pas de null silencieux).
        $group = WorkstationGroup::factory()->create([
            'environment' => WorkstationEnvironment::SharedLocal,
        ]);

        Livewire::test(self::COMPONENT, ['id' => $group->id])
            ->set('environment', 'bogus')
            ->call('save')
            ->assertDispatched('toastMagic', fn ($event, $params) => ($params['status'] ?? null) === 'error');

        self::assertSame(
            WorkstationEnvironment::SharedLocal,
            WorkstationGroup::query()->findOrFail($group->id)->environment,
        );
    }
}
