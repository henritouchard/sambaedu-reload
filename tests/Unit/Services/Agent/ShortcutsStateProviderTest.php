<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\ActiveCloud;
use App\Enums\FileBackendName;
use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Enums\WorkstationEnvironment;
use App\Exceptions\Filesystem\FileLocationException;
use App\Models\Shortcut;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\DesktopPathResolver;
use App\Services\Agent\Providers\ShortcutsStateProvider;
use App\Services\Agent\StateCandidate;
use App\Services\Agent\TargetContext;
use App\Services\Agent\WorkstationEnvironmentResolver;
use App\Services\FilePolicyService;
use App\Services\Filesystem\FileLocations;
use App\Services\Filesystem\FileLocationService;
use App\Services\Shortcuts\PortalShortcutIcon;
use App\Services\Shortcuts\ShortcutIconAssetService;
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

        $this->provider = new ShortcutsStateProvider(
            new WorkstationEnvironmentResolver(),
            new DesktopPathResolver(),
            new PortalShortcutIcon(new ShortcutIconAssetService()),
        );
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

    // ── Défaut de parc : posé partout, sans assignation ──────────────────────

    #[Test]
    public function a_parc_default_shortcut_reaches_a_workstation_without_any_assignment(): void
    {
        $this->shortcut('poste-partout', ['is_parc_default' => true]);

        $candidates = $this->provider->itemsFor($this->ctx());

        $names = $candidates->map(fn (StateCandidate $c): string => $c->payload['name']);
        self::assertContains('poste-partout', $names->all());

        $candidate = $candidates->first(fn (StateCandidate $c): bool => $c->payload['name'] === 'poste-partout');
        self::assertSame(StateMaille::Broadcast, $candidate->maille);
    }

    #[Test]
    public function an_inactive_parc_default_shortcut_reaches_nobody(): void
    {
        $this->shortcut('eteint', ['is_parc_default' => true, 'is_active' => false]);

        $names = $this->provider->itemsFor($this->ctx())
            ->map(fn (StateCandidate $c): string => $c->payload['name']);

        self::assertNotContains('eteint', $names->all());
    }

    #[Test]
    public function a_shortcut_both_assigned_and_parc_default_is_not_emitted_twice(): void
    {
        // Deux candidats de payload identique : le compilateur les collapse (aggregate),
        // mais le provider doit déjà produire un payload strictement identique — sans
        // quoi le poste verrait deux fois le même raccourci.
        $shortcut = $this->shortcut('doublon', ['is_parc_default' => true]);
        $this->assign($shortcut, WorkstationGroup::class, $this->room->id);

        $payloads = $this->provider->itemsFor($this->ctx())
            ->filter(fn (StateCandidate $c): bool => $c->payload['name'] === 'doublon')
            ->map(fn (StateCandidate $c): string => json_encode($c->payload))
            ->unique();

        self::assertCount(1, $payloads, 'Les deux origines doivent produire le même payload.');
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
            // Story 27.21 (option A) : parc partagé ⇒ les DEUX Bureaux sont
            // balayés (POSE ≠ BALAYAGE).
            'desktop_sweep_paths' => [
                '\\\\<se4fs>\\users\\<user>\\Bureau\\',
                '%USERPROFILE%\\Desktop\\',
            ],
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

    // --- Story 63.2 : le bureau ne dépend QUE de l'environnement du parc ------

    /**
     * Matrice {SharedLocal, PersonalLocal, Nomade} — **et rien d'autre**.
     *
     * L'axe « politique home » a DISPARU en 63.2 : le bureau réseau vit dans le
     * home SMB, et ce partage-là est toujours là pour l'agent même quand
     * l'espace perso de l'utilisateur a déménagé au cloud. Le seul facteur
     * restant est l'exception « portables » (perdir / nomade), et elle est
     * juste.
     *
     * @return iterable<string, array{WorkstationEnvironment, string}>
     */
    public static function desktopPathMatrix(): iterable
    {
        $network = '\\\\<se4fs>\\users\\<user>\\Bureau\\';
        $local = '%USERPROFILE%\\Desktop\\';

        yield 'shared_local → réseau' => [WorkstationEnvironment::SharedLocal, $network];
        yield 'personal_local → local' => [WorkstationEnvironment::PersonalLocal, $local];
        yield 'nomade → local' => [WorkstationEnvironment::Nomade, $local];
    }

    #[Test]
    #[DataProvider('desktopPathMatrix')]
    public function desktop_path_follows_the_park_environment_alone(
        WorkstationEnvironment $environment,
        string $expected,
    ): void {
        $this->parc->update(['environment' => $environment]);

        $sc = $this->shortcut('intranet', [
            'place' => Shortcut::PLACE_DESKTOP,
            'windows_link' => 'https://intranet.edu',
        ]);
        $this->assign($sc, Workstation::class, $this->ws->id);

        $payload = $this->provider->itemsFor($this->ctx())->first()->payload;

        self::assertSame($expected, $payload['desktop_path']);
    }

    /**
     * LE point de la story, énoncé sur l'axe qui a disparu : poser l'espace
     * perso au cloud ne déplace PLUS le Bureau d'un poste partagé.
     */
    #[Test]
    #[DataProvider('desktopPathMatrix')]
    public function the_personal_space_in_the_cloud_never_moves_the_desktop(
        WorkstationEnvironment $environment,
        string $expected,
    ): void {
        $this->parc->update(['environment' => $environment]);
        FileLocationService::set(FileLocations::make(
            FileBackendName::Nextcloud,
            FileBackendName::Posix,
            ActiveCloud::Nextcloud,
        ));

        $sc = $this->shortcut('intranet', [
            'place' => Shortcut::PLACE_DESKTOP,
            'windows_link' => 'https://intranet.edu',
        ]);
        $this->assign($sc, Workstation::class, $this->ws->id);

        $payload = $this->provider->itemsFor($this->ctx())
            ->firstWhere(fn (StateCandidate $c): bool => $c->payload['name'] === 'intranet')->payload;

        self::assertSame($expected, $payload['desktop_path']);
    }

    // --- Story 27.21 (option A) : le SERVEUR nomme les Bureaux à BALAYER ------

    /**
     * Matrice {SharedLocal, PersonalLocal, Nomade} des emplacements de BALAYAGE
     * (finding 🔴 #1 de la review 27.21).
     *
     * Le Bureau réseau est PARTAGÉ entre tous les postes d'un utilisateur : seul
     * un parc `shared_local` a autorité pour y supprimer des `.lnk` gérés. Un
     * poste perdir/nomade ne doit JAMAIS le balayer, sous peine d'effacer les
     * raccourcis que le poste de classe du même utilisateur vient de poser.
     *
     * La liste n'a jamais dépendu d'un réglage de fichiers, et ne dépend
     * toujours que de l'environnement : les deux emplacements d'un parc partagé
     * restent balayés quoi qu'il arrive, sinon celui qu'on vient d'abandonner ne
     * serait plus jamais nettoyé (AC3 de la 27.21).
     *
     * @return iterable<string, array{WorkstationEnvironment, list<string>}>
     */
    public static function desktopSweepPathsMatrix(): iterable
    {
        $network = '\\\\<se4fs>\\users\\<user>\\Bureau\\';
        $local = '%USERPROFILE%\\Desktop\\';

        yield 'shared_local → [réseau, local]' => [WorkstationEnvironment::SharedLocal, [$network, $local]];
        yield 'personal_local → [local] SEUL' => [WorkstationEnvironment::PersonalLocal, [$local]];
        yield 'nomade → [local] SEUL' => [WorkstationEnvironment::Nomade, [$local]];
    }

    /**
     * @param  list<string>  $expected
     */
    #[Test]
    #[DataProvider('desktopSweepPathsMatrix')]
    public function desktop_sweep_paths_follow_the_park_environment(
        WorkstationEnvironment $environment,
        array $expected,
    ): void {
        $this->parc->update(['environment' => $environment]);

        $sc = $this->shortcut('intranet', [
            'place' => Shortcut::PLACE_DESKTOP,
            'windows_link' => 'https://intranet.edu',
        ]);
        $this->assign($sc, Workstation::class, $this->ws->id);

        $payload = $this->provider->itemsFor($this->ctx())->first()->payload;

        self::assertSame($expected, $payload['desktop_sweep_paths']);
    }

    #[Test]
    public function desktop_sweep_paths_are_emitted_on_non_desktop_places_too(): void
    {
        // Le balayage est une donnée de CONTEXTE (le poste), pas une propriété
        // du raccourci : l'agent doit connaître les Bureaux à nettoyer MÊME
        // quand plus aucune règle `place=desktop` n'existe (sinon un Bureau vidé
        // de ses règles garde ses `.lnk` gérés orphelins à vie — review #2 de 27.1).
        $sc = $this->shortcut('boot', ['place' => Shortcut::PLACE_STARTUP, 'windows_link' => 'C:\\b.exe']);
        $this->assign($sc, Workstation::class, $this->ws->id);

        $payload = $this->provider->itemsFor($this->ctx())->first()->payload;

        self::assertArrayNotHasKey('desktop_path', $payload);
        self::assertSame([
            '\\\\<se4fs>\\users\\<user>\\Bureau\\',
            '%USERPROFILE%\\Desktop\\',
        ], $payload['desktop_sweep_paths']);
    }

    #[Test]
    public function perdir_park_never_names_the_shared_network_desktop(): void
    {
        // LE garde-fou du finding #1 : un parc perdir ne nomme JAMAIS le Bureau
        // réseau — ni en pose, ni en balayage, ni dans le payload du portail.
        // Y compris quand l'espace perso a déménagé au cloud (63.2 : le Bureau
        // ne suit plus les emplacements, mais l'exception portables tient).
        $this->parc->update(['environment' => WorkstationEnvironment::PersonalLocal]);
        $this->enablePortalShortcut();

        $sc = $this->shortcut('intranet', [
            'place' => Shortcut::PLACE_DESKTOP,
            'windows_link' => 'https://intranet.edu',
        ]);
        $this->assign($sc, Workstation::class, $this->ws->id);

        foreach ($this->provider->itemsFor($this->ctx()) as $candidate) {
            self::assertStringNotContainsString('<se4fs>', json_encode($candidate->payload, JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    public function file_locations_never_create_a_desktop_path_on_non_desktop_places(): void
    {
        // Garde-fou : déplacer l'espace perso au cloud ne doit RIEN changer aux
        // emplacements startup/taskbar (toujours locaux, jamais de `desktop_path`).
        FileLocationService::set(FileLocations::make(
            FileBackendName::Nextcloud,
            FileBackendName::Posix,
            ActiveCloud::Nextcloud,
        ));
        $sc = $this->shortcut('boot', ['place' => Shortcut::PLACE_STARTUP, 'windows_link' => 'C:\\b.exe']);
        $this->assign($sc, Workstation::class, $this->ws->id);

        $payload = $this->provider->itemsFor($this->ctx())
            ->firstWhere(fn (StateCandidate $c): bool => $c->payload['name'] === 'boot')->payload;

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

    // --- Le raccourci SYNTHÉTIQUE vers le portail web -------------------------
    //
    // Il ne vient d'aucune ligne de `shortcuts` : il naît du PLAN DE FICHIERS
    // (Story 63.2) — un cloud actif ET au moins un des deux espaces servi par
    // lui —, parce qu'un espace servi par un cloud n'a aucune lettre de lecteur
    // et que le navigateur est son seul chemin d'accès. Un cloud seulement
    // CONFIGURÉ, dont aucun espace ne dépend, ne pose rien : le raccourci mène
    // là où vivent les fichiers.

    #[Test]
    public function portal_shortcut_is_emitted_when_the_active_cloud_serves_a_space(): void
    {
        $this->enablePortalShortcut();

        $candidates = $this->provider->itemsFor($this->ctx());

        self::assertCount(1, $candidates, 'aucune ligne de shortcuts : le seul candidat est le portail');

        $portal = $candidates->first();
        self::assertSame(StateMaille::Broadcast, $portal->maille, 'réglage d\'instance = maille diffusée');
        self::assertSame(0, $portal->sourceId, 'id libre par construction (les id Postgres commencent à 1)');
        self::assertNull($portal->updatedAt);

        $payload = $portal->payload;
        self::assertSame(ShortcutsStateProvider::PORTAL_SHORTCUT_NAME, $payload['name']);
        self::assertSame(Shortcut::PLACE_DESKTOP, $payload['place']);
        // L'URL est en ARGUMENTS, jamais en cible : une URL posée en cible d'un
        // `.lnk` produit « l'élément auquel ce raccourci renvoie a été modifié ou
        // déplacé » sur le poste.
        self::assertSame('C:\\Windows\\System32\\rundll32.exe', $payload['target']);
        self::assertSame('url.dll,FileProtocolHandler https://cloud.etab.fr', $payload['args']);
        self::assertArrayHasKey('desktop_path', $payload);
        self::assertArrayHasKey('desktop_sweep_paths', $payload);
    }

    #[Test]
    public function portal_shortcut_is_added_to_the_assigned_ones_never_replacing_them(): void
    {
        $this->enablePortalShortcut();
        $this->assign($this->shortcut('pronote'), Workstation::class, $this->ws->id);

        $names = $this->provider->itemsFor($this->ctx())
            ->map(fn (StateCandidate $c): string => $c->payload['name']);

        self::assertEqualsCanonicalizing(
            [ShortcutsStateProvider::PORTAL_SHORTCUT_NAME, 'pronote'],
            $names->all(),
            'sémantique aggregate : le portail s\'AJOUTE, il n\'évince rien',
        );
    }

    /**
     * TROIS conditions, et chacune refuse à elle seule : un cloud actif, au
     * moins un espace servi par ce cloud, une URL non vide pour lui. Un
     * raccourci qui n'ouvre rien est pire que pas de raccourci ; un raccourci
     * qui ne mène nulle part où vivent des fichiers l'est tout autant.
     *
     * @return array<string, array{FileBackendName, FileBackendName, ActiveCloud, string}>
     */
    public static function portalRefusals(): array
    {
        return [
            'aucun cloud actif' => [
                FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::Aucun, 'https://cloud.etab.fr',
            ],
            'cloud actif mais URL non renseignée' => [
                FileBackendName::Nextcloud, FileBackendName::Posix, ActiveCloud::Nextcloud, '',
            ],
            'cloud actif mais URL réduite à des espaces' => [
                FileBackendName::Nextcloud, FileBackendName::Posix, ActiveCloud::Nextcloud, '   ',
            ],
            // LE CAS LE PLUS COURANT, et le motif de la condition resserrée : un
            // cloud configuré et joignable, mais dont AUCUN espace ne dépend. Y
            // poser une icône sur tous les bureaux de l'établissement serait un
            // changement visible que personne n'a demandé.
            'cloud actif et joignable, mais les deux espaces sont sur le serveur de fichiers' => [
                FileBackendName::Posix, FileBackendName::Posix, ActiveCloud::Nextcloud, 'https://cloud.etab.fr',
            ],
        ];
    }

    #[Test]
    #[DataProvider('portalRefusals')]
    public function portal_shortcut_is_silent_unless_a_reachable_cloud_serves_a_space(
        FileBackendName $espacePerso,
        FileBackendName $espacePartage,
        ActiveCloud $cloud,
        string $url,
    ): void {
        // L'URL est posée dans TOUS les cas : le refus « aucun cloud actif »
        // doit tenir même quand une URL traîne dans les réglages du produit
        // (une instance qui a configuré Nextcloud puis y a renoncé).
        FilePolicyService::setGlobal(true, true, true, $url);
        FileLocationService::set(FileLocations::make($espacePerso, $espacePartage, $cloud));

        self::assertCount(0, $this->provider->itemsFor($this->ctx()));
    }

    /**
     * **AC6 — la capacité du produit n'est PLUS une condition.** L'accès
     * Nextcloud est ÉTEINT dans `files.policy` pendant que le plan de fichiers
     * désigne Nextcloud comme cloud actif et lui confie l'espace perso : le
     * raccourci est posé quand même. C'est le plan de fichiers qui décide où
     * vivent les fichiers, et l'ancienne capacité ne gouverne plus rien ici.
     */
    #[Test]
    public function portal_shortcut_ignores_the_product_capability(): void
    {
        FilePolicyService::setGlobal(true, true, false, 'https://cloud.etab.fr');
        FileLocationService::set(FileLocations::make(
            FileBackendName::Nextcloud,
            FileBackendName::Posix,
            ActiveCloud::Nextcloud,
        ));

        $candidates = $this->provider->itemsFor($this->ctx());

        self::assertCount(1, $candidates, 'la capacité éteinte ne retient plus le raccourci');
        self::assertSame(
            ShortcutsStateProvider::PORTAL_SHORTCUT_NAME,
            $candidates->first()->payload['name'],
        );
        self::assertSame(
            'url.dll,FileProtocolHandler https://cloud.etab.fr',
            $candidates->first()->payload['args'],
        );
    }

    /**
     * Le raccourci suit le cloud ACTIF, pas un produit : sous OpenCloud, il
     * ouvre l'URL OpenCloud — et jamais celle de l'autre produit, même si elle
     * est renseignée.
     */
    #[Test]
    public function portal_shortcut_opens_the_active_cloud_url(): void
    {
        FilePolicyService::setGlobal(
            true,
            true,
            true,
            'https://nextcloud.etab.fr',
            opencloud: true,
            opencloudServerUrl: 'https://opencloud.etab.fr',
        );
        FileLocationService::set(FileLocations::make(
            FileBackendName::Posix,
            FileBackendName::OpenCloud,
            ActiveCloud::OpenCloud,
        ));

        $payload = $this->provider->itemsFor($this->ctx())->first()->payload;

        self::assertSame(ShortcutsStateProvider::PORTAL_SHORTCUT_NAME, $payload['name']);
        self::assertSame('url.dll,FileProtocolHandler https://opencloud.etab.fr', $payload['args']);
    }

    /**
     * L'URL du cloud actif est VIDE alors que l'autre produit en a une : rien
     * n'est posé. Le repli sur l'URL du voisin ouvrirait le mauvais portail.
     */
    #[Test]
    public function portal_shortcut_never_falls_back_to_the_other_products_url(): void
    {
        FilePolicyService::setGlobal(
            true,
            true,
            true,
            'https://nextcloud.etab.fr',
            opencloud: true,
            opencloudServerUrl: '',
        );
        // L'espace perso EST au cloud actif : la condition d'emplacement est
        // remplie, et seule l'URL manquante décide du refus.
        FileLocationService::set(FileLocations::make(
            FileBackendName::OpenCloud,
            FileBackendName::Posix,
            ActiveCloud::OpenCloud,
        ));

        self::assertCount(0, $this->provider->itemsFor($this->ctx()));
    }

    /**
     * **UN RÉGLAGE CORROMPU REFUSE, IL NE SE REPLIE PAS.** Un repli sur « aucun
     * cloud » ferait disparaître en silence, de tous les bureaux de
     * l'établissement, le seul chemin d'accès aux fichiers en ligne.
     */
    #[Test]
    public function a_forged_locations_row_makes_the_compilation_refuse(): void
    {
        SystemSetting::set(FileLocationService::SETTING_KEY, [
            'espace_perso.autorite' => 'posix',
            'espace_partage.autorite' => 'posix',
            'cloud.actif' => 'owncloud',
        ]);

        $this->expectException(FileLocationException::class);

        $this->provider->itemsFor($this->ctx());
    }

    /**
     * **LE GOLDEN NE BOUGE PAS.** Sans décision enregistrée, `desktop_path` vaut
     * le chemin RÉSEAU de la ligne `shortcuts` de
     * `tests/Fixtures/Agent/state.v1.json`, et AUCUN candidat de portail n'est
     * émis (pas de cloud actif par défaut).
     */
    #[Test]
    public function the_default_decision_carries_the_golden_network_desktop_and_no_portal(): void
    {
        self::assertNull(SystemSetting::get(FileLocationService::SETTING_KEY));

        $sc = $this->shortcut('Calculatrice', [
            'place' => Shortcut::PLACE_DESKTOP,
            'windows_link' => 'C:\\Windows\\System32\\calc.exe',
        ]);
        $this->assign($sc, Workstation::class, $this->ws->id);

        $candidates = $this->provider->itemsFor($this->ctx());

        self::assertCount(1, $candidates, 'aucun cloud actif ⇒ aucun raccourci de portail');
        $payload = $candidates->first()->payload;
        self::assertSame('\\\\<se4fs>\\users\\<user>\\Bureau\\', $payload['desktop_path']);
        self::assertSame([
            '\\\\<se4fs>\\users\\<user>\\Bureau\\',
            '%USERPROFILE%\\Desktop\\',
        ], $payload['desktop_sweep_paths']);
    }

    #[Test]
    public function portal_shortcut_carries_the_published_icon_when_there_is_one(): void
    {
        $this->enablePortalShortcut();

        // Le dossier servi est redirigé : un test n'écrit pas dans le storage de
        // l'application. L'icône SOURCE, elle, est bien celle livrée avec le code.
        $served = sys_get_temp_dir() . '/se5-portal-icon-' . uniqid();
        config(['shortcut_icons.served_path' => $served]);

        $published = app(PortalShortcutIcon::class)->publish();

        self::assertNotNull($published, 'l\'icône source est livrée avec l\'application');
        self::assertFileExists($served . '/' . $published['asset']);

        $payload = $this->provider->itemsFor($this->ctx())->first()->payload;

        self::assertSame($published['asset'], $payload['icon_asset']);
        self::assertSame($published['checksum'], $payload['icon_checksum']);
        // `icon` reste vide : quand `icon_asset` est là, l'agent ne regarde
        // JAMAIS `icon`. Y écrire quelque chose laisserait croire à un repli.
        self::assertSame('', $payload['icon']);
    }

    #[Test]
    public function portal_shortcut_is_still_posted_without_a_published_icon(): void
    {
        // Aucune publication : le raccourci part SANS icône plutôt que pas du
        // tout — un chemin d'accès sans icône reste un chemin d'accès.
        $this->enablePortalShortcut();

        $payload = $this->provider->itemsFor($this->ctx())->first()->payload;

        self::assertSame('', $payload['icon']);
        self::assertArrayNotHasKey('icon_asset', $payload);
        self::assertArrayNotHasKey('icon_checksum', $payload);
    }

    #[Test]
    public function portal_shortcut_follows_the_desktop_path_of_the_park(): void
    {
        // Le portail n'est pas un cas à part : il est posé au MÊME Bureau que les
        // autres raccourcis, celui que le parc impose. Un parc perdir ne nomme
        // jamais le Bureau réseau, partagé entre tous les postes de l'utilisateur.
        $this->parc->update(['environment' => WorkstationEnvironment::PersonalLocal]);
        $this->enablePortalShortcut();

        $payload = $this->provider->itemsFor($this->ctx())->first()->payload;

        self::assertSame('%USERPROFILE%\\Desktop\\', $payload['desktop_path']);
        self::assertStringNotContainsString('<se4fs>', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Les TROIS conditions réunies : un cloud actif, au moins un espace servi
     * par lui (ici l'espace perso), et une URL pour ce cloud.
     */
    private function enablePortalShortcut(string $url = 'https://cloud.etab.fr'): void
    {
        FilePolicyService::setGlobal(true, true, true, $url);
        FileLocationService::set(FileLocations::make(
            FileBackendName::Nextcloud,
            FileBackendName::Posix,
            ActiveCloud::Nextcloud,
        ));
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
