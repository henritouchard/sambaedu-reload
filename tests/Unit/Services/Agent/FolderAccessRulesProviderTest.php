<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\FolderAccessRule;
use App\Models\FolderAccessRuleAssignable;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Providers\FolderAccessRulesStateProvider;
use App\Services\Agent\Providers\FsAclCapabilityProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 36.4 (AC3) — provider composite `fs_acl` : bi-alimentation, exclusiveKey
 * DÉLÉGUÉE, émission des règles (maille/depth/trustee D9/absent/sourceId),
 * machine-only, PG-pur, byte-identité sans règles.
 */
class FolderAccessRulesProviderTest extends TestCase
{
    use RefreshDatabase;

    private \App\Models\Workstation $ws;

    private WorkstationGroup $logical;

    private WorkstationGroup $physical;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();

        DB::table('capability_assignments')->delete();
        DB::table('capability_projections')->delete();
        DB::table('capabilities')->delete();

        $this->ws = \App\Models\Workstation::factory()->create();
        $this->logical = WorkstationGroup::factory()->logical()->create();
        $this->physical = WorkstationGroup::factory()->create(['is_physical' => true]);
        $this->ws->groups()->attach($this->logical->id);
        $this->ws->groups()->attach($this->physical->id);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    private function provider(): FolderAccessRulesStateProvider
    {
        return new FolderAccessRulesStateProvider(new FsAclCapabilityProvider());
    }

    private function ctx(): TargetContext
    {
        return TargetContext::for($this->ws, null);
    }

    private function rule(WorkstationGroup $parc, array $overrides = [], ?UserGroup $group = null): FolderAccessRule
    {
        $group ??= UserGroup::factory()->create(['name' => '3A', 'ad_dn' => 'CN=Classe_3A,OU=Groups']);
        $rule = FolderAccessRule::factory()->create(array_merge([
            'user_group_id' => $group->id,
            'path' => 'D:\\Ressources',
            'ace_type' => 'deny',
            'rights' => 'list_folder',
            'applies_to' => 'folder_only',
        ], $overrides));
        FolderAccessRuleAssignable::create([
            'folder_access_rule_id' => $rule->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $parc->id,
        ]);

        return $rule;
    }

    // ── Type / sémantique / portée / exclusiveKey DÉLÉGUÉS ────────────────

    #[Test]
    public function delegates_type_semantics_scope_and_exclusive_key(): void
    {
        $p = $this->provider();
        $cap = new FsAclCapabilityProvider();

        self::assertSame('fs_acl', $p->type());
        self::assertSame(ResourceSemantics::Exclusive, $p->semantics());
        self::assertSame(StateScope::Machine, $p->scope());

        $payload = ['path' => 'D:\\Ressources', 'trustee' => 'Classe_3A', 'ace_type' => 'deny'];
        self::assertSame($cap->exclusiveKey($payload), $p->exclusiveKey($payload), 'exclusiveKey DÉLÉGUÉE');
    }

    // ── Émission d'une règle active à la maille du parc (logique) ─────────

    #[Test]
    public function emits_one_present_item_for_an_active_rule_on_a_logical_parc(): void
    {
        $this->rule($this->logical);

        $items = $this->provider()->itemsFor($this->ctx());
        self::assertCount(1, $items);

        $c = $items->first();
        self::assertInstanceOf(StateCandidate::class, $c);
        self::assertSame(StateMaille::LogicalGroup, $c->maille);
        self::assertNull($c->depth, 'logique sans profondeur (piège #8)');
        self::assertSame(['path', 'trustee', 'ace_type', 'rights', 'applies_to', 'ensure'], array_keys($c->payload));
        self::assertSame('Classe_3A', $c->payload['trustee'], 'trustee dérivé du CN de ad_dn (D9)');
        self::assertSame('present', $c->payload['ensure']);
    }

    // ── Off réel : règle inactive → item ABSENT (D3) ──────────────────────

    #[Test]
    public function inactive_rule_still_emits_an_absent_item(): void
    {
        $this->rule($this->logical, ['is_active' => false]);

        $items = $this->provider()->itemsFor($this->ctx());
        self::assertCount(1, $items, 'désactiver n\'éteint PAS l\'émission');
        self::assertSame('absent', $items->first()->payload['ensure'], 'off réel (D3)');
    }

