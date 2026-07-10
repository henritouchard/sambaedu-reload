<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\ResourceSemantics;
use App\Enums\StateScope;
use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Providers\LegacyCleanupCapabilityProvider;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 38.3 (AC2) — Tests Unit du provider `legacy_cleanup` CAPABILITY-FIRST.
 *
 * Le provider EXPANSE la capacité de gating → AU PLUS un item CONCRET 1 clé
 * `{mozilla: "vanilla"}` (enum FERMÉ §7.10, Q5-a VANILLA). `exclusiveKey()`
 * FIXE `legacy_cleanup` (un seul nettoyage par poste). Lecture Postgres pure
 * (NFR7 — le catalogue d'artefacts est DANS l'agent, D3). Invariant central
 * 27.12 : jamais d'id/key de capacité au payload.
 */
class CapabilityLegacyCleanupProviderTest extends TestCase
{
    use RefreshDatabase;

    /** Spec canonique du seed : map valeur → traitement Mozilla (pas de off). */
    private const SPEC = ['mozilla' => ['on' => 'vanilla']];

    private Workstation $ws;

    private WorkstationGroup $parc;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();

        // Catalogue VIDE : on contrôle exactement ce que le provider émet
        // (la capacité de preuve est seedée par migration — testée ailleurs).
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
        parent::tearDown();
    }

    private function ctx(): TargetContext
    {
        return TargetContext::for($this->ws, null);
    }

    private function provider(): LegacyCleanupCapabilityProvider
    {
        return new LegacyCleanupCapabilityProvider();
    }

    /**
     * @param  array<string,mixed>|null  $spec
     */
    private function makeCapability(string $default, ?array $spec = self::SPEC, string $key = 'legacy_hooks'): Capability
    {
        $cap = Capability::factory()->create(['key' => $key, 'default_value' => $default]);
        CapabilityProjection::factory()->for($cap)->create([
            'mechanism' => CapabilityProjection::MECHANISM_LEGACY_CLEANUP,
            'spec' => $spec,
        ]);

        return $cap;
    }

    // ── Type / sémantique / portée / identité ────────────────────────────

    #[Test]
    public function provider_declares_legacy_cleanup_exclusive_machine(): void
    {
        $p = $this->provider();
        self::assertSame('legacy_cleanup', $p->type());
        self::assertSame(ResourceSemantics::Exclusive, $p->semantics());
        self::assertSame(StateScope::Machine, $p->scope());
    }

    #[Test]
    public function exclusive_key_is_fixed_regardless_of_payload(): void
    {
        $p = $this->provider();
        self::assertSame('legacy_cleanup', $p->exclusiveKey(['mozilla' => 'vanilla']));
        self::assertSame('legacy_cleanup', $p->exclusiveKey([]), 'identité FIXE : un seul nettoyage par poste');
    }

    // ── Expansion : payload EXACTEMENT 1 clé, jamais d'id de capacité ─────

    #[Test]
    public function on_emits_exactly_one_item_with_the_one_key_vanilla_payload(): void
    {
        $this->makeCapability('on');

        $items = $this->provider()->itemsFor($this->ctx());

        self::assertCount(1, $items);
        $payload = $items->first()->payload;
        self::assertSame(['mozilla' => 'vanilla'], $payload, 'payload CONCRET : EXACTEMENT 1 clé, enum fermé Q5-a');
        foreach (['id', 'key', 'capability_id', 'label', 'spec'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $payload, 'invariant 27.12 : jamais d\'id/key de capacité au payload');
        }
    }

    #[Test]
    public function unmanaged_sentinel_emits_nothing(): void
    {
        // `unmanaged` ABSENT de la map ⇒ sentinelle ⇒ rien émis (agent inactif
        // sur ce type — le handler n'est même pas invoqué, contrat §8).
        $this->makeCapability('unmanaged');

        self::assertCount(0, $this->provider()->itemsFor($this->ctx()));
    }

    // ── Gardes défensives (spec corrompue ⇒ jamais d'exception au render) ─

    #[Test]
    public function value_outside_the_closed_enum_is_not_emitted(): void
    {
        // Enum FERMÉ ["vanilla"] (§7.10) : une spec hors domaine est écartée.
        $this->makeCapability('on', ['mozilla' => ['on' => 'forced_profile']]);

        self::assertCount(0, $this->provider()->itemsFor($this->ctx()));
    }

    #[Test]
    public function malformed_specs_are_not_emitted_defensively(): void
    {
        // Clé `mozilla` absente (résolue null, non-string) ⇒ rien.
        $this->makeCapability('on', ['autre' => 'chose'], 'cap_missing');
        // Valeur résolue non scalaire-string (liste) ⇒ rien.
        $this->makeCapability('on', ['mozilla' => ['on' => ['vanilla']]], 'cap_list');

        self::assertCount(0, $this->provider()->itemsFor($this->ctx()), 'spec corrompue ⇒ non émis, jamais d\'exception');
    }

    // ── Override parc (patron défaut Broadcast + override parc) ───────────

    #[Test]
    public function parc_override_on_arms_the_cleanup_over_broadcast_unmanaged(): void
    {
        $cap = $this->makeCapability('unmanaged');
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->parc->id,
            'value' => 'on',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $items = $this->provider()->itemsFor($this->ctx());
        self::assertCount(1, $items, 'override parc `on` bat le défaut Broadcast `unmanaged`');
        self::assertSame(['mozilla' => 'vanilla'], $items->first()->payload);
    }
}
