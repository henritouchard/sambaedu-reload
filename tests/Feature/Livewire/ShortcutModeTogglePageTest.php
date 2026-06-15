<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\StateMode;
use App\Models\Shortcut;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 27.1 (FR26) — première exposition UI du toggle strict/default sur les
 * raccourcis. Vérifie le chargement du mode existant et sa persistance via
 * `save()`.
 *
 * ⚠️ La page rend via `<x-organisms.page>` (@vite) : sur un hôte sans build
 * d'assets, le RENDU échoue (Vite manifest). Ces tests passent sur /vm (assets
 * construits) ; la logique `save()` est par ailleurs couverte sans rendu par
 * StateModeCastTest + ShortcutsStateProviderTest.
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
        // Toggle UI = édition autorisée (le ciblage des droits est testé ailleurs).
        Gate::define('update-shortcut', fn () => true);
        Gate::define('delete-shortcut', fn () => true);
    }

    #[Test]
    public function loads_existing_mode_and_persists_the_toggle(): void
    {
        $sc = Shortcut::create([
            'key' => 'firefox', 'name' => 'Firefox', 'place' => 'desktop',
            'is_active' => true, 'is_global' => false, 'windows_link' => 'C:\\ff.exe',
            'mode' => StateMode::Default,
        ]);

        Livewire::test(self::COMPONENT, ['id' => $sc->key])
            ->assertSet('mode', 'default')
            ->set('mode', 'strict')
            ->set('name', 'Firefox')
            ->set('place', 'desktop')
            ->call('save')
            ->assertHasNoErrors();

        self::assertSame(StateMode::Strict, $sc->fresh()->mode);
    }

    #[Test]
    public function null_mode_defaults_to_strict_in_form(): void
    {
        $sc = Shortcut::create([
            'key' => 'np', 'name' => 'Notepad', 'place' => 'startup',
            'is_active' => true, 'is_global' => false, 'windows_link' => 'C:\\n.exe',
        ]);

        Livewire::test(self::COMPONENT, ['id' => $sc->key])
            ->assertSet('mode', 'strict');
    }
}
