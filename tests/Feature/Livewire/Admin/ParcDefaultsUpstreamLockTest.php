<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\ControlHubContractItem;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 29.2 (AC #2, #5, #6) — verrou amont sur l'onglet « Registre / capacités »
 * de /admin/settings/parc-defaults (DÉFAUT DIFFUSÉ niveau instance).
 *
 * Capacité verrouillée amont → `saveDefault`/`toggleLock` refusés SERVEUR
 * (`capabilities.default_value`/`overrides_locked` inchangés) ; non verrouillée →
 * OK (non-régression 27.17).
 *
 * Le Gate `server.admin` est autorisé via un `Gate::before` CIBLÉ (renvoyant
 * `null` pour les autres abilities) afin que `modify-capability` soit ÉVALUÉ
 * réellement (et non court-circuité par un before global).
 */
class ParcDefaultsUpstreamLockTest extends TestCase
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

    /** Admin (server.admin + app.customize) ; modify-capability évalué réellement. */
    private function actAsAdmin(): void
    {
        $user = Mockery::mock(
            \Illuminate\Contracts\Auth\Authenticatable::class,
            \Illuminate\Contracts\Auth\Access\Authorizable::class,
        );
        $user->shouldReceive('can')->with('app.customize')->andReturn(true);
        $user->shouldReceive('can')->andReturn(true);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        $user->shouldReceive('getAuthIdentifierName')->andReturn('id');
        $user->shouldReceive('getAuthPassword')->andReturn('');
        $user->shouldReceive('getRememberToken')->andReturn('');
        $user->shouldReceive('setRememberToken');
        $user->shouldReceive('getRememberTokenName')->andReturn('');
        $this->actingAs($user);
        // server.admin autorisé ; null pour le reste → modify-capability évalué.
        Gate::before(fn ($u, string $ability) => $ability === 'server.admin' ? true : null);
    }

    /**
     * Story 29.8 AC#3 — acteur SANS `server.admin` mais porteur de `app.customize`
     * (le persona « délégué par-parc » qui pourrait croire pouvoir toucher le défaut
     * diffusé global). Pas de `Gate::before` → `Gate::allows('server.admin')` = false.
     */
    private function actAsNonAdmin(): void
    {
        $user = Mockery::mock(
            \Illuminate\Contracts\Auth\Authenticatable::class,
            \Illuminate\Contracts\Auth\Access\Authorizable::class,
        );
        // Stubs `can()` INERTES pour ce test : `guardAdmin()` lit `Gate::allows('server.admin')`
        // (résolu via le before-hook Spatie, absent ici → false), JAMAIS `$user->can()`. Ils
        // documentent l'intention du persona (porteur de `app.customize`, pas de `server.admin`) ;
        // le 403 vient de l'absence de `Gate::before` accordant `server.admin`.
        $user->shouldReceive('can')->with('app.customize')->andReturn(true);
        $user->shouldReceive('can')->andReturn(false);
        $user->shouldReceive('getAuthIdentifier')->andReturn(2);
        $user->shouldReceive('getAuthIdentifierName')->andReturn('id');
        $user->shouldReceive('getAuthPassword')->andReturn('');
        $user->shouldReceive('getRememberToken')->andReturn('');
        $user->shouldReceive('setRememberToken');
        $user->shouldReceive('getRememberTokenName')->andReturn('');
        $this->actingAs($user);
        // Pas de Gate::before → server.admin refusé.
    }

    private function capabilityWithKey(string $key, string $hive, string $path, string $name, string $default = 'on'): Capability
    {
        $cap = Capability::factory()->create([
            'key' => $key,
            'label' => ucfirst(str_replace('_', ' ', $key)),
            'default_value' => $default,
        ]);
        CapabilityProjection::factory()->for($cap)->keys([
            ['hive' => $hive, 'path' => $path, 'name' => $name, 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
        ])->create();

        return $cap;
    }

    private function lockUpstream(string $hive, string $path, string $name): void
    {
        ControlHubContractItem::factory()->create([
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => "{$hive}|{$path}|{$name}|REG_DWORD",
        ]);
    }

    #[Test]
    public function save_default_is_blocked_for_upstream_locked_capability(): void
    {
        $this->actAsAdmin();
        $cap = $this->capabilityWithKey('remote_desktop_enabled', 'HKCU', 'Software\\RD', 'Enabled', 'on');
        $this->lockUpstream('HKCU', 'Software\\RD', 'Enabled');

        // Contournement de l'UI : poser l'id puis save.
        $component = Livewire::test(self::REGISTRY_TAB)
            ->set('editingCapabilityId', $cap->id)
            ->set('formValue', 'off')
            ->call('saveDefault');

        // AC #2 : refus explicite via toast d'erreur (verrou amont), pas silencieux.
        $component->assertDispatched('toastMagic', fn ($event, $params): bool => ($params['status'] ?? null) === 'error');

        self::assertSame('on', Capability::query()->find($cap->id)->default_value, 'défaut inchangé (refus)');
    }

    #[Test]
    public function toggle_lock_is_blocked_for_upstream_locked_capability(): void
    {
        $this->actAsAdmin();
        $cap = $this->capabilityWithKey('show_file_extensions', 'HKCU', 'Software\\SFE', 'Show');
        self::assertFalse((bool) $cap->overrides_locked);
        $this->lockUpstream('HKCU', 'Software\\SFE', 'Show');

        $component = Livewire::test(self::REGISTRY_TAB)->call('toggleLock', $cap->id);

        // AC #2 : refus explicite via toast d'erreur, pas silencieux.
        $component->assertDispatched('toastMagic', fn ($event, $params): bool => ($params['status'] ?? null) === 'error');

        self::assertFalse(
            (bool) Capability::query()->find($cap->id)->overrides_locked,
            'le (dé)gel local est refusé sur une capacité verrouillée amont',
        );
    }

    #[Test]
    public function non_admin_is_blocked_on_registry_tab(): void
    {
        // Story 29.8 AC#3 — le retrait du plancher `app.customize` de
        // `modify-capability` n'AFFAIBLIT PAS la garde GLOBALE `server.admin` du
        // défaut diffusé : un acteur porteur de `app.customize` mais SANS
        // `server.admin` est refusé (403) DÈS le mount par guardAdmin(), qui garde
        // aussi openEdit/saveDefault/toggleLock. Le défaut reste inchangé.
        // (La fermeture au mount est également couverte par
        // AdminSettingsParcDefaultsPageTest::registry_tab_gate_blocks_mount_without_server_admin ;
        // ici on prouve en plus le point 29.8 : défaut intact après retrait du plancher.)
        $this->actAsNonAdmin();
        $cap = $this->capabilityWithKey('non_admin_blocked', 'HKCU', 'Software\\NAB', 'V', 'on');

        Livewire::test(self::REGISTRY_TAB)->assertStatus(403);

        self::assertSame('on', Capability::query()->find($cap->id)->default_value, 'défaut inchangé pour le non-admin');
        self::assertFalse((bool) Capability::query()->find($cap->id)->overrides_locked);
    }

    #[Test]
    public function non_locked_capability_default_still_saves_non_regression(): void
    {
        $this->actAsAdmin();
        $cap = $this->capabilityWithKey('show_hidden_files', 'HKCU', 'Software\\SHF', 'Hidden', 'on');
        // Pas de verrou amont.

        Livewire::test(self::REGISTRY_TAB)
            ->call('openEdit', $cap->id)
            ->set('formValue', 'off')
            ->call('saveDefault');

        self::assertSame('off', Capability::query()->find($cap->id)->default_value);
    }
}
