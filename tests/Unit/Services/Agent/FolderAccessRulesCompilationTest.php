<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\FolderAccessRule;
use App\Models\FolderAccessRuleAssignable;
use App\Models\UserGroup;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\AgentTtlResolver;
use App\Services\Agent\Providers\FolderAccessRulesStateProvider;
use App\Services\Agent\Providers\FsAclCapabilityProvider;
use App\Services\Agent\StateCompiler;
use App\Services\Agent\StateContract;
use App\Services\Agent\StateHasher;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 36.4 (AC3) — compilation BOUT-EN-BOUT via le `StateCompiler` INTOUCHÉ
 * (D1/D2). Prouve l'arbitrage règle↔capacité par la sélection exclusive UNIQUE
 * (deux flux, UN provider) : (a) règle de parc bat le défaut Broadcast d'une
 * capacité de même identité ; (b) sur maille ÉGALE, la récence tranche ; (c) deux
 * identités distinctes coexistent ; (d) sans règle, byte-identité avec le
 * provider capacités nu.
 */
class FolderAccessRulesCompilationTest extends TestCase
{
    use RefreshDatabase;

    private Workstation $ws;

    private WorkstationGroup $logical;

    private UserGroup $group;

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
        $this->logical = WorkstationGroup::factory()->logical()->create();
        $this->ws->groups()->attach($this->logical->id);

        // Groupe dont le CN dérivé = 'Classe_3A' (matche les capacités littérales).
        $this->group = UserGroup::factory()->create(['name' => '3A', 'ad_dn' => 'CN=Classe_3A,OU=Groups']);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    private function compiler(): StateCompiler
    {
        return new StateCompiler(new StateHasher(), [
            new FolderAccessRulesStateProvider(new FsAclCapabilityProvider()),
        ], new AgentTtlResolver());
    }

    private function bareCompiler(): StateCompiler
    {
        return new StateCompiler(new StateHasher(), [new FsAclCapabilityProvider()], new AgentTtlResolver());
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function machineFsAcl(StateCompiler $compiler): array
    {
        $state = $compiler->compile(TargetContext::for($this->ws, null));

        return array_values(array_filter(
            $state[StateContract::SCOPE_MACHINE],
            fn ($i): bool => $i['type'] === 'fs_acl',
        ));
    }

    /**
     * @param  list<array<string,mixed>>  $aces
     */
    private function makeCapability(string $key, string $default, array $aces): Capability
    {
        $cap = Capability::factory()->create(['key' => $key, 'default_value' => $default]);
        CapabilityProjection::factory()->for($cap)->create([
            'mechanism' => CapabilityProjection::MECHANISM_FS_ACL,
            'spec' => ['aces' => $aces],
        ]);

        return $cap;
    }

    private function ruleOnLogical(array $overrides = []): FolderAccessRule
    {
        $rule = FolderAccessRule::factory()->create(array_merge([
            'user_group_id' => $this->group->id,
            'path' => 'D:\\Ressources',
            'ace_type' => 'deny',
            'rights' => 'list_folder',
            'applies_to' => 'folder_only',
        ], $overrides));
        FolderAccessRuleAssignable::create([
            'folder_access_rule_id' => $rule->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->logical->id,
        ]);

        return $rule;
    }

    /** Identité partagée : { D:\Ressources | Classe_3A | deny }. */
    private const SHARED_ACE = [[
        'path' => 'D:\\Ressources',
        'ace_type' => 'deny',
        'rights' => 'list_folder',
        'applies_to' => 'folder_only',
        'trustee' => 'Classe_3A',
        'ensure' => ['on' => 'present', 'off' => 'absent'],
    ]];

    // ── (a) Règle de parc bat le défaut Broadcast (identité ÉGALE) ────────

    #[Test]
    public function rule_on_parc_beats_capability_broadcast_default_on_equal_identity(): void
    {
        // Capacité broadcast `off` → item ABSENT ; règle de parc ACTIVE → PRESENT.
        $this->makeCapability('cap_shared', 'off', self::SHARED_ACE);
        $this->ruleOnLogical(['is_active' => true]);

        $items = $this->machineFsAcl($this->compiler());
        self::assertCount(1, $items, 'une seule valeur gagne pour l\'identité partagée');
        self::assertSame('present', $items[0]['payload']['ensure'], 'la règle de parc (present) bat le broadcast (absent)');
        self::assertSame('Classe_3A', $items[0]['payload']['trustee']);
    }

    // ── (b) Maille ÉGALE : la récence tranche ─────────────────────────────

    #[Test]
    public function on_equal_maille_recency_decides(): void
    {
        // Capacité avec OVERRIDE sur le MÊME parc (LogicalGroup, present), posé
        // dans le PASSÉ ; règle sur le même parc (absent), plus RÉCENTE → gagne.
        $cap = $this->makeCapability('cap_shared', 'off', self::SHARED_ACE);
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->logical->id,
            'value' => 'on', // present
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $rule = $this->ruleOnLogical(['is_active' => false]); // absent
        // Rafraîchit la règle ET son pivot pour qu'ils soient plus récents.
        $rule->touch();
        FolderAccessRuleAssignable::where('folder_access_rule_id', $rule->id)->update(['updated_at' => now()]);

        $items = $this->machineFsAcl($this->compiler());
        self::assertCount(1, $items);
        self::assertSame('absent', $items[0]['payload']['ensure'], 'à maille égale, la règle plus récente (absent) gagne');
    }

    // ── (c) Identités distinctes COEXISTENT (cumul, piège #2) ─────────────

    #[Test]
    public function distinct_identities_coexist(): void
    {
        // Capacité trustee Profs ; règle trustee Classe_3A — même path, identités
        // distinctes → les DEUX convergent.
        $this->makeCapability('cap_profs', 'on', [[
            'path' => 'D:\\Ressources', 'ace_type' => 'deny', 'rights' => 'list_folder',
            'applies_to' => 'folder_only', 'trustee' => 'Profs', 'ensure' => 'present',
        ]]);
        $this->ruleOnLogical(['is_active' => true]);

        $items = $this->machineFsAcl($this->compiler());
        self::assertCount(2, $items, 'identités distinctes = cumul (piège #2)');
        $trustees = array_map(fn ($i): string => $i['payload']['trustee'], $items);
        sort($trustees);
        self::assertSame(['Classe_3A', 'Profs'], $trustees);
    }

    // ── (d) Sans règle : byte-identité avec le provider capacités nu ──────

    #[Test]
    public function without_rules_compiles_identically_to_the_bare_capability_provider(): void
    {
        $this->makeCapability('cap_shared', 'on', self::SHARED_ACE);
        // AUCUNE règle en base.

        self::assertEquals(
            $this->machineFsAcl($this->bareCompiler()),
            $this->machineFsAcl($this->compiler()),
            'sans règle, la composition est byte-identique (hash inclus)',
        );
    }
}
