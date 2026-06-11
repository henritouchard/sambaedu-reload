<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateMode;
use App\Enums\StateScope;
use App\Models\OverlaySignal;
use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\Agent\Providers\OverlayStateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `OverlayStateProvider` — Story 23.4 (AC4).
 *
 * Étiquette maille par signal (décision n° 8), exclusion des signaux expirés
 * et des signaux user quand la compilation est machine-only, payload v1
 * (décision n° 7 : signaux POSTÉS uniquement, jamais d'alerte dérivée).
 */
class OverlayStateProviderTest extends TestCase
{
    use RefreshDatabase;

    private OverlayStateProvider $provider;

    private Workstation $ws;

    private WorkstationGroup $room;

    private WorkstationGroup $parc;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new OverlayStateProvider();
        $this->ws = Workstation::factory()->create();
        $this->room = WorkstationGroup::factory()->create();
        $this->parc = WorkstationGroup::factory()->logical()->create();
        $this->ws->groups()->attach([$this->room->id, $this->parc->id]);
        $this->user = User::factory()->create();
    }

    #[Test]
    public function declares_frozen_type_and_constants(): void
    {
        self::assertSame('overlay', $this->provider->type());
        self::assertSame(ResourceSemantics::Aggregate, $this->provider->semantics());
        self::assertSame(StateMode::Strict, $this->provider->mode());
        self::assertSame(StateScope::Session, $this->provider->scope());
    }

    #[Test]
    public function broadcast_signal_yields_broadcast_candidate_with_v1_payload(): void
    {
        $signal = $this->signal(['title' => 'Maintenance', 'text' => 'Ce soir 18h']);

        $candidates = $this->provider->itemsFor($this->ctx());

        self::assertCount(1, $candidates);
        $candidate = $candidates->first();
        self::assertSame(StateMaille::Broadcast, $candidate->maille);
        self::assertSame(
            [
                'kind' => 'info',
                'severity' => 'info',
                'title' => 'Maintenance',
                'text' => 'Ce soir 18h',
                'expires_at' => null,
            ],
            $candidate->payload,
        );
        self::assertSame($signal->id, $candidate->sourceId);
    }

    #[Test]
    public function workstation_targeted_signal_is_labeled_workstation_and_foreign_uuid_excluded(): void
    {
        $this->signal(['workstation_uuid' => $this->ws->uuid]);
        $this->signal(['workstation_uuid' => 'autre-uuid-inconnu']);

        $candidates = $this->provider->itemsFor($this->ctx());

        self::assertCount(1, $candidates);
        self::assertSame(StateMaille::Workstation, $candidates->first()->maille);
    }

    #[Test]
    public function group_targeted_signal_is_labeled_physical_or_logical_and_foreign_group_excluded(): void
    {
        $this->signal(['workstation_group_id' => $this->room->id]);
        $this->signal(['workstation_group_id' => $this->parc->id]);
        $otherRoom = WorkstationGroup::factory()->create();
        $this->signal(['workstation_group_id' => $otherRoom->id]);

        $mailles = $this->provider->itemsFor($this->ctx())
            ->map(fn (StateCandidate $c): string => $c->maille->value);

        self::assertEqualsCanonicalizing(
            [StateMaille::PhysicalGroup->value, StateMaille::LogicalGroup->value],
            $mailles->all(),
        );
    }

    #[Test]
    public function user_targeted_signal_is_labeled_user_even_when_multi_criteria(): void
    {
        // Multi-critères (groupe + user) → maille la plus spécifique (déc. n° 8).
        $this->signal([
            'workstation_group_id' => $this->room->id,
            'user_login' => $this->user->login,
        ]);

        $candidates = $this->provider->itemsFor($this->ctx());

        self::assertCount(1, $candidates);
        self::assertSame(StateMaille::User, $candidates->first()->maille);
    }

    #[Test]
    public function expired_signal_is_excluded_and_future_expiry_is_serialized_iso8601_utc(): void
    {
        $this->signal(['title' => 'mort', 'expires_at' => now()->subMinute()]);
        $expiry = now()->addHour();
        $this->signal(['title' => 'vivant', 'expires_at' => $expiry]);

        $candidates = $this->provider->itemsFor($this->ctx());

        self::assertCount(1, $candidates);
        $payload = $candidates->first()->payload;
        self::assertSame('vivant', $payload['title']);
        self::assertSame($expiry->copy()->utc()->toIso8601String(), $payload['expires_at']);
    }

    #[Test]
    public function null_user_excludes_user_targeted_signals(): void
    {
        $this->signal(['title' => 'broadcast']);
        $this->signal(['title' => 'perso', 'user_login' => $this->user->login]);

        $candidates = $this->provider->itemsFor(TargetContext::for($this->ws, null));

        self::assertCount(1, $candidates);
        self::assertSame('broadcast', $candidates->first()->payload['title']);
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function signal(array $attributes = []): OverlaySignal
    {
        return OverlaySignal::create(array_merge([
            'kind' => 'info',
            'severity' => 'info',
            'title' => 'titre',
            'text' => 'texte',
        ], $attributes));
    }

    private function ctx(): TargetContext
    {
        return TargetContext::for($this->ws, $this->user);
    }
}
