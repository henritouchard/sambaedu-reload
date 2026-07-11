<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Capability;
use App\Models\CapabilityProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 43.2 (D6, AC1/AC6) — `Capability::refreshHint()` + `Capability::effectTiming()`.
 *
 * Les DEUX lisent la relation `projections` DÉJÀ chargée par l'appelant (zéro
 * requête ajoutée) : chaque test charge explicitement `projections` (comme les
 * 3 composants UI réels) plutôt que de compter sur le lazy-loading implicite.
 */
class CapabilityRefreshHintTest extends TestCase
{
    use RefreshDatabase;

    private function reload(Capability $cap): Capability
    {
        return Capability::query()->with('projections')->findOrFail($cap->id);
    }

    #[Test]
    public function refresh_hint_is_null_without_any_valid_hint(): void
    {
        $cap = Capability::factory()->create();
        CapabilityProjection::factory()->for($cap)->create([
            'mechanism' => CapabilityProjection::MECHANISM_REGISTRY,
            'spec' => ['keys' => [['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'K', 'type' => 'REG_DWORD', 'value' => ['on' => 1]]]],
        ]);

        self::assertNull($this->reload($cap)->refreshHint());
    }

    #[Test]
    public function refresh_hint_reads_the_root_level_spec_refresh_field(): void
    {
        $cap = Capability::factory()->create();
        CapabilityProjection::factory()->for($cap)->create([
            'mechanism' => CapabilityProjection::MECHANISM_REGISTRY,
            'spec' => [
                'keys' => [['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'K', 'type' => 'REG_DWORD', 'value' => ['on' => 1]]],
                'refresh' => 'policy_broadcast',
            ],
        ]);

        self::assertSame('policy_broadcast', $this->reload($cap)->refreshHint());
    }

    #[Test]
    public function bi_projection_takes_the_strongest_hint_of_the_two_specs(): void
    {
        // D1/D6 : ordre de force = REFRESH_HINTS (shell_notify < policy_broadcast
        // < explorer_restart). La bi-projection prend le MAX des deux specs.
        $cap = Capability::factory()->create();
        CapabilityProjection::factory()->for($cap)->create([
            'mechanism' => CapabilityProjection::MECHANISM_REGISTRY,
            'spec' => [
                'keys' => [['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'Flag', 'type' => 'REG_DWORD', 'value' => ['on' => 1]]],
                'refresh' => 'shell_notify',
            ],
        ]);
        CapabilityProjection::factory()->for($cap)->create([
            'mechanism' => CapabilityProjection::MECHANISM_REGISTRY_LIST,
            'spec' => [
                'keys' => [['hive' => 'HKCU', 'path' => 'Software\\X\\List', 'entry_type' => 'REG_SZ', 'values' => ['on' => ['a']]]],
                'refresh' => 'explorer_restart',
            ],
        ]);

        self::assertSame('explorer_restart', $this->reload($cap)->refreshHint());
    }

    #[Test]
    public function refresh_hint_ignores_a_hint_outside_the_closed_vocabulary(): void
    {
        // Donnée corrompue hypothétique (déjà refusée à l'authoring) : ignorée,
        // jamais d'exception.
        $cap = Capability::factory()->create();
        CapabilityProjection::factory()->for($cap)->create([
            'mechanism' => CapabilityProjection::MECHANISM_REGISTRY,
            'spec' => [
                'keys' => [['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'K', 'type' => 'REG_DWORD', 'value' => ['on' => 1]]],
                'refresh' => 'SHELL_NOTIFY',
            ],
        ]);

        self::assertNull($this->reload($cap)->refreshHint());
    }

    #[Test]
    public function effect_timing_is_null_without_any_hkcu_registry_key(): void
    {
        // D5/piège n°8 : capacité machine-only (HKLM) — AUCUN badge, même si
        // (hypothétiquement) un hint était posé (règle 5b du guard le refuserait
        // de toute façon à l'authoring).
        $cap = Capability::factory()->create();
        CapabilityProjection::factory()->for($cap)->create([
            'mechanism' => CapabilityProjection::MECHANISM_REGISTRY,
            'spec' => ['keys' => [['hive' => 'HKLM', 'path' => 'SOFTWARE\\X', 'name' => 'K', 'type' => 'REG_DWORD', 'value' => ['on' => 1]]]],
        ]);

        self::assertNull($this->reload($cap)->effectTiming());
    }

    #[Test]
    public function effect_timing_falls_back_to_next_session_wording_without_a_hint(): void
    {
        $cap = Capability::factory()->create();
        CapabilityProjection::factory()->for($cap)->create([
            'mechanism' => CapabilityProjection::MECHANISM_REGISTRY,
            'spec' => ['keys' => [['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'K', 'type' => 'REG_DWORD', 'value' => ['on' => 1]]]],
        ]);

        $timing = $this->reload($cap)->effectTiming();

        self::assertNotNull($timing);
        self::assertSame('À la prochaine session', $timing['label']);
        // D5 — pas de jargon (ni « logon », ni « HKCU », ni « broadcast »).
        self::assertStringNotContainsString('logon', $timing['tooltip']);
        self::assertStringContainsString('session Windows', $timing['tooltip']);
    }

    #[Test]
    public function effect_timing_says_immediate_for_shell_notify_and_policy_broadcast(): void
    {
        foreach (['shell_notify', 'policy_broadcast'] as $hint) {
            $cap = Capability::factory()->create();
            CapabilityProjection::factory()->for($cap)->create([
                'mechanism' => CapabilityProjection::MECHANISM_REGISTRY,
                'spec' => [
                    'keys' => [['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'K', 'type' => 'REG_DWORD', 'value' => ['on' => 1]]],
                    'refresh' => $hint,
                ],
            ]);

            $timing = $this->reload($cap)->effectTiming();

            self::assertNotNull($timing);
            self::assertSame('Immédiat', $timing['label'], "hint '{$hint}'");
            self::assertStringNotContainsString('HKCU', $timing['tooltip'], 'pas de jargon');
            self::assertStringNotContainsString('broadcast', $timing['tooltip'], 'pas de jargon');
        }
    }

    #[Test]
    public function effect_timing_says_immediate_with_restart_for_explorer_restart(): void
    {
        $cap = Capability::factory()->create();
        CapabilityProjection::factory()->for($cap)->create([
            'mechanism' => CapabilityProjection::MECHANISM_REGISTRY,
            'spec' => [
                'keys' => [['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'K', 'type' => 'REG_DWORD', 'value' => ['on' => 1]]],
                'refresh' => 'explorer_restart',
            ],
        ]);

        $timing = $this->reload($cap)->effectTiming();

        self::assertNotNull($timing);
        self::assertSame('Immédiat (le bureau redémarre)', $timing['label']);
        self::assertStringContainsString('Explorateur', $timing['tooltip']);
    }
}
