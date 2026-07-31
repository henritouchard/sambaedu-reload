<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Admin;

use App\Models\Extension;
use App\Models\ExtensionAuditLog;
use App\Models\ExtensionSource;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 56.5 (AC4) — la carte « Santé » de la fiche d'extension, et le bouton
 * « Sonder maintenant ».
 *
 * Fichier NOUVEAU, volontairement : {@see ExtensionDetailPageTest} (54.1/54.2),
 * {@see ExtensionAppOperationsPageTest} (56.3) et {@see ExtensionScopesPageTest}
 * (56.4) restent VERBATIM — qu'elles passent inchangées est la preuve que cette
 * carte s'ajoute sans rien déplacer.
 *
 * ⚠️ Le RENDU ne sonde jamais (NFR9) : la carte affiche l'état PERSISTÉ. Le seul
 * chemin de mesure à la demande est le bouton, et un test l'affirme par
 * `Http::assertNothingSent()` au rendu.
 */
class ExtensionHealthCardTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'pages::admin.extensions.[id].index';

    private User $admin;

    /** @var \Closure(\Illuminate\Http\Client\Request): mixed */
    private \Closure $responder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Http::preventStrayRequests();
        $this->responder = static fn (): mixed => Http::response('', 200);
        Http::fake(['127.0.0.1:*' => fn ($request): mixed => ($this->responder)($request)]);

        $this->admin = User::query()->create([
            'login' => 'extension-health-admin',
            'role' => 'autre',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @param list<string> $abilities */
    private function grant(array $abilities): void
    {
        Gate::before(fn ($user, string $ability) => in_array($ability, $abilities, true) ? true : null);
    }

    private function installedApp(int $port = 9200, string $version = '1.0.0'): Extension
    {
        return Extension::factory()
            ->for(ExtensionSource::factory()->remote('https://depot.example.test/extensions'), 'source')
            ->app()
            ->withInstallBlock()
            ->installed($port, $version)
            ->create(['key' => 'hello', 'name' => 'Hello', 'version' => $version]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // AC4 — la carte n'existe que là où il y a quelque chose à sonder
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function the_health_card_is_visible_for_an_installed_app(): void
    {
        $this->grant(['server.admin']);
        $extension = $this->installedApp();

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->assertSeeHtml('data-testid="health-card"')
            ->assertSeeHtml('data-testid="health-probe-now"');
    }

    #[Test]
    public function a_link_extension_has_no_health_card(): void
    {
        $this->grant(['server.admin']);
        $extension = Extension::factory()->fromBundled()->link('/doc')->integrated()->create();

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->assertDontSeeHtml('data-testid="health-card"');
    }

    #[Test]
    public function a_non_installed_app_has_no_health_card(): void
    {
        $this->grant(['server.admin']);
        $extension = Extension::factory()->fromBundled()->app()->withInstallBlock()->create();

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->assertDontSeeHtml('data-testid="health-card"');
    }

    // ══════════════════════════════════════════════════════════════════════
    // AC4 — contenus
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function a_never_probed_app_shows_an_unknown_badge_and_no_incident(): void
    {
        $this->grant(['server.admin']);
        $extension = $this->installedApp();

        $component = Livewire::test(self::PAGE, ['id' => $extension->id]);

        self::assertSame('', $component->get('extension.health_status'));
        self::assertTrue($component->get('extension.health_stale'));
        $component->assertSee('Inconnu ou périmé')
            ->assertSee('Jamais sondée')
            ->assertSee('Aucun incident enregistré')
            ->assertSeeHtml('data-testid="health-stale-note"');
    }

    #[Test]
    public function a_fresh_unreachable_state_shows_the_unavailable_badge_and_the_incident(): void
    {
        $this->grant(['server.admin']);
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00'));

        $extension = $this->installedApp();
        $extension->health_status = Extension::HEALTH_UNREACHABLE;
        $extension->health_checked_at = now();
        $extension->health_last_incident_at = now();
        $extension->health_last_incident_detail = 'backend injoignable (connexion refusée ou expirée)';
        $extension->save();

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->assertSee('Indisponible')
            ->assertSee('07/08/2026 12:00:00')
            ->assertSee('backend injoignable (connexion refusée ou expirée)')
            ->assertDontSeeHtml('data-testid="health-stale-note"');
    }

    #[Test]
    public function a_recovered_app_shows_ok_and_still_shows_its_last_incident(): void
    {
        $this->grant(['server.admin']);
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00'));

        $extension = $this->installedApp();
        $extension->health_status = Extension::HEALTH_OK;
        $extension->health_checked_at = now();
        $extension->health_last_incident_at = Carbon::parse('2026-08-06 08:30:00');
        $extension->health_last_incident_detail = 'backend injoignable (connexion refusée ou expirée)';
        $extension->save();

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->assertSee('Joignable')
            ->assertSee('06/08/2026 08:30');
    }

    /**
     * La carte réutilise le badge de mise à jour de 56.3 — la règle n'est pas
     * recalculée (review 56.1 #3).
     */
    #[Test]
    public function the_card_reuses_the_existing_update_available_flag_for_versions(): void
    {
        $this->grant(['server.admin']);

        $extension = $this->installedApp(9200, '1.0.0');
        $extension->version = '2.0.0';
        $extension->save();

        $component = Livewire::test(self::PAGE, ['id' => $extension->id]);

        self::assertTrue($component->get('extension.update_available'));
        $component->assertSeeHtml('data-testid="health-update-badge"')
            ->assertSeeHtml('data-testid="health-installed-version"');
    }

    // ══════════════════════════════════════════════════════════════════════
    // NFR9 — le rendu ne sonde JAMAIS
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function rendering_the_detail_page_never_probes_anything(): void
    {
        $this->grant(['server.admin']);
        $extension = $this->installedApp();

        Livewire::test(self::PAGE, ['id' => $extension->id]);

        Http::assertNothingSent();
    }

    // ══════════════════════════════════════════════════════════════════════
    // AC4 — « Sonder maintenant » : mesure ET persiste
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function probe_now_persists_a_reachable_state_and_toasts_success(): void
    {
        $this->grant(['server.admin']);
        $extension = $this->installedApp();

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->call('probeNow')
            ->assertSet('extension.health_status', Extension::HEALTH_OK);

        Http::assertSentCount(1);
        self::assertSame(Extension::HEALTH_OK, $extension->refresh()->health_status);
        self::assertNotNull($extension->health_checked_at);
    }

    #[Test]
    public function probe_now_persists_an_unreachable_state_with_its_incident(): void
    {
        $this->grant(['server.admin']);
        $extension = $this->installedApp();
        $this->responder = static fn (): mixed => Http::failedConnection('down');

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->call('probeNow')
            ->assertSet('extension.health_status', Extension::HEALTH_UNREACHABLE);

        $extension->refresh();
        self::assertSame(Extension::HEALTH_UNREACHABLE, $extension->health_status);
        self::assertNotNull($extension->health_last_incident_at);
    }

    /** La santé n'est jamais auditée — y compris depuis l'UI (décision n° 2). */
    #[Test]
    public function probe_now_writes_no_audit_line(): void
    {
        $this->grant(['server.admin']);
        $extension = $this->installedApp();
        $before = ExtensionAuditLog::query()->count();

        Livewire::test(self::PAGE, ['id' => $extension->id])->call('probeNow');

        self::assertSame($before, ExtensionAuditLog::query()->count());
    }

    // ══════════════════════════════════════════════════════════════════════
    // Sécurité — Gate DANS la méthode (defense-in-depth)
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function probe_now_is_forbidden_without_server_admin(): void
    {
        // Composant NEUF pour l'action gate-testée : un 403 invalide le snapshot
        // Livewire (leçon 56.x).
        $this->grant(['server.admin']);
        $extension = $this->installedApp();
        $component = Livewire::test(self::PAGE, ['id' => $extension->id]);

        // Le Gate résolu porte encore le `before()` du montage : on le jette du
        // conteneur pour retirer RÉELLEMENT la délégation.
        $this->app->forgetInstance(GateContract::class);
        Gate::clearResolvedInstances();

        $component->call('probeNow')->assertForbidden();

        Http::assertNothingSent();
        self::assertSame('', (string) $extension->refresh()->health_status);
    }

    /**
     * CONTRE-ÉPREUVE : avec le droit, le même appel passe. Sans elle, un
     * composant qui répondrait 403 à TOUT validerait la garde sans rien prouver.
     */
    #[Test]
    public function probe_now_succeeds_with_server_admin(): void
    {
        $this->grant(['server.admin']);
        $extension = $this->installedApp();

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->call('probeNow')
            ->assertOk();

        self::assertSame(Extension::HEALTH_OK, $extension->refresh()->health_status);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Tolérance aux pannes du bouton « Sonder maintenant » (review 56.5 #2)
    //
    // Les notes de développement affirmaient ces deux comportements « prouvés »
    // alors qu'aucun test ne les exerçait. Le code était juste — la preuve
    // manquait. Sur la story qui clôt un epic dont l'incident fondateur est
    // « une page d'extension a fait tomber tout SE5 », ça ne peut pas rester
    // une affirmation.
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function probing_when_the_registry_has_vanished_toasts_instead_of_exploding(): void
    {
        $this->grant(['server.admin']);
        $extension = $this->installedApp();

        $component = Livewire::test(self::PAGE, ['id' => $extension->id]);

        // La table disparaît ENTRE le montage et le clic (fenêtre de migration,
        // scénario QA 10.1 de l'Epic 54).
        Schema::drop('extensions');

        $component->call('probeNow')->assertOk();
    }

    #[Test]
    public function probing_an_extension_deleted_under_the_admin_does_not_break_the_page(): void
    {
        $this->grant(['server.admin']);
        $extension = $this->installedApp();

        $component = Livewire::test(self::PAGE, ['id' => $extension->id]);

        // Un autre admin a désinstallé et retiré l'extension entre-temps.
        Extension::query()->whereKey($extension->id)->delete();

        $component->call('probeNow')->assertOk();
    }

    // ══════════════════════════════════════════════════════════════════════
    // Lien vers le journal, pré-filtré
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function the_page_links_to_the_audit_journal_prefiltered_on_this_extension(): void
    {
        $this->grant(['server.admin']);
        $extension = $this->installedApp();

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->assertSeeHtml('data-testid="extension-journal-link"')
            ->assertSeeHtml('ext=hello');
    }

    /** Le lien existe aussi pour une `link` : un journal se consulte toujours. */
    #[Test]
    public function the_journal_link_is_also_present_on_a_link_extension(): void
    {
        $this->grant(['server.admin']);
        $extension = Extension::factory()->fromBundled()->link('/doc')->integrated()->create(['key' => 'doc']);

        Livewire::test(self::PAGE, ['id' => $extension->id])
            ->assertSeeHtml('data-testid="extension-journal-link"');
    }
}
