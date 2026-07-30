<?php

declare(strict_types=1);

namespace Tests\Feature\Extensions;

use App\Models\Extension;
use App\Models\ExtensionSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 56.5 (AC1) — `php artisan ext:health:check {key?}` et sa planification.
 *
 * La commande CONSTATE : elle rend 0 même quand un backend est mort (un `exit 1`
 * toutes les 5 minutes remplirait la supervision d'alertes pour un état que
 * l'admin voit déjà sur sa tuile). Le verdict, c'est le doctor.
 */
class ExtensionHealthCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var \Closure(\Illuminate\Http\Client\Request): mixed */
    private \Closure $responder;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // Fake UNIQUE délégant à une closure remplaçable (leçon 56.1 : les
        // stubs fusionnent, le premier motif gagne).
        $this->responder = static fn (): mixed => Http::response('', 200);
        Http::fake(['127.0.0.1:*' => fn ($request): mixed => ($this->responder)($request)]);
    }

    private function installedApp(string $key, int $port): Extension
    {
        return Extension::factory()
            ->for(ExtensionSource::factory()->remote('https://depot.example.test/extensions'), 'source')
            ->app()
            ->withInstallBlock()
            ->installed($port)
            ->create(['key' => $key, 'name' => ucfirst($key)]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Toutes les extensions
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function it_reports_nothing_to_probe_on_an_empty_registry(): void
    {
        $this->artisan('ext:health:check')
            ->expectsOutputToContain('Aucune extension `app` installée')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_probes_every_installed_app_and_persists_their_state(): void
    {
        $alpha = $this->installedApp('alpha', 8601);
        $beta = $this->installedApp('beta', 8602);

        $this->responder = static fn ($request): mixed => str_contains($request->url(), ':8601')
            ? Http::response('', 200)
            : Http::failedConnection('down');

        $this->artisan('ext:health:check')
            ->expectsOutputToContain('2 extension(s) sondée(s) — 1 injoignable(s).')
            ->expectsOutputToContain('beta')
            ->assertExitCode(0);

        self::assertSame(Extension::HEALTH_OK, $alpha->refresh()->health_status);
        self::assertSame(Extension::HEALTH_UNREACHABLE, $beta->refresh()->health_status);
    }

    /**
     * Le code retour reste 0 même avec TOUS les backends morts : c'est une
     * décision assumée (divergence documentée avec `ext:sources:sync`).
     */
    #[Test]
    public function it_exits_zero_even_when_every_backend_is_dead(): void
    {
        $this->installedApp('alpha', 8601);
        $this->responder = static fn (): mixed => Http::failedConnection('down');

        $this->artisan('ext:health:check')->assertExitCode(0);
    }

    #[Test]
    public function it_reports_the_number_of_health_states_reset(): void
    {
        $extension = $this->installedApp('alpha', 8601);
        $this->responder = static fn (): mixed => Http::failedConnection('down');
        $this->artisan('ext:health:check')->assertExitCode(0);

        app(\App\Services\Extensions\ExtensionLifecycleService::class)->markAppRemoved($extension->id, null);

        $this->artisan('ext:health:check')
            ->expectsOutputToContain('1 état(s) de santé remis à zéro')
            ->assertExitCode(0);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Une seule clé
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function it_probes_a_single_extension_by_key(): void
    {
        $alpha = $this->installedApp('alpha', 8601);
        $this->installedApp('beta', 8602);

        $this->artisan('ext:health:check', ['key' => 'alpha'])
            ->expectsOutputToContain('alpha : joignable.')
            ->assertExitCode(0);

        self::assertSame(Extension::HEALTH_OK, $alpha->refresh()->health_status);
        self::assertSame('', (string) Extension::query()->where('key', 'beta')->firstOrFail()->health_status);
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_dead_single_backend_names_the_diagnostic_command_without_running_it(): void
    {
        $this->installedApp('alpha', 8601);
        $this->responder = static fn (): mixed => Http::failedConnection('down');

        $this->artisan('ext:health:check', ['key' => 'alpha'])
            ->expectsOutputToContain('alpha : INJOIGNABLE')
            ->expectsOutputToContain('systemctl status sambaedu-ext-alpha')
            ->assertExitCode(0);
    }

    #[Test]
    public function an_unknown_key_is_an_invocation_error_and_exits_non_zero(): void
    {
        $this->artisan('ext:health:check', ['key' => 'nope'])
            ->expectsOutputToContain('Extension inconnue : nope')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    #[Test]
    public function a_link_extension_is_reported_as_having_nothing_to_probe(): void
    {
        Extension::factory()->fromBundled()->link('/doc')->integrated()->create(['key' => 'doc']);

        $this->artisan('ext:health:check', ['key' => 'doc'])
            ->expectsOutputToContain('n\'a pas de backend à sonder')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    // ══════════════════════════════════════════════════════════════════════
    // Planification
    // ══════════════════════════════════════════════════════════════════════

    /**
     * La sonde est planifiée toutes les 5 minutes.
     *
     * On interroge `schedule:list`, donc le Schedule RÉEL tel que le noyau
     * console l'a construit depuis `routes/console.php` — pas une relecture
     * textuelle du fichier, qui ne prouverait rien de l'enregistrement effectif.
     */
    #[Test]
    public function the_probe_is_scheduled_every_five_minutes(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        self::assertMatchesRegularExpression(
            '#\*/5\s+\*\s+\*\s+\*\s+\*\s+php artisan ext:health:check#',
            $output,
            'ext:health:check doit être planifiée toutes les 5 minutes (période dont dérive stale_after)',
        );
    }

    /**
     * Contrat de cohérence : le seuil de péremption est DÉRIVÉ de la période de
     * sonde (3 passages tolérés). Ce test empêche les deux valeurs de diverger
     * en silence — la leçon de la review 56.3 #2 (`LOCK_SECONDS` vs
     * `job_timeout`).
     */
    #[Test]
    public function the_stale_threshold_stays_consistent_with_the_probe_period(): void
    {
        self::assertSame(
            900,
            (int) config('extensions.health.stale_after'),
            'stale_after = 3 × 300 s : si la période du scheduler change, cette valeur doit changer avec elle',
        );
    }
}
