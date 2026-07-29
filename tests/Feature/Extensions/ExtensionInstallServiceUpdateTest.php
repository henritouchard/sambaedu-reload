<?php

declare(strict_types=1);

namespace Tests\Feature\Extensions;

use App\Enums\ExtensionStatus;
use App\Enums\ExtensionType;
use App\Exceptions\ExtensionInstallException;
use App\Models\Extension;
use App\Models\ExtensionAuditLog;
use App\Models\ExtensionSource;
use App\Models\OidcClient;
use App\Services\Extensions\Contracts\ExtensionHelperRunner;
use App\Services\Extensions\ExtensionInstallService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeExtensionHelperRunner;
use Tests\TestCase;

/**
 * Story 56.3 (AC3) — `ExtensionInstallService::update()`, prouvé sur l'HÔTE.
 *
 * Ce que cette suite verrouille, dans l'ordre d'importance :
 *
 *  1. **Le rollback est une PRÉCONDITION, pas une espérance.** Si le `.deb` de
 *     la version installée n'est pas là, ou n'est plus ce qu'il prétend être,
 *     la mise à jour est refusée AVANT d'avoir touché quoi que ce soit — zéro
 *     appel au helper. Une mise à jour dont on ne sait pas revenir n'a pas le
 *     droit de commencer.
 *  2. **Le périmètre est minimal et il le reste.** La séquence privilégiée
 *     nominale est EXACTEMENT `install-package` puis `restart-service` : ni
 *     `write-env`, ni `write-fragment`, ni `reload-apache`, ni
 *     `enable-service`. Le port, le fragment Apache et le client OIDC sont des
 *     invariants de la clé — les régénérer serait du churn à risque.
 *  3. **La frontière fail-closed de 56.2 vaut aussi ici** : un sha256 qui ne
 *     colle pas produit ZÉRO exécution privilégiée.
 *  4. **Un échec compense et ne ment pas** : l'ancien paquet est réinstallé, le
 *     service redémarré, `installed_*` reste VRAI en base, et l'audit consigne
 *     `update_failed`.
 */
class ExtensionInstallServiceUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://depot.example.test/extensions';

    private const V1_PATH = 'packages/sambaedu-ext-hello_1.0.0_all.deb';

    private const V1_BODY = 'paquet-version-1';

    private const V2_PATH = 'packages/sambaedu-ext-hello_2.0.0_all.deb';

    private const V2_BODY = 'paquet-version-2';

    private FakeExtensionHelperRunner $helper;

    private string $staging;

    /**
     * ⚠️ UN SEUL `Http::fake()` lisant cette table mutable : `Http::fake()`
     * FUSIONNE ses stubs et le premier motif gagne (piège 56.1). Or ce test
     * fait précisément « le dépôt a publié une autre version » — re-faker
     * servirait l'ancien contenu et la suite entière vérifierait que rien n'a
     * changé.
     *
     * @var array<string, array{body: string, status: int, headers: array<string, string>}|Closure>
     */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->staging = sys_get_temp_dir().'/se5-ext-update-'.bin2hex(random_bytes(6));
        Config::set('extensions.install.staging_path', $this->staging);
        Config::set('oidc.issuer', 'https://se4fs.test');

        $this->helper = new FakeExtensionHelperRunner();
        $this->app->instance(ExtensionHelperRunner::class, $this->helper);

        $this->files = [];
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->serve($request));
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->staging);

        parent::tearDown();
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach ((array) scandir($dir) as $entry) {
            if (! is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->rmrf($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    private function serve(Request $request): mixed
    {
        $url = $request->url();

        if (! array_key_exists($url, $this->files)) {
            return Http::response('not found', 404);
        }

        $file = $this->files[$url];

        return $file instanceof Closure ? $file() : Http::response($file['body'], $file['status'], $file['headers']);
    }

    private function serveFile(string $url, string $body, int $status = 200, array $headers = []): void
    {
        $this->files[$url] = ['body' => $body, 'status' => $status, 'headers' => $headers];
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    private function service(): ExtensionInstallService
    {
        return $this->app->make(ExtensionInstallService::class);
    }

    private function source(array $state = []): ExtensionSource
    {
        $factory = ExtensionSource::factory()->remote(self::BASE, base64_encode(random_bytes(32)));

        foreach ($state as $method => $args) {
            $factory = $factory->{$method}(...(array) $args);
        }

        return $factory->create();
    }

    /**
     * Une `app` publiée en 1.0.0 et RÉELLEMENT installée par le moteur : c'est
     * la seule façon d'obtenir l'état vrai (`installed_sha256` posé, `.deb`
     * vérifié en staging, client OIDC enregistré) dont dépend l'update.
     */
    private function installed(?ExtensionSource $source = null, array $redirectPaths = []): Extension
    {
        $source ??= $this->source();

        Extension::factory()
            ->for($source, 'source')
            ->create([
                'key' => 'hello',
                'name' => 'Hello',
                'version' => '1.0.0',
                'type' => ExtensionType::App,
                'manifest' => $this->manifest('1.0.0', self::V1_PATH, hash('sha256', self::V1_BODY), $redirectPaths),
            ]);

        $this->serveFile(self::BASE.'/'.self::V1_PATH, self::V1_BODY);

        $result = $this->service()->install('hello');
        self::assertSame('', $result['error'], 'la fixture doit partir d\'une installation réussie');

        // Compteur remis à zéro : `failAtCall(N)` doit désigner le Nième appel
        // de l'OPÉRATION étudiée, pas de la fixture qui l'a précédée.
        $this->helper->forgetAll();

        return Extension::where('key', 'hello')->firstOrFail();
    }

    /**
     * Le dépôt publie une nouvelle version : c'est ce que fait une re-synchro
     * de catalogue (elle réécrit `version` et `manifest`, jamais `installed_*`).
     */
    private function publish(
        string $version = '2.0.0',
        string $path = self::V2_PATH,
        ?string $body = self::V2_BODY,
        ?string $sha256 = null,
        array $redirectPaths = [],
        string $channel = 'deb',
    ): Extension {
        $extension = Extension::where('key', 'hello')->firstOrFail();

        $manifest = $this->manifest(
            $version,
            $path,
            $sha256 ?? hash('sha256', (string) $body),
            $redirectPaths,
            $channel,
        );

        $extension->fill(['version' => $version, 'manifest' => $manifest])->save();

        if ($body !== null) {
            $this->serveFile(self::BASE.'/'.$path, $body);
        }

        return $extension->refresh();
    }

    /** @return array<string, mixed> */
    private function manifest(string $version, string $package, string $sha256, array $redirectPaths, string $channel = 'deb'): array
    {
        return [
            'manifest_version' => 1,
            'id' => 'hello',
            'type' => 'app',
            'name' => 'Hello',
            'version' => $version,
            'entry_url' => '/ext/hello',
            'icon' => 'fa-solid fa-hand',
            'publisher' => 'QA',
            'description' => 'Extension de test.',
            'scopes' => [],
            'dependencies' => [],
            'visibility' => ['roles' => ['admin']],
            'install' => [
                'channel' => $channel,
                'package' => $package,
                'sha256' => $sha256,
                'redirect_paths' => $redirectPaths,
            ],
        ];
    }

    // =====================================================================
    // AC3 — chemin nominal : le paquet et le service, RIEN D'AUTRE
    // =====================================================================

    #[Test]
    public function the_update_touches_exactly_the_package_and_the_service(): void
    {
        $this->installed();
        $this->publish();

        $result = $this->service()->update('hello');

        self::assertSame('', $result['error']);
        self::assertTrue($result['changed']);
        self::assertSame([
            ExtensionInstallService::HELPER_INSTALL_PACKAGE,
            ExtensionInstallService::HELPER_RESTART_SERVICE,
        ], $this->helper->sequence());
    }

    #[Test]
    public function the_update_never_regenerates_the_env_the_fragment_or_the_oidc_client(): void
    {
        $extension = $this->installed();
        $clientBefore = OidcClient::where('extension_key', 'hello')->firstOrFail();
        $portBefore = (int) $extension->installed_port;

        $this->publish();
        $this->service()->update('hello');

        foreach ([
            ExtensionInstallService::HELPER_WRITE_ENV,
            ExtensionInstallService::HELPER_WRITE_FRAGMENT,
            ExtensionInstallService::HELPER_RELOAD_APACHE,
            ExtensionInstallService::HELPER_ENABLE_SERVICE,
            ExtensionInstallService::HELPER_REMOVE_PACKAGE,
        ] as $forbidden) {
            self::assertSame(0, $this->helper->countOf($forbidden), $forbidden.' n\'a rien à faire dans une mise à jour');
        }

        $clientAfter = OidcClient::where('extension_key', 'hello')->firstOrFail();
        self::assertSame($clientBefore->client_id, $clientAfter->client_id);
        self::assertSame($clientBefore->client_secret_hash, $clientAfter->client_secret_hash);
        self::assertTrue((bool) $clientAfter->enabled);
        self::assertSame(1, OidcClient::where('extension_key', 'hello')->count());

        self::assertSame($portBefore, (int) Extension::where('key', 'hello')->firstOrFail()->installed_port);
    }

    #[Test]
    public function the_registry_records_the_new_version_and_the_new_package_fingerprint(): void
    {
        $this->installed();
        $this->publish();

        $result = $this->service()->update('hello');

        $extension = Extension::where('key', 'hello')->firstOrFail();
        self::assertSame(ExtensionStatus::Integrated, $extension->status);
        self::assertSame('2.0.0', $extension->installed_version);
        self::assertSame(hash('sha256', self::V2_BODY), $extension->installed_sha256);
        self::assertSame(8600, $result['port']);
    }

    #[Test]
    public function the_act_is_audited_as_update(): void
    {
        $this->installed();
        $this->publish();

        $this->service()->update('hello');

        $log = ExtensionAuditLog::where('action', ExtensionAuditLog::ACTION_UPDATE)->firstOrFail();
        self::assertSame('hello', $log->extension_key);
        self::assertSame(ExtensionAuditLog::ACTOR_SYSTEM, $log->actor_login);
        self::assertSame(0, ExtensionAuditLog::where('action', ExtensionAuditLog::ACTION_UPDATE_FAILED)->count());
    }

    #[Test]
    public function the_new_package_lands_beside_the_old_one_in_the_staging(): void
    {
        // Content-addressed : les deux coexistent, et c'est justement ce qui
        // rendra un futur rollback possible.
        $this->installed();
        $this->publish();
        $this->service()->update('hello');

        self::assertFileExists($this->staging.'/hello/'.hash('sha256', self::V1_BODY).'.deb');
        self::assertFileExists($this->staging.'/hello/'.hash('sha256', self::V2_BODY).'.deb');
    }

    // =====================================================================
    // AC3 — no-op signalés
    // =====================================================================

    #[Test]
    public function updating_to_the_same_version_is_a_signalled_no_op(): void
    {
        $this->installed();

        $result = $this->service()->update('hello');

        self::assertFalse($result['changed']);
        self::assertSame('', $result['error']);
        self::assertSame(ExtensionStatus::Integrated->value, $result['status']);
        self::assertSame([], $this->helper->calls, 'un no-op n\'exécute RIEN');
        self::assertSame(0, ExtensionAuditLog::where('action', ExtensionAuditLog::ACTION_UPDATE)->count());
        self::assertSame(0, ExtensionAuditLog::where('action', ExtensionAuditLog::ACTION_UPDATE_FAILED)->count());
    }

    #[Test]
    public function updating_an_extension_that_is_not_installed_is_a_signalled_no_op(): void
    {
        // Écran périmé : l'autre admin a désinstallé entre-temps. Ce n'est pas
        // un échec, c'est un clic qui n'a plus d'objet — donc ni audit, ni
        // message d'erreur (même doctrine que `remove()` sur une disponible).
        $source = $this->source();
        Extension::factory()->for($source, 'source')->create([
            'key' => 'hello',
            'type' => ExtensionType::App,
            'version' => '2.0.0',
            'manifest' => $this->manifest('2.0.0', self::V2_PATH, hash('sha256', self::V2_BODY), []),
        ]);

        $result = $this->service()->update('hello');

        self::assertFalse($result['changed']);
        self::assertSame('', $result['error']);
        self::assertSame(ExtensionStatus::Available->value, $result['status']);
        self::assertSame([], $this->helper->calls);
        self::assertSame(0, ExtensionAuditLog::count());
        Http::assertNothingSent();
    }

    // =====================================================================
    // AC3 — fail-closed : rien ne s'exécute sans garantie
    // =====================================================================

    #[Test]
    public function a_mismatching_sha256_on_the_new_package_stops_everything(): void
    {
        $this->installed();
        // Le manifest annonce le sha d'un contenu, le dépôt en sert un autre.
        $this->publish(sha256: hash('sha256', 'ce-que-le-manifest-annonce'));

        $result = $this->service()->update('hello');

        self::assertSame('sha256 du paquet non concordant', $result['error']);
        self::assertSame([], $this->helper->calls, 'aucune exécution privilégiée ne doit avoir lieu');

        $extension = Extension::where('key', 'hello')->firstOrFail();
        self::assertSame('1.0.0', $extension->installed_version);
        self::assertSame(hash('sha256', self::V1_BODY), $extension->installed_sha256);

        $log = ExtensionAuditLog::where('action', ExtensionAuditLog::ACTION_UPDATE_FAILED)->firstOrFail();
        self::assertSame('sha256 du paquet non concordant', $log->details);
        self::assertStringNotContainsString('depot.example.test', (string) $log->details);
    }

    #[Test]
    public function changed_redirect_paths_are_refused_before_any_action(): void
    {
        $this->installed();
        $this->publish(redirectPaths: ['/ext/hello/nouveau/callback']);

        $result = $this->service()->update('hello');

        self::assertSame(ExtensionInstallService::ERROR_REDIRECT_PATHS_CHANGED, $result['error']);
        self::assertSame([], $this->helper->calls);
        Http::assertSentCount(1, 'le paquet de la 1.0.0 seulement : rien n\'est téléchargé pour un refus');
        self::assertSame('1.0.0', Extension::where('key', 'hello')->firstOrFail()->installed_version);
        self::assertSame(
            ExtensionInstallService::ERROR_REDIRECT_PATHS_CHANGED,
            ExtensionAuditLog::where('action', ExtensionAuditLog::ACTION_UPDATE_FAILED)->firstOrFail()->details,
        );
    }

    #[Test]
    public function reordered_redirect_paths_are_not_a_change(): void
    {
        // Un réordonnancement ne change rien au comportement du client OIDC
        // (l'égalité est exacte URI par URI à l'usage). Refuser pour cela
        // serait un faux positif qui bloquerait une mise à jour légitime.
        $this->installed(redirectPaths: ['/ext/hello/a', '/ext/hello/b']);
        $this->publish(redirectPaths: ['/ext/hello/b', '/ext/hello/a']);

        $result = $this->service()->update('hello');

        self::assertSame('', $result['error']);
        self::assertTrue($result['changed']);
    }

    #[Test]
    public function a_redirect_path_outside_the_prefix_is_refused_fail_closed(): void
    {
        $this->installed();
        $this->publish(redirectPaths: ['/ext/autre/callback']);

        $result = $this->service()->update('hello');

        self::assertSame('URI de redirection hors du préfixe de l\'extension', $result['error']);
        self::assertSame([], $this->helper->calls);
    }

    #[Test]
    public function a_missing_rollback_package_refuses_the_update_before_acting(): void
    {
        $this->installed();
        $this->publish();

        // Le staging a été purgé (ménage manuel, volume recréé) : plus aucune
        // garantie de retour arrière.
        $this->rmrf($this->staging.'/hello');

        $result = $this->service()->update('hello');

        self::assertSame(ExtensionInstallService::ERROR_ROLLBACK_PACKAGE_MISSING, $result['error']);
        self::assertSame([], $this->helper->calls);
        Http::assertSentCount(1, 'le nouveau paquet n\'est même pas téléchargé');
        self::assertSame('1.0.0', Extension::where('key', 'hello')->firstOrFail()->installed_version);
    }

    #[Test]
    public function a_corrupted_rollback_package_refuses_the_update_before_acting(): void
    {
        // Le nom du fichier ne fait jamais foi : il est RE-HACHÉ.
        $this->installed();
        $this->publish();

        file_put_contents(
            $this->staging.'/hello/'.hash('sha256', self::V1_BODY).'.deb',
            'octets-corrompus',
        );

        $result = $this->service()->update('hello');

        self::assertSame(ExtensionInstallService::ERROR_ROLLBACK_PACKAGE_MISSING, $result['error']);
        self::assertSame([], $this->helper->calls);
    }

    #[Test]
    public function an_extension_installed_before_the_fingerprint_column_cannot_be_updated(): void
    {
        // Dette assumée de la migration : `installed_sha256 = ''` ⇒ refus
        // explicite plutôt qu'une mise à jour dont on ne saurait pas revenir.
        $extension = $this->installed();
        $extension->forceFill(['installed_sha256' => ''])->save();
        $this->publish();

        $result = $this->service()->update('hello');

        self::assertSame(ExtensionInstallService::ERROR_ROLLBACK_PACKAGE_MISSING, $result['error']);
        self::assertSame([], $this->helper->calls);
    }

    #[Test]
    public function an_integrated_app_without_an_active_oidc_client_is_refused(): void
    {
        $this->installed();
        OidcClient::query()->where('extension_key', 'hello')->update(['enabled' => false]);
        $this->publish();

        $result = $this->service()->update('hello');

        self::assertSame(ExtensionInstallService::ERROR_OIDC_CLIENT_MISSING, $result['error']);
        self::assertSame([], $this->helper->calls);
    }

    #[Test]
    public function a_frozen_source_cannot_update_anything(): void
    {
        $source = $this->source();
        $this->installed($source);
        $this->publish();

        $source->enabled = false;
        $source->save();

        $result = $this->service()->update('hello');

        self::assertSame('source désactivée ou catalogue non vérifié', $result['error']);
        self::assertSame([], $this->helper->calls);
    }

    #[Test]
    public function a_source_whose_catalog_could_not_be_verified_cannot_update_anything(): void
    {
        $source = $this->source();
        $this->installed($source);
        $this->publish();

        $source->sync_status = \App\Enums\ExtensionSourceSyncStatus::Error;
        $source->save();

        $result = $this->service()->update('hello');

        self::assertSame('source désactivée ou catalogue non vérifié', $result['error']);
        self::assertSame([], $this->helper->calls);
    }

    #[Test]
    public function a_new_manifest_without_a_usable_install_block_is_refused(): void
    {
        $this->installed();
        $this->publish(channel: 'snap');

        $result = $this->service()->update('hello');

        self::assertSame('canal d\'installation non supporté', $result['error']);
        self::assertSame([], $this->helper->calls);
    }

    #[Test]
    public function updating_a_link_points_to_the_library_instead(): void
    {
        Extension::factory()->link('/doc')->integrated()->create(['key' => 'doc']);

        try {
            $this->service()->update('doc');
            self::fail('Une `link` n\'a rien à mettre à jour.');
        } catch (ExtensionInstallException $e) {
            self::assertStringContainsString('bibliothèque', $e->getMessage());
        }

        self::assertSame([], $this->helper->calls);
    }

    #[Test]
    public function an_unknown_key_is_refused(): void
    {
        $this->expectException(ExtensionInstallException::class);

        $this->service()->update('inconnue');
    }

    #[Test]
    public function a_second_operation_cannot_interleave_with_a_running_one(): void
    {
        $this->installed();
        $this->publish();

        $lock = Cache::store('file')->lock('extensions:install-engine', 60);
        self::assertTrue($lock->get());

        try {
            $this->service()->update('hello');
            self::fail('Le moteur doit refuser une opération concurrente.');
        } catch (ExtensionInstallException $e) {
            self::assertStringContainsString('déjà en cours', $e->getMessage());
        } finally {
            $lock->release();
        }

        self::assertSame([], $this->helper->calls);
    }

    // =====================================================================
    // AC3 — compensations : retour à la version installée
    // =====================================================================

    /**
     * Le plan de mise à jour n'a que DEUX étapes privilégiées ; on les fait
     * échouer l'une après l'autre, par RANG et non par sous-commande : la
     * compensation rejoue `install-package`, et la faire échouer elle aussi ne
     * prouverait que la tolérance aux compensations ratées (déjà couverte
     * en 56.2).
     *
     * @return array<string, array{0: int}>
     */
    public static function failingSteps(): array
    {
        return [
            'échec de l\'installation du nouveau paquet' => [1],
            'échec du redémarrage du service' => [2],
        ];
    }

    #[Test]
    #[DataProvider('failingSteps')]
    public function a_failure_reinstalls_the_previous_package_and_restarts(int $failingCall): void
    {
        $this->installed();
        $this->publish();

        $this->helper->failAtCall($failingCall);

        $result = $this->service()->update('hello');

        self::assertStringStartsWith('échec à l\'étape ', $result['error']);

        $sequence = $this->helper->sequence();
        // Dans les deux cas, la compensation est la MÊME et arrive en dernier :
        // ré-installer l'ancien `.deb`, puis redémarrer.
        self::assertSame(
            [ExtensionInstallService::HELPER_INSTALL_PACKAGE, ExtensionInstallService::HELPER_RESTART_SERVICE],
            array_slice($sequence, -2),
        );

        // Le paquet réinstallé est bien celui de la version d'AVANT.
        $lastInstall = null;
        foreach ($this->helper->calls as $call) {
            if (($call['args'][0] ?? '') === ExtensionInstallService::HELPER_INSTALL_PACKAGE) {
                $lastInstall = $call['args'];
            }
        }
        self::assertNotNull($lastInstall);
        self::assertStringEndsWith(hash('sha256', self::V1_BODY).'.deb', (string) $lastInstall[2]);
    }

    #[Test]
    public function the_engine_lock_always_outlives_the_job_budget(): void
    {
        // Review 56.3 #2 — le verrou du store `file` n'est pas lié à la vie du
        // processus : c'est une entrée à expiration. Un TTL plus COURT que le
        // budget du Job ouvre une fenêtre où un second run acquiert le « même »
        // verrou pendant que le premier travaille encore — deux allocations de
        // port, deux transactions entrelacées. Les deux réglages doivent donc
        // rester liés par construction, pas par vigilance.
        $lockSeconds = new \ReflectionMethod(ExtensionInstallService::class, 'lockSeconds');

        foreach ([1800, 3600, 60] as $jobTimeout) {
            config(['extensions.install.job_timeout' => $jobTimeout]);

            $effective = (int) $lockSeconds->invoke($this->service());

            self::assertGreaterThan(
                $jobTimeout,
                $effective,
                "un job de {$jobTimeout}s ne doit jamais survivre à son propre verrou",
            );
        }
    }

    #[Test]
    public function a_rollback_that_fails_says_so_instead_of_claiming_the_service_is_back(): void
    {
        // Review 56.3 #1 — l'angle mort : l'échec DE LA COMPENSATION. Le
        // redémarrage nominal échoue (appel 2), la compensation réinstalle
        // l'ancien paquet (appel 3) puis échoue à son tour au redémarrage
        // (appel 4). Jusqu'ici ce cas rendait EXACTEMENT le même message,
        // la même trace d'audit et le même état qu'un rollback réussi — un
        // service pouvait rester arrêté pendant que tout le monde affirmait
        // « la version précédente tourne toujours ».
        $this->installed();
        $this->publish();

        $this->helper->failAtCall(2)->failAtCall(4);

        $result = $this->service()->update('hello');

        self::assertSame(ExtensionInstallService::ERROR_ROLLBACK_FAILED, $result['error']);
        self::assertStringContainsString('intervention manuelle', $result['error']);

        // La compensation a bien été TENTÉE malgré tout (best effort conservé).
        self::assertSame(
            [ExtensionInstallService::HELPER_INSTALL_PACKAGE, ExtensionInstallService::HELPER_RESTART_SERVICE],
            array_slice($this->helper->sequence(), -2),
        );

        // Et la trace d'audit porte la même catégorie que ce qui est affiché.
        $entry = ExtensionAuditLog::query()->where('action', ExtensionAuditLog::ACTION_UPDATE_FAILED)->latest('id')->first();
        self::assertNotNull($entry);
        self::assertStringContainsString('ÉCHOUÉ', (string) $entry->details);
    }

    #[Test]
    public function a_rollback_that_succeeds_keeps_the_ordinary_step_message(): void
    {
        // Contre-épreuve du test ci-dessus : sans échec de compensation, le
        // message reste celui de l'étape fautive — la nouvelle catégorie ne
        // doit pas contaminer le cas nominal d'échec.
        $this->installed();
        $this->publish();

        $this->helper->failAtCall(2);

        $result = $this->service()->update('hello');

        self::assertStringStartsWith('échec à l\'étape ', $result['error']);
        self::assertNotSame(ExtensionInstallService::ERROR_ROLLBACK_FAILED, $result['error']);
    }

    #[Test]
    #[DataProvider('failingSteps')]
    public function a_failure_leaves_the_registry_telling_the_truth(int $failingCall): void
    {
        $this->installed();
        $this->publish();
        $this->helper->failAtCall($failingCall);

        $this->service()->update('hello');

        $extension = Extension::where('key', 'hello')->firstOrFail();
        self::assertSame(ExtensionStatus::Integrated, $extension->status);
        self::assertSame('1.0.0', $extension->installed_version, 'la base doit dire ce qui TOURNE');
        self::assertSame(hash('sha256', self::V1_BODY), $extension->installed_sha256);
        self::assertSame(8600, $extension->installed_port);

        self::assertSame(0, ExtensionAuditLog::where('action', ExtensionAuditLog::ACTION_UPDATE)->count());
        $log = ExtensionAuditLog::where('action', ExtensionAuditLog::ACTION_UPDATE_FAILED)->firstOrFail();
        self::assertStringStartsWith('échec à l\'étape ', (string) $log->details);
    }

    #[Test]
    public function a_failing_database_step_also_rolls_the_package_back(): void
    {
        // La transaction finale est la DERNIÈRE étape : si elle échoue, le
        // système doit revenir à la version d'avant. On simule la panne par la
        // disparition de la table d'audit (patron 54.2/56.2).
        $this->installed();
        $this->publish();

        Schema::drop('extension_audit_logs');

        $result = $this->service()->update('hello');

        self::assertStringContainsString('échec à l\'étape', $result['error']);

        // 2 appels nominaux + 2 de compensation.
        self::assertSame([
            ExtensionInstallService::HELPER_INSTALL_PACKAGE,
            ExtensionInstallService::HELPER_RESTART_SERVICE,
            ExtensionInstallService::HELPER_INSTALL_PACKAGE,
            ExtensionInstallService::HELPER_RESTART_SERVICE,
        ], $this->helper->sequence());

        self::assertSame('1.0.0', Extension::where('key', 'hello')->firstOrFail()->installed_version);
    }

    #[Test]
    public function a_retry_after_a_failure_succeeds_without_downloading_the_package_again(): void
    {
        $this->installed();
        $this->publish();
        $this->helper->failOnSubcommand(ExtensionInstallService::HELPER_RESTART_SERVICE);

        self::assertNotSame('', $this->service()->update('hello')['error']);
        Http::assertSentCount(2);

        $this->helper->heal()->forget();
        $second = $this->service()->update('hello');

        self::assertSame('', $second['error']);
        self::assertSame('2.0.0', Extension::where('key', 'hello')->firstOrFail()->installed_version);
        Http::assertSentCount(2, 'le paquet vérifié survit à l\'échec : pas de re-téléchargement');
    }

    // =====================================================================
    // AC2 — rapport de progression (le pont vers l'UI)
    // =====================================================================

    #[Test]
    public function the_progress_callback_reports_every_completed_step_in_order(): void
    {
        $this->installed();
        $this->publish();

        $reported = [];
        $result = $this->service()->update('hello', null, function (string $step) use (&$reported): void {
            $reported[] = $step;
        });

        self::assertSame([
            ExtensionInstallService::STEP_PACKAGE,
            ExtensionInstallService::STEP_APT,
            ExtensionInstallService::STEP_SERVICE,
            ExtensionInstallService::STEP_REGISTRY,
        ], $reported);
        self::assertSame($reported, $result['steps'], 'le rapport et le retour disent la même chose');
    }

    #[Test]
    public function a_progress_callback_that_throws_never_breaks_the_operation(): void
    {
        // Le callback écrit en base (la ligne de run de l'UI). Si son échec
        // remontait dans le plan, il déclencherait les compensations d'une
        // mise à jour pourtant réussie — un canal d'AFFICHAGE n'a pas le droit
        // de désinstaller quoi que ce soit.
        $this->installed();
        $this->publish();

        $result = $this->service()->update('hello', null, function (): void {
            throw new \RuntimeException('base de runs indisponible');
        });

        self::assertSame('', $result['error']);
        self::assertTrue($result['changed']);
        self::assertSame('2.0.0', Extension::where('key', 'hello')->firstOrFail()->installed_version);
    }

    #[Test]
    public function the_install_progress_callback_reports_the_documented_step_order(): void
    {
        // Non-régression du plan 56.2 : l'ajout du rapport ne réordonne rien.
        $source = $this->source();
        Extension::factory()->for($source, 'source')->create([
            'key' => 'hello',
            'name' => 'Hello',
            'version' => '1.0.0',
            'type' => ExtensionType::App,
            'manifest' => $this->manifest('1.0.0', self::V1_PATH, hash('sha256', self::V1_BODY), []),
        ]);
        $this->serveFile(self::BASE.'/'.self::V1_PATH, self::V1_BODY);

        $reported = [];
        $this->service()->install('hello', null, null, function (string $step) use (&$reported): void {
            $reported[] = $step;
        });

        self::assertSame([
            ExtensionInstallService::STEP_PACKAGE,
            ExtensionInstallService::STEP_OIDC,
            ExtensionInstallService::STEP_ENV,
            ExtensionInstallService::STEP_APT,
            ExtensionInstallService::STEP_SERVICE,
            ExtensionInstallService::STEP_APACHE,
            ExtensionInstallService::STEP_REGISTRY,
        ], $reported);
    }

    #[Test]
    public function the_remove_progress_callback_reports_the_reverse_order(): void
    {
        $this->installed();

        $reported = [];
        $this->service()->remove('hello', null, function (string $step) use (&$reported): void {
            $reported[] = $step;
        });

        self::assertSame([
            ExtensionInstallService::STEP_SERVICE,
            ExtensionInstallService::STEP_APACHE,
            ExtensionInstallService::STEP_APT,
            ExtensionInstallService::STEP_ENV,
            ExtensionInstallService::STEP_OIDC,
            ExtensionInstallService::STEP_PACKAGE,
            ExtensionInstallService::STEP_REGISTRY,
        ], $reported);
    }

    // =====================================================================
    // Libellés d'étapes : un seul énoncé, quatre consommateurs
    // =====================================================================

    #[Test]
    public function every_step_of_every_operation_has_a_french_label(): void
    {
        $plans = [
            \App\Models\ExtensionInstallRun::OPERATION_INSTALL => [
                ExtensionInstallService::STEP_PACKAGE,
                ExtensionInstallService::STEP_OIDC,
                ExtensionInstallService::STEP_ENV,
                ExtensionInstallService::STEP_APT,
                ExtensionInstallService::STEP_SERVICE,
                ExtensionInstallService::STEP_APACHE,
                ExtensionInstallService::STEP_REGISTRY,
            ],
            \App\Models\ExtensionInstallRun::OPERATION_REMOVE => [
                ExtensionInstallService::STEP_SERVICE,
                ExtensionInstallService::STEP_APACHE,
                ExtensionInstallService::STEP_APT,
                ExtensionInstallService::STEP_ENV,
                ExtensionInstallService::STEP_OIDC,
                ExtensionInstallService::STEP_PACKAGE,
                ExtensionInstallService::STEP_REGISTRY,
            ],
            \App\Models\ExtensionInstallRun::OPERATION_UPDATE => [
                ExtensionInstallService::STEP_PACKAGE,
                ExtensionInstallService::STEP_APT,
                ExtensionInstallService::STEP_SERVICE,
                ExtensionInstallService::STEP_REGISTRY,
            ],
        ];

        foreach ($plans as $operation => $steps) {
            $labels = ExtensionInstallService::stepLabels($operation);
            foreach ($steps as $step) {
                self::assertArrayHasKey($step, $labels, "l'étape {$step} de {$operation} doit avoir un libellé");
                self::assertNotSame('', $labels[$step]);
            }
        }
    }

    #[Test]
    public function the_step_labels_of_install_and_remove_are_unchanged_by_the_refactor(): void
    {
        // Verrou de non-régression des sorties CLI 56.2 : ces chaînes étaient
        // écrites en dur dans `ExtensionInstall` et `ExtensionRemove`.
        $install = ExtensionInstallService::stepLabels(\App\Models\ExtensionInstallRun::OPERATION_INSTALL);
        self::assertSame('paquet téléchargé et sha256 vérifié', $install[ExtensionInstallService::STEP_PACKAGE]);
        self::assertSame('client OIDC enregistré', $install[ExtensionInstallService::STEP_OIDC]);
        self::assertSame('fichier d\'environnement posé (0600 root)', $install[ExtensionInstallService::STEP_ENV]);
        self::assertSame('paquet installé (apt)', $install[ExtensionInstallService::STEP_APT]);
        self::assertSame('unité systemd activée et démarrée', $install[ExtensionInstallService::STEP_SERVICE]);
        self::assertSame('fragment Apache posé et configuration rechargée', $install[ExtensionInstallService::STEP_APACHE]);
        self::assertSame('registre mis à jour et acte journalisé', $install[ExtensionInstallService::STEP_REGISTRY]);

        $remove = ExtensionInstallService::stepLabels(\App\Models\ExtensionInstallRun::OPERATION_REMOVE);
        self::assertSame('unité systemd arrêtée et désactivée', $remove[ExtensionInstallService::STEP_SERVICE]);
        self::assertSame('fragment Apache retiré et configuration rechargée', $remove[ExtensionInstallService::STEP_APACHE]);
        self::assertSame('paquet purgé (apt)', $remove[ExtensionInstallService::STEP_APT]);
        self::assertSame('fichier d\'environnement supprimé', $remove[ExtensionInstallService::STEP_ENV]);
        self::assertSame('clients OIDC de l\'extension révoqués (jetons morts)', $remove[ExtensionInstallService::STEP_OIDC]);
        self::assertSame('staging du paquet nettoyé', $remove[ExtensionInstallService::STEP_PACKAGE]);
        self::assertSame('registre mis à jour et acte journalisé', $remove[ExtensionInstallService::STEP_REGISTRY]);
    }

    // =====================================================================
    // Non-régression 56.2 — l'installation pose désormais l'empreinte
    // =====================================================================

    #[Test]
    public function installing_records_the_fingerprint_of_the_package_actually_posted(): void
    {
        $extension = $this->installed();

        self::assertSame(hash('sha256', self::V1_BODY), $extension->installed_sha256);
    }

    #[Test]
    public function removing_clears_the_fingerprint(): void
    {
        $this->installed();

        $this->service()->remove('hello');

        $extension = Extension::where('key', 'hello')->firstOrFail();
        self::assertSame('', $extension->installed_sha256);
        self::assertDirectoryDoesNotExist($this->staging.'/hello');
    }
}
