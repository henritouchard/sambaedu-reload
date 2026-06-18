<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\Capability;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 27.12 (AC6, AC9) — page serveur /admin/settings/capabilities.
 *
 * Édite la VALEUR PAR DÉFAUT diffusée (`capabilities.default_value`) + le gel
 * (`overrides_locked`). Couvre : édition du défaut, validation serveur,
 * confirmation du warning, gel/dégel, Gate server.admin.
 *
 * Rendu via <x-organisms.page> (@vite) → withoutVite(). Le Gate server.admin est
 * forcé via Gate::before (autorisé) ou laissé refuser (403).
 */
class AdminSettingsCapabilitiesPageTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::admin.settings.capabilities.index';

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

    private function actAsAdmin(): void
    {
        $user = Mockery::mock(
            \Illuminate\Contracts\Auth\Authenticatable::class,
            \Illuminate\Contracts\Auth\Access\Authorizable::class,
        );
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        $user->shouldReceive('getAuthIdentifierName')->andReturn('id');
        $user->shouldReceive('getAuthPassword')->andReturn('');
        $user->shouldReceive('getRememberToken')->andReturn('');
        $user->shouldReceive('setRememberToken');
        $user->shouldReceive('getRememberTokenName')->andReturn('');
        $user->shouldReceive('can')->andReturn(true);
        $this->actingAs($user);
        Gate::before(fn () => true);
    }

    private function actAsNonAdmin(): void
    {
        $user = Mockery::mock(
            \Illuminate\Contracts\Auth\Authenticatable::class,
            \Illuminate\Contracts\Auth\Access\Authorizable::class,
        );
        $user->shouldReceive('getAuthIdentifier')->andReturn(2);
        $user->shouldReceive('getAuthIdentifierName')->andReturn('id');
        $user->shouldReceive('getAuthPassword')->andReturn('');
        $user->shouldReceive('getRememberToken')->andReturn('');
        $user->shouldReceive('setRememberToken');
        $user->shouldReceive('getRememberTokenName')->andReturn('');
        $user->shouldReceive('can')->andReturn(false);
        $this->actingAs($user);
        // Pas de Gate::before → Gate::allows('server.admin') renvoie false.
    }

    private function makeToggleCapability(string $key, string $default = 'on', ?string $warning = null): Capability
    {
        return Capability::factory()->create([
            'key' => $key,
            'label' => ucfirst(str_replace('_', ' ', $key)),
            'default_value' => $default,
            'warning' => $warning,
        ]);
    }

    #[Test]
    public function gate_blocks_mount_without_server_admin(): void
    {
        $this->actAsNonAdmin();

        Livewire::test(self::COMPONENT)->assertStatus(403);
    }

    #[Test]
    public function save_default_updates_capability_default_value(): void
    {
        $this->actAsAdmin();
        $cap = $this->makeToggleCapability('remote_desktop_enabled', 'on');

        Livewire::test(self::COMPONENT)
            ->call('openEdit', $cap->id)
            ->set('formValue', 'off')
            ->call('saveDefault');

        self::assertSame('off', Capability::query()->find($cap->id)->default_value);
    }

    #[Test]
    public function invalid_default_is_rejected(): void
    {
        $this->actAsAdmin();
        $cap = $this->makeToggleCapability('show_file_extensions', 'on');

        Livewire::test(self::COMPONENT)
            ->call('openEdit', $cap->id)
            ->set('formValue', 'invalid-value')
            ->call('saveDefault')
            ->assertHasErrors('formValue');

        self::assertSame('on', Capability::query()->find($cap->id)->default_value, 'défaut inchangé');
    }

    #[Test]
    public function warning_default_requires_acknowledgement(): void
    {
        $this->actAsAdmin();
        $cap = $this->makeToggleCapability('uac_enabled', 'on', warning: 'Sécurité.');

        Livewire::test(self::COMPONENT)
            ->call('openEdit', $cap->id)
            ->set('formValue', 'off')
            ->call('saveDefault')
            ->assertHasErrors('warningAcknowledged');

        self::assertSame('on', Capability::query()->find($cap->id)->default_value);
    }

    #[Test]
    public function toggle_lock_flips_overrides_locked(): void
    {
        $this->actAsAdmin();
        $cap = $this->makeToggleCapability('show_hidden_files');
        self::assertFalse((bool) $cap->overrides_locked);

        Livewire::test(self::COMPONENT)->call('toggleLock', $cap->id);

        self::assertTrue((bool) Capability::query()->find($cap->id)->overrides_locked);
    }

    #[Test]
    public function page_lists_full_catalog(): void
    {
        $this->actAsAdmin();
        $this->makeToggleCapability('cap_one');
        $this->makeToggleCapability('cap_two');

        $list = Livewire::test(self::COMPONENT)->instance()->capabilities();

        self::assertCount(2, $list, 'la page serveur liste le catalogue complet');
    }
}
