<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Agent;

use App\Models\AgentRelease;
use App\Models\AgentReleaseRing;
use App\Models\User;
use App\Models\WorkstationGroup;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Story 25.5 — surface releases & rings (AC2, AC3, AC6).
 *
 * L'UI est la 2ᵉ façade sur `ReleaseCreationService` (SEUL écrivain) : cibler
 * un ring = `target()` (`agent.release.targeted`), définir la stable =
 * `promote()` (`agent.release.promoted`), rollback ring = re-`target()` sur la
 * stable. Version inconnue → `toastError`, jamais 500. Gate `server.admin`
 * sur chaque action mutante (adressabilité /livewire/update).
 */
class ReleasesRingsSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::admin.settings.agent._partials.releases-rings';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'server.admin', 'guard_name' => 'web']);
        $this->admin = User::query()->create(['login' => 'rel-admin', 'role' => 'prof', 'is_active' => true]);
        $this->admin->givePermissionTo('server.admin');
        $this->actingAs($this->admin);
    }

    private function release(string $version, bool $stable = false): AgentRelease
    {
        return AgentRelease::query()->create([
            'version' => $version,
            'hash' => str_repeat('a', 64),
            'filename' => "sambaedu-agent-{$version}.exe",
            'is_stable' => $stable,
        ]);
    }

    #[Test]
    public function targeting_a_ring_calls_target_and_creates_the_ring(): void
    {
        $this->release('2.1.1', stable: true);
        $rel = $this->release('2.2.0');
        $group = WorkstationGroup::factory()->create(['name' => 'salle-canari']);

        Livewire::test(self::COMPONENT)
            ->call('openTarget', $group->id)
            ->assertSet('isTargetOpen', true)
            ->set('targetVersion', '2.2.0')
            ->call('confirmTarget')
            ->assertSet('isTargetOpen', false)
            ->assertDispatched('toastMagic', fn ($event, $params) => ($params['status'] ?? null) === 'success'
                && str_contains($params['message'] ?? '', '2.2.0'));

        $ring = AgentReleaseRing::query()->where('workstation_group_id', $group->id)->sole();
        self::assertSame($rel->id, $ring->agent_release_id);
    }

    #[Test]
    public function targeting_an_unknown_version_toasts_error_not_500(): void
    {
        $this->release('2.1.1', stable: true);
        $group = WorkstationGroup::factory()->create();

        Livewire::test(self::COMPONENT)
            ->call('openTarget', $group->id)
            ->set('targetVersion', '9.9.9') // n'existe pas
            ->call('confirmTarget')
            ->assertDispatched('toastMagic', fn ($event, $params) => ($params['status'] ?? null) === 'error');

        // Aucune écriture : la version inconnue est refusée par le service.
        self::assertSame(0, AgentReleaseRing::query()->count());
    }

    #[Test]
    public function promote_moves_the_default_stable_pointer(): void
    {
        $old = $this->release('2.1.1', stable: true);
        $new = $this->release('2.2.0');

        Livewire::test(self::COMPONENT)
            ->call('openPromote', '2.2.0')
            ->assertSet('isPromoteOpen', true)
            ->call('confirmPromote')
            ->assertSet('isPromoteOpen', false)
            ->assertDispatched('toastMagic');

        self::assertTrue($new->refresh()->is_stable);
        self::assertFalse($old->refresh()->is_stable);
        // Invariant « au plus une stable ».
        self::assertSame(1, AgentRelease::query()->where('is_stable', true)->count());
    }

    #[Test]
    public function rollback_ring_retargets_on_the_default_stable(): void
    {
        $stable = $this->release('2.1.1', stable: true);
        $canari = $this->release('2.2.0');
        $group = WorkstationGroup::factory()->create();
        $ring = AgentReleaseRing::query()->create([
            'workstation_group_id' => $group->id,
            'agent_release_id' => $canari->id,
        ]);

        Livewire::test(self::COMPONENT)
            ->call('rollbackRing', $ring->id)
            ->assertDispatched('toastMagic');

        self::assertSame($stable->id, $ring->refresh()->agent_release_id);
    }

    /**
     * AC6 : CHAQUE méthode mutante vérifie `Gate::authorize('server.admin')`
     * (adressabilité /livewire/update). On couvre les 5 méthodes, pas seulement
     * `openTarget` — un refactor qui retirerait un seul `Gate::authorize` casse.
     *
     * @return array<string,array{0:string,1:array<int,mixed>}>
     */
    public static function mutatingMethodsProvider(): array
    {
        return [
            'openTarget' => ['openTarget', [1]],
            'confirmTarget' => ['confirmTarget', []],
            'rollbackRing' => ['rollbackRing', [1]],
            'openPromote' => ['openPromote', ['2.2.0']],
            'confirmPromote' => ['confirmPromote', []],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('mutatingMethodsProvider')]
    public function mutating_actions_are_forbidden_without_permission(string $method, array $args): void
    {
        $this->release('2.1.1', stable: true);
        WorkstationGroup::factory()->create();

        $viewer = User::query()->create(['login' => 'rel-viewer', 'role' => 'eleve', 'is_active' => true]);
        $this->actingAs($viewer);

        $this->withoutExceptionHandling();
        $this->expectException(AuthorizationException::class);

        Livewire::test(self::COMPONENT)->call($method, ...$args);
    }
}
