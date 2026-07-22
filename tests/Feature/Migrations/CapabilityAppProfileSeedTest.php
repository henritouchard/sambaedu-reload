<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\User;
use App\Models\Workstation;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Providers\AppProfileAuthoringGuard;
use App\Services\Agent\Providers\AppProfileCapabilityProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use App\Services\FilePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 36.5 (AC8) — seed de PREUVE `roaming_app_profile` (catalogue Firefox +
 * Thunderbird) + intégration provider sur données RÉELLES + invariant
 * `AppProfileAuthoringGuard` sur le catalogue seedé.
 *
 * FICHIER DÉDIÉ : la migration de seed est jouée par `RefreshDatabase`.
 */
class CapabilityAppProfileSeedTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'roaming_app_profile';

    private const MIGRATION = 'database/migrations/2026_07_21_120000_seed_capability_app_profile.php';

    private Workstation $ws;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();

        $this->ws = Workstation::factory()->create();
        $this->user = User::factory()->create(['login' => 'alice']);
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

    private function items(): \Illuminate\Support\Collection
    {
        return (new AppProfileCapabilityProvider())->itemsFor(TargetContext::for($this->ws, $this->user));
    }

    #[Test]
    public function seed_creates_the_active_capability_within_varchar_limits(): void
    {
        $cap = $this->capabilityRow();
        self::assertNotNull($cap, 'la migration doit avoir seedé la capacité');
        self::assertSame('1', (string) $cap->is_active);
        self::assertLessThanOrEqual(255, mb_strlen((string) $cap->description));
        self::assertLessThanOrEqual(255, mb_strlen((string) $cap->label));
        self::assertNotEmpty($cap->warning, 'la dépendance au home K: est signalée (AC7)');
    }

    #[Test]
    public function catalog_holds_firefox_and_thunderbird(): void
    {
        $cap = $this->capabilityRow();
        $projection = DB::table('capability_projections')
            ->where('capability_id', $cap->id)
            ->where('mechanism', 'app_profile')
            ->first();
        $spec = json_decode((string) $projection->spec, true, 512, JSON_THROW_ON_ERROR);

        $apps = array_column($spec['apps'], 'app');
        self::assertSame(['firefox', 'thunderbird'], $apps);
        // Aucun profil bâti sur le radical sambaedu (AC4).
        foreach ($spec['apps'] as $app) {
            self::assertStringNotContainsStringIgnoringCase('sambaedu', (string) $app['profile_name']);
            self::assertSame('managed.default', $app['profile_name']);
        }
    }

    #[Test]
    public function provider_emits_two_token_items_on_seeded_catalog(): void
    {
        $items = $this->items();
        self::assertCount(2, $items);
        self::assertSame(
            ['firefox', 'thunderbird'],
            $items->map(fn (StateCandidate $c): string => $c->payload['app'])->all(),
        );
        // Chemins serveur en TOKEN (jamais résolus côté serveur).
        foreach ($items as $c) {
            self::assertStringStartsWith('\\\\<se4fs>\\users\\<user>\\', $c->payload['server']);
        }
    }

    #[Test]
    public function seeded_capability_ignores_file_policy_home(): void
    {
        // Story 36.7 (AC3) — le gate K: est SUPPRIMÉ : home coupé ⇒ items émis.
        FilePolicyService::setGlobal(false, true, false); // home coupé
        self::assertCount(2, $this->items(), 'AC3 : home coupé ⇒ items TOUJOURS émis');
    }

    #[Test]
    public function upgrade_migration_adds_enabled_options_and_decorrelates_warning(): void
    {
        // Story 36.7 — la migration d'upgrade (jouée par RefreshDatabase) : chaque
        // entrée porte `enabled:true` (AC2), la capacité gagne ses options on/off
        // (AC4) et son warning ne mentionne PLUS la dépendance au home K: (AC3).
        $cap = $this->capabilityRow();

        $options = json_decode((string) $cap->options, true);
        self::assertSame(
            ['on', 'off'],
            array_column($options, 'value'),
            'AC4 : options toggle on/off ajoutées',
        );
        self::assertStringNotContainsStringIgnoringCase(
            'si le home est désactivé',
            (string) $cap->warning,
            'AC3 : warning décorrélé du gate K:',
        );
        self::assertLessThanOrEqual(255, mb_strlen((string) $cap->warning));

        $projection = DB::table('capability_projections')
            ->where('capability_id', $cap->id)
            ->where('mechanism', 'app_profile')
            ->first();
        $spec = json_decode((string) $projection->spec, true, 512, JSON_THROW_ON_ERROR);
        foreach ($spec['apps'] as $app) {
            self::assertTrue($app['enabled'], 'AC2 : chaque entrée porte enabled:true');
            self::assertIsBool($app['enabled'], 'AC2 : enabled est un booléen strict');
        }
    }

    #[Test]
    public function migration_is_idempotent_and_reversible(): void
    {
        $migration = require base_path(self::MIGRATION);

        $migration->up();
        self::assertSame(1, DB::table('capabilities')->where('key', self::KEY)->count());

        $migration->down();
        self::assertNull($this->capabilityRow());

        $migration->up();
        self::assertNotNull($this->capabilityRow());
    }

    #[Test]
    public function authoring_guard_passes_on_the_seeded_catalog(): void
    {
        $projections = DB::table('capability_projections')
            ->join('capabilities', 'capabilities.id', '=', 'capability_projections.capability_id')
            ->where('capability_projections.mechanism', 'app_profile')
            ->get(['capabilities.key', 'capabilities.warning', 'capability_projections.spec'])
            ->map(fn ($r): array => [
                'capability' => $r->key,
                'warning' => $r->warning,
                'spec' => json_decode((string) $r->spec, true),
            ])
            ->all();

        self::assertNotEmpty($projections);
        self::assertSame([], (new AppProfileAuthoringGuard())->violations($projections));
    }
}
