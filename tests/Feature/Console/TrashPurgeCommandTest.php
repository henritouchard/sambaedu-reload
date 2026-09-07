<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\TrashPurgeCommand;
use App\Models\QuotaAuditLog;
use App\Models\SystemSetting;
use App\Services\Filesystem\HomeDirService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Feature de la commande `trash:purge`.
 *
 * Stratégie tests : on remplace `static::$trashDir` par un répertoire temporaire
 * sous `sys_get_temp_dir()` pour ne pas toucher `/home/trash`. La méthode
 * `HomeDirService::deleteHomeDirectoryPermanently` est mockée via instance
 * binding pour simuler succès/échecs sans `sudo rm`.
 */
class TrashPurgeCommandTest extends TestCase
{
    use DatabaseTransactions;

    private string $tmpTrashDir = '';

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Répertoire temporaire isolé par test (uniqid + microtime).
        $this->tmpTrashDir = sys_get_temp_dir() . '/trash-purge-test-' . uniqid('', true);
        @mkdir($this->tmpTrashDir, 0755, true);
        TrashPurgeCommand::$trashDir = $this->tmpTrashDir;

        $this->ensureTables();
    }

    protected function tearDown(): void
    {
        // Reset path pour ne pas polluer les autres tests.
        TrashPurgeCommand::$trashDir = '/home/trash';
        $this->cleanupTmpTrash();

        if ($this->createdTables) {
            Schema::dropIfExists('system_settings');
            Schema::dropIfExists('quota_audit_logs');
        }
        parent::tearDown();
    }

    private function cleanupTmpTrash(): void
    {
        if (!is_dir($this->tmpTrashDir)) {
            return;
        }
        $entries = @scandir($this->tmpTrashDir) ?: [];
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $this->tmpTrashDir . '/' . $name;
            if (is_dir($path)) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($this->tmpTrashDir);
    }

    private function ensureTables(): void
    {
        if (!Schema::hasTable('system_settings')) {
            Schema::create('system_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 191)->unique();
                $table->json('value')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }
        if (!Schema::hasTable('quota_audit_logs')) {
            Schema::create('quota_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->string('action');
                $table->string('performed_by');
                $table->string('target_type')->nullable();
                $table->string('target_name')->nullable();
                $table->string('partition')->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->boolean('fs_applied')->default(false);
                $table->text('fs_error')->nullable();
                $table->unsignedBigInteger('quota_rule_id')->nullable();
                $table->timestamp('created_at')->nullable();
            });
            $this->createdTables = true;
        }
    }

    /**
     * Crée un sous-dossier `<tmpTrashDir>/<login>` et force son `mtime` à
     * `now() - $ageDays * 86400`.
     */
    private function makeTrashDir(string $login, int $ageDays): string
    {
        $path = $this->tmpTrashDir . '/' . $login;
        @mkdir($path, 0755, true);
        $ts = time() - ($ageDays * 86400);
        @touch($path, $ts);
        return $path;
    }

    private function bindHomeDirServiceMock(\Closure $deleteHandler): void
    {
        $stub = new class($deleteHandler) extends HomeDirService {
            /** @var \Closure(string): bool */
            public \Closure $handler;

            public function __construct(\Closure $handler)
            {
                $this->handler = $handler;
            }

            public function deleteHomeDirectoryPermanently(string $login): bool
            {
                return ($this->handler)($login);
            }
        };
        $this->app->instance(HomeDirService::class, $stub);
    }

    #[Test]
    public function it_purges_directories_older_than_ttl(): void
    {
        SystemSetting::set('quota.trash', ['ttl_days' => 30, 'purge_auto' => false]);

        $this->makeTrashDir('alice', 45); // > 30j → à purger
        $this->makeTrashDir('bob', 5);    // < 30j → conservé

        $deleted = [];
        $this->bindHomeDirServiceMock(function (string $login) use (&$deleted): bool {
            $deleted[] = $login;
            return true;
        });

        $exit = $this->artisan('trash:purge')->run();
        $this->assertSame(0, $exit);
        $this->assertSame(['alice'], $deleted);
    }

    #[Test]
    public function it_keeps_all_directories_when_ttl_is_high_enough(): void
    {
        SystemSetting::set('quota.trash', ['ttl_days' => 365, 'purge_auto' => false]);

        $this->makeTrashDir('alice', 30);
        $this->makeTrashDir('bob', 10);

        $deleted = [];
        $this->bindHomeDirServiceMock(function (string $login) use (&$deleted): bool {
            $deleted[] = $login;
            return true;
        });

        $exit = $this->artisan('trash:purge')->run();
        $this->assertSame(0, $exit);

        $this->assertSame([], $deleted);
    }

    #[Test]
    public function it_fails_with_explicit_error_when_ttl_is_zero_or_missing(): void
    {
        // Cas 1 : clé absente → exit FAILURE + message explicite.
        $this->makeTrashDir('alice', 100);

        $this->bindHomeDirServiceMock(fn ($login) => true);

        $this->artisan('trash:purge')
            ->expectsOutputToContain('TTL non configuré')
            ->assertExitCode(1);

        // Cas 2 : ttl_days = 0 → idem.
        SystemSetting::set('quota.trash', ['ttl_days' => 0, 'purge_auto' => true]);

        $this->artisan('trash:purge')
            ->expectsOutputToContain('TTL non configuré')
            ->assertExitCode(1);

        // Le dossier existe toujours (rien n'a été purgé).
        $this->assertDirectoryExists($this->tmpTrashDir . '/alice');
    }

    #[Test]
    public function it_continues_on_individual_failure_and_logs_audit_for_success(): void
    {
        SystemSetting::set('quota.trash', ['ttl_days' => 30, 'purge_auto' => false]);

        $this->makeTrashDir('charlie', 60); // échouera
        $this->makeTrashDir('dave', 60);    // réussira

        $this->bindHomeDirServiceMock(function (string $login): bool {
            return $login !== 'charlie';
        });

        $exit = $this->artisan('trash:purge')->run();
        $this->assertSame(0, $exit, 'SUCCESS car au moins 1 réussite (fail-soft 5.1b D3)');

        // Audit : 1 row créée pour dave (charlie non audité car échec).
        $this->assertSame(1, QuotaAuditLog::query()->count());
        $audit = QuotaAuditLog::query()->first();
        $this->assertSame('trash', $audit->target_type);
        $this->assertSame('dave', $audit->target_name);
        $this->assertSame('trash:purge', $audit->performed_by);
    }

    #[Test]
    public function it_returns_failure_when_all_deletes_fail(): void
    {
        SystemSetting::set('quota.trash', ['ttl_days' => 30, 'purge_auto' => false]);

        $this->makeTrashDir('alice', 60);
        $this->makeTrashDir('bob', 60);

        $this->bindHomeDirServiceMock(fn (string $login) => false);

        $exit = $this->artisan('trash:purge')->run();
        $this->assertSame(1, $exit, 'FAILURE car TOUTES les suppressions ont échoué');
    }

    #[Test]
    public function it_supports_dry_run_without_modifying_db(): void
    {
        SystemSetting::set('quota.trash', ['ttl_days' => 30, 'purge_auto' => false]);

        $this->makeTrashDir('alice', 60);

        $deleted = [];
        $this->bindHomeDirServiceMock(function (string $login) use (&$deleted): bool {
            $deleted[] = $login;
            return true;
        });

        $this->artisan('trash:purge --dry-run')
            ->expectsOutputToContain('[DRY-RUN]')
            ->assertExitCode(0);

        $this->assertSame([], $deleted, 'Aucun delete ne doit avoir été appelé en dry-run.');
        $this->assertSame(0, QuotaAuditLog::query()->count(), 'Aucun audit en dry-run.');
        $this->assertDirectoryExists($this->tmpTrashDir . '/alice');
    }

    #[Test]
    public function it_force_flag_ignores_ttl_guard_and_purges_all_when_unconfigured(): void
    {
        // Pas de SystemSetting → TTL = 0. Sans --force → FAILURE explicite.
        // Avec --force → bypass garde-fou + purge tout dossier > 0j (intentionnel).
        $this->makeTrashDir('alice', 100); // > 0j → sera purgé en mode --force

        $deleted = [];
        $this->bindHomeDirServiceMock(function (string $login) use (&$deleted): bool {
            $deleted[] = $login;
            return true;
        });

        // Sans --force : TTL=0 → FAILURE + message explicite.
        $this->artisan('trash:purge')
            ->expectsOutputToContain('TTL non configuré')
            ->assertExitCode(1);
        $this->assertSame([], $deleted, 'Pas de purge sans --force quand TTL invalide.');

        // Avec --force : bypass garde-fou + purge tout dossier > 0j (intentionnel).
        $this->artisan('trash:purge --force')
            ->expectsOutputToContain('Mode --force')
            ->assertExitCode(0);

        $this->assertSame(['alice'], $deleted, 'alice doit avoir été purgée en mode --force.');
    }

    #[Test]
    public function it_returns_success_when_trash_dir_missing(): void
    {
        SystemSetting::set('quota.trash', ['ttl_days' => 30, 'purge_auto' => false]);

        // On supprime le tmpDir AVANT l'appel : la commande doit gérer
        // l'absence sans erreur (no-op safe défensif).
        @rmdir($this->tmpTrashDir);
        $this->assertFalse(is_dir($this->tmpTrashDir), 'precondition: trashDir absent');

        $deleted = [];
        $this->bindHomeDirServiceMock(function (string $login) use (&$deleted): bool {
            $deleted[] = $login;
            return true;
        });

        $this->artisan('trash:purge')
            ->expectsOutputToContain('inexistant')
            ->assertExitCode(0);

        $this->assertSame([], $deleted, 'Aucun delete ne doit avoir été appelé.');
        $this->assertSame(0, QuotaAuditLog::query()->count(), 'Aucun audit ne doit avoir été créé.');
    }

    #[Test]
    public function it_keeps_directory_at_exactly_ttl_boundary(): void
    {
        SystemSetting::set('quota.trash', ['ttl_days' => 30, 'purge_auto' => false]);

        $this->makeTrashDir('alice', 30); // ageDays=30 → conservé (30 n'est pas > 30)
        $this->makeTrashDir('bob', 31);   // ageDays=31 → purgé (31 > 30)

        $deleted = [];
        $this->bindHomeDirServiceMock(function (string $login) use (&$deleted): bool {
            $deleted[] = $login;
            return true;
        });

        $exit = $this->artisan('trash:purge')->run();
        $this->assertSame(0, $exit);

        // Seul bob doit être purgé. Alice (frontière exacte) doit être conservée.
        $this->assertSame(['bob'], $deleted, 'Frontière TTL exclusive : ageDays=TTL est conservé.');
    }

    #[Test]
    public function it_skips_locked_directory_during_purge(): void
    {
        SystemSetting::set('quota.trash', ['ttl_days' => 30, 'purge_auto' => false]);

        $this->makeTrashDir('alice', 60); // > 30j, mais sera verrouillée
        $this->makeTrashDir('bob', 60);   // > 30j, sera purgée normalement

        // Verrouillage préalable d'alice : on simule une opération concurrente
        // (restoreHomeDirectory en cours) en posant le lock avant la purge.
        $externalLock = \Illuminate\Support\Facades\Cache::lock('trash:action:alice', 60);
        $this->assertTrue($externalLock->get(), 'Le lock doit être posable au préalable.');

        $deleted = [];
        $this->bindHomeDirServiceMock(function (string $login) use (&$deleted): bool {
            $deleted[] = $login;
            return true;
        });

        try {
            $this->artisan('trash:purge')
                ->expectsOutputToContain('[SKIP] alice')
                ->expectsOutputToContain('Verrouillés : 1')
                ->assertExitCode(0);
        } finally {
            $externalLock->release();
        }

        // alice a été skipped (verrou) ; bob a été purgée normalement.
        $this->assertSame(['bob'], $deleted, 'alice doit être skippée, bob purgée.');
        // Aucun audit pour alice (pas de suppression effective).
        $this->assertSame(1, QuotaAuditLog::query()->count(), '1 audit attendu (bob seulement).');
    }

    #[Test]
    public function it_skips_directories_with_invalid_login_name(): void
    {
        SystemSetting::set('quota.trash', ['ttl_days' => 30, 'purge_auto' => false]);

        // Dossier "valid-login" légitime.
        $this->makeTrashDir('alice', 60);
        // Dossier au nom invalide (caractères interdits) → doit être skip avant même
        // le call deleteHomeDirectoryPermanently. La regex anti-injection est inline
        // dans la commande. On simule en créant un dossier avec un point d'exclamation.
        @mkdir($this->tmpTrashDir . '/bad name!', 0755);
        @touch($this->tmpTrashDir . '/bad name!', time() - (60 * 86400));

        $deleted = [];
        $this->bindHomeDirServiceMock(function (string $login) use (&$deleted): bool {
            $deleted[] = $login;
            return true;
        });

        $exit = $this->artisan('trash:purge')->run();
        $this->assertSame(0, $exit);

        // Seule alice doit être traitée — bad name! est skipped.
        $this->assertSame(['alice'], $deleted);

        // Cleanup manuel du dossier "bad name!" (pas dans cleanupTmpTrash car le
        // mtime ne suit pas le nom).
        @rmdir($this->tmpTrashDir . '/bad name!');
    }
}
