<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\QuotaAuditLog;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Services\Filesystem\AclService;
use App\Services\Filesystem\ShareService;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 5.2 (D5=D, AC 11) — Tests Feature de la commande `shares:resync-class`.
 *
 * Couvre :
 *  - `--dry-run` (preview tabulaire, aucune modif FS).
 *  - `--class=<name>` ciblage d'une seule classe.
 *  - Sans option : itère toutes les classes type='classe'.
 *  - Validation `--class=` anti-injection.
 *  - Validation `--performed-by=` anti log poisoning.
 *  - Audit consolidé `quota_audit_logs` `action='resync_class'`.
 *
 * Pattern : `Process::fake()` pour les sudo setfacl/mkdir, override
 * `AclService::$classesRoot` + `ShareService::$classesRoot` vers un tempdir
 * réel pour les `is_dir()` checks.
 */
class SharesResyncClassCommandTest extends TestCase
{
    use CreatesPermissionSchema;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPermissionSchema();

        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        Queue::fake();
        Process::fake([
            '*' => Process::result(output: '', exitCode: 0),
        ]);

        $this->tempRoot = sys_get_temp_dir() . '/shares-resync-' . uniqid();
        @mkdir($this->tempRoot, 0o755, true);

        AclService::$classesRoot = $this->tempRoot;
        ShareService::$classesRoot = $this->tempRoot;
    }

    protected function tearDown(): void
    {
        AclService::$classesRoot = '/var/sambaedu/Classes';
        ShareService::$classesRoot = '/var/sambaedu/Classes';
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        if (is_dir($this->tempRoot)) {
            $this->rrmdir($this->tempRoot);
        }
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        $items = @scandir($dir) ?: [];
        foreach ($items as $i) {
            if ($i === '.' || $i === '..') {
                continue;
            }
            $f = $dir . '/' . $i;
            if (is_dir($f) && ! is_link($f)) {
                $this->rrmdir($f);
            } else {
                @unlink($f);
            }
        }
        @rmdir($dir);
    }

    private function makeClasse(string $name): UserGroup
    {
        return UserGroup::create([
            'name' => $name,
            'display_name' => "Classe $name",
            'type' => 'classe',
        ]);
    }

    // =========================================================================
    // AC 11 — itération toutes classes / ciblée / dry-run
    // =========================================================================

    #[Test]
    public function it_resyncs_all_classes_when_no_filter(): void
    {
        $a = $this->makeClasse('6A');
        $b = $this->makeClasse('5B');
        // Un groupe non-classe ne doit pas être pris en compte.
        UserGroup::create(['name' => 'Profs', 'type' => 'role']);

        $this->artisan('shares:resync-class', ['--performed-by' => 'tester'])
            ->expectsOutputToContain('6A')
            ->expectsOutputToContain('5B')
            ->expectsOutputToContain('Classes : 2 traitée(s).')
            ->assertSuccessful();

        // Le ShareService a appelé mkdir + setfacl pour chaque classe.
        Process::assertRan(fn ($p) => str_contains($p->command, 'mkdir')
            && str_contains($p->command, 'Classe_6A'));
        Process::assertRan(fn ($p) => str_contains($p->command, 'mkdir')
            && str_contains($p->command, 'Classe_5B'));
    }

    #[Test]
    public function it_resyncs_one_class_when_filter_provided(): void
    {
        $this->makeClasse('6A');
        $this->makeClasse('5B');

        $this->artisan('shares:resync-class', ['--class' => '6A', '--performed-by' => 'tester'])
            ->expectsOutputToContain('6A')
            ->expectsOutputToContain('Classes : 1 traitée(s).')
            ->assertSuccessful();

        // 5B ne doit pas être touchée.
        Process::assertNotRan(fn ($p) => str_contains($p->command, 'mkdir')
            && str_contains($p->command, 'Classe_5B'));
    }

    #[Test]
    public function it_supports_dry_run_without_modifying_fs(): void
    {
        $this->makeClasse('6A');
        $this->makeClasse('5B');

        $this->artisan('shares:resync-class', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY-RUN]')
            ->expectsOutputToContain('6A')
            ->expectsOutputToContain('5B')
            ->assertSuccessful();

        // Aucun setfacl/mkdir ne doit être appelé en dry-run.
        Process::assertNotRan(fn ($p) => str_contains($p->command, 'setfacl'));
        Process::assertNotRan(fn ($p) => str_contains($p->command, 'mkdir'));
    }

    // =========================================================================
    // Validations input
    // =========================================================================

    #[Test]
    public function it_returns_failure_when_class_filter_not_found(): void
    {
        $this->makeClasse('6A');

        $this->artisan('shares:resync-class', ['--class' => 'INEXISTANTE'])
            ->expectsOutputToContain('Aucune classe trouvée')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_rejects_malicious_class_name(): void
    {
        $this->artisan('shares:resync-class', ['--class' => 'Classe;rm -rf /'])
            ->expectsOutputToContain('--class invalide')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_rejects_malicious_performed_by(): void
    {
        $this->artisan('shares:resync-class', ['--performed-by' => 'evil$(whoami)'])
            ->expectsOutputToContain('--performed-by invalide')
            ->assertExitCode(1);
    }

    #[Test]
    public function it_returns_success_when_no_classe_groups_exist(): void
    {
        // Un groupe non-classe seulement.
        UserGroup::create(['name' => 'Profs', 'type' => 'role']);

        $this->artisan('shares:resync-class')
            ->expectsOutputToContain('Aucune classe')
            ->assertSuccessful();
    }

    // =========================================================================
    // Audit
    // =========================================================================

    #[Test]
    public function it_returns_exit_code_2_when_all_classes_locked(): void
    {
        $this->makeClasse('6A');
        $this->makeClasse('5B');

        // Mock ShareService : retourne false (échec/lock indéterminé).
        // Pré-acquière les locks via Cache::lock pour que le probe interne
        // de la commande détecte "verrouillé" et NON "failed".
        $g6A = UserGroup::where('name', '6A')->first();
        $g5B = UserGroup::where('name', '5B')->first();

        $lock6A = \Illuminate\Support\Facades\Cache::lock('shares:resync:' . $g6A->id, 60);
        $lock5B = \Illuminate\Support\Facades\Cache::lock('shares:resync:' . $g5B->id, 60);
        $this->assertTrue($lock6A->get());
        $this->assertTrue($lock5B->get());

        $mock = \Mockery::mock(ShareService::class);
        $mock->shouldReceive('createClassShare')->andReturn(false);
        $mock->shouldReceive('resolveClassPath')->andReturn(null);
        $this->app->instance(ShareService::class, $mock);

        $this->artisan('shares:resync-class', ['--performed-by' => 'tester'])
            ->expectsOutputToContain('Verrouillées : 2')
            ->assertExitCode(2);

        $lock6A->release();
        $lock5B->release();
    }

    #[Test]
    public function it_writes_consolidated_audit_log_with_action_resync_class(): void
    {
        $this->makeClasse('6A');

        $this->artisan('shares:resync-class', ['--performed-by' => 'tester'])
            ->assertSuccessful();

        $log = QuotaAuditLog::query()->where('action', 'resync_class')->first();
        $this->assertNotNull($log);
        $this->assertSame('share', $log->target_type);
        $this->assertSame('tester', $log->performed_by);
        $newValues = $log->new_values;
        $this->assertSame(1, $newValues['classes_total']);
    }
}
