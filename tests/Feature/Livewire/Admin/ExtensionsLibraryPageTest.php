<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Enums\ExtensionStatus;
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
 * Story 54.1 (AC1) / 54.2 (AC1-AC3) — page `/admin/extensions` : bibliothèque.
 *
 * Couvre : liste alimentée par le registre multi-sources (nom, type, éditeur,
 * source, état), état vide propre, la garde `server.admin` (403 + middleware
 * de route), et depuis 54.2 les gestes « Intégrer » / « Désinstaller »
 * (boutons conditionnels, flux de modale, idempotence, defense-in-depth).
 */
class ExtensionsLibraryPageTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'pages::admin.extensions.index';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->admin = User::query()->create([
            'login' => 'extensions-admin',
            'role' => 'autre',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);
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
        Livewire::test(self::PAGE)->assertForbidden();
    }

    #[Test]
    public function the_route_carries_the_server_admin_middleware(): void
    {
        // Defense-in-depth : la garde `mount()` ne remplace pas le middleware.
        $route = Route::getRoutes()->getByName('admin.extensions');

        self::assertNotNull($route, 'la route admin.extensions est déclarée');
        self::assertContains('can:server.admin', $route->gatherMiddleware());
        self::assertContains('sambaedu.admin', $route->gatherMiddleware());
    }

    // ── AC1 — la bibliothèque liste le registre ───────────────────────────

    #[Test]
    public function lists_extensions_with_name_publisher_source_and_state(): void
    {
        $this->grant(['server.admin']);

        $source = ExtensionSource::factory()->bundled()->create();
        Extension::factory()->create([
            'extension_source_id' => $source->id,
            'key' => 'doc',
            'name' => 'Documentation',
            'publisher' => 'SambaEdu',
        ]);

        Livewire::test(self::PAGE)
            ->assertOk()
            ->assertSee('Documentation')
            ->assertSee('SambaEdu')
            ->assertSee(ExtensionSource::NAME_BUNDLED)
            ->assertSee('Lien')          // libellé du type `link`
            ->assertSee('Disponible');   // libellé de l'état
    }

    #[Test]
    public function an_integrated_extension_is_displayed_as_such(): void
    {
        $this->grant(['server.admin']);

        Extension::factory()->fromBundled()->integrated()->create(['name' => 'Visio']);

        Livewire::test(self::PAGE)
            ->assertOk()
            ->assertSee('Visio')
            ->assertSee('Intégrée');
    }

    #[Test]
    public function lists_extensions_coming_from_several_sources(): void
    {
        $this->grant(['server.admin']);

        $bundled = ExtensionSource::factory()->bundled()->create();
        $remote = ExtensionSource::factory()->remote()->create(['name' => 'Dépôt partenaire']);

        Extension::factory()->create(['extension_source_id' => $bundled->id, 'name' => 'Documentation']);
        Extension::factory()->create(['extension_source_id' => $remote->id, 'name' => 'Extension tierce']);

        Livewire::test(self::PAGE)
            ->assertOk()
            ->assertSee('Documentation')
            ->assertSee('Extension tierce')
            ->assertSee(ExtensionSource::NAME_BUNDLED)
            ->assertSee('Dépôt partenaire');
    }

    #[Test]
    public function the_component_exposes_the_rows_and_the_integrated_count(): void
    {
        $this->grant(['server.admin']);

        Extension::factory()->fromBundled()->create(['name' => 'A']);
        Extension::factory()->fromBundled()->integrated()->create(['name' => 'B']);

        $component = Livewire::test(self::PAGE)->assertOk();

        self::assertCount(2, $component->get('extensions'));
        self::assertSame(1, $component->instance()->integratedCount());
    }

    #[Test]
    public function an_empty_registry_renders_a_clean_empty_state(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::PAGE)
            ->assertOk()
            ->assertSee('Aucune extension')
            ->assertSet('extensions', []);
    }

    // ── Story 54.2 — AC1 : boutons suivent l'état ─────────────────────────

    #[Test]
    public function shows_the_integrate_button_for_an_available_link_extension(): void
    {
        $this->grant(['server.admin']);
        $extension = Extension::factory()->fromBundled()->link()->create();

        // ⚠️ La modale de confirmation est toujours présente dans le DOM (juste
        // fermée) : son titre/bouton contiennent le mot « Désinstaller » — on
        // vérifie donc la présence du BOUTON DE CARTE via son data-testid, pas
        // le texte brut.
        Livewire::test(self::PAGE)
            ->assertOk()
            ->assertSeeHtml("integrate-{$extension->id}")
            ->assertDontSeeHtml("uninstall-{$extension->id}");
    }

    #[Test]
    public function shows_the_uninstall_button_for_an_integrated_link_extension(): void
    {
        $this->grant(['server.admin']);
        $extension = Extension::factory()->fromBundled()->link()->integrated()->create();

        Livewire::test(self::PAGE)
            ->assertOk()
            ->assertSeeHtml("uninstall-{$extension->id}")
            ->assertDontSeeHtml("integrate-{$extension->id}");
    }

    #[Test]
    public function shows_no_action_button_for_an_app_type_extension(): void
    {
        $this->grant(['server.admin']);
        $extension = Extension::factory()->fromBundled()->create(['type' => ExtensionType::App]);

        Livewire::test(self::PAGE)
            ->assertOk()
            ->assertDontSeeHtml("integrate-{$extension->id}")
            ->assertDontSeeHtml("uninstall-{$extension->id}");
    }

    // ── AC1 — intégrer, direct, tracé ─────────────────────────────────────

    #[Test]
    public function integrate_mutates_and_dispatches_a_success_toast(): void
    {
        $this->grant(['server.admin']);
        $extension = Extension::factory()->fromBundled()->link()->create();

        Livewire::test(self::PAGE)
            ->call('integrate', $extension->id)
            ->assertDispatched('toastMagic', status: 'success');

        self::assertSame('integrated', $extension->fresh()->status->value);
        self::assertSame(1, ExtensionAuditLog::query()->count());
    }

    // ── AC3 — idempotence : intégrer une extension déjà intégrée ─────────

    #[Test]
    public function integrate_on_an_already_integrated_extension_is_a_noop_with_info_toast(): void
    {
        $this->grant(['server.admin']);
        $extension = Extension::factory()->fromBundled()->link()->integrated()->create();

        Livewire::test(self::PAGE)
            ->call('integrate', $extension->id)
            ->assertDispatched('toastMagic', status: 'info');

        self::assertSame(0, ExtensionAuditLog::query()->count());
    }

    // ── Correctifs de review 54.2 ─────────────────────────────────────────

    #[Test]
    public function confirming_uninstall_twice_is_a_clean_noop_and_never_reports_extension_zero(): void
    {
        // Review #1 : `confirmUninstall()` appelait `closeUninstall()`, qui remet
        // `uninstallTargetId` à 0. Le bouton restant cliquable tant que la 1re
        // réponse n'est pas revenue, un double-clic rejouait la 2e invocation
        // avec l'id 0 → toast d'ERREUR « Extension #0 introuvable », au lieu du
        // no-op propre exigé par l'AC3 (« clic rejoué, double-clic »).
        $this->grant(['server.admin']);
        $extension = Extension::factory()->fromBundled()->link()->integrated()->create();

        $component = Livewire::test(self::PAGE)
            ->call('askUninstall', $extension->id)
            ->call('confirmUninstall')
            ->assertDispatched('toastMagic', status: 'success');

        $component->call('confirmUninstall')
            ->assertDispatched('toastMagic', status: 'info')
            ->assertNotDispatched('toastMagic', status: 'error');

        self::assertSame(ExtensionStatus::Available, $extension->fresh()->status);
        self::assertSame(1, ExtensionAuditLog::query()->count(), 'une seule transition, une seule trace');
    }

    #[Test]
    public function a_noop_refreshes_the_stale_screen_instead_of_contradicting_it(): void
    {
        // Review #2 : le no-op n'arrive QUE sur un écran périmé (second admin,
        // onglet dupliqué). Toaster « déjà intégrée » sans recharger laissait la
        // carte afficher « Disponible » + le bouton « Intégrer » — le message de
        // l'application et son écran se contredisaient.
        $this->grant(['server.admin']);
        $extension = Extension::factory()->fromBundled()->link()->create();

        $component = Livewire::test(self::PAGE)
            ->assertSeeHtml('data-testid="integrate-'.$extension->id.'"');

        // Un autre admin intègre, hors du composant : l'écran est désormais faux.
        $fresh = $extension->fresh();
        $fresh->status = ExtensionStatus::Integrated;
        $fresh->save();

        $component->call('integrate', $extension->id)
            ->assertDispatched('toastMagic', status: 'info')
            ->assertSeeHtml('data-testid="uninstall-'.$extension->id.'"')
            ->assertDontSeeHtml('data-testid="integrate-'.$extension->id.'"');
    }

    // ── AC2 — flux de la modale de désinstallation ────────────────────────

    #[Test]
    public function ask_uninstall_opens_the_confirmation_modal(): void
    {
        $this->grant(['server.admin']);
        $extension = Extension::factory()->fromBundled()->link()->integrated()->create(['name' => 'Documentation']);

        Livewire::test(self::PAGE)
            ->call('askUninstall', $extension->id)
            ->assertSet('isUninstallOpen', true)
            ->assertSet('uninstallTargetId', $extension->id)
            ->assertSet('uninstallTargetName', 'Documentation');
    }

    #[Test]
    public function confirm_uninstall_mutates_and_audits(): void
    {
        $this->grant(['server.admin']);
        $extension = Extension::factory()->fromBundled()->link()->integrated()->create();

        Livewire::test(self::PAGE)
            ->call('askUninstall', $extension->id)
            ->call('confirmUninstall')
            ->assertSet('isUninstallOpen', false)
            ->assertDispatched('toastMagic', status: 'success');

        self::assertSame('available', $extension->fresh()->status->value);
        self::assertSame(1, ExtensionAuditLog::query()->count());
        self::assertSame(ExtensionAuditLog::ACTION_UNINSTALL, ExtensionAuditLog::query()->first()->action);
    }

    #[Test]
    public function closing_the_modal_without_confirming_changes_nothing(): void
    {
        $this->grant(['server.admin']);
        $extension = Extension::factory()->fromBundled()->link()->integrated()->create();

        Livewire::test(self::PAGE)
            ->call('askUninstall', $extension->id)
            ->call('closeUninstall')
            ->assertSet('isUninstallOpen', false);

        self::assertSame('integrated', $extension->fresh()->status->value);
        self::assertSame(0, ExtensionAuditLog::query()->count());
    }

    // ── AC3 — fail-closed : type non pris en charge, id inconnu ──────────

    #[Test]
    public function integrating_an_app_type_extension_is_refused_with_an_error_toast(): void
    {
        $this->grant(['server.admin']);
        $extension = Extension::factory()->fromBundled()->create(['type' => ExtensionType::App]);

        Livewire::test(self::PAGE)
            ->call('integrate', $extension->id)
            ->assertDispatched('toastMagic', status: 'error');

        self::assertSame(0, ExtensionAuditLog::query()->count());
    }

    #[Test]
    public function integrating_an_unknown_id_is_refused_without_a_500(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::PAGE)
            ->call('integrate', 999_999)
            ->assertOk()
            ->assertDispatched('toastMagic', status: 'error');
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

        $component = Livewire::test(self::PAGE)->assertOk();

        $allowed = false;
        $component->call('integrate', $extension->id)->assertForbidden();

        self::assertSame('available', $extension->fresh()->status->value);
    }

    #[Test]
    public function ask_uninstall_is_forbidden_when_the_ability_is_revoked_after_mount(): void
    {
        $extension = Extension::factory()->fromBundled()->link()->integrated()->create();

        $allowed = true;
        Gate::before(function ($user, string $ability) use (&$allowed) {
            return ($ability === 'server.admin' && $allowed) ? true : null;
        });

        $component = Livewire::test(self::PAGE)->assertOk()->assertSet('isUninstallOpen', false);

        $allowed = false;
        $component->call('askUninstall', $extension->id)->assertForbidden();

        // Le composant n'a pas dépassé la garde : l'état monté avant révocation
        // reste celui observable (le 403 a interrompu la requête avant toute
        // mutation de $isUninstallOpen).
        self::assertSame('integrated', $extension->fresh()->status->value);
    }

    #[Test]
    public function confirm_uninstall_is_forbidden_when_the_ability_is_revoked_after_mount(): void
    {
        $extension = Extension::factory()->fromBundled()->link()->integrated()->create();

        $allowed = true;
        Gate::before(function ($user, string $ability) use (&$allowed) {
            return ($ability === 'server.admin' && $allowed) ? true : null;
        });

        $component = Livewire::test(self::PAGE)
            ->assertOk()
            ->call('askUninstall', $extension->id);

        $allowed = false;
        $component->call('confirmUninstall')->assertForbidden();

        self::assertSame('integrated', $extension->fresh()->status->value);
        self::assertSame(0, ExtensionAuditLog::query()->count());
    }

    // ══════════════════════════════════════════════════════════════════════
    // Story 56.1 — provenance impossible à ignorer (AC2) et filtrage (AC3/AC4)
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function every_card_carries_an_unambiguous_provenance_badge(): void
    {
        // FR4/UX-DR4 : icône + libellé, jamais une couleur seule.
        $this->grant(['server.admin']);

        $bundled = ExtensionSource::factory()->bundled()->create();
        $remote = ExtensionSource::factory()->remote()->create(['name' => 'Dépôt partenaire']);

        $official = Extension::factory()->create(['extension_source_id' => $bundled->id, 'name' => 'Documentation']);
        $thirdParty = Extension::factory()->create(['extension_source_id' => $remote->id, 'name' => 'Extension tierce']);

        Livewire::test(self::PAGE)
            ->assertOk()
            ->assertSeeHtml('data-testid="official-badge-'.$official->id.'"')
            ->assertSeeHtml('data-testid="third-party-badge-'.$thirdParty->id.'"')
            ->assertSee('Tierce')
            ->assertSee('Officielle');
    }

    #[Test]
    public function integrating_a_third_party_link_goes_through_the_warning_modal(): void
    {
        $this->grant(['server.admin']);

        $remote = ExtensionSource::factory()->remote('https://depot.example.test/extensions')->create();
        $extension = Extension::factory()->link()->create([
            'extension_source_id' => $remote->id,
            'name' => 'Extension tierce',
        ]);

        Livewire::test(self::PAGE)
            ->call('askIntegrate', $extension->id)
            ->assertSet('isThirdPartyWarningOpen', true)
            ->assertSet('integrateTargetName', 'Extension tierce')
            ->assertSet('integrateTargetHost', 'depot.example.test')
            ->assertSee('Source non officielle');

        self::assertSame('available', $extension->fresh()->status->value, 'ouvrir l\'avertissement n\'intègre rien');
    }

    #[Test]
    public function confirming_the_warning_integrates_the_third_party_extension(): void
    {
        $this->grant(['server.admin']);

        $remote = ExtensionSource::factory()->remote()->create();
        $extension = Extension::factory()->link()->create(['extension_source_id' => $remote->id]);

        Livewire::test(self::PAGE)
            ->call('askIntegrate', $extension->id)
            ->call('confirmIntegrate')
            ->assertSet('isThirdPartyWarningOpen', false)
            ->assertDispatched('toastMagic');

        self::assertSame('integrated', $extension->fresh()->status->value);
        self::assertSame(1, ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_INTEGRATE)->count());
    }

    #[Test]
    public function a_double_click_on_the_warning_confirmation_is_a_clean_no_op(): void
    {
        // Piège review 54.2 #1 reproduit : la cible n'est PAS remise à zéro
        // avant l'appel, sinon le second clic parle de l'extension #0.
        $this->grant(['server.admin']);

        $remote = ExtensionSource::factory()->remote()->create();
        $extension = Extension::factory()->link()->create(['extension_source_id' => $remote->id]);

        Livewire::test(self::PAGE)
            ->call('askIntegrate', $extension->id)
            ->call('confirmIntegrate')
            ->call('confirmIntegrate')
            ->assertDispatched('toastMagic');

        self::assertSame('integrated', $extension->fresh()->status->value);
        self::assertSame(
            1,
            ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_INTEGRATE)->count(),
            'le rejeu ne fabrique pas une seconde ligne d\'audit',
        );
    }

    #[Test]
    public function an_official_extension_is_still_integrated_in_a_single_click(): void
    {
        // Comportement 54.2 INCHANGÉ pour la source officielle.
        $this->grant(['server.admin']);

        $extension = Extension::factory()->fromBundled()->link()->create();

        Livewire::test(self::PAGE)
            ->call('integrate', $extension->id)
            ->assertSet('isThirdPartyWarningOpen', false)
            ->assertDispatched('toastMagic');

        self::assertSame('integrated', $extension->fresh()->status->value);
    }

    #[Test]
    public function asking_to_integrate_an_official_extension_never_opens_the_warning(): void
    {
        $this->grant(['server.admin']);

        $extension = Extension::factory()->fromBundled()->link()->create();

        Livewire::test(self::PAGE)
            ->call('askIntegrate', $extension->id)
            ->assertSet('isThirdPartyWarningOpen', false);

        self::assertSame('integrated', $extension->fresh()->status->value);
    }

    #[Test]
    public function asking_to_integrate_an_unknown_extension_never_produces_a_500(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::PAGE)
            ->call('askIntegrate', 999_999)
            ->assertOk()
            ->assertSet('isThirdPartyWarningOpen', false)
            ->assertDispatched('toastMagic', status: 'error');
    }

    #[Test]
    public function the_library_hides_available_extensions_of_a_disabled_source(): void
    {
        $this->grant(['server.admin']);

        $disabled = ExtensionSource::factory()->remote()->disabled()->create();
        Extension::factory()->create(['extension_source_id' => $disabled->id, 'name' => 'Masquée']);

        $integrated = Extension::factory()->integrated()->create([
            'extension_source_id' => $disabled->id,
            'name' => 'Conservée',
        ]);

        Livewire::test(self::PAGE)
            ->assertOk()
            ->assertDontSee('Masquée')
            ->assertSee('Conservée')
            ->assertSeeHtml('data-testid="source-disabled-badge-'.$integrated->id.'"');
    }

    #[Test]
    public function the_library_hides_available_extensions_of_a_source_in_error(): void
    {
        $this->grant(['server.admin']);

        $broken = ExtensionSource::factory()->remote()->syncError()->create();
        Extension::factory()->create(['extension_source_id' => $broken->id, 'name' => 'Masquée']);

        Livewire::test(self::PAGE)
            ->assertOk()
            ->assertDontSee('Masquée');
    }

    #[Test]
    public function the_library_offers_a_link_to_the_sources_page(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::PAGE)
            ->assertOk()
            ->assertSeeHtml('data-testid="manage-sources"')
            ->assertSee('Gérer les sources');
    }
}
