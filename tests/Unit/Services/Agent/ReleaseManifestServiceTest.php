<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Models\AgentRelease;
use App\Models\AgentReleaseRing;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Releases\ReleaseManifestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `ReleaseManifestService` — Story 25.1 (AC2, AC3).
 *
 * Règle de résolution complète : ring du poste → récence multi-rings
 * (+ warning `ring_conflict`) → fallback stable → null. URL absolue
 * construite à la réponse. Sans HTTP (le contrat HTTP vit dans
 * `ReleaseEndpointTest`) — les lignes sont posées directement (le service
 * de création est testé dans `ReleaseCreationServiceTest`).
 */
class ReleaseManifestServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReleaseManifestService $service;

    private Workstation $ws;

    protected function setUp(): void
    {
        parent::setUp();

        // Neutraliser les observers AD (host sans LDAP).
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();

        $this->service = new ReleaseManifestService();
        $this->ws = Workstation::factory()->create();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    private function release(string $version, bool $stable = false): AgentRelease
    {
        return AgentRelease::query()->create([
            'version' => $version,
            'hash' => hash('sha256', $version),
            'filename' => 'sambaedu-agent-' . $version . '.exe',
            'is_stable' => $stable,
        ]);
    }

    private function ring(WorkstationGroup $group, AgentRelease $release, ?string $updatedAt = null): AgentReleaseRing
    {
        $ring = AgentReleaseRing::query()->create([
            'workstation_group_id' => $group->id,
            'agent_release_id' => $release->id,
        ]);
        if ($updatedAt !== null) {
            DB::table('agent_release_rings')->where('id', $ring->id)->update(['updated_at' => $updatedAt]);
        }

        return $ring->refresh();
    }

    private function memberOf(WorkstationGroup $group): void
    {
        $this->ws->groups()->attach($group->id);
    }

    #[Test]
    public function resolves_the_ring_release_for_a_group_member(): void
    {
        $this->release('2.0.0', stable: true);
        $canary = $this->release('2.1.2');
        $group = WorkstationGroup::factory()->create();
        $this->memberOf($group);
        $this->ring($group, $canary);

        $manifest = $this->service->manifestFor($this->ws);

        self::assertNotNull($manifest);
        self::assertSame(['version', 'hash', 'url'], array_keys($manifest));
        self::assertSame('2.1.2', $manifest['version']);
        self::assertSame($canary->hash, $manifest['hash']);
        self::assertSame(
            route('agent.v1.release.download', ['filename' => $canary->filename]),
            $manifest['url'],
        );
        self::assertStringStartsWith('http', $manifest['url']);
    }

    #[Test]
    public function workstation_without_any_group_gets_the_stable(): void
    {
        $stable = $this->release('2.0.0', stable: true);

        $manifest = $this->service->manifestFor($this->ws);

        self::assertSame($stable->version, $manifest['version'] ?? null);
    }

    #[Test]
    public function group_member_without_ring_gets_the_stable(): void
    {
        $stable = $this->release('2.0.0', stable: true);
        $this->release('2.1.2'); // ni stable ni ciblée — ne fuit pas
        $this->memberOf(WorkstationGroup::factory()->create());

        $manifest = $this->service->manifestFor($this->ws);

        self::assertSame($stable->version, $manifest['version'] ?? null);
    }

    #[Test]
    public function no_ring_and_no_stable_returns_null(): void
    {
        $this->release('2.1.2'); // existe mais non applicable

        self::assertNull($this->service->manifestFor($this->ws));
    }

    #[Test]
    public function most_recently_updated_ring_wins_and_conflict_is_warned(): void
    {
        $old = $this->release('2.1.0');
        $new = $this->release('2.1.2');
        $parc = WorkstationGroup::factory()->logical()->create();
        $lab = WorkstationGroup::factory()->create();
        $this->memberOf($parc);
        $this->memberOf($lab);
        $this->ring($parc, $old, now()->subDays(3)->toDateTimeString());
        $this->ring($lab, $new, now()->toDateTimeString());

        $logs = new \ArrayObject();
        Log::shouldReceive('channel')->with('agent')->andReturnSelf();
        foreach (['debug', 'info', 'warning', 'error', 'critical'] as $level) {
            Log::shouldReceive($level)->andReturnUsing(
                function (string $message, array $context = []) use ($logs, $level): void {
                    $logs->append([$level, $message, $context]);
                },
            );
        }

        $manifest = $this->service->manifestFor($this->ws);

        self::assertSame('2.1.2', $manifest['version'] ?? null);
        $conflicts = array_values(array_filter(
            $logs->getArrayCopy(),
            fn (array $log): bool => ($log[2]['action_type'] ?? null) === 'agent.release.ring_conflict',
        ));
        self::assertCount(1, $conflicts);
        self::assertSame('warning', $conflicts[0][0]);
        self::assertSame($this->ws->id, $conflicts[0][2]['workstation_id']);
        self::assertEqualsCanonicalizing([$parc->id, $lab->id], $conflicts[0][2]['group_ids']);
    }

    #[Test]
    public function aligned_rings_on_the_same_release_do_not_warn(): void
    {
        // Review 25.1 #5 : conflit = ambiguïté RÉELLE (releases distinctes).
        // Salle + parc alignés sur la même version = cas banal, zéro warning
        // (sinon pollution du canal à chaque check-in, NFR4).
        $release = $this->release('2.1.2');
        $parc = WorkstationGroup::factory()->logical()->create();
        $salle = WorkstationGroup::factory()->create();
        $this->memberOf($parc);
        $this->memberOf($salle);
        $this->ring($parc, $release);
        $this->ring($salle, $release);

        $logs = new \ArrayObject();
        Log::shouldReceive('channel')->with('agent')->andReturnSelf();
        foreach (['debug', 'info', 'warning', 'error', 'critical'] as $level) {
            Log::shouldReceive($level)->andReturnUsing(
                function (string $message, array $context = []) use ($logs, $level): void {
                    $logs->append([$level, $message, $context]);
                },
            );
        }

        $manifest = $this->service->manifestFor($this->ws);

        self::assertSame('2.1.2', $manifest['version'] ?? null);
        $conflicts = array_filter(
            $logs->getArrayCopy(),
            fn (array $log): bool => ($log[2]['action_type'] ?? null) === 'agent.release.ring_conflict',
        );
        self::assertCount(0, $conflicts);
    }

    #[Test]
    public function equal_recency_is_tie_broken_deterministically_by_id(): void
    {
        // Les timestamps SQLite/PG coïncident à la seconde : le tie-break
        // id desc rend la résolution déterministe (jamais d'aléa de tri).
        $a = $this->release('2.1.0');
        $b = $this->release('2.1.2');
        $g1 = WorkstationGroup::factory()->create();
        $g2 = WorkstationGroup::factory()->create();
        $this->memberOf($g1);
        $this->memberOf($g2);
        $same = now()->startOfSecond()->toDateTimeString();
        $this->ring($g1, $a, $same);
        $last = $this->ring($g2, $b, $same);

        $manifest = $this->service->manifestFor($this->ws);

        self::assertSame($last->release->version, $manifest['version'] ?? null);
    }

    #[Test]
    public function deleted_release_cascades_its_ring_and_falls_back_to_stable(): void
    {
        $stable = $this->release('2.0.0', stable: true);
        $canary = $this->release('2.1.2');
        $group = WorkstationGroup::factory()->create();
        $this->memberOf($group);
        $this->ring($group, $canary);

        $canary->delete();

        $manifest = $this->service->manifestFor($this->ws);

        self::assertSame(0, AgentReleaseRing::query()->count(), 'FK cascade : le ring meurt avec la release.');
        self::assertSame($stable->version, $manifest['version'] ?? null);
    }

    #[Test]
    public function a_single_ring_never_warns_conflict(): void
    {
        $canary = $this->release('2.1.2');
        $group = WorkstationGroup::factory()->create();
        $this->memberOf($group);
        $this->ring($group, $canary);

        Log::shouldReceive('channel')->with('agent')->andReturnSelf();
        Log::shouldReceive('warning')->never();
        Log::shouldReceive('debug', 'info', 'error', 'critical')->andReturnNull();

        $manifest = $this->service->manifestFor($this->ws);

        self::assertSame('2.1.2', $manifest['version'] ?? null);
    }
}
