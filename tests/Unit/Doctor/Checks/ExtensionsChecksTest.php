<?php

declare(strict_types=1);

namespace Tests\Unit\Doctor\Checks;

use App\Doctor\Checks\Extensions\ExtensionsAuditTrailCheck;
use App\Doctor\Checks\Extensions\ExtensionsOidcClientsCheck;
use App\Doctor\Checks\Extensions\ExtensionsReachableCheck;
use App\Doctor\EnvironmentCheck;
use App\Doctor\Level;
use App\Models\Extension;
use App\Models\ExtensionAuditLog;
use App\Models\ExtensionSource;
use App\Models\OidcClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 56.5 (AC3, AC6) — les trois checks doctor du domaine extensions.
 *
 * Patron {@see SystemStatusChecksTest} : instanciation par `app()`, assertions
 * sur le `Level`, et le smoke test « aucun check ne lève » — le harnais attrape
 * les exceptions, mais un check PROPRE rend un `CheckResult`.
 *
 * ⚠️ Règle d'or du contrat {@see EnvironmentCheck} : **aucun side effect**. Un
 * test dédié vérifie que `ExtensionsReachableCheck` ne PERSISTE rien, alors même
 * qu'il sonde en direct.
 */
class ExtensionsChecksTest extends TestCase
{
    use RefreshDatabase;

    /** @var \Closure(\Illuminate\Http\Client\Request): mixed */
    private \Closure $responder;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->responder = static fn (): mixed => Http::response('', 200);
        Http::fake(['127.0.0.1:*' => fn ($request): mixed => ($this->responder)($request)]);

