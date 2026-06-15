<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Models\Shortcut;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 27.3 (FR26, FR19) — le toggle drift policy a QUITTÉ le formulaire de
 * RÈGLE pour le geste d'ASSIGNATION : le mode strict|default est posé sur le
 * pivot `shortcut_assignables.mode`, plus sur la règle.
 *
 * Ce test vérifie (a) le RETRAIT du champ `mode` du formulaire d'édition de
 * règle (régression de retrait) et (b) la persistance du `pivot.mode` au geste
 * d'assignation (`onAssignmentsConfirmed`).
 *
 * ⚠️ La page rend via `<x-organisms.page>` (@vite) : sur un hôte sans build
 * d'assets, le RENDU échoue (Vite manifest). Ces tests passent sur /vm (assets
 * construits) ; la logique de provenance du mode est par ailleurs couverte sans
 * rendu par ShortcutsStateProviderTest.
 */
class ShortcutModeTogglePageTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::shortcuts.[id].index';

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        WorkstationGroupObserver::disableSync();
        Gate::define('update-shortcut', fn () => true);
        Gate::define('delete-shortcut', fn () => true);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        parent::tearDown();
    }

    #[Test]
    public function rule_edit_form_no_longer_exposes_a_mode_property(): void
    {
        // Story 27.3 : la propriété `$mode` a quitté le composant de page (elle
        // vivait sur le formulaire de règle). Plus aucun toggle mode sur la règle.
        $sc = Shortcut::create([
            'key' => 'firefox', 'name' => 'Firefox', 'place' => 'desktop',
            'is_active' => true, 'is_global' => false, 'windows_link' => 'C:\\ff.exe',
        ]);

        $component = Livewire::test(self::COMPONENT, ['id' => $sc->key]);

        self::assertFalse(
            property_exists($component->instance(), 'mode'),
            'le formulaire de règle ne porte plus le champ mode (déplacé à l\'assignation)',
        );
    }

    #[Test]
    public function assignment_persists_mode_on_the_pivot(): void
    {
        $sc = Shortcut::create([
            'key' => 'pronote', 'name' => 'Pronote', 'place' => 'desktop',
            'is_active' => true, 'is_global' => false, 'windows_link' => 'C:\\p.exe',
        ]);
        $room = WorkstationGroup::factory()->create();
        $parc = WorkstationGroup::factory()->logical()->create();

        Livewire::test(self::COMPONENT, ['id' => $sc->key])
            ->call('onAssignmentsConfirmed', [$room->id], [], [], [], 'strict')
            ->call('onAssignmentsConfirmed', [$parc->id], [], [], [], 'default')
            ->assertHasNoErrors();

        // Le MÊME raccourci : verrouillé (strict) sur la salle, modifiable
        // (default) sur le parc — le mode suit la cible.
        $roomMode = DB::table('shortcut_assignables')
            ->where('shortcut_id', $sc->id)
            ->where('assignable_type', WorkstationGroup::class)
            ->where('assignable_id', $room->id)
            ->value('mode');
        $parcMode = DB::table('shortcut_assignables')
            ->where('shortcut_id', $sc->id)
            ->where('assignable_type', WorkstationGroup::class)
            ->where('assignable_id', $parc->id)
            ->value('mode');

        self::assertSame('strict', $roomMode);
        self::assertSame('default', $parcMode);
    }
}
