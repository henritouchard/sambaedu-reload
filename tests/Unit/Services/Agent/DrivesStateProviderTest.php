<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateMode;
use App\Enums\StateScope;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Providers\DrivesStateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use App\Services\Filesystem\ShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `DrivesStateProvider` — Story 27.2 (AC1, AC2 ; décision n° 1 MVP-A).
 *
 * Projection des partages de CLASSE du user en montages réseau (PAS de table),
 * maille user_group, payload v1 (lettre conventionnelle figée + UNC tokenisé),
 * émis indépendamment du WorkstationEnvironment, ZÉRO AD. La projection lit le
 * pivot SQL `user_group_user` (classes du user) — jamais l'AD.
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

        $this->provider = new DrivesStateProvider(app(ShareService::class));
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

    /** @return UserGroup classe rattachée au user. */
    private function classFor(string $name): UserGroup
    {
        $group = UserGroup::factory()->create(['name' => $name, 'type' => 'classe']);
        $this->user->groups()->attach($group->id);

        return $group;
    }

    #[Test]
    public function declares_frozen_type_and_constants(): void
    {
        self::assertSame('drives', $this->provider->type());
        self::assertSame(ResourceSemantics::Aggregate, $this->provider->semantics());
        self::assertSame(StateMode::Strict, $this->provider->mode());
        self::assertSame(StateScope::Session, $this->provider->scope());
    }

    #[Test]
    public function projects_one_drive_per_user_class(): void
    {
        $this->classFor('Classe_3emeA');
        $this->classFor('Classe_3emeB');

        $candidates = $this->provider->itemsFor($this->ctx());

        self::assertCount(2, $candidates);
        foreach ($candidates as $c) {
            self::assertSame(StateMaille::UserGroup, $c->maille);
        }
    }

    #[Test]
    public function payload_carries_conventional_letter_and_tokenized_unc(): void
    {
        $this->classFor('Classe_3emeA');

        $payload = $this->provider->itemsFor($this->ctx())->first()->payload;

        self::assertSame('K:', $payload['letter'], 'convention figée : K: = classe');
        self::assertSame('\\\\<se4fs>\\Classe_3emeA\\<login>\\', $payload['unc']);
        self::assertSame('Classe 3emeA', $payload['label']);
        // Tokens NON substitués côté serveur (l'agent substitue localement).
        self::assertStringContainsString('<se4fs>', $payload['unc']);
        self::assertStringContainsString('<login>', $payload['unc']);
    }

    #[Test]
    public function strips_classe_prefix_consistently_with_share_service(): void
    {
        // Un nom stocké AVEC préfixe `Classe_` (cas SER) → UNC sans double préfixe.
        $this->classFor('Classe_6emeC');

        $payload = $this->provider->itemsFor($this->ctx())->first()->payload;

        self::assertSame('\\\\<se4fs>\\Classe_6emeC\\<login>\\', $payload['unc']);
        self::assertStringNotContainsString('Classe_Classe_', $payload['unc']);
    }

    #[Test]
    public function multiple_classes_get_distinct_incrementing_letters(): void
    {
        // Ordre déterministe par nom asc → lettres K, L, M…
        $this->classFor('Classe_aaa');
        $this->classFor('Classe_bbb');
        $this->classFor('Classe_ccc');

        $letters = $this->provider->itemsFor($this->ctx())
            ->map(fn (StateCandidate $c): string => $c->payload['letter'])
            ->all();

        self::assertSame(['K:', 'L:', 'M:'], $letters);
    }

    #[Test]
    public function non_class_user_groups_are_ignored(): void
    {
        // Un groupe user NON-classe (ex. équipe) ne produit aucun lecteur.
        $team = UserGroup::factory()->create(['name' => 'equipe_prof', 'type' => 'equipe']);
        $this->user->groups()->attach($team->id);

        self::assertCount(0, $this->provider->itemsFor($this->ctx()));
    }

    #[Test]
    public function machine_only_context_returns_no_drives(): void
    {
        $this->classFor('Classe_3emeA');

        // user null → aucun lecteur (un montage de classe dépend du login).
        self::assertCount(0, $this->provider->itemsFor(TargetContext::for($this->ws, null)));
    }

    #[Test]
    public function emitted_regardless_of_environment(): void
    {
        // Décision n° 6 : émis PARTOUT, indépendamment du WorkstationEnvironment.
        // Le poste appartient à un parc nomade → les lecteurs sont quand même émis
        // (le provider ne consomme PAS le resolver 26.1).
        $nomadeParc = WorkstationGroup::factory()->logical()->create([
            'environment' => \App\Enums\WorkstationEnvironment::Nomade,
        ]);
        $this->ws->groups()->attach($nomadeParc->id);
        $this->classFor('Classe_3emeA');

        self::assertCount(1, $this->provider->itemsFor($this->ctx()));
    }

    #[Test]
    public function invalid_class_name_is_skipped(): void
    {
        // Un nom de classe avec caractère suspect (refusé par bareClassName) est
        // ignoré sans casser la projection des autres.
        $this->classFor('Classe_..evil');
        $this->classFor('Classe_3emeA');

        $candidates = $this->provider->itemsFor($this->ctx());

        self::assertCount(1, $candidates);
        self::assertStringContainsString('Classe_3emeA', $candidates->first()->payload['unc']);
    }
}
