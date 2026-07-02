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
 * Epic 34 — Tests de l'audit de dérive {@see NetworkShareService::computeDrift()}.
 *
 * Vérifie que la comparaison désiré-vs-effectif est SÉMANTIQUE (raccourci vs
 * sortie getfacl normalisés) et détecte correctement conforme / drifted / absent.
 */
class NetworkShareDriftTest extends TestCase
{
    use RefreshDatabase;

    private NetworkShareService $service;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();

        $this->tempRoot = sys_get_temp_dir() . '/netshare-drift-' . uniqid();
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

    /** Set canonique de base (aucune assignation), en sortie getfacl. */
    private const BASE_GETFACL = <<<TXT
    user::rwx
    group::---
    group:domain\\040admins:rwx
    mask::rwx
    other::---
    default:user::rwx
    default:group::---
    default:group:domain\\040admins:rwx
    default:mask::rwx
    default:other::---
    TXT;

    private function makeProvisionedShare(): NetworkShare
    {
        @mkdir($this->tempRoot . '/proj', 0o755, true);

        return NetworkShare::factory()->create(['directory_name' => 'proj']);
    }

    #[Test]
    public function reports_absent_when_directory_missing(): void
    {
        Process::fake();
        $share = NetworkShare::factory()->create(['directory_name' => 'ghost']);

        self::assertSame('absent', $this->service->computeDrift($share)['status']);
    }

    #[Test]
    public function reports_conforme_when_disk_matches_desired(): void
    {
        $share = $this->makeProvisionedShare();
        Process::fake([
            'sudo getfacl *' => Process::result(output: self::BASE_GETFACL, exitCode: 0),
        ]);

        $drift = $this->service->computeDrift($share);

        self::assertSame('conforme', $drift['status']);
        self::assertSame([], $drift['missing']);
        self::assertSame([], $drift['unexpected']);
    }

    #[Test]
    public function reports_drifted_with_missing_entry(): void
    {
        $share = $this->makeProvisionedShare();

        // Disque amputé de `group:domain\040admins:rwx` (accès + default).
        $amputated = str_replace(
            ["group:domain\\040admins:rwx\n", "default:group:domain\\040admins:rwx\n"],
            '',
            self::BASE_GETFACL,
        );
        Process::fake([
            'sudo getfacl *' => Process::result(output: $amputated, exitCode: 0),
        ]);

        $drift = $this->service->computeDrift($share);

        self::assertSame('drifted', $drift['status']);
        self::assertContains('group:domain\040admins:rwx', $drift['missing']);
    }

    #[Test]
    public function reports_error_when_getfacl_fails(): void
    {
        $share = $this->makeProvisionedShare();
        Process::fake([
            'sudo getfacl *' => Process::result(output: '', errorOutput: 'boom', exitCode: 1),
        ]);

        self::assertSame('error', $this->service->computeDrift($share)['status']);
    }
}
