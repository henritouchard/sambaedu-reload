<?php

declare(strict_types=1);

namespace Tests\Feature\Extensions;

use App\Enums\ExtensionSourceSyncStatus;
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
 * Story 56.2 — Le moteur d'installation, prouvé sur l'HÔTE.
 *
 * Ce que cette suite verrouille, dans l'ordre d'importance :
 *
 *  1. **La frontière fail-closed.** Un paquet dont le sha256 ne correspond pas
 *     — ou une source qui ne propose plus rien, ou un bloc `install` absent —
 *     produit **ZÉRO appel au helper**. C'est l'affirmation littérale de « la
 *     vérification a lieu avant TOUTE exécution » (FR7) : la première exécution
 *     de code tiers étant le maintainer script d'apt, si le runner n'est jamais
 *     appelé, rien de tiers n'a tourné.
 *  2. **Les compensations.** Un test PARAMÉTRÉ fait échouer CHAQUE étape
 *     privilégiée et asserte la séquence EXACTE des compensations, en ordre
 *     inverse, plus l'état final : `status = available`, aucun client OIDC
 *     actif, et une relance qui réussit sans re-télécharger.
 *  3. **Le secret OIDC ne sort que par stdin** : présent dans le `write-env`,
 *     absent de TOUS les arguments de TOUS les appels, absent de l'audit.
 *
 * Le seam privilégié est doublé ({@see FakeExtensionHelperRunner}) : rien de
 * root, rien d'apt, rien de systemd, rien d'Apache n'est exécuté ici. Ce qui ne
 * peut PAS être prouvé sans VM (le helper bash lui-même, le provisioning
 * sudoers, le parcours réel) constitue la dette documentée en Section 18 du
 * runbook QA.
 */
class ExtensionInstallServiceTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://depot.example.test/extensions';

    private const PACKAGE_PATH = 'packages/sambaedu-ext-hello_1.0.0_all.deb';

    /** Octets « du paquet » : le moteur ne les interprète jamais, il les hache. */
    private const PACKAGE_BODY = 'ceci-tient-lieu-de-paquet-debian-pour-les-tests';

    private FakeExtensionHelperRunner $helper;

    private string $staging;

    /**
     * Contenu servi par le faux dépôt, indexé par URL exacte.
     *
     * ⚠️ Un SEUL `Http::fake()`, qui lit cette table à chaque requête :
     * `Http::fake()` FUSIONNE ses stubs et le premier motif gagne, donc re-faker
     * en cours de test servirait l'ancien contenu et un test « le paquet a
     * changé » vérifierait que rien n'a changé (piège documenté en 56.1).
     *
     * @var array<string, array{body: string, status: int, headers: array<string, string>}|Closure>
     */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->staging = sys_get_temp_dir().'/se5-ext-staging-'.bin2hex(random_bytes(6));
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

    // =====================================================================
    // Faux dépôt
    // =====================================================================

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
     * Une extension `app` publiée par une source, avec son paquet servi au bon
     * sha256 — le cas nominal duquel tous les autres dérivent.
     */
    private function installable(
        string $key = 'hello',
        ?ExtensionSource $source = null,
        ?string $body = null,
        array $installOverrides = [],
        string $base = self::BASE,
    ): Extension {
        $source ??= $this->source();
        $body ??= self::PACKAGE_BODY;

        $install = array_merge([
            'channel' => 'deb',
            'package' => self::PACKAGE_PATH,
            'sha256' => hash('sha256', $body),
            'redirect_paths' => [],
        ], $installOverrides);

        $extension = Extension::factory()
            ->for($source, 'source')
            ->create([
                'key' => $key,
                'name' => 'Hello',
                'version' => '1.2.3',
                'type' => ExtensionType::App,
                'manifest' => [
                    'manifest_version' => 1,
                    'id' => $key,
                    'type' => 'app',
                    'name' => 'Hello',
                    'version' => '1.2.3',
                    'entry_url' => '/ext/'.$key,
                    'icon' => 'fa-solid fa-hand',
                    'publisher' => 'QA',
                    'description' => 'Extension de test.',
                    'scopes' => [],
                    'dependencies' => [],
                    'visibility' => ['roles' => ['admin']],
                    'install' => $install,
                ],
            ]);

        $this->serveFile($base.'/'.$install['package'], $body);

        return $extension;
    }

    // =====================================================================
    // AC2 — chemin nominal
    // =====================================================================

    #[Test]
    public function the_privileged_sequence_is_exactly_the_documented_order(): void
    {
        $this->installable();

        $result = $this->service()->install('hello');

        self::assertSame('', $result['error']);
        self::assertTrue($result['changed']);
        self::assertSame([
            ExtensionInstallService::HELPER_WRITE_ENV,
            ExtensionInstallService::HELPER_INSTALL_PACKAGE,
            ExtensionInstallService::HELPER_ENABLE_SERVICE,
            ExtensionInstallService::HELPER_WRITE_FRAGMENT,
            ExtensionInstallService::HELPER_RELOAD_APACHE,
        ], $this->helper->sequence());
    }

    #[Test]
    public function the_registry_records_the_installed_version_port_and_timestamp(): void
    {
        $this->installable();

        $result = $this->service()->install('hello');

        $extension = Extension::where('key', 'hello')->firstOrFail();
        self::assertSame(ExtensionStatus::Integrated, $extension->status);
        self::assertSame('1.2.3', $extension->installed_version);
        self::assertSame(8600, $extension->installed_port);
        self::assertNotNull($extension->installed_at);
        self::assertSame(8600, $result['port']);
    }

    #[Test]
    public function the_catalog_version_and_the_installed_version_are_two_different_things(): void
    {
        $this->installable();
        $this->service()->install('hello');

        // Une re-synchro de catalogue publie une nouvelle version : elle ne doit
        // JAMAIS toucher ce qui tourne (c'est ce qui rendra la détection de mise
        // à jour possible en 56.3).
        $extension = Extension::where('key', 'hello')->firstOrFail();
        $extension->fill(['version' => '2.0.0'])->save();

        $extension->refresh();
        self::assertSame('2.0.0', $extension->version);
        self::assertSame('1.2.3', $extension->installed_version);
    }

    #[Test]
    public function the_install_columns_cannot_be_mass_assigned_by_a_catalog_upsert(): void
    {
        // Défense de fond : un manifest tiers portant `installed_port` ne doit
        // pas pouvoir se faire passer pour installé.
        $extension = $this->installable();
        $extension->refresh();

        $extension->fill([
            'installed_version' => '9.9.9',
            'installed_port' => 8666,
        ]);

        self::assertSame('', $extension->installed_version);
        self::assertNull($extension->installed_port);
    }

    #[Test]
    public function the_verified_package_lands_content_addressed_in_the_staging(): void
    {
        $this->installable();
        $this->service()->install('hello');

        $expected = $this->staging.'/hello/'.hash('sha256', self::PACKAGE_BODY).'.deb';
        self::assertFileExists($expected);
        self::assertSame(self::PACKAGE_BODY, file_get_contents($expected));

        // Aucun résidu `.tmp` : le rename est atomique.
        self::assertSame([], glob($this->staging.'/hello/.ext-*.tmp') ?: []);
    }

    #[Test]
    public function the_act_is_audited_as_install(): void
    {
        $this->installable();
        $this->service()->install('hello');

        $log = ExtensionAuditLog::where('action', ExtensionAuditLog::ACTION_INSTALL)->firstOrFail();
        self::assertSame('hello', $log->extension_key);
        self::assertNull($log->actor_user_id);
        self::assertSame(ExtensionAuditLog::ACTOR_SYSTEM, $log->actor_login);
        self::assertSame(0, ExtensionAuditLog::where('action', ExtensionAuditLog::ACTION_INSTALL_FAILED)->count());
    }

    // =====================================================================
    // NFR3 — le secret ne sort que par stdin
    // =====================================================================

    #[Test]
    public function the_oidc_secret_travels_only_through_stdin_and_never_through_argv(): void
    {
        $this->installable();
        $this->service()->install('hello');

        $stdin = (string) $this->helper->stdinFor(ExtensionInstallService::HELPER_WRITE_ENV);
        self::assertMatchesRegularExpression('/^SE5_OIDC_CLIENT_SECRET=(.+)$/m', $stdin);

        preg_match('/^SE5_OIDC_CLIENT_SECRET=(.+)$/m', $stdin, $matches);
        $secret = $matches[1];
        self::assertNotSame('', $secret);

        foreach ($this->helper->allArguments() as $arg) {
            self::assertStringNotContainsString($secret, $arg, 'Le secret ne doit apparaître dans AUCUN argument.');
        }

        // Ni dans le journal d'audit, ni dans la base des clients (seul le hash
        // sha256 y est persisté — doctrine 55.1).
        foreach (ExtensionAuditLog::all() as $log) {
            self::assertStringNotContainsString($secret, (string) $log->details);
        }

        $client = OidcClient::where('extension_key', 'hello')->firstOrFail();
        self::assertSame(hash('sha256', $secret), $client->client_secret_hash);
    }

    #[Test]
    public function the_environment_file_carries_the_whole_extension_contract(): void
    {
        $this->installable();
        $this->service()->install('hello');

        $stdin = (string) $this->helper->stdinFor(ExtensionInstallService::HELPER_WRITE_ENV);

        self::assertStringContainsString('SE5_EXT_KEY=hello', $stdin);
        self::assertStringContainsString('SE5_EXT_BASE_PATH=/ext/hello', $stdin);
        self::assertStringContainsString('SE5_EXT_PORT=8600', $stdin);
        self::assertStringContainsString('SE5_OIDC_ISSUER=https://se4fs.test', $stdin);
        self::assertStringContainsString('SE5_OIDC_REDIRECT_URI=/ext/hello/oidc/callback', $stdin);
        self::assertStringContainsString('SE5_OIDC_CLIENT_ID=', $stdin);
    }

    #[Test]
    public function the_declared_redirect_paths_win_over_the_conventional_default(): void
    {
        $this->installable(installOverrides: ['redirect_paths' => ['/ext/hello/auth/retour']]);
        $this->service()->install('hello');

        $client = OidcClient::where('extension_key', 'hello')->firstOrFail();
        self::assertSame(['/ext/hello/auth/retour'], $client->redirect_uris);
        self::assertStringContainsString(
            'SE5_OIDC_REDIRECT_URI=/ext/hello/auth/retour',
            (string) $this->helper->stdinFor(ExtensionInstallService::HELPER_WRITE_ENV),
        );
    }

    #[Test]
    public function the_apache_fragment_is_asked_for_with_the_key_and_the_assigned_port_only(): void
    {
        $this->installable();
        $this->service()->install('hello');

        self::assertSame(
            [ExtensionInstallService::HELPER_WRITE_FRAGMENT, 'hello', '8600'],
            $this->helper->argsFor(ExtensionInstallService::HELPER_WRITE_FRAGMENT),
        );

        // Aucun contenu de configuration ne transite : le fragment est GÉNÉRÉ
        // par le helper, côté root. Accepter une conf arbitraire de www-admin
        // serait un équivalent-root.
        self::assertNull($this->helper->stdinFor(ExtensionInstallService::HELPER_WRITE_FRAGMENT));
    }

    // =====================================================================
    // AC3 — fail-closed : ZÉRO exécution
    // =====================================================================

    #[Test]
    public function a_mismatching_sha256_stops_everything_before_any_privileged_call(): void
    {
        $this->installable(body: 'contenu-substitue-par-un-attaquant');
        // Le manifest annonce le sha du corps nominal ; le dépôt sert autre chose.
        $extension = Extension::where('key', 'hello')->firstOrFail();
        $manifest = $extension->manifest;
        $manifest['install']['sha256'] = hash('sha256', self::PACKAGE_BODY);
        $extension->fill(['manifest' => $manifest])->save();

        $result = $this->service()->install('hello');

        self::assertSame('sha256 du paquet non concordant', $result['error']);
        self::assertSame([], $this->helper->calls, 'Aucune exécution privilégiée ne doit avoir lieu.');
        self::assertSame(0, OidcClient::count());
        self::assertSame(ExtensionStatus::Available, Extension::where('key', 'hello')->firstOrFail()->status);
        self::assertSame([], glob($this->staging.'/hello/*') ?: [], 'Le fichier temporaire fautif doit être supprimé.');
    }

    #[Test]
    public function the_failure_is_audited_with_a_short_category_that_never_carries_the_url(): void
    {
        $this->installable(body: 'autre-contenu');
        $extension = Extension::where('key', 'hello')->firstOrFail();
        $manifest = $extension->manifest;
        $manifest['install']['sha256'] = hash('sha256', self::PACKAGE_BODY);
        $extension->fill(['manifest' => $manifest])->save();

        $this->service()->install('hello');

        $log = ExtensionAuditLog::where('action', ExtensionAuditLog::ACTION_INSTALL_FAILED)->firstOrFail();
        self::assertSame('sha256 du paquet non concordant', $log->details);
        self::assertStringNotContainsString('depot.example.test', $log->details);
        self::assertStringNotContainsString('http', $log->details);
        self::assertSame(ExtensionAuditLog::ACTOR_SYSTEM, $log->actor_login);
    }

    #[Test]
    public function every_failed_attempt_gets_its_own_audit_line(): void
    {
        // Contrairement à `source_sync_failed`, PAS de dédoublonnage : une
        // installation est un acte de l'opérateur, pas une tâche planifiée.
        $this->installable(installOverrides: ['sha256' => str_repeat('f', 64)]);

        $this->service()->install('hello');
        $this->service()->install('hello');

        self::assertSame(2, ExtensionAuditLog::where('action', ExtensionAuditLog::ACTION_INSTALL_FAILED)->count());
    }

    #[Test]
    public function an_app_without_an_install_block_is_refused_fail_closed(): void
    {
        $extension = $this->installable();
        $manifest = $extension->manifest;
        unset($manifest['install']);
        $extension->fill(['manifest' => $manifest])->save();

        $result = $this->service()->install('hello');

        self::assertSame('bloc install absent du manifest', $result['error']);
        self::assertSame([], $this->helper->calls);
        self::assertSame(0, OidcClient::count());
    }

    #[Test]
    public function an_unsupported_channel_is_refused_fail_closed(): void
    {
        $this->installable(installOverrides: ['channel' => 'snap']);

        $result = $this->service()->install('hello');

        self::assertSame('canal d\'installation non supporté', $result['error']);
        self::assertSame([], $this->helper->calls);
    }

    #[Test]
    public function a_persisted_redirect_path_outside_the_prefix_is_refused_fail_closed(): void
    {
        // Défense en profondeur : le validateur borne déjà `redirect_paths` à la
        // synchro, mais c'est ICI qu'une URI devient un client OIDC réel. La
        // garde ne doit pas dépendre de qui a écrit la ligne — et elle refuse,
        // elle ne filtre pas en silence.
        $this->installable(installOverrides: ['redirect_paths' => ['/ext/autre/oidc/callback']]);

        $result = $this->service()->install('hello');

        self::assertSame('URI de redirection hors du préfixe de l\'extension', $result['error']);
        self::assertSame([], $this->helper->calls);
        self::assertSame(0, OidcClient::count());
    }

    #[Test]
    public function a_package_announcing_a_huge_content_length_is_refused_without_being_read(): void
    {
        $source = $this->source();
        $this->installable(source: $source);
        $this->serveFile(
            self::BASE.'/'.self::PACKAGE_PATH,
            self::PACKAGE_BODY,
            200,
            ['Content-Length' => '2147483648'],
        );

        $result = $this->service()->install('hello');

        self::assertSame('paquet hors borne de taille', $result['error']);
        self::assertSame([], $this->helper->calls);
    }

    #[Test]
    public function a_package_larger_than_the_bound_is_cut_during_the_read(): void
    {
        Config::set('extensions.install.package_max_bytes', 16);
        $this->installable(body: str_repeat('X', 4096));

        $result = $this->service()->install('hello');

        self::assertSame('paquet hors borne de taille', $result['error']);
        self::assertSame([], $this->helper->calls);
        self::assertSame([], glob($this->staging.'/hello/*') ?: []);
    }

    #[Test]
    public function a_redirection_is_never_followed(): void
    {
        $this->installable();
        $this->serveFile(self::BASE.'/'.self::PACKAGE_PATH, '', 302, ['Location' => 'https://ailleurs.test/x.deb']);

        $result = $this->service()->install('hello');

        self::assertSame('téléchargement refusé (HTTP 302)', $result['error']);
        self::assertSame([], $this->helper->calls);
    }

    #[Test]
    public function a_missing_package_is_refused(): void
    {
        $this->installable();
        unset($this->files[self::BASE.'/'.self::PACKAGE_PATH]);

        $result = $this->service()->install('hello');

        self::assertSame('téléchargement refusé (HTTP 404)', $result['error']);
        self::assertSame([], $this->helper->calls);
    }

    #[Test]
    public function a_frozen_source_cannot_install_anything(): void
    {
        $this->installable(source: $this->source(['disabled' => []]));

        $result = $this->service()->install('hello');

        self::assertSame('source désactivée ou catalogue non vérifié', $result['error']);
        self::assertSame([], $this->helper->calls);
        // Aucune requête sortante : on ne télécharge pas depuis une source gelée.
        Http::assertNothingSent();
    }

    #[Test]
    public function a_source_whose_catalog_could_not_be_verified_cannot_install_anything(): void
    {
        $this->installable(source: $this->source(['syncError' => []]));

        $result = $this->service()->install('hello');

        self::assertSame('source désactivée ou catalogue non vérifié', $result['error']);
        self::assertSame([], $this->helper->calls);
        Http::assertNothingSent();
    }

    #[Test]
    public function an_unreachable_source_can_still_install_its_last_verified_catalog(): void
    {
        // Contre-épreuve NFR7 : `unreachable` n'est PAS un refus de contenu.
        // Le paquet, lui, reste vérifié par son sha256.
        $this->installable(source: $this->source(['unreachable' => []]));

        $result = $this->service()->install('hello');

        self::assertSame('', $result['error']);
        self::assertTrue($result['changed']);
    }

    // =====================================================================
    // AC4 — compensations exactes, en ordre inverse
    // =====================================================================

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function failingSteps(): array
    {
        $env = ExtensionInstallService::HELPER_WRITE_ENV;
        $pkg = ExtensionInstallService::HELPER_INSTALL_PACKAGE;
        $svc = ExtensionInstallService::HELPER_ENABLE_SERVICE;
        $frg = ExtensionInstallService::HELPER_WRITE_FRAGMENT;
        $rel = ExtensionInstallService::HELPER_RELOAD_APACHE;
        $rmEnv = ExtensionInstallService::HELPER_REMOVE_ENV;
        $rmPkg = ExtensionInstallService::HELPER_REMOVE_PACKAGE;
        $rmSvc = ExtensionInstallService::HELPER_DISABLE_SERVICE;
        $rmFrg = ExtensionInstallService::HELPER_REMOVE_FRAGMENT;

        return [
            'échec du fichier d\'environnement' => [$env, [$env]],
            'échec de l\'installation apt' => [$pkg, [$env, $pkg, $rmEnv]],
            'échec de l\'activation du service' => [$svc, [$env, $pkg, $svc, $rmPkg, $rmEnv]],
            'échec de la pose du fragment' => [$frg, [$env, $pkg, $svc, $frg, $rmSvc, $rmPkg, $rmEnv]],
            'échec du rechargement Apache' => [$rel, [$env, $pkg, $svc, $frg, $rel, $rmFrg, $rel, $rmSvc, $rmPkg, $rmEnv]],
        ];
    }

    #[Test]
    #[DataProvider('failingSteps')]
    public function a_failure_at_any_privileged_step_compensates_in_reverse_order(string $failing, array $expected): void
    {
        $this->installable();
        $this->helper->failOnSubcommand($failing);

        $result = $this->service()->install('hello');

        self::assertNotSame('', $result['error']);
        self::assertSame($expected, $this->helper->sequence());
    }

    #[Test]
    #[DataProvider('failingSteps')]
    public function a_failure_at_any_privileged_step_leaves_no_zombie_state(string $failing): void
    {
        $this->installable();
        $this->helper->failOnSubcommand($failing);

        $this->service()->install('hello');

        $extension = Extension::where('key', 'hello')->firstOrFail();
        self::assertSame(ExtensionStatus::Available, $extension->status);
        self::assertSame('', $extension->installed_version);
        self::assertNull($extension->installed_port);
        self::assertNull($extension->installed_at);

        // Le client OIDC a été révoqué : aucun client actif ne subsiste.
        self::assertSame(0, OidcClient::where('enabled', true)->count());
        self::assertGreaterThan(0, OidcClient::count(), 'Le client est révoqué, jamais supprimé (doctrine 55.1).');

        // Aucune trace d'acte réussi ; une trace d'échec nommant l'étape.
        self::assertSame(0, ExtensionAuditLog::where('action', ExtensionAuditLog::ACTION_INSTALL)->count());
        $log = ExtensionAuditLog::where('action', ExtensionAuditLog::ACTION_INSTALL_FAILED)->firstOrFail();
        self::assertStringStartsWith('échec à l\'étape ', (string) $log->details);
    }

    #[Test]
    public function a_second_installation_cannot_interleave_with_a_running_one(): void
    {
        $this->installable();

        // Le verrou est pris ailleurs (autre processus, autre requête).
        // ⚠️ `Cache::store('file')` et NON `Cache::lock()` : le store par défaut
        // du projet est APCu, qui n'a aucun support de lock — un verrou qui ne
        // verrouille rien laisserait deux installations se disputer le même
        // port et la même clé.
        $lock = Cache::store('file')->lock('extensions:install-engine', 60);
        self::assertTrue($lock->get());

        try {
            $this->service()->install('hello');
            self::fail('Le moteur doit refuser une opération concurrente.');
        } catch (ExtensionInstallException $e) {
            self::assertStringContainsString('déjà en cours', $e->getMessage());
        } finally {
            $lock->release();
        }

        self::assertSame([], $this->helper->calls);
        Http::assertNothingSent();

        // Le verrou relâché, l'installation redevient possible : il n'a pas
        // fuité.
        self::assertSame('', $this->service()->install('hello')['error']);
    }

    #[Test]
    public function a_retry_after_a_failure_succeeds_without_downloading_the_package_again(): void
    {
        $this->installable();
        $this->helper->failOnSubcommand(ExtensionInstallService::HELPER_ENABLE_SERVICE);

        $first = $this->service()->install('hello');
        self::assertNotSame('', $first['error']);

        // Le paquet VÉRIFIÉ survit à l'échec (décision #6) : la relance ne
        // re-télécharge pas.
        self::assertFileExists($this->staging.'/hello/'.hash('sha256', self::PACKAGE_BODY).'.deb');

        $this->helper->heal()->forget();
        $second = $this->service()->install('hello');

        self::assertSame('', $second['error']);
        self::assertTrue($second['changed']);
        self::assertSame(ExtensionStatus::Integrated, Extension::where('key', 'hello')->firstOrFail()->status);
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_corrupted_cached_package_is_downloaded_again_not_trusted(): void
    {
        $this->installable();
        $dir = $this->staging.'/hello';
        mkdir($dir, 0o750, true);
        file_put_contents($dir.'/'.hash('sha256', self::PACKAGE_BODY).'.deb', 'contenu-corrompu');

        $result = $this->service()->install('hello');

        self::assertSame('', $result['error']);
        self::assertSame(
            self::PACKAGE_BODY,
            file_get_contents($dir.'/'.hash('sha256', self::PACKAGE_BODY).'.deb'),
        );
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_failing_compensation_does_not_stop_the_following_ones(): void
    {
        // Best effort explicite : si `remove-package` échoue aussi, `remove-env`
        // doit quand même être tenté — sinon une compensation ratée
        // garantirait l'état zombie qu'on cherche à éviter.
        $this->installable();
        $this->helper
            ->failOnSubcommand(ExtensionInstallService::HELPER_ENABLE_SERVICE)
            ->failOnSubcommand(ExtensionInstallService::HELPER_REMOVE_PACKAGE);

        $this->service()->install('hello');

        self::assertContains(ExtensionInstallService::HELPER_REMOVE_ENV, $this->helper->sequence());
        self::assertSame(0, OidcClient::where('enabled', true)->count());
    }

    // =====================================================================
    // AC2 — no-op, unicité, ambiguïté, ports
    // =====================================================================

    #[Test]
    public function installing_an_already_installed_extension_is_a_silent_no_op(): void
    {
        $this->installable();
        $this->service()->install('hello');
        $this->helper->forget();

        $result = $this->service()->install('hello');

        self::assertFalse($result['changed']);
        self::assertSame('', $result['error']);
        self::assertSame(8600, $result['port']);
        self::assertSame([], $this->helper->calls, 'Un no-op n\'exécute RIEN.');
        self::assertSame(1, ExtensionAuditLog::where('action', ExtensionAuditLog::ACTION_INSTALL)->count());
        Http::assertSentCount(1);
    }

    #[Test]
    public function a_key_already_installed_from_another_source_is_refused(): void
    {
        $official = $this->installable(source: $this->source());
        $this->service()->install('hello', $official->source->key);

        $other = $this->source();
        $other->key = 'depot-tiers';
        $other->save();
        $this->installable(source: $other);

        $result = $this->service()->install('hello', 'depot-tiers');

        self::assertStringContainsString('clé déjà installée depuis la source', $result['error']);
        self::assertStringContainsString($official->source->key, $result['error']);
        self::assertSame(1, ExtensionAuditLog::where('action', ExtensionAuditLog::ACTION_INSTALL)->count());
    }

    #[Test]
    public function an_integrated_link_of_the_same_name_does_not_block_an_app(): void
    {
        // Review 56.2 #3 — ce que la clé RÉSERVE, c'est un paquet, une unité
        // systemd, un fragment et un préfixe `/ext/<key>`. Une `link` n'occupe
        // rien de tout cela : elle ne doit pas faire échouer l'installation
        // d'une `app` homonyme publiée par une autre source.
        // Deux lignes portent la clé `hello` : la résolution reste
        // légitimement ambiguë (l'opérateur précise `--source`), ce que ce test
        // ne conteste pas. Ce qu'il verrouille, c'est l'étape d'APRÈS : le
        // refus « clé déjà installée » ne doit plus se déclencher sur une
        // `link`.
        $linkSource = $this->source();
        $linkSource->key = 'depot-des-liens';
        $linkSource->save();
        Extension::factory()->link()->integrated()->for($linkSource, 'source')->create(['key' => 'hello']);

        $app = $this->installable();

        $result = $this->service()->install('hello', (string) $app->source->key);

        self::assertSame('', $result['error']);
        self::assertSame(
            ExtensionStatus::Integrated,
            Extension::where('key', 'hello')->where('type', ExtensionType::App)->firstOrFail()->status,
        );
    }

    #[Test]
    public function a_key_published_by_several_sources_requires_an_explicit_source(): void
    {
        $a = $this->source();
        $a->key = 'depot-a';
        $a->save();
        $b = $this->source();
        $b->key = 'depot-b';
        $b->save();

        $this->installable(source: $a);
        $this->installable(source: $b);

        try {
            $this->service()->install('hello');
            self::fail('L\'ambiguïté doit être refusée, jamais arbitrée en silence.');
        } catch (ExtensionInstallException $e) {
            self::assertStringContainsString('--source', $e->getMessage());
            self::assertStringContainsString('depot-a', $e->getMessage());
            self::assertStringContainsString('depot-b', $e->getMessage());
        }

        self::assertSame([], $this->helper->calls);
    }

    #[Test]
    public function an_explicit_source_disambiguates(): void
    {
        $a = $this->source();
        $a->key = 'depot-a';
        $a->save();
        $b = $this->source();
        $b->key = 'depot-b';
        $b->save();

        $this->installable(source: $a);
        $chosen = $this->installable(source: $b);

        $result = $this->service()->install('hello', 'depot-b');

        self::assertSame('', $result['error']);
        self::assertSame(ExtensionStatus::Integrated, $chosen->fresh()->status);
    }

    #[Test]
    public function an_unknown_source_for_a_known_key_is_refused(): void
    {
        $this->installable();

        $this->expectException(ExtensionInstallException::class);

        $this->service()->install('hello', 'depot-inexistant');
    }

    #[Test]
    public function an_unknown_key_is_refused(): void
    {
        $this->expectException(ExtensionInstallException::class);

        $this->service()->install('inconnue');
    }

    #[Test]
    public function a_link_extension_is_redirected_to_the_library_cycle(): void
    {
        Extension::factory()->link('/doc')->create(['key' => 'doc']);

        try {
            $this->service()->install('doc');
            self::fail('Une `link` ne s\'installe pas par ce moteur.');
        } catch (ExtensionInstallException $e) {
            self::assertStringContainsString('bibliothèque', $e->getMessage());
        }

        self::assertSame([], $this->helper->calls);
    }

    #[Test]
    public function the_port_allocator_fills_the_holes_left_by_removals(): void
    {
        // 8600 et 8602 pris : la prochaine installation doit prendre 8601.
        Extension::factory()->app()->create(['key' => 'a'])->forceFill(['installed_port' => 8600])->save();
        Extension::factory()->app()->create(['key' => 'b'])->forceFill(['installed_port' => 8602])->save();

        $this->installable();
        $this->service()->install('hello');

        self::assertSame(8601, Extension::where('key', 'hello')->firstOrFail()->installed_port);
    }

    #[Test]
    public function an_exhausted_port_range_is_an_explicit_refusal(): void
    {
        Config::set('extensions.install.port_range', [8600, 8601]);
        Extension::factory()->app()->create(['key' => 'a'])->forceFill(['installed_port' => 8600])->save();
        Extension::factory()->app()->create(['key' => 'b'])->forceFill(['installed_port' => 8601])->save();

        $this->installable();

        $result = $this->service()->install('hello');

        self::assertSame('plage de ports épuisée', $result['error']);
        self::assertSame([], $this->helper->calls);
        Http::assertNothingSent();
    }

    // =====================================================================
    // AC5 — désinstallation
    // =====================================================================

    #[Test]
    public function removing_replays_the_installation_backwards(): void
    {
        $this->installable();
        $this->service()->install('hello');
        $this->helper->forget();

        $result = $this->service()->remove('hello');

        self::assertSame('', $result['error']);
        self::assertTrue($result['changed']);
        self::assertSame([
            ExtensionInstallService::HELPER_DISABLE_SERVICE,
            ExtensionInstallService::HELPER_REMOVE_FRAGMENT,
            ExtensionInstallService::HELPER_RELOAD_APACHE,
            ExtensionInstallService::HELPER_REMOVE_PACKAGE,
            ExtensionInstallService::HELPER_REMOVE_ENV,
        ], $this->helper->sequence());
    }

    #[Test]
    public function removing_frees_the_registry_the_port_and_the_staging(): void
    {
        $this->installable();
        $this->service()->install('hello');

        $this->service()->remove('hello');

        $extension = Extension::where('key', 'hello')->firstOrFail();
        self::assertSame(ExtensionStatus::Available, $extension->status);
        self::assertSame('', $extension->installed_version);
        self::assertNull($extension->installed_port);
        self::assertNull($extension->installed_at);
        self::assertDirectoryDoesNotExist($this->staging.'/hello');

        $log = ExtensionAuditLog::where('action', ExtensionAuditLog::ACTION_REMOVE)->firstOrFail();
        self::assertSame('hello', $log->extension_key);
    }

    #[Test]
    public function removing_revokes_every_active_oidc_client_of_the_extension(): void
    {
        $this->installable();
        $this->service()->install('hello');

        // Fantôme d'une installation avortée antérieure : un client actif que
        // personne ne connaît plus (décision #5).
        OidcClient::query()->create([
            'extension_key' => 'hello',
            'name' => 'Client fantôme',
            'client_id' => 'fantome',
            'client_secret_hash' => hash('sha256', 'x'),
            'redirect_uris' => ['/ext/hello/oidc/callback'],
            'enabled' => true,
        ]);

        $this->service()->remove('hello');

        self::assertSame(0, OidcClient::where('extension_key', 'hello')->where('enabled', true)->count());
        self::assertSame(2, OidcClient::where('extension_key', 'hello')->count());
    }

    #[Test]
    public function removing_tolerates_components_that_are_already_gone(): void
    {
        // Le helper répond « déjà absent » (exit 0) : la désinstallation aboutit.
        $this->installable();
        $this->service()->install('hello');
        $this->helper->forget();

        $result = $this->service()->remove('hello');

        self::assertSame('', $result['error']);
        self::assertSame(ExtensionStatus::Available, Extension::where('key', 'hello')->firstOrFail()->status);
    }

    #[Test]
    public function a_failing_removal_leaves_the_state_intact_and_is_replayable(): void
    {
        $this->installable();
        $this->service()->install('hello');
        $this->helper->forget()->failOnSubcommand(ExtensionInstallService::HELPER_REMOVE_PACKAGE);

        $failed = $this->service()->remove('hello');

        self::assertStringContainsString('apt_install', $failed['error']);
        self::assertSame(ExtensionStatus::Integrated, Extension::where('key', 'hello')->firstOrFail()->status);
        self::assertSame(0, ExtensionAuditLog::where('action', ExtensionAuditLog::ACTION_REMOVE)->count());

        $this->helper->heal();
        $retry = $this->service()->remove('hello');

        self::assertSame('', $retry['error']);
        self::assertSame(ExtensionStatus::Available, Extension::where('key', 'hello')->firstOrFail()->status);
    }

    #[Test]
    public function a_removal_failing_after_the_system_teardown_never_throws_and_stays_replayable(): void
    {
        // Review 56.2 #2 — la révocation OIDC, la purge du staging et
        // `markAppRemoved()` étaient HORS du filet : une erreur de base y
        // remontait nue jusqu'à `ext:remove` (qui n'attrape
        // qu'ExtensionInstallException), en laissant les composants système
        // déjà retirés et la base disant encore « installée ». On simule la
        // panne par la disparition de la table d'audit, patron 54.2.
        $this->installable();
        $this->service()->install('hello');

        Schema::drop('extension_audit_logs');

        $result = $this->service()->remove('hello');

        self::assertFalse($result['changed']);
        self::assertStringContainsString('échec à l\'étape', $result['error']);
        self::assertSame(ExtensionStatus::Integrated, Extension::where('key', 'hello')->firstOrFail()->status);
    }

    #[Test]
    public function removing_an_extension_that_is_not_installed_is_a_silent_no_op(): void
    {
        $this->installable();

        $result = $this->service()->remove('hello');

        self::assertFalse($result['changed']);
        self::assertSame('', $result['error']);
        self::assertSame([], $this->helper->calls);
        self::assertSame(0, ExtensionAuditLog::count());
    }

    #[Test]
    public function removing_a_link_points_to_the_library_instead(): void
    {
        Extension::factory()->link('/doc')->integrated()->create(['key' => 'doc']);

        try {
            $this->service()->remove('doc');
            self::fail('Le volet `link` de FR10 vit dans la bibliothèque (54.2).');
        } catch (ExtensionInstallException $e) {
            self::assertStringContainsString('bibliothèque', $e->getMessage());
        }

        self::assertSame([], $this->helper->calls);
    }

    #[Test]
    public function removing_a_key_published_by_several_sources_is_never_ambiguous(): void
    {
        // Une seule ligne peut être installée (unicité globale) : exiger un
        // `--source` pour l'arrêter obligerait l'opérateur à retrouver ce que
        // le système sait déjà.
        $a = $this->source();
        $a->key = 'depot-a';
        $a->save();
        $b = $this->source();
        $b->key = 'depot-b';
        $b->save();

        $this->installable(source: $a);
        $installed = $this->installable(source: $b);
        $this->service()->install('hello', 'depot-b');

        $result = $this->service()->remove('hello');

        self::assertSame('', $result['error']);
        self::assertTrue($result['changed']);
        self::assertSame(ExtensionStatus::Available, $installed->fresh()->status);
    }

    #[Test]
    public function the_freed_port_is_reusable_by_the_next_installation(): void
    {
        $this->installable();
        $this->service()->install('hello');
        $this->service()->remove('hello');

        $this->helper->forget();
        $result = $this->service()->install('hello');

        self::assertSame(8600, $result['port']);
    }
}
