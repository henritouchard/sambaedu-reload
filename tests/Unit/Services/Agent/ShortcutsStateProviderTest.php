<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateMode;
use App\Enums\StateScope;
use App\Enums\WorkstationEnvironment;
use App\Models\Shortcut;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Providers\ShortcutsStateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use App\Services\Agent\WorkstationEnvironmentResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `ShortcutsStateProvider` — Story 27.1 (AC1, AC2, AC3).
 *
 * Mailles (poste/parc/user/groupes user via pivot SQL), union sans précédence,
 * payload v1, résolution du chemin du bureau par environnement (fix Bug C),
 * lecture seule, ZÉRO AD. Le ciblage AD-CN legacy (`ad_users`/`ad_user_groups`)
 * n'est JAMAIS lu (NFR7, décision n° 8).
 */
class ShortcutsStateProviderTest extends TestCase
{
    use RefreshDatabase;

    private ShortcutsStateProvider $provider;

    private Workstation $ws;

    private WorkstationGroup $room;

    private WorkstationGroup $parc;

    private User $user;

    private UserGroup $userGroup;

    protected function setUp(): void
    {
        parent::setUp();
        // Le ciblage est Postgres-pur : aucune raison de déclencher la synchro
        // AD des groupes/users (host sans LDAP). Iso discipline NFR7.
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();

        $this->provider = new ShortcutsStateProvider(new WorkstationEnvironmentResolver());
        $this->ws = Workstation::factory()->create();
        $this->room = WorkstationGroup::factory()->create();
        $this->parc = WorkstationGroup::factory()->logical()->create();
        $this->ws->groups()->attach([$this->room->id, $this->parc->id]);
        $this->user = User::factory()->create();
        $this->userGroup = UserGroup::factory()->create();
        $this->user->groups()->attach($this->userGroup->id);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    #[Test]
    public function declares_frozen_type_and_constants(): void
    {
        self::assertSame('shortcuts', $this->provider->type());
        self::assertSame(ResourceSemantics::Aggregate, $this->provider->semantics());
        self::assertSame(StateMode::Strict, $this->provider->mode());
        self::assertSame(StateScope::MachineUser, $this->provider->scope());
    }

    #[Test]
    public function assignments_are_labeled_by_their_maille(): void
    {
        $this->assign($this->shortcut('room'), WorkstationGroup::class, $this->room->id);
        $this->assign($this->shortcut('parc'), WorkstationGroup::class, $this->parc->id);
        $this->assign($this->shortcut('ws'), Workstation::class, $this->ws->id);
        $this->assign($this->shortcut('ug'), UserGroup::class, $this->userGroup->id);
        $this->assign($this->shortcut('u'), User::class, $this->user->id);

        $mailles = $this->provider->itemsFor($this->ctx())
            ->map(fn (StateCandidate $c): string => $c->maille->value);

        self::assertEqualsCanonicalizing([
            StateMaille::PhysicalGroup->value,
            StateMaille::LogicalGroup->value,
            StateMaille::Workstation->value,
            StateMaille::UserGroup->value,
            StateMaille::User->value,
        ], $mailles->all());
    }

    #[Test]
    public function unions_all_applicable_mailles_without_precedence(): void
    {
        $this->assign($this->shortcut('a'), WorkstationGroup::class, $this->room->id);
        $this->assign($this->shortcut('b'), Workstation::class, $this->ws->id);
        $this->assign($this->shortcut('c'), User::class, $this->user->id);

        $candidates = $this->provider->itemsFor($this->ctx());

        self::assertCount(3, $candidates, 'aggregate = union, le provider étiquette sans trancher (D2 = compilateur)');
    }

    #[Test]
    public function rules_outside_the_context_are_not_returned(): void
    {
        $otherRoom = WorkstationGroup::factory()->create();
        $otherUser = User::factory()->create();
        $otherWs = Workstation::factory()->create();
        $this->assign($this->shortcut('x'), WorkstationGroup::class, $otherRoom->id);
        $this->assign($this->shortcut('y'), User::class, $otherUser->id);
        $this->assign($this->shortcut('z'), Workstation::class, $otherWs->id);

        self::assertCount(0, $this->provider->itemsFor($this->ctx()));
    }

    #[Test]
    public function inactive_shortcuts_are_excluded(): void
    {
        $inactive = $this->shortcut('inactive', ['is_active' => false]);
        $this->assign($inactive, Workstation::class, $this->ws->id);

        self::assertCount(0, $this->provider->itemsFor($this->ctx()));
    }

    #[Test]
    public function desktop_payload_carries_network_path_when_shared_local(): void
    {
        // shared_local (défaut) → bureau RÉSEAU (pansement Bug C, mais piloté).
        $sc = $this->shortcut('intranet', [
            'place' => Shortcut::PLACE_DESKTOP,
            'windows_link' => 'https://intranet.edu',
            'windows_args' => '--kiosk',
            'windows_icon' => 'C:\\icons\\i.ico',
        ]);
        $this->assign($sc, Workstation::class, $this->ws->id);

        $payload = $this->provider->itemsFor($this->ctx())->first()->payload;

        self::assertSame([
            'name' => 'intranet',
            'target' => 'https://intranet.edu',
            'args' => '--kiosk',
            'icon' => 'C:\\icons\\i.ico',
            'place' => 'desktop',
            'desktop_path' => '\\\\<se4fs>\\users\\<user>\\Bureau\\',
        ], $payload);
    }

    #[Test]
    public function desktop_payload_carries_local_path_when_personal_local(): void
    {
        // Le parc déclare personal_local → bureau LOCAL (fix Bug C : plus de
        // branche figée, c'est la donnée du domaine qui dicte le chemin).
        $this->parc->update(['environment' => WorkstationEnvironment::PersonalLocal]);
        $sc = $this->shortcut('notes', ['place' => Shortcut::PLACE_DESKTOP, 'windows_link' => 'C:\\app.exe']);
        $this->assign($sc, Workstation::class, $this->ws->id);

        $payload = $this->provider->itemsFor($this->ctx())->first()->payload;

        self::assertSame('%USERPROFILE%\\Desktop\\', $payload['desktop_path']);
    }

    #[Test]
    public function non_desktop_places_have_no_desktop_path(): void
    {
        $sc = $this->shortcut('boot', ['place' => Shortcut::PLACE_STARTUP, 'windows_link' => 'C:\\b.exe']);
        $this->assign($sc, Workstation::class, $this->ws->id);

        $payload = $this->provider->itemsFor($this->ctx())->first()->payload;

        self::assertArrayNotHasKey('desktop_path', $payload);
        self::assertSame('startup', $payload['place']);
    }

    #[Test]
    public function mode_is_read_per_rule_from_the_table(): void
    {
        $strict = $this->shortcut('strict', ['mode' => StateMode::Strict]);
        $default = $this->shortcut('lax', ['mode' => StateMode::Default]);
        $unset = $this->shortcut('unset'); // mode null
        $this->assign($strict, Workstation::class, $this->ws->id);
        $this->assign($default, Workstation::class, $this->ws->id);
        $this->assign($unset, Workstation::class, $this->ws->id);

        $byName = $this->provider->itemsFor($this->ctx())
            ->keyBy(fn (StateCandidate $c): string => $c->payload['name']);

        self::assertSame(StateMode::Strict, $byName['strict']->mode);
        self::assertSame(StateMode::Default, $byName['lax']->mode);
        self::assertNull($byName['unset']->mode, 'null = défaut résolu côté compilateur');
    }

    #[Test]
    public function null_user_returns_no_user_maille_candidates(): void
    {
        $this->assign($this->shortcut('room'), WorkstationGroup::class, $this->room->id);
        $this->assign($this->shortcut('ug'), UserGroup::class, $this->userGroup->id);
        $this->assign($this->shortcut('u'), User::class, $this->user->id);

        $candidates = $this->provider->itemsFor(TargetContext::for($this->ws, null));

        self::assertCount(1, $candidates);
        self::assertSame(StateMaille::PhysicalGroup, $candidates->first()->maille);
    }

    #[Test]
    public function ad_cn_targeting_is_never_read(): void
    {
        // Une règle ciblée UNIQUEMENT par CN AD legacy (ad_users) — INTERDIT
        // NFR7 — ne doit JAMAIS produire de candidat (le provider lit le pivot
        // SQL seulement, décision n° 8).
        $sc = $this->shortcut('ad-only', [
            'ad_users' => [$this->user->login ?? 'someone'],
            'ad_user_groups' => ['Profs'],
        ]);
        // Aucune assignation pivot SQL : la seule cible est AD-CN.

        self::assertCount(0, $this->provider->itemsFor($this->ctx()));
    }

    /**
     * @param  array<string,mixed>  $attrs
     */
    private function shortcut(string $name, array $attrs = []): Shortcut
    {
        return Shortcut::create(array_merge([
            'key' => $name . '-' . uniqid(),
            'name' => $name,
            'place' => Shortcut::PLACE_DESKTOP,
            'is_active' => true,
            'windows_link' => 'C:\\app.exe',
        ], $attrs));
    }

    /**
     * Insère une ligne du pivot polymorphe `shortcut_assignables` (le morph
     * accepte tout modèle SQL — WorkstationGroup, Workstation, UserGroup,
     * User : ciblage MVP pivot SQL, décision n° 8).
     */
    private function assign(Shortcut $shortcut, string $type, int $id): void
    {
        \Illuminate\Support\Facades\DB::table('shortcut_assignables')->insert([
            'shortcut_id' => $shortcut->id,
            'assignable_type' => $type,
            'assignable_id' => $id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ctx(): TargetContext
    {
        return TargetContext::for($this->ws, $this->user);
    }
}
