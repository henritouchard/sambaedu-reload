<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\RegistrySetting;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Providers\RegistryMachineStateProvider;
use App\Services\Agent\Providers\RegistryUserStateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `Registry{Machine,User}StateProvider` — Story 27.3 (AC2, AC3).
 *
 * Catalogue `registry_settings` × pivot `registry_setting_assignables` →
 * candidats CONCRETS `{hive, path, name, type, value}` par maille. DEUX
 * providers (HKLM/machine + HKCU/session), UNE table, filtre par hive. Lecture
 * Postgres pure (NFR7). Invariant central : JAMAIS d'id de catalogue au payload.
 */
class RegistryStateProviderTest extends TestCase
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

    private function ctx(): TargetContext
    {
        return TargetContext::for($this->ws, null);
    }

    private function machineProvider(): RegistryMachineStateProvider
    {
        return new RegistryMachineStateProvider();
    }

    private function userProvider(): RegistryUserStateProvider
    {
        return new RegistryUserStateProvider();
    }

    // ── Type / sémantique / portée ────────────────────────────────────────

    #[Test]
    public function machine_provider_declares_registry_exclusive_machine(): void
    {
        $p = $this->machineProvider();
        self::assertSame(RegistrySetting::TYPE_REGISTRY, $p->type());
        self::assertSame(ResourceSemantics::Exclusive, $p->semantics());
        self::assertSame(StateScope::Machine, $p->scope());
    }

    #[Test]
    public function user_provider_declares_registry_exclusive_session(): void
    {
        $p = $this->userProvider();
        self::assertSame(RegistrySetting::TYPE_REGISTRY, $p->type());
        self::assertSame(ResourceSemantics::Exclusive, $p->semantics());
        self::assertSame(StateScope::Session, $p->scope());
    }

    // ── AC3 — catalogue → item CONCRET par maille ─────────────────────────

    #[Test]
    public function user_provider_emits_concrete_payload_without_any_catalog_id(): void
    {
        $setting = RegistrySetting::factory()->user()->create([
            'hive' => 'HKCU',
            'path' => 'Software\\Microsoft\\Windows\\CurrentVersion\\Explorer\\Advanced',
            'name' => 'HideFileExt',
            'type' => 'REG_DWORD',
            'value' => '0',
        ]);
        $setting->workstationGroups()->attach($this->parc->id);

        $items = $this->userProvider()->itemsFor($this->ctx());

        self::assertCount(1, $items);
        /** @var StateCandidate $c */
        $c = $items->first();

        // Payload CONCRET, EXACTEMENT 5 clés (invariant central).
        self::assertSame(
            ['hive', 'path', 'name', 'type', 'value'],
            array_keys($c->payload),
        );
        self::assertSame('HKCU', $c->payload['hive']);
        self::assertSame('HideFileExt', $c->payload['name']);
        self::assertSame('REG_DWORD', $c->payload['type']);
        self::assertSame(0, $c->payload['value'], 'REG_DWORD → entier (zéro float)');

        // INVARIANT CENTRAL : aucun id/key de catalogue ne fuite au payload.
        self::assertArrayNotHasKey('id', $c->payload);
        self::assertArrayNotHasKey('key', $c->payload);
        self::assertArrayNotHasKey('setting_id', $c->payload);
        self::assertArrayNotHasKey('label', $c->payload);
    }

    #[Test]
    public function machine_provider_only_reads_hklm_settings(): void
    {
        // Un HKLM (machine) + un HKCU (user) assignés au MÊME parc.
        $hklm = RegistrySetting::factory()->machine()->create(['hive' => 'HKLM', 'name' => 'EnableLUA']);
        $hkcu = RegistrySetting::factory()->user()->create(['hive' => 'HKCU', 'name' => 'Hidden']);
        $hklm->workstationGroups()->attach($this->parc->id);
        $hkcu->workstationGroups()->attach($this->parc->id);

        $machineItems = $this->machineProvider()->itemsFor($this->ctx());
        $userItems = $this->userProvider()->itemsFor($this->ctx());

        self::assertCount(1, $machineItems);
        self::assertSame('HKLM', $machineItems->first()->payload['hive']);

        self::assertCount(1, $userItems);
        self::assertSame('HKCU', $userItems->first()->payload['hive']);
    }

    #[Test]
    public function unassigned_setting_emits_no_item(): void
    {
        // Réglage actif mais NON assigné à aucune maille du poste.
        RegistrySetting::factory()->user()->create();

        $items = $this->userProvider()->itemsFor($this->ctx());

        self::assertCount(0, $items, 'un réglage non assigné = type/clé absent (cesser de gérer)');
    }

    #[Test]
    public function inactive_setting_is_ignored(): void
    {
        $setting = RegistrySetting::factory()->user()->create(['is_active' => false]);
        $setting->workstationGroups()->attach($this->parc->id);

        self::assertCount(0, $this->userProvider()->itemsFor($this->ctx()));
    }

    #[Test]
    public function candidate_is_tagged_with_logical_group_maille(): void
    {
        $setting = RegistrySetting::factory()->user()->create();
        $setting->workstationGroups()->attach($this->parc->id); // parc = logique

        $c = $this->userProvider()->itemsFor($this->ctx())->first();

        self::assertSame(StateMaille::LogicalGroup, $c->maille);
    }

    #[Test]
    public function multi_sz_value_is_emitted_as_list(): void
    {
        $setting = RegistrySetting::factory()->user()->create([
            'type' => 'REG_MULTI_SZ',
            'value' => json_encode(['a', 'b']),
        ]);
        $setting->workstationGroups()->attach($this->parc->id);

        $c = $this->userProvider()->itemsFor($this->ctx())->first();

        self::assertSame(['a', 'b'], $c->payload['value']);
    }

    #[Test]
    public function sz_value_is_emitted_as_string(): void
    {
        $setting = RegistrySetting::factory()->user()->create([
            'type' => 'REG_SZ',
            'value' => 'C:\\Path\\to',
        ]);
        $setting->workstationGroups()->attach($this->parc->id);

        $c = $this->userProvider()->itemsFor($this->ctx())->first();

        self::assertSame('C:\\Path\\to', $c->payload['value']);
    }

    // ── NFR7 — lecture seule Postgres, zéro AD ────────────────────────────

    #[Test]
    public function provider_source_has_no_ad_apcu_samba_dependency(): void
    {
        foreach ([
            app_path('Services/Agent/Providers/AbstractRegistryStateProvider.php'),
            app_path('Services/Agent/Providers/RegistryMachineStateProvider.php'),
            app_path('Services/Agent/Providers/RegistryUserStateProvider.php'),
        ] as $file) {
            $src = file_get_contents($file);
            // On retire les commentaires/docblocks (les occurrences en
            // commentaire sont tolérées — NFR7 vise le CODE).
            $codeOnly = preg_replace('#/\*.*?\*/#s', '', $src);
            $codeOnly = preg_replace('#//.*#', '', $codeOnly);

            foreach (['LdapRecord', 'samba-tool', 'Cache::', 'apcu_'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $codeOnly,
                    "NFR7 : '{$forbidden}' interdit dans ".basename($file),
                );
            }
        }
    }

    // ── exclusiveKey : identité {hive,path,name} insensible à la casse ────

    #[Test]
    public function exclusive_key_is_case_insensitive_identity(): void
    {
        $p = $this->userProvider();
        $a = $p->exclusiveKey(['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'Foo']);
        $b = $p->exclusiveKey(['hive' => 'hkcu', 'path' => 'software\\x', 'name' => 'foo']);

        self::assertSame($a, $b, 'la clé d\'identité est insensible à la casse (Windows)');
    }

    #[Test]
    public function exclusive_key_differs_for_different_keys(): void
    {
        $p = $this->userProvider();
        $a = $p->exclusiveKey(['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'Foo']);
        $b = $p->exclusiveKey(['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'Bar']);

        self::assertNotSame($a, $b);
    }

    // ── Ciblage multi-maille : poste + groupe user ────────────────────────

    #[Test]
    public function targets_workstation_and_user_group_mailles_too(): void
    {
        $user = User::factory()->create();
        $group = UserGroup::factory()->create();
        $user->groups()->attach($group->id);

        $byWorkstation = RegistrySetting::factory()->user()->create(['name' => 'WsKey']);
        $byUserGroup = RegistrySetting::factory()->user()->create(['name' => 'UgKey']);
        $byWorkstation->workstations()->attach($this->ws->id);
        $byUserGroup->userGroups()->attach($group->id);

        $items = $this->userProvider()->itemsFor(TargetContext::for($this->ws, $user));
        $mailles = $items->mapWithKeys(fn (StateCandidate $c): array => [$c->payload['name'] => $c->maille])->all();

        self::assertSame(StateMaille::Workstation, $mailles['WsKey']);
        self::assertSame(StateMaille::UserGroup, $mailles['UgKey']);
    }
}
