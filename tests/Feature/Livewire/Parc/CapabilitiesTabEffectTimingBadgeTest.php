<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Parc;

use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 43.2 (AC6, D5/D6) — badge de temporalité d'effet sur l'onglet
 * « Options / Capacités » d'un WorkstationGroup (liste des overrides + picker).
 *
 * Patron {@see CapabilitiesTabStatusBadgeTest}.
 */
class CapabilitiesTabEffectTimingBadgeTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::parc.groups._partials.capabilities-tab';

    private WorkstationGroup $parc;

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
        $this->withoutVite();
        WorkstationGroupObserver::disableSync();

        DB::table('capability_assignments')->delete();
        DB::table('capability_projections')->delete();
        DB::table('capabilities')->delete();

        $this->parc = WorkstationGroup::factory()->logical()->create();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        Mockery::close();
        parent::tearDown();
    }

    private function actAsCustomizer(): void
    {
        $user = Mockery::mock(
            Authenticatable::class,
            Authorizable::class,
        );
        $user->shouldReceive('can')->andReturn(true);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        $user->shouldReceive('getAuthIdentifierName')->andReturn('id');
        $user->shouldReceive('getAuthPassword')->andReturn('');
        $user->shouldReceive('getRememberToken')->andReturn('');
        $user->shouldReceive('setRememberToken');
        $user->shouldReceive('getRememberTokenName')->andReturn('');
        $this->actingAs($user);
    }

    private function makeCapability(string $key, array $spec): Capability
    {
        $cap = Capability::factory()->create(['key' => $key, 'label' => ucfirst(str_replace('_', ' ', $key)), 'default_value' => 'on']);
        CapabilityProjection::factory()->for($cap)->create([
            'mechanism' => CapabilityProjection::MECHANISM_REGISTRY,
            'spec' => $spec,
        ]);

        return $cap;
    }

    private function addOverride(Capability $cap, string $value = 'off'): void
    {
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->parc->id,
            'value' => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function overrides_list_shows_the_immediate_badge_for_a_retrofitted_capability(): void
    {
        $this->actAsCustomizer();
        $cap = $this->makeCapability('show_file_extensions', [
            'keys' => [['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'HideFileExt', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]]],
            'refresh' => 'shell_notify',
        ]);
        $this->addOverride($cap);

        $component = Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id]);

        $component->assertSeeHtml('data-testid="effect-timing-'.$cap->id.'"');
        $component->assertSeeHtml('Immédiat');
    }

    #[Test]
    public function overrides_list_shows_the_next_session_badge_without_a_hint(): void
    {
        $this->actAsCustomizer();
        $cap = $this->makeCapability('no_hint_cap', [
            'keys' => [['hive' => 'HKCU', 'path' => 'Software\\Y', 'name' => 'K', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]]],
        ]);
        $this->addOverride($cap);

        $component = Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id]);

        $component->assertSeeHtml('data-testid="effect-timing-'.$cap->id.'"');
        $component->assertSeeHtml('À la prochaine session');
    }

    #[Test]
    public function overrides_list_shows_no_badge_for_a_machine_only_capability(): void
    {
        $this->actAsCustomizer();
        $cap = $this->makeCapability('machine_only_cap', [
            'keys' => [['hive' => 'HKLM', 'path' => 'SOFTWARE\\Z', 'name' => 'K', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]]],
        ]);
        $this->addOverride($cap);

        $component = Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id]);

        $component->assertDontSeeHtml('data-testid="effect-timing-'.$cap->id.'"');
    }

    #[Test]
    public function picker_shows_the_effect_timing_badge_for_addable_capabilities(): void
    {
        $this->actAsCustomizer();
        $cap = $this->makeCapability('restart_cap', [
            'keys' => [['hive' => 'HKCU', 'path' => 'Software\\R', 'name' => 'K', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]]],
            'refresh' => 'explorer_restart',
        ]);
        // Pas d'override : la capacité reste dans le picker (addableCapabilities).

        $component = Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id]);

        $component->assertSeeHtml('data-testid="picker-effect-timing-'.$cap->id.'"');
        $component->assertSeeHtml('Immédiat (le bureau redémarre)');
    }
}
