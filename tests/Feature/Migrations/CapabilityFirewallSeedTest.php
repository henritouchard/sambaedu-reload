<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Providers\FirewallAuthoringGuard;
use App\Services\Agent\Providers\FirewallCapabilityProvider;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 36.2 (AC5) — seed de PREUVE `internet_access` + intégration provider sur
 * données RÉELLES + invariant `FirewallAuthoringGuard`.
 *
 * FICHIER DÉDIÉ (piège #13) : ne touche NI `CapabilitiesSchemaAndSeedTest.php`
 * (36.3) NI `CapabilityFsAclSeedTest.php` (36.1). La migration de seed est jouée
 * par `RefreshDatabase`.
 */
class CapabilityFirewallSeedTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'internet_access';

    private const MIGRATION = 'database/migrations/2026_07_04_120000_seed_capability_internet_access.php';

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
     * @return list<array<string,mixed>>
     */
    private function rules(): array
    {
        $cap = $this->capabilityRow();
        $projection = DB::table('capability_projections')
            ->where('capability_id', $cap->id)
            ->where('os', 'windows')
            ->where('mechanism', 'firewall')
            ->first();
        $spec = json_decode((string) $projection->spec, true, 512, JSON_THROW_ON_ERROR);

        return $spec['rules'];
    }

    private function setValue(string $value): void
    {
        DB::table('capabilities')->where('key', self::KEY)->update(['default_value' => $value]);
    }

    private function items(): \Illuminate\Support\Collection
    {
        return (new FirewallCapabilityProvider())->itemsFor(TargetContext::for($this->ws, null));
    }

    // ── Seed : options / défaut / warning / description ≤ 255 ─────────────

    #[Test]
    public function seed_creates_the_capability_with_enum_options_default_and_warning(): void
    {
        $cap = $this->capabilityRow();
        self::assertNotNull($cap, 'la migration doit avoir seedé la capacité');
        self::assertSame('enum', $cap->value_type);
        self::assertSame('unmanaged', $cap->default_value);
        self::assertNotEmpty($cap->warning, 'capacité porteuse de block ⇒ warning non vide');

        // Description/label ≤ 255 (piège #12 — varchar PG).
        self::assertLessThanOrEqual(255, mb_strlen((string) $cap->description));
        self::assertLessThanOrEqual(255, mb_strlen((string) $cap->label));

        $options = json_decode((string) $cap->options, true);
        $values = array_column($options, 'value');
        self::assertSame(['unmanaged', 'on', 'off'], $values, 'enum epic telle quelle');
        $byValue = array_column($options, 'label', 'value');
        self::assertSame('Non géré', $byValue['unmanaged']);
        self::assertSame('Autorisé', $byValue['on']);
    }

    #[Test]
    public function projection_has_one_internet_block_rule(): void
    {
        $rules = $this->rules();
        self::assertCount(1, $rules);
        $rule = $rules[0];
        self::assertSame('internet-block', $rule['rule_id']);
        self::assertSame('out', $rule['direction']);
        self::assertSame('block', $rule['action']);
        self::assertSame('internet', $rule['remote_scope']);
        self::assertSame('any', $rule['protocol']);
        // ensure map : off → present, on → absent (unmanaged absent = sentinelle).
        self::assertSame(['off' => 'present', 'on' => 'absent'], $rule['ensure']);
    }

    // ── Idempotence / réversibilité ───────────────────────────────────────

    #[Test]
    public function migration_is_idempotent_and_reversible(): void
    {
        $migration = require base_path(self::MIGRATION);

        $migration->up();
        self::assertSame(1, DB::table('capabilities')->where('key', self::KEY)->count());
        self::assertSame(1, DB::table('capability_projections')
            ->where('capability_id', $this->capabilityRow()->id)
            ->where('mechanism', 'firewall')
            ->count());

        $migration->down();
        self::assertNull($this->capabilityRow());

        $migration->up();
        self::assertNotNull($this->capabilityRow());
    }

    // ── Intégration provider sur données RÉELLES ──────────────────────────

    #[Test]
    public function value_off_emits_one_present_block_item(): void
    {
        $this->setValue('off');

        $items = $this->items();
        self::assertCount(1, $items);
        $payload = $items->first()->payload;
        self::assertSame('internet-block', $payload['rule_id']);
        self::assertSame('out', $payload['direction']);
        self::assertSame('block', $payload['action']);
        self::assertSame('internet', $payload['remote_scope']);
        self::assertSame('any', $payload['protocol']);
        self::assertSame('present', $payload['ensure']);
    }

    #[Test]
    public function value_on_emits_one_absent_item_same_identity(): void
    {
        $this->setValue('on');

        $items = $this->items();
        self::assertCount(1, $items);
        self::assertSame('internet-block', $items->first()->payload['rule_id']);
        self::assertSame('absent', $items->first()->payload['ensure']);
    }

    #[Test]
    public function value_unmanaged_emits_nothing(): void
    {
        $this->setValue('unmanaged');
        self::assertCount(0, $this->items(), 'sentinelle unmanaged ⇒ rien émis');
    }

    // ── Invariant guard sur le catalogue seedé + combos Q3 + unicité ──────

    #[Test]
    public function authoring_guard_passes_on_the_seeded_catalog(): void
    {
        $projections = $this->seededFirewallProjections();

        self::assertNotEmpty($projections, 'le catalogue seedé porte au moins une projection firewall');
        $violations = (new FirewallAuthoringGuard())->violations($projections);
        self::assertSame([], $violations, 'le catalogue seedé passe le guard d\'authoring');
    }

    #[Test]
    public function authoring_guard_refuses_fabricated_q3_combos(): void
    {
        foreach (['192.168.0.0/16', '0.0.0.0/0', '192.160.0.0/12'] as $addr) {
            $violations = (new FirewallAuthoringGuard())->violations([[
                'capability' => 'rogue',
                'warning' => 'w',
                'spec' => ['rules' => [[
                    'rule_id' => 'lan-block',
                    'direction' => 'out',
                    'action' => 'block',
                    'remote_scope' => 'explicit',
                    'protocol' => 'any',
                    'remote_addresses' => [$addr],
                    'ensure' => 'present',
                ]]],
            ]]);
            self::assertNotEmpty($violations, "block explicit sur '{$addr}' doit être refusé (Q3)");
        }
    }

    #[Test]
    public function rule_ids_are_unique_across_capabilities(): void
    {
        // Piège #10 : deux projections firewall de capacités DIFFÉRENTES ne
        // partagent aucun rule_id (invariant de données sur le catalogue).
        $projections = DB::table('capability_projections')
            ->where('os', 'windows')
            ->where('mechanism', 'firewall')
            ->get(['capability_id', 'spec']);

        $owner = [];
        foreach ($projections as $p) {
            $spec = json_decode((string) $p->spec, true, 512, JSON_THROW_ON_ERROR);
            foreach ($spec['rules'] ?? [] as $rule) {
                $id = (string) ($rule['rule_id'] ?? '');
                if ($id === '') {
                    continue;
                }
                if (isset($owner[$id]) && $owner[$id] !== $p->capability_id) {
                    self::fail("rule_id '{$id}' partagé entre deux capacités (collision inter-capacités)");
                }
                $owner[$id] = $p->capability_id;
            }
        }
        self::assertNotEmpty($owner, 'au moins un rule_id seedé');
    }

    /**
     * @return list<array{capability:string, warning:?string, spec:mixed}>
     */
    private function seededFirewallProjections(): array
    {
        return DB::table('capability_projections')
            ->join('capabilities', 'capabilities.id', '=', 'capability_projections.capability_id')
            ->where('capability_projections.os', 'windows')
            ->where('capability_projections.mechanism', 'firewall')
            ->get(['capabilities.key', 'capabilities.warning', 'capability_projections.spec'])
            ->map(fn ($r): array => [
                'capability' => $r->key,
                'warning' => $r->warning,
                'spec' => json_decode((string) $r->spec, true),
            ])
            ->all();
    }
}
