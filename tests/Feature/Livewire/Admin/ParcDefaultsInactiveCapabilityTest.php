<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\Capability;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 35.5 (review #2) — garde `is_active` sur l'onglet « Registre / capacités »
 * de /admin/settings/parc-defaults.
 *
 * `photo_viewer_restored` est la PREMIÈRE capacité seedée `is_active=false`
 * (gate d'honnêteté 35.5) : avant elle, le trou « éditer le défaut d'une
 * capacité inactive » était théorique. Le provider ignore les capacités
 * inactives (`where('is_active', true)`) → poser un défaut serait un réglage
 * silencieusement sans effet. `openEdit`/`saveDefault` refusent désormais
 * côté SERVEUR (l'opacité CSS seule ne protège rien).
 */
class ParcDefaultsInactiveCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private const REGISTRY_TAB = 'pages::admin.settings.parc-defaults._partials.registry-tab';

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->withoutVite();
        WorkstationGroupObserver::disableSync();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        Mockery::close();
        parent::tearDown();
    }

    /** Admin plein (patron ParcDefaultsUpstreamLockTest) : la garde testée est bien is_active, pas le droit. */
    private function actAsAdmin(): void
    {
        $user = Mockery::mock(
            \Illuminate\Contracts\Auth\Authenticatable::class,
            \Illuminate\Contracts\Auth\Access\Authorizable::class,
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

    #[Test]
    public function open_edit_refuses_an_inactive_capability(): void
    {
        // Fixture factory : photo_viewer_restored a été ACTIVÉE par le flip
        // 2026_07_03_150000 — la garde reste nécessaire pour toute future
        // capacité gatée inactive.
        $this->actAsAdmin();
        $inactive = Capability::factory()->create(['key' => 'gated_cap', 'is_active' => false]);

        Livewire::test(self::REGISTRY_TAB)
            ->call('openEdit', $inactive->id)
            ->assertSet('showEditModal', false)
            ->assertSet('editingCapabilityId', null);
    }

    #[Test]
    public function save_default_refuses_an_inactive_capability_even_if_ui_is_bypassed(): void
    {
        $this->actAsAdmin();
        $inactive = Capability::factory()->create(['key' => 'gated_cap', 'is_active' => false]);
        $before = $inactive->default_value;

        Livewire::test(self::REGISTRY_TAB)
            ->set('editingCapabilityId', $inactive->id)
            ->set('formValue', 'on')
            ->call('saveDefault');

        self::assertSame(
            $before,
            $inactive->fresh()->default_value,
            'le défaut d\'une capacité inactive ne doit pas être modifiable (réglage sans effet)',
        );
    }

    #[Test]
    public function open_edit_still_works_for_an_active_capability(): void
    {
        // Non-régression : la garde ne bloque QUE les inactives.
        $this->actAsAdmin();
        $active = Capability::query()->where('is_active', true)->firstOrFail();

        Livewire::test(self::REGISTRY_TAB)
            ->call('openEdit', $active->id)
            ->assertSet('showEditModal', true)
            ->assertSet('editingCapabilityId', $active->id);
    }
}
