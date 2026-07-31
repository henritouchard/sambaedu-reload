<?php

declare(strict_types=1);

namespace Tests\Feature\Extensions;

use App\Enums\ExtensionStatus;
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
 * Story 56.2 (AC2/AC3/AC5) — Les deux façades CLI du moteur.
 *
 * Ce qu'on vérifie ici n'est PAS le moteur (couvert par
 * {@see ExtensionInstallServiceTest}) mais le contrat de la commande : codes de
 * sortie, `--source`, no-op signalé en succès, et surtout **aucun secret dans
 * la sortie** — l'historique d'un terminal et les journaux d'exploitation sont
 * deux endroits où un `client_secret` ne doit jamais atterrir (NFR3).
 */
class ExtensionInstallCommandsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://depot.example.test/extensions';

    private const PACKAGE_PATH = 'packages/sambaedu-ext-hello_1.0.0_all.deb';

    private const PACKAGE_BODY = 'paquet-de-test';

    private FakeExtensionHelperRunner $helper;

    private string $staging;

    /** @var array<string, array{body: string, status: int, headers: array<string, string>}|Closure> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->staging = sys_get_temp_dir().'/se5-ext-cmd-'.bin2hex(random_bytes(6));
        Config::set('extensions.install.staging_path', $this->staging);

        $this->helper = new FakeExtensionHelperRunner();
        $this->app->instance(ExtensionHelperRunner::class, $this->helper);

        $this->files = [];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $url = $request->url();

            return array_key_exists($url, $this->files)
                ? Http::response($this->files[$url]['body'], $this->files[$url]['status'])
                : Http::response('not found', 404);
        });
    }

    protected function tearDown(): void
    {
        if (is_dir($this->staging)) {
            foreach ((array) glob($this->staging.'/*/*') as $file) {
                @unlink((string) $file);
            }
            foreach ((array) glob($this->staging.'/*') as $dir) {
                @rmdir((string) $dir);
            }
            @rmdir($this->staging);
        }

        parent::tearDown();
    }

    private function installable(string $key = 'hello', ?ExtensionSource $source = null, ?string $sha = null): Extension
    {
        $source ??= ExtensionSource::factory()->remote(self::BASE, base64_encode(random_bytes(32)))->create();

        $extension = Extension::factory()->for($source, 'source')->create([
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
                'install' => [
                    'channel' => 'deb',
                    'package' => self::PACKAGE_PATH,
                    'sha256' => $sha ?? hash('sha256', self::PACKAGE_BODY),
                    'redirect_paths' => [],
                ],
            ],
        ]);

        $this->files[self::BASE.'/'.self::PACKAGE_PATH] = ['body' => self::PACKAGE_BODY, 'status' => 200];

        return $extension;
    }

    // =====================================================================
    // ext:install
    // =====================================================================

    #[Test]
    public function install_succeeds_and_reports_every_step(): void
    {
        $this->installable();

        $this->artisan('ext:install hello')->assertExitCode(0);

        self::assertSame(ExtensionStatus::Integrated, Extension::where('key', 'hello')->firstOrFail()->status);
    }

    #[Test]
    public function install_never_prints_the_client_secret(): void
    {
        $this->installable();

        $this->artisan('ext:install hello')->assertExitCode(0);

        $stdin = (string) $this->helper->stdinFor(ExtensionInstallService::HELPER_WRITE_ENV);
        preg_match('/^SE5_OIDC_CLIENT_SECRET=(.+)$/m', $stdin, $matches);
        $secret = $matches[1] ?? '';
        self::assertNotSame('', $secret);

        // La sortie complète de la commande, rejouée : le secret n'y est pas.
        $this->artisan('ext:install hello')->doesntExpectOutputToContain($secret)->assertExitCode(0);
    }

    #[Test]
    public function install_on_an_already_installed_extension_is_a_signalled_no_op_with_exit_zero(): void
    {
        $this->installable();
        $this->artisan('ext:install hello')->assertExitCode(0);
        $this->helper->forget();

        $this->artisan('ext:install hello')
            ->expectsOutputToContain('déjà installée')
            ->assertExitCode(0);

        self::assertSame([], $this->helper->calls);
    }

    #[Test]
    public function install_fails_loudly_when_the_signature_does_not_match(): void
    {
        $this->installable(sha: str_repeat('0', 64));

        $this->artisan('ext:install hello')
            ->expectsOutputToContain('sha256 du paquet non concordant')
            ->assertExitCode(1);

        self::assertSame([], $this->helper->calls);
    }

    #[Test]
    public function install_fails_on_an_unknown_key(): void
    {
        $this->artisan('ext:install inconnue')->assertExitCode(1);
    }

    #[Test]
    public function install_asks_for_an_explicit_source_when_the_key_is_ambiguous(): void
    {
        $a = ExtensionSource::factory()->remote(self::BASE, base64_encode(random_bytes(32)))->create(['key' => 'depot-a']);
        $b = ExtensionSource::factory()->remote(self::BASE, base64_encode(random_bytes(32)))->create(['key' => 'depot-b']);
        $this->installable(source: $a);
        $this->installable(source: $b);

        $this->artisan('ext:install hello')
            ->expectsOutputToContain('--source')
            ->assertExitCode(1);

        $this->artisan('ext:install hello --source=depot-b')->assertExitCode(0);

        self::assertSame(ExtensionStatus::Integrated, Extension::where('extension_source_id', $b->id)->firstOrFail()->status);
        self::assertSame(ExtensionStatus::Available, Extension::where('extension_source_id', $a->id)->firstOrFail()->status);
    }

    #[Test]
    public function install_refuses_a_link_and_points_to_the_library(): void
    {
        Extension::factory()->link('/doc')->create(['key' => 'doc']);

        $this->artisan('ext:install doc')
            ->expectsOutputToContain('bibliothèque')
            ->assertExitCode(1);
    }

    // =====================================================================
    // ext:remove
    // =====================================================================

    #[Test]
    public function remove_succeeds_after_an_install(): void
    {
        $this->installable();
        $this->artisan('ext:install hello')->assertExitCode(0);

        $this->artisan('ext:remove hello')->assertExitCode(0);

        self::assertSame(ExtensionStatus::Available, Extension::where('key', 'hello')->firstOrFail()->status);
    }

    #[Test]
    public function remove_on_a_non_installed_app_is_a_signalled_no_op_with_exit_zero(): void
    {
        $this->installable();

        $this->artisan('ext:remove hello')
            ->expectsOutputToContain('n\'est pas installée')
            ->assertExitCode(0);
    }

    #[Test]
    public function remove_refuses_a_link_and_points_to_the_library(): void
    {
        Extension::factory()->link('/doc')->integrated()->create(['key' => 'doc']);

        $this->artisan('ext:remove doc')
            ->expectsOutputToContain('bibliothèque')
            ->assertExitCode(1);
    }

    #[Test]
    public function remove_reports_a_failing_step_and_exits_non_zero(): void
    {
        $this->installable();
        $this->artisan('ext:install hello')->assertExitCode(0);
        $this->helper->failOnSubcommand(ExtensionInstallService::HELPER_REMOVE_PACKAGE);

        $this->artisan('ext:remove hello')
            ->expectsOutputToContain('Désinstallation interrompue')
            ->assertExitCode(1);

        self::assertSame(ExtensionStatus::Integrated, Extension::where('key', 'hello')->firstOrFail()->status);
    }
}
