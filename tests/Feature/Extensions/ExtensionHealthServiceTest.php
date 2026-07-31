<?php

declare(strict_types=1);

namespace Tests\Feature\Extensions;

use App\Models\Extension;
use App\Models\ExtensionAuditLog;
use App\Models\ExtensionSource;
use App\Services\Extensions\ExtensionHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 56.5 (AC1) — `ExtensionHealthService` : la sonde, ses transitions et sa
 * persistance.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  CE QUE CE FICHIER VERROUILLE
 *
 *  1. « Joignable » = une RÉPONSE HTTP, quelle qu'elle soit (4xx/5xx compris) ;
 *     seule une erreur réseau vaut « injoignable ».
 *  2. L'incident est écrit à la TRANSITION, jamais réécrit — sinon le « depuis
 *     quand » serait perdu à chaque passage du scheduler.
 *  3. Le retour du backend CONSERVE l'incident : c'est sa raison d'être.
 *  4. ZÉRO ligne d'audit : la santé est de la télémétrie, pas un acte.
 *  5. Aucune catégorie d'incident ne porte d'URL ni de message Guzzle brut.
 * ══════════════════════════════════════════════════════════════════════════
 */
class ExtensionHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    private ExtensionHealthService $service;

    /**
     * LE répondeur de la boucle locale, mutable en cours de test.
     *
     * ⚠️ Piège connu (leçon 56.1) : `Http::fake()` FUSIONNE ses stubs et le
     * PREMIER motif enregistré gagne — un second `Http::fake(['127.0.0.1:*' =>
     * …])` ne remplace donc rien, et un scénario « le service redémarre » resterait
     * silencieusement bloqué sur la première réponse. Un fake UNIQUE, posé au
     * `setUp()`, qui délègue à une closure remplaçable, est la seule forme qui
     * permette de faire varier la réponse dans un même test.
     *
     * @var \Closure(\Illuminate\Http\Client\Request): mixed
     */
    private \Closure $responder;

    protected function setUp(): void
    {
        parent::setUp();

        // Toute requête sortante non explicitement fakée doit ÉCHOUER le test :
        // une sonde qui partirait ailleurs que sur la boucle locale doit se voir.
        Http::preventStrayRequests();

        $this->responder = static fn (): mixed => Http::response('', 200);
        Http::fake(['127.0.0.1:*' => fn ($request): mixed => ($this->responder)($request)]);

        $this->service = app(ExtensionHealthService::class);
    }

    /** @param \Closure(\Illuminate\Http\Client\Request): mixed $responder */
    private function respondWith(\Closure $responder): void
    {
        $this->responder = $responder;
    }

    /** Une `app` réellement installée (donc sondable). */
    private function installedApp(string $key = 'hello', int $port = 8600, string $version = '1.0.0'): Extension
    {
        return Extension::factory()
            ->for(ExtensionSource::factory()->remote('https://depot.example.test/extensions'), 'source')
            ->app()
            ->withInstallBlock()
            ->installed($port, $version)
            ->create(['key' => $key, 'name' => ucfirst($key), 'version' => $version]);
    }

    private function fakeBackend(int $status, array $headers = []): void
    {
        $this->respondWith(static fn (): mixed => Http::response('', $status, $headers));
    }

    private function fakeDeadBackend(?string $message = null): void
    {
        $message ??= 'cURL error 7: Failed to connect to 127.0.0.1 port 8600';

        $this->respondWith(static fn (): mixed => Http::failedConnection($message));
    }

    // ══════════════════════════════════════════════════════════════════════
    // AC1 — transitions
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function a_dead_backend_is_marked_unreachable_with_a_dated_incident(): void
    {
        $extension = $this->installedApp();
        $this->fakeDeadBackend();

        $result = $this->service->checkOne($extension);

        self::assertFalse($result['reachable']);
        self::assertSame(Extension::HEALTH_UNREACHABLE, $result['status']);

        $extension->refresh();
        self::assertSame(Extension::HEALTH_UNREACHABLE, $extension->health_status);
        self::assertNotNull($extension->health_checked_at);
        self::assertNotNull($extension->health_last_incident_at);
        self::assertNotSame('', $extension->health_last_incident_detail);
    }

    #[Test]
    public function the_probe_targets_only_the_loopback_port_of_the_extension(): void
    {
        $extension = $this->installedApp('hello', 8642);
        $this->fakeBackend(200);

        $this->service->checkOne($extension);

        Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:8642/');
    }

    #[Test]
    public function a_second_pass_on_a_still_dead_backend_moves_checked_at_but_never_redates_the_incident(): void
    {
        $extension = $this->installedApp();
        $this->fakeDeadBackend();

        Carbon::setTestNow(Carbon::parse('2026-08-07 10:00:00'));
        $this->service->checkOne($extension);
        $extension->refresh();

        $firstIncidentAt = $extension->health_last_incident_at;
        $firstDetail = $extension->health_last_incident_detail;
        self::assertNotNull($firstIncidentAt);

        // 20 minutes plus tard, le backend est toujours mort.
        Carbon::setTestNow(Carbon::parse('2026-08-07 10:20:00'));
        $this->service->checkOne($extension);
        $extension->refresh();

        self::assertSame(
            $firstIncidentAt->toDateTimeString(),
            $extension->health_last_incident_at?->toDateTimeString(),
            'l\'incident ne doit PAS être redaté : « depuis quand » est l\'information utile',
        );
        self::assertSame($firstDetail, $extension->health_last_incident_detail);
        self::assertSame('2026-08-07 10:20:00', $extension->health_checked_at?->toDateTimeString());

        Carbon::setTestNow();
    }

    #[Test]
    public function a_recovered_backend_returns_to_ok_and_keeps_its_last_incident(): void
    {
        $extension = $this->installedApp();

        Carbon::setTestNow(Carbon::parse('2026-08-07 09:00:00'));
        $this->fakeDeadBackend();
        $this->service->checkOne($extension);
        $extension->refresh();
        $incidentAt = $extension->health_last_incident_at?->toDateTimeString();
        self::assertNotNull($incidentAt);

        // Le service redémarre.
        Carbon::setTestNow(Carbon::parse('2026-08-07 09:10:00'));
        $this->fakeBackend(200);
        $this->service->checkOne($extension);
        $extension->refresh();

        self::assertSame(Extension::HEALTH_OK, $extension->health_status);
        self::assertSame(
            $incidentAt,
            $extension->health_last_incident_at?->toDateTimeString(),
            'le dernier incident SURVIT au retour du backend — c\'est sa raison d\'être (FR34)',
        );

        Carbon::setTestNow();
    }

    /**
     * Une nouvelle panne APRÈS un retour à `ok` redate l'incident : la
     * déduplication ne porte que sur la répétition d'un même état, pas sur une
     * nouvelle occurrence. Sans ce test, une implémentation qui n'écrirait
     * l'incident qu'une seule fois dans la vie de la ligne passerait.
     */
    #[Test]
    public function a_new_outage_after_a_recovery_dates_a_new_incident(): void
    {
        $extension = $this->installedApp();

        Carbon::setTestNow(Carbon::parse('2026-08-07 09:00:00'));
        $this->fakeDeadBackend();
        $this->service->checkOne($extension);

        Carbon::setTestNow(Carbon::parse('2026-08-07 09:10:00'));
        $this->fakeBackend(200);
        $this->service->checkOne($extension);

        Carbon::setTestNow(Carbon::parse('2026-08-07 11:30:00'));
        $this->fakeDeadBackend();
        $this->service->checkOne($extension);

        $extension->refresh();
        self::assertSame('2026-08-07 11:30:00', $extension->health_last_incident_at?->toDateTimeString());

        Carbon::setTestNow();
    }

    // ══════════════════════════════════════════════════════════════════════
    // AC1 — « toute réponse HTTP prouve la joignabilité »
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function a_backend_answering_404_is_reachable(): void
    {
        $extension = $this->installedApp();
        $this->fakeBackend(404);

        $result = $this->service->checkOne($extension);

        self::assertTrue($result['reachable']);
        self::assertSame(Extension::HEALTH_OK, $extension->refresh()->health_status);
    }

    #[Test]
    public function a_backend_answering_500_is_reachable(): void
    {
        $extension = $this->installedApp();
        $this->fakeBackend(500);

        $result = $this->service->checkOne($extension);

        self::assertTrue($result['reachable'], 'un backend qui répond 500 RÉPOND : il n\'est pas injoignable');
        self::assertSame(Extension::HEALTH_OK, $extension->refresh()->health_status);
    }

    #[Test]
    public function a_backend_answering_302_is_reachable_and_the_redirect_is_not_followed(): void
    {
        $extension = $this->installedApp();
        $this->fakeBackend(302, ['Location' => 'https://evil.example.test/']);

        self::assertTrue($this->service->checkOne($extension)['reachable']);

        // `preventStrayRequests` échouerait si la redirection avait été suivie ;
        // on l'affirme aussi explicitement.
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'evil.example.test'));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Sécurité — la catégorie d'incident ne fuit rien
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function the_incident_category_carries_neither_url_nor_raw_guzzle_message(): void
    {
        $extension = $this->installedApp();
        $this->fakeDeadBackend(
            'cURL error 7: Failed to connect to 127.0.0.1 port 8600 (see https://curl.se/libcurl/c/libcurl-errors.html) for http://127.0.0.1:8600/?token=SECRET',
        );

        $detail = (string) $this->service->checkOne($extension)['category'];

        self::assertNotSame('', $detail);
        self::assertStringNotContainsString('http', $detail, 'aucune URL dans une catégorie lisible par tout admin');
        self::assertStringNotContainsString('SECRET', $detail);
        self::assertStringNotContainsString('cURL', $detail);
        self::assertLessThanOrEqual(
            ExtensionHealthService::INCIDENT_DETAIL_MAX,
            mb_strlen((string) $extension->refresh()->health_last_incident_detail),
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // AC1 — la santé n'est JAMAIS auditée (décision n° 2, pas un oubli)
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function probing_never_writes_a_single_audit_line(): void
    {
        $extension = $this->installedApp();
        $before = ExtensionAuditLog::query()->count();

        $this->fakeDeadBackend();
        $this->service->checkOne($extension);
        $this->fakeBackend(200);
        $this->service->checkOne($extension);
        $this->service->checkAll();

        self::assertSame(
            $before,
            ExtensionAuditLog::query()->count(),
            'la santé est de la TÉLÉMÉTRIE : un scheduler toutes les 5 min noierait le journal de conformité',
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // AC1 — périmètre de la sonde
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function links_and_non_installed_apps_are_never_probed(): void
    {
        Extension::factory()->fromBundled()->link('/doc')->integrated()->create(['key' => 'doc']);
        Extension::factory()->fromBundled()->app()->withInstallBlock()->create(['key' => 'not-installed']);
        // `app` marquée intégrée SANS port : rien n'a été provisionné.
        Extension::factory()->fromBundled()->app()->integrated()->create(['key' => 'portless']);

        $result = $this->service->checkAll();

        self::assertSame(0, $result['checked']);
        Http::assertNothingSent();

        foreach (['doc', 'not-installed', 'portless'] as $key) {
            self::assertSame(
                '',
                (string) Extension::query()->where('key', $key)->firstOrFail()->health_status,
            );
        }
    }

    #[Test]
    public function check_all_reports_counts_and_names_unreachable_keys(): void
    {
        $this->installedApp('alpha', 8601);
        $this->installedApp('beta', 8602);

        // Réponse DIFFÉRENCIÉE par port, dans un seul répondeur (un second
        // `Http::fake()` ne remplacerait pas le premier).
        $this->respondWith(static fn ($request): mixed => str_contains($request->url(), ':8601')
            ? Http::response('', 200)
            : Http::failedConnection('down'));

        $result = $this->service->checkAll();

        self::assertSame(2, $result['checked']);
        self::assertSame(1, $result['unreachable']);
        self::assertSame(['beta'], $result['unreachable_keys']);
    }

    /**
     * NFR6 — une extension qui échoue ne prive PAS les autres de leur mesure.
     * Sans cette isolation, un premier élément fautif laisserait tout le reste du
     * parc avec un état périmé, donc des tuiles muettes.
     */
    #[Test]
    public function a_persistence_failure_on_one_extension_never_stops_the_others(): void
    {
        $this->installedApp('alpha', 8601);
        $this->installedApp('beta', 8602);

        // Un observateur qui fait échouer la sauvegarde d'`alpha` seulement :
        // c'est la panne la plus proche du réel (ligne supprimée en concurrence,
        // contrainte, base momentanément indisponible).
        Extension::saving(function (Extension $extension): void {
            if ($extension->key === 'alpha') {
                throw new \RuntimeException('écriture impossible');
            }
        });

        try {
            $result = $this->service->checkAll();
        } finally {
            Extension::flushEventListeners();
        }

        self::assertSame(1, $result['failed']);
        self::assertSame(1, $result['checked']);
        self::assertSame(
            Extension::HEALTH_OK,
            Extension::query()->where('key', 'beta')->firstOrFail()->health_status,
            'beta doit avoir été mesurée malgré l\'échec d\'alpha',
        );
    }

    #[Test]
    public function check_one_on_a_non_monitored_extension_writes_nothing(): void
    {
        $extension = Extension::factory()->fromBundled()->link('/doc')->integrated()->create();

        $result = $this->service->checkOne($extension);

        self::assertFalse($result['monitored']);
        Http::assertNothingSent();
        self::assertNull($extension->refresh()->health_checked_at);
    }

    /**
     * Self-healing : une `app` désinstallée ne doit pas garder un `unreachable`
     * fossilisé, qui ferait mentir la fiche et le doctor pour toujours.
     */
    #[Test]
    public function check_all_resets_the_health_state_of_extensions_that_are_no_longer_installed_apps(): void
    {
        $extension = $this->installedApp();
        $this->fakeDeadBackend();
        $this->service->checkOne($extension);

        self::assertSame(Extension::HEALTH_UNREACHABLE, $extension->refresh()->health_status);

        // Exactement ce que fait `markAppRemoved()`.
        app(\App\Services\Extensions\ExtensionLifecycleService::class)->markAppRemoved($extension->id, null);

        $this->fakeBackend(200);
        $result = $this->service->checkAll();

        self::assertSame(0, $result['checked']);
        self::assertSame(1, $result['reset']);

        $extension->refresh();
        self::assertSame('', (string) $extension->health_status);
        self::assertNull($extension->health_checked_at);
        self::assertNull($extension->health_last_incident_at);
        self::assertSame('', (string) $extension->health_last_incident_detail);
    }

    /** CONTRE-ÉPREUVE : une `app` toujours installée n'est jamais réinitialisée. */
    #[Test]
    public function check_all_never_resets_a_still_installed_app(): void
    {
        $extension = $this->installedApp();
        $this->fakeDeadBackend();

        $result = $this->service->checkAll();

        self::assertSame(1, $result['checked']);
        self::assertSame(0, $result['reset']);
        self::assertSame(Extension::HEALTH_UNREACHABLE, $extension->refresh()->health_status);
    }

    // ══════════════════════════════════════════════════════════════════════
    // AC2 — fraîcheur : la règle unique du badge
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function a_fresh_unreachable_state_is_flagged_but_a_stale_one_is_not(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00'));

        $fresh = $this->installedApp('fresh', 8611);
        $stale = $this->installedApp('stale', 8612);

        $this->fakeDeadBackend('down');
        $this->service->checkOne($fresh);

        // Le même état, mais mesuré il y a 1 heure (scheduler arrêté).
        Carbon::setTestNow(Carbon::parse('2026-08-07 11:00:00'));
        $this->service->checkOne($stale);
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00'));

        self::assertTrue($fresh->refresh()->isFlaggedUnreachable());
        self::assertFalse(
            $stale->refresh()->isFlaggedUnreachable(),
            'un état périmé ne se signale PAS comme une panne : c\'est le doctor qui dit « scheduler mort »',
        );
        self::assertTrue($stale->healthIsStale());

        Carbon::setTestNow();
    }

    #[Test]
    public function a_never_probed_extension_is_never_flagged(): void
    {
        $extension = $this->installedApp();

        self::assertSame('', (string) $extension->health_status);
        self::assertFalse($extension->isFlaggedUnreachable());
        self::assertTrue($extension->healthIsStale(), 'jamais sondée ⇒ on ne SAIT pas');
    }

    #[Test]
    public function the_stale_threshold_comes_from_configuration(): void
    {
        config(['extensions.health.stale_after' => 60]);

        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00'));
        $extension = $this->installedApp();
        $this->fakeDeadBackend('down');
        $this->service->checkOne($extension);

        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:30'));
        self::assertFalse($extension->refresh()->healthIsStale());

        Carbon::setTestNow(Carbon::parse('2026-08-07 12:02:00'));
        self::assertTrue($extension->refresh()->healthIsStale());

        Carbon::setTestNow();
    }
}
