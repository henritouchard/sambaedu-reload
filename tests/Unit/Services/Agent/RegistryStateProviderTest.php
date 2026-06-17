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
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `Registry{Machine,User}StateProvider` — Story 27.3 + 27.3ter.
 *
 * MODÈLE 27.3ter : chaque réglage actif de la ruche émet un candidat **Broadcast**
 * (valeur par défaut, diffusée à TOUTE la flotte), PLUS un candidat par maille par
 * assignation applicable (override `pivot.value` avec repli sur le défaut si null).
 * Candidats BRUTS (D2). Lecture Postgres pure (NFR7). Invariant central : JAMAIS
 * d'id de catalogue au payload.
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

        // Le catalogue est seedé par migration (27.3 + 27.3ter). En 27.3ter CHAQUE
        // réglage actif est diffusé (Broadcast) → on repart d'un catalogue VIDE
        // pour contrôler exactement ce que le provider émet.
        DB::table('registry_setting_assignables')->delete();
        DB::table('registry_settings')->delete();

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

    /**
     * Pose un override de valeur sur le pivot (WorkstationGroup) — colonne `value`.
     */
    private function setOverride(RegistrySetting $setting, WorkstationGroup $wg, ?string $value): void
    {
        DB::table('registry_setting_assignables')->updateOrInsert(
            [
                'registry_setting_id' => $setting->id,
                'assignable_type' => WorkstationGroup::class,
                'assignable_id' => $wg->id,
            ],
            ['value' => $value, 'created_at' => now(), 'updated_at' => now()],
        );
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

    // ── 27.3ter — DÉFAUT diffusé (Broadcast) ──────────────────────────────

    #[Test]
    public function active_setting_without_override_emits_a_broadcast_default(): void
    {
        // NOUVEAU SENS (27.3ter) : un réglage actif SANS override émet quand même
        // un candidat Broadcast au défaut catalogue (diffusé à toute la flotte).
        $setting = RegistrySetting::factory()->user()->create([
            'name' => 'HideFileExt',
            'type' => 'REG_DWORD',
            'value' => '0',
        ]);
        // AUCUN pivot, AUCUN override.

        $items = $this->userProvider()->itemsFor($this->ctx());

        self::assertCount(1, $items, 'le défaut Broadcast est émis même sans assignation');
        /** @var StateCandidate $c */
        $c = $items->first();
        self::assertSame(StateMaille::Broadcast, $c->maille);
        self::assertSame(0, $c->payload['value'], 'le défaut catalogue est porté');
        self::assertSame((int) $setting->id, $c->sourceId);
    }

    #[Test]
    public function inactive_setting_emits_nothing(): void
    {
        // Un réglage INACTIF n'est ni diffusé ni surchargeable.
        $setting = RegistrySetting::factory()->user()->create(['is_active' => false]);
        $this->setOverride($setting, $this->parc, '1');

        self::assertCount(0, $this->userProvider()->itemsFor($this->ctx()));
    }

    // ── 27.3ter — OVERRIDE par maille ─────────────────────────────────────

    #[Test]
    public function override_emits_default_broadcast_plus_maille_candidate_with_override_value(): void
    {
        $setting = RegistrySetting::factory()->user()->create([
            'name' => 'HideFileExt',
            'type' => 'REG_DWORD',
            'value' => '0',
        ]);
        // Le parc dévie vers 1.
        $this->setOverride($setting, $this->parc, '1');

        $items = $this->userProvider()->itemsFor($this->ctx());

        // Un Broadcast (défaut 0) + un candidat maille logique (override 1).
        self::assertCount(2, $items);

        $byMaille = $items->keyBy(fn (StateCandidate $c): string => $c->maille->value);
        self::assertSame(0, $byMaille[StateMaille::Broadcast->value]->payload['value']);
        self::assertSame(1, $byMaille[StateMaille::LogicalGroup->value]->payload['value'], 'override de parc porté');
    }

    #[Test]
    public function null_override_falls_back_to_catalog_default(): void
    {
        // Override inerte (value=null) : le candidat maille replie sur le défaut
        // catalogue — couvre les assignations 27.3 résiduelles (AC1).
        $setting = RegistrySetting::factory()->user()->create([
            'name' => 'Hidden',
            'type' => 'REG_DWORD',
            'value' => '1',
        ]);
        $this->setOverride($setting, $this->parc, null);

        $items = $this->userProvider()->itemsFor($this->ctx());

        self::assertCount(2, $items, 'Broadcast + candidat maille (repli défaut)');
        foreach ($items as $c) {
            self::assertSame(1, $c->payload['value'], 'value=null → repli sur le défaut catalogue');
        }
    }

    // ── Invariant central — payload CONCRET, jamais d'id de catalogue ──────

    #[Test]
    public function payload_is_concrete_five_keys_without_any_catalog_id(): void
    {
        $setting = RegistrySetting::factory()->user()->create([
            'hive' => 'HKCU',
            'path' => 'Software\\Microsoft\\Windows\\CurrentVersion\\Explorer\\Advanced',
            'name' => 'HideFileExt',
            'type' => 'REG_DWORD',
            'value' => '0',
        ]);
        $this->setOverride($setting, $this->parc, '1');

        foreach ($this->userProvider()->itemsFor($this->ctx()) as $c) {
            // EXACTEMENT 5 clés (invariant central), aucune fuite de catalogue.
            self::assertSame(['hive', 'path', 'name', 'type', 'value'], array_keys($c->payload));
            self::assertSame('HKCU', $c->payload['hive']);
            self::assertSame('HideFileExt', $c->payload['name']);
            self::assertSame('REG_DWORD', $c->payload['type']);
            self::assertArrayNotHasKey('id', $c->payload);
            self::assertArrayNotHasKey('key', $c->payload);
            self::assertArrayNotHasKey('setting_id', $c->payload);
            self::assertArrayNotHasKey('label', $c->payload);
        }
    }

    #[Test]
    public function machine_provider_only_reads_hklm_settings(): void
    {
        $hklm = RegistrySetting::factory()->machine()->create(['hive' => 'HKLM', 'name' => 'EnableLUA']);
        $hkcu = RegistrySetting::factory()->user()->create(['hive' => 'HKCU', 'name' => 'Hidden']);

        $machineItems = $this->machineProvider()->itemsFor($this->ctx());
        $userItems = $this->userProvider()->itemsFor($this->ctx());

        // Chacun n'émet QUE le Broadcast de sa ruche (aucun override posé).
        self::assertCount(1, $machineItems);
        self::assertSame('HKLM', $machineItems->first()->payload['hive']);

        self::assertCount(1, $userItems);
        self::assertSame('HKCU', $userItems->first()->payload['hive']);
    }

    #[Test]
    public function override_candidate_is_tagged_with_logical_group_maille(): void
    {
        $setting = RegistrySetting::factory()->user()->create(['value' => '0']);
        $this->setOverride($setting, $this->parc, '1'); // parc = logique

        $logical = $this->userProvider()->itemsFor($this->ctx())
            ->first(fn (StateCandidate $c): bool => $c->maille === StateMaille::LogicalGroup);

        self::assertNotNull($logical);
        self::assertSame(1, $logical->payload['value']);
    }

    #[Test]
    public function multi_sz_default_is_emitted_as_list(): void
    {
        RegistrySetting::factory()->user()->create([
            'type' => 'REG_MULTI_SZ',
            'value' => json_encode(['a', 'b']),
        ]);

        $c = $this->userProvider()->itemsFor($this->ctx())->first();

        self::assertSame(['a', 'b'], $c->payload['value']);
    }

    #[Test]
    public function multi_sz_override_is_emitted_as_list(): void
    {
        $setting = RegistrySetting::factory()->user()->create([
            'type' => 'REG_MULTI_SZ',
            'value' => json_encode(['a']),
        ]);
        $this->setOverride($setting, $this->parc, json_encode(['x', 'y']));

        $override = $this->userProvider()->itemsFor($this->ctx())
            ->first(fn (StateCandidate $c): bool => $c->maille === StateMaille::LogicalGroup);

        self::assertSame(['x', 'y'], $override->payload['value']);
    }

    #[Test]
    public function sz_default_is_emitted_as_string(): void
    {
        RegistrySetting::factory()->user()->create([
            'type' => 'REG_SZ',
            'value' => 'C:\\Path\\to',
        ]);

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

    // ── Ciblage multi-maille : poste + groupe user (avec override) ─────────

    #[Test]
    public function targets_workstation_and_user_group_mailles_too(): void
    {
        $user = User::factory()->create();
        $group = UserGroup::factory()->create();
        $user->groups()->attach($group->id);

        $byWorkstation = RegistrySetting::factory()->user()->create(['name' => 'WsKey', 'value' => '0']);
        $byUserGroup = RegistrySetting::factory()->user()->create(['name' => 'UgKey', 'value' => '0']);

        DB::table('registry_setting_assignables')->insert([
            'registry_setting_id' => $byWorkstation->id,
            'assignable_type' => Workstation::class,
            'assignable_id' => $this->ws->id,
            'value' => '5',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('registry_setting_assignables')->insert([
            'registry_setting_id' => $byUserGroup->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $group->id,
            'value' => '7',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $items = $this->userProvider()->itemsFor(TargetContext::for($this->ws, $user));

        // Pour chaque clé : un Broadcast + un candidat maille spécifique.
        $wsOverride = $items->first(fn (StateCandidate $c): bool => $c->payload['name'] === 'WsKey' && $c->maille === StateMaille::Workstation);
        $ugOverride = $items->first(fn (StateCandidate $c): bool => $c->payload['name'] === 'UgKey' && $c->maille === StateMaille::UserGroup);

        self::assertNotNull($wsOverride);
        self::assertNotNull($ugOverride);
        self::assertSame(5, $wsOverride->payload['value']);
        self::assertSame(7, $ugOverride->payload['value']);
    }
}
