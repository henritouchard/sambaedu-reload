<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\StateMode;
use App\Models\OverlaySignal;
use App\Models\Shortcut;
use App\Models\Wallpaper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 27.1 — cast `mode` (enum App\Enums\StateMode) sur les tables qui portent
 * le toggle strict/default. Révisé Story 27.3 : pour `shortcuts` le mode a quitté
 * la RÈGLE pour l'ASSIGNATION (`shortcut_assignables.mode`, plus de cast sur le
 * modèle `Shortcut`) — la couverture du mode shortcuts vit désormais dans
 * `ShortcutsStateProviderTest`. `wallpapers` et `overlay_signals` gardent le mode
 * sur leur table (déjà « par cible », Option A) : cast inchangé, testé ici.
 */
class StateModeCastTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function shortcut_no_longer_casts_mode_on_the_model(): void
    {
        // Story 27.3 : `mode` a quitté `$casts`/`$fillable` du modèle Shortcut.
        $sc = new Shortcut();

        self::assertArrayNotHasKey('mode', $sc->getCasts(), 'le cast mode a quitté le modèle Shortcut (mode = sur le pivot)');
        self::assertNotContains('mode', $sc->getFillable(), 'mode n\'est plus fillable sur Shortcut');
    }

    #[Test]
    public function wallpaper_casts_mode_to_enum(): void
    {
        $wp = Wallpaper::create(['name' => 'w', 'type' => Wallpaper::TYPE_WALLPAPER, 'is_default' => true, 'mode' => 'default']);

        self::assertSame(StateMode::Default, $wp->fresh()->mode);
    }

    #[Test]
    public function overlay_signal_casts_mode_to_enum(): void
    {
        $sig = OverlaySignal::create([
            'kind' => 'notice', 'severity' => 'info', 'title' => 't', 'text' => 'x',
            'mode' => StateMode::Default,
        ]);

        self::assertSame(StateMode::Default, $sig->fresh()->mode);
    }
}
