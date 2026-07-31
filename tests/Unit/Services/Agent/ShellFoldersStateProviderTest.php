<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\ResourceSemantics;
use App\Enums\StateMaille;
use App\Enums\StateScope;
use App\Enums\WorkstationEnvironment;
use App\Models\Shortcut;
use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\DesktopPathResolver;
use App\Services\Agent\Providers\ShellFoldersStateProvider;
use App\Services\Agent\Providers\ShortcutsStateProvider;
use App\Services\Agent\TargetContext;
use App\Services\Agent\WorkstationEnvironmentResolver;
use App\Services\FilePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `ShellFoldersStateProvider` — Story 58.1 (type `folders`, §7.12).
 *
 * Ce que ces tests protègent réellement : la redirection du Bureau et le
 * placement des raccourcis DOIVENT désigner le même dossier. Quand ils ont
 * divergé (juillet 2026, plus aucun émetteur de la redirection), le symptôme
 * n'a été ni une erreur ni un item non conforme — juste des raccourcis
 * invisibles. Le test croisé ci-dessous est donc le cœur du fichier.
 */
class ShellFoldersStateProviderTest extends TestCase
{
    use RefreshDatabase;

    private ShellFoldersStateProvider $provider;

    private Workstation $ws;

    private WorkstationGroup $parc;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Ciblage Postgres-pur : aucune raison de déclencher la synchro AD
        // (host sans LDAP). Iso discipline NFR7.
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();

