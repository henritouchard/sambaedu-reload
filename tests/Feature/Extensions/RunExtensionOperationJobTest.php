<?php

declare(strict_types=1);

namespace Tests\Feature\Extensions;

use App\Enums\ExtensionStatus;
use App\Enums\ExtensionType;
use App\Jobs\RunExtensionOperationJob;
use App\Models\Extension;
use App\Models\ExtensionAuditLog;
use App\Models\ExtensionInstallRun;
use App\Models\ExtensionSource;
use App\Models\User;
use App\Services\Extensions\Contracts\ExtensionHelperRunner;
use App\Services\Extensions\ExtensionInstallService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeExtensionHelperRunner;
use Tests\TestCase;

/**
 * Story 56.3 (AC2, AC5) — Le Job de fond : il exécute le MÊME moteur que la CLI
 * et rapporte l'avancement dans `extension_install_runs`.
 *
 * `handle()` est appelé DIRECTEMENT (jamais via la file) : `phpunit.xml` force
 * `QUEUE_CONNECTION=sync`, un dispatch s'exécuterait inline et on ne saurait
 * plus ce qu'on observe. Ce qu'on prouve ici, c'est la couture — transitions du
 * run, progression, acteur d'audit, et les TROIS chemins d'erreur du moteur.
 */
