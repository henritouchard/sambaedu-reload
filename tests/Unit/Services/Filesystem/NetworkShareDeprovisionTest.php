<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem;

use App\Models\NetworkShare;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Filesystem\NetworkShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Epic 34 (gap deprovision) — Tests de {@see NetworkShareService::deprovision()}.
 *
 * Vérifie la révocation d'accès (wipe ACL + chmod anti-other) et l'archivage
 * hors de l'espace exposé, sans destruction de données.
 */
class NetworkShareDeprovisionTest extends TestCase
{
    use RefreshDatabase;

    private NetworkShareService $service;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();

        $this->tempRoot = sys_get_temp_dir() . '/netshare-deprov-' . uniqid();
        @mkdir($this->tempRoot, 0o755, true);
        config(['filesystem.shares_root' => $this->tempRoot]);

        $this->service = app(NetworkShareService::class);
    }

    protected function tearDown(): void
    {
        @rmdir($this->tempRoot . '/proj');
        @rmdir($this->tempRoot);
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    #[Test]
    public function no_op_success_when_directory_already_absent(): void
    {
        Process::fake();
        $share = NetworkShare::factory()->create(['directory_name' => 'ghost']);

        self::assertTrue($this->service->deprovision($share));
        // Aucun setfacl/chmod/mv lancé (rien à révoquer).
        Process::assertNothingRan();
    }

    #[Test]
    public function revokes_acls_removes_other_access_and_archives_out_of_band(): void
    {
        @mkdir($this->tempRoot . '/proj', 0o755, true);
        $share = NetworkShare::factory()->create(['directory_name' => 'proj']);

        Process::fake();

        self::assertTrue($this->service->deprovision($share));

        // 1. Wipe des ACL étendues.
        Process::assertRan(fn ($p) => str_contains($p->command, 'setfacl -R -P -b')
            && str_contains($p->command, '/proj'));
        // 2. Retrait de l'accès other.
        Process::assertRan(fn ($p) => str_contains($p->command, 'chmod -R 0770')
            && str_contains($p->command, '/proj'));
        // 3. Archivage vers .trash/<name>-<id> (hors espace exposé).
        Process::assertRan(fn ($p) => str_contains($p->command, 'mv ')
            && str_contains($p->command, '.trash/proj-' . $share->id));
    }

    #[Test]
    public function reports_failure_when_a_step_fails(): void
    {
        @mkdir($this->tempRoot . '/proj', 0o755, true);
        $share = NetworkShare::factory()->create(['directory_name' => 'proj']);

        // Le wipe ACL échoue → deprovision renvoie false (fail-soft).
        Process::fake([
            'sudo setfacl *' => Process::result(output: '', errorOutput: 'denied', exitCode: 1),
            '*' => Process::result(output: '', exitCode: 0),
        ]);

        self::assertFalse($this->service->deprovision($share));
    }
}
