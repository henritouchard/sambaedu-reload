<?php

declare(strict_types=1);

namespace Tests\Feature\Extensions;

use App\Enums\ExtensionType;
use App\Models\Extension;
use App\Models\ExtensionSource;
use App\Services\Extensions\Contracts\ExtensionHelperRunner;
use App\Services\Extensions\ExtensionInstallService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeExtensionHelperRunner;
use Tests\TestCase;

/**
 * Story 56.3 (AC3, AR1) — `php artisan ext:update <key>`.
 *
 * La commande est une FAÇADE : ce qui est vérifié ici, ce n'est pas le moteur
 * (couvert par {@see ExtensionInstallServiceUpdateTest}) mais le contrat CLI —
 * codes retour, messages, et l'absence de tout secret à l'écran.
 *
 * ⚠️ Non-régression 56.2 : `ext:install` et `ext:remove` lisent désormais leurs
 * libellés d'étapes dans le service. Leurs sorties doivent être IDENTIQUES —
 * les valeurs sont verrouillées chaîne par chaîne dans
 * {@see ExtensionInstallServiceUpdateTest::the_step_labels_of_install_and_remove_are_unchanged_by_the_refactor()},
 * et rejouées ici de bout en bout.
 */
class ExtensionUpdateCommandTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://depot.example.test/extensions';

    private const V1_PATH = 'packages/sambaedu-ext-hello_1.0.0_all.deb';

    private const V1_BODY = 'paquet-v1';

    private const V2_PATH = 'packages/sambaedu-ext-hello_2.0.0_all.deb';

    private const V2_BODY = 'paquet-v2';

    private FakeExtensionHelperRunner $helper;

    private string $staging;

    /** @var array<string, array{body: string, status: int, headers: array<string, string>}|Closure> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->staging = sys_get_temp_dir().'/se5-ext-updcmd-'.bin2hex(random_bytes(6));
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

    /** @return array<string, mixed> */
    private function manifest(string $version, string $package, string $sha256): array
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
                'channel' => 'deb',
                'package' => $package,
                'sha256' => $sha256,
                'redirect_paths' => [],
            ],
        ];
    }

    private function installed(): Extension
    {
        $source = ExtensionSource::factory()->remote(self::BASE, base64_encode(random_bytes(32)))->create();

        Extension::factory()->for($source, 'source')->create([
            'key' => 'hello',
            'name' => 'Hello',
            'version' => '1.0.0',
            'type' => ExtensionType::App,
            'manifest' => $this->manifest('1.0.0', self::V1_PATH, hash('sha256', self::V1_BODY)),
        ]);

        $this->files[self::BASE.'/'.self::V1_PATH] = ['body' => self::V1_BODY, 'status' => 200, 'headers' => []];

        $this->app->make(ExtensionInstallService::class)->install('hello');
        $this->helper->forgetAll();

        return Extension::where('key', 'hello')->firstOrFail();
    }

    private function publish(string $version = '2.0.0', ?string $sha256 = null): void
    {
        $extension = Extension::where('key', 'hello')->firstOrFail();
        $extension->fill([
            'version' => $version,
            'manifest' => $this->manifest($version, self::V2_PATH, $sha256 ?? hash('sha256', self::V2_BODY)),
        ])->save();

        $this->files[self::BASE.'/'.self::V2_PATH] = ['body' => self::V2_BODY, 'status' => 200, 'headers' => []];
    }

    // =====================================================================
    // ext:update
    // =====================================================================

    #[Test]
    public function update_succeeds_and_reports_its_steps(): void
    {
        $this->installed();
        $this->publish();

        $this->artisan('ext:update hello')
            ->expectsOutputToContain('nouveau paquet téléchargé et sha256 vérifié')
            ->expectsOutputToContain('nouvelle version installée (apt)')
            ->expectsOutputToContain('service redémarré')
            ->expectsOutputToContain('mise à jour')
            ->assertExitCode(0);

        self::assertSame('2.0.0', Extension::where('key', 'hello')->firstOrFail()->installed_version);
    }

    #[Test]
    public function update_on_an_up_to_date_extension_is_a_signalled_no_op_with_exit_zero(): void
    {
        $this->installed();

        $this->artisan('ext:update hello')
            ->expectsOutputToContain('déjà à la version publiée')
            ->assertExitCode(0);

        self::assertSame([], $this->helper->calls);
    }

    #[Test]
    public function update_on_an_extension_that_is_not_installed_points_to_install(): void
    {
        $source = ExtensionSource::factory()->remote(self::BASE, base64_encode(random_bytes(32)))->create();
        Extension::factory()->for($source, 'source')->create([
            'key' => 'hello',
            'type' => ExtensionType::App,
            'version' => '1.0.0',
            'manifest' => $this->manifest('1.0.0', self::V1_PATH, hash('sha256', self::V1_BODY)),
        ]);

        $this->artisan('ext:update hello')
            ->expectsOutputToContain('n\'est pas installée')
            ->expectsOutputToContain('ext:install hello')
            ->assertExitCode(0);
    }

    #[Test]
    public function update_fails_loudly_when_the_signature_does_not_match(): void
    {
        $this->installed();
        $this->publish(sha256: str_repeat('a', 64));

        $this->artisan('ext:update hello')
            ->expectsOutputToContain('sha256 du paquet non concordant')
            ->expectsOutputToContain('version précédente a été rétablie')
            ->assertExitCode(1);

        self::assertSame('1.0.0', Extension::where('key', 'hello')->firstOrFail()->installed_version);
    }

    #[Test]
    public function update_reports_a_failing_step_and_exits_non_zero(): void
    {
        $this->installed();
        $this->publish();
        $this->helper->failOnSubcommand(ExtensionInstallService::HELPER_RESTART_SERVICE);

        $this->artisan('ext:update hello')
            ->expectsOutputToContain('Mise à jour refusée')
            ->assertExitCode(1);
    }

    #[Test]
    public function update_refuses_a_link_and_points_to_the_library(): void
    {
        Extension::factory()->link('/doc')->integrated()->create(['key' => 'doc']);

        $this->artisan('ext:update doc')
            ->expectsOutputToContain('bibliothèque')
            ->assertExitCode(1);
    }

    #[Test]
    public function update_on_an_unknown_key_exits_non_zero(): void
    {
        $this->artisan('ext:update inconnue')->assertExitCode(1);
    }

    #[Test]
    public function update_never_prints_the_client_secret(): void
    {
        // La mise à jour ne régénère AUCUN secret — mais l'invariant NFR3 se
        // vérifie, il ne se suppose pas.
        $this->installed();
        $client = \App\Models\OidcClient::where('extension_key', 'hello')->firstOrFail();
        $hash = (string) $client->client_secret_hash;
        self::assertNotSame('', $hash);

        $this->publish();

        $this->artisan('ext:update hello')->assertExitCode(0);

        foreach ($this->helper->allArguments() as $argument) {
            self::assertStringNotContainsString($hash, $argument);
        }

        // Aucun `write-env` : le fichier d'environnement — seul porteur du
        // secret en clair — n'est pas réécrit par une mise à jour.
        self::assertNull($this->helper->stdinFor(ExtensionInstallService::HELPER_WRITE_ENV));
    }

    // =====================================================================
    // Non-régression 56.2 — les sorties d'install/remove n'ont pas bougé
    // =====================================================================

    #[Test]
    public function install_still_prints_its_documented_step_labels(): void
    {
        $source = ExtensionSource::factory()->remote(self::BASE, base64_encode(random_bytes(32)))->create();
        Extension::factory()->for($source, 'source')->create([
            'key' => 'hello',
            'type' => ExtensionType::App,
            'version' => '1.0.0',
            'manifest' => $this->manifest('1.0.0', self::V1_PATH, hash('sha256', self::V1_BODY)),
        ]);
        $this->files[self::BASE.'/'.self::V1_PATH] = ['body' => self::V1_BODY, 'status' => 200, 'headers' => []];

        $this->artisan('ext:install hello')
            ->expectsOutputToContain('paquet téléchargé et sha256 vérifié')
            ->expectsOutputToContain('client OIDC enregistré')
            ->expectsOutputToContain('fichier d\'environnement posé (0600 root)')
            ->expectsOutputToContain('paquet installé (apt)')
            ->expectsOutputToContain('unité systemd activée et démarrée')
            ->expectsOutputToContain('fragment Apache posé et configuration rechargée')
            ->expectsOutputToContain('registre mis à jour et acte journalisé')
            ->assertExitCode(0);
    }

    #[Test]
    public function remove_still_prints_its_documented_step_labels(): void
    {
        $this->installed();

        $this->artisan('ext:remove hello')
            ->expectsOutputToContain('unité systemd arrêtée et désactivée')
            ->expectsOutputToContain('fragment Apache retiré et configuration rechargée')
            ->expectsOutputToContain('paquet purgé (apt)')
            ->expectsOutputToContain('fichier d\'environnement supprimé')
            ->expectsOutputToContain('clients OIDC de l\'extension révoqués (jetons morts)')
            ->expectsOutputToContain('staging du paquet nettoyé')
            ->expectsOutputToContain('registre mis à jour et acte journalisé')
            ->assertExitCode(0);
    }
}
