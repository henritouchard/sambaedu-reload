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
 * Story 27.8 (FR26 retiré, FR19) — le mécanisme de drift policy strict/default
 * est SUPPRIMÉ : l'assignation d'un raccourci ne pose plus AUCUN `mode`
 * (STRICT inconditionnel — la cible fait toujours loi).
 *
 * Ce test vérifie (a) l'ABSENCE du champ `mode` sur le composant de page et
 * (b) que `onAssignmentsConfirmed` n'écrit RIEN dans une éventuelle colonne
 * `mode` du pivot (le geste métier d'assignation reste fonctionnel).
 *
 * ⚠️ La page rend via `<x-organisms.page>` (@vite) : sur un hôte sans build
 * d'assets, le RENDU échoue (Vite manifest). Ces tests passent sur /vm (assets
 * construits) ; la projection de l'assignation est par ailleurs couverte sans
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
    public function page_no_longer_exposes_a_mode_property(): void
    {
        // Story 27.8 : plus aucune propriété `$mode` sur le composant de page —
        // le mécanisme strict/default est entièrement retiré.
        $sc = Shortcut::create([
            'key' => 'firefox', 'name' => 'Firefox', 'place' => 'desktop',
            'is_active' => true, 'is_global' => false, 'windows_link' => 'C:\\ff.exe',
        ]);

        $component = Livewire::test(self::COMPONENT, ['id' => $sc->key]);

        self::assertFalse(
            property_exists($component->instance(), 'mode'),
            'le composant de page ne porte plus le champ mode (mécanisme retiré, Story 27.8)',
        );
    }

    #[Test]
    public function assignment_no_longer_persists_any_mode_on_the_pivot(): void
    {
        // Story 27.8 : `onAssignmentsConfirmed` ne pose plus de `mode` (signature
        // sans param `$mode`) — l'assignation crée le lien, sans drift policy.
        $sc = Shortcut::create([
            'key' => 'pronote', 'name' => 'Pronote', 'place' => 'desktop',
            'is_active' => true, 'is_global' => false, 'windows_link' => 'C:\\p.exe',
        ]);
        $room = WorkstationGroup::factory()->create();

        Livewire::test(self::COMPONENT, ['id' => $sc->key])
            ->call('onAssignmentsConfirmed', [$room->id], [], [], [])
            ->assertHasNoErrors();

        // Le lien existe (geste métier fonctionnel) et la colonne `mode` n'existe
        // plus sur le pivot après le DROP 27.8.
        self::assertSame(1, DB::table('shortcut_assignables')
            ->where('shortcut_id', $sc->id)
            ->where('assignable_type', WorkstationGroup::class)
            ->where('assignable_id', $room->id)
            ->count());
        self::assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('shortcut_assignables', 'mode'),
            'la colonne mode est droppée du pivot (Story 27.8)',
        );
    }
}
