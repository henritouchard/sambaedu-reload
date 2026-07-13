<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Providers\RegistryListMachineCapabilityProvider;
use App\Services\Agent\Providers\RegistryListUserCapabilityProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 35.2 — Tests Unit des providers `registry_list` (contrat §7.6).
 *
 * Le provider EXPANSE une capacité → conteneurs CONCRETS 4 clés
 * `{hive, path, entry_type, values}` via l'interpréteur de `spec` list
 * (map/littéral → LISTE ordonnée de chaînes ; liste vide = purge ; UNMANAGED =
 * rien ; `$ensure`/assoc = non émis défensif). INVARIANT CENTRAL : jamais
 * d'id/key de capacité au payload, jamais de `name`, strings only.
 */
class CapabilityRegistryListProviderTest extends TestCase
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

        // Catalogue VIDE : on contrôle exactement ce que le provider émet.
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

    private function machineProvider(): RegistryListMachineCapabilityProvider
    {
        return new RegistryListMachineCapabilityProvider;
    }

    private function userProvider(): RegistryListUserCapabilityProvider
    {
        return new RegistryListUserCapabilityProvider;
    }

    /**
     * Fabrique une capacité + sa projection registry_list.
     *
     * @param  list<array<string,mixed>>  $keys
     */
    private function makeListCapability(string $key, string $default, array $keys): Capability
    {
        $cap = Capability::factory()->create([
            'key' => $key,
            'default_value' => $default,
        ]);
        CapabilityProjection::factory()->for($cap)->keys($keys)->create([
            'mechanism' => CapabilityProjection::MECHANISM_REGISTRY_LIST,
        ]);

        return $cap;
    }

    // ── Type / sémantique / portée (mêmes casiers que registry) ────────────

    #[Test]
    public function providers_declare_registry_list_exclusive_with_registry_scopes(): void
    {
        $machine = $this->machineProvider();
        self::assertSame('registry_list', $machine->type());
        self::assertSame(ResourceSemantics::Exclusive, $machine->semantics());
        self::assertSame(StateScope::Machine, $machine->scope());

        $user = $this->userProvider();
        self::assertSame('registry_list', $user->type());
        self::assertSame(ResourceSemantics::Exclusive, $user->semantics());
        self::assertSame(StateScope::Session, $user->scope());
    }

    // ── (a) map on → liste émise, payload EXACTEMENT 4 clés ────────────────

    #[Test]
    public function map_resolved_list_is_emitted_as_a_four_key_container(): void
    {
        $cap = $this->makeListCapability('blocked_executables', 'on', [
            ['hive' => 'HKCU', 'path' => 'Software\\P\\Explorer\\DisallowRun', 'entry_type' => 'REG_SZ', 'values' => ['on' => ['cmd.exe', 'mstsc.exe'], 'off' => []]],
        ]);

        $items = $this->userProvider()->itemsFor($this->ctx());

        self::assertCount(1, $items);
        /** @var StateCandidate $c */
        $c = $items->first();
        self::assertSame(StateMaille::Broadcast, $c->maille);
        self::assertSame((int) $cap->id, $c->sourceId);
        self::assertSame(['hive', 'path', 'entry_type', 'values'], array_keys($c->payload));
        self::assertSame('HKCU', $c->payload['hive']);
        self::assertSame('Software\\P\\Explorer\\DisallowRun', $c->payload['path']);
        self::assertSame('REG_SZ', $c->payload['entry_type']);
        self::assertSame(['cmd.exe', 'mstsc.exe'], $c->payload['values'], 'ordre PRÉSERVÉ (jamais trié)');
    }

    // ── (b) `'off' => []` émet values: [] (purge, vraie valeur) ────────────

    #[Test]
    public function off_empty_list_emits_an_empty_values_container(): void
    {
        $this->makeListCapability('blocked_executables', 'off', [
            ['hive' => 'HKCU', 'path' => 'Software\\P\\Explorer\\DisallowRun', 'entry_type' => 'REG_SZ', 'values' => ['on' => ['cmd.exe'], 'off' => []]],
        ]);

        $items = $this->userProvider()->itemsFor($this->ctx());

        self::assertCount(1, $items, 'liste vide = VRAIE valeur (purge), pas la sentinelle');
        self::assertSame([], $items->first()->payload['values']);
        self::assertSame(['hive', 'path', 'entry_type', 'values'], array_keys($items->first()->payload));
    }

    // ── (c) UNMANAGED (clé de map absente) n'émet rien ─────────────────────

    #[Test]
    public function unmanaged_sentinel_emits_nothing(): void
    {
        $this->makeListCapability('pix_extension_forced', 'unmanaged', [
            ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Policies\\Google\\Chrome\\ExtensionInstallForcelist', 'entry_type' => 'REG_SZ', 'values' => ['on' => ['abc']]],
        ]);

        self::assertCount(0, $this->machineProvider()->itemsFor($this->ctx()));
    }

    // ── (d) littéral liste = toujours émis ─────────────────────────────────

    #[Test]
    public function literal_list_is_always_emitted(): void
    {
        $this->makeListCapability('literal_list_cap', 'whatever', [
            ['hive' => 'HKLM', 'path' => 'SOFTWARE\\X\\List', 'entry_type' => 'REG_SZ', 'values' => ['a', 'b', 'c']],
        ]);

        $c = $this->machineProvider()->itemsFor($this->ctx())->first();

        self::assertNotNull($c);
        self::assertSame(['a', 'b', 'c'], $c->payload['values']);
    }

    // ── (e) forme assoc inattendue (dont $ensure) : non émis défensif ──────

    #[Test]
    public function unexpected_assoc_forms_including_ensure_marker_emit_nothing(): void
    {
        // Le marqueur $ensure de 35.1 n'est PAS supporté en registry_list :
        // l'idiome de suppression EST la liste vide. Scalaire résolu = non émis
        // aussi (une liste est la seule forme émissible). Jamais d'exception.
        $this->makeListCapability('weird_list_cap', 'off', [
            ['hive' => 'HKLM', 'path' => 'SOFTWARE\\X\\A', 'entry_type' => 'REG_SZ', 'values' => ['off' => ['$ensure' => 'absent']]],
            ['hive' => 'HKLM', 'path' => 'SOFTWARE\\X\\B', 'entry_type' => 'REG_SZ', 'values' => ['off' => ['unexpected' => ['shape']]]],
            ['hive' => 'HKLM', 'path' => 'SOFTWARE\\X\\C', 'entry_type' => 'REG_SZ', 'values' => ['off' => 'scalar']],
            ['hive' => 'HKLM', 'path' => 'SOFTWARE\\X\\D', 'entry_type' => 'REG_SZ', 'values' => ['off' => ['real.exe']]],
        ]);

        $items = $this->machineProvider()->itemsFor($this->ctx());

        self::assertCount(1, $items, 'seul le conteneur D (liste réelle) est émis');
        self::assertSame('SOFTWARE\\X\\D', $items->first()->payload['path']);
        self::assertSame(['real.exe'], $items->first()->payload['values']);
    }

    // ── (f) entry_type hors contrat : non émis (+ défaut REG_SZ) ───────────

    #[Test]
    public function invalid_entry_type_is_not_emitted_and_default_is_reg_sz(): void
    {
        $this->makeListCapability('typed_list_cap', 'on', [
            ['hive' => 'HKLM', 'path' => 'SOFTWARE\\X\\Dword', 'entry_type' => 'REG_DWORD', 'values' => ['on' => ['1']]],
            ['hive' => 'HKLM', 'path' => 'SOFTWARE\\X\\Multi', 'entry_type' => 'REG_MULTI_SZ', 'values' => ['on' => ['a']]],
            ['hive' => 'HKLM', 'path' => 'SOFTWARE\\X\\NoType', 'values' => ['on' => ['a']]],
            ['hive' => 'HKLM', 'path' => 'SOFTWARE\\X\\Expand', 'entry_type' => 'REG_EXPAND_SZ', 'values' => ['on' => ['%ProgramFiles%\\x']]],
        ]);

        $items = $this->machineProvider()->itemsFor($this->ctx());

        self::assertCount(2, $items, 'REG_DWORD/REG_MULTI_SZ hors contrat §7.6 non émis');
        $byPath = $items->keyBy(fn (StateCandidate $c): string => $c->payload['path']);
        self::assertSame('REG_SZ', $byPath['SOFTWARE\\X\\NoType']->payload['entry_type'], 'défaut de spec = REG_SZ');
        self::assertSame('REG_EXPAND_SZ', $byPath['SOFTWARE\\X\\Expand']->payload['entry_type']);
    }

    // ── (g) filtre par ruche (HKLM vs HKCU) ────────────────────────────────

    #[Test]
    public function each_provider_only_emits_containers_of_its_hive(): void
    {
        $this->makeListCapability('mixed_hive_list', 'on', [
            ['hive' => 'HKLM', 'path' => 'SOFTWARE\\X\\MachineList', 'entry_type' => 'REG_SZ', 'values' => ['on' => ['m']]],
            ['hive' => 'HKCU', 'path' => 'Software\\Y\\UserList', 'entry_type' => 'REG_SZ', 'values' => ['on' => ['u']]],
        ]);

        $machineItems = $this->machineProvider()->itemsFor($this->ctx());
        $userItems = $this->userProvider()->itemsFor($this->ctx());

        self::assertCount(1, $machineItems);
        self::assertSame('SOFTWARE\\X\\MachineList', $machineItems->first()->payload['path']);
        self::assertCount(1, $userItems);
        self::assertSame('Software\\Y\\UserList', $userItems->first()->payload['path']);
    }

    // ── (h) pas de fuite d'id, exactement 4 clés, strings only ─────────────

    #[Test]
    public function payload_is_concrete_four_keys_strings_only_without_any_capability_id(): void
    {
        $cap = $this->makeListCapability('pix_extension_forced', 'on', [
            // Entrées volontairement non-string dans la spec : cast défensif.
            ['hive' => 'HKLM', 'path' => 'SOFTWARE\\X\\List', 'entry_type' => 'REG_SZ', 'values' => ['on' => ['abc', 42]]],
        ]);
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->parc->id,
            'value' => 'on',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $items = $this->machineProvider()->itemsFor($this->ctx());
        self::assertCount(2, $items, 'Broadcast + override de maille');

        foreach ($items as $c) {
            self::assertSame(['hive', 'path', 'entry_type', 'values'], array_keys($c->payload));
            foreach (['name', 'type', 'value', 'id', 'key', 'capability_id', 'label', 'spec', 'ensure'] as $forbidden) {
                self::assertArrayNotHasKey($forbidden, $c->payload);
            }
            self::assertTrue(array_is_list($c->payload['values']));
            foreach ($c->payload['values'] as $entry) {
                self::assertIsString($entry, 'entrées castées en chaînes (zéro float §4.1)');
            }
            self::assertSame(['abc', '42'], $c->payload['values']);
        }
    }

    // ── (i) exclusiveKey = 2 segments minuscules (jamais de name) ──────────

    #[Test]
    public function exclusive_key_is_two_segment_lowercased_container_identity(): void
    {
        $p = $this->userProvider();

        $a = $p->exclusiveKey(['hive' => 'HKCU', 'path' => 'Software\\P\\DisallowRun', 'entry_type' => 'REG_SZ', 'values' => ['x']]);
        $b = $p->exclusiveKey(['hive' => 'hkcu', 'path' => 'software\\p\\disallowrun', 'entry_type' => 'REG_SZ', 'values' => ['y', 'z']]);

        self::assertSame($a, $b, 'insensible à la casse ET aux values (identité = conteneur)');
        self::assertSame('hkcu|software\\p\\disallowrun', $a, '2 segments {hive|path}, jamais de name');
        self::assertSame(1, substr_count($a, '|'), 'exactement 2 segments');
    }

    // ── Story 43.2 (D3, AC3) — recopie du hint `refresh` au payload ────────

    /**
     * @param  list<array<string,mixed>>  $keys
     */
    private function makeListCapabilityWithRefresh(string $key, string $default, array $keys, string $refresh): Capability
    {
        $cap = Capability::factory()->create(['key' => $key, 'default_value' => $default]);
        CapabilityProjection::factory()->for($cap)->create([
            'mechanism' => CapabilityProjection::MECHANISM_REGISTRY_LIST,
            'spec' => ['keys' => $keys, 'refresh' => $refresh],
        ]);

        return $cap;
    }

    #[Test]
    public function session_provider_recopies_the_refresh_hint_on_the_container_payload(): void
    {
        $cap = $this->makeListCapabilityWithRefresh('blocked_executables', 'on', [
            ['hive' => 'HKCU', 'path' => 'Software\\P\\Explorer\\DisallowRun', 'entry_type' => 'REG_SZ', 'values' => ['on' => ['cmd.exe'], 'off' => []]],
        ], CapabilityProjection::REFRESH_POLICY_BROADCAST);

        $broadcast = $this->userProvider()->itemsFor($this->ctx())->first();
        self::assertSame(['hive', 'path', 'entry_type', 'values', 'refresh'], array_keys($broadcast->payload));
        self::assertSame('policy_broadcast', $broadcast->payload['refresh']);

        // Override de maille (purge `off`) — le hint reste recopié.
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->parc->id,
            'value' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $override = $this->userProvider()->itemsFor($this->ctx())
            ->first(fn (StateCandidate $c): bool => $c->maille === StateMaille::LogicalGroup);
        self::assertSame([], $override->payload['values'], 'off = purge (liste vide), toujours une VRAIE valeur émise');
        self::assertSame('policy_broadcast', $override->payload['refresh']);
    }

    #[Test]
    public function machine_provider_never_recopies_the_refresh_hint(): void
    {
        // Piège n°4 — test négatif sur le mécanisme registry_list aussi.
        $this->makeListCapabilityWithRefresh('pix_extension_forced', 'on', [
            ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Policies\\Google\\Chrome\\ExtensionInstallForcelist', 'entry_type' => 'REG_SZ', 'values' => ['on' => ['abc']]],
        ], CapabilityProjection::REFRESH_SHELL_NOTIFY);

        $machineItems = $this->machineProvider()->itemsFor($this->ctx());

        self::assertCount(1, $machineItems);
        self::assertArrayNotHasKey('refresh', $machineItems->first()->payload, 'JAMAIS de refresh sur un conteneur Machine');
    }

    #[Test]
    public function an_invalid_spec_refresh_is_tolerated_at_render_and_emits_no_refresh_key(): void
    {
        foreach ([null, 42, 'SHELL_NOTIFY', ''] as $i => $invalidRefresh) {
            $cap = Capability::factory()->create(['key' => 'invalid_refresh_list_cap_'.$i, 'default_value' => 'on']);
            CapabilityProjection::factory()->for($cap)->create([
                'mechanism' => CapabilityProjection::MECHANISM_REGISTRY_LIST,
                'spec' => [
                    'keys' => [['hive' => 'HKCU', 'path' => 'Software\\X\\List', 'entry_type' => 'REG_SZ', 'values' => ['on' => ['a']]]],
                    'refresh' => $invalidRefresh,
                ],
            ]);

            $item = $this->userProvider()->itemsFor($this->ctx())
                ->first(fn (StateCandidate $c): bool => (int) $c->sourceId === (int) $cap->id);

            self::assertNotNull($item);
            self::assertArrayNotHasKey('refresh', $item->payload);
        }
    }

    #[Test]
    public function a_spec_without_refresh_emits_byte_identical_container_payloads(): void
    {
        $this->makeListCapability('blocked_executables', 'on', [
            ['hive' => 'HKCU', 'path' => 'Software\\P\\Explorer\\DisallowRun', 'entry_type' => 'REG_SZ', 'values' => ['on' => ['cmd.exe']]],
        ]);

        $item = $this->userProvider()->itemsFor($this->ctx())->first();

        self::assertSame(['hive', 'path', 'entry_type', 'values'], array_keys($item->payload));
    }

    // ── Story 35.7 (D1/D2, AC2) — marqueur `writer` par conteneur de spec ──
    // Le conteneur marqué est réconcilié par le service SYSTEM dans HKU\<SID>
    // (jamais par le compagnon — …\Policies\Explorer\DisallowRun non
    // user-writable sur poste joint au domaine).

    #[Test]
    public function session_provider_recopies_the_writer_marker_as_a_five_key_container(): void
    {
        // (a) conteneur marqué : EXACTEMENT 5 clés {hive, path, entry_type,
        // values, writer} — sur le `on` (liste peuplée) ET sur le `off`
        // (purge, liste vide) : le marqueur voyage avec la clé.
        $cap = $this->makeListCapability('blocked_executables', 'on', [
            ['hive' => 'HKCU', 'path' => 'Software\\P\\Explorer\\DisallowRun', 'entry_type' => 'REG_SZ', 'values' => ['on' => ['cmd.exe'], 'off' => []], 'writer' => 'system'],
        ]);

        $broadcast = $this->userProvider()->itemsFor($this->ctx())->first();
        self::assertSame(['hive', 'path', 'entry_type', 'values', 'writer'], array_keys($broadcast->payload));
        self::assertSame('system', $broadcast->payload['writer']);
        self::assertSame(['cmd.exe'], $broadcast->payload['values']);

        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->parc->id,
            'value' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $override = $this->userProvider()->itemsFor($this->ctx())
            ->first(fn (StateCandidate $c): bool => $c->maille === StateMaille::LogicalGroup);
        self::assertSame([], $override->payload['values'], 'off = purge (liste vide)');
        self::assertSame('system', $override->payload['writer'], 'le marqueur voyage sur le candidat de maille');
    }

    #[Test]
    public function refresh_hint_is_never_posed_on_a_writer_marked_container(): void
    {
        // (b) piège n°6 — exclusion mutuelle refresh/writer côté registry_list :
        // un hint résiduel au spec n'est JAMAIS recopié sur le conteneur marqué.
        $cap = Capability::factory()->create(['key' => 'marked_list_with_hint', 'default_value' => 'on']);
        CapabilityProjection::factory()->for($cap)->create([
            'mechanism' => CapabilityProjection::MECHANISM_REGISTRY_LIST,
            'spec' => [
                'keys' => [
                    ['hive' => 'HKCU', 'path' => 'Software\\P\\Explorer\\DisallowRun', 'entry_type' => 'REG_SZ', 'values' => ['on' => ['cmd.exe']], 'writer' => 'system'],
                ],
                'refresh' => 'policy_broadcast',
            ],
        ]);

        $item = $this->userProvider()->itemsFor($this->ctx())->first();

        self::assertSame('system', $item->payload['writer']);
        self::assertArrayNotHasKey('refresh', $item->payload, 'JAMAIS refresh sur un conteneur writer (piège n°6)');
    }

    #[Test]
    public function machine_provider_never_emits_the_writer_marker_on_a_container(): void
    {
        // (c) garde HKCU du helper : un conteneur HKLM portant un marqueur
        // corrompu n'est jamais recopié par le provider Machine (défense au
        // render — le guard refuse déjà à l'authoring).
        $this->makeListCapability('rogue_marked_machine_list', 'on', [
            ['hive' => 'HKLM', 'path' => 'SOFTWARE\\Policies\\Google\\Chrome\\ExtensionInstallForcelist', 'entry_type' => 'REG_SZ', 'values' => ['on' => ['abc']], 'writer' => 'system'],
        ]);

        $machineItems = $this->machineProvider()->itemsFor($this->ctx());

        self::assertCount(1, $machineItems);
        self::assertArrayNotHasKey('writer', $machineItems->first()->payload, 'JAMAIS de writer sur un conteneur Machine (AC2)');
    }

    // ── NFR7 — zéro AD/APCu dans les sources ───────────────────────────────

    #[Test]
    public function provider_source_has_no_ad_apcu_samba_dependency(): void
    {
        foreach ([
            app_path('Services/Agent/Providers/AbstractRegistryListCapabilityProvider.php'),
            app_path('Services/Agent/Providers/RegistryListMachineCapabilityProvider.php'),
            app_path('Services/Agent/Providers/RegistryListUserCapabilityProvider.php'),
            app_path('Services/Agent/Providers/CapabilitySpecCollisionGuard.php'),
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
