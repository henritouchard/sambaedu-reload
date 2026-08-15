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
use App\Models\NetworkShare;
use App\Models\NetworkShareAssignable;
use App\Models\SystemSetting;
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
use App\Services\Filesystem\FileLocations;
use App\Services\Filesystem\FileLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // =========================================================================
    // Story 63.2 — LES EMPLACEMENTS gouvernent les lecteurs, pas les capacités
    //
    // Une lettre ne désigne QUE du SMB : `K:` si et seulement si l'espace perso
    // y vit, `H:` si et seulement si l'espace partagé y vit. Les répertoires
    // réseau gérés, eux, ne sont gouvernés par AUCUN des deux : leur autorité
    // est la leur, choisie à leur création.
    // =========================================================================

    /** Pose une décision d'emplacements — l'arrangement de toute cette section. */
    private function locations(
        FileBackendName $perso,
        FileBackendName $partage,
        ActiveCloud $cloud,
    ): void {
        FileLocationService::set(FileLocations::make($perso, $partage, $cloud));
    }

    /** @return list<string> les lettres émises, dans l'ordre. */
    private function letters(): array
    {
        return $this->provider->itemsFor($this->ctx())
            ->map(fn (StateCandidate $c): string => $c->payload['letter'])->all();
    }

    #[Test]
    public function no_recorded_decision_emits_home_and_classes(): void
    {
        // Aucune ligne persistée ⇒ défauts 63.1 (`posix`/`posix`/`aucun`) ⇒ jeu
        // fixe émis (garde-fou golden : sortie identique à l'historique).
        self::assertNull(SystemSetting::get(FileLocationService::SETTING_KEY));
        self::assertSame(['K:', 'H:'], $this->letters());
    }

    #[Test]
    public function the_personal_space_in_the_cloud_removes_K_and_nothing_else(): void
    {
        $share = NetworkShare::factory()->create(['directory_name' => 'gere', 'letter' => 'P:']);
        $this->assign($share, User::class, $this->user->id);

        $this->locations(FileBackendName::Nextcloud, FileBackendName::Posix, ActiveCloud::Nextcloud);

        self::assertSame(['H:', 'P:'], $this->letters());
    }

    #[Test]
    public function the_shared_space_in_the_cloud_removes_H_and_nothing_else(): void
    {
        // ⚠️ LE changement de comportement de la story : les répertoires réseau
        // gérés SORTENT de la garde de l'espace partagé. Leur autorité est
        // `network_shares.backend`, pas le plan de fichiers — celui-ci ne les
        // gouverne pas, il refuse seulement une lettre à ce qui n'est pas POSIX.
        $share = NetworkShare::factory()->create(['directory_name' => 'gere', 'letter' => 'P:']);
        $this->assign($share, User::class, $this->user->id);

        $this->locations(FileBackendName::Posix, FileBackendName::Nextcloud, ActiveCloud::Nextcloud);

        self::assertSame(['K:', 'P:'], $this->letters());
    }

    #[Test]
    public function both_spaces_in_the_cloud_still_leave_the_managed_directories(): void
    {
        // Et NON PAS `[]` : un répertoire déclaré `posix` reste servi par le
        // serveur de fichiers, quoi que décident les deux emplacements.
        $share = NetworkShare::factory()->create(['directory_name' => 'gere', 'letter' => 'P:']);
        $this->assign($share, User::class, $this->user->id);

        $this->locations(FileBackendName::OpenCloud, FileBackendName::OpenCloud, ActiveCloud::OpenCloud);

        self::assertSame(['P:'], $this->letters());
    }

    #[Test]
    public function both_spaces_in_the_cloud_without_managed_directory_emit_nothing(): void
    {
        $this->locations(FileBackendName::Nextcloud, FileBackendName::Nextcloud, ActiveCloud::Nextcloud);

        self::assertSame([], $this->letters());
    }

    /**
     * **AUCUNE LETTRE POUR UN RÉPERTOIRE QUE LE SERVEUR DE FICHIERS NE SERT PAS.**
     *
     * Ce n'est pas une coupe de périmètre : il n'y a PAS de chemin SMB au-dessus d'un
     * dossier d'équipe hébergé par un cloud, et c'est une impossibilité vérifiée.
     * Émettre une lettre monterait un lecteur vers un partage qui n'existe pas côté
     * serveur de fichiers — l'utilisateur verrait un lecteur en erreur pendant que
     * l'écran affirmerait que tout est en place. L'accès réel de ces répertoires est
     * le web et le client de synchronisation.
     *
     * Le filtre est une LISTE BLANCHE depuis la 63.2 : `opencloud` (vocabulaire
     * ouvert en 61.4) passait au travers de l'exclusion nominative `!= nextcloud`.
     *
     * @return array<string, array{string}>
     */
    public static function nonPosixBackends(): array
    {
        return [
            'nextcloud' => [FileBackendName::Nextcloud->value],
            'opencloud (la régression que la liste blanche ferme)' => [FileBackendName::OpenCloud->value],
            'aperçu (n\'écrit aucun droit, ne sert aucun fichier)' => [FileBackendName::Preview->value],
        ];
    }

    #[Test]
    #[DataProvider('nonPosixBackends')]
    public function a_directory_not_served_by_the_file_server_never_emits_a_drive_letter(string $backend): void
    {
        $posix = NetworkShare::factory()->create(['directory_name' => 'gere_smb', 'letter' => 'P:']);
        $this->assign($posix, User::class, $this->user->id);

        $cloud = NetworkShare::factory()->create(['directory_name' => 'gere_cloud', 'letter' => 'Q:']);
        DB::table('network_shares')->where('id', $cloud->id)->update(['backend' => $backend]);
        $this->assign($cloud, User::class, $this->user->id);

        self::assertSame(
            ['K:', 'H:', 'P:'],
            $this->letters(),
            "la lettre d'un répertoire de backend `{$backend}` ne doit pas être émise",
        );
    }

    /**
     * **UN RÉGLAGE CORROMPU REFUSE, IL NE SE REPLIE PAS.**
     *
     * Un repli sur `posix/posix` inventerait une décision que personne n'a
     * prise et déplacerait en silence les lecteurs de tout un établissement.
     */
    #[Test]
    public function a_forged_locations_row_makes_the_compilation_refuse(): void
    {
        SystemSetting::set(FileLocationService::SETTING_KEY, [
            'espace_perso.autorite' => 'posix',
            // Hors vocabulaire : ni un backend, ni rien de connu.
            'espace_partage.autorite' => 'ceph',
            'cloud.actif' => 'aucun',
        ]);

        $this->expectException(FileLocationException::class);

        $this->provider->itemsFor($this->ctx());
    }

    /**
     * **LE GOLDEN NE BOUGE PAS, ET C'EST PROUVÉ ICI.**
     *
     * Les deux payloads sont recopiés LITTÉRALEMENT des lignes `drives` de
     * `tests/Fixtures/Agent/state.v1.json`. C'est ce test — pas le fichier de
     * fixture, qui est statique — qui attrape une régression de contrat.
     */
    #[Test]
    public function the_default_decision_emits_exactly_the_golden_payloads(): void
    {
        $payloads = $this->provider->itemsFor($this->ctx())
            ->map(fn (StateCandidate $c): array => $c->payload)->all();

        self::assertSame([
            [
                'letter' => 'K:',
                'unc' => '\\\\<se4fs>\\users\\<user>\\',
                'label' => 'Mes documents',
            ],
            [
                'letter' => 'H:',
                'unc' => '\\\\<se4fs>\\classes\\',
                'label' => 'Classes',
            ],
        ], $payloads);
    }

    // =========================================================================
    // Story 34.1 — répertoires réseau gérés (network_shares)
    // =========================================================================

    private function assign(NetworkShare $share, string $type, int $id, string $access = 'ro'): void
    {
        NetworkShareAssignable::create([
            'network_share_id' => $share->id,
            'assignable_type' => $type,
            'assignable_id' => $id,
            'access' => $access,
        ]);
    }

    #[Test]
    public function zero_network_shares_yields_byte_identical_fixed_output(): void
    {
        // GARDE-FOU golden : aucune ligne network_shares ⇒ sortie strictement
        // limitée au jeu fixe K:/H: (FROZEN_STATE_HASH PHP/Go inchangés).
        self::assertSame(0, NetworkShare::count());

        $candidates = $this->provider->itemsFor($this->ctx());

        self::assertCount(2, $candidates);
        self::assertSame(
            ['K:', 'H:'],
            $candidates->map(fn (StateCandidate $c): string => $c->payload['letter'])->all(),
        );
        // Chaque candidat porte EXACTEMENT {letter, unc, label} — jamais `access`.
        foreach ($candidates as $c) {
            self::assertSame(['letter', 'unc', 'label'], array_keys($c->payload));
        }
    }

    #[Test]
    public function emits_a_user_assigned_share_with_user_maille(): void
    {
        $share = NetworkShare::factory()->create([
            'directory_name' => 'direction',
            'name' => 'Échanges direction',
            'letter' => 'P:',
        ]);
        $this->assign($share, User::class, $this->user->id, 'rw');

        $candidates = $this->provider->itemsFor($this->ctx());

        self::assertCount(3, $candidates); // K, H, + share
        $shareCandidate = $candidates->last();
        self::assertSame(StateMaille::User, $shareCandidate->maille);
        self::assertSame([
            'letter' => 'P:',
            'unc' => '\\\\<se4fs>\\partages\\direction\\',
            'label' => 'Échanges direction',
        ], $shareCandidate->payload);
        // `access` (rw) ne FUIT PAS au payload (gouverné par l'ACL serveur).
        self::assertArrayNotHasKey('access', $shareCandidate->payload);
    }

    #[Test]
    public function label_defaults_to_name_when_null(): void
    {
        $share = NetworkShare::factory()->create([
            'directory_name' => 'commun',
            'name' => 'Espace commun',
            'label' => null,
            'letter' => 'P:',
        ]);
        $this->assign($share, User::class, $this->user->id);

        $payload = $this->provider->itemsFor($this->ctx())->last()->payload;
        self::assertSame('Espace commun', $payload['label']);
    }

    #[Test]
    public function emits_a_user_group_assigned_share_with_user_group_maille(): void
    {
        $group = UserGroup::create(['name' => 'profs', 'type' => 'equipe']);
        $this->user->groups()->attach($group->id);

        $share = NetworkShare::factory()->create(['directory_name' => 'profs', 'letter' => 'Q:']);
        $this->assign($share, UserGroup::class, $group->id);

        $candidates = $this->provider->itemsFor($this->ctx());
        $shareCandidate = $candidates->last();
        self::assertSame(StateMaille::UserGroup, $shareCandidate->maille);
        self::assertSame('Q:', $shareCandidate->payload['letter']);
    }

    #[Test]
    public function workstation_group_assignment_is_mount_only_and_labels_physical_vs_logical(): void
    {
        $physical = WorkstationGroup::factory()->physical()->create();
        $logical = WorkstationGroup::factory()->logical()->create();
        $this->ws->groups()->attach([$physical->id, $logical->id]);

        $sharePhys = NetworkShare::factory()->create(['directory_name' => 'salle', 'letter' => 'P:']);
        $this->assign($sharePhys, WorkstationGroup::class, $physical->id);
        $shareLog = NetworkShare::factory()->create(['directory_name' => 'parc', 'letter' => 'Q:']);
        $this->assign($shareLog, WorkstationGroup::class, $logical->id);

        $byLetter = [];
        foreach ($this->provider->itemsFor($this->ctx()) as $c) {
            $byLetter[$c->payload['letter']] = $c->maille;
        }

        self::assertSame(StateMaille::PhysicalGroup, $byLetter['P:']);
        self::assertSame(StateMaille::LogicalGroup, $byLetter['Q:']);
    }

    #[Test]
    public function same_share_via_two_mailles_yields_identical_payloads_for_compiler_dedup(): void
    {
        // Un même share assigné au userGroup (du user) ET au WG (du poste) → 2
        // candidats au MÊME payload (lettre/unc/label) → le StateCompiler
        // dédoublonne par contenu (AC4). Le provider, lui, étiquette 2 mailles.
        $group = UserGroup::create(['name' => 'profs', 'type' => 'equipe']);
        $this->user->groups()->attach($group->id);
        $wg = WorkstationGroup::factory()->logical()->create();
        $this->ws->groups()->attach($wg->id);

        $share = NetworkShare::factory()->create(['directory_name' => 'mixte', 'letter' => 'P:']);
        $this->assign($share, UserGroup::class, $group->id);
        $this->assign($share, WorkstationGroup::class, $wg->id);

        $shareCandidates = $this->provider->itemsFor($this->ctx())
            ->filter(fn (StateCandidate $c): bool => $c->payload['letter'] === 'P:')
            ->values();

        self::assertCount(2, $shareCandidates);
        // Payloads IDENTIQUES (la dédup du compilateur les collapse).
        self::assertSame($shareCandidates[0]->payload, $shareCandidates[1]->payload);
        // Mailles distinctes (UserGroup vs LogicalGroup).
        self::assertEqualsCanonicalizing(
            [StateMaille::UserGroup, StateMaille::LogicalGroup],
            $shareCandidates->map(fn (StateCandidate $c): StateMaille => $c->maille)->all(),
        );
    }

    #[Test]
    public function auto_assigns_first_free_letter_from_M_to_Z_pool(): void
    {
        $share = NetworkShare::factory()->create(['directory_name' => 'auto', 'letter' => null]);
        $this->assign($share, User::class, $this->user->id);

        $payload = $this->provider->itemsFor($this->ctx())->last()->payload;
        self::assertSame('M:', $payload['letter']); // 1re lettre du pool M..Z
    }

    #[Test]
    public function auto_assignment_excludes_reserved_and_already_emitted_letters(): void
    {
        // Un share force 'M:', deux autres sans lettre → N:, O: (M exclue car
        // déjà émise ; K/H/I/L jamais dans le pool). Déterministe par id asc.
        $forced = NetworkShare::factory()->create(['directory_name' => 'forced', 'letter' => 'M:']);
        $autoA = NetworkShare::factory()->create(['directory_name' => 'auto_a', 'letter' => null]);
        $autoB = NetworkShare::factory()->create(['directory_name' => 'auto_b', 'letter' => null]);
        foreach ([$forced, $autoA, $autoB] as $s) {
            $this->assign($s, User::class, $this->user->id);
        }

        $byDir = [];
        foreach ($this->provider->itemsFor($this->ctx()) as $c) {
            // dérive le directory depuis l'unc \\<se4fs>\partages\<dir>\
            if (preg_match('#partages\\\\([^\\\\]+)\\\\#', $c->payload['unc'], $m)) {
                $byDir[$m[1]] = $c->payload['letter'];
            }
        }

        self::assertSame('M:', $byDir['forced']);
        self::assertSame('N:', $byDir['auto_a']);
        self::assertSame('O:', $byDir['auto_b']);
        // Aucune lettre réservée émise.
        self::assertNotContains($byDir['auto_a'], ['K:', 'H:', 'I:', 'L:', 'A:', 'B:', 'C:', 'D:']);
    }

    #[Test]
    public function auto_assignment_is_deterministic_across_calls(): void
    {
        $autoA = NetworkShare::factory()->create(['directory_name' => 'd1', 'letter' => null]);
        $autoB = NetworkShare::factory()->create(['directory_name' => 'd2', 'letter' => null]);
        $this->assign($autoA, User::class, $this->user->id);
        $this->assign($autoB, User::class, $this->user->id);

        $first = $this->provider->itemsFor($this->ctx())
            ->map(fn (StateCandidate $c): string => $c->payload['letter'])->all();
        $second = $this->provider->itemsFor($this->ctx())
            ->map(fn (StateCandidate $c): string => $c->payload['letter'])->all();

        self::assertSame($first, $second);
        self::assertSame(['K:', 'H:', 'M:', 'N:'], $first);
    }

    #[Test]
    public function shares_not_assigned_to_context_are_not_emitted(): void
    {
        // Un share assigné à un AUTRE user / groupe non porté par le contexte ne
        // doit pas apparaître (lecture restreinte aux ids du contexte).
        $otherUser = User::factory()->create(['login' => 'bob']);
        $share = NetworkShare::factory()->create(['directory_name' => 'autre', 'letter' => 'P:']);
        $this->assign($share, User::class, $otherUser->id);

        self::assertCount(2, $this->provider->itemsFor($this->ctx())); // K, H seulement
    }

    #[Test]
    public function machine_only_context_emits_no_network_shares(): void
    {
        // Même un share assigné à un WG du poste ne s'affiche pas hors session
        // user (montage dépend du login).
        $wg = WorkstationGroup::factory()->logical()->create();
        $this->ws->groups()->attach($wg->id);
        $share = NetworkShare::factory()->create(['directory_name' => 'wgonly', 'letter' => 'P:']);
        $this->assign($share, WorkstationGroup::class, $wg->id);

        self::assertCount(0, $this->provider->itemsFor(TargetContext::for($this->ws, null)));
    }

    #[Test]
    public function source_ids_are_deterministic_and_after_fixed_drives(): void
    {
        $share = NetworkShare::factory()->create(['directory_name' => 'sid', 'letter' => 'P:']);
        $this->assign($share, User::class, $this->user->id);

        $sourceIds = $this->provider->itemsFor($this->ctx())
            ->map(fn (StateCandidate $c): int => $c->sourceId)->all();

        self::assertSame(1, $sourceIds[0]); // K
        self::assertSame(2, $sourceIds[1]); // H
        self::assertGreaterThanOrEqual(3, $sourceIds[2]); // share ≥ 3
    }

    #[Test]
    public function explicit_reserved_letter_falls_back_to_auto_assignment(): void
    {
        // Garde-fou #1 (piège #4) : une lettre explicite réservée (ici 'K:', le
        // home) NE doit PAS écraser le lecteur fixe. Le share bascule sur une
        // lettre sûre du pool (M:) ; le home K: reste intact.
        $share = NetworkShare::factory()->create([
            'directory_name' => 'pirate',
            'letter' => 'K:', // collision volontaire avec le home fixe
        ]);
        $this->assign($share, User::class, $this->user->id);

        $byLetter = $this->payloadsByLetter();

        // Le home K: pointe TOUJOURS le partage users (jamais le share pirate).
        self::assertSame('\\\\<se4fs>\\users\\<user>\\', $byLetter['K:']['unc']);
        // Le share a basculé en auto-assignation sur la 1re lettre du pool.
        self::assertArrayHasKey('M:', $byLetter);
        self::assertSame('\\\\<se4fs>\\partages\\pirate\\', $byLetter['M:']['unc']);
        // Une seule entrée K: (pas de doublon collisionnant).
        $kCount = 0;
        foreach ($this->provider->itemsFor($this->ctx()) as $c) {
            if ($c->payload['letter'] === 'K:') {
                $kCount++;
            }
        }
        self::assertSame(1, $kCount);
    }

    #[Test]
    public function exhausted_letter_pool_omits_the_extra_share(): void
    {
        // Garde-fou #3 : 15 répertoires auto (lettre null) > pool M..Z (14
        // lettres) → 14 émis, le 15ᵉ omis (fail-soft tracé, pas de lettre
        // invalide montée par l'agent).
        for ($i = 1; $i <= 15; $i++) {
            $share = NetworkShare::factory()->create([
                'directory_name' => 'dir_' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'letter' => null,
            ]);
            $this->assign($share, User::class, $this->user->id);
        }

        $candidates = $this->provider->itemsFor($this->ctx());

        // K + H + 14 shares (le 15ᵉ tombe faute de lettre disponible).
        self::assertCount(16, $candidates);
        $letters = $candidates->map(fn (StateCandidate $c): string => $c->payload['letter'])->all();
        // Le pool M..Z entier est consommé, aucune lettre hors-pool ni doublon.
        self::assertSame(
            ['K:', 'H:', 'M:', 'N:', 'O:', 'P:', 'Q:', 'R:', 'S:', 'T:', 'U:', 'V:', 'W:', 'X:', 'Y:', 'Z:'],
            $letters,
        );
    }
}
