<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ControlHub;

use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\ControlHubContractItem;
use App\Services\Agent\Providers\RegistryUserCapabilityProvider;
use App\Services\ControlHub\UpstreamLockResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 29.2 — Résolution du verrou amont ({@see UpstreamLockResolver}).
 *
 * Couvre : locked → verrou, permissive/absent/severed/standalone → libre,
 * court-circuit NFR3 (≤ 1 requête, jamais `items`), clé non matchante → libre,
 * label différé Epic 30 → libre, primitive générique `isLocked`, et l'identité de
 * clé alignée à l'octet sur le provider registry.
 *
 * Tests HÔTE (php8.4 + pdo_sqlite), `RefreshDatabase`. SQLite n'applique pas
 * varchar/enum PG → on teste des DÉCISIONS (booléens), pas des bornes.
 */
class UpstreamLockResolverTest extends TestCase
{
    use RefreshDatabase;

    /** Capacité + projection registry HKCU (clé déterministe pour le matching). */
    private function makeCapabilityWithKey(string $hive, string $path, string $name): Capability
    {
        $cap = Capability::factory()->create();
        CapabilityProjection::factory()->for($cap)->keys([
            [
                'hive' => $hive,
                'path' => $path,
                'name' => $name,
                'type' => 'REG_DWORD',
                'value' => ['on' => 1, 'off' => 0],
            ],
        ])->create();

        return $cap;
    }

    /**
     * Item amont `registry` `locked`/`instance` ciblant cette clé.
     *
     * ⚠️ Crée un NOUVEAU `ControlHubContract` actif par appel (via la factory).
     * `UpstreamLockResolver` lit `where(active)->first()` (invariant ≤ 1 contrat
     * actif garanti en réception 28.2) → n'appeler qu'UNE fois par test, sinon seul
     * le 1ᵉʳ contrat est vu. Pour verrouiller plusieurs clés, utiliser
     * {@see lockItemsOnSameContract()}. [P4 review]
     */
    private function lockItem(string $hive, string $path, string $name): ControlHubContractItem
    {
        return ControlHubContractItem::factory()->create([
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => "{$hive}|{$path}|{$name}|REG_DWORD",
        ]);
    }

    #[Test]
    public function standalone_no_contract_is_never_locked_and_short_circuits(): void
    {
        $cap = $this->makeCapabilityWithKey('HKCU', 'Software\\X', 'Foo');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $resolver = new UpstreamLockResolver();
        $locked = $resolver->isCapabilityLocked($cap);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        self::assertFalse($locked, 'aucun contrat → jamais verrouillé');

        $contractQueries = $this->countQueries($log, 'controlhub_contracts');
        $itemQueries = $this->countQueries($log, 'controlhub_contract_items');
        self::assertSame(1, $contractQueries, 'exactement 1 requête « contrat actif ? »');
        self::assertSame(0, $itemQueries, 'JAMAIS la table items sans contrat actif (court-circuit NFR3)');
    }

    #[Test]
    public function locked_instance_registry_item_matching_a_projection_locks_capability(): void
    {
        $cap = $this->makeCapabilityWithKey('HKCU', 'Software\\Y', 'Bar');
        $this->lockItem('HKCU', 'Software\\Y', 'Bar');

        self::assertTrue((new UpstreamLockResolver())->isCapabilityLocked($cap));
    }

