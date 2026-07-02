<?php

declare(strict_types=1);

namespace Tests\Unit\Doctor\Checks;

use App\Doctor\Checks\Filesystem\NetworkShareAclDriftCheck;
use App\Doctor\Level;
use App\Models\NetworkShare;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Epic 34 — Test du Doctor check {@see NetworkShareAclDriftCheck}.
 */
class NetworkShareAclDriftCheckTest extends TestCase
{
    use RefreshDatabase;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        $this->tempRoot = sys_get_temp_dir() . '/netshare-doctor-' . uniqid();
        @mkdir($this->tempRoot, 0o755, true);
        config(['filesystem.shares_root' => $this->tempRoot]);
    }

    protected function tearDown(): void
    {
        @rmdir($this->tempRoot);
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    #[Test]
    public function ok_when_no_shares(): void
    {
        Process::fake();
        $result = app(NetworkShareAclDriftCheck::class)->run();
        self::assertSame(Level::Ok, $result->level);
    }

    #[Test]
    public function warns_when_a_share_is_not_provisioned(): void
    {
        Process::fake();
        NetworkShare::factory()->create(['directory_name' => 'ghost']); // dossier absent → drift 'absent'

        $result = app(NetworkShareAclDriftCheck::class)->run();

        self::assertSame(Level::Warn, $result->level);
        self::assertStringContainsString('ghost', $result->detail);
    }
}
