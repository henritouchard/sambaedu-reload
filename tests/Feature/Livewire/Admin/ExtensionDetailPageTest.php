<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Enums\ExtensionType;
use App\Models\Extension;
use App\Models\ExtensionAuditLog;
use App\Models\ExtensionSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 54.1 (AC1) / 54.2 (AC1-AC2) — page `/admin/extensions/{id}` : fiche
 * d'une extension.
 *
 * Couvre : les champs issus du MANIFEST (version, description, scopes,
 * dépendances, cible, visibilité), le rendu PROPRE des listes vides, la 404 sur
 * identifiant inconnu, la garde `server.admin`, et depuis 54.2 les gestes
 * « Intégrer » / « Désinstaller » dans `<x-slot:actions>`.
 */
class ExtensionDetailPageTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'pages::admin.extensions.[id].index';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $admin = User::query()->create([
            'login' => 'extension-detail-admin',
            'role' => 'autre',
            'is_active' => true,
        ]);
        $this->actingAs($admin);
    }

    /** @param list<string> $abilities */
    private function grant(array $abilities): void
    {
        Gate::before(fn ($user, string $ability) => in_array($ability, $abilities, true) ? true : null);
    }

    // ── Sécurité ──────────────────────────────────────────────────────────

    #[Test]
    public function mount_is_forbidden_without_server_admin(): void
    {
        $extension = Extension::factory()->fromBundled()->create();

        Livewire::test(self::PAGE, ['id' => $extension->id])->assertForbidden();
    }

    #[Test]
    public function the_route_carries_the_server_admin_middleware(): void
    {
        $route = Route::getRoutes()->getByName('admin.extensions.show');

        self::assertNotNull($route, 'la route admin.extensions.show est déclarée');
        self::assertContains('can:server.admin', $route->gatherMiddleware());
        self::assertSame('[0-9]+', $route->wheres['id'] ?? null, 'identifiant borné à un entier');
    }

    #[Test]
    public function an_unknown_id_returns_404(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::PAGE, ['id' => 999_999])->assertNotFound();
    }

    // ── AC1 — la fiche affiche ce que dit le manifest ─────────────────────

    #[Test]
    public function displays_the_manifest_driven_fields(): void
    {
        $this->grant(['server.admin']);

        $extension = Extension::factory()
            ->fromBundled()
            ->link('/doc')
            ->withManifestExtras(['profile', 'groups'], ['doc'])
            ->create([
                'name' => 'Documentation',
                'version' => '1.2.3',
                'publisher' => 'SambaEdu',
                'description' => 'Documentation publique SambaEdu.',
            ]);

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->assertOk()
            ->assertSee('Documentation')
            ->assertSee('1.2.3')
            ->assertSee('SambaEdu')
            ->assertSee('Documentation publique SambaEdu.')
            ->assertSee('/doc')
            ->assertSee('profile')
            ->assertSee('groups')
            ->assertSee('Lien')
            ->assertSee('Disponible')
            ->assertSee('Embarquée');
    }

    #[Test]
    public function empty_scopes_and_dependencies_render_cleanly(): void
    {
        $this->grant(['server.admin']);

        $extension = Extension::factory()->fromBundled()->create(['name' => 'Documentation']);

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->assertOk()
            ->assertSee('Aucun scope demandé.')
            ->assertSee('Aucune dépendance.');
    }

    #[Test]
    public function displays_the_business_roles_of_the_manifest_visibility(): void
    {
        $this->grant(['server.admin']);

        $extension = Extension::factory()->fromBundled()->create();
        $manifest = $extension->manifest;
        $manifest['visibility'] = ['roles' => ['admin', 'prof', 'eleve']];
        $extension->manifest = $manifest;
        $extension->save();

        $component = Livewire::test(self::PAGE, ['id' => $extension->id])->assertOk();

        self::assertSame(
            ['admin', 'prof', 'eleve'],
            $component->get('extension')['visibility_roles'],
        );
        $component->assertSee('eleve');
    }

    #[Test]
    public function an_integrated_extension_shows_its_state(): void
    {
        $this->grant(['server.admin']);

        $extension = Extension::factory()->fromBundled()->integrated()->create();

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->assertOk()
            ->assertSee('Intégrée');
    }

    // ── Story 54.2 — AC1 : bouton suit l'état ─────────────────────────────

    #[Test]
    public function shows_the_integrate_action_for_an_available_link_extension(): void
    {
        $this->grant(['server.admin']);
        $extension = Extension::factory()->fromBundled()->link()->create();

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->assertOk()
            ->assertSeeHtml('integrate-action')
            ->assertDontSeeHtml('uninstall-action');
    }

    #[Test]
    public function shows_the_uninstall_action_for_an_integrated_link_extension(): void
    {
        $this->grant(['server.admin']);
        $extension = Extension::factory()->fromBundled()->link()->integrated()->create();

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->assertOk()
            ->assertSeeHtml('uninstall-action')
            ->assertDontSeeHtml('integrate-action');
    }

    #[Test]
    public function shows_no_action_for_an_app_type_extension(): void
    {
        $this->grant(['server.admin']);
        $extension = Extension::factory()->fromBundled()->create(['type' => ExtensionType::App]);

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->assertOk()
            ->assertDontSeeHtml('integrate-action')
            ->assertDontSeeHtml('uninstall-action');
    }

    // ── AC1 — intégrer, direct, tracé ─────────────────────────────────────

    #[Test]
    public function integrate_mutates_reloads_and_dispatches_a_success_toast(): void
    {
        $this->grant(['server.admin']);
        $extension = Extension::factory()->fromBundled()->link()->create();

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->call('integrate')
            ->assertDispatched('toastMagic', status: 'success')
            ->assertSet('extension.status', 'integrated');

        self::assertSame('integrated', $extension->fresh()->status->value);
        self::assertSame(1, ExtensionAuditLog::query()->count());
    }

    #[Test]
    public function integrate_on_an_already_integrated_extension_is_a_noop_with_info_toast(): void
    {
        $this->grant(['server.admin']);
        $extension = Extension::factory()->fromBundled()->link()->integrated()->create();

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->call('integrate')
            ->assertDispatched('toastMagic', status: 'info');

        self::assertSame(0, ExtensionAuditLog::query()->count());
    }

    // ── AC2 — flux de la modale de désinstallation ────────────────────────

    #[Test]
    public function ask_uninstall_opens_the_confirmation_modal(): void
    {
        $this->grant(['server.admin']);
        $extension = Extension::factory()->fromBundled()->link()->integrated()->create();

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->call('askUninstall')
            ->assertSet('isUninstallOpen', true);
    }

    #[Test]
    public function confirm_uninstall_mutates_reloads_and_audits(): void
    {
        $this->grant(['server.admin']);
        $extension = Extension::factory()->fromBundled()->link()->integrated()->create();

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->call('askUninstall')
            ->call('confirmUninstall')
            ->assertSet('isUninstallOpen', false)
            ->assertDispatched('toastMagic', status: 'success')
            ->assertSet('extension.status', 'available');

        self::assertSame('available', $extension->fresh()->status->value);
        self::assertSame(1, ExtensionAuditLog::query()->count());
        self::assertSame(ExtensionAuditLog::ACTION_UNINSTALL, ExtensionAuditLog::query()->first()->action);
    }

    #[Test]
    public function closing_the_modal_without_confirming_changes_nothing(): void
    {
        $this->grant(['server.admin']);
        $extension = Extension::factory()->fromBundled()->link()->integrated()->create();

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->call('askUninstall')
            ->call('closeUninstall')
            ->assertSet('isUninstallOpen', false);

        self::assertSame('integrated', $extension->fresh()->status->value);
        self::assertSame(0, ExtensionAuditLog::query()->count());
    }

    // ── AC3 — fail-closed ───────────────────────────────────────────────

    #[Test]
    public function integrating_an_app_type_extension_is_refused_with_an_error_toast(): void
    {
        $this->grant(['server.admin']);
        $extension = Extension::factory()->fromBundled()->create(['type' => ExtensionType::App]);

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->call('integrate')
            ->assertDispatched('toastMagic', status: 'error');

        self::assertSame(0, ExtensionAuditLog::query()->count());
    }

    // ── AC1/AC2 — defense-in-depth : garde révoquée APRÈS mount() ────────

    #[Test]
    public function integrate_is_forbidden_when_the_ability_is_revoked_after_mount(): void
    {
        $extension = Extension::factory()->fromBundled()->link()->create();

        $allowed = true;
        Gate::before(function ($user, string $ability) use (&$allowed) {
            return ($ability === 'server.admin' && $allowed) ? true : null;
        });

        $component = Livewire::test(self::PAGE, ['id' => $extension->id])->assertOk();

        $allowed = false;
        $component->call('integrate')->assertForbidden();

        self::assertSame('available', $extension->fresh()->status->value);
    }

    #[Test]
    public function confirm_uninstall_is_forbidden_when_the_ability_is_revoked_after_mount(): void
    {
        $extension = Extension::factory()->fromBundled()->link()->integrated()->create();

        $allowed = true;
        Gate::before(function ($user, string $ability) use (&$allowed) {
            return ($ability === 'server.admin' && $allowed) ? true : null;
        });

        $component = Livewire::test(self::PAGE, ['id' => $extension->id])
            ->assertOk()
            ->call('askUninstall');

        $allowed = false;
        $component->call('confirmUninstall')->assertForbidden();

        self::assertSame('integrated', $extension->fresh()->status->value);
        self::assertSame(0, ExtensionAuditLog::query()->count());
    }

    // ══════════════════════════════════════════════════════════════════════
    // Story 56.1 — provenance sur la fiche (AC2) et 404 des masquées (AC3/AC4)
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function a_third_party_extension_shows_a_provenance_warning_naming_the_host(): void
    {
        $this->grant(['server.admin']);

        $remote = ExtensionSource::factory()->remote('https://depot.example.test/extensions')->create();
        $extension = Extension::factory()->create(['extension_source_id' => $remote->id]);

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->assertOk()
            ->assertSeeHtml('data-testid="third-party-alert"')
            ->assertSee('Source non officielle')
            ->assertSee('depot.example.test');
    }

    #[Test]
    public function an_official_extension_shows_no_provenance_warning(): void
    {
        $this->grant(['server.admin']);

        $extension = Extension::factory()->fromBundled()->create();

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->assertOk()
            ->assertDontSeeHtml('data-testid="third-party-alert"');
    }

    #[Test]
    public function integrating_a_third_party_link_from_the_detail_page_requires_confirmation(): void
    {
        $this->grant(['server.admin']);

        $remote = ExtensionSource::factory()->remote('https://depot.example.test/extensions')->create();
        $extension = Extension::factory()->link()->create(['extension_source_id' => $remote->id]);

        $component = Livewire::test(self::PAGE, ['id' => $extension->id])
            ->call('askIntegrate')
            ->assertSet('isThirdPartyWarningOpen', true);

        self::assertSame('available', $extension->fresh()->status->value);

        $component->call('confirmIntegrate')
            ->assertSet('isThirdPartyWarningOpen', false)
            ->assertDispatched('toastMagic');

        self::assertSame('integrated', $extension->fresh()->status->value);
    }

    #[Test]
    public function an_official_link_is_still_integrated_in_a_single_click_from_the_detail_page(): void
    {
        $this->grant(['server.admin']);

        $extension = Extension::factory()->fromBundled()->link()->create();

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->call('integrate')
            ->assertSet('isThirdPartyWarningOpen', false)
            ->assertDispatched('toastMagic');

        self::assertSame('integrated', $extension->fresh()->status->value);
    }

    #[Test]
    public function asking_to_integrate_an_official_link_never_opens_the_warning(): void
    {
        $this->grant(['server.admin']);

        $extension = Extension::factory()->fromBundled()->link()->create();

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->call('askIntegrate')
            ->assertSet('isThirdPartyWarningOpen', false);

        self::assertSame('integrated', $extension->fresh()->status->value);
    }

    #[Test]
    public function the_detail_of_an_available_extension_of_a_disabled_source_is_a_404(): void
    {
        // Une extension masquée de la bibliothèque ne doit pas rester
        // intégrable par son URL directe.
        $this->grant(['server.admin']);

        $disabled = ExtensionSource::factory()->remote()->disabled()->create();
        $extension = Extension::factory()->create(['extension_source_id' => $disabled->id]);

        Livewire::test(self::PAGE, ['id' => $extension->id])->assertNotFound();
    }

    #[Test]
    public function the_detail_of_an_available_extension_of_a_source_in_error_is_a_404(): void
    {
        $this->grant(['server.admin']);

        $broken = ExtensionSource::factory()->remote()->syncError()->create();
        $extension = Extension::factory()->create(['extension_source_id' => $broken->id]);

        Livewire::test(self::PAGE, ['id' => $extension->id])->assertNotFound();
    }

    #[Test]
    public function the_detail_of_an_integrated_extension_of_a_disabled_source_stays_reachable(): void
    {
        // Elle DOIT rester atteignable : c'est là que l'admin la désinstalle.
        $this->grant(['server.admin']);

        $disabled = ExtensionSource::factory()->remote()->disabled()->create();
        $extension = Extension::factory()->link()->integrated()->create(['extension_source_id' => $disabled->id]);

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->assertOk()
            ->assertSeeHtml('data-testid="uninstall-action"');
    }
}
