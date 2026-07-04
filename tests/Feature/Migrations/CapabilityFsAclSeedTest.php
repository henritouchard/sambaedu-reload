<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\UserGroup;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Providers\FsAclAuthoringGuard;
use App\Services\Agent\Providers\FsAclCapabilityProvider;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 36.1 (AC5) — seed de PREUVE `program_files_browse_denied` + intégration
 * provider sur données RÉELLES + invariant `FsAclAuthoringGuard`.
 *
 * FICHIER DÉDIÉ (piège #12) : ne touche NI `CapabilitiesSchemaAndSeedTest.php`
 * (36.3 en parallèle y écrit), NI `CapabilitySpecCollisionGuard` (guard
 * REGISTRE). La migration de seed est jouée par `RefreshDatabase`.
 */
class CapabilityFsAclSeedTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'program_files_browse_denied';

    private const MIGRATION = 'database/migrations/2026_07_04_100000_seed_capability_program_files_browse_denied.php';

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
    private function aces(): array
    {
        $cap = $this->capabilityRow();
        $projection = DB::table('capability_projections')
            ->where('capability_id', $cap->id)
            ->where('os', 'windows')
            ->where('mechanism', 'fs_acl')
            ->first();
        $spec = json_decode((string) $projection->spec, true, 512, JSON_THROW_ON_ERROR);

        return $spec['aces'];
    }

    private function setValue(string $value): void
    {
        DB::table('capabilities')->where('key', self::KEY)->update(['default_value' => $value]);
    }

    private function items(): \Illuminate\Support\Collection
    {
        return (new FsAclCapabilityProvider())->itemsFor(TargetContext::for($this->ws, null));
    }

    private function seedEleves(): void
    {
        UserGroup::factory()->create(['name' => 'Eleves', 'type' => 'role']);
    }

    // ── Seed : options / défaut / warning ─────────────────────────────────

    #[Test]
    public function seed_creates_the_capability_with_enum_options_default_and_warning(): void
    {
        $cap = $this->capabilityRow();
        self::assertNotNull($cap, 'la migration doit avoir seedé la capacité');
        self::assertSame('enum', $cap->value_type);
        self::assertSame('unmanaged', $cap->default_value);
        self::assertNotEmpty($cap->warning, 'capacité porteuse de deny ⇒ warning non vide');

        $options = json_decode((string) $cap->options, true);
        $values = array_column($options, 'value');
        self::assertSame(['unmanaged', 'off', 'eleves', 'tous'], $values);
        // Convention « Non géré » réservé à la sentinelle.
        $byValue = array_column($options, 'label', 'value');
        self::assertSame('Non géré', $byValue['unmanaged']);
    }

    #[Test]
    public function projection_has_four_deny_list_folder_folder_only_entries(): void
    {
        $aces = $this->aces();
        self::assertCount(4, $aces, '2 chemins × 2 trustees = 4 entrées');
        foreach ($aces as $ace) {
            self::assertSame('deny', $ace['ace_type']);
            self::assertSame('list_folder', $ace['rights']);
            self::assertSame('folder_only', $ace['applies_to'], 'masquer sans casser (dossier seul)');
            self::assertContains($ace['path'], ['C:\\Program Files', 'C:\\Program Files (x86)']);
        }
        // Trustees : deux entrées @eleves, deux Domain Users.
        $trustees = array_map(fn ($a) => is_array($a['trustee']) ? array_values($a['trustee'])[0] : $a['trustee'], $aces);
        self::assertContains('@eleves', $trustees);
        self::assertContains('Domain Users', $trustees);
    }

    // ── Idempotence / réversibilité ───────────────────────────────────────

    #[Test]
    public function migration_is_idempotent_and_reversible(): void
    {
        $migration = require base_path(self::MIGRATION);

        // Rejouer up() ne duplique pas.
        $migration->up();
        self::assertSame(1, DB::table('capabilities')->where('key', self::KEY)->count());
        self::assertSame(1, DB::table('capability_projections')
            ->where('capability_id', $this->capabilityRow()->id)
            ->where('mechanism', 'fs_acl')
            ->count());

        // down() supprime (FK cascade → projection).
        $migration->down();
        self::assertNull($this->capabilityRow());

        // up() recrée.
        $migration->up();
        self::assertNotNull($this->capabilityRow());
    }

    // ── Intégration provider sur données RÉELLES ──────────────────────────

    #[Test]
    public function value_eleves_emits_two_present_deny_items_for_eleves(): void
    {
        $this->seedEleves();
        $this->setValue('eleves');

        $items = $this->items();
        self::assertCount(2, $items, 'deux chemins → deux items');
        foreach ($items as $c) {
            self::assertSame('Eleves', $c->payload['trustee']);
            self::assertSame('present', $c->payload['ensure']);
            self::assertSame('deny', $c->payload['ace_type']);
        }
    }

    #[Test]
    public function value_tous_emits_two_present_items_for_domain_users(): void
    {
        $this->setValue('tous');

        $items = $this->items();
        self::assertCount(2, $items);
        foreach ($items as $c) {
            self::assertSame('Domain Users', $c->payload['trustee']);
            self::assertSame('present', $c->payload['ensure']);
        }
    }

    #[Test]
    public function value_off_emits_four_absent_items(): void
    {
        $this->seedEleves();
        $this->setValue('off');

        $items = $this->items();
        self::assertCount(4, $items, 'off = retrait honnête des DEUX trustees sur les DEUX chemins');
        foreach ($items as $c) {
            self::assertSame('absent', $c->payload['ensure']);
        }
    }

    #[Test]
    public function value_unmanaged_emits_nothing(): void
    {
        $this->setValue('unmanaged');
        self::assertCount(0, $this->items(), 'sentinelle unmanaged ⇒ rien émis');
    }

    #[Test]
    public function missing_eleves_group_suppresses_at_eleves_entries_and_warns(): void
    {
        // Groupe `Eleves` ABSENT de user_groups : la valeur `eleves` n'a que des
        // trustees @eleves → RIEN émis + warning loggé (jamais de jeton brut).
        Log::spy();
        $this->setValue('eleves');

        self::assertCount(0, $this->items());
        Log::shouldHaveReceived('warning')->atLeast()->once();
    }

    // ── Invariant guard sur le catalogue seedé + combo interdit ───────────

    #[Test]
    public function authoring_guard_passes_on_the_seeded_catalog(): void
    {
        $projections = DB::table('capability_projections')
            ->join('capabilities', 'capabilities.id', '=', 'capability_projections.capability_id')
            ->where('capability_projections.os', 'windows')
            ->where('capability_projections.mechanism', 'fs_acl')
            ->get(['capabilities.key', 'capabilities.warning', 'capability_projections.spec'])
            ->map(fn ($r): array => [
                'capability' => $r->key,
                'warning' => $r->warning,
                'spec' => json_decode((string) $r->spec, true),
            ])
            ->all();

        self::assertNotEmpty($projections, 'le catalogue seedé porte au moins une projection fs_acl');
        $violations = (new FsAclAuthoringGuard())->violations($projections);
        self::assertSame([], $violations, 'le catalogue seedé passe le guard d\'authoring');
    }

    #[Test]
    public function authoring_guard_refuses_a_fabricated_forbidden_combo(): void
    {
        // Combo Q2 fabriqué : deny à héritage descendant sur C:\Windows.
        $violations = (new FsAclAuthoringGuard())->violations([[
            'capability' => 'rogue',
            'warning' => 'w',
            'spec' => ['aces' => [[
                'path' => 'C:\\Windows',
                'ace_type' => 'deny',
                'rights' => 'modify',
                'applies_to' => 'folder_subfolders_files',
                'trustee' => '@eleves',
                'ensure' => 'present',
            ]]],
        ]]);

        self::assertNotEmpty($violations, 'un deny descendant sur C:\\Windows doit être refusé (Q2)');
    }
}
