<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
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
use App\Services\FilePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // --- Story 27.21 : le bureau RÉSEAU suit la politique home (K:) -----------

    /**
     * Matrice complète {SharedLocal, PersonalLocal, Nomade} × {home on, home off}
     * (AC1, 6 cas). Le SEUL basculement introduit par la story est
     * `SharedLocal` + `home=false` → bureau LOCAL : un bureau réseau posé alors
     * que le home est coupé serait invisible pour l'utilisateur (constat terrain
     * 2026-07-22). Les cinq autres cas sont inchangés.
     *
     * @return iterable<string, array{WorkstationEnvironment, bool, string}>
     */
    public static function desktopPathMatrix(): iterable
    {
        $network = '\\\\<se4fs>\\users\\<user>\\Bureau\\';
        $local = '%USERPROFILE%\\Desktop\\';

        yield 'shared_local + home on → réseau' => [WorkstationEnvironment::SharedLocal, true, $network];
        yield 'shared_local + home off → local (LE basculement)' => [WorkstationEnvironment::SharedLocal, false, $local];
        yield 'personal_local + home on → local' => [WorkstationEnvironment::PersonalLocal, true, $local];
        yield 'personal_local + home off → local' => [WorkstationEnvironment::PersonalLocal, false, $local];
        yield 'nomade + home on → local' => [WorkstationEnvironment::Nomade, true, $local];
        yield 'nomade + home off → local' => [WorkstationEnvironment::Nomade, false, $local];
    }

    #[Test]
    #[DataProvider('desktopPathMatrix')]
    public function desktop_path_crosses_environment_and_home_policy(
        WorkstationEnvironment $environment,
        bool $home,
        string $expected,
    ): void {
        $this->parc->update(['environment' => $environment]);
        // `shares`/`nextcloud` sont hors sujet ici : seule la capacité `home`
        // gouverne le bureau (capacités INDÉPENDANTES, pas un mode exclusif).
        FilePolicyService::setGlobal($home, true, false);

        $sc = $this->shortcut('intranet', [
            'place' => Shortcut::PLACE_DESKTOP,
            'windows_link' => 'https://intranet.edu',
        ]);
        $this->assign($sc, Workstation::class, $this->ws->id);

        $payload = $this->provider->itemsFor($this->ctx())->first()->payload;

        self::assertSame($expected, $payload['desktop_path']);
    }

    #[Test]
    public function home_policy_never_creates_a_desktop_path_on_non_desktop_places(): void
    {
        // Garde-fou : couper le home ne doit RIEN changer aux emplacements
        // startup/taskbar (toujours locaux, jamais de `desktop_path`).
        FilePolicyService::setGlobal(false, true, false);
        $sc = $this->shortcut('boot', ['place' => Shortcut::PLACE_STARTUP, 'windows_link' => 'C:\\b.exe']);
        $this->assign($sc, Workstation::class, $this->ws->id);

        $payload = $this->provider->itemsFor($this->ctx())->first()->payload;

        self::assertArrayNotHasKey('desktop_path', $payload);
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
    public function same_rule_on_two_mailles_yields_one_candidate_per_maille(): void
    {
        // Story 27.8 : un MÊME raccourci assigné à deux mailles produit un
        // candidat par maille (l'étiquetage par maille survit ; le mécanisme
        // mode strict/default a été retiré — STRICT inconditionnel).
        $shortcut = $this->shortcut('pronote');
        $this->assign($shortcut, WorkstationGroup::class, $this->room->id);
        $this->assign($shortcut, WorkstationGroup::class, $this->parc->id);

        $byMaille = $this->provider->itemsFor($this->ctx())
            ->keyBy(fn (StateCandidate $c): string => $c->maille->value);

        self::assertCount(2, $byMaille, 'une règle sur 2 mailles → 2 candidats');
        self::assertArrayHasKey(StateMaille::PhysicalGroup->value, $byMaille);
        self::assertArrayHasKey(StateMaille::LogicalGroup->value, $byMaille);
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

    // --- Story 27.7 : icône UPLOADÉE (nom nu) → asset content-addressed -------

    #[Test]
    public function uploaded_icon_bare_name_emits_asset_and_checksum(): void
    {
        // Icône UPLOADÉE : `windows_icon` = nom NU (`Calculatrice`), un asset
        // content-addressed existe en base → payload porte icon_asset/checksum
        // À CÔTÉ de `icon` (nom nu brut). PAS d'URL (l'agent dérive).
        $sha = str_repeat('a', 64);
        $sc = $this->shortcut('calc', [
            'place' => Shortcut::PLACE_STARTUP,
            'windows_link' => 'C:\\Windows\\System32\\calc.exe',
            'windows_icon' => 'Calculatrice',
            'icon_asset' => $sha . '.ico',
            'icon_checksum' => $sha,
        ]);
        $this->assign($sc, Workstation::class, $this->ws->id);

        $payload = $this->provider->itemsFor($this->ctx())->first()->payload;

        self::assertSame('Calculatrice', $payload['icon']);
        self::assertSame($sha . '.ico', $payload['icon_asset']);
        self::assertSame($sha, $payload['icon_checksum']);
        self::assertArrayNotHasKey('url', $payload, 'décision n° 4 : pas de champ url');
    }

    #[Test]
    public function real_icon_path_keeps_icon_raw_and_emits_no_asset(): void
    {
        // Chemin RÉEL (`firefox.exe,0`) → `icon` brut, JAMAIS d'asset (régression
        // zéro pour ce cas, déjà géré par ParseIconLocation 2.2.1).
        $sc = $this->shortcut('ff', [
            'place' => Shortcut::PLACE_STARTUP,
            'windows_icon' => 'C:\\Program Files\\Mozilla Firefox\\firefox.exe,0',
            // même si des colonnes étaient renseignées par erreur, un chemin
            // réel ne déclenche PAS l'émission d'asset.
            'icon_asset' => str_repeat('b', 64) . '.ico',
            'icon_checksum' => str_repeat('b', 64),
        ]);
        $this->assign($sc, Workstation::class, $this->ws->id);

        $payload = $this->provider->itemsFor($this->ctx())->first()->payload;

        self::assertSame('C:\\Program Files\\Mozilla Firefox\\firefox.exe,0', $payload['icon']);
        self::assertArrayNotHasKey('icon_asset', $payload);
        self::assertArrayNotHasKey('icon_checksum', $payload);
    }

    #[Test]
    public function bare_name_without_backfilled_asset_falls_back_to_raw_icon(): void
    {
        // Nom nu mais AUCUN asset content-addressed en base (`icon_asset` null) :
        // on tombe sur `icon` brut (ancien comportement), JAMAIS un asset cassé
        // (piège n° 3). Le backfill rattrapera.
        $sc = $this->shortcut('vivaldi', [
            'place' => Shortcut::PLACE_STARTUP,
            'windows_icon' => 'vivaldi',
            // icon_asset / icon_checksum laissés null
        ]);
        $this->assign($sc, Workstation::class, $this->ws->id);

        $payload = $this->provider->itemsFor($this->ctx())->first()->payload;

        self::assertSame('vivaldi', $payload['icon']);
        self::assertArrayNotHasKey('icon_asset', $payload);
        self::assertArrayNotHasKey('icon_checksum', $payload);
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