        $this->provider = new ShellFoldersStateProvider(
            new WorkstationEnvironmentResolver(),
            new DesktopPathResolver(),
        );
        $this->ws = Workstation::factory()->create();
        $this->parc = WorkstationGroup::factory()->logical()->create();
        $this->ws->groups()->attach($this->parc->id);
        $this->user = User::factory()->create();
    }

    private function ctx(?User $user = null): TargetContext
    {
        return TargetContext::for($this->ws, func_num_args() > 0 ? $user : $this->user);
    }

    // --- Identité du provider dans le contrat --------------------------------

    #[Test]
    public function it_declares_the_folders_type_exclusive_and_machine_user_scoped(): void
    {
        self::assertSame('folders', $this->provider->type());
        // Un dossier shell a UNE valeur : aucune union n'aurait de sens.
        self::assertSame(ResourceSemantics::Exclusive, $this->provider->semantics());
        // La valeur s'écrit dans la ruche de l'UTILISATEUR mais se calcule à
        // partir du POSTE (environnement du parc) — c'est un croisement.
        self::assertSame(StateScope::MachineUser, $this->provider->scope());
    }

    #[Test]
    public function the_type_is_published_in_the_frozen_contract_list(): void
    {
        self::assertContains('folders', \App\Services\Agent\StateContract::RESOURCE_TYPES);
    }

    // --- Émission ------------------------------------------------------------

    #[Test]
    public function it_emits_exactly_one_broadcast_desktop_candidate(): void
    {
        $items = $this->provider->itemsFor($this->ctx());

        self::assertCount(1, $items);
        $candidate = $items->first();
        // Broadcast : la valeur dérive du parc et de la politique globale de
        // fichiers, jamais d'une assignation par maille — aucun arbitrage de
        // précédence n'a lieu d'être.
        self::assertSame(StateMaille::Broadcast, $candidate->maille);
        self::assertSame(
            ['folder', 'path', 'quick_access'],
            array_keys($candidate->payload),
            'le payload §7.12 porte EXACTEMENT 3 clés, dans cet ordre',
        );
        self::assertSame('desktop', $candidate->payload['folder']);
        // L'entrée d'Accès rapide suit la redirection : sans elle, le raccourci
        // de barre latérale le plus visible de l'explorateur continue de mener
        // à l'ancien dossier (Windows épingle un CHEMIN, pas un KNOWNFOLDERID).
        self::assertSame('pinned', $candidate->payload['quick_access']);
    }

    #[Test]
    public function machine_only_context_emits_nothing(): void
    {
        // `User Shell Folders` est une clé per-utilisateur : sans session il n'y
        // a pas de ruche à écrire. Le service SYSTEM ne fait rien de ce type.
        self::assertTrue($this->provider->itemsFor($this->ctx(null))->isEmpty());
    }

    // --- Le chemin : même matrice que `shortcuts` ----------------------------

    /**
     * Matrice complète {SharedLocal, PersonalLocal, Nomade} × {home on, home off}.
     *
     * Elle est VOLONTAIREMENT identique à `ShortcutsStateProviderTest::desktopPathMatrix()`
     * — c'est la même décision serveur, prise une seule fois
     * ({@see DesktopPathResolver::pathFor()}).
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
    public function redirection_path_crosses_environment_and_home_policy(
        WorkstationEnvironment $environment,
        bool $home,
        string $expected,
    ): void {
        $this->parc->update(['environment' => $environment]);
        // `shares`/`nextcloud` sont hors sujet : seule la capacité `home`
        // gouverne le Bureau (capacités INDÉPENDANTES, pas un mode exclusif).
        FilePolicyService::setGlobal($home, true, false);

        $payload = $this->provider->itemsFor($this->ctx())->first()->payload;

        self::assertSame($expected, $payload['path']);
    }

    #[Test]
    #[DataProvider('desktopPathMatrix')]
    public function a_local_environment_still_emits_an_explicit_redirection(
        WorkstationEnvironment $environment,
        bool $home,
        string $expected,
    ): void {
        $this->parc->update(['environment' => $environment]);
        FilePolicyService::setGlobal($home, true, false);

        // Règle des maps symétriques : « bureau local » s'ÉCRIT, il ne se tait
        // pas. Le profil itinérant est partagé entre tous les postes de
        // l'utilisateur — sans écriture explicite, un portable perdir hériterait
        // du Bureau réseau laissé par le poste de classe. « Ne pas gérer »
        // (contrat §8) laisserait la mauvaise valeur en place.
        $items = $this->provider->itemsFor($this->ctx());

        self::assertCount(1, $items, 'un item est émis dans TOUS les cas de la matrice');
        self::assertSame($expected, $items->first()->payload['path']);
    }

    // --- L'invariant de la story ---------------------------------------------

    /**
     * LE test de la story : la redirection et le placement des `.lnk` désignent
     * le MÊME dossier, dans les six configurations.
     *
     * Sans cet invariant, `shortcuts` dépose des raccourcis dans un dossier que
     * le shell ne regarde pas — sans erreur, sans item non conforme, sans la
     * moindre trace côté serveur. C'est exactement la panne diagnostiquée le
     * 2026-07-30 : elle n'était visible qu'en comparant les dates de création
     * des profils itinérants.
     */
    #[Test]
    #[DataProvider('desktopPathMatrix')]
    public function redirection_target_equals_the_path_where_shortcuts_are_dropped(
        WorkstationEnvironment $environment,
        bool $home,
    ): void {
        $this->parc->update(['environment' => $environment]);
        FilePolicyService::setGlobal($home, true, false);

        $shortcut = Shortcut::create([
            'key' => 'intranet-' . uniqid(),
            'name' => 'intranet',
            'place' => Shortcut::PLACE_DESKTOP,
            'is_active' => true,
            'windows_link' => 'https://intranet.edu',
        ]);
        DB::table('shortcut_assignables')->insert([
            'shortcut_id' => $shortcut->id,
            'assignable_type' => Workstation::class,
            'assignable_id' => $this->ws->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shortcuts = new ShortcutsStateProvider(
            new WorkstationEnvironmentResolver(),
            new DesktopPathResolver(),
        );

        $redirection = $this->provider->itemsFor($this->ctx())->first()->payload['path'];
        $dropPath = $shortcuts->itemsFor($this->ctx())->first()->payload['desktop_path'];

        self::assertSame(
            $dropPath,
            $redirection,
            'la redirection du Bureau doit désigner le dossier où `shortcuts` pose les `.lnk` — '
            .'les faire diverger rend TOUS les raccourcis `place=desktop` invisibles',
        );
    }

    // --- Pureté (NFR7) --------------------------------------------------------

    #[Test]
    public function it_reads_no_authoring_table_and_never_touches_ad(): void
    {
        // Aucune table d'authoring derrière ce type : la valeur dérive de
        // l'environnement du parc et de la politique globale de fichiers. Les
        // seules lectures admises sont donc celles-là — jamais `shortcuts`,
        // jamais `network_shares`, et surtout jamais les colonnes de ciblage
        // AD-CN legacy (`ad_users`/`ad_user_groups`, NFR7 / critère Keycloak).
        $ctx = $this->ctx();

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->provider->itemsFor($ctx);

        $forbidden = ['shortcuts', 'network_shares', 'ad_users', 'ad_user_groups'];
        foreach ($statements as $sql) {
            foreach ($forbidden as $table) {
                self::assertStringNotContainsString(
                    $table,
                    $sql,
                    "le provider `folders` ne doit jamais lire `{$table}`",
                );
            }
        }
    }
}
