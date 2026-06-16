<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\Agent\Providers\OverlayMachineStateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `OverlayMachineStateProvider` — Story 27.10 (AC1).
 *
 * Le volet MACHINE de l'overlay : émet `{kind:"machine", room}` en portée
 * machine (source UNIQUE de la salle, retirée de l'item identity session).
 * Émis MÊME en machine-only (préchargement poste+salle au logon).
 */
class OverlayMachineStateProviderTest extends TestCase
{
    use RefreshDatabase;

    private OverlayMachineStateProvider $provider;

    private Workstation $ws;

    private WorkstationGroup $room;

    private WorkstationGroup $parc;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Observers\WorkstationGroupObserver::disableSync();
        \App\Observers\UserGroupUserPivotObserver::disableSync();

        $this->provider = new OverlayMachineStateProvider();
        $this->ws = Workstation::factory()->create();
        $this->room = WorkstationGroup::factory()->create();
        $this->parc = WorkstationGroup::factory()->logical()->create();
        $this->ws->groups()->attach([$this->room->id, $this->parc->id]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        \App\Observers\WorkstationGroupObserver::enableSync();
        \App\Observers\UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    #[Test]
    public function declares_overlay_type_aggregate_and_machine_scope(): void
    {
        self::assertSame('overlay', $this->provider->type());
        self::assertSame(ResourceSemantics::Aggregate, $this->provider->semantics());
        self::assertSame(StateScope::Machine, $this->provider->scope());
    }

    #[Test]
    public function emits_machine_item_with_physical_room_name(): void
    {
        $candidates = $this->provider->itemsFor(TargetContext::for($this->ws, $this->user));

        self::assertCount(1, $candidates);
        $candidate = $candidates->first();
        self::assertSame(StateMaille::Workstation, $candidate->maille);
        self::assertSame(
            [
                'kind' => 'machine',
                // room = nom du WG PHYSIQUE (la salle), jamais le parc logique.
                'room' => $this->room->name,
            ],
            $candidate->payload,
        );
        self::assertSame(0, $candidate->sourceId);
    }

    #[Test]
    public function emits_machine_item_even_in_machine_only_context(): void
    {
        // Préchargement : la salle ne dépend d'aucune session — émise même
        // sans user (GET /state machine-only).
        $candidates = $this->provider->itemsFor(TargetContext::for($this->ws, null));

        self::assertCount(1, $candidates);
        self::assertSame('machine', $candidates->first()->payload['kind']);
        self::assertSame($this->room->name, $candidates->first()->payload['room']);
    }

    #[Test]
    public function room_is_null_without_physical_group(): void
    {
        $wsSansSalle = Workstation::factory()->create();

        $candidates = $this->provider->itemsFor(TargetContext::for($wsSansSalle, $this->user));

        self::assertCount(1, $candidates);
        $payload = $candidates->first()->payload;
        self::assertSame('machine', $payload['kind']);
        self::assertNull($payload['room']);
    }

    #[Test]
    public function machine_payload_carries_no_float(): void
    {
        $candidates = $this->provider->itemsFor(TargetContext::for($this->ws, $this->user));

        foreach ($candidates->first()->payload as $value) {
            self::assertIsNotFloat($value);
        }
    }
}
