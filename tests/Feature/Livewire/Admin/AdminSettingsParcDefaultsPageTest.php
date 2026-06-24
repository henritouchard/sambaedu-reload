<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\AgentTool;
use App\Models\Application;
use App\Models\Capability;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Tools\AgentToolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 27.17 — page /admin/settings/parc-defaults (couche Broadcast consolidée).
 *
 * Couvre : accès gardé server.admin (page + onglets), navigation par onglets,
 * et l'onglet « Applications » net-new (toggle `is_parc_default`). Le Gate
 * server.admin est forcé via Gate::before (autorisé) ou laissé refuser (403).
 *
 * Rendu via <x-organisms.page> (@vite) → withoutVite().
 */
class AdminSettingsParcDefaultsPageTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'pages::admin.settings.parc-defaults.index';
    private const APPS_TAB = 'pages::admin.settings.parc-defaults._partials.apps-tab';
    private const REGISTRY_TAB = 'pages::admin.settings.parc-defaults._partials.registry-tab';
    private const TOOLS_TAB = 'pages::admin.settings.parc-defaults._partials.tools-tab';

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->withoutVite();
        WorkstationGroupObserver::disableSync();

        // Catalogue capacités vierge (iso AdminSettingsCapabilitiesPageTest) :
        // les migrations/seeders peuvent pré-remplir des capacités → on repart
        // d'une table vide pour des clés déterministes dans les tests registry.
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

    #[Test]
    public function page_gate_blocks_mount_without_server_admin(): void
    {
        $this->actAsNonAdmin();

        Livewire::test(self::PAGE)->assertStatus(403);
    }

    #[Test]
    public function page_mounts_for_admin_with_default_tab(): void
    {
        $this->actAsAdmin();

        Livewire::test(self::PAGE)
            ->assertStatus(200)
            ->assertSet('tab', 'wallpaper');
    }

    #[Test]
    public function set_tab_switches_between_known_tabs(): void
    {
        $this->actAsAdmin();

        Livewire::test(self::PAGE)
            ->call('setTab', 'apps')
            ->assertSet('tab', 'apps')
            ->call('setTab', 'tools')
            ->assertSet('tab', 'tools')
            ->call('setTab', 'registry')
            ->assertSet('tab', 'registry');
    }

    #[Test]
    public function set_tab_ignores_unknown_tab(): void
    {
        $this->actAsAdmin();

        Livewire::test(self::PAGE)
            ->call('setTab', 'wallpaper')
            ->call('setTab', 'overlay') // 27.18, pas encore présent
            ->assertSet('tab', 'wallpaper');
    }

    #[Test]
    public function unknown_url_tab_falls_back_to_wallpaper(): void
    {
        $this->actAsAdmin();

        Livewire::withQueryParams(['tab' => 'bogus'])
            ->test(self::PAGE)
            ->assertSet('tab', 'wallpaper');
    }

    // ── Onglet Applications (net-new is_parc_default) ─────────────────────────

    #[Test]
    public function apps_tab_gate_blocks_mount_without_server_admin(): void
    {
        $this->actAsNonAdmin();

        Livewire::test(self::APPS_TAB)->assertStatus(403);
    }

    #[Test]
    public function apps_tab_marks_application_as_parc_default(): void
    {
        $this->actAsAdmin();
        $app = Application::create(['app_id' => '7za', 'name' => '7-Zip CLI']);
        self::assertFalse((bool) $app->is_parc_default);

        Livewire::test(self::APPS_TAB)
            ->call('setParcDefault', $app->id, true);

        self::assertTrue((bool) Application::query()->find($app->id)->is_parc_default);
    }

    #[Test]
    public function apps_tab_unmarks_application(): void
    {
        $this->actAsAdmin();
        $app = Application::create(['app_id' => 'nircmd', 'name' => 'NirCmd', 'is_parc_default' => true]);

        Livewire::test(self::APPS_TAB)
            ->call('setParcDefault', $app->id, false);

        self::assertFalse((bool) Application::query()->find($app->id)->is_parc_default);
    }

    #[Test]
    public function apps_tab_lists_current_defaults(): void
    {
        $this->actAsAdmin();
        Application::create(['app_id' => 'a', 'name' => 'AppA', 'is_parc_default' => true]);
        Application::create(['app_id' => 'b', 'name' => 'AppB', 'is_parc_default' => false]);

        $defaults = Livewire::test(self::APPS_TAB)->instance()->defaults();

        self::assertCount(1, $defaults, 'seules les apps défaut parc sont listées');
        self::assertSame('a', $defaults->first()->app_id);
    }

    // ── Onglet Registre/capacités (réutilise le flow saveDefault) ─────────────

    #[Test]
    public function registry_tab_gate_blocks_mount_without_server_admin(): void
    {
        $this->actAsNonAdmin();

        Livewire::test(self::REGISTRY_TAB)->assertStatus(403);
    }

    #[Test]
    public function registry_tab_renders_for_admin(): void
    {
        $this->actAsAdmin();

        Livewire::test(self::REGISTRY_TAB)->assertStatus(200);
    }

    #[Test]
    public function registry_tab_save_default_persists_valid_value(): void
    {
        $this->actAsAdmin();
        $cap = $this->makeToggleCapability('remote_desktop_enabled', 'on');

        // Flow iso /admin/settings/capabilities : ouvrir l'édition, poser une
        // valeur VALIDE (option du domaine fermé), enregistrer.
        Livewire::test(self::REGISTRY_TAB)
            ->call('openEdit', $cap->id)
            ->set('formValue', 'off')
            ->call('saveDefault');

        self::assertSame('off', Capability::query()->find($cap->id)->default_value);
    }

    #[Test]
    public function registry_tab_toggle_lock_flips_overrides_locked(): void
    {
        $this->actAsAdmin();
        $cap = $this->makeToggleCapability('show_hidden_files', 'on');
        self::assertFalse((bool) $cap->overrides_locked);

        Livewire::test(self::REGISTRY_TAB)->call('toggleLock', $cap->id);

        self::assertTrue((bool) Capability::query()->find($cap->id)->overrides_locked);
    }

    /**
     * Porté depuis AdminSettingsCapabilitiesPageTest::invalid_default_is_rejected :
     * une valeur HORS options (domaine fermé) doit être rejetée (erreur formValue)
     * et NE PAS persister.
     */
    #[Test]
    public function registry_tab_rejects_invalid_default(): void
    {
        $this->actAsAdmin();
        $cap = $this->makeToggleCapability('show_file_extensions', 'on');

        Livewire::test(self::REGISTRY_TAB)
            ->call('openEdit', $cap->id)
            ->set('formValue', 'invalid-value')
            ->call('saveDefault')
            ->assertHasErrors('formValue');

        self::assertSame('on', Capability::query()->find($cap->id)->default_value, 'défaut inchangé');
    }

    /**
     * Porté depuis AdminSettingsCapabilitiesPageTest::warning_default_requires_acknowledgement :
     * une capacité portant un warning exige l'acquittement (warningAcknowledged)
     * sans quoi le défaut NE PERSISTE PAS.
     */
    #[Test]
    public function registry_tab_warning_default_requires_acknowledgement(): void
    {
        $this->actAsAdmin();
        $cap = $this->makeToggleCapability('uac_enabled', 'on', warning: 'Sécurité.');

        Livewire::test(self::REGISTRY_TAB)
            ->call('openEdit', $cap->id)
            ->set('formValue', 'off')
            ->call('saveDefault')
            ->assertHasErrors('warningAcknowledged');

        self::assertSame('on', Capability::query()->find($cap->id)->default_value);
    }

    // ── Onglet Outils agent (canal séparé — manifest) ─────────────────────────

    #[Test]
    public function tools_tab_gate_blocks_mount_without_server_admin(): void
    {
        $this->actAsNonAdmin();

        Livewire::test(self::TOOLS_TAB)->assertStatus(403);
    }

    #[Test]
    public function tools_tab_toggle_flips_enabled(): void
    {
        $this->actAsAdmin();
        // `toggle` ne décompresse rien (pas de dépendance ext-zip) : on crée
        // directement l'entrée catalogue en base via le modèle.
        $tool = AgentTool::query()->create([
            'key' => AgentToolService::RAINMETER_KEY,
            'name' => 'Rainmeter (overlay)',
            'filename' => 'sambaedu-rainmeter-0.1.zip',
            'sha256' => str_repeat('a', 64),
            'size' => 1024,
            'enabled' => false,
            'uploaded_at' => now(),
        ]);

        Livewire::test(self::TOOLS_TAB)->call('toggle');

        self::assertTrue((bool) AgentTool::query()->find($tool->id)->enabled);
    }

    /**
     * Crée une capacité « toggle » (domaine fermé on/off) via la factory —
     * réutilise le pattern de AdminSettingsCapabilitiesPageTest.
     */
    private function makeToggleCapability(string $key, string $default = 'on', ?string $warning = null): Capability
    {
        return Capability::factory()->create([
            'key' => $key,
            'label' => ucfirst(str_replace('_', ' ', $key)),
            'default_value' => $default,
            'warning' => $warning,
        ]);
    }
}
