<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Jobs\RunExtensionOperationJob;
use App\Models\Extension;
use App\Models\ExtensionInstallRun;
use App\Models\ExtensionSource;
use App\Models\User;
use App\Services\Extensions\ExtensionInstallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 56.3 (AC1, AC2, AC4, AC5, AC6) — Le cycle `app` dans les DEUX pages
 * admin : bibliothèque et fiche.
 *
 * Fichier NOUVEAU, volontairement : les suites 54.1/54.2/56.1
 * ({@see ExtensionsLibraryPageTest}, {@see ExtensionDetailPageTest}) restent
 * VERBATIM, sans une assertion touchée. Qu'elles passent inchangées EST la
 * preuve de non-régression du cycle `link` demandée par l'AC6 — l'affaiblir ou
 * la réécrire ferait disparaître la preuve avec le risque.
 *
 * ⚠️ `Queue::fake()` partout : `phpunit.xml` force `QUEUE_CONNECTION=sync`, un
 * dispatch réel exécuterait le moteur INLINE dans le test de page — on
 * testerait alors l'installation, pas l'interface.
 */
class ExtensionAppOperationsPageTest extends TestCase
{
    use RefreshDatabase;

    private const LIBRARY = 'pages::admin.extensions.index';

    private const DETAIL = 'pages::admin.extensions.[id].index';

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

    private function remoteSource(bool $official = false): ExtensionSource
    {
        $source = ExtensionSource::factory()->remote('https://depot.example.test/extensions')->create();
        $source->is_official = $official;
        $source->save();

        return $source;
    }

    /** Une `app` proposable : bloc `install` valide, source active. */
    private function installableApp(?ExtensionSource $source = null, array $scopes = []): Extension
    {
        $source ??= $this->remoteSource();

        return Extension::factory()
            ->for($source, 'source')
            ->app()
            ->withInstallBlock()
            ->withManifestExtras($scopes, [])
            ->create(['key' => 'hello', 'name' => 'Hello']);
    }

    private function installedApp(?ExtensionSource $source = null): Extension
    {
        $source ??= $this->remoteSource();

        return Extension::factory()
            ->for($source, 'source')
            ->app()
            ->withInstallBlock()
            ->installed(8600, '1.0.0')
            ->create(['key' => 'hello', 'name' => 'Hello', 'version' => '1.0.0']);
    }

    // =====================================================================
    // AC1 — la modale de confirmation
    // =====================================================================

    #[Test]
    public function an_installable_app_offers_the_integrate_button_in_the_library(): void
    {
        $this->grant(['server.admin']);
        $extension = $this->installableApp();

        Livewire::test(self::LIBRARY)
            ->assertOk()
            ->assertSeeHtml('data-testid="app-install-'.$extension->id.'"');
    }

    #[Test]
    public function an_app_without_a_usable_install_block_offers_no_button(): void
    {
        // « L'UI ne propose jamais ce que le moteur refusera. »
        $this->grant(['server.admin']);
        $extension = Extension::factory()->for($this->remoteSource(), 'source')->app()->create(['key' => 'sans-install']);

        Livewire::test(self::LIBRARY)
            ->assertOk()
            ->assertDontSeeHtml('data-testid="app-install-'.$extension->id.'"');
    }

    #[Test]
    public function an_app_of_a_source_in_error_is_not_offered(): void
    {
        $this->grant(['server.admin']);

        $broken = ExtensionSource::factory()->remote()->syncError()->create();
        $extension = Extension::factory()->for($broken, 'source')->app()->withInstallBlock()->integrated()
            ->create(['key' => 'gelée']);

        Livewire::test(self::LIBRARY)
            ->assertOk()
            ->assertDontSeeHtml('data-testid="app-install-'.$extension->id.'"');
    }

