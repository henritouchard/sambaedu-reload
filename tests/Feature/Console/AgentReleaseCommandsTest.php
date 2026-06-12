<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\AgentRelease;
use App\Models\AgentReleaseRing;
use App\Models\WorkstationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests des commandes artisan releases agent — Story 25.1 (AC1, AC7).
 *
 * `agent:release:create` (OK/KO + exit codes — l'outillage de publication
 * pré-UI 25.5), `agent:release:target` (lookup WG par name, updateOrCreate
 * + récence), `agent:release:promote` (swap du pointeur stable). Commandes
 * minces : la matrice des refus vit dans `ReleaseCreationServiceTest`, ici
 * on prouve la traduction exit ≠ 0 / aucune écriture.
 */
class AgentReleaseCommandsTest extends TestCase
{
    use RefreshDatabase;

    private string $releasesDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->releasesDir = storage_path('framework/testing/releases-' . uniqid());
        File::ensureDirectoryExists($this->releasesDir);
        config(['agent.releases_path' => $this->releasesDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->releasesDir);

        parent::tearDown();
    }

    private function putBinary(string $filename, string $content = "MZ\x90\x00fake-pe"): string
    {
        file_put_contents($this->releasesDir . '/' . $filename, $content);

        return hash('sha256', $content);
    }

    // ── agent:release:create ─────────────────────────────────────────────

    #[Test]
    public function create_publishes_a_verified_release_with_stable_flag(): void
    {
        $hash = $this->putBinary('sambaedu-agent-2.1.2.exe');

        $this->artisan('agent:release:create', [
            'version' => '2.1.2',
            'filename' => 'sambaedu-agent-2.1.2.exe',
            '--hash' => $hash,
            '--stable' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('agent_releases', [
            'version' => '2.1.2',
            'hash' => $hash,
            'filename' => 'sambaedu-agent-2.1.2.exe',
            'is_stable' => true,
        ]);
    }

    #[Test]
    public function create_with_mismatching_hash_fails_without_any_write(): void
    {
        $this->putBinary('sambaedu-agent-2.1.2.exe');

        $this->artisan('agent:release:create', [
            'version' => '2.1.2',
            'filename' => 'sambaedu-agent-2.1.2.exe',
            '--hash' => str_repeat('0', 64),
        ])->assertExitCode(1);

        self::assertSame(0, AgentRelease::query()->count());
    }

    #[Test]
    public function create_without_hash_option_fails(): void
    {
        $this->putBinary('sambaedu-agent-2.1.2.exe');

        $this->artisan('agent:release:create', [
            'version' => '2.1.2',
            'filename' => 'sambaedu-agent-2.1.2.exe',
        ])->assertExitCode(1);

        self::assertSame(0, AgentRelease::query()->count());
    }

    // ── agent:release:target ─────────────────────────────────────────────

    #[Test]
    public function target_rings_an_existing_group_and_refreshes_recency_on_retarget(): void
    {
        $hash = $this->putBinary('sambaedu-agent-2.1.2.exe');
        $this->artisan('agent:release:create', [
            'version' => '2.1.2',
            'filename' => 'sambaedu-agent-2.1.2.exe',
            '--hash' => $hash,
        ])->assertExitCode(0);
        $group = WorkstationGroup::factory()->create();

        $this->artisan('agent:release:target', [
            'version' => '2.1.2',
            'group' => $group->name,
        ])->assertExitCode(0);

        $ring = AgentReleaseRing::query()->sole();
        self::assertSame($group->id, $ring->workstation_group_id);

        // Re-ciblage de la MÊME version (rollback) : ligne unique, récence
        // rafraîchie (updateOrCreate + touch — décision n° 6).
        $past = now()->subDays(2)->startOfSecond();
        DB::table('agent_release_rings')->where('id', $ring->id)
            ->update(['updated_at' => $past->toDateTimeString()]);

        $this->artisan('agent:release:target', [
            'version' => '2.1.2',
            'group' => $group->name,
        ])->assertExitCode(0);

        self::assertSame(1, AgentReleaseRing::query()->count());
        self::assertTrue($ring->refresh()->updated_at->greaterThan($past));
    }

    #[Test]
    public function target_unknown_group_or_version_fails_cleanly(): void
    {
        $hash = $this->putBinary('sambaedu-agent-2.1.2.exe');
        $this->artisan('agent:release:create', [
            'version' => '2.1.2',
            'filename' => 'sambaedu-agent-2.1.2.exe',
            '--hash' => $hash,
        ])->assertExitCode(0);

        $this->artisan('agent:release:target', [
            'version' => '2.1.2',
            'group' => 'salle_inexistante',
        ])->assertExitCode(1);

        $this->artisan('agent:release:target', [
            'version' => '9.9.9',
            'group' => WorkstationGroup::factory()->create()->name,
        ])->assertExitCode(1);

        self::assertSame(0, AgentReleaseRing::query()->count());
    }

    // ── agent:release:promote ────────────────────────────────────────────

    #[Test]
    public function promote_swaps_the_stable_pointer(): void
    {
        $h1 = $this->putBinary('sambaedu-agent-2.0.0.exe', 'one');
        $h2 = $this->putBinary('sambaedu-agent-2.1.2.exe', 'two');
        $this->artisan('agent:release:create', [
            'version' => '2.0.0',
            'filename' => 'sambaedu-agent-2.0.0.exe',
            '--hash' => $h1,
            '--stable' => true,
        ])->assertExitCode(0);
        $this->artisan('agent:release:create', [
            'version' => '2.1.2',
            'filename' => 'sambaedu-agent-2.1.2.exe',
            '--hash' => $h2,
        ])->assertExitCode(0);

        $this->artisan('agent:release:promote', ['version' => '2.1.2'])->assertExitCode(0);

        self::assertTrue(AgentRelease::query()->where('version', '2.1.2')->sole()->is_stable);
        self::assertFalse(AgentRelease::query()->where('version', '2.0.0')->sole()->is_stable);
        self::assertSame(1, AgentRelease::query()->where('is_stable', true)->count());
    }

    #[Test]
    public function promote_unknown_version_fails(): void
    {
        $this->artisan('agent:release:promote', ['version' => '9.9.9'])->assertExitCode(1);
    }
}
