<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend\Posix;

use App\Models\NetworkShare;
use App\Models\NetworkShareAssignable;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Filesystem\Backend\Posix\PosixAclCompiler;
use App\Services\Filesystem\SharePlanProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 60.4 — LE RÉFÉRENTIEL FIGÉ : iso-comportement, chaîne par chaîne.
 *
 * ---------------------------------------------------------------------------
 * **POURQUOI DES LITTÉRAUX ET PAS UN ORACLE VIVANT.**
 *
 * L'oracle naturel serait la dérivation historique des permissions. Mais elle
 * DÉMÉNAGE pendant cette story : un test qui compilerait les deux chemins depuis
 * le même code après la descente comparerait le code à lui-même et ne prouverait
 * rien. Les chaînes ci-dessous ont donc été CAPTURÉES sur le comportement
 * d'AVANT, en premier geste de la story, contre le code de l'Epic 34 encore en
 * place, et figées telles quelles. C'est le seul témoin indépendant qui survive
 * au déménagement.
 *
 * Journal de capture : `PosixGoldenCaptureTest` (harnais jetable), exécuté vert
 * le 2026-08-04 avant toute modification, sortie recopiée sans retouche.
 *
 * ---------------------------------------------------------------------------
 * **UNE DIVERGENCE, UNE SEULE, ET ELLE EST DOCUMENTÉE : L'ORDRE D'ÉMISSION.**
 *
 * La dérivation historique parcourait les lignes du pivot dans l'ordre où la base
 * les rendait. Cet ordre n'était pas choisi : sur le moteur des tests, il suit
 * l'index unique `(répertoire, identifiant de cible, type de cible)`, qui
 * ENTRELACE les types — un utilisateur, puis un groupe, puis un autre
 * utilisateur. Sur un autre moteur il aurait pu différer. Ce n'était donc pas une
 * propriété du code, c'était un accident du plan de requête.
 *
 * Le nouveau chemin émet dans l'ordre CANONIQUE du plan (par type de sujet, puis
 * identité), qui est déterministe et indépendant du moteur — c'est une exigence de
 * la story 60.1, sans laquelle la comparaison de deux résolutions serait bruitée.
 *
 * Conséquences, toutes vérifiées ci-dessous :
 *  - pour cinq des six situations, les deux ordres COÏNCIDENT et l'égalité est
 *    stricte, chaîne par chaîne ;
 *  - pour la sixième (types entrelacés), l'égalité est stricte sur l'ENSEMBLE et
 *    l'ordre canonique est lui-même figé en littéraux ;
 *  - la différence est sans effet : chaque entrée est posée par une commande
 *    distincte, et toute comparaison ultérieure normalise et trie avant de
 *    comparer. Un test dédié montre en outre que le nouvel ordre ne dépend plus de
 *    l'ordre de saisie — propriété que l'ancien n'avait pas.
 */
class PosixGoldenAclTest extends TestCase
{
    use RefreshDatabase;

