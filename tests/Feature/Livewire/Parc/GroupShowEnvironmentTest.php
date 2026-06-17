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
 * La fiche de présentation d'un groupe AFFICHE l'environnement (nature des postes,
 * Story 26.1) en lecture seule dans sa carte d'identité — l'édition se fait
 * ailleurs (formulaire « Modifier » + action groupée). Garde anti-régression :
 * l'environnement avait d'abord été posé dans un partial orphelin (group-info)
 * jamais inclus, donc invisible.
 *
 * RefreshDatabase (vraies migrations) — contrairement à GroupShowPageTest qui
 * forge ses tables à la main (pivot imprimante sans la colonne `is_default`).
 */
class GroupShowEnvironmentTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::parc.groups.[id].index';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();

        $admin = User::query()->create(['login' => 'show-admin', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($admin);

        // `view` est requise par le mount (loadGroup) pour ne pas rediriger.
        Gate::before(fn ($user, string $ability) => $ability === 'view' ? true : null);
    }

    #[Test]
    public function the_presentation_card_displays_the_declared_environment_read_only(): void
    {
        $group = WorkstationGroup::factory()->create([
            'environment' => WorkstationEnvironment::PersonalLocal,
        ]);

        Livewire::test(self::COMPONENT, ['id' => $group->id])
            ->assertSee('Environnement')
            ->assertSee('Personnel') // shortLabel()
            // Affichage seul : aucune édition inline sur la fiche de présentation.
            ->assertDontSeeHtml('wire:change="saveEnvironment"');
    }

    #[Test]
    public function the_presentation_card_shows_non_declare_when_null(): void
    {
        $group = WorkstationGroup::factory()->create(['environment' => null]);

        Livewire::test(self::COMPONENT, ['id' => $group->id])
            ->assertSee('Environnement')
            ->assertSee('Non déclaré');
    }
}