        // Un marqueur laissé par un autre test (cache FICHIER, donc partagé
        // entre exécutions) ferait mentir les verdicts d'audit.
        ExtensionAuditLog::acknowledgeWriteFailure();
    }

    protected function tearDown(): void
    {
        ExtensionAuditLog::acknowledgeWriteFailure();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function installedApp(string $key, int $port, string $version = '1.0.0'): Extension
    {
        return Extension::factory()
            ->for(ExtensionSource::factory()->remote('https://depot.example.test/extensions'), 'source')
            ->app()
            ->withInstallBlock()
            ->installed($port, $version)
            ->create(['key' => $key, 'name' => ucfirst($key), 'version' => $version]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // AC3 — joignabilité
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function it_reports_ok_when_no_app_is_installed(): void
    {
        Extension::factory()->fromBundled()->link('/doc')->integrated()->create();

        $result = app(ExtensionsReachableCheck::class)->run();

        self::assertSame(Level::Ok, $result->level);
        self::assertStringContainsString('aucune extension app installée', $result->detail);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_reports_ok_when_every_backend_answers(): void
    {
        $extension = $this->installedApp('hello', 8600);
        $extension->health_status = Extension::HEALTH_OK;
        $extension->health_checked_at = now();
        $extension->save();

        $result = app(ExtensionsReachableCheck::class)->run();

        self::assertSame(Level::Ok, $result->level);
        self::assertStringContainsString('1 extension(s) app installée(s), toutes joignables', $result->detail);
    }

    #[Test]
    public function it_errors_and_names_the_keys_when_a_backend_is_down(): void
    {
        $this->installedApp('hello', 8600);
        $this->installedApp('agenda', 8601);

        $this->responder = static fn ($request): mixed => str_contains($request->url(), ':8600')
            ? Http::failedConnection('down')
            : Http::response('', 200);

        $result = app(ExtensionsReachableCheck::class)->run();

        self::assertSame(Level::Error, $result->level);
        self::assertStringContainsString('hello', $result->detail);
        self::assertStringNotContainsString('agenda', $result->detail);
        self::assertNotNull($result->fix);
        self::assertStringContainsString('systemctl status sambaedu-ext-hello', (string) $result->fix);
    }

    /**
     * Le doctor NOMME la commande de diagnostic, il ne la LANCE pas : pas
     * d'auto-réparation (décision de périmètre de la story).
     */
    /**
     * Review 56.5 #1 — ce check tourne DANS une requête HTTP, à côté des autres
     * checks réseau. Les sondes sont séquentielles et un backend mort coûte le
     * délai complet : sans borne, quelques extensions mortes suffisaient à
     * dépasser le `max_execution_time` et à faire tomber la page de diagnostic
     * — celle qu'on ouvre justement quand ça va mal.
     *
     * Budget à 0,000001 s : la borne mord dès la première extension. Ce qui est
     * verrouillé ici n'est pas une durée (intestable de façon fiable) mais le
     * COMPORTEMENT au dépassement : verdict partiel, nommant les extensions non
     * mesurées — surtout pas un « tout va bien » sur du non-mesuré.
     */
    #[Test]
    public function an_exhausted_probe_budget_yields_a_partial_verdict_instead_of_a_silent_ok(): void
    {
        config(['extensions.health.doctor_probe_budget' => 0.000001]);

        $extension = $this->installedApp('hello', 8600);
        $extension->health_status = Extension::HEALTH_OK;
        $extension->health_checked_at = now();
        $extension->save();

        $result = app(ExtensionsReachableCheck::class)->run();

        self::assertSame(Level::Warn, $result->level);
        self::assertStringContainsString('PARTIEL', $result->detail);
        self::assertStringContainsString('hello', $result->detail);

        // Et rien n'a été sondé : la borne coupe AVANT l'appel réseau, elle ne
        // se contente pas de commenter après coup.
        Http::assertNothingSent();
    }

    #[Test]
    public function a_zero_budget_disables_the_bound_and_probes_everything(): void
    {
        // Contre-épreuve : sans elle, un budget cassé à « toujours épuisé »
        // rendrait le test ci-dessus vert tout en supprimant la sonde.
        config(['extensions.health.doctor_probe_budget' => 0]);

        $extension = $this->installedApp('hello', 8600);
        $extension->health_status = Extension::HEALTH_OK;
        $extension->health_checked_at = now();
        $extension->save();

        $result = app(ExtensionsReachableCheck::class)->run();

        self::assertSame(Level::Ok, $result->level);
        self::assertStringNotContainsString('PARTIEL', $result->detail);
    }

    #[Test]
    public function the_fix_never_promises_an_automatic_restart(): void
    {
        $this->installedApp('hello', 8600);
        $this->responder = static fn (): mixed => Http::failedConnection('down');

        $fix = (string) app(ExtensionsReachableCheck::class)->run()->fix;

        self::assertStringContainsString('systemctl status', $fix);
        self::assertStringNotContainsString('systemctl restart', $fix);
    }

    #[Test]
    public function it_warns_when_the_persisted_state_is_stale_although_the_probe_answers(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00'));

        $extension = $this->installedApp('hello', 8600);
        $extension->health_status = Extension::HEALTH_OK;
        // Mesuré il y a 2 h : le scheduler ne tourne plus.
        $extension->health_checked_at = Carbon::parse('2026-08-07 10:00:00');
        $extension->save();

        $result = app(ExtensionsReachableCheck::class)->run();

        self::assertSame(Level::Warn, $result->level);
        self::assertStringContainsString('n\'est pas exploitable', $result->detail);
        self::assertStringContainsString('hello', $result->detail);
        self::assertStringContainsString('scheduler', (string) $result->fix);
    }

    /**
     * Une extension installée à l'instant, jamais encore mesurée, donne le MÊME
     * `warn` — et son libellé doit rester VRAI : « jamais mesuré, ou mesuré il y
     * a plus de N s ». Affirmer « le scheduler est muet depuis 900 s » serait
     * faux ici, et un diagnostic qui peut être faux est pire qu'une absence de
     * diagnostic (leçon review 56.3 #1).
     */
    #[Test]
    public function a_never_measured_app_warns_without_claiming_the_scheduler_is_dead(): void
    {
        $this->installedApp('hello', 8600);

        $result = app(ExtensionsReachableCheck::class)->run();

        self::assertSame(Level::Warn, $result->level);
        self::assertStringContainsString('jamais mesuré', $result->detail);
        self::assertStringContainsString('ext:health:check', (string) $result->fix);
    }

    /** Un backend mort l'emporte sur une péremption : la panne réelle d'abord. */
    #[Test]
    public function a_dead_backend_outranks_a_stale_state(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00'));

        $down = $this->installedApp('hello', 8600);
        $down->health_status = Extension::HEALTH_UNREACHABLE;
        $down->health_checked_at = Carbon::parse('2026-08-07 08:00:00');
        $down->save();

        $this->responder = static fn (): mixed => Http::failedConnection('down');

        self::assertSame(Level::Error, app(ExtensionsReachableCheck::class)->run()->level);
    }

    #[Test]
    public function the_detail_names_version_drift_and_the_last_incident(): void
    {
        $extension = $this->installedApp('hello', 8600, '1.0.0');
        $extension->version = '2.0.0';
        $extension->health_status = Extension::HEALTH_OK;
        $extension->health_checked_at = now();
        $extension->health_last_incident_at = now()->subDay();
        $extension->health_last_incident_detail = 'backend injoignable (connexion refusée ou expirée)';
        $extension->save();

        $detail = app(ExtensionsReachableCheck::class)->run()->detail;

        self::assertStringContainsString('écart de version', $detail);
        self::assertStringContainsString('1.0.0 installée, 2.0.0 au catalogue', $detail);
        self::assertStringContainsString('dernier incident', $detail);
        self::assertStringContainsString('backend injoignable', $detail);
    }

    /**
     * ⚠️ LA règle d'or : un check ne PERSISTE rien. Sans ce test, une
     * implémentation qui appellerait `checkOne()` au lieu de `probe()` passerait
     * tous les autres tests — en écrasant silencieusement l'état mesuré par le
     * scheduler à chaque ouverture de la page « État du système ».
     */
    #[Test]
    public function the_reachability_check_never_writes_the_health_columns(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00'));

        $extension = $this->installedApp('hello', 8600);
        $extension->health_status = Extension::HEALTH_OK;
        $extension->health_checked_at = Carbon::parse('2026-08-07 11:59:00');
        $extension->save();

        $this->responder = static fn (): mixed => Http::failedConnection('down');

        app(ExtensionsReachableCheck::class)->run();

        $extension->refresh();
        self::assertSame(Extension::HEALTH_OK, $extension->health_status);
        self::assertSame('2026-08-07 11:59:00', $extension->health_checked_at?->toDateTimeString());
        self::assertNull($extension->health_last_incident_at);
    }

    #[Test]
    public function the_reachability_check_warns_instead_of_throwing_when_the_registry_is_unreadable(): void
    {
        Schema::drop('extensions');

        $result = app(ExtensionsReachableCheck::class)->run();

        self::assertSame(Level::Warn, $result->level);
        self::assertStringContainsString('illisible', $result->detail);
    }

    // ══════════════════════════════════════════════════════════════════════
    // AC6 — signal d'échec d'écriture d'audit
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function the_audit_trail_check_is_ok_without_a_marker(): void
    {
        $result = app(ExtensionsAuditTrailCheck::class)->run();

        self::assertSame(Level::Ok, $result->level);
        self::assertStringContainsString('aucune écriture d\'audit perdue', $result->detail);
    }

    #[Test]
    public function the_audit_trail_check_errors_with_a_marker_and_reports_the_count(): void
    {
        ExtensionAuditLog::recordWriteFailure();
        ExtensionAuditLog::recordWriteFailure();

        $result = app(ExtensionsAuditTrailCheck::class)->run();

        self::assertSame(Level::Error, $result->level);
        self::assertStringContainsString('2 écriture(s) perdue(s)', $result->detail);
        self::assertStringContainsString('INCOMPLET', $result->detail);
        self::assertStringContainsString('/admin/extensions/journal', (string) $result->fix);
    }

    #[Test]
    public function acknowledging_clears_the_marker_and_the_check_returns_to_ok(): void
    {
        ExtensionAuditLog::recordWriteFailure();
        self::assertSame(Level::Error, app(ExtensionsAuditTrailCheck::class)->run()->level);

        ExtensionAuditLog::acknowledgeWriteFailure();

        self::assertSame(Level::Ok, app(ExtensionsAuditTrailCheck::class)->run()->level);
        self::assertNull(ExtensionAuditLog::writeFailureMarker());
    }

    // ══════════════════════════════════════════════════════════════════════
    // Legs review 56.4 #4 — clients OIDC fantômes
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function the_oidc_clients_check_is_ok_with_one_enabled_client_per_extension(): void
    {
        $extension = $this->installedApp('hello', 8600);
        OidcClient::factory()->grantedScopes(['profile'])->create([
            'extension_id' => $extension->id,
            'extension_key' => $extension->key,
        ]);

        $result = app(ExtensionsOidcClientsCheck::class)->run();

        self::assertSame(Level::Ok, $result->level);
    }

    /**
     * LE scénario de la review 56.4 #4 : un second client `enabled` porte un
     * scope que le client AFFICHÉ n'a pas. L'admin ne le voit pas, donc il ne
     * peut pas le révoquer — alors qu'il continue d'être servi.
     */
    #[Test]
    public function a_ghost_client_carrying_an_invisible_scope_is_an_error(): void
    {
        $extension = $this->installedApp('hello', 8600);

        // Le fantôme (créé d'abord, donc id plus petit : la fiche affiche le
        // plus récent).
        OidcClient::factory()->grantedScopes(['profile', 'groups'])->create([
            'extension_id' => $extension->id,
            'extension_key' => $extension->key,
        ]);
        OidcClient::factory()->grantedScopes(['profile'])->create([
            'extension_id' => $extension->id,
            'extension_key' => $extension->key,
        ]);

        $result = app(ExtensionsOidcClientsCheck::class)->run();

        self::assertSame(Level::Error, $result->level);
        self::assertStringContainsString('hello', $result->detail);
        self::assertStringContainsString('groups', $result->detail);
        self::assertNotNull($result->fix);
    }

    /** NFR3 — le détail ne nomme jamais un `client_id`. */
    #[Test]
    public function the_oidc_clients_check_never_leaks_a_client_id(): void
    {
        $extension = $this->installedApp('hello', 8600);

        $ghost = OidcClient::factory()->grantedScopes(['profile', 'groups'])->create([
            'extension_id' => $extension->id,
            'extension_key' => $extension->key,
        ]);
        OidcClient::factory()->grantedScopes(['profile'])->create([
            'extension_id' => $extension->id,
            'extension_key' => $extension->key,
        ]);

        $result = app(ExtensionsOidcClientsCheck::class)->run();

        self::assertStringNotContainsString((string) $ghost->client_id, $result->detail);
        self::assertStringNotContainsString((string) $ghost->client_id, (string) $result->fix);
    }

    #[Test]
    public function duplicated_clients_without_any_invisible_scope_only_warn(): void
    {
        $extension = $this->installedApp('hello', 8600);

        OidcClient::factory()->grantedScopes(['profile'])->create([
            'extension_id' => $extension->id,
            'extension_key' => $extension->key,
        ]);
        OidcClient::factory()->grantedScopes(['profile'])->create([
            'extension_id' => $extension->id,
            'extension_key' => $extension->key,
        ]);

        $result = app(ExtensionsOidcClientsCheck::class)->run();

        self::assertSame(Level::Warn, $result->level);
        self::assertStringContainsString('2 clients actifs', $result->detail);
    }

    /**
     * Un client révoqué (`enabled = false`) n'est PAS un fantôme : il ne sert
     * plus rien. Sans ce test, le check crierait au loup sur toute instance
     * ayant fait tourner un secret.
     */
    #[Test]
    public function a_disabled_client_is_never_counted_as_a_ghost(): void
    {
        $extension = $this->installedApp('hello', 8600);

        OidcClient::factory()->grantedScopes(['profile', 'groups'])->create([
            'extension_id' => $extension->id,
            'extension_key' => $extension->key,
            'enabled' => false,
        ]);
        OidcClient::factory()->grantedScopes(['profile'])->create([
            'extension_id' => $extension->id,
            'extension_key' => $extension->key,
        ]);

        self::assertSame(Level::Ok, app(ExtensionsOidcClientsCheck::class)->run()->level);
    }

    /**
     * Un client actif dont la clé n'est PAS une `app` installée est légitime :
     * l'app-témoin `sso-demo` (55.3) est une extension `link`. Signaler ce cas
     * produirait un faux positif permanent — et un check qui crie au loup ne se
     * lit plus.
     */
    #[Test]
    public function a_client_for_a_link_extension_is_not_an_anomaly(): void
    {
        $extension = Extension::factory()->fromBundled()->link('/sso-demo')->integrated()->create([
            'key' => 'sso-demo',
        ]);
        OidcClient::factory()->grantedScopes(['profile', 'groups'])->create([
            'extension_id' => $extension->id,
            'extension_key' => $extension->key,
        ]);

        self::assertSame(Level::Ok, app(ExtensionsOidcClientsCheck::class)->run()->level);
    }

    #[Test]
    public function the_oidc_clients_check_warns_instead_of_throwing_when_the_table_is_missing(): void
    {
        Schema::drop('oidc_clients');

        $result = app(ExtensionsOidcClientsCheck::class)->run();

        self::assertSame(Level::Warn, $result->level);
        self::assertStringContainsString('illisible', $result->detail);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Contrat EnvironmentCheck — aucun check ne lève, jamais
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function it_all_extension_checks_never_throw_even_without_any_table(): void
    {
        Schema::drop('extension_audit_logs');
        Schema::drop('oidc_clients');
        Schema::drop('extensions');

        foreach ([
            ExtensionsReachableCheck::class,
            ExtensionsAuditTrailCheck::class,
            ExtensionsOidcClientsCheck::class,
        ] as $class) {
            /** @var EnvironmentCheck $check */
            $check = app($class);

            $result = $check->run();

            self::assertSame('extensions', $check->tag(), 'tag attendu pour l\'auto-découverte --tag=extensions');
            self::assertNotSame('', $check->name());
            self::assertContains($result->level, [Level::Ok, Level::Warn, Level::Error]);
        }
    }
}