    // ── Maille physique AVEC profondeur (piège #8) ────────────────────────

    #[Test]
    public function physical_parc_carries_the_context_depth(): void
    {
        $this->rule($this->physical);

        $c = $this->provider()->itemsFor($this->ctx())->first();
        self::assertSame(StateMaille::PhysicalGroup, $c->maille);
        self::assertSame(0, $c->depth, 'salle directe = profondeur 0');
    }

    // ── Trustee fallback verbatim quand ad_dn absent (D9) ─────────────────

    #[Test]
    public function trustee_falls_back_to_the_bare_name_without_ad_dn(): void
    {
        $group = UserGroup::factory()->create(['name' => 'Profs', 'ad_dn' => null]);
        $this->rule($this->logical, [], $group);

        $c = $this->provider()->itemsFor($this->ctx())->first();
        self::assertSame('Profs', $c->payload['trustee']);
    }

    // ── sourceId injectif (offset, piège #6) ──────────────────────────────

    #[Test]
    public function rule_source_id_is_offset_by_the_pivot_id(): void
    {
        $this->rule($this->logical);
        $pivotId = (int) FolderAccessRuleAssignable::query()->value('id');

        $c = $this->provider()->itemsFor($this->ctx())->first();
        self::assertSame(FolderAccessRulesStateProvider::RULE_SOURCE_ID_OFFSET + $pivotId, $c->sourceId);
    }

    // ── Machine-only : les règles SORTENT (piège #7 — inverse de Drives) ──

    #[Test]
    public function rules_are_emitted_on_a_machine_only_compile(): void
    {
        $this->rule($this->logical);
        // ctx() est DÉJÀ machine-only (user null) — l'item doit sortir.
        self::assertCount(1, $this->provider()->itemsFor(TargetContext::for($this->ws, null)));
    }

    #[Test]
    public function a_rule_on_an_unrelated_parc_is_not_emitted(): void
    {
        $other = WorkstationGroup::factory()->logical()->create();
        $this->rule($other); // poste PAS membre de $other

        self::assertCount(0, $this->provider()->itemsFor($this->ctx()));
    }

    // ── Byte-identité sans règles (piège #5) ──────────────────────────────

    #[Test]
    public function without_any_rule_the_output_equals_the_bare_capability_provider(): void
    {
        // Une capacité fs_acl de preuve (aucune règle en base).
        $cap = Capability::factory()->create(['key' => 'proof', 'default_value' => 'on']);
        CapabilityProjection::factory()->for($cap)->create([
            'mechanism' => CapabilityProjection::MECHANISM_FS_ACL,
            'spec' => ['aces' => [[
                'path' => 'C:\\Program Files', 'ace_type' => 'deny', 'rights' => 'list_folder',
                'applies_to' => 'folder_only', 'trustee' => 'Domain Users', 'ensure' => 'present',
            ]]],
        ]);

        $bare = (new FsAclCapabilityProvider())->itemsFor($this->ctx());
        $composite = $this->provider()->itemsFor($this->ctx());

        self::assertEquals(
            $bare->map(fn (StateCandidate $c): array => [$c->maille, $c->payload, $c->sourceId, $c->depth])->all(),
            $composite->map(fn (StateCandidate $c): array => [$c->maille, $c->payload, $c->sourceId, $c->depth])->all(),
            'sans règle : byte-identique aux candidats capacités (piège #5)',
        );
    }

    // ── Postgres pur (NFR7) ───────────────────────────────────────────────

    #[Test]
    public function provider_source_has_no_ad_apcu_dependency(): void
    {
        $src = (string) file_get_contents(app_path('Services/Agent/Providers/FolderAccessRulesStateProvider.php'));
        $codeOnly = (string) preg_replace('#/\*.*?\*/#s', '', $src);
        $codeOnly = (string) preg_replace('#//.*#', '', $codeOnly);

        foreach (['LdapRecord', 'samba-tool', 'Cache::', 'apcu_'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $codeOnly, "NFR7 : '{$forbidden}' interdit");
        }
    }
}
