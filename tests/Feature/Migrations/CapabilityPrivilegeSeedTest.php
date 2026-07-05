<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\UserGroup;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Providers\PrivilegeAuthoringGuard;
use App\Services\Agent\Providers\PrivilegeCapabilityProvider;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 35.6 (AC5) — seed de PREUVE `rdp_denied_for_group` + intégration
 * provider sur données RÉELLES + invariant `PrivilegeAuthoringGuard`.
 *
 * FICHIER DÉDIÉ (piège #12 de 36.1) : ne touche NI
 * `CapabilitiesSchemaAndSeedTest.php` NI `CapabilityFsAclSeedTest.php` (tests
 * d'autres mécanismes). La migration de seed est jouée par `RefreshDatabase`.
 */
class CapabilityPrivilegeSeedTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'rdp_denied_for_group';

    private const RDP_DENY = 'SeDenyRemoteInteractiveLogonRight';

    private const MIGRATION = 'database/migrations/2026_07_04_140000_seed_capability_rdp_denied_for_group.php';

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
            ->where('mechanism', 'privilege')
            ->first();

        return json_decode((string) $projection->spec, true, 512, JSON_THROW_ON_ERROR);
    }

    private function setValue(string $value): void
    {
        DB::table('capabilities')->where('key', self::KEY)->update(['default_value' => $value]);
    }

    private function items(): \Illuminate\Support\Collection
    {
        return (new PrivilegeCapabilityProvider())->itemsFor(TargetContext::for($this->ws, null));
    }

    private function seedEleves(): void
    {
        UserGroup::factory()->create(['name' => 'Eleves', 'type' => 'role']);
    }

    // ── Seed : options / défaut / warning / projection ────────────────────

    #[Test]
    public function seed_creates_the_capability_with_enum_options_default_and_warning(): void
    {
        $cap = $this->capabilityRow();
        self::assertNotNull($cap, 'la migration doit avoir seedé la capacité');
        self::assertSame('enum', $cap->value_type);
        self::assertSame('unmanaged', $cap->default_value);
        self::assertNotEmpty($cap->warning, 'mécanisme de refus par nature ⇒ warning non vide');
        self::assertStringContainsString('logon', (string) $cap->warning, 'le warning documente l\'effet au logon suivant');
        self::assertLessThanOrEqual(255, mb_strlen((string) $cap->description), 'varchar PG 255 (piège 22001, invisible en SQLite)');

        $options = json_decode((string) $cap->options, true);
        $values = array_column($options, 'value');
        self::assertSame(['unmanaged', 'eleves', 'off'], $values);
        // Convention « sujet + état » : « Non géré » réservé à la sentinelle.
        $byValue = array_column($options, 'label', 'value');
        self::assertSame('Non géré', $byValue['unmanaged']);
        self::assertSame('RDP refusé aux élèves', $byValue['eleves']);
        self::assertSame('RDP autorisé (droit retiré)', $byValue['off']);
    }

    #[Test]
    public function projection_carries_the_sedeny_rdp_privilege_and_the_value_map(): void
    {
        $spec = $this->spec();
        self::assertSame(self::RDP_DENY, $spec['privilege']);
        // Map de valeur : `unmanaged` ABSENT (sentinelle), `eleves` = jeton
        // @eleves (résolu à l'expansion), `off` = [] (privilège vidé).
        self::assertSame(['eleves', 'off'], array_keys($spec['accounts']));
        self::assertSame(['@eleves'], $spec['accounts']['eleves']);
        self::assertSame([], $spec['accounts']['off']);
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
            ->where('mechanism', 'privilege')
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
    public function value_eleves_emits_one_item_denying_rdp_to_the_eleves_group(): void
    {
        $this->seedEleves();
        $this->setValue('eleves');

        $items = $this->items();
        self::assertCount(1, $items);
        $payload = $items->first()->payload;
        self::assertSame(self::RDP_DENY, $payload['privilege']);
        self::assertSame(['Eleves'], $payload['accounts'], '@eleves résolu vers le nom conventionnel');
    }

    #[Test]
    public function value_off_emits_one_item_with_an_empty_accounts_list(): void
    {
        $this->seedEleves();
        $this->setValue('off');

        $items = $this->items();
        self::assertCount(1, $items, 'off RÉEL : item émis (le handler VIDE le privilège — RDP rétabli au logon suivant)');
        self::assertSame([], $items->first()->payload['accounts']);
        self::assertSame(self::RDP_DENY, $items->first()->payload['privilege']);
    }

    #[Test]
    public function value_unmanaged_emits_nothing(): void
    {
        $this->seedEleves();
        $this->setValue('unmanaged');
        self::assertCount(0, $this->items(), 'sentinelle unmanaged ⇒ rien émis');
    }

    #[Test]
    public function missing_eleves_group_suppresses_the_item_and_warns(): void
    {
        // Groupe `Eleves` ABSENT de user_groups : le jeton @eleves est
        // irrésoluble → item ENTIER non émis + warning loggé (jamais de payload
        // avec un jeton brut, jamais de liste partielle).
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
            ->where('capability_projections.mechanism', 'privilege')
            ->get(['capabilities.key', 'capabilities.warning', 'capability_projections.spec'])
            ->map(fn ($r): array => [
                'capability' => $r->key,
                'warning' => $r->warning,
                'spec' => json_decode((string) $r->spec, true),
            ])
            ->all();

        self::assertNotEmpty($projections, 'le catalogue seedé porte au moins une projection privilege');
        $violations = (new PrivilegeAuthoringGuard())->violations($projections);
        self::assertSame([], $violations, 'le catalogue seedé passe le guard d\'authoring');
    }

    #[Test]
    public function authoring_guard_refuses_a_fabricated_grant_combo(): void
    {
        // Combo interdit fabriqué : droit *grant* — une convergence « possède
        // la liste entière » sur SeRemoteInteractiveLogonRight révoquerait le
        // droit de session RDP à TOUT LE MONDE (machine verrouillée, piège #3).
        $violations = (new PrivilegeAuthoringGuard())->violations([[
            'capability' => 'rogue',
            'warning' => 'w',
            'spec' => [
                'privilege' => 'SeRemoteInteractiveLogonRight',
                'accounts' => ['eleves' => ['@eleves'], 'off' => []],
            ],
        ]]);

        self::assertNotEmpty($violations, 'un droit grant doit être refusé (SeDeny*-only)');
    }
}
