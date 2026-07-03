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
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Providers\RegistryListMachineCapabilityProvider;
use App\Services\Agent\Providers\RegistryListUserCapabilityProvider;
use App\Services\Agent\StateCompiler;
use App\Services\Agent\StateContract;
use App\Services\Agent\StateHasher;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 35.2 (AC2) — compilation BOUT-EN-BOUT capacité → conteneurs
 * `registry_list` via le `StateCompiler` INTOUCHÉ (D2 — piège n°2).
 *
 * Prouve que `exclusiveKey() = {hive|path}` (2 segments) suffit : la maille la
 * plus spécifique gagne la clé-conteneur ENTIÈRE (la liste de l'override
 * REMPLACE celle du broadcast — JAMAIS d'union d'entrées entre mailles), les
 * conteneurs distincts s'accumulent, UserGroup bat Broadcast (cas
 * blocked_executables, Session/UserGroup-ciblée).
 */
class CapabilityRegistryListCompilationTest extends TestCase
{
    use RefreshDatabase;

    private Workstation $ws;

    private WorkstationGroup $parc;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();

        DB::table('capability_assignments')->delete();
        DB::table('capability_projections')->delete();
        DB::table('capabilities')->delete();

        $this->ws = Workstation::factory()->create();
        $this->parc = WorkstationGroup::factory()->logical()->create();
        $this->ws->groups()->attach($this->parc->id);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    /**
     * @param  list<array<string,mixed>>  $keys
     */
    private function makeListCapability(string $key, string $default, array $keys): Capability
    {
        $cap = Capability::factory()->create(['key' => $key, 'default_value' => $default]);
        CapabilityProjection::factory()->for($cap)->keys($keys)->create([
            'mechanism' => CapabilityProjection::MECHANISM_REGISTRY_LIST,
        ]);

        return $cap;
    }

    private function compiler(): StateCompiler
    {
        // Compilateur RÉEL non modifié (D2) + les seuls providers list.
        return new StateCompiler(new StateHasher(), [
            new RegistryListMachineCapabilityProvider(),
            new RegistryListUserCapabilityProvider(),
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listItems(array $state, string $scope): array
    {
        return array_values(array_filter(
            $state[$scope],
            static fn (array $i): bool => $i['type'] === 'registry_list',
        ));
    }

    #[Test]
    public function parc_override_replaces_the_entire_broadcast_list_never_a_union(): void
    {
        // Broadcast ['a','b'] battu par override de parc ['c'] → la cible du
        // conteneur est ['c'], PAS ['a','b','c'] (piège n°2 : la maille la plus
        // spécifique gagne le conteneur ENTIER).
        $cap = $this->makeListCapability('list_cap', 'on', [
            ['hive' => 'HKLM', 'path' => 'SOFTWARE\\X\\List', 'entry_type' => 'REG_SZ', 'values' => ['on' => ['a', 'b'], 'alt' => ['c']]],
        ]);
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->parc->id,
            'value' => 'alt',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $machine = $this->listItems(
            $this->compiler()->compile(TargetContext::for($this->ws, null)),
            StateContract::SCOPE_MACHINE,
        );

        self::assertCount(1, $machine, 'UN conteneur gagnant pour l\'identité {hive|path}');
        self::assertSame(['c'], $machine[0]['payload']['values'], 'REMPLACEMENT entier, jamais d\'union');
        self::assertSame(['type', 'semantics', 'payload', 'hash'], array_keys($machine[0]));
        self::assertSame('exclusive', $machine[0]['semantics']);
    }

    #[Test]
    public function empty_list_override_beats_broadcast_list(): void
    {
        // Le « off » honnête (purge []) est un candidat de plein droit : il bat
        // le broadcast par la même précédence.
        $cap = $this->makeListCapability('list_cap', 'on', [
            ['hive' => 'HKCU', 'path' => 'Software\\P\\DisallowRun', 'entry_type' => 'REG_SZ', 'values' => ['on' => ['cmd.exe'], 'off' => []]],
        ]);
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->parc->id,
            'value' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $session = $this->listItems(
            $this->compiler()->compile(TargetContext::for($this->ws, null)),
            StateContract::SCOPE_SESSION,
        );

        self::assertCount(1, $session);
        self::assertSame([], $session[0]['payload']['values'], 'la purge [] remplace la liste du broadcast');
    }

    #[Test]
    public function distinct_containers_accumulate(): void
    {
        // Chrome + Edge (2 conteneurs d'une MÊME capacité) + un conteneur d'une
        // autre capacité : les identités {hive|path} distinctes s'accumulent.
        $this->makeListCapability('pix_extension_forced', 'on', [
            ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Policies\\Google\\Chrome\\ExtensionInstallForcelist', 'entry_type' => 'REG_SZ', 'values' => ['on' => ['abc']]],
            ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Policies\\Microsoft\\Edge\\ExtensionInstallForcelist', 'entry_type' => 'REG_SZ', 'values' => ['on' => ['abc;https://u']]],
        ]);
        $this->makeListCapability('other_list', 'on', [
            ['hive' => 'HKLM', 'path' => 'SOFTWARE\\X\\Other', 'entry_type' => 'REG_SZ', 'values' => ['on' => ['x']]],
        ]);

        $machine = $this->listItems(
            $this->compiler()->compile(TargetContext::for($this->ws, null)),
            StateContract::SCOPE_MACHINE,
        );

        self::assertCount(3, $machine, 'trois conteneurs distincts s\'accumulent');
        $paths = array_map(static fn (array $i): string => $i['payload']['path'], $machine);
        sort($paths);
        self::assertSame([
            'SOFTWARE\\Policies\\Google\\Chrome\\ExtensionInstallForcelist',
            'SOFTWARE\\Policies\\Microsoft\\Edge\\ExtensionInstallForcelist',
            'SOFTWARE\\X\\Other',
        ], $paths);
    }

    #[Test]
    public function user_group_override_beats_broadcast_for_a_container(): void
    {
        // blocked_executables est Session/UserGroup-ciblée : l'override du
        // groupe utilisateur (maille UserGroup, rang 1) bat le Broadcast (rang 5).
        $user = User::factory()->create();
        $group = UserGroup::factory()->create();
        $user->groups()->attach($group->id);

        $cap = $this->makeListCapability('blocked_executables', 'on', [
            ['hive' => 'HKCU', 'path' => 'Software\\P\\DisallowRun', 'entry_type' => 'REG_SZ', 'values' => ['on' => ['cmd.exe'], 'hard' => ['cmd.exe', 'powershell.exe']]],
        ]);
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $group->id,
            'value' => 'hard',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $session = $this->listItems(
            $this->compiler()->compile(TargetContext::for($this->ws, $user)),
            StateContract::SCOPE_SESSION,
        );

        self::assertCount(1, $session);
        self::assertSame(
            ['cmd.exe', 'powershell.exe'],
            $session[0]['payload']['values'],
            'UserGroup remplace la liste du Broadcast pour ce conteneur',
        );
    }

    #[Test]
    public function state_compiler_source_is_untouched_by_the_story(): void
    {
        // Garde-fou D2 (piège n°2) : zéro référence registry_list dans le
        // compilateur — le mécanisme passe ENTIÈREMENT par exclusiveKey().
        $src = (string) file_get_contents(app_path('Services/Agent/StateCompiler.php'));
        self::assertStringNotContainsString('registry_list', $src);
        self::assertStringNotContainsString('RegistryList', $src);
    }
}
