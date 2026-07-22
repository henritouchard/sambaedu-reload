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
use App\Services\Agent\Providers\AppProfileCapabilityProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use App\Services\FilePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 36.5 — Tests Unit du provider `app_profile` : redirection du profil
 * applicatif vers le home réseau (contrat §7.11). Portée SESSION, maille User,
 * chemin serveur en TOKEN, gate `FilePolicyService['home']`, patron
 * {@see \App\Services\Agent\Providers\DrivesStateProvider}.
 */
class AppProfileCapabilityProviderTest extends TestCase
{
    use RefreshDatabase;

    private Workstation $ws;

    private User $user;

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
        $this->user = User::factory()->create(['login' => 'alice']);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    private function provider(): AppProfileCapabilityProvider
    {
        return new AppProfileCapabilityProvider();
    }

    private function ctx(): TargetContext
    {
        return TargetContext::for($this->ws, $this->user);
    }

    /**
     * @param  list<array<string,mixed>>  $apps
     */
    private function seedCatalog(
        array $apps,
        bool $active = true,
        string $key = 'roaming_app_profile',
        string $defaultValue = 'on',
    ): Capability {
        $cap = Capability::factory()->create([
            'key' => $key,
            'is_active' => $active,
            'value_type' => 'toggle',
            'default_value' => $defaultValue,
        ]);
        CapabilityProjection::factory()->for($cap)->create([
            'os' => 'windows',
            'mechanism' => CapabilityProjection::MECHANISM_APP_PROFILE,
            'spec' => ['apps' => $apps],
        ]);

        return $cap;
    }

