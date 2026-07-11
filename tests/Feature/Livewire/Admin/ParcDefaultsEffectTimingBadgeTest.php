<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 43.2 (AC6, D5/D6) — badge de temporalité d'effet sur l'onglet
 * « Registre / capacités » de /admin/settings/parc-defaults.
 *
 * Patron {@see ParcDefaultsStatusBadgeTest} : mêmes gardes (Gate::before ciblé
 * server.admin, catalogue vidé), composant identique.
 */
class ParcDefaultsEffectTimingBadgeTest extends TestCase
{
    use RefreshDatabase;

    private const REGISTRY_TAB = 'pages::admin.settings.parc-defaults._partials.registry-tab';

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
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        Mockery::close();
        parent::tearDown();
    }

    private function actAsAdmin(): void
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
        Gate::before(fn ($u, string $ability) => $ability === 'server.admin' ? true : null);
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

    #[Test]
    public function a_retrofitted_capability_shows_the_immediate_badge(): void
    {
        $this->actAsAdmin();
        $cap = $this->makeCapability('show_file_extensions', [
            'keys' => [['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'HideFileExt', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]]],
            'refresh' => 'shell_notify',
        ]);

        $component = Livewire::test(self::REGISTRY_TAB);

        $component->assertSeeHtml('data-testid="effect-timing-'.$cap->id.'"');
        $component->assertSeeHtml('Immédiat');
        $component->assertDontSeeHtml('À la prochaine session');
    }

    #[Test]
    public function an_hkcu_capability_without_hint_shows_the_next_session_badge(): void
    {
        $this->actAsAdmin();
        $cap = $this->makeCapability('no_hint_cap', [
            'keys' => [['hive' => 'HKCU', 'path' => 'Software\\Y', 'name' => 'K', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]]],
        ]);

        $component = Livewire::test(self::REGISTRY_TAB);

        $component->assertSeeHtml('data-testid="effect-timing-'.$cap->id.'"');
        $component->assertSeeHtml('À la prochaine session');
    }

    #[Test]
    public function a_machine_only_capability_shows_no_badge(): void
    {
        // Piège n°8 — AUCUN badge pour une capacité sans clé HKCU registre.
        $this->actAsAdmin();
        $cap = $this->makeCapability('machine_only_cap', [
            'keys' => [['hive' => 'HKLM', 'path' => 'SOFTWARE\\Z', 'name' => 'K', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]]],
        ]);

        $component = Livewire::test(self::REGISTRY_TAB);

        $component->assertDontSeeHtml('data-testid="effect-timing-'.$cap->id.'"');
    }

    #[Test]
    public function an_explorer_restart_hint_shows_the_restart_wording(): void
    {
        $this->actAsAdmin();
        $cap = $this->makeCapability('restart_cap', [
            'keys' => [['hive' => 'HKCU', 'path' => 'Software\\R', 'name' => 'K', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]]],
            'refresh' => 'explorer_restart',
        ]);

        $component = Livewire::test(self::REGISTRY_TAB);

        $component->assertSeeHtml('data-testid="effect-timing-'.$cap->id.'"');
        $component->assertSeeHtml('Immédiat (le bureau redémarre)');
    }
}
