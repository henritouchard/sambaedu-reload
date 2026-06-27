<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\AppKind;
use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Models\AppCustomization;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\AppCustomization\AppCustomizationService;
use App\Services\AppCustomization\AppPolicyRegistry;
use App\Services\Agent\Providers\AppConfigStateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `AppConfigStateProvider` — Story 27.4 (AC1, AC2, AC7).
 *
 * Type figé `app_config` (aggregate par app_kind / scope MACHINE — correctif
 * post-review #1 : `policies.json` machine-wide, résolu PAR PARC niveaux 1-4,
 * `$user = null`). Lecture seule des policies résolues (`app_customizations` via
 * `AppCustomizationService`), un item par `app_kind`, payload concret (jamais un
 * id de scope/customization), résolution par parc (niveaux 3-4), précédence WG
 * logique > physique (impédance 4.8 ↔ Epic 27), tiebreak multi-parcs logiques
 * documenté (review #2), pas de float. Lecture PG-pure : aucun AD/APCu/Cache
 * (NFR7).
 */
class AppConfigStateProviderTest extends TestCase
{
    use RefreshDatabase;

    private AppConfigStateProvider $provider;

    private Workstation $ws;

    private WorkstationGroup $room;

    private WorkstationGroup $parc;

    private User $user;

    private UserGroup $userGroup;

    protected function setUp(): void
    {
        parent::setUp();
        // Projection Postgres-pure : aucune synchro AD à déclencher (host sans
        // LDAP, iso NFR7). Pattern aligné sur ShortcutsStateProviderTest (27.1).
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();

        // Templates FS statiques (le service les lit ; pas de fichiers système
        // sur l'hôte). Iso AppCustomizationServiceTest.
        config()->set('app-customizations.template_paths.firefox', [
            base_path('tests/fixtures/firefox/template.json'),
        ]);
        config()->set('app-customizations.template_paths.thunderbird', [
            base_path('tests/fixtures/thunderbird/template.json'),
        ]);
        config()->set('app-customizations.export_fs_on_save', false);

        $this->provider = new AppConfigStateProvider(
            new AppCustomizationService($this->app->make(AppPolicyRegistry::class)),
        );

        $this->ws = Workstation::factory()->create();
        $this->room = WorkstationGroup::factory()->create();           // is_physical = true
        $this->parc = WorkstationGroup::factory()->logical()->create(); // is_physical = false
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

    private function ctx(?User $user = null): TargetContext
    {
        return TargetContext::for($this->ws, $user ?? $this->user);
    }

    #[Test]
    public function declares_frozen_type_semantics_and_scope(): void
    {
        self::assertSame(AppCustomization::TYPE_APP_CONFIG, $this->provider->type());
        self::assertSame('app_config', $this->provider->type());
        self::assertSame(ResourceSemantics::Aggregate, $this->provider->semantics());
        // Portée MACHINE (correctif post-review #1) : `policies.json` machine-wide
        // (admin-write, écrit par le service SYSTEM), résolu PAR PARC.
        self::assertSame(StateScope::Machine, $this->provider->scope());
    }

    #[Test]
    public function emits_exactly_one_item_per_app_kind(): void
    {
        $candidates = $this->provider->itemsFor($this->ctx());

        // Un candidat par AppKind (Firefox + Thunderbird) — aggregate par app.
        self::assertCount(count(AppKind::cases()), $candidates);

        $kinds = $candidates->map(fn (StateCandidate $c) => $c->payload['app_kind'])->all();
        sort($kinds);
        self::assertSame(['firefox', 'thunderbird'], $kinds);
    }

    #[Test]
    public function payload_carries_concrete_resolved_policies_never_a_scope_id(): void
    {
        $candidates = $this->provider->itemsFor($this->ctx());
        $firefox = $candidates->first(fn (StateCandidate $c) => $c->payload['app_kind'] === 'firefox');

        self::assertNotNull($firefox);
        // Payload concret : {app_kind, policies}, jamais un id de scope/customization.
        self::assertSame(['app_kind', 'policies'], array_keys($firefox->payload));
        self::assertArrayNotHasKey('customization_id', $firefox->payload);
        self::assertArrayNotHasKey('scope', $firefox->payload);
        self::assertArrayNotHasKey('customizable_id', $firefox->payload);

        // Les policies résolues portent le socle template + auto (Proxy/DNS) —
        // contenu CONCRET, pas un pointeur.
        self::assertIsArray($firefox->payload['policies']);
        self::assertArrayHasKey('policies', $firefox->payload['policies']);
    }

    #[Test]
    public function resolves_parc_override_and_ignores_user_levels_in_machine_scope(): void
    {
        // Niveau 4 (parc LOGIQUE — WG gagnant) : impose une Homepage.
        AppCustomization::factory()->firefox()->forScope($this->parc)->create([
            'policies_json' => ['policies' => ['Homepage' => ['URL' => 'https://parc.local/']]],
        ]);
        // Niveau 6 (User) : impose une autre clé ABSENTE du template (afin que
        // sa non-résolution soit observable — `ExtensionSettings` étant déjà
        // dans le template, elle resterait toujours présente). En portée
        // MACHINE (`$user = null`, review #1), ce niveau N'EST PAS résolu — le
        // par-user de Firefox = le profil (Mécanisme B / roaming, hors 27.4).
        AppCustomization::factory()->firefox()->forScope($this->user)->create([
            'policies_json' => ['policies' => ['OfferToSaveLogins' => false]],
        ]);

        $candidates = $this->provider->itemsFor($this->ctx());
        $firefox = $candidates->first(fn (StateCandidate $c) => $c->payload['app_kind'] === 'firefox');
        $policies = $firefox->payload['policies']['policies'];

        // Le parc (niveau 4) EST appliqué.
        self::assertSame('https://parc.local/', $policies['Homepage']['URL']);
        // Le niveau user (niveau 6) N'EST PAS résolu en portée machine.
        self::assertArrayNotHasKey('OfferToSaveLogins', $policies);
    }

    #[Test]
    public function logical_parc_wins_over_physical_room_for_wg_resolution(): void
    {
        // Salle PHYSIQUE et parc LOGIQUE imposent chacun une Homepage différente.
        AppCustomization::factory()->firefox()->forScope($this->room)->create([
            'policies_json' => ['policies' => ['Homepage' => ['URL' => 'https://salle.local/']]],
        ]);
        AppCustomization::factory()->firefox()->forScope($this->parc)->create([
            'policies_json' => ['policies' => ['Homepage' => ['URL' => 'https://parc.local/']]],
        ]);

        $candidates = $this->provider->itemsFor($this->ctx());
        $firefox = $candidates->first(fn (StateCandidate $c) => $c->payload['app_kind'] === 'firefox');

        // Le WG LOGIQUE (parc) gagne (impédance 4.8 ↔ Epic 27, inversion 27.3 :
        // logique > physique). Le candidat est étiqueté maille LogicalGroup.
        self::assertSame(
            'https://parc.local/',
            $firefox->payload['policies']['policies']['Homepage']['URL'],
        );
        self::assertSame(StateMaille::LogicalGroup, $firefox->maille);
    }

    #[Test]
    public function two_logical_parcs_tiebreak_smallest_id_wins_second_ignored(): void
    {
        // Limite connue (review #2, statu quo assumé) : un poste dans DEUX parcs
        // logiques avec des policies Firefox différentes. `policies.json` est
        // machine-wide (un fichier par install) → on ne peut pas porter deux
        // configs concurrentes : le WG gagnant (plus petit id — déterminisme) est
        // résolu, l'autre parc logique est SILENCIEUSEMENT ignoré. Tiebreak
        // documenté dans le docblock du provider + state-providers.md.
        $parcA = WorkstationGroup::factory()->logical()->create();
        $parcB = WorkstationGroup::factory()->logical()->create();
        // Détache la salle/parc du setUp pour isoler les deux parcs logiques.
        $bareWs = Workstation::factory()->create();
        $bareWs->groups()->attach([$parcA->id, $parcB->id]);

        AppCustomization::factory()->firefox()->forScope($parcA)->create([
            'policies_json' => ['policies' => ['Homepage' => ['URL' => 'https://parc-a.local/']]],
        ]);
        AppCustomization::factory()->firefox()->forScope($parcB)->create([
            'policies_json' => ['policies' => ['Homepage' => ['URL' => 'https://parc-b.local/']]],
        ]);

        $candidates = $this->provider->itemsFor(TargetContext::for($bareWs, null));
        $firefox = $candidates->first(fn (StateCandidate $c) => $c->payload['app_kind'] === 'firefox');

        // Le parc au plus petit id gagne ; un seul item Firefox (un par app).
        $winnerId = min($parcA->id, $parcB->id);
        $expected = $winnerId === $parcA->id ? 'https://parc-a.local/' : 'https://parc-b.local/';
        self::assertSame($expected, $firefox->payload['policies']['policies']['Homepage']['URL']);
        self::assertCount(count(AppKind::cases()), $candidates);
    }

    #[Test]
    public function candidate_updated_at_is_non_null_when_a_rule_exists_else_null(): void
    {
        // latestUpdatedAt (review #6) : null si aucune règle (template/auto only),
        // non-null dès qu'une règle de parc/défaut étab existe pour CETTE app.
        $bareCandidates = $this->provider->itemsFor(TargetContext::for(
            Workstation::factory()->create(),
            null,
        ));
        $bareFirefox = $bareCandidates->first(fn (StateCandidate $c) => $c->payload['app_kind'] === 'firefox');
        self::assertNull($bareFirefox->updatedAt, 'aucune règle → updatedAt null');

        // Une règle de parc (niveau 4) → updatedAt non-null.
        AppCustomization::factory()->firefox()->forScope($this->parc)->create([
            'policies_json' => ['policies' => ['Homepage' => ['URL' => 'https://parc.local/']]],
        ]);
        $candidates = $this->provider->itemsFor($this->ctx());
        $firefox = $candidates->first(fn (StateCandidate $c) => $c->payload['app_kind'] === 'firefox');
        self::assertNotNull($firefox->updatedAt, 'une règle de parc → updatedAt non-null');
    }

    #[Test]
    public function machine_only_context_without_user_still_emits_one_item_per_app(): void
    {
        // Compilation machine-only (pas de user) : les niveaux 5-6 sont sautés,
        // mais chaque app émet quand même son item (template + auto + parc).
        $candidates = $this->provider->itemsFor(TargetContext::for($this->ws, null));

        self::assertCount(count(AppKind::cases()), $candidates);
        foreach ($candidates as $candidate) {
            self::assertArrayHasKey('app_kind', $candidate->payload);
            self::assertArrayHasKey('policies', $candidate->payload);
        }
    }

    #[Test]
    public function poste_without_any_workstation_group_emits_broadcast_candidates(): void
    {
        // Poste sans aucun WG : pas de niveau 4 (la chaîne 4.8 saute le WG) →
        // candidats étiquetés Broadcast, toujours un par app.
        $bareWs = Workstation::factory()->create();
        $candidates = $this->provider->itemsFor(TargetContext::for($bareWs, $this->user));

        self::assertCount(count(AppKind::cases()), $candidates);
        foreach ($candidates as $candidate) {
            self::assertSame(StateMaille::Broadcast, $candidate->maille);
        }
    }

    #[Test]
    public function policies_payload_contains_no_float_values(): void
    {
        // Une policy avec une valeur float (timeout décimal) doit être normalisée
        // en string (contrat §4.1 : zéro float).
        AppCustomization::factory()->firefox()->forScope($this->parc)->create([
            'policies_json' => ['policies' => ['SomeTimeout' => 1.5, 'Nested' => ['Ratio' => 2.25]]],
        ]);

        $candidates = $this->provider->itemsFor($this->ctx());
        $firefox = $candidates->first(fn (StateCandidate $c) => $c->payload['app_kind'] === 'firefox');

        self::assertFalse($this->hasFloat($firefox->payload), 'aucune valeur de policy ne doit être un float (§4.1)');

        // La valeur normalisée reste lisible (string), pas perdue.
        $policies = $firefox->payload['policies']['policies'];
        self::assertSame('1.5', $policies['SomeTimeout']);
        self::assertSame('2.25', $policies['Nested']['Ratio']);
    }

    /**
     * @param  array<int|string,mixed>  $value
     */
    private function hasFloat(array $value): bool
    {
        foreach ($value as $v) {
            if (is_float($v)) {
                return true;
            }
            if (is_array($v) && $this->hasFloat($v)) {
                return true;
            }
        }

        return false;
    }
}