    #[Test]
    public function asking_to_install_opens_the_modal_and_starts_nothing(): void
    {
        Queue::fake();
        $this->grant(['server.admin']);
        $extension = $this->installableApp(scopes: ['profile', 'users:read']);

        Livewire::test(self::LIBRARY)
            ->call('askAppOperation', $extension->id, 'install')
            ->assertSet('isAppOperationOpen', true)
            ->assertSet('appOperation', 'install')
            ->assertSet('appTargetId', $extension->id)
            ->assertSee('Intégrer l\'extension')
            // Provenance ET scopes : les deux sont récapitulés.
            ->assertSeeHtml('data-testid="app-operation-host"')
            ->assertSee('depot.example.test')
            ->assertSeeHtml('data-testid="app-operation-scopes"')
            ->assertSee('users:read');

        self::assertSame(0, ExtensionInstallRun::query()->count(), 'ouvrir la modale ne lance rien');
        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_modal_of_a_third_party_app_carries_the_verbatim_warning(): void
    {
        $this->grant(['server.admin']);
        $extension = $this->installableApp();

        Livewire::test(self::LIBRARY)
            ->call('askAppOperation', $extension->id, 'install')
            ->assertSeeHtml('data-testid="app-operation-warning"')
            ->assertSee('Source non officielle : depot.example.test')
            ->assertSee('vous installez sous votre responsabilité')
            // ⚠️ `escape: false` : ce texte est LITTÉRAL dans le gabarit (Blade
            // ne l'échappe pas), alors qu'`assertSee` échappe son aiguille par
            // défaut — l'apostrophe deviendrait `&#039;` et l'assertion
            // passerait à vide.
            ->assertSee('ni SambaEdu ni votre académie n\'ont audité ce que fait cette extension', escape: false);
    }

    #[Test]
    public function an_official_app_goes_through_the_modal_too_but_without_the_warning(): void
    {
        // Contrairement au type `link`, une `app` officielle N'A PAS de chemin
        // un-clic : installer des composants système mérite confirmation, et
        // les scopes doivent être vus.
        $this->grant(['server.admin']);
        $extension = $this->installableApp($this->remoteSource(official: true));

        Livewire::test(self::LIBRARY)
            ->call('askAppOperation', $extension->id, 'install')
            ->assertSet('isAppOperationOpen', true)
            ->assertSeeHtml('data-testid="app-operation-official"')
            ->assertDontSeeHtml('data-testid="app-operation-warning"');

        self::assertSame(0, ExtensionInstallRun::query()->count());
    }

    #[Test]
    public function an_app_with_no_scopes_says_so_instead_of_showing_an_empty_list(): void
    {
        $this->grant(['server.admin']);
        $extension = $this->installableApp();

        Livewire::test(self::LIBRARY)
            ->call('askAppOperation', $extension->id, 'install')
            ->assertSeeHtml('data-testid="app-operation-no-scopes"')
            ->assertSee('Aucun scope demandé');
    }

    #[Test]
    public function cancelling_the_modal_starts_nothing(): void
    {
        Queue::fake();
        $this->grant(['server.admin']);
        $extension = $this->installableApp();

        Livewire::test(self::LIBRARY)
            ->call('askAppOperation', $extension->id, 'install')
            ->call('closeAppOperation')
            ->assertSet('isAppOperationOpen', false)
            ->assertSet('appTargetId', 0);

        self::assertSame(0, ExtensionInstallRun::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function asking_an_operation_on_an_unknown_extension_never_produces_a_500(): void
    {
        $this->grant(['server.admin']);

        Livewire::test(self::LIBRARY)
            ->call('askAppOperation', 999_999, 'install')
            ->assertOk()
            ->assertSet('isAppOperationOpen', false)
            ->assertDispatched('toastMagic', status: 'error');
    }

    #[Test]
    public function an_unrecognised_operation_is_refused(): void
    {
        $this->grant(['server.admin']);
        $extension = $this->installableApp();

        Livewire::test(self::LIBRARY)
            ->call('askAppOperation', $extension->id, 'reboot')
            ->assertSet('isAppOperationOpen', false)
            ->assertDispatched('toastMagic', status: 'error');
    }

    // =====================================================================
    // AC2 — confirmer crée le run et met le Job en file
    // =====================================================================

    #[Test]
    public function confirming_creates_a_pending_run_and_queues_the_job(): void
    {
        Queue::fake();
        $this->grant(['server.admin']);
        $extension = $this->installableApp();

        Livewire::test(self::LIBRARY)
            ->call('askAppOperation', $extension->id, 'install')
            ->call('confirmAppOperation')
            ->assertSet('isAppOperationOpen', false)
            ->assertDispatched('toastMagic', status: 'info');

        $run = ExtensionInstallRun::query()->firstOrFail();
        self::assertSame(ExtensionInstallRun::STATUS_PENDING, $run->status);
        self::assertSame(ExtensionInstallRun::OPERATION_INSTALL, $run->operation);
        self::assertSame($this->admin->id, $run->requested_by_user_id);
        Queue::assertPushedOn('default', RunExtensionOperationJob::class);
    }

    #[Test]
    public function a_double_click_on_the_confirmation_creates_a_single_run(): void
    {
        // Piège review 54.2 #1 reproduit : la cible n'est PAS remise à zéro
        // avant l'appel, sinon le second clic parlerait de l'extension #0.
        Queue::fake();
        $this->grant(['server.admin']);
        $extension = $this->installableApp();

        Livewire::test(self::LIBRARY)
            ->call('askAppOperation', $extension->id, 'install')
            ->call('confirmAppOperation')
            ->call('confirmAppOperation')
            ->assertDispatched('toastMagic', status: 'error');

        self::assertSame(1, ExtensionInstallRun::query()->count());
        Queue::assertPushed(RunExtensionOperationJob::class, 1);
    }

    #[Test]
    public function confirming_without_having_opened_the_modal_does_nothing(): void
    {
        Queue::fake();
        $this->grant(['server.admin']);
        $this->installableApp();

        Livewire::test(self::LIBRARY)
            ->call('confirmAppOperation')
            ->assertOk();

        self::assertSame(0, ExtensionInstallRun::query()->count());
        Queue::assertNothingPushed();
    }

    // =====================================================================
    // AC5 — le verrou du moteur est global : l'UI le reflète
    // =====================================================================

    #[Test]
    public function every_operation_button_is_disabled_while_a_run_is_active(): void
    {
        $this->grant(['server.admin']);
        $busy = $this->installableApp();
        $other = Extension::factory()->for($this->remoteSource(), 'source')->app()->withInstallBlock()
            ->create(['key' => 'autre', 'name' => 'Autre']);

        ExtensionInstallRun::factory()->for($busy, 'extension')->running()->create();

        $html = Livewire::test(self::LIBRARY)->assertOk()->html();

        self::assertStringContainsString('data-testid="active-run-banner"', $html);
        self::assertStringContainsString('data-testid="run-progress-'.$busy->id.'"', $html);
        // La carte occupée n'expose plus ses boutons, l'autre les expose désactivés.
        self::assertStringNotContainsString('data-testid="app-install-'.$busy->id.'"', $html);
        self::assertMatchesRegularExpression(
            '/<button[^>]*disabled[^>]*data-testid="app-install-'.$other->id.'"|data-testid="app-install-'.$other->id.'"[^>]*disabled/s',
            $html,
            'un run actif gèle TOUTES les cartes — le verrou du moteur est global',
        );
    }

    #[Test]
    public function the_poll_is_rendered_only_while_something_is_running(): void
    {
        // Zéro trafic au repos : le `wire:poll` n'existe pas dans le DOM tant
        // qu'il n'y a rien à suivre.
        $this->grant(['server.admin']);
        $extension = $this->installableApp();

        $idle = Livewire::test(self::LIBRARY)->assertOk()->html();
        self::assertStringNotContainsString('wire:poll', $idle);

        ExtensionInstallRun::factory()->for($extension, 'extension')->running()->create();

        $busy = Livewire::test(self::LIBRARY)->assertOk()->html();
        self::assertStringContainsString('wire:poll.3s="pollRuns"', $busy);
    }

    #[Test]
    public function a_stale_run_stops_blocking_the_library(): void
    {
        $this->grant(['server.admin']);
        $extension = $this->installableApp();
        ExtensionInstallRun::factory()->for($extension, 'extension')->stale()->create();

        $html = Livewire::test(self::LIBRARY)->assertOk()->html();

        self::assertStringNotContainsString('data-testid="active-run-banner"', $html);
        self::assertStringContainsString('data-testid="run-stale-'.$extension->id.'"', $html);
        self::assertStringContainsString('data-testid="app-install-'.$extension->id.'"', $html);
    }

    #[Test]
    public function a_second_admin_sees_the_very_same_run(): void
    {
        // L'état vit en base, pas dans la mémoire d'un composant : un autre
        // onglet, un autre navigateur, un autre admin voient la même chose.
        $this->grant(['server.admin']);
        $extension = $this->installableApp();
        ExtensionInstallRun::factory()->for($extension, 'extension')->running()->create([
            'current_step' => ExtensionInstallService::STEP_APT,
            'requested_by_login' => 'la-collègue',
        ]);

        Livewire::test(self::LIBRARY)
            ->assertOk()
            ->assertSee('paquet installé (apt)')
            ->assertSee('la-collègue');
    }

    #[Test]
    public function the_failure_reason_stays_visible_on_the_card(): void
    {
        $this->grant(['server.admin']);
        $extension = $this->installableApp();
        ExtensionInstallRun::factory()->for($extension, 'extension')
            ->failed('sha256 du paquet non concordant')
            ->create();

        Livewire::test(self::LIBRARY)
            ->assertOk()
            ->assertSeeHtml('data-testid="run-error-'.$extension->id.'"')
            ->assertSee('sha256 du paquet non concordant')
            // L'extension redevient actionnable : l'échec a été compensé.
            ->assertSeeHtml('data-testid="app-install-'.$extension->id.'"');
    }

    #[Test]
    public function the_end_of_a_tracked_run_toasts_exactly_once(): void
    {
        Queue::fake();
        $this->grant(['server.admin']);
        $extension = $this->installableApp();

        $component = Livewire::test(self::LIBRARY)
            ->call('askAppOperation', $extension->id, 'install')
            ->call('confirmAppOperation');

        // Le worker termine l'opération, hors du composant.
        $run = ExtensionInstallRun::query()->firstOrFail();
        $run->status = ExtensionInstallRun::STATUS_SUCCESS;
        $run->finished_at = now();
        $run->save();

        $component->call('pollRuns')->assertDispatched('toastMagic', status: 'success');

        // Second poll : plus aucun toast, le run n'est plus suivi.
        $component->call('pollRuns')
            ->assertSet('trackedRunId', 0)
            ->assertNotDispatched('toastMagic');
    }

    #[Test]
    public function the_failure_of_a_tracked_run_toasts_the_reason(): void
    {
        Queue::fake();
        $this->grant(['server.admin']);
        $extension = $this->installableApp();

        $component = Livewire::test(self::LIBRARY)
            ->call('askAppOperation', $extension->id, 'install')
            ->call('confirmAppOperation');

        $run = ExtensionInstallRun::query()->firstOrFail();
        $run->status = ExtensionInstallRun::STATUS_FAILED;
        $run->error = ExtensionInstallRun::ERROR_ENGINE_BUSY;
        $run->finished_at = now();
        $run->save();

        $component->call('pollRuns')->assertDispatched('toastMagic', status: 'error');
    }

    // =====================================================================
    // AC3 — la mise à jour proposée
    // =====================================================================

    #[Test]
    public function an_available_update_is_advertised_and_offered(): void
    {
        $this->grant(['server.admin']);
        $extension = $this->installedApp();
        $extension->fill(['version' => '2.0.0'])->save();

        Livewire::test(self::LIBRARY)
            ->assertOk()
            ->assertSeeHtml('data-testid="update-badge-'.$extension->id.'"')
            ->assertSee('Mise à jour disponible')
            ->assertSeeHtml('data-testid="app-update-'.$extension->id.'"')
            ->assertSeeHtml('data-testid="app-remove-'.$extension->id.'"');
    }

    #[Test]
    public function an_up_to_date_app_offers_no_update_button(): void
    {
        $this->grant(['server.admin']);
        $extension = $this->installedApp();

        Livewire::test(self::LIBRARY)
            ->assertOk()
            ->assertDontSeeHtml('data-testid="update-badge-'.$extension->id.'"')
            ->assertDontSeeHtml('data-testid="app-update-'.$extension->id.'"')
            ->assertSeeHtml('data-testid="app-remove-'.$extension->id.'"');
    }

    #[Test]
    public function the_update_modal_names_both_versions(): void
    {
        Queue::fake();
        $this->grant(['server.admin']);
        $extension = $this->installedApp();
        $extension->fill(['version' => '2.0.0'])->save();

        Livewire::test(self::LIBRARY)
            ->call('askAppOperation', $extension->id, 'update')
            ->assertSet('appOperation', 'update')
            ->assertSee('Mettre à jour l\'extension')
            ->assertSeeHtml('data-testid="update-from"')
            ->assertSeeHtml('data-testid="update-to"')
            ->assertSee('1.0.0')
            ->assertSee('2.0.0');
    }

    // =====================================================================
    // AC4 — la désinstallation d'une `app`
    // =====================================================================

    #[Test]
    public function the_remove_modal_says_what_will_actually_be_purged(): void
    {
        Queue::fake();
        $this->grant(['server.admin']);
        $extension = $this->installedApp();

        Livewire::test(self::LIBRARY)
            ->call('askAppOperation', $extension->id, 'remove')
            ->assertSeeHtml('data-testid="app-remove-purge-text"')
            // `escape: false` : texte littéral du gabarit (cf. plus haut).
            ->assertSee('Les données propres à l\'extension seront purgées', escape: false)
            // Le texte nomme les composants SYSTÈME retirés — c'est ce qui le
            // distingue du texte `link` (« il n'y a rien à nettoyer »), lequel
            // reste présent ailleurs dans le DOM pour sa propre modale : la
            // preuve doit donc être POSITIVE, sur le contenu de celle-ci.
            ->assertSee('le paquet, son service, l\'exposition', escape: false)
            ->assertSee('le client SSO de l\'extension', escape: false);
    }

    #[Test]
    public function confirming_a_removal_queues_a_remove_run(): void
    {
        Queue::fake();
        $this->grant(['server.admin']);
        $extension = $this->installedApp();

        Livewire::test(self::LIBRARY)
            ->call('askAppOperation', $extension->id, 'remove')
            ->call('confirmAppOperation');

        $run = ExtensionInstallRun::query()->firstOrFail();
        self::assertSame(ExtensionInstallRun::OPERATION_REMOVE, $run->operation);
        Queue::assertPushedOn('default', RunExtensionOperationJob::class);
    }

    // =====================================================================
    // AC6 — defense-in-depth
    // =====================================================================

    #[Test]
    public function asking_an_operation_is_forbidden_when_the_ability_is_revoked_after_mount(): void
    {
        $extension = $this->installableApp();

        $allowed = true;
        Gate::before(function ($user, string $ability) use (&$allowed) {
            return ($ability === 'server.admin' && $allowed) ? true : null;
        });

        $component = Livewire::test(self::LIBRARY)->assertOk();

        $allowed = false;
        $component->call('askAppOperation', $extension->id, 'install')->assertForbidden();

        self::assertSame(0, ExtensionInstallRun::query()->count());
    }

    #[Test]
    public function confirming_an_operation_is_forbidden_when_the_ability_is_revoked_after_mount(): void
    {
        $extension = $this->installableApp();

        $allowed = true;
        Gate::before(function ($user, string $ability) use (&$allowed) {
            return ($ability === 'server.admin' && $allowed) ? true : null;
        });

        $component = Livewire::test(self::LIBRARY)
            ->assertOk()
            ->call('askAppOperation', $extension->id, 'install');

        $allowed = false;
        $component->call('confirmAppOperation')->assertForbidden();

        self::assertSame(0, ExtensionInstallRun::query()->count());
    }

    #[Test]
    public function polling_is_forbidden_when_the_ability_is_revoked_after_mount(): void
    {
        $extension = $this->installableApp();
        ExtensionInstallRun::factory()->for($extension, 'extension')->running()->create();

        $allowed = true;
        Gate::before(function ($user, string $ability) use (&$allowed) {
            return ($ability === 'server.admin' && $allowed) ? true : null;
        });

        $component = Livewire::test(self::LIBRARY)->assertOk();

        $allowed = false;
        $component->call('pollRuns')->assertForbidden();
    }

    // =====================================================================
    // La FICHE : mêmes gestes, même modale, panneau d'état
    // =====================================================================

    #[Test]
    public function the_detail_page_offers_the_same_app_actions(): void
    {
        $this->grant(['server.admin']);
        $extension = $this->installedApp();
        $extension->fill(['version' => '2.0.0'])->save();

        Livewire::test(self::DETAIL, ['id' => (string) $extension->id])
            ->assertOk()
            ->assertSeeHtml('data-testid="app-update-action"')
            ->assertSeeHtml('data-testid="app-remove-action"')
            ->assertSeeHtml('data-testid="update-badge"')
            ->assertSeeHtml('data-testid="installed-version"')
            ->assertSee('Version installée');
    }

    #[Test]
    public function the_detail_page_confirms_through_the_same_modal(): void
    {
        Queue::fake();
        $this->grant(['server.admin']);
        $extension = $this->installableApp();

        Livewire::test(self::DETAIL, ['id' => (string) $extension->id])
            ->call('askAppOperation', 'install')
            ->assertSet('isAppOperationOpen', true)
            ->assertSee('Source non officielle : depot.example.test')
            ->call('confirmAppOperation')
            ->assertDispatched('toastMagic', status: 'info');

        self::assertSame(1, ExtensionInstallRun::query()->count());
        Queue::assertPushedOn('default', RunExtensionOperationJob::class);
    }

    #[Test]
    public function the_detail_page_shows_the_accomplished_steps_and_the_current_one(): void
    {
        $this->grant(['server.admin']);
        $extension = $this->installableApp();
        ExtensionInstallRun::factory()->for($extension, 'extension')->running()->create([
            'current_step' => ExtensionInstallService::STEP_APT,
            'steps' => [ExtensionInstallService::STEP_PACKAGE, ExtensionInstallService::STEP_OIDC],
        ]);

        Livewire::test(self::DETAIL, ['id' => (string) $extension->id])
            ->assertOk()
            ->assertSeeHtml('data-testid="run-panel"')
            ->assertSeeHtml('data-testid="run-steps"')
            ->assertSee('paquet téléchargé et sha256 vérifié')
            ->assertSee('client OIDC enregistré')
            ->assertSeeHtml('data-testid="run-current-step"')
            ->assertSee('paquet installé (apt)')
            ->assertSeeHtml('wire:poll.3s="pollRun"');
    }

    #[Test]
    public function the_detail_page_freezes_and_polls_when_another_extension_is_busy(): void
    {
        // Sans ce bandeau, la fiche resterait gelée jusqu'à un rechargement
        // manuel : le verrou est global, mais rien ne viendrait dire qu'il a
        // été relâché.
        $this->grant(['server.admin']);
        $shown = $this->installedApp();
        $busy = Extension::factory()->for($this->remoteSource(), 'source')->app()->withInstallBlock()
            ->create(['key' => 'autre', 'name' => 'Autre']);
        ExtensionInstallRun::factory()->for($busy, 'extension')->running()->create();

        $html = Livewire::test(self::DETAIL, ['id' => (string) $shown->id])->assertOk()->html();

        self::assertStringContainsString('data-testid="foreign-run-banner"', $html);
        self::assertStringContainsString('wire:poll.3s="pollRun"', $html);
        self::assertMatchesRegularExpression(
            '/<button[^>]*disabled[^>]*data-testid="app-remove-action"|data-testid="app-remove-action"[^>]*disabled/s',
            $html,
        );
    }

    #[Test]
    public function the_detail_page_does_not_poll_when_nothing_is_running(): void
    {
        $this->grant(['server.admin']);
        $extension = $this->installedApp();

        $html = Livewire::test(self::DETAIL, ['id' => (string) $extension->id])->assertOk()->html();

        self::assertStringNotContainsString('wire:poll', $html);
    }

    #[Test]
    public function the_detail_page_shows_the_last_failure_reason(): void
    {
        $this->grant(['server.admin']);
        $extension = $this->installableApp();
        ExtensionInstallRun::factory()->for($extension, 'extension')
            ->failed(ExtensionInstallService::ERROR_ROLLBACK_PACKAGE_MISSING)
            ->create();

        Livewire::test(self::DETAIL, ['id' => (string) $extension->id])
            ->assertOk()
            ->assertSeeHtml('data-testid="run-error"')
            ->assertSee('désinstaller puis réinstaller');
    }

    #[Test]
    public function the_detail_page_refuses_an_app_operation_on_a_link(): void
    {
        Queue::fake();
        $this->grant(['server.admin']);
        $extension = Extension::factory()->for($this->remoteSource(), 'source')->link('/doc')->create(['key' => 'doc']);

        Livewire::test(self::DETAIL, ['id' => (string) $extension->id])
            ->call('askAppOperation', 'install')
            ->assertSet('isAppOperationOpen', false)
            ->assertDispatched('toastMagic', status: 'error');

        self::assertSame(0, ExtensionInstallRun::query()->count());
    }

    #[Test]
    public function the_detail_page_action_is_forbidden_when_the_ability_is_revoked_after_mount(): void
    {
        $extension = $this->installableApp();

        $allowed = true;
        Gate::before(function ($user, string $ability) use (&$allowed) {
            return ($ability === 'server.admin' && $allowed) ? true : null;
        });

        $component = Livewire::test(self::DETAIL, ['id' => (string) $extension->id])->assertOk();

        $allowed = false;
        $component->call('askAppOperation', 'install')->assertForbidden();
    }
}
