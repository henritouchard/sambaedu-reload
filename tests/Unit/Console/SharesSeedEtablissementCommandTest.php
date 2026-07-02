<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Models\NetworkShare;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Epic 34 (gap Docs/Progs) — Tests de `shares:seed-etablissement`.
 */
class SharesSeedEtablissementCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        $this->tempRoot = sys_get_temp_dir() . '/netshare-seed-' . uniqid();
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
    public function dry_run_creates_nothing(): void
    {
        Process::fake();
        $this->artisan('shares:seed-etablissement')->assertExitCode(0);
        self::assertSame(0, NetworkShare::count());
    }

    #[Test]
    public function apply_creates_flat_etablissement_shares_without_audience(): void
    {
        Process::fake();
        $this->artisan('shares:seed-etablissement', ['--apply' => true])->assertExitCode(0);

        self::assertNotNull(NetworkShare::firstWhere('directory_name', 'Documents'));
        self::assertNotNull(NetworkShare::firstWhere('directory_name', 'Progs'));
        // Aucune assignation (audience laissée à l'admin).
        self::assertSame(0, NetworkShare::firstWhere('directory_name', 'Documents')->assignments()->count());
    }

    #[Test]
    public function apply_is_idempotent(): void
    {
        Process::fake();
        NetworkShare::factory()->create(['directory_name' => 'Documents']);

        $this->artisan('shares:seed-etablissement', ['--apply' => true])->assertExitCode(0);

        // Documents non dupliqué ; Progs créé.
        self::assertSame(1, NetworkShare::where('directory_name', 'Documents')->count());
        self::assertNotNull(NetworkShare::firstWhere('directory_name', 'Progs'));
    }
}