    /** Jeu canonique de base, présent sur TOUTE situation. */
    private const BASE = [
        'user::rwx',
        'group::---',
        'group:domain\\040admins:rwx',
        'mask::rwx',
        'other::---',
        'default:user::rwx',
        'default:group::---',
        'default:group:domain\\040admins:rwx',
        'default:mask::rwx',
        'default:other::---',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        config(['filesystem.shares_root' => '/var/sambaedu/Partages']);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    private function assign(NetworkShare $share, string $type, int $id, string $access = 'ro'): void
    {
        NetworkShareAssignable::create([
            'network_share_id' => $share->id,
            'assignable_type' => $type,
            'assignable_id' => $id,
            'access' => $access,
        ]);
    }

    /**
     * Le NOUVEAU chemin, de bout en bout : projection du répertoire en plan neutre
     * (au-dessus de la ligne), puis compilation du nœud racine (sous la ligne).
     *
     * @return list<string>
     */
    private function compile(NetworkShare $share): array
    {
        $plan = app(SharePlanProjector::class)->project($share->load('assignments'));

        return app(PosixAclCompiler::class)->compile($plan->nodes[0])->acls;
    }

    // =========================================================================
    // Les six situations du référentiel
    // =========================================================================

    #[Test]
    public function a_directory_without_audience_yields_exactly_the_canonical_base(): void
    {
        Process::fake();
        $share = NetworkShare::factory()->create(['directory_name' => 'commun', 'name' => 'Commun']);

        self::assertSame(self::BASE, $this->compile($share));
    }

    #[Test]
    public function user_grants_keep_their_levels_their_inheritance_mirrors_and_their_case(): void
    {
        Process::fake();
        $alice = User::factory()->create(['login' => 'alice']);
        $bob = User::factory()->create(['login' => 'Bob.Martin']);
        $share = NetworkShare::factory()->create(['directory_name' => 'mix', 'name' => 'Mix']);
        $this->assign($share, User::class, $alice->id, 'ro');
        $this->assign($share, User::class, $bob->id, 'rw');

        self::assertSame([
            ...self::BASE,
            'user:alice:rx',
            'default:user:alice:rx',
            'user:Bob.Martin:rwx',
            'default:user:Bob.Martin:rwx',
        ], $this->compile($share));
    }

    /**
     * Les trois formes de nommage de groupe d'un seul coup : préfixe de classe,
     * anti double-préfixe pour une équipe déjà préfixée, nom nu pour un collectif
     * ordinaire — et le suffixe d'établissement dérivé du DN dans les deux
     * premiers cas.
     */
    #[Test]
    public function group_grants_keep_the_historic_naming_including_the_establishment_suffix(): void
    {
        Process::fake();
        [$classe, $equipe, $custom] = $this->makeGroups();

        $share = NetworkShare::factory()->create(['directory_name' => 'groupes', 'name' => 'Groupes']);
        $this->assign($share, UserGroup::class, $classe->id, 'rw');
        $this->assign($share, UserGroup::class, $equipe->id, 'ro');
        $this->assign($share, UserGroup::class, $custom->id, 'rw');

        self::assertSame([
            ...self::BASE,
            'group:classe_3sb-1229y:rwx',
            'default:group:classe_3sb-1229y:rwx',
            'group:equipe_3sb-1229y:rx',
            'default:group:equipe_3sb-1229y:rx',
            'group:direction:rwx',
            'default:group:direction:rwx',
        ], $this->compile($share));
    }

    #[Test]
    public function a_workstation_group_contributes_nothing_it_is_mount_only(): void
    {
        Process::fake();
        $wg = WorkstationGroup::factory()->logical()->create();
        $share = NetworkShare::factory()->create(['directory_name' => 'montage', 'name' => 'Montage']);
        $this->assign($share, WorkstationGroup::class, $wg->id, 'rw');

        self::assertSame(self::BASE, $this->compile($share));
    }

    /**
     * Un compte dont le nom d'ouverture de session n'est pas utilisable côté
     * système ne produit AUCUNE entrée — exactement comme avant. Ce qui change
     * n'est pas le disque, c'est le silence : le refus est désormais un état de
     * rapport (vérifié ailleurs), pas une ligne de journal que personne ne lit.
     */
    #[Test]
    public function an_unusable_login_writes_nothing_exactly_like_before(): void
    {
        Process::fake();
        $bad = User::factory()->create(['login' => 'in valid']);
        $share = NetworkShare::factory()->create(['directory_name' => 'invalide', 'name' => 'Invalide']);
        $this->assign($share, User::class, $bad->id, 'rw');

        self::assertSame(self::BASE, $this->compile($share));
    }

    /**
     * La situation composite — la seule où les deux ordres d'émission divergent
     * (voir le docblock de classe). L'ENSEMBLE est identique chaîne par chaîne ;
     * l'ordre canonique est figé à part.
     */
    #[Test]
    public function the_composite_case_is_identical_as_a_set_to_the_frozen_reference(): void
    {
        Process::fake();
        $alice = User::factory()->create(['login' => 'alice']);
        $bob = User::factory()->create(['login' => 'Bob.Martin']);
        [$classe, , $custom] = $this->makeGroups();
        $wg = WorkstationGroup::factory()->logical()->create();

        $share = NetworkShare::factory()->create(['directory_name' => 'composite', 'name' => 'Composite']);
        $this->assign($share, User::class, $alice->id, 'ro');
        $this->assign($share, User::class, $bob->id, 'rw');
        $this->assign($share, UserGroup::class, $classe->id, 'rw');
        $this->assign($share, UserGroup::class, $custom->id, 'ro');
        $this->assign($share, WorkstationGroup::class, $wg->id, 'rw');

        // Référentiel CAPTURÉ avant la descente, dans l'ordre du moteur.
        $frozen = [
            ...self::BASE,
            'user:alice:rx',
            'default:user:alice:rx',
            'group:classe_3sb-1229y:rwx',
            'default:group:classe_3sb-1229y:rwx',
            'user:Bob.Martin:rwx',
            'default:user:Bob.Martin:rwx',
            'group:direction:rx',
            'default:group:direction:rx',
        ];

        // Ordre CANONIQUE du plan : mêmes chaînes, sujets groupés par type.
        $canonical = [
            ...self::BASE,
            'user:alice:rx',
            'default:user:alice:rx',
            'user:Bob.Martin:rwx',
            'default:user:Bob.Martin:rwx',
            'group:classe_3sb-1229y:rwx',
            'default:group:classe_3sb-1229y:rwx',
            'group:direction:rx',
            'default:group:direction:rx',
        ];

        $compiled = $this->compile($share);

        self::assertSame($canonical, $compiled, 'l\'ordre canonique du plan est lui aussi figé');

        $sortedFrozen = $frozen;
        $sortedCompiled = $compiled;
        sort($sortedFrozen);
        sort($sortedCompiled);
        self::assertSame(
            $sortedFrozen,
            $sortedCompiled,
            'ENSEMBLE D\'ENTRÉES DIVERGENT du référentiel figé avant la descente : le disque bougerait.',
        );
    }

    /**
     * Propriété NOUVELLE, et c'est pour elle que la divergence d'ordre est
     * acceptable : deux saisies du même état produisent désormais la même sortie,
     * quel que soit l'ordre dans lequel les cibles ont été assignées. L'ancienne
     * dérivation ne l'avait pas.
     */
    #[Test]
    public function the_emission_order_no_longer_depends_on_the_order_of_entry(): void
    {
        Process::fake();
        $alice = User::factory()->create(['login' => 'alice']);
        [$classe] = $this->makeGroups();

        $first = NetworkShare::factory()->create(['directory_name' => 'ordreun', 'name' => 'Ordre 1']);
        $this->assign($first, User::class, $alice->id, 'ro');
        $this->assign($first, UserGroup::class, $classe->id, 'rw');

        $second = NetworkShare::factory()->create(['directory_name' => 'ordredeux', 'name' => 'Ordre 2']);
        $this->assign($second, UserGroup::class, $classe->id, 'rw');
        $this->assign($second, User::class, $alice->id, 'ro');

        self::assertSame($this->compile($first), $this->compile($second));
    }

    /**
     * Le référentiel ne vaut que si le groupe se résout côté système. La règle
     * « jamais un nom inventé » ne l'entame pas sur le chemin normal, et elle est
     * ce qui empêche une entrée posée sans effet ailleurs.
     */
    #[Test]
    public function the_frozen_reference_holds_only_because_the_system_resolves_the_group(): void
    {
        Process::fake([
            'getent group *' => Process::result(output: '', exitCode: 2),
            '*' => Process::result(output: '', exitCode: 0),
        ]);
        [$classe] = $this->makeGroups();
        $share = NetworkShare::factory()->create(['directory_name' => 'inconnu', 'name' => 'Inconnu']);
        $this->assign($share, UserGroup::class, $classe->id, 'rw');

        self::assertSame(self::BASE, $this->compile($share), 'un groupe non résolu ne doit produire aucune entrée');
    }

    /** @return array{0:UserGroup,1:UserGroup,2:UserGroup} */
    private function makeGroups(): array
    {
        return [
            UserGroup::create([
                'name' => 'Classe_3SB', 'type' => 'classe',
                'ad_dn' => 'CN=Classe_3SB,OU=Groupes,OU=0991229y,DC=lab,DC=lan',
            ]),
            UserGroup::create([
                'name' => 'equipe_3SB', 'type' => 'equipe',
                'ad_dn' => 'CN=equipe_3SB,OU=Groupes,OU=0991229y,DC=lab,DC=lan',
            ]),
            UserGroup::create(['name' => 'Direction', 'type' => 'custom', 'ad_dn' => null]),
        ];
    }
}
