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
 * Story 27.1 — cast `mode` (enum App\Enums\StateMode) sur les 3 tables qui
 * portent désormais le toggle strict/default (shortcuts + wallpapers +
 * overlay_signals, décision n° 2). Vérifie aussi la non-régression : `mode`
 * null reste null (le défaut est résolu côté provider, pas en base).
 */
class StateModeCastTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function shortcut_casts_mode_to_enum_and_keeps_null(): void
    {
        $strict = Shortcut::create(['key' => 'k1', 'name' => 'A', 'place' => 'desktop', 'mode' => StateMode::Strict]);
        $unset = Shortcut::create(['key' => 'k2', 'name' => 'B', 'place' => 'desktop']);

        self::assertSame(StateMode::Strict, $strict->fresh()->mode);
        self::assertNull($unset->fresh()->mode, 'mode non déclaré reste null (défaut résolu côté provider)');
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