class RunExtensionOperationJobTest extends TestCase
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

        $this->staging = sys_get_temp_dir().'/se5-ext-job-'.bin2hex(random_bytes(6));
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

    private int $adminSeq = 0;

    private function admin(?string $login = null): User
    {
        $this->adminSeq++;

        return User::query()->create([
            'login' => $login ?? ($this->adminSeq === 1 ? 'job-admin' : 'job-admin-'.$this->adminSeq),
            'role' => 'autre',
            'is_active' => true,
        ]);
    }

    private function installable(?string $sha256 = null): Extension
    {
        $source = ExtensionSource::factory()->remote(self::BASE, base64_encode(random_bytes(32)))->create();

        $extension = Extension::factory()->for($source, 'source')->create([
            'key' => 'hello',
            'name' => 'Hello',
            'version' => '1.0.0',
            'type' => ExtensionType::App,
            'manifest' => [
                'manifest_version' => 1,
                'id' => 'hello',
                'type' => 'app',
                'name' => 'Hello',
                'version' => '1.0.0',
                'entry_url' => '/ext/hello',
                'icon' => 'fa-solid fa-hand',
                'publisher' => 'QA',
                'description' => 'Extension de test.',
                'scopes' => [],
                'dependencies' => [],
                'visibility' => ['roles' => ['admin']],
                'install' => [
                    'channel' => 'deb',
                    'package' => self::PACKAGE_PATH,
                    'sha256' => $sha256 ?? hash('sha256', self::PACKAGE_BODY),
                    'redirect_paths' => [],
                ],
            ],
        ]);

        $this->files[self::BASE.'/'.self::PACKAGE_PATH] = [
            'body' => self::PACKAGE_BODY, 'status' => 200, 'headers' => [],
        ];

        return $extension;
    }

    private function makeRun(Extension $extension, string $operation, ?User $actor = null): ExtensionInstallRun
    {
        return ExtensionInstallRun::query()->create([
            'extension_id' => $extension->id,
            'operation' => $operation,
            'status' => ExtensionInstallRun::STATUS_PENDING,
            'current_step' => '',
            'steps' => [],
            'error' => '',
            'requested_by_user_id' => $actor?->id,
            'requested_by_login' => (string) ($actor?->login ?? ''),
        ]);
    }

    private function execute(ExtensionInstallRun $run): void
    {
        $this->app->make(RunExtensionOperationJob::class, ['runId' => (int) $run->id])
            ->handle($this->app->make(ExtensionInstallService::class));
    }

    // =====================================================================
    // AC2 — chemin nominal
    // =====================================================================

    #[Test]
    public function a_successful_install_walks_the_run_from_pending_to_success(): void
    {
        $extension = $this->installable();
        $admin = $this->admin();
        $run = $this->makeRun($extension, ExtensionInstallRun::OPERATION_INSTALL, $admin);

        $this->execute($run);

        $run->refresh();
        self::assertSame(ExtensionInstallRun::STATUS_SUCCESS, $run->status);
        self::assertSame('', $run->error);
        self::assertNotNull($run->started_at);
        self::assertNotNull($run->finished_at);

        self::assertSame(ExtensionStatus::Integrated, $extension->fresh()->status);
    }

    #[Test]
    public function the_run_records_every_step_in_the_documented_order(): void
    {
        $extension = $this->installable();
        $run = $this->makeRun($extension, ExtensionInstallRun::OPERATION_INSTALL, $this->admin());

        $this->execute($run);

        $run->refresh();
        self::assertSame([
            ExtensionInstallService::STEP_PACKAGE,
            ExtensionInstallService::STEP_OIDC,
            ExtensionInstallService::STEP_ENV,
            ExtensionInstallService::STEP_APT,
            ExtensionInstallService::STEP_SERVICE,
            ExtensionInstallService::STEP_APACHE,
            ExtensionInstallService::STEP_REGISTRY,
        ], $run->steps);
        self::assertSame(ExtensionInstallService::STEP_REGISTRY, $run->current_step);
    }

    #[Test]
    public function the_audit_actor_is_the_admin_who_clicked_never_system(): void
    {
        // Un acte d'UI a un auteur. L'auditer sous `system` reviendrait à
        // perdre la seule information que le journal d'audit existe pour
        // conserver.
        $extension = $this->installable();
        $admin = $this->admin();
        $run = $this->makeRun($extension, ExtensionInstallRun::OPERATION_INSTALL, $admin);

        $this->execute($run);

        $log = ExtensionAuditLog::where('action', ExtensionAuditLog::ACTION_INSTALL)->firstOrFail();
        self::assertSame($admin->id, $log->actor_user_id);
        self::assertSame('job-admin', $log->actor_login);
    }

    #[Test]
    public function an_admin_deleted_between_the_click_and_the_pickup_does_not_break_the_job(): void
    {
        // L'acteur est RECHARGÉ par identifiant. Sérialiser le modèle dans le
        // payload ferait exploser le unserialize au pickup.
        $extension = $this->installable();
        $admin = $this->admin();
        $run = $this->makeRun($extension, ExtensionInstallRun::OPERATION_INSTALL, $admin);

        $admin->delete();

        $this->execute($run);

        $run->refresh();
        self::assertSame(ExtensionInstallRun::STATUS_SUCCESS, $run->status);
        $log = ExtensionAuditLog::where('action', ExtensionAuditLog::ACTION_INSTALL)->firstOrFail();
        self::assertSame(ExtensionAuditLog::ACTOR_SYSTEM, $log->actor_login);
    }

    #[Test]
    public function a_remove_run_goes_through_the_same_channel(): void
    {
        $extension = $this->installable();
        $install = $this->makeRun($extension, ExtensionInstallRun::OPERATION_INSTALL, $this->admin());
        $this->execute($install);

        $remove = $this->makeRun($extension->fresh(), ExtensionInstallRun::OPERATION_REMOVE, $this->admin());
        $this->execute($remove);

        $remove->refresh();
        self::assertSame(ExtensionInstallRun::STATUS_SUCCESS, $remove->status);
        self::assertSame(ExtensionStatus::Available, $extension->fresh()->status);
        self::assertSame('Désinstallation', $remove->operationLabel());
    }

    #[Test]
    public function an_update_run_goes_through_the_same_channel(): void
    {
        $extension = $this->installable();
        $this->execute($this->makeRun($extension, ExtensionInstallRun::OPERATION_INSTALL, $this->admin()));

        // Le dépôt publie une 2.0.0.
        $body = 'paquet-version-2';
        $path = 'packages/sambaedu-ext-hello_2.0.0_all.deb';
        $extension->refresh();
        $manifest = $extension->manifest;
        $manifest['version'] = '2.0.0';
        $manifest['install']['package'] = $path;
        $manifest['install']['sha256'] = hash('sha256', $body);
        $extension->fill(['version' => '2.0.0', 'manifest' => $manifest])->save();
        $this->files[self::BASE.'/'.$path] = ['body' => $body, 'status' => 200, 'headers' => []];

        $update = $this->makeRun($extension->fresh(), ExtensionInstallRun::OPERATION_UPDATE, $this->admin());
        $this->execute($update);

        $update->refresh();
        self::assertSame(ExtensionInstallRun::STATUS_SUCCESS, $update->status);
        self::assertSame('2.0.0', $extension->fresh()->installed_version);
        self::assertSame([
            ExtensionInstallService::STEP_PACKAGE,
            ExtensionInstallService::STEP_APT,
            ExtensionInstallService::STEP_SERVICE,
            ExtensionInstallService::STEP_REGISTRY,
        ], $update->steps);
    }

    #[Test]
    public function a_clean_no_op_is_a_success_not_a_failure(): void
    {
        // « Déjà installée » veut dire que l'état demandé est celui qui est en
        // place : l'admin n'a rien à corriger.
        $extension = $this->installable();
        $this->execute($this->makeRun($extension, ExtensionInstallRun::OPERATION_INSTALL, $this->admin()));

        $second = $this->makeRun($extension->fresh(), ExtensionInstallRun::OPERATION_INSTALL, $this->admin());
        $this->execute($second);

        $second->refresh();
        self::assertSame(ExtensionInstallRun::STATUS_SUCCESS, $second->status);
        self::assertSame('', $second->error);

        // Review 56.3 #3 — succès, mais succès SANS acte : l'écran doit
        // pouvoir le dire (AC5 exige un toast info, pas « terminée »).
        self::assertFalse($second->changed, 'un no-op ne doit pas se raconter comme un acte accompli');
    }

    #[Test]
    public function a_real_operation_is_marked_as_having_changed_something(): void
    {
        // Contre-épreuve du test ci-dessus : sans ce cas, `changed` pourrait
        // être faux partout et le test précédent passerait quand même.
        $extension = $this->installable();
        $run = $this->makeRun($extension, ExtensionInstallRun::OPERATION_INSTALL, $this->admin());

        $this->execute($run);

        $run->refresh();
        self::assertSame(ExtensionInstallRun::STATUS_SUCCESS, $run->status);
        self::assertTrue($run->changed);
    }

    // =====================================================================
    // AC2/AC5 — les trois chemins d'erreur, aucun oublié
    // =====================================================================

    #[Test]
    public function an_engine_refusal_returned_as_an_error_ends_the_run_as_failed(): void
    {
        // Chemin n° 1 : le moteur RETOURNE un `error` (extension résolue).
        $extension = $this->installable(sha256: str_repeat('f', 64));
        $run = $this->makeRun($extension, ExtensionInstallRun::OPERATION_INSTALL, $this->admin());

        $this->execute($run);

        $run->refresh();
        self::assertSame(ExtensionInstallRun::STATUS_FAILED, $run->status);
        self::assertSame('sha256 du paquet non concordant', $run->error);
        self::assertNotNull($run->finished_at);
        self::assertSame(ExtensionStatus::Available, $extension->fresh()->status);
    }

    #[Test]
    public function an_engine_contract_refusal_ends_the_run_without_leaking_the_exception(): void
    {
        // Chemin n° 2 : le moteur LÈVE (`ExtensionInstallException`). Le
        // message long va au journal, la catégorie courte en base.
        $extension = Extension::factory()->link()->create(['key' => 'doc']);
        $run = $this->makeRun($extension, ExtensionInstallRun::OPERATION_INSTALL, $this->admin());

        $this->execute($run);

        $run->refresh();
        self::assertSame(ExtensionInstallRun::STATUS_FAILED, $run->status);
        self::assertSame(ExtensionInstallRun::ERROR_LINK_NOT_SUPPORTED, $run->error);
        self::assertStringContainsString('lien', $run->errorLabel());
    }

    #[Test]
    public function a_busy_engine_ends_the_run_as_engine_busy(): void
    {
        $extension = $this->installable();
        $run = $this->makeRun($extension, ExtensionInstallRun::OPERATION_INSTALL, $this->admin());

        $lock = Cache::store('file')->lock('extensions:install-engine', 60);
        self::assertTrue($lock->get());

        try {
            $this->execute($run);
        } finally {
            $lock->release();
        }

        $run->refresh();
        self::assertSame(ExtensionInstallRun::STATUS_FAILED, $run->status);
        self::assertSame(ExtensionInstallRun::ERROR_ENGINE_BUSY, $run->error);
        self::assertSame([], $this->helper->calls, 'pas de demi-installation');
    }

    #[Test]
    public function an_unexpected_throwable_never_reaches_the_database_as_a_raw_message(): void
    {
        // Chemin n° 3 : tout le reste. Un message brut peut porter l'URL du
        // dépôt — donc, potentiellement, un jeton.
        $extension = $this->installable();
        $run = $this->makeRun($extension, ExtensionInstallRun::OPERATION_INSTALL, $this->admin());

        $installer = new class extends ExtensionInstallService
        {
            public function __construct() {}

            public function install(string $key, ?string $sourceKey = null, ?\App\Models\User $actor = null, ?callable $onStep = null): array
            {
                throw new \RuntimeException('cURL error 7 for https://depot.example.test/?private_token=SECRET');
            }
        };

        $this->app->make(RunExtensionOperationJob::class, ['runId' => (int) $run->id])->handle($installer);

        $run->refresh();
        self::assertSame(ExtensionInstallRun::STATUS_FAILED, $run->status);
        self::assertSame(ExtensionInstallRun::ERROR_UNEXPECTED, $run->error);
        self::assertStringNotContainsString('private_token', $run->error);
        self::assertStringNotContainsString('depot.example.test', $run->error);
    }

    #[Test]
    public function deleting_an_extension_takes_its_runs_with_it(): void
    {
        // La FK est `cascadeOnDelete` : un prune de catalogue emporte les runs
        // de l'extension prunée. C'est ce qui rend la branche défensive
        // `extension_gone` du Job pratiquement inatteignable sur un backend qui
        // applique ses contraintes — elle reste là pour ceux qui ne le font pas
        // (restauration partielle, `session_replication_role`, ménage manuel).
        $extension = $this->installable();
        $run = $this->makeRun($extension, ExtensionInstallRun::OPERATION_INSTALL, $this->admin());

        Extension::query()->where('id', $extension->id)->delete();

        self::assertNull(ExtensionInstallRun::query()->find($run->id));
    }

    #[Test]
    public function a_run_carrying_an_unknown_operation_never_stays_running(): void
    {
        // Ligne bricolée à la main : l'orchestrateur ne peut pas la produire,
        // mais elle ne doit pas rester « en cours » indéfiniment à l'écran.
        $extension = $this->installable();
        $run = ExtensionInstallRun::query()->create([
            'extension_id' => $extension->id,
            'operation' => 'reboot',
            'status' => ExtensionInstallRun::STATUS_PENDING,
            'current_step' => '',
            'steps' => [],
            'error' => '',
            'requested_by_user_id' => null,
            'requested_by_login' => '',
        ]);

        $this->execute($run);

        $run->refresh();
        self::assertSame(ExtensionInstallRun::STATUS_FAILED, $run->status);
        self::assertSame(ExtensionInstallRun::ERROR_UNEXPECTED, $run->error);
        self::assertSame([], $this->helper->calls);
    }

    #[Test]
    public function a_missing_run_is_a_silent_no_op(): void
    {
        $this->app->make(RunExtensionOperationJob::class, ['runId' => 999_999])
            ->handle($this->app->make(ExtensionInstallService::class));

        self::assertSame([], $this->helper->calls);
    }

    #[Test]
    public function a_run_already_taken_over_is_never_replayed(): void
    {
        $extension = $this->installable();
        $run = $this->makeRun($extension, ExtensionInstallRun::OPERATION_INSTALL, $this->admin());
        $run->status = ExtensionInstallRun::STATUS_RUNNING;
        $run->save();

        $this->execute($run);

        self::assertSame([], $this->helper->calls, 'un double pickup ne doit pas relancer apt');
        self::assertSame(ExtensionInstallRun::STATUS_RUNNING, $run->fresh()->status);
    }

    #[Test]
    public function the_queue_failure_handler_closes_a_run_left_running(): void
    {
        // Le worker a été tué, ou le Job a dépassé son timeout : `handle()`
        // n'est jamais revenu, mais la file appelle `failed()`.
        $extension = $this->installable();
        $run = $this->makeRun($extension, ExtensionInstallRun::OPERATION_INSTALL, $this->admin());
        $run->status = ExtensionInstallRun::STATUS_RUNNING;
        $run->save();

        (new RunExtensionOperationJob((int) $run->id))->failed(new \RuntimeException('worker tué'));

        $run->refresh();
        self::assertSame(ExtensionInstallRun::STATUS_FAILED, $run->status);
        self::assertSame(ExtensionInstallRun::ERROR_INTERRUPTED, $run->error);
        self::assertNotNull($run->finished_at);
    }

    #[Test]
    public function the_queue_failure_handler_never_rewrites_a_terminated_run(): void
    {
        $extension = $this->installable();
        $run = $this->makeRun($extension, ExtensionInstallRun::OPERATION_INSTALL, $this->admin());
        $this->execute($run);

        (new RunExtensionOperationJob((int) $run->id))->failed(new \RuntimeException('trop tard'));

        self::assertSame(ExtensionInstallRun::STATUS_SUCCESS, $run->fresh()->status);
    }
}
