<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Providers\LegacyCleanupCapabilityProvider;
use App\Services\Agent\StateCompiler;
use App\Services\Agent\StateContract;
use App\Services\Agent\StateHasher;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 38.3 (AC2) — compilation BOUT-EN-BOUT capacité `legacy_hooks_cleanup`
 * → item de contrat via le `StateCompiler` INCHANGÉ. Prouve : (a) le patron
 * « défaut Broadcast + override parc » dans LES DEUX SENS (armement par parc,
 * retrait au silence par parc) ; (b) l'identité FIXE `legacy_cleanup` — deux
 * capacités concurrentes ⇒ UN seul item (la maille la plus spécifique gagne
 * l'item ENTIER) ; (c) le hash compilé est BYTE-IDENTIQUE au golden
 * `state.v1.json` (jumelage croisé PHP↔Go de l'AC1).
 */
class CapabilityLegacyCleanupCompilationTest extends TestCase
{
    use RefreshDatabase;

    /** Hash de l'item `legacy_cleanup` du golden state.v1.json (AC1). */
    private const GOLDEN_ITEM_HASH = 'a0a1097487671bcacdb4722b8246b5b98de991fd5ef80d2c734ad324eb23164c';

    private const SPEC = ['mozilla' => ['on' => 'vanilla']];

    private Workstation $ws;

    private WorkstationGroup $logical;

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
        $this->ws->groups()->attach($this->logical->id);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private function makeCapability(string $key, string $default): Capability
    {
        $cap = Capability::factory()->create(['key' => $key, 'default_value' => $default]);
        CapabilityProjection::factory()->for($cap)->create([
            'mechanism' => CapabilityProjection::MECHANISM_LEGACY_CLEANUP,
            'spec' => self::SPEC,
        ]);

        return $cap;
    }

    private function assign(Capability $cap, string $value): void
    {
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->logical->id,
            'value' => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function machineLegacyCleanup(): array
    {
        $compiler = new StateCompiler(new StateHasher(), [new LegacyCleanupCapabilityProvider()]);
        $state = $compiler->compile(TargetContext::for($this->ws, null));

        return array_values(array_filter(
            $state[StateContract::SCOPE_MACHINE],
            fn ($i): bool => $i['type'] === 'legacy_cleanup',
        ));
    }

    // ── (a) Défaut Broadcast + override parc, deux sens ───────────────────

    #[Test]
    public function parc_on_arms_the_cleanup_over_broadcast_unmanaged(): void
    {
        $cap = $this->makeCapability('legacy_hooks_cleanup', 'unmanaged');
        $this->assign($cap, 'on');

        $items = $this->machineLegacyCleanup();
        self::assertCount(1, $items, 'override parc `on` ⇒ item émis malgré le défaut Broadcast `unmanaged`');
        self::assertSame(['mozilla' => 'vanilla'], $items[0]['payload']);
    }

    #[Test]
    public function broadcast_unmanaged_default_compiles_to_silence(): void
    {
        // Défaut Broadcast `unmanaged` (sentinelle) sans override ⇒ RIEN n'est
        // émis pour ce poste : agent inactif sur le type (le handler n'est
        // même pas invoqué, contrat §8).
        $this->makeCapability('legacy_hooks_cleanup', 'unmanaged');

        self::assertCount(0, $this->machineLegacyCleanup(), 'défaut unmanaged ⇒ silence (aucun item au state)');
    }

    #[Test]
    public function parc_unmanaged_override_does_not_resilence_a_broadcast_on(): void
    {
        // Discipline UNMANAGED du modèle capacités (commune à TOUS les
        // providers) : la sentinelle N'ÉMET PAS de candidat à sa maille — elle
        // ne peut donc PAS masquer un candidat Broadcast existant. Un parc
        // « repassé à Non géré » sous un Broadcast `on` reste nettoyé (sans
        // conséquence ici : le nettoyage est one-way et idempotent, piège #7 —
        // le retrait du gating global passe par le default_value Broadcast).
        $cap = $this->makeCapability('legacy_hooks_cleanup', 'on');
        $this->assign($cap, 'unmanaged');

        $items = $this->machineLegacyCleanup();
        self::assertCount(1, $items, 'le candidat Broadcast `on` survit (unmanaged n\'émet rien, il ne masque pas)');
        self::assertSame(['mozilla' => 'vanilla'], $items[0]['payload']);
    }

    // ── (b) Identité FIXE : deux capacités ⇒ UN item ─────────────────────

    #[Test]
    public function fixed_exclusive_key_yields_a_single_item_across_capabilities(): void
    {
        // Deux capacités projetant le MÊME mécanisme : identité `legacy_cleanup`
        // FIXE ⇒ le compilateur n'en garde qu'UNE (la plus spécifique).
        $this->makeCapability('cap_broadcast', 'on');
        $capParc = $this->makeCapability('cap_parc', 'unmanaged');
        $this->assign($capParc, 'on');

        $items = $this->machineLegacyCleanup();
        self::assertCount(1, $items, 'identité fixe ⇒ un SEUL nettoyage par poste, jamais de cumul');
        self::assertSame(['mozilla' => 'vanilla'], $items[0]['payload']);
    }

    // ── (c) Byte-identité avec le golden (AC1, jumelage PHP↔Go) ──────────

    #[Test]
    public function compiled_item_hash_matches_the_golden_fixture(): void
    {
        $this->makeCapability('legacy_hooks_cleanup', 'on');

        $items = $this->machineLegacyCleanup();
        self::assertCount(1, $items);
        self::assertSame('exclusive', $items[0]['semantics']);
        self::assertSame(
            self::GOLDEN_ITEM_HASH,
            $items[0]['hash'],
            'le hash compilé doit être BYTE-IDENTIQUE à l\'item du golden state.v1.json (croisé Go)',
        );
    }
}
