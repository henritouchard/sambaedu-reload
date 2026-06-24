<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Facades\SEConfig;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Repositories\GroupRepository;
use App\Repositories\RightRepository;
use App\Repositories\UserGroupRepository;
use App\Services\UserGroupService;
use ReflectionClass;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class UserGroupServiceLegacyCompatibilityTest extends TestCase
{
    use DatabaseTransactions;

    /** true si on a créé les tables nous-mêmes (SQLite :memory:) */
    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestTables();
        UserGroupObserver::disableSync();
    }

    protected function tearDown(): void
    {
        // Nettoyer uniquement si on a créé les tables (SQLite :memory:)
        if ($this->createdTables) {
            Schema::dropIfExists('user_group_user');
            Schema::dropIfExists('user_groups');
            Schema::dropIfExists('users');
        }
        UserGroupObserver::enableSync();

        // Purge le cache statique request-scope de User (rempli par primeNoLdap)
        // pour éviter qu'un login réutilisé hérite d'une entrée d'un test antérieur.
        $ref = new ReflectionClass(User::class);
        $prop = $ref->getProperty('ldapCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        parent::tearDown();
    }

    #[Test]
    public function it_creates_three_sql_groups_for_classe_like_legacy(): void
    {
        $service = $this->makeService(
            collect([
                [
                    'cn' => 'Classe_3emeA',
                    'dn' => 'CN=Classe_3emeA,OU=Classes,OU=Groups,DC=example,DC=local',
                    'description' => '3ème A',
                ],
                [
                    'cn' => 'Equipe_3emeA',
                    'dn' => 'CN=Equipe_3emeA,OU=Equipes,OU=Groups,DC=example,DC=local',
                    'description' => 'Equipe pédagogique de 3ème A',
                ],
                [
                    'cn' => 'PP_3emeA',
                    'dn' => 'CN=PP_3emeA,OU=Equipes,OU=Groups,DC=example,DC=local',
                    'description' => 'Profs principaux de 3ème A',
                ],
            ]),
            [],
            [
                'Classe_3emeA' => [
                    ['cn' => 'alice', 'dn' => 'CN=alice,OU=Users,DC=example,DC=local'],
                ],
                'Equipe_3emeA' => [],
                'PP_3emeA' => [],
            ],
        );

        $user = User::query()->create([
            'login' => 'alice',
            'role' => 'eleve',
            'is_active' => true,
        ]);

        $this->primeNoLdap('alice');

        $primary = $service->createGroup([
            'name' => '3emeA',
            'display_name' => '3ème A',
            'type' => 'classe',
            'user_ids' => [$user->id],
        ]);

        $this->assertSame('Classe_3emeA', $primary->name);

        $names = UserGroup::query()->orderBy('name')->pluck('name')->all();
        $this->assertSame(
            ['Classe_3emeA', 'Equipe_3emeA', 'PP_3emeA'],
            $names
        );

        $this->assertSame(1, UserGroup::query()->where('name', 'Classe_3emeA')->firstOrFail()->users()->count());
        $this->assertSame(0, UserGroup::query()->where('name', 'Equipe_3emeA')->firstOrFail()->users()->count());
        $this->assertSame(0, UserGroup::query()->where('name', 'PP_3emeA')->firstOrFail()->users()->count());
    }

    #[Test]
    public function it_creates_two_sql_groups_for_cours_like_legacy(): void
    {
        $service = $this->makeService(
            collect([
                [
                    'cn' => 'Cours_Maths5A',
                    'dn' => 'CN=Cours_Maths5A,OU=Cours,OU=Groups,DC=example,DC=local',
                    'description' => 'Cours de Maths 5A',
                ],
                [
                    'cn' => 'Equipe_Maths5A',
                    'dn' => 'CN=Equipe_Maths5A,OU=Equipes,OU=Groups,DC=example,DC=local',
                    'description' => 'Equipe pédagogique de Maths 5A',
                ],
            ]),
            [],
        );

        $service->createGroup([
            'name' => 'Maths5A',
            'display_name' => 'Maths 5A',
            'type' => 'cours',
        ]);

        $names = UserGroup::query()->orderBy('name')->pluck('name')->all();
        $this->assertSame(['Cours_Maths5A', 'Equipe_Maths5A'], $names);
    }

    #[Test]
    public function it_creates_matiere_classe_group_with_legacy_naming(): void
    {
        $service = $this->makeService(
            collect([
                [
                    'cn' => 'Matiere_Math@3emeA',
                    'dn' => 'CN=Matiere_Math@3emeA,OU=Equipes,OU=Groups,DC=example,DC=local',
                    'description' => 'Equipe pédagogique de la matière Math 3ème A',
                ],
            ]),
            [],
        );

        $group = $service->createGroup([
            'name' => 'Math@3emeA',
            'display_name' => 'Math 3ème A',
            'type' => 'matiere_classe',
        ]);

        $this->assertSame('Matiere_Math@3emeA', $group->name);
        $this->assertSame('matiere_classe', $group->type);
    }

    #[Test]
    public function it_imports_ad_groups_with_legacy_type_detection_and_rights_exclusion(): void
    {
        $groupRows = collect([
            [
                'cn' => 'Cours_Histoire4A',
                'dn' => 'CN=Cours_Histoire4A,OU=Cours,OU=Groups,DC=example,DC=local',
            ],
            [
                'cn' => 'Matiere_Math@3emeA',
                'dn' => 'CN=Matiere_Math@3emeA,OU=Equipes,OU=Groups,DC=example,DC=local',
            ],
            [
                'cn' => 'sovajon_is_admin',
                'dn' => 'CN=sovajon_is_admin,OU=Rights,OU=Groups,DC=example,DC=local',
            ],
        ]);

        $service = $this->makeService(
            $groupRows,
            ['RefNum' => 'x'],
            [
                'Cours_Histoire4A' => [
                    ['cn' => 'bob', 'dn' => 'CN=bob,OU=Users,DC=example,DC=local'],
                ],
                'Matiere_Math@3emeA' => [],
            ],
        );

        SEConfig::shouldReceive('get')
            ->andReturnUsing(static function (string $key, mixed $default = null): mixed {
                return match ($key) {
                    'rights_rdn' => 'OU=Rights',
                    'delegations_rdn' => 'OU=Delegations',
                    'groups_rdn' => 'OU=Groups',
                    default => $default,
                };
            });

        $user = User::query()->create([
            'login' => 'bob',
            'role' => 'prof',
            'is_active' => true,
        ]);

        $stats = $service->importFromUsersAdGroups();

        $this->assertSame(2, $stats['created']);
        $this->assertFalse(UserGroup::query()->where('name', 'RefNum')->exists());
        $this->assertFalse(UserGroup::query()->where('name', 'sovajon_is_admin')->exists());

        $this->assertSame('cours', UserGroup::query()->where('name', 'Cours_Histoire4A')->firstOrFail()->type);
        $this->assertSame('matiere_classe', UserGroup::query()->where('name', 'Matiere_Math@3emeA')->firstOrFail()->type);

        $this->assertSame(1, UserGroup::query()->where('name', 'Cours_Histoire4A')->firstOrFail()->users()->count());
        $this->assertSame($user->id, UserGroup::query()->where('name', 'Cours_Histoire4A')->firstOrFail()->users()->firstOrFail()->id);
        $this->assertFalse(UserGroup::query()->where('name', 'Classe_3emeA')->exists());
    }

    #[Test]
    public function it_partitions_members_by_role_between_equipe_and_classe(): void
    {
        // 1 prof + 2 élèves dans une classe 3A : le prof doit aller dans
        // Equipe_3A, les 2 élèves dans Classe_3A. PP_3A reste vide.
        $service = $this->makeService(
            collect([
                $this->adGroupRow('Classe_3A'),
                $this->adGroupRow('Equipe_3A', 'OU=Equipes'),
                $this->adGroupRow('PP_3A', 'OU=Equipes'),
            ]),
            [],
            [
                'Classe_3A' => [],
                'Equipe_3A' => [],
                'PP_3A' => [],
            ],
        );

        $prof = User::query()->create([
            'login' => 'prof.martin',
            'role' => 'prof',
            'dn' => 'CN=prof.martin,OU=Users,DC=example,DC=local',
            'is_active' => true,
        ]);
        $eleve1 = User::query()->create([
            'login' => 'eleve.un',
            'role' => 'eleve',
            'dn' => 'CN=eleve.un,OU=Users,DC=example,DC=local',
            'is_active' => true,
        ]);
        $eleve2 = User::query()->create([
            'login' => 'eleve.deux',
            'role' => 'eleve',
            'dn' => 'CN=eleve.deux,OU=Users,DC=example,DC=local',
            'is_active' => true,
        ]);

        $this->primeNoLdap('prof.martin', 'eleve.un', 'eleve.deux');

        $service->createGroup([
            'name' => '3A',
            'display_name' => '3A',
            'type' => 'classe',
            'user_ids' => [$prof->id, $eleve1->id, $eleve2->id],
        ]);

        $this->assertSame(
            ['CN=prof.martin,OU=Users,DC=example,DC=local'],
            $this->addedDnsFor('Equipe_3A')
        );
        $this->assertEqualsCanonicalizing(
            [
                'CN=eleve.un,OU=Users,DC=example,DC=local',
                'CN=eleve.deux,OU=Users,DC=example,DC=local',
            ],
            $this->addedDnsFor('Classe_3A')
        );

        // PP_X jamais peuplé (D1 — différé).
        $this->assertSame([], $this->addedDnsFor('PP_3A'));
        $this->assertSame([], $this->removedDnsFor('PP_3A'));
    }

    #[Test]
    public function it_is_idempotent_when_resyncing_same_partition(): void
    {
        // Le prof est déjà dans Equipe_3A et l'élève déjà dans Classe_3A :
        // un nouveau sync ne doit produire aucun add ni remove.
        $service = $this->makeService(
            collect([
                $this->adGroupRow('Classe_3A'),
                $this->adGroupRow('Equipe_3A', 'OU=Equipes'),
                $this->adGroupRow('PP_3A', 'OU=Equipes'),
            ]),
            [],
            [
                'Classe_3A' => [['dn' => 'CN=eleve.un,OU=Users,DC=example,DC=local']],
                'Equipe_3A' => [['dn' => 'CN=prof.martin,OU=Users,DC=example,DC=local']],
                'PP_3A' => [],
            ],
            mutableMembership: true,
        );

        $prof = User::query()->create([
            'login' => 'prof.martin',
            'role' => 'prof',
            'dn' => 'CN=prof.martin,OU=Users,DC=example,DC=local',
            'is_active' => true,
        ]);
        $eleve = User::query()->create([
            'login' => 'eleve.un',
            'role' => 'eleve',
            'dn' => 'CN=eleve.un,OU=Users,DC=example,DC=local',
            'is_active' => true,
        ]);

        $this->primeNoLdap('prof.martin', 'eleve.un');

        // Le CN primaire stocké en SQL est `Classe_3A` (résolu à la création) ;
        // l'edit-form renvoie ce nom. Le helper doit dériver la base `3A` et
        // partitionner sans rien changer (membres déjà bien placés).
        $group = UserGroup::query()->create([
            'name' => 'Classe_3A',
            'display_name' => '3A',
            'type' => 'classe',
        ]);

        $service->updateGroup($group->id, [
            'name' => 'Classe_3A',
            'display_name' => '3A',
            'type' => 'classe',
            'user_ids' => [$prof->id, $eleve->id],
        ]);

        $this->assertSame([], $this->addedDnsFor('Equipe_3A'));
        $this->assertSame([], $this->addedDnsFor('Classe_3A'));
        $this->assertSame([], $this->removedDnsFor('Equipe_3A'));
        $this->assertSame([], $this->removedDnsFor('Classe_3A'));
    }

    #[Test]
    public function it_removes_prof_from_equipe_when_detached(): void
    {
        // Le prof est dans Equipe_3A puis n'est plus sélectionné : il doit
        // être retiré d'Equipe_3A (fail-soft, DN connu SQL).
        $service = $this->makeService(
            collect([
                $this->adGroupRow('Classe_3A'),
                $this->adGroupRow('Equipe_3A', 'OU=Equipes'),
                $this->adGroupRow('PP_3A', 'OU=Equipes'),
            ]),
            [],
            [
                'Classe_3A' => [['dn' => 'CN=eleve.un,OU=Users,DC=example,DC=local']],
                'Equipe_3A' => [['dn' => 'CN=prof.martin,OU=Users,DC=example,DC=local']],
                'PP_3A' => [],
            ],
            mutableMembership: true,
        );

        // Le prof reste connu SQL (sa branche removeMember ne porte que sur les DN SQL).
        User::query()->create([
            'login' => 'prof.martin',
            'role' => 'prof',
            'dn' => 'CN=prof.martin,OU=Users,DC=example,DC=local',
            'is_active' => true,
        ]);
        $eleve = User::query()->create([
            'login' => 'eleve.un',
            'role' => 'eleve',
            'dn' => 'CN=eleve.un,OU=Users,DC=example,DC=local',
            'is_active' => true,
        ]);

        $this->primeNoLdap('prof.martin', 'eleve.un');

        $group = UserGroup::query()->create([
            'name' => 'Classe_3A',
            'display_name' => '3A',
            'type' => 'classe',
        ]);

        // On ne garde que l'élève.
        $service->updateGroup($group->id, [
            'name' => 'Classe_3A',
            'display_name' => '3A',
            'type' => 'classe',
            'user_ids' => [$eleve->id],
        ]);

        $this->assertSame(
            ['CN=prof.martin,OU=Users,DC=example,DC=local'],
            $this->removedDnsFor('Equipe_3A')
        );
        $this->assertSame([], $this->removedDnsFor('Classe_3A'));
        $this->assertSame([], $this->addedDnsFor('Classe_3A')); // élève déjà présent
    }

    #[Test]
    public function it_moves_member_between_equipe_and_classe_on_role_switch(): void
    {
        // Un membre était prof (dans Equipe_3A), devient élève : au sync suivant
        // il doit être retiré d'Equipe_3A et ajouté à Classe_3A (jamais dans les deux).
        $service = $this->makeService(
            collect([
                $this->adGroupRow('Classe_3A'),
                $this->adGroupRow('Equipe_3A', 'OU=Equipes'),
                $this->adGroupRow('PP_3A', 'OU=Equipes'),
            ]),
            [],
            [
                'Classe_3A' => [],
                'Equipe_3A' => [['dn' => 'CN=jean.dupont,OU=Users,DC=example,DC=local']],
                'PP_3A' => [],
            ],
            mutableMembership: true,
        );

        // Bascule prof -> eleve.
        $switched = User::query()->create([
            'login' => 'jean.dupont',
            'role' => 'eleve',
            'dn' => 'CN=jean.dupont,OU=Users,DC=example,DC=local',
            'is_active' => true,
        ]);

        $this->primeNoLdap('jean.dupont');

        $group = UserGroup::query()->create([
            'name' => 'Classe_3A',
            'display_name' => '3A',
            'type' => 'classe',
        ]);

        $service->updateGroup($group->id, [
            'name' => 'Classe_3A',
            'display_name' => '3A',
            'type' => 'classe',
            'user_ids' => [$switched->id],
        ]);

        // Retiré d'Equipe_3A, ajouté à Classe_3A.
        $this->assertSame(
            ['CN=jean.dupont,OU=Users,DC=example,DC=local'],
            $this->removedDnsFor('Equipe_3A')
        );
        $this->assertSame(
            ['CN=jean.dupont,OU=Users,DC=example,DC=local'],
            $this->addedDnsFor('Classe_3A')
        );
        $this->assertSame([], $this->addedDnsFor('Equipe_3A'));

        // Plus présent dans Equipe_3A, présent dans Classe_3A (état mutable final).
        $this->assertSame([], $this->adMembersByCn['Equipe_3A']);
        $this->assertSame(
            [['dn' => 'CN=jean.dupont,OU=Users,DC=example,DC=local']],
            $this->adMembersByCn['Classe_3A']
        );
    }

    #[Test]
    public function it_moves_member_from_classe_to_equipe_on_eleve_to_prof_switch(): void
    {
        // Sens inverse (AC3 « ou inverse ») : un membre était élève (dans Classe_3A),
        // devient prof : au sync suivant il doit être retiré de Classe_3A et ajouté
        // à Equipe_3A (jamais dans les deux).
        $service = $this->makeService(
            collect([
                $this->adGroupRow('Classe_3A'),
                $this->adGroupRow('Equipe_3A', 'OU=Equipes'),
                $this->adGroupRow('PP_3A', 'OU=Equipes'),
            ]),
            [],
            [
                'Classe_3A' => [['dn' => 'CN=jean.dupont,OU=Users,DC=example,DC=local']],
                'Equipe_3A' => [],
                'PP_3A' => [],
            ],
            mutableMembership: true,
        );

        // Bascule eleve -> prof.
        $switched = User::query()->create([
            'login' => 'jean.dupont',
            'role' => 'prof',
            'dn' => 'CN=jean.dupont,OU=Users,DC=example,DC=local',
            'is_active' => true,
        ]);

        $this->primeNoLdap('jean.dupont');

        $group = UserGroup::query()->create([
            'name' => 'Classe_3A',
            'display_name' => '3A',
            'type' => 'classe',
        ]);

        $service->updateGroup($group->id, [
            'name' => 'Classe_3A',
            'display_name' => '3A',
            'type' => 'classe',
            'user_ids' => [$switched->id],
        ]);

        // Retiré de Classe_3A, ajouté à Equipe_3A.
        $this->assertSame(
            ['CN=jean.dupont,OU=Users,DC=example,DC=local'],
            $this->removedDnsFor('Classe_3A')
        );
        $this->assertSame(
            ['CN=jean.dupont,OU=Users,DC=example,DC=local'],
            $this->addedDnsFor('Equipe_3A')
        );
        $this->assertSame([], $this->addedDnsFor('Classe_3A'));

        // Plus présent dans Classe_3A, présent dans Equipe_3A (état mutable final).
        $this->assertSame([], $this->adMembersByCn['Classe_3A']);
        $this->assertSame(
            [['dn' => 'CN=jean.dupont,OU=Users,DC=example,DC=local']],
            $this->adMembersByCn['Equipe_3A']
        );
    }

    #[Test]
    public function it_rejects_reserved_prefix_name_for_classe_like_create(): void
    {
        // Garde-fou : un nom NU portant un préfixe réservé (Classe_/Equipe_/PP_)
        // pour un type classe/équipe doit être rejeté dès la validation — sinon
        // l'expansion viserait des CN AD fantômes (échec LDAP silencieux).
        $service = $this->makeService(collect([]), []);

        $this->expectException(\InvalidArgumentException::class);

        $service->createGroup([
            'name' => 'PP_terminale',
            'display_name' => 'PP terminale',
            'type' => 'classe',
        ]);
    }

    #[Test]
    public function it_keeps_single_target_for_non_classe_types(): void
    {
        // Type cours : pas de partition par rôle, une seule cible (Cours_X).
        $service = $this->makeService(
            collect([
                $this->adGroupRow('Cours_Maths5A', 'OU=Cours'),
                $this->adGroupRow('Equipe_Maths5A', 'OU=Equipes'),
            ]),
            [],
            [
                'Cours_Maths5A' => [],
                'Equipe_Maths5A' => [],
            ],
        );

        $prof = User::query()->create([
            'login' => 'prof.maths',
            'role' => 'prof',
            'dn' => 'CN=prof.maths,OU=Users,DC=example,DC=local',
            'is_active' => true,
        ]);

        $this->primeNoLdap('prof.maths');

        $service->createGroup([
            'name' => 'Maths5A',
            'display_name' => 'Maths 5A',
            'type' => 'cours',
            'user_ids' => [$prof->id],
        ]);

        $this->assertSame(
            ['CN=prof.maths,OU=Users,DC=example,DC=local'],
            $this->addedDnsFor('Cours_Maths5A')
        );
        // Aucune écriture sur Equipe_Maths5A (pas de partition pour le type cours).
        $this->assertSame([], $this->addedDnsFor('Equipe_Maths5A'));
        $this->assertSame([], $this->removedDnsFor('Equipe_Maths5A'));
    }

    #[Test]
    public function it_does_not_re_expand_prefixed_matiere_classe_cn(): void
    {
        // Un CN legacy préfixé (Matiere_Math@3emeA) ne doit jamais être ré-expansé
        // en Equipe_/Classe_ : on cible exactement ce groupe.
        $service = $this->makeService(
            collect([
                $this->adGroupRow('Matiere_Math@3emeA', 'OU=Equipes'),
            ]),
            [],
            [
                'Matiere_Math@3emeA' => [],
            ],
        );

        $prof = User::query()->create([
            'login' => 'prof.math',
            'role' => 'prof',
            'dn' => 'CN=prof.math,OU=Users,DC=example,DC=local',
            'is_active' => true,
        ]);

        $this->primeNoLdap('prof.math');

        $service->createGroup([
            'name' => 'Math@3emeA',
            'display_name' => 'Math 3ème A',
            'type' => 'matiere_classe',
            'user_ids' => [$prof->id],
        ]);

        $this->assertSame(
            ['CN=prof.math,OU=Users,DC=example,DC=local'],
            $this->addedDnsFor('Matiere_Math@3emeA')
        );
        // Aucune expansion vers Equipe_/Classe_.
        $this->assertSame([], $this->addedDnsFor('Equipe_Math@3emeA'));
        $this->assertSame([], $this->addedDnsFor('Classe_Math@3emeA'));
    }

    /**
     * Court-circuite la résolution LDAP de `User::isProf()/isEleve()` en
     * pré-remplissant le cache request-scope statique avec `null` : sans
     * connexion AD sur l'hôte de test, la résolution retombe alors sur
     * `users.role` (comportement de fallback déjà présent dans le modèle).
     */
    private function primeNoLdap(string ...$logins): void
    {
        $ref = new ReflectionClass(User::class);
        $prop = $ref->getProperty('ldapCache');
        $prop->setAccessible(true);

        /** @var array<string,mixed> $cache */
        $cache = $prop->getValue();

        foreach ($logins as $login) {
            $cache['ldap:' . $login] = null;
            $cache['bo:' . $login] = null;
        }

        $prop->setValue(null, $cache);
    }

    private function adGroupRow(string $cn, string $ou = 'OU=Classes'): array
    {
        return [
            'cn' => $cn,
            'dn' => "CN={$cn},{$ou},OU=Groups,DC=example,DC=local",
            'description' => $cn,
        ];
    }

    /**
     * Journal des appels add/remove vers la couche AD du dernier service fabriqué.
     * Forme : [['op' => 'add'|'remove', 'group' => string, 'dn' => string], …]
     *
     * @var array<int,array{op:string,group:string,dn:string}>
     */
    private array $membershipCalls = [];

    /**
     * État mutable des membres AD par CN, partagé entre getGroupMembers/add/remove
     * pour simuler l'idempotence réelle de la couche LDAP.
     *
     * @var array<string,array<int,array{cn?:string,dn:string}>>
     */
    private array $adMembersByCn = [];

    /**
     * @param array<string,array<int,array{cn:string,dn:string}>> $groupMembersByCn
     */
    private function makeService(
        Collection $groupsWithMemberCount,
        array $rights,
        array $groupMembersByCn = [],
        bool $mutableMembership = false,
    ): UserGroupService {
        $this->membershipCalls = [];
        $this->adMembersByCn = $groupMembersByCn;

        $groupRepository = $this->createMock(GroupRepository::class);
        $groupRepository->method('getGroupsWithMemberCount')->willReturn($groupsWithMemberCount);
        $groupRepository->method('createGroup')->willReturn(true);
        $groupRepository->method('deleteGroup')->willReturn(true);
        $groupRepository->method('updateGroupDescription')->willReturn(true);

        $groupRepository->method('addMember')->willReturnCallback(
            function (string $cn, string $dn) use ($mutableMembership): bool {
                $this->membershipCalls[] = ['op' => 'add', 'group' => $cn, 'dn' => $dn];

                if ($mutableMembership) {
                    $this->adMembersByCn[$cn] ??= [];
                    foreach ($this->adMembersByCn[$cn] as $member) {
                        if (($member['dn'] ?? '') === $dn) {
                            return true;
                        }
                    }
                    $this->adMembersByCn[$cn][] = ['dn' => $dn];
                }

                return true;
            }
        );

        $groupRepository->method('removeMember')->willReturnCallback(
            function (string $cn, string $dn) use ($mutableMembership): bool {
                $this->membershipCalls[] = ['op' => 'remove', 'group' => $cn, 'dn' => $dn];

                if ($mutableMembership && isset($this->adMembersByCn[$cn])) {
                    $this->adMembersByCn[$cn] = array_values(array_filter(
                        $this->adMembersByCn[$cn],
                        static fn(array $member): bool => ($member['dn'] ?? '') !== $dn
                    ));
                }

                return true;
            }
        );

        $groupRepository->method('getGroupMembers')->willReturnCallback(
            fn(string $cn): Collection => collect($this->adMembersByCn[$cn] ?? [])
        );

        $rightRepository = $this->createMock(RightRepository::class);
        $rightRepository->method('getAllRightsValues')->willReturn($rights);

        return new UserGroupService(
            new UserGroupRepository(),
            $groupRepository,
            $rightRepository,
        );
    }

    /**
     * @return array<int,string> DN ajoutés au groupe $cn dans l'ordre des appels
     */
    private function addedDnsFor(string $cn): array
    {
        return array_values(array_map(
            static fn(array $call): string => $call['dn'],
            array_filter(
                $this->membershipCalls,
                static fn(array $call): bool => $call['op'] === 'add' && $call['group'] === $cn
            )
        ));
    }

    /**
     * @return array<int,string> DN retirés du groupe $cn dans l'ordre des appels
     */
    private function removedDnsFor(string $cn): array
    {
        return array_values(array_map(
            static fn(array $call): string => $call['dn'],
            array_filter(
                $this->membershipCalls,
                static fn(array $call): bool => $call['op'] === 'remove' && $call['group'] === $cn
            )
        ));
    }

    private function createTestTables(): void
    {
        // En SQLite :memory:, les tables n'existent pas → les créer
        // Sur PostgreSQL (VM), les tables existent déjà via les migrations → ne pas y toucher
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('login')->unique();
                $table->string('password')->nullable();
                $table->string('fullname')->nullable();
                $table->string('firstname')->nullable();
                $table->string('lastname')->nullable();
                $table->string('email')->nullable();
                $table->text('dn')->nullable();
                $table->string('role')->default('autre');
                $table->boolean('is_active')->default(true);
                $table->json('ad_right_profiles')->nullable();
                $table->integer('ad_rights_bitmask')->default(0);
                $table->timestamp('ad_synced_at')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('user_groups')) {
            Schema::create('user_groups', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->unique();
                $table->string('display_name')->nullable();
                $table->string('type');
                $table->text('ad_dn')->nullable();
                $table->string('ad_guid')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('user_group_user')) {
            Schema::create('user_group_user', function (Blueprint $table): void {
                $table->unsignedBigInteger('user_group_id');
                $table->unsignedBigInteger('user_id');
                $table->primary(['user_group_id', 'user_id']);
            });
            $this->createdTables = true;
        }
    }
}
