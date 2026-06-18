<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Providers\RegistryMachineCapabilityProvider;
use App\Services\Agent\Providers\RegistryUserCapabilityProvider;
use App\Services\Agent\StateCompiler;
use App\Services\Agent\StateContract;
use App\Services\Agent\StateHasher;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 27.12 (AC4) — compilation BOUT-EN-BOUT capacité → items de contrat via le
 * `StateCompiler` INCHANGÉ et les vrais providers capability-first.
 *
 * Prouve que la couture « capability → registry » se branche sur l'exclusive par
 * clé existante SANS modifier le compilateur : override bat défaut (précédence),
 * parc logique bat parc physique (D-Q3), clés distinctes s'accumulent, collision
 * de 2 capacités sur la même clé arbitrée par la récence.
 */
class CapabilityRegistryCompilationTest extends TestCase
{
    use RefreshDatabase;

    private Workstation $ws;

    private WorkstationGroup $logical;

    private WorkstationGroup $physical;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();

        DB::table('capability_assignments')->delete();
        DB::table('capability_projections')->delete();
        DB::table('capabilities')->delete();

        $this->ws = Workstation::factory()->create();
        $this->logical = WorkstationGroup::factory()->logical()->create();
        // Défaut factory = is_physical true (salle physique).
        $this->physical = WorkstationGroup::factory()->create(['is_physical' => true]);
        $this->ws->groups()->attach($this->logical->id);
        $this->ws->groups()->attach($this->physical->id);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        parent::tearDown();
    }

    /**
     * @param  list<array<string,mixed>>  $keys
     */
    private function makeCapability(string $key, string $default, array $keys): Capability
    {
        $cap = Capability::factory()->create(['key' => $key, 'default_value' => $default]);
        CapabilityProjection::factory()->for($cap)->keys($keys)->create();

        return $cap;
    }

    private function compiler(): StateCompiler
    {
        return new StateCompiler(new StateHasher(), [
            new RegistryMachineCapabilityProvider(),
            new RegistryUserCapabilityProvider(),
        ]);
    }

    private function sessionItems(array $state): array
    {
        return $state[StateContract::SCOPE_SESSION];
    }

    #[Test]
    public function override_beats_broadcast_default_for_a_key(): void
    {
        $cap = $this->makeCapability('show_file_extensions', 'on', [
            ['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'HideFileExt', 'type' => 'REG_DWORD', 'value' => ['on' => 0, 'off' => 1]],
        ]);
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->logical->id,
            'value' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $session = $this->sessionItems($this->compiler()->compile(TargetContext::for($this->ws, null)));

        // Une seule clé HideFileExt → l'override (off=1) gagne sur le défaut (on=0).
        $registry = array_values(array_filter($session, fn ($i): bool => $i['type'] === 'registry'));
        self::assertCount(1, $registry);
        self::assertSame(1, $registry[0]['payload']['value']);
    }

    #[Test]
    public function logical_group_beats_physical_group(): void
    {
        // D-Q3 : le parc LOGIQUE bat la salle PHYSIQUE pour une même clé.
        $cap = $this->makeCapability('a_cap', 'on', [
            ['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'Shared', 'type' => 'REG_DWORD', 'value' => ['on' => 0, 'off' => 1, 'mid' => 5]],
        ]);
        DB::table('capability_assignments')->insert([
            ['capability_id' => $cap->id, 'assignable_type' => WorkstationGroup::class, 'assignable_id' => $this->physical->id, 'value' => 'off', 'created_at' => now(), 'updated_at' => now()],
            ['capability_id' => $cap->id, 'assignable_type' => WorkstationGroup::class, 'assignable_id' => $this->logical->id, 'value' => 'mid', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $session = $this->sessionItems($this->compiler()->compile(TargetContext::for($this->ws, null)));

        $registry = array_values(array_filter($session, fn ($i): bool => $i['type'] === 'registry'));
        self::assertCount(1, $registry);
        self::assertSame(5, $registry[0]['payload']['value'], 'le parc logique (mid=5) bat la salle physique (off=1)');
    }

    #[Test]
    public function distinct_keys_accumulate(): void
    {
        $this->makeCapability('cap_a', 'on', [
            ['hive' => 'HKCU', 'path' => 'Software\\A', 'name' => 'KeyA', 'type' => 'REG_DWORD', 'value' => ['on' => 1]],
        ]);
        $this->makeCapability('cap_b', 'on', [
            ['hive' => 'HKCU', 'path' => 'Software\\B', 'name' => 'KeyB', 'type' => 'REG_DWORD', 'value' => ['on' => 2]],
        ]);

        $session = $this->sessionItems($this->compiler()->compile(TargetContext::for($this->ws, null)));

        $registry = array_values(array_filter($session, fn ($i): bool => $i['type'] === 'registry'));
        self::assertCount(2, $registry, 'deux clés distinctes s\'accumulent');
        $names = array_map(fn ($i): string => $i['payload']['name'], $registry);
        sort($names);
        self::assertSame(['KeyA', 'KeyB'], $names);
    }

    #[Test]
    public function two_capabilities_defining_the_same_key_collide_and_recency_wins(): void
    {
        // Deux capacités au DÉFAUT (Broadcast) définissant la MÊME clé → collision
        // arbitrée par la récence (la capacité la plus récente / id desc gagne).
        $old = $this->makeCapability('cap_old', 'on', [
            ['hive' => 'HKCU', 'path' => 'Software\\Shared', 'name' => 'Conflict', 'type' => 'REG_DWORD', 'value' => ['on' => 10]],
        ]);
        // Force des updated_at distincts (le plus récent gagne au sein du Broadcast).
        Capability::query()->whereKey($old->id)->update(['updated_at' => now()->subMinutes(5)]);

        $new = $this->makeCapability('cap_new', 'on', [
            ['hive' => 'HKCU', 'path' => 'Software\\Shared', 'name' => 'Conflict', 'type' => 'REG_DWORD', 'value' => ['on' => 20]],
        ]);
        Capability::query()->whereKey($new->id)->update(['updated_at' => now()]);

        $session = $this->sessionItems($this->compiler()->compile(TargetContext::for($this->ws, null)));

        $registry = array_values(array_filter($session, fn ($i): bool => $i['type'] === 'registry'));
        self::assertCount(1, $registry, 'une seule valeur gagne pour la clé en collision');
        self::assertSame(20, $registry[0]['payload']['value'], 'la capacité la plus récente gagne');
    }
}