    /** Insère une assignation de capacité (pivot polymorphe). */
    private function assign(int $capabilityId, string $assignableType, int $assignableId, ?string $value): void
    {
        DB::table('capability_assignments')->insert([
            'capability_id' => $capabilityId,
            'assignable_type' => $assignableType,
            'assignable_id' => $assignableId,
            'value' => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function firefoxThunderbird(): array
    {
        return [
            [
                'app' => 'firefox',
                'link' => 'AppData\\Roaming\\Mozilla\\Firefox\\managed.default',
                'server' => '.mozilla\\firefox\\managed.default',
                'profile_name' => 'managed.default',
                'install_hash' => '308046B0AF4A39CB',
                'cache_local' => 'cacheFirefox',
            ],
            [
                'app' => 'thunderbird',
                'link' => 'AppData\\Roaming\\Thunderbird\\managed.default',
                'server' => '.thunderbird\\Profiles\\managed.default',
                'profile_name' => 'managed.default',
                'install_hash' => 'D78BF5DD33499EC2',
                'cache_local' => 'cacheThunderbird',
            ],
        ];
    }

    #[Test]
    public function declares_app_profile_aggregate_session(): void
    {
        $p = $this->provider();
        self::assertSame('app_profile', $p->type());
        self::assertSame(ResourceSemantics::Aggregate, $p->semantics());
        self::assertSame(StateScope::Session, $p->scope());
    }

    #[Test]
    public function emits_one_item_per_catalog_app_with_user_maille(): void
    {
        $this->seedCatalog($this->firefoxThunderbird());

        $items = $this->provider()->itemsFor($this->ctx());

        self::assertCount(2, $items);
        foreach ($items as $c) {
            self::assertInstanceOf(StateCandidate::class, $c);
            self::assertSame(StateMaille::User, $c->maille);
        }
        self::assertSame(
            ['firefox', 'thunderbird'],
            $items->map(fn (StateCandidate $c): string => $c->payload['app'])->all(),
        );
    }

    #[Test]
    public function server_path_is_a_token_never_resolved_server_side(): void
    {
        $this->seedCatalog([$this->firefoxThunderbird()[0]]);

        $payload = $this->provider()->itemsFor($this->ctx())->first()->payload;

        // Chemin serveur = TOKEN home + relatif du catalogue (AC3).
        self::assertSame(
            '\\\\<se4fs>\\users\\<user>\\.mozilla\\firefox\\managed.default',
            $payload['server'],
        );
        self::assertStringContainsString('<se4fs>', $payload['server']);
        self::assertStringContainsString('<user>', $payload['server']);
        // `link` relatif au profil Windows, verbatim.
        self::assertSame('AppData\\Roaming\\Mozilla\\Firefox\\managed.default', $payload['link']);
        self::assertSame('managed.default', $payload['profile_name']);
    }

    #[Test]
    public function install_hash_and_cache_local_are_optional(): void
    {
        $this->seedCatalog([[
            'app' => 'firefox',
            'link' => 'AppData\\Roaming\\Mozilla\\Firefox\\managed.default',
            'server' => '.mozilla\\firefox\\managed.default',
            'profile_name' => 'managed.default',
        ]]);

        $payload = $this->provider()->itemsFor($this->ctx())->first()->payload;

        self::assertSame(['app', 'link', 'server', 'profile_name'], array_keys($payload));
        self::assertArrayNotHasKey('install_hash', $payload);
        self::assertArrayNotHasKey('cache_local', $payload);
    }

    #[Test]
    public function machine_only_context_returns_no_items(): void
    {
        $this->seedCatalog($this->firefoxThunderbird());

        // user null → aucun profil (dépend du login de session).
        self::assertCount(0, $this->provider()->itemsFor(TargetContext::for($this->ws, null)));
    }

    #[Test]
    public function home_policy_disabled_still_emits_items(): void
    {
        // Story 36.7 (AC3) — DÉCORRÉLATION du gate K: : le lien pointe l'UNC
        // direct (K: cosmétique), couper le home ne coupe plus la redirection.
        $this->seedCatalog($this->firefoxThunderbird());

        FilePolicyService::setGlobal(false, true, false); // home coupé

        self::assertCount(2, $this->provider()->itemsFor($this->ctx()),
            'AC3 : home coupé ⇒ items TOUJOURS émis (gate K: supprimé)');
    }

    #[Test]
    public function default_instance_value_on_emits_items_without_any_assignment(): void
    {
        // Story 36.7 (AC4) — défaut d'instance = default_value `on` : un
        // utilisateur couvert par AUCUNE assignation reçoit les items.
        $this->seedCatalog($this->firefoxThunderbird(), defaultValue: 'on');

        self::assertCount(2, $this->provider()->itemsFor($this->ctx()));
    }

    #[Test]
    public function disabled_catalog_entry_is_filtered(): void
    {
        // Story 36.7 (AC2) — « off réel » par entrée : `enabled:false` n'émet plus.
        $apps = $this->firefoxThunderbird();
        $apps[1]['enabled'] = false; // thunderbird désactivé.
        $apps[0]['enabled'] = true;
        $this->seedCatalog($apps);

        $items = $this->provider()->itemsFor($this->ctx());
        self::assertCount(1, $items);
        self::assertSame('firefox', $items->first()->payload['app']);
    }

    #[Test]
    public function default_instance_value_off_suppresses_without_assignment(): void
    {
        // Story 36.7 (AC4) — basculer default_value à `off` inverse la politique
        // sans code : aucune assignation ⇒ aucun item.
        $this->seedCatalog($this->firefoxThunderbird(), defaultValue: 'off');

        self::assertCount(0, $this->provider()->itemsFor($this->ctx()));
    }

    #[Test]
    public function user_group_assignment_on_emits_items(): void
    {
        // Story 36.7 (AC4) — défaut off, mais un groupe d'utilisateurs assigné `on`
        // active la redirection pour ses membres.
        $cap = $this->seedCatalog($this->firefoxThunderbird(), defaultValue: 'off');

        $group = UserGroup::create(['name' => 'classe-a', 'type' => 'classe']);
        $this->user->groups()->attach($group->id);
        $this->assign($cap->id, UserGroup::class, $group->id, 'on');

        self::assertCount(2, $this->provider()->itemsFor($this->ctx()));
    }

    #[Test]
    public function user_group_assignment_off_suppresses_items(): void
    {
        // Story 36.7 (AC4) — défaut on, mais le groupe est assigné `off` : couvert
        // UNIQUEMENT par du off ⇒ exclu.
        $cap = $this->seedCatalog($this->firefoxThunderbird(), defaultValue: 'on');

        $group = UserGroup::create(['name' => 'classe-b', 'type' => 'classe']);
        $this->user->groups()->attach($group->id);
        $this->assign($cap->id, UserGroup::class, $group->id, 'off');

        self::assertCount(0, $this->provider()->itemsFor($this->ctx()));
    }

    #[Test]
    public function any_user_group_on_wins_over_off_or_semantics(): void
    {
        // Story 36.7 (AC4) — sémantique OR : couvert par au moins UNE assignation
        // `on` ⇒ items émis, même si un autre groupe est `off`.
        $cap = $this->seedCatalog($this->firefoxThunderbird(), defaultValue: 'off');

        $groupOff = UserGroup::create(['name' => 'groupe-off', 'type' => 'classe']);
        $groupOn = UserGroup::create(['name' => 'groupe-on', 'type' => 'classe']);
        $this->user->groups()->attach([$groupOff->id, $groupOn->id]);
        $this->assign($cap->id, UserGroup::class, $groupOff->id, 'off');
        $this->assign($cap->id, UserGroup::class, $groupOn->id, 'on');

        self::assertCount(2, $this->provider()->itemsFor($this->ctx()));
    }

    #[Test]
    public function user_maille_assignment_decides_over_group_precedence(): void
    {
        // Story 36.7 (AC4) — précédence specificity() : une assignation User (rang 0)
        // décide seule, même si le groupe dit l'inverse.
        $cap = $this->seedCatalog($this->firefoxThunderbird(), defaultValue: 'off');

        $group = UserGroup::create(['name' => 'classe-c', 'type' => 'classe']);
        $this->user->groups()->attach($group->id);
        $this->assign($cap->id, UserGroup::class, $group->id, 'off');
        $this->assign($cap->id, User::class, $this->user->id, 'on');

        self::assertCount(2, $this->provider()->itemsFor($this->ctx()),
            'User `on` bat le groupe `off` (rang 0 > rang 1)');
    }

    #[Test]
    public function inactive_capability_emits_nothing(): void
    {
        $this->seedCatalog($this->firefoxThunderbird(), active: false);

        self::assertCount(0, $this->provider()->itemsFor($this->ctx()));
    }

    #[Test]
    public function no_capability_emits_nothing(): void
    {
        self::assertCount(0, $this->provider()->itemsFor($this->ctx()));
    }

    #[Test]
    public function duplicate_link_across_capabilities_emits_one_item_and_warns(): void
    {
        // C3 (post-review) : deux capacités actives ciblant le MÊME lien (casse
        // différente = même chemin Windows) → dédup PREMIER GAGNANT + warning.
        // Le provider journalise via Log::channel('agent')->warning(...).
        \Illuminate\Support\Facades\Log::shouldReceive('channel')->with('agent')->andReturnSelf();
        \Illuminate\Support\Facades\Log::shouldReceive('warning')->atLeast()->once();

        $firefox = [
            'app' => 'firefox',
            'link' => 'AppData\\Roaming\\Mozilla\\Firefox\\managed.default',
            'server' => '.mozilla\\firefox\\managed.default',
            'profile_name' => 'managed.default',
        ];
        $this->seedCatalog([$firefox], key: 'roaming_app_profile');

        $firefoxLowercaseLink = $firefox;
        $firefoxLowercaseLink['link'] = 'appdata\\roaming\\mozilla\\firefox\\managed.default';
        $this->seedCatalog([$firefoxLowercaseLink], key: 'roaming_app_profile_b');

        $items = $this->provider()->itemsFor($this->ctx());

        // Un SEUL item émis (premier gagnant), collision journalisée (warning
        // asserté via l'attente Mockery ci-dessus).
        self::assertCount(1, $items);
    }

    #[Test]
    public function incomplete_catalog_entry_is_skipped(): void
    {
        $this->seedCatalog([
            ['app' => 'firefox', 'link' => 'AppData\\Roaming\\Mozilla\\Firefox\\managed.default',
                'server' => '.mozilla\\firefox\\managed.default', 'profile_name' => 'managed.default'],
            ['app' => 'broken'], // manque link/server/profile_name
        ]);

        $items = $this->provider()->itemsFor($this->ctx());
        self::assertCount(1, $items);
        self::assertSame('firefox', $items->first()->payload['app']);
    }
}
