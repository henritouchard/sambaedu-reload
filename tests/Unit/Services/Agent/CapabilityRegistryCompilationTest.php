<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\AgentTtlResolver;
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
        UserGroupObserver::disableSync();

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
        UserGroupObserver::enableSync();
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
        ], new AgentTtlResolver());
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

    // ── Story 35.4 : précédence UserGroup > Broadcast (deux sens) ─────────
    // Le StateCompiler est INTOUCHÉ : la maille `UserGroup` (rang 1) bat déjà le
    // `Broadcast` (rang 5) via `specificity()`. On prouve ici que quand les DEUX
    // mailles ÉMETTENT une valeur divergente sur la MÊME clé HKCU, l'override
    // UserGroup gagne au compilé — miroir du test parc `override_beats_broadcast`.

    #[Test]
    public function user_group_override_beats_broadcast_when_both_emit(): void
    {
        $user = User::factory()->create();
        $group = UserGroup::factory()->create();
        $user->groups()->attach($group->id);

        // Défaut 'off' → Broadcast émet off=>1 ; override UserGroup 'on' → 0.
        $cap = $this->makeCapability('both_emit', 'off', [
            ['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'K', 'type' => 'REG_DWORD', 'value' => ['on' => 0, 'off' => 1]],
        ]);
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $group->id,
            'value' => 'on',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $session = $this->sessionItems($this->compiler()->compile(TargetContext::for($this->ws, $user)));

        $registry = array_values(array_filter($session, fn ($i): bool => $i['type'] === 'registry'));
        self::assertCount(1, $registry, 'une seule valeur gagne pour l\'identité {hive|path|name}');
        self::assertSame(0, $registry[0]['payload']['value'], 'override UserGroup (on=>0) bat le Broadcast (off=>1)');
    }

    #[Test]
    public function user_group_override_beats_broadcast_inverse(): void
    {
        // Miroir : le Broadcast et l'override échangent leurs valeurs → l'override
        // UserGroup gagne toujours (précédence, pas la valeur).
        $user = User::factory()->create();
        $group = UserGroup::factory()->create();
        $user->groups()->attach($group->id);

        // Défaut 'on' → Broadcast on=>0 ; override UserGroup 'off' → 1.
        $cap = $this->makeCapability('both_emit_inv', 'on', [
            ['hive' => 'HKCU', 'path' => 'Software\\Y', 'name' => 'K', 'type' => 'REG_DWORD', 'value' => ['on' => 0, 'off' => 1]],
        ]);
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $group->id,
            'value' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $session = $this->sessionItems($this->compiler()->compile(TargetContext::for($this->ws, $user)));

        $registry = array_values(array_filter($session, fn ($i): bool => $i['type'] === 'registry'));
        self::assertCount(1, $registry);
        self::assertSame(1, $registry[0]['payload']['value'], 'override UserGroup (off=>1) bat le Broadcast (on=>0)');
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

    // ── Story 35.1 : items `ensure:absent` vs items d'écriture ────────────
    // `exclusiveKey()` est IDENTIQUE pour les deux formes ({hive|path|name}) :
    // la précédence EXISTANTE du StateCompiler (INTOUCHÉ, D2) arbitre.

    #[Test]
    public function parc_override_write_beats_broadcast_absent_for_a_key(): void
    {
        // Broadcast `off` → suppression ; le parc dévie vers `on` → écriture.
        // L'override (maille logique) bat le défaut Broadcast pour CETTE clé.
        $cap = $this->makeCapability('llmnr_disabled', 'off', [
            ['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'EnableMulticast', 'type' => 'REG_DWORD', 'value' => ['on' => 0, 'off' => ['$ensure' => 'absent']]],
        ]);
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->logical->id,
            'value' => 'on',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $session = $this->sessionItems($this->compiler()->compile(TargetContext::for($this->ws, null)));

        $registry = array_values(array_filter($session, fn ($i): bool => $i['type'] === 'registry'));
        self::assertCount(1, $registry, 'une seule valeur gagne pour l\'identité {hive|path|name}');
        self::assertSame(
            ['hive', 'path', 'name', 'type', 'value'],
            array_keys($registry[0]['payload']),
            'l\'override d\'écriture (5 clés) bat le broadcast absent',
        );
        self::assertSame(0, $registry[0]['payload']['value']);
    }

    #[Test]
    public function parc_override_absent_beats_broadcast_write_for_a_key(): void
    {
        // Inverse : Broadcast `on` → écriture ; le parc dévie vers `off` →
        // suppression. L'item `absent` gagne par la MÊME précédence.
        $cap = $this->makeCapability('llmnr_disabled', 'on', [
            ['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'EnableMulticast', 'type' => 'REG_DWORD', 'value' => ['on' => 0, 'off' => ['$ensure' => 'absent']]],
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

        $registry = array_values(array_filter($session, fn ($i): bool => $i['type'] === 'registry'));
        self::assertCount(1, $registry);
        self::assertSame(
            ['hive', 'path', 'name', 'ensure'],
            array_keys($registry[0]['payload']),
            'l\'override de suppression (4 clés) bat le broadcast d\'écriture',
        );
        self::assertSame('absent', $registry[0]['payload']['ensure']);
    }

    // ── Story 35.3 : ruche HKU — coexistence, précédence, machine-only ─────
    // `exclusiveKey()` est INCHANGÉE ({hive|path|name} minuscules) : l'identité
    // `hku|…` est DISTINCTE de la clé HKCU jumelle — les deux items coexistent
    // (machine + session) via le StateCompiler INTOUCHÉ (D2).

    /** La spec numlock bi-ruche (miroir du retrofit 2026_07_03_160000). */
    private const NUMLOCK_KEYS = [
        ['hive' => 'HKCU', 'path' => 'Control Panel\\Keyboard', 'name' => 'InitialKeyboardIndicators', 'type' => 'REG_SZ', 'value' => ['on' => '2', 'off' => '0']],
        ['hive' => 'HKU', 'path' => 'Control Panel\\Keyboard', 'name' => 'InitialKeyboardIndicators', 'type' => 'REG_SZ', 'value' => ['on' => '2', 'off' => '0']],
    ];

    #[Test]
    public function hku_and_hkcu_twin_numlock_items_coexist_across_scopes(): void
    {
        // (a) les items numlock HKU (machine) et HKCU (session) COEXISTENT :
        // identités distinctes (hku|… ≠ hkcu|…), un item par portée, aucune
        // ligne modifiée au StateCompiler.
        $this->makeCapability('numlock_on_logon', 'on', self::NUMLOCK_KEYS);

        $state = $this->compiler()->compile(TargetContext::for($this->ws, null));

        $machine = array_values(array_filter($state[StateContract::SCOPE_MACHINE], fn ($i): bool => $i['type'] === 'registry'));
        $session = array_values(array_filter($state[StateContract::SCOPE_SESSION], fn ($i): bool => $i['type'] === 'registry'));

        self::assertCount(1, $machine, 'la clé HKU compile en portée machine');
        self::assertSame('HKU', $machine[0]['payload']['hive']);
        self::assertSame('2', $machine[0]['payload']['value']);

        self::assertCount(1, $session, 'la clé HKCU jumelle compile en portée session');
        self::assertSame('HKCU', $session[0]['payload']['hive']);
        self::assertSame('2', $session[0]['payload']['value']);

        // Identités exclusives DISTINCTES (même {path|name}, ruches différentes).
        $provider = new RegistryMachineCapabilityProvider();
        self::assertNotSame(
            $provider->exclusiveKey($machine[0]['payload']),
            $provider->exclusiveKey($session[0]['payload']),
            'hku|path|name est une identité distincte de hkcu|path|name',
        );
    }

    #[Test]
    public function parc_override_beats_broadcast_on_the_hku_key(): void
    {
        // (b) précédence broadcast/parc EXISTANTE sur la clé HKU : le parc
        // dévie vers off → la valeur '0' bat le défaut broadcast '2'.
        $cap = $this->makeCapability('numlock_on_logon', 'on', self::NUMLOCK_KEYS);
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->logical->id,
            'value' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $state = $this->compiler()->compile(TargetContext::for($this->ws, null));

        $machine = array_values(array_filter($state[StateContract::SCOPE_MACHINE], fn ($i): bool => $i['type'] === 'registry'));
        self::assertCount(1, $machine, 'une seule valeur gagne pour l\'identité hku|…');
        self::assertSame('0', $machine[0]['payload']['value'], 'l\'override de parc (off=>0) bat le broadcast (on=>2)');
    }

    #[Test]
    public function user_group_override_never_reaches_the_hku_item_in_machine_only_compile(): void
    {
        // (c) « pas de ciblage par utilisateur » est STRUCTUREL (piège #4) : le
        // service SYSTEM fetch son state SANS ?user (TargetContext::for($ws,
        // null) → userGroupIds = []) — un override UserGroup posé en base
        // n'atteint JAMAIS l'item HKU compilé en machine.
        $user = User::factory()->create();
        $group = UserGroup::factory()->create();
        $user->groups()->attach($group->id);

        $cap = $this->makeCapability('numlock_on_logon', 'on', self::NUMLOCK_KEYS);
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $group->id,
            'value' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Compile MACHINE-ONLY (contexte du service : user null).
        $state = $this->compiler()->compile(TargetContext::for($this->ws, null));

        $machine = array_values(array_filter($state[StateContract::SCOPE_MACHINE], fn ($i): bool => $i['type'] === 'registry'));
        self::assertCount(1, $machine);
        self::assertSame(
            '2',
            $machine[0]['payload']['value'],
            'l\'override UserGroup (off) est SANS EFFET sur l\'item HKU : le contexte machine n\'a pas de user',
        );
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
