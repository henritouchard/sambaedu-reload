<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Parc;

use App\Models\User;
use App\Models\WorkstationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * La carte d'identité d'un parc NOMME le label amont qu'il porte. Le badge
 * « Imposé par le contrat amont » disait déjà qu'un contrat gouvernait ce parc,
 * jamais par quel label — or c'est le label qui décide des politiques reçues et
 * des capacités verrouillées.
 *
 * Un parc sans label n'affiche rien : une instance sans contrat garde une carte
 * inchangée.
 */
class GroupShowControlHubLabelTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::parc.groups.[id].index';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();

        $admin = User::query()->create(['login' => 'label-admin', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($admin);

        Gate::before(fn ($user, string $ability) => $ability === 'view' ? true : null);
    }

    #[Test]
    public function the_presentation_card_names_the_label_the_parc_carries(): void
    {
        $group = WorkstationGroup::factory()->create(['controlhub_label' => 'modelibre']);

        Livewire::test(self::COMPONENT, ['id' => $group->id])
            ->assertSee('Label amont')
            ->assertSee('modelibre');
    }

    #[Test]
    public function a_parc_without_a_label_shows_no_such_row(): void
    {
        $group = WorkstationGroup::factory()->create(['controlhub_label' => null]);

        Livewire::test(self::COMPONENT, ['id' => $group->id])
            ->assertDontSee('Label amont');
    }
}