    #[Test]
    public function capability_with_multiple_keys_is_locked_when_any_one_key_matches(): void
    {
        // Une projection registry porte DEUX clés (`spec.keys[]`) ; une seule est
        // verrouillée amont. Sémantique « au moins une clé » → la capacité est
        // verrouillée. (Le schéma impose 1 projection registry/capacité → le cas
        // multi-clés se modélise dans une projection unique.) [P3]
        $cap = Capability::factory()->create();
        CapabilityProjection::factory()->for($cap)->keys([
            ['hive' => 'HKCU', 'path' => 'Software\\Multi', 'name' => 'KeyA', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
            ['hive' => 'HKCU', 'path' => 'Software\\Multi', 'name' => 'KeyB', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
        ])->create();

        // Seule KeyB est verrouillée (KeyA reste libre).
        $this->lockItem('HKCU', 'Software\\Multi', 'KeyB');

        self::assertTrue(
            (new UpstreamLockResolver())->isCapabilityLocked($cap),
            'une seule clé verrouillée parmi plusieurs suffit à verrouiller la capacité',
        );
    }

    #[Test]
    public function permissive_item_does_not_lock(): void
    {
        $cap = $this->makeCapabilityWithKey('HKCU', 'Software\\Z', 'Baz');
        ControlHubContractItem::factory()->permissive()->create([
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => 'HKCU|Software\\Z|Baz|REG_DWORD',
        ]);

        self::assertFalse(
            (new UpstreamLockResolver())->isCapabilityLocked($cap),
            'permissive est surchargeable (Story 29.3 / FR4) — pas un verrou',
        );
    }

    #[Test]
    public function absent_item_does_not_lock(): void
    {
        $cap = $this->makeCapabilityWithKey('HKCU', 'Software\\A', 'Qux');
        ControlHubContractItem::factory()->absent()->create([
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => 'HKCU|Software\\A|Qux|REG_DWORD',
        ]);

        self::assertFalse((new UpstreamLockResolver())->isCapabilityLocked($cap));
    }

    #[Test]
    public function severed_contract_does_not_lock(): void
    {
        $cap = $this->makeCapabilityWithKey('HKCU', 'Software\\S', 'Sev');
        $item = $this->lockItem('HKCU', 'Software\\S', 'Sev');
        // Couper le lien du contrat parent.
        $item->contract->update(['link_state' => \App\Enums\ControlHubLinkState::Severed]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $locked = (new UpstreamLockResolver())->isCapabilityLocked($cap);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        self::assertFalse($locked, 'contrat severed → l\'état local prime, aucun verrou');
        self::assertSame(0, $this->countQueries($log, 'controlhub_contract_items'), 'severed = pas de contrat actif → court-circuit items');
    }

    #[Test]
    public function non_matching_key_does_not_lock(): void
    {
        $cap = $this->makeCapabilityWithKey('HKCU', 'Software\\Real', 'Name');
        // Verrou sur une AUTRE clé.
        $this->lockItem('HKCU', 'Software\\Other', 'Different');

        self::assertFalse((new UpstreamLockResolver())->isCapabilityLocked($cap));
    }

    #[Test]
    public function label_targeted_locked_item_is_deferred_to_epic30(): void
    {
        $cap = $this->makeCapabilityWithKey('HKCU', 'Software\\L', 'Lab');
        ControlHubContractItem::factory()->forLabel('salle-info')->create([
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => 'HKCU|Software\\L|Lab|REG_DWORD',
            // forLabel() règle target_type=label ; enforcement reste locked (défaut).
        ]);

        self::assertFalse(
            (new UpstreamLockResolver())->isCapabilityLocked($cap),
            'target_type=label différé Epic 30 (instance only en 29.2)',
        );
    }

    #[Test]
    public function key_identity_is_case_insensitive_and_byte_aligned_with_provider(): void
    {
        // Projection en casse mixte ; item amont en casse différente → doivent
        // matcher (identité `strtolower(hive|path|name)`).
        $cap = $this->makeCapabilityWithKey('HKCU', 'Software\\MixedCase', 'ValueName');
        $this->lockItem('hkcu', 'software\\mixedcase', 'valuename');

        self::assertTrue(
            (new UpstreamLockResolver())->isCapabilityLocked($cap),
            'matching insensible à la casse, aligné sur exclusiveKey() du provider',
        );

        // Garde-fou d'alignement à l'octet : la normalisation du resolver doit
        // produire la MÊME clé que le provider registry sur le même payload.
        $providerKey = (new RegistryUserCapabilityProvider())->exclusiveKey([
            'hive' => 'HKCU',
            'path' => 'Software\\MixedCase',
            'name' => 'ValueName',
        ]);
        self::assertSame('hkcu|software\\mixedcase|valuename', $providerKey);

        // Cross-check DIRECT : la clé normalisée par le resolver (exposée via le set
        // public `lockedRegistryKeys()`) est byte-identique à celle du provider —
        // preuve explicite de l'alignement, pas seulement déduite de isCapabilityLocked. [P5]
        self::assertArrayHasKey(
            $providerKey,
            (new UpstreamLockResolver())->lockedRegistryKeys(),
            'la clé indexée par le resolver == exclusiveKey() du provider (alignement à l\'octet)',
        );
    }

    #[Test]
    public function is_locked_primitive_handles_registry_and_ignores_aggregate_types(): void
    {
        $this->lockItem('HKLM', 'Software\\P', 'Prim');
        $resolver = new UpstreamLockResolver();

        self::assertTrue($resolver->isLocked('registry', 'HKLM|Software\\P|Prim'));
        self::assertTrue($resolver->isLocked('registry', 'hklm|software\\p|prim|REG_DWORD'));
        self::assertFalse($resolver->isLocked('registry', 'HKLM|Software\\P|Absent'));
        // Couture : les types aggregate (shortcuts) sont HORS verrou 29.2.
        self::assertFalse($resolver->isLocked('shortcuts', 'HKLM|Software\\P|Prim'));
    }

    #[Test]
    public function non_registry_locked_item_does_not_populate_registry_key_set(): void
    {
        // Un item `locked`/`instance` mais de type non-registry (ex. shortcuts) ne
        // doit JAMAIS verrouiller une capacité registre.
        $cap = $this->makeCapabilityWithKey('HKCU', 'Software\\NR', 'NReg');
        ControlHubContractItem::factory()->create([
            'type' => 'shortcuts',
            'key' => 'HKCU|Software\\NR|NReg|REG_DWORD',
        ]);

        self::assertFalse((new UpstreamLockResolver())->isCapabilityLocked($cap));
        self::assertSame([], (new UpstreamLockResolver())->lockedRegistryKeys());
    }

    /**
     * @param  list<array{query:string,bindings:array<mixed>,time:float}>  $log
     */
    private function countQueries(array $log, string $needle): int
    {
        return count(array_filter($log, static fn (array $q): bool => str_contains($q['query'], $needle)));
    }
}
