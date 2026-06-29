<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Enums\WorkstationEnvironment;
use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Providers\DrivesStateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `DrivesStateProvider` — lecteurs réseau NATIFS (décision Henri
 * 2026-06-29) : jeu standard FIXE {K: home, H: classes} pour toute session user,
 * lettres figées serveur, tokens `<se4fs>`/`<user>` non substitués, ZÉRO AD,
 * indépendant du WorkstationEnvironment ET de l'appartenance à une classe.
 */
class DrivesStateProviderTest extends TestCase
{
    use RefreshDatabase;

    private DrivesStateProvider $provider;

    private Workstation $ws;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();

        $this->provider = new DrivesStateProvider();
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

    private function ctx(): TargetContext
    {
        return TargetContext::for($this->ws, $this->user);
    }

    /** @return array<string,array<string,mixed>> payloads indexés par lettre. */
    private function payloadsByLetter(): array
    {
        $out = [];
        foreach ($this->provider->itemsFor($this->ctx()) as $c) {
            $out[$c->payload['letter']] = $c->payload;
        }

        return $out;
    }

    #[Test]
    public function declares_frozen_type_and_constants(): void
    {
        self::assertSame('drives', $this->provider->type());
        self::assertSame(ResourceSemantics::Aggregate, $this->provider->semantics());
        self::assertSame(StateScope::Session, $this->provider->scope());
    }

    #[Test]
    public function emits_fixed_home_and_classes_drives_for_a_session(): void
    {
        $candidates = $this->provider->itemsFor($this->ctx());

        self::assertCount(2, $candidates);
        // Ordre déterministe (sourceId asc) : K: (home) puis H: (classes).
        self::assertSame(
            ['K:', 'H:'],
            $candidates->map(fn (StateCandidate $c): string => $c->payload['letter'])->all(),
        );
    }

    #[Test]
    public function home_drive_payload_targets_users_share(): void
    {
        $k = $this->payloadsByLetter()['K:'];

        self::assertSame('\\\\<se4fs>\\users\\<user>\\', $k['unc']);
        self::assertSame('Mes documents', $k['label']);
        // Tokens NON substitués côté serveur (l'agent substitue localement).
        self::assertStringContainsString('<se4fs>', $k['unc']);
        self::assertStringContainsString('<user>', $k['unc']);
    }

    #[Test]
    public function classes_drive_payload_targets_classes_share_root(): void
    {
        $h = $this->payloadsByLetter()['H:'];

        // Racine du partage classes : jamais une classe unique (un user peut en
        // avoir plusieurs) — l'agent navigue vers H:\Classe_<nom>\<login>.
        self::assertSame('\\\\<se4fs>\\classes\\', $h['unc']);
        self::assertSame('Classes', $h['label']);
        self::assertStringNotContainsString('<user>', $h['unc']);
    }

    #[Test]
    public function home_and_classes_use_distinct_mailles(): void
    {
        $byLetter = [];
        foreach ($this->provider->itemsFor($this->ctx()) as $c) {
            $byLetter[$c->payload['letter']] = $c->maille;
        }

        self::assertSame(StateMaille::User, $byLetter['K:']);
        self::assertSame(StateMaille::Broadcast, $byLetter['H:']);
    }

    #[Test]
    public function emitted_even_without_any_class_membership(): void
    {
        // Aucune classe rattachée : le jeu standard {K:, H:} est quand même émis
        // (H: = racine du partage, ACL-gated — comportement uniforme).
        self::assertCount(2, $this->provider->itemsFor($this->ctx()));
    }

    #[Test]
    public function machine_only_context_returns_no_drives(): void
    {
        // user null → aucun lecteur (un montage dépend du login de session).
        self::assertCount(0, $this->provider->itemsFor(TargetContext::for($this->ws, null)));
    }

    #[Test]
    public function emitted_regardless_of_environment(): void
    {
        // Émis PARTOUT, indépendamment du WorkstationEnvironment (un montage
        // réseau est réseau par nature ; le provider ne consomme PAS le resolver).
        $nomadeParc = WorkstationGroup::factory()->logical()->create([
            'environment' => WorkstationEnvironment::Nomade,
        ]);
        $this->ws->groups()->attach($nomadeParc->id);

        self::assertCount(2, $this->provider->itemsFor($this->ctx()));
    }
}
