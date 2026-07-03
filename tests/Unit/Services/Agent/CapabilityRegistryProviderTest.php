<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Providers\RegistryMachineCapabilityProvider;
use App\Services\Agent\Providers\RegistryUserCapabilityProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 27.12 — Tests Unit des providers `registry` CAPABILITY-FIRST.
 *
 * Le provider EXPANSE une capacité (intention) → items de contrat CONCRETS
 * `{hive, path, name, type, value}` via l'interpréteur de `spec` (D5 : map/
 * littéral, coercition par type). Broadcast (défaut diffusé) + override de VALEUR
 * de capacité par maille (D4). Candidats BRUTS (D2). Lecture Postgres pure (NFR7).
 * INVARIANT CENTRAL : jamais d'id/key de capacité/projection au payload.
 */
class CapabilityRegistryProviderTest extends TestCase
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

        // Le lot iso est seedé par migration (AC5). On repart d'un catalogue VIDE
        // pour contrôler exactement ce que le provider émet.
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

    private function ctx(): TargetContext
    {
        return TargetContext::for($this->ws, null);
    }

    private function machineProvider(): RegistryMachineCapabilityProvider
    {
        return new RegistryMachineCapabilityProvider();
    }

    private function userProvider(): RegistryUserCapabilityProvider
    {
        return new RegistryUserCapabilityProvider();
    }

    /**
     * Fabrique une capacité toggle + sa projection registry.
     *
     * @param  list<array<string,mixed>>  $keys
     */
    private function makeCapability(string $key, string $default, array $keys, bool $active = true): Capability
    {
        $cap = Capability::factory()->create([
            'key' => $key,
            'default_value' => $default,
            'is_active' => $active,
        ]);
        CapabilityProjection::factory()->for($cap)->keys($keys)->create();

        return $cap;
    }

    private function setOverride(Capability $cap, WorkstationGroup $wg, ?string $value): void
    {
        DB::table('capability_assignments')->updateOrInsert(
            [
                'capability_id' => $cap->id,
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
        self::assertSame('registry', $p->type());
        self::assertSame(ResourceSemantics::Exclusive, $p->semantics());
        self::assertSame(StateScope::Machine, $p->scope());
    }

    #[Test]
    public function user_provider_declares_registry_exclusive_session(): void
    {
        $p = $this->userProvider();
        self::assertSame('registry', $p->type());
        self::assertSame(ResourceSemantics::Exclusive, $p->semantics());
        self::assertSame(StateScope::Session, $p->scope());
    }

    // ── Broadcast (défaut diffusé) + map on/off ───────────────────────────

    #[Test]
    public function active_capability_without_override_emits_a_broadcast_default(): void
    {
        $cap = $this->makeCapability('show_file_extensions', 'on', [
            ['hive' => 'HKCU', 'path' => 'Software\\X\\Advanced', 'name' => 'HideFileExt', 'type' => 'REG_DWORD', 'value' => ['on' => 0, 'off' => 1]],
        ]);

        $items = $this->userProvider()->itemsFor($this->ctx());

        self::assertCount(1, $items, 'le défaut Broadcast est émis même sans assignation');
        /** @var StateCandidate $c */
        $c = $items->first();
        self::assertSame(StateMaille::Broadcast, $c->maille);
        self::assertSame(0, $c->payload['value'], 'map on=0 résolue pour le défaut "on"');
        self::assertSame((int) $cap->id, $c->sourceId);
    }

    #[Test]
    public function override_emits_default_broadcast_plus_maille_candidate_with_override_value(): void
    {
        $cap = $this->makeCapability('show_file_extensions', 'on', [
            ['hive' => 'HKCU', 'path' => 'Software\\X\\Advanced', 'name' => 'HideFileExt', 'type' => 'REG_DWORD', 'value' => ['on' => 0, 'off' => 1]],
        ]);
        // Le parc dévie vers "off".
        $this->setOverride($cap, $this->parc, 'off');

        $items = $this->userProvider()->itemsFor($this->ctx());

        self::assertCount(2, $items, 'Broadcast (on=0) + candidat maille (off=1)');
        $byMaille = $items->keyBy(fn (StateCandidate $c): string => $c->maille->value);
        self::assertSame(0, $byMaille[StateMaille::Broadcast->value]->payload['value']);
        self::assertSame(1, $byMaille[StateMaille::LogicalGroup->value]->payload['value'], 'map off=1 résolue pour l\'override');
    }

    #[Test]
    public function null_override_falls_back_to_default_value(): void
    {
        // Override inerte (value=null) : la valeur effective replie sur default_value.
        $cap = $this->makeCapability('show_hidden_files', 'on', [
            ['hive' => 'HKCU', 'path' => 'Software\\X\\Advanced', 'name' => 'Hidden', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
        ]);
        $this->setOverride($cap, $this->parc, null);

        $items = $this->userProvider()->itemsFor($this->ctx());

        self::assertCount(2, $items, 'Broadcast + candidat maille (repli défaut)');
        foreach ($items as $c) {
            self::assertSame(1, $c->payload['value'], 'value=null → repli sur default_value (on=1)');
        }
    }

    // ── on-only : valeur effective absente de la map ⇒ clé NON émise ───────

    #[Test]
    public function on_only_map_emits_nothing_when_effective_value_is_off(): void
    {
        // Capacité on-only : la map ne porte que `on`. Override vers `off` ⇒ aucune
        // clé émise (= cesser de gérer cette clé) — D5 / piège n°5.
        $cap = $this->makeCapability('windows_copilot_off', 'on', [
            ['hive' => 'HKCU', 'path' => 'Software\\Policies\\…\\WindowsCopilot', 'name' => 'TurnOffWindowsCopilot', 'type' => 'REG_DWORD', 'value' => ['on' => 1]],
        ]);
        $this->setOverride($cap, $this->parc, 'off');

        $items = $this->userProvider()->itemsFor($this->ctx());

        // Broadcast (défaut on=1) émis ; la maille `off` n'émet RIEN.
        self::assertCount(1, $items, 'seul le Broadcast (on) est émis, l\'override off ne gère plus la clé');
        self::assertSame(StateMaille::Broadcast, $items->first()->maille);
        self::assertSame(1, $items->first()->payload['value']);
    }

    #[Test]
    public function on_only_capability_with_default_off_emits_nothing(): void
    {
        $this->makeCapability('windows_copilot_off', 'off', [
            ['hive' => 'HKCU', 'path' => 'Software\\Policies\\…\\WindowsCopilot', 'name' => 'TurnOffWindowsCopilot', 'type' => 'REG_DWORD', 'value' => ['on' => 1]],
        ]);

        $items = $this->userProvider()->itemsFor($this->ctx());

        self::assertCount(0, $items, 'défaut off + map on-only ⇒ aucune clé gérée');
    }

    // ── Littéral (toujours émis) + MULTI_SZ ───────────────────────────────

    #[Test]
    public function literal_scalar_value_is_always_emitted(): void
    {
        // value littéral (scalaire) → toujours émis quelle que soit la valeur de capacité.
        $this->makeCapability('a_literal_cap', 'whatever', [
            ['hive' => 'HKCU', 'path' => 'Software\\Lit', 'name' => 'Fixed', 'type' => 'REG_SZ', 'value' => 'C:\\fixed'],
        ]);

        $c = $this->userProvider()->itemsFor($this->ctx())->first();

        self::assertNotNull($c);
        self::assertSame('C:\\fixed', $c->payload['value']);
    }

    #[Test]
    public function multi_sz_literal_list_is_emitted_as_list(): void
    {
        // value = liste (array_is_list) → littéral MULTI_SZ, jamais interprété comme map.
        $this->makeCapability('a_multi_cap', 'on', [
            ['hive' => 'HKCU', 'path' => 'Software\\Multi', 'name' => 'Items', 'type' => 'REG_MULTI_SZ', 'value' => ['a', 'b', 'c']],
        ]);

        $c = $this->userProvider()->itemsFor($this->ctx())->first();

        self::assertNotNull($c);
        self::assertSame(['a', 'b', 'c'], $c->payload['value']);
    }

    // ── Marqueur `$ensure` (Story 35.1) : trois régimes ────────────────────

    #[Test]
    public function ensure_marker_emits_a_four_key_absent_item(): void
    {
        // (a) map `off => {$ensure: absent}` : la valeur effective `off` émet un
        // item de SUPPRESSION 4 clés au lieu de la sentinelle (rien).
        $this->makeCapability('llmnr_disabled', 'off', [
            ['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'EnableMulticast', 'type' => 'REG_DWORD', 'value' => ['on' => 0, 'off' => ['$ensure' => 'absent']]],
        ]);

        $items = $this->userProvider()->itemsFor($this->ctx());

        self::assertCount(1, $items);
        $payload = $items->first()->payload;
        self::assertSame(['hive', 'path', 'name', 'ensure'], array_keys($payload));
        self::assertSame('HKCU', $payload['hive']);
        self::assertSame('Software\\X', $payload['path']);
        self::assertSame('EnableMulticast', $payload['name']);
        self::assertSame('absent', $payload['ensure']);
    }

    #[Test]
    public function unmanaged_sentinel_still_emits_nothing_alongside_the_marker(): void
    {
        // (b) la sentinelle UNMANAGED (clé de map ABSENTE) reste disponible et
        // DISTINCTE du marqueur : `unmanaged` hors map ⇒ rien n'est émis.
        $this->makeCapability('a_cap', 'unmanaged', [
            ['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'K', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => ['$ensure' => 'absent']]],
        ]);

        self::assertCount(0, $this->userProvider()->itemsFor($this->ctx()));
    }

    #[Test]
    public function the_three_regimes_coexist_in_a_single_spec(): void
    {
        // (c) une MÊME spec porte les trois régimes selon la valeur effective :
        // `on` écrit, `off` supprime, `unmanaged` (hors map) n'émet rien.
        $keys = [
            ['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'K', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => ['$ensure' => 'absent']]],
        ];

        // Régime 1 : écrire (défaut on).
        $cap = $this->makeCapability('tri_regime', 'on', $keys);
        $write = $this->userProvider()->itemsFor($this->ctx())->first();
        self::assertSame(['hive', 'path', 'name', 'type', 'value'], array_keys($write->payload));
        self::assertSame(1, $write->payload['value']);
        self::assertArrayNotHasKey('ensure', $write->payload, 'ensure:"present" n\'est JAMAIS émis explicitement');

        // Régime 2 : supprimer (override de parc vers off).
        $this->setOverride($cap, $this->parc, 'off');
        $items = $this->userProvider()->itemsFor($this->ctx());
        self::assertCount(2, $items, 'Broadcast (écriture) + maille (suppression)');
        $absent = $items->first(fn (StateCandidate $c): bool => $c->maille === StateMaille::LogicalGroup);
        self::assertSame(['hive', 'path', 'name', 'ensure'], array_keys($absent->payload));

        // Régime 3 : ne pas gérer (override vers une valeur hors map).
        $this->setOverride($cap, $this->parc, 'unmanaged');
        $items = $this->userProvider()->itemsFor($this->ctx());
        self::assertCount(1, $items, 'seul le Broadcast subsiste, la maille ne gère plus la clé');
        self::assertSame(StateMaille::Broadcast, $items->first()->maille);
    }

    #[Test]
    public function absent_item_carries_no_value_no_type_and_no_capability_id(): void
    {
        // (d) l'item de suppression ne porte ni value/type ni fuite d'id (invariant
        // central 27.12, étendu au payload 4 clés).
        $this->makeCapability('llmnr_disabled', 'off', [
            ['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'K', 'type' => 'REG_DWORD', 'value' => ['on' => 0, 'off' => ['$ensure' => 'absent']]],
        ]);

        $payload = $this->userProvider()->itemsFor($this->ctx())->first()->payload;

        foreach (['value', 'type', 'id', 'key', 'capability_id', 'label', 'spec'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $payload);
        }
    }

    #[Test]
    public function unknown_assoc_form_in_map_emits_nothing_defensively(): void
    {
        // (e) forme assoc NON reconnue (ni marqueur, ni liste) ⇒ clé non émise —
        // défensif, jamais d'exception au render (piège n°4 : avant 35.1,
        // typedValue() la coerçait silencieusement en 0/'').
        $this->makeCapability('weird_cap', 'off', [
            ['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'A', 'type' => 'REG_DWORD', 'value' => ['off' => ['$ensure' => 'present']]],
            ['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'B', 'type' => 'REG_DWORD', 'value' => ['off' => ['unexpected' => 'shape']]],
            ['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'C', 'type' => 'REG_DWORD', 'value' => ['off' => 7]],
        ]);

        $items = $this->userProvider()->itemsFor($this->ctx());

        self::assertCount(1, $items, 'seule la clé C (valeur réelle) est émise');
        self::assertSame('C', $items->first()->payload['name']);
        self::assertSame(7, $items->first()->payload['value']);
    }

    #[Test]
    public function each_provider_filters_absent_items_by_its_hive_too(): void
    {
        // (f) le filtre de ruche s'applique aussi aux items de suppression : une
        // spec mixte HKLM+HKCU émet chaque item absent par SON provider.
        $this->makeCapability('mixed_cap', 'off', [
            ['hive' => 'HKLM', 'path' => 'SOFTWARE\\X', 'name' => 'MachineKey', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => ['$ensure' => 'absent']]],
            ['hive' => 'HKCU', 'path' => 'Software\\Y', 'name' => 'UserKey', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => ['$ensure' => 'absent']]],
        ]);

        $machineItems = $this->machineProvider()->itemsFor($this->ctx());
        $userItems = $this->userProvider()->itemsFor($this->ctx());

        self::assertCount(1, $machineItems);
        self::assertSame('MachineKey', $machineItems->first()->payload['name']);
        self::assertSame('absent', $machineItems->first()->payload['ensure']);

        self::assertCount(1, $userItems);
        self::assertSame('UserKey', $userItems->first()->payload['name']);
        self::assertSame('absent', $userItems->first()->payload['ensure']);
    }

    // ── Invariant central : payload d'ÉCRITURE concret 5 clés, jamais d'id ─

    #[Test]
    public function payload_is_concrete_five_keys_without_any_capability_id(): void
    {
        $cap = $this->makeCapability('show_file_extensions', 'on', [
            ['hive' => 'HKCU', 'path' => 'Software\\X\\Advanced', 'name' => 'HideFileExt', 'type' => 'REG_DWORD', 'value' => ['on' => 0, 'off' => 1]],
        ]);
        $this->setOverride($cap, $this->parc, 'off');

        foreach ($this->userProvider()->itemsFor($this->ctx()) as $c) {
            self::assertSame(['hive', 'path', 'name', 'type', 'value'], array_keys($c->payload));
            self::assertSame('HKCU', $c->payload['hive']);
            self::assertSame('HideFileExt', $c->payload['name']);
            self::assertSame('REG_DWORD', $c->payload['type']);
            self::assertArrayNotHasKey('id', $c->payload);
            self::assertArrayNotHasKey('key', $c->payload);
            self::assertArrayNotHasKey('capability_id', $c->payload);
            self::assertArrayNotHasKey('label', $c->payload);
            self::assertArrayNotHasKey('spec', $c->payload);
        }
    }

    // ── Filtre HKLM / HKCU par provider ───────────────────────────────────

    #[Test]
    public function each_provider_only_emits_keys_of_its_hive(): void
    {
        // Une capacité dont la projection mélange HKLM et HKCU : chaque provider
        // n'émet QUE les clés de sa ruche.
        $this->makeCapability('uac_enabled', 'on', [
            ['hive' => 'HKLM', 'path' => 'SOFTWARE\\…\\System', 'name' => 'EnableLUA', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
            ['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'UserKey', 'type' => 'REG_DWORD', 'value' => ['on' => 1]],
        ]);

        $machineItems = $this->machineProvider()->itemsFor($this->ctx());
        $userItems = $this->userProvider()->itemsFor($this->ctx());

        self::assertCount(1, $machineItems);
        self::assertSame('HKLM', $machineItems->first()->payload['hive']);
        self::assertSame('EnableLUA', $machineItems->first()->payload['name']);

        self::assertCount(1, $userItems);
        self::assertSame('HKCU', $userItems->first()->payload['hive']);
        self::assertSame('UserKey', $userItems->first()->payload['name']);
    }

    #[Test]
    public function inactive_capability_emits_nothing(): void
    {
        $cap = $this->makeCapability('inactive_cap', 'on', [
            ['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'Foo', 'type' => 'REG_DWORD', 'value' => ['on' => 1]],
        ], active: false);
        $this->setOverride($cap, $this->parc, 'on');

        self::assertCount(0, $this->userProvider()->itemsFor($this->ctx()));
    }

    // ── Bundle = une capacité → N candidats ───────────────────────────────

    #[Test]
    public function bundle_capability_emits_one_candidate_per_emitted_key(): void
    {
        // Bundle de 3 clés HKLM on-only → 3 candidats Broadcast, tous au même sourceId.
        $cap = $this->makeCapability('windows_updates_managed', 'on', [
            ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Policies\\…\\WindowsUpdate\\AU', 'name' => 'NoAutoUpdate', 'type' => 'REG_DWORD', 'value' => ['on' => 0]],
            ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Policies\\…\\WindowsUpdate\\AU', 'name' => 'AUOptions', 'type' => 'REG_DWORD', 'value' => ['on' => 4]],
            ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Policies\\…\\WindowsUpdate', 'name' => 'ElevateNonAdmins', 'type' => 'REG_DWORD', 'value' => ['on' => 0]],
        ]);

        $items = $this->machineProvider()->itemsFor($this->ctx());

        self::assertCount(3, $items, 'une capacité bundle de 3 clés → 3 candidats');
        foreach ($items as $c) {
            self::assertSame(StateMaille::Broadcast, $c->maille);
            self::assertSame((int) $cap->id, $c->sourceId, 'tous au même sourceId (= capability.id)');
        }
    }

    // ── Ciblage multi-maille (poste + groupe user) ────────────────────────

    #[Test]
    public function targets_workstation_and_user_group_mailles_too(): void
    {
        $user = User::factory()->create();
        $group = UserGroup::factory()->create();
        $user->groups()->attach($group->id);

        $capWs = $this->makeCapability('cap_ws', 'on', [
            ['hive' => 'HKCU', 'path' => 'Software\\Ws', 'name' => 'WsKey', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 9]],
        ]);
        $capUg = $this->makeCapability('cap_ug', 'on', [
            ['hive' => 'HKCU', 'path' => 'Software\\Ug', 'name' => 'UgKey', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 7]],
        ]);

        DB::table('capability_assignments')->insert([
            'capability_id' => $capWs->id,
            'assignable_type' => Workstation::class,
            'assignable_id' => $this->ws->id,
            'value' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('capability_assignments')->insert([
            'capability_id' => $capUg->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $group->id,
            'value' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $items = $this->userProvider()->itemsFor(TargetContext::for($this->ws, $user));

        $wsOverride = $items->first(fn (StateCandidate $c): bool => $c->payload['name'] === 'WsKey' && $c->maille === StateMaille::Workstation);
        $ugOverride = $items->first(fn (StateCandidate $c): bool => $c->payload['name'] === 'UgKey' && $c->maille === StateMaille::UserGroup);

        self::assertNotNull($wsOverride);
        self::assertNotNull($ugOverride);
        self::assertSame(9, $wsOverride->payload['value']);
        self::assertSame(7, $ugOverride->payload['value']);
    }

    // ── exclusiveKey : identité insensible à la casse ─────────────────────

    #[Test]
    public function exclusive_key_is_case_insensitive_identity(): void
    {
        $p = $this->userProvider();
        $a = $p->exclusiveKey(['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'Foo']);
        $b = $p->exclusiveKey(['hive' => 'hkcu', 'path' => 'software\\x', 'name' => 'foo']);

        self::assertSame($a, $b);
    }

    // ── NFR7 — lecture seule Postgres, zéro AD ────────────────────────────

    #[Test]
    public function provider_source_has_no_ad_apcu_samba_dependency(): void
    {
        foreach ([
            app_path('Services/Agent/Providers/AbstractCapabilityStateProvider.php'),
            app_path('Services/Agent/Providers/RegistryMachineCapabilityProvider.php'),
            app_path('Services/Agent/Providers/RegistryUserCapabilityProvider.php'),
        ] as $file) {
            $src = file_get_contents($file);
            $codeOnly = preg_replace('#/\*.*?\*/#s', '', $src);
            $codeOnly = preg_replace('#//.*#', '', (string) $codeOnly);

            foreach (['LdapRecord', 'samba-tool', 'Cache::', 'apcu_'] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    (string) $codeOnly,
                    "NFR7 : '{$forbidden}' interdit dans ".basename($file),
                );
            }
        }
    }
}
