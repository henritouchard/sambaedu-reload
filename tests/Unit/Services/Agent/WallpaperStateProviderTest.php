<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateMode;
use App\Enums\StateScope;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Wallpaper;
use App\Models\WallpaperAsset;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\Agent\Providers\WallpaperStateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `WallpaperStateProvider` — Story 23.4 (AC4).
 *
 * Mapping owner → maille, lockscreen ignoré, règle sans asset = payload null
 * explicite, applicabilité au contexte (jamais de précédence : tous les
 * candidats applicables sont retournés, étiquetés — D2 = compilateur).
 */
class WallpaperStateProviderTest extends TestCase
{
    use RefreshDatabase;

    private WallpaperStateProvider $provider;

    private Workstation $ws;

    private WorkstationGroup $room;

    private WorkstationGroup $parc;

    private User $user;

    private UserGroup $userGroup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new WallpaperStateProvider();
        $this->ws = Workstation::factory()->create();
        $this->room = WorkstationGroup::factory()->create();
        $this->parc = WorkstationGroup::factory()->logical()->create();
        $this->ws->groups()->attach([$this->room->id, $this->parc->id]);
        $this->user = User::factory()->create();
        $this->userGroup = UserGroup::factory()->create();
        $this->user->groups()->attach($this->userGroup->id);
    }

    #[Test]
    public function declares_frozen_type_and_constants(): void
    {
        self::assertSame('wallpaper', $this->provider->type());
        self::assertSame(ResourceSemantics::Exclusive, $this->provider->semantics());
        self::assertSame(StateMode::Default, $this->provider->mode());
        self::assertSame(StateScope::Session, $this->provider->scope());
    }

    #[Test]
    public function broadcast_default_yields_broadcast_candidate_with_asset_payload(): void
    {
        $wallpaper = Wallpaper::factory()->default()->create();
        $asset = $wallpaper->asset;

        $candidates = $this->provider->itemsFor($this->ctx());

        self::assertCount(1, $candidates);
        $candidate = $candidates->first();
        self::assertSame(StateMaille::Broadcast, $candidate->maille);
        self::assertSame(
            ['asset' => $asset->filename, 'checksum' => $asset->checksum],
            $candidate->payload,
        );
        self::assertSame($wallpaper->id, $candidate->sourceId);
        self::assertNotNull($candidate->updatedAt);
    }

    #[Test]
    public function workstation_group_owner_is_labeled_physical_or_logical_by_is_physical(): void
    {
        Wallpaper::factory()->create(['owner_type' => WorkstationGroup::class, 'owner_id' => $this->room->id]);
        Wallpaper::factory()->create(['owner_type' => WorkstationGroup::class, 'owner_id' => $this->parc->id]);

        $mailles = $this->provider->itemsFor($this->ctx())
            ->map(fn (StateCandidate $c): string => $c->maille->value);

        self::assertEqualsCanonicalizing(
            [StateMaille::PhysicalGroup->value, StateMaille::LogicalGroup->value],
            $mailles->all(),
        );
    }

    #[Test]
    public function user_group_and_user_owners_are_labeled_with_user_mailles(): void
    {
        Wallpaper::factory()->create(['owner_type' => UserGroup::class, 'owner_id' => $this->userGroup->id]);
        Wallpaper::factory()->create(['owner_type' => User::class, 'owner_id' => $this->user->id]);

        $mailles = $this->provider->itemsFor($this->ctx())
            ->map(fn (StateCandidate $c): string => $c->maille->value);

        self::assertEqualsCanonicalizing(
            [StateMaille::UserGroup->value, StateMaille::User->value],
            $mailles->all(),
        );
    }

    #[Test]
    public function rules_outside_the_context_are_not_returned(): void
    {
        $otherRoom = WorkstationGroup::factory()->create();
        $otherGroup = UserGroup::factory()->create();
        $otherUser = User::factory()->create();
        Wallpaper::factory()->create(['owner_type' => WorkstationGroup::class, 'owner_id' => $otherRoom->id]);
        Wallpaper::factory()->create(['owner_type' => UserGroup::class, 'owner_id' => $otherGroup->id]);
        Wallpaper::factory()->create(['owner_type' => User::class, 'owner_id' => $otherUser->id]);
        // Orphelin : owner null SANS is_default → pas une règle broadcast.
        Wallpaper::factory()->create(['owner_type' => null, 'owner_id' => null, 'is_default' => false]);

        self::assertCount(0, $this->provider->itemsFor($this->ctx()));
    }

    #[Test]
    public function lockscreen_rules_are_ignored(): void
    {
        Wallpaper::factory()->lockscreen()->create([
            'owner_type' => User::class,
            'owner_id' => $this->user->id,
        ]);

        self::assertCount(0, $this->provider->itemsFor($this->ctx()));
    }

    #[Test]
    public function rule_without_asset_yields_explicit_null_payload_not_absence(): void
    {
        Wallpaper::factory()->create([
            'owner_type' => User::class,
            'owner_id' => $this->user->id,
            'asset_id' => null,
        ]);

        $candidates = $this->provider->itemsFor($this->ctx());

        self::assertCount(1, $candidates);
        self::assertSame(
            ['asset' => null, 'checksum' => null],
            $candidates->first()->payload,
            'règle explicite « pas de fond imposé » (contrat §8) — distinct du type absent',
        );
    }

    #[Test]
    public function null_user_returns_no_user_maille_candidates(): void
    {
        Wallpaper::factory()->default()->create();
        Wallpaper::factory()->create(['owner_type' => UserGroup::class, 'owner_id' => $this->userGroup->id]);
        Wallpaper::factory()->create(['owner_type' => User::class, 'owner_id' => $this->user->id]);

        $candidates = $this->provider->itemsFor(TargetContext::for($this->ws, null));

        self::assertCount(1, $candidates);
        self::assertSame(StateMaille::Broadcast, $candidates->first()->maille);
    }

    #[Test]
    public function all_applicable_candidates_are_returned_without_any_precedence(): void
    {
        Wallpaper::factory()->default()->create();
        Wallpaper::factory()->create(['owner_type' => WorkstationGroup::class, 'owner_id' => $this->room->id]);
        Wallpaper::factory()->create(['owner_type' => User::class, 'owner_id' => $this->user->id]);

        $candidates = $this->provider->itemsFor($this->ctx());

        self::assertCount(3, $candidates, 'le provider étiquette, il ne tranche pas (D2 = compilateur)');
    }

    private function ctx(): TargetContext
    {
        return TargetContext::for($this->ws, $this->user);
    }
}
