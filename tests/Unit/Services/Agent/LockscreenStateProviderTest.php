<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Wallpaper;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\Agent\Providers\LockscreenStateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `LockscreenStateProvider` — fond de l'écran de verrouillage
 * (type `lockscreen`, portée machine).
 *
 * Pendant pré-login du {@see WallpaperStateProvider} : owners défaut étab +
 * WorkstationGroup SEULEMENT (jamais User/UserGroup — pas de session au
 * verrouillage), règles `type = 'wallpaper'` ignorées, règle sans asset =
 * payload null explicite.
 */
class LockscreenStateProviderTest extends TestCase
{
    use RefreshDatabase;

    private LockscreenStateProvider $provider;

    private Workstation $ws;

    private WorkstationGroup $room;

    private WorkstationGroup $parc;

    private User $user;

    private UserGroup $userGroup;

    protected function setUp(): void
    {
        parent::setUp();
        // Projection Postgres-pure : aucune synchro AD (host sans LDAP, NFR7).
        \App\Observers\WorkstationGroupObserver::disableSync();
        \App\Observers\UserGroupObserver::disableSync();
        \App\Observers\UserGroupUserPivotObserver::disableSync();

        $this->provider = new LockscreenStateProvider();
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
        \App\Observers\WorkstationGroupObserver::enableSync();
        \App\Observers\UserGroupObserver::enableSync();
        \App\Observers\UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    #[Test]
    public function declares_frozen_type_scope_and_semantics(): void
    {
        self::assertSame('lockscreen', $this->provider->type());
        self::assertSame(ResourceSemantics::Exclusive, $this->provider->semantics());
        self::assertSame(StateScope::Machine, $this->provider->scope());
    }

    #[Test]
    public function broadcast_default_yields_broadcast_candidate_with_asset_payload(): void
    {
        $lockscreen = Wallpaper::factory()->lockscreen()->default()->create();
        $asset = $lockscreen->asset;

        $candidates = $this->provider->itemsFor($this->ctx());

        self::assertCount(1, $candidates);
        $candidate = $candidates->first();
        self::assertSame(StateMaille::Broadcast, $candidate->maille);
        self::assertSame(
            ['asset' => $asset->filename, 'checksum' => $asset->checksum],
            $candidate->payload,
        );
        self::assertSame($lockscreen->id, $candidate->sourceId);
        self::assertNotNull($candidate->updatedAt);
    }

    #[Test]
    public function workstation_group_owner_is_labeled_physical_or_logical(): void
    {
        Wallpaper::factory()->lockscreen()->create(['owner_type' => WorkstationGroup::class, 'owner_id' => $this->room->id]);
        Wallpaper::factory()->lockscreen()->create(['owner_type' => WorkstationGroup::class, 'owner_id' => $this->parc->id]);

        $mailles = $this->provider->itemsFor($this->ctx())
            ->map(fn (StateCandidate $c): string => $c->maille->value);

        self::assertEqualsCanonicalizing(
            [StateMaille::PhysicalGroup->value, StateMaille::LogicalGroup->value],
            $mailles->all(),
        );
    }

    #[Test]
    public function user_and_user_group_owners_are_never_returned(): void
    {
        // L'écran de verrouillage est pré-login : aucune contribution user, même
        // si une règle lockscreen ciblait un user/usergroup du contexte.
        Wallpaper::factory()->lockscreen()->create(['owner_type' => UserGroup::class, 'owner_id' => $this->userGroup->id]);
        Wallpaper::factory()->lockscreen()->create(['owner_type' => User::class, 'owner_id' => $this->user->id]);

        self::assertCount(0, $this->provider->itemsFor($this->ctx()));
    }

    #[Test]
    public function wallpaper_rules_are_ignored(): void
    {
        // Le pendant `wallpaper` (fond de bureau) ne remonte PAS ici.
        Wallpaper::factory()->default()->create();
        Wallpaper::factory()->create(['owner_type' => WorkstationGroup::class, 'owner_id' => $this->room->id]);

        self::assertCount(0, $this->provider->itemsFor($this->ctx()));
    }

    #[Test]
    public function rules_outside_the_context_are_not_returned(): void
    {
        $otherRoom = WorkstationGroup::factory()->create();
        Wallpaper::factory()->lockscreen()->create(['owner_type' => WorkstationGroup::class, 'owner_id' => $otherRoom->id]);
        // Orphelin : owner null SANS is_default → pas une règle broadcast.
        Wallpaper::factory()->lockscreen()->create(['owner_type' => null, 'owner_id' => null, 'is_default' => false]);

        self::assertCount(0, $this->provider->itemsFor($this->ctx()));
    }

    #[Test]
    public function rule_without_asset_yields_explicit_null_payload_not_absence(): void
    {
        Wallpaper::factory()->lockscreen()->default()->create(['asset_id' => null]);

        $candidates = $this->provider->itemsFor($this->ctx());

        self::assertCount(1, $candidates);
        self::assertSame(
            ['asset' => null, 'checksum' => null],
            $candidates->first()->payload,
            'règle explicite « pas de fond de verrouillage imposé » (contrat §8)',
        );
    }

    #[Test]
    public function machine_only_context_without_user_still_serves_broadcast_and_group(): void
    {
        Wallpaper::factory()->lockscreen()->default()->create();
        Wallpaper::factory()->lockscreen()->create(['owner_type' => WorkstationGroup::class, 'owner_id' => $this->room->id]);

        // Aucun user (verrouillage = pré-login) : les mailles machine sortent.
        $candidates = $this->provider->itemsFor(TargetContext::for($this->ws, null));

        self::assertCount(2, $candidates, 'le provider étiquette, il ne tranche pas (D2 = compilateur)');
    }

    private function ctx(): TargetContext
    {
        return TargetContext::for($this->ws, $this->user);
    }
}
