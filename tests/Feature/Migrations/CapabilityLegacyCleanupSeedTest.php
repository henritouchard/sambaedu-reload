<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

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
 * Story 38.3 (AC2) — seed de la capacité de gating `legacy_hooks_cleanup` +
 * intégration provider sur données RÉELLES (la migration de seed est jouée par
 * `RefreshDatabase`).
 *
 * FICHIER DÉDIÉ (piège #12 de 36.1) : ne touche NI
 * `CapabilitiesSchemaAndSeedTest.php` NI les tests des autres mécanismes.
 */
class CapabilityLegacyCleanupSeedTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'legacy_hooks_cleanup';

    private const MIGRATION = 'database/migrations/2026_07_10_100000_seed_capability_legacy_hooks_cleanup.php';

    private Workstation $ws;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();

        $this->ws = Workstation::factory()->create();
        $parc = WorkstationGroup::factory()->logical()->create();
        $this->ws->groups()->attach($parc->id);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private function capabilityRow(): ?object
    {
        return DB::table('capabilities')->where('key', self::KEY)->first();
    }

    /**
     * @return array<string,mixed>
     */
    private function spec(): array
    {
        $cap = $this->capabilityRow();
        $projection = DB::table('capability_projections')
            ->where('capability_id', $cap->id)
            ->where('os', 'windows')
            ->where('mechanism', 'legacy_cleanup')
            ->first();

        self::assertNotNull($projection, 'la migration doit avoir seedé la projection windows/legacy_cleanup');

        return json_decode((string) $projection->spec, true, 512, JSON_THROW_ON_ERROR);
    }

    private function setValue(string $value): void
    {
        DB::table('capabilities')->where('key', self::KEY)->update(['default_value' => $value]);
    }

    private function items(): \Illuminate\Support\Collection
    {
        return (new LegacyCleanupCapabilityProvider())->itemsFor(TargetContext::for($this->ws, null));
    }

    // ── Seed : options / défaut / warning / projection ────────────────────

    #[Test]
    public function seed_creates_the_capability_with_the_two_value_toggle_and_warning(): void
    {
        $cap = $this->capabilityRow();
        self::assertNotNull($cap, 'la migration doit avoir seedé la capacité');
        self::assertSame('enum', $cap->value_type);
        self::assertSame('unmanaged', $cap->default_value, 'défaut Broadcast = unmanaged (agent inactif)');
        self::assertNotEmpty($cap->warning, 'mécanisme qui SUPPRIME des fichiers ⇒ warning non vide');
        self::assertStringContainsString('ONE-WAY', (string) $cap->warning, 'le warning documente le nettoyage one-way');
        self::assertStringContainsString('2.9.0', (string) $cap->warning, 'le warning documente le prérequis binaire publié');
        self::assertLessThanOrEqual(255, mb_strlen((string) $cap->description), 'varchar PG 255 (piège 22001, invisible en SQLite)');
        self::assertSame(['windows'], json_decode((string) $cap->applies_to_os, true));

        // Toggle à DEUX valeurs — PAS de `off` (piège #7 : nettoyage one-way,
        // la règle des maps registre symétriques ne s'applique pas ici).
        $options = json_decode((string) $cap->options, true);
        $values = array_column($options, 'value');
        self::assertSame(['unmanaged', 'on'], $values, 'unmanaged/on SEULEMENT — jamais de off');
        // Convention « sujet + état » : « Non géré » réservé à la sentinelle.
        $byValue = array_column($options, 'label', 'value');
        self::assertSame('Non géré', $byValue['unmanaged']);
        self::assertSame('Nettoyés', $byValue['on']);
    }

    #[Test]
    public function projection_carries_the_mozilla_value_map_without_off_key(): void
    {
        $spec = $this->spec();

        self::assertSame(['mozilla'], array_keys($spec), 'spec minimale : la seule donnée contractuelle est le traitement Mozilla (D3)');
        self::assertSame(['on' => 'vanilla'], $spec['mozilla'], 'map valeur → traitement : `on` = vanilla (Q5-a), `unmanaged` ABSENT (sentinelle), pas de `off`');
    }

    // ── Intégration provider sur le seed réel ─────────────────────────────

    #[Test]
    public function default_unmanaged_emits_nothing_for_a_workstation(): void
    {
        self::assertCount(0, $this->items(), 'défaut Broadcast unmanaged ⇒ agent inactif, aucun item');
    }

    #[Test]
    public function value_on_emits_the_vanilla_payload(): void
    {
        $this->setValue('on');

        $items = $this->items();
        self::assertCount(1, $items);
        self::assertSame(['mozilla' => 'vanilla'], $items->first()->payload);
    }

    // ── Idempotence / réversibilité de la migration ───────────────────────

    #[Test]
    public function migration_is_idempotent_and_reversible(): void
    {
        $migration = require base_path(self::MIGRATION);

        // Rejouer up() ne duplique rien (updateOrInsert).
        $migration->up();
        self::assertSame(1, DB::table('capabilities')->where('key', self::KEY)->count());
        $capId = $this->capabilityRow()->id;
        self::assertSame(
            1,
            DB::table('capability_projections')->where('capability_id', $capId)->count(),
            'une seule projection windows/legacy_cleanup après re-run',
        );

        // down() retire capacité + projection (FK cascade).
        $migration->down();
        self::assertNull($this->capabilityRow());
        self::assertSame(0, DB::table('capability_projections')->where('capability_id', $capId)->count());
    }
}
