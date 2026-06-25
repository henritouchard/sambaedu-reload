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
    public function it_folds_classe_variants_into_one_bare_name_group(): void
    {
        // 4.13 — Les 3 CN AD d'une classe (Classe_/Equipe_/PP_) foldent en UNE
        // seule ligne SQL au nom nu (`3emeA`, type classe). Aucune ligne
        // préfixée ne subsiste ; createGroup retourne la ligne nue.
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

        $folded = $service->createGroup([
            'name' => '3emeA',
            'display_name' => '3ème A',
            'type' => 'classe',
            'user_ids' => [$user->id],
        ]);

        // createGroup retourne la ligne nue.
        $this->assertSame('3emeA', $folded->name);
        $this->assertSame('classe', $folded->type);

        // UNE seule ligne, au nom nu — aucune variante préfixée.
        $names = UserGroup::query()->orderBy('name')->pluck('name')->all();
        $this->assertSame(['3emeA'], $names);

        $this->assertFalse(UserGroup::query()->where('name', 'Classe_3emeA')->exists());
        $this->assertFalse(UserGroup::query()->where('name', 'Equipe_3emeA')->exists());
        $this->assertFalse(UserGroup::query()->where('name', 'PP_3emeA')->exists());

        // L'unique membre (alice, via Classe_3emeA) est sur la ligne nue.
        $this->assertSame(1, $folded->users()->count());
    }

    #[Test]
    public function it_uses_canonical_classe_guid_for_folded_group(): void
    {
        // AC3 — ad_guid/ad_dn de la ligne nue = ceux du CN canonique Classe_.
        $classeGuid = '11111111-1111-1111-1111-111111111111';
        $service = $this->makeService(
            collect([
                [
                    'cn' => 'Equipe_3A',
                    'dn' => 'CN=Equipe_3A,OU=Equipes,OU=Groups,DC=example,DC=local',
                    'description' => 'Equipe 3A',
                    'objectguid' => '22222222-2222-2222-2222-222222222222',
                ],
                [
                    'cn' => 'Classe_3A',
                    'dn' => 'CN=Classe_3A,OU=Classes,OU=Groups,DC=example,DC=local',
                    'description' => '3A',
                    'objectguid' => $classeGuid,
                ],
                [
                    'cn' => 'PP_3A',
                    'dn' => 'CN=PP_3A,OU=Equipes,OU=Groups,DC=example,DC=local',
                    'description' => 'PP 3A',
                    'objectguid' => '33333333-3333-3333-3333-333333333333',
                ],
            ]),
            [],
            ['Classe_3A' => [], 'Equipe_3A' => [], 'PP_3A' => []],
        );

        $service->importFromUsersAdGroups();

        $group = UserGroup::query()->where('name', '3A')->firstOrFail();
        $this->assertSame($classeGuid, $group->ad_guid);
        $this->assertSame('CN=Classe_3A,OU=Classes,OU=Groups,DC=example,DC=local', $group->ad_dn);
    }

    #[Test]
    public function it_falls_back_to_equipe_guid_when_classe_absent(): void
    {
        // AC3 — fallback déterministe Equipe_ quand Classe_ absent du lot.
        $equipeGuid = '44444444-4444-4444-4444-444444444444';
        $service = $this->makeService(
            collect([
                [
                    'cn' => 'PP_3A',
                    'dn' => 'CN=PP_3A,OU=Equipes,OU=Groups,DC=example,DC=local',
                    'description' => 'PP 3A',
                    'objectguid' => '55555555-5555-5555-5555-555555555555',
                ],
                [
                    'cn' => 'Equipe_3A',
                    'dn' => 'CN=Equipe_3A,OU=Equipes,OU=Groups,DC=example,DC=local',
                    'description' => 'Equipe 3A',
                    'objectguid' => $equipeGuid,
                ],
            ]),
            [],
            ['Equipe_3A' => [], 'PP_3A' => []],
        );

        $service->importFromUsersAdGroups();

        $group = UserGroup::query()->where('name', '3A')->firstOrFail();
        // Classe_ absent → fallback Equipe_ (prime sur PP_).
        $this->assertSame($equipeGuid, $group->ad_guid);
        $this->assertSame('CN=Equipe_3A,OU=Equipes,OU=Groups,DC=example,DC=local', $group->ad_dn);
        $this->assertSame('classe', $group->type);
    }

    #[Test]
    public function it_unions_members_across_folded_variants(): void
    {
        // AC2 — la ligne nue reçoit l'UNION dédupliquée des membres des 3 CN.
        $service = $this->makeService(
            collect([
                $this->adGroupRow('Classe_3A'),
                $this->adGroupRow('Equipe_3A', 'OU=Equipes'),
                $this->adGroupRow('PP_3A', 'OU=Equipes'),
            ]),
            [],
            [
                'Classe_3A' => [['cn' => 'alice', 'dn' => 'CN=alice,OU=Users,DC=example,DC=local']],
                'Equipe_3A' => [['cn' => 'bob', 'dn' => 'CN=bob,OU=Users,DC=example,DC=local']],
                // bob présent aussi dans PP_ → doit être dédupliqué.
                'PP_3A' => [['cn' => 'bob', 'dn' => 'CN=bob,OU=Users,DC=example,DC=local']],
            ],
        );

        User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);
        User::query()->create(['login' => 'bob', 'role' => 'prof', 'is_active' => true]);

        $service->importFromUsersAdGroups();

        $group = UserGroup::query()->where('name', '3A')->firstOrFail();
        $logins = $group->users()->pluck('login')->sort()->values()->all();
        $this->assertSame(['alice', 'bob'], $logins);
    }

    #[Test]
    public function it_is_idempotent_across_repeated_imports(): void
    {
        // AC5 — deux syncFromAd consécutifs ne dupliquent ni ne suppriment la
        // ligne foldée, et laissent ses membres stables.
        $service = $this->makeService(
            collect([
                $this->adGroupRow('Classe_3A'),
                $this->adGroupRow('Equipe_3A', 'OU=Equipes'),
                $this->adGroupRow('PP_3A', 'OU=Equipes'),
            ]),
            [],
            [
                'Classe_3A' => [['cn' => 'alice', 'dn' => 'CN=alice,OU=Users,DC=example,DC=local']],
                'Equipe_3A' => [['cn' => 'bob', 'dn' => 'CN=bob,OU=Users,DC=example,DC=local']],
                'PP_3A' => [],
            ],
        );

        User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);
        User::query()->create(['login' => 'bob', 'role' => 'prof', 'is_active' => true]);

        $service->importFromUsersAdGroups();
        $service->importFromUsersAdGroups();

        $names = UserGroup::query()->orderBy('name')->pluck('name')->all();
        $this->assertSame(['3A'], $names);

        $group = UserGroup::query()->where('name', '3A')->firstOrFail();
        $this->assertSame(['alice', 'bob'], $group->users()->pluck('login')->sort()->values()->all());
    }

    #[Test]
    public function it_keeps_orphan_equipe_as_its_own_bare_group(): void
    {
        // AC6 / D1 — un Cours_ + son Equipe_ orphelin (pas de Classe_/PP_) :
        // Cours_Maths5A → ligne nue Maths5A type cours ; Equipe_Maths5A ne fold
        // PAS avec le cours → reste sa propre ligne nue type equipe.
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
            ['Cours_Maths5A' => [], 'Equipe_Maths5A' => []],
        );

        $service->createGroup([
            'name' => 'Maths5A',
            'display_name' => 'Maths 5A',
            'type' => 'cours',
        ]);

        $names = UserGroup::query()->orderBy('name')->pluck('name')->all();
        // Cours_ reste CN (non foldé) ; Equipe_ orphelin → ligne nue type equipe.
        $this->assertSame(['Cours_Maths5A', 'Maths5A'], $names);

        $this->assertSame('cours', UserGroup::query()->where('name', 'Cours_Maths5A')->firstOrFail()->type);
        $this->assertSame('equipe', UserGroup::query()->where('name', 'Maths5A')->firstOrFail()->type);
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
    public function it_rejects_lowercase_reserved_prefix_name_for_classe_like_create(): void
    {
        // 4.16 (Q2) — le garde-fou est INSENSIBLE À LA CASSE : un nom NU en
        // minuscule `pp_terminale` doit être rejeté tout autant que `PP_terminale`.
        // Sans ça, la saisie minuscule échappait au garde-fou et partait en
        // expansion fantôme `Classe_pp_terminale`.
        $service = $this->makeService(collect([]), []);

        $this->expectException(\InvalidArgumentException::class);

        $service->createGroup([
            'name' => 'pp_terminale',
            'display_name' => 'pp terminale',
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

    #[Test]
    public function it_does_not_re_expand_lowercase_prefixed_matiere_classe_cn(): void
    {
        // 4.16 (Q2) — un CN legacy préfixé en MINUSCULE (`matiere_Math@3emeA`)
        // ne doit pas être re-préfixé en `Matiere_matiere_Math@3emeA` (double
        // préfixe). resolvePrimaryGroupName détecte désormais `Matiere_` quelle
        // que soit la casse.
        $service = $this->makeService(
            collect([
                $this->adGroupRow('matiere_Math@3emeA', 'OU=Equipes'),
            ]),
            [],
            [
                'matiere_Math@3emeA' => [],
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
            'name' => 'matiere_Math@3emeA',
            'display_name' => 'Math 3ème A',
            'type' => 'matiere_classe',
            'user_ids' => [$prof->id],
        ]);

        // La cible reste le CN minuscule tel quel — pas de double préfixe.
        $this->assertSame(
            ['CN=prof.math,OU=Users,DC=example,DC=local'],
            $this->addedDnsFor('matiere_Math@3emeA')
        );
        $this->assertSame([], $this->addedDnsFor('Matiere_matiere_Math@3emeA'));
    }

    #[Test]
    public function it_keeps_orphan_equipe_stable_type_across_repeated_imports(): void
    {
        // Correction review #4/#9 — IDEMPOTENCE de la décision de fold.
        // Un Equipe_ orphelin (sans Classe_/PP_ dans le lot AD) doit rester
        // de type `equipe` (nom nu) sur N runs. Avant la correction, le 2e run
        // lisait l'état SQL (EXISTS sur la ligne nue déjà persistée) et faisait
        // basculer le type equipe -> classe (viole AC6).
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
            ['Cours_Maths5A' => [], 'Equipe_Maths5A' => []],
        );

        // 1er import : Equipe_ orphelin → ligne nue type equipe.
        $service->importFromUsersAdGroups();
        $this->assertSame(
            'equipe',
            UserGroup::query()->where('name', 'Maths5A')->firstOrFail()->type,
            'Run 1 : Equipe_ orphelin doit être de type equipe'
        );

        // 2e import sur le MÊME lot : le type doit RESTER equipe (idempotence).
        $service->importFromUsersAdGroups();
        $this->assertSame(
            'equipe',
            UserGroup::query()->where('name', 'Maths5A')->firstOrFail()->type,
            'Run 2 : le type ne doit PAS basculer en classe (review #4)'
        );

        // Toujours 2 lignes, pas de doublon.
        $names = UserGroup::query()->orderBy('name')->pluck('name')->all();
        $this->assertSame(['Cours_Maths5A', 'Maths5A'], $names);
    }

    #[Test]
    public function it_targets_folded_bare_names_when_syncing_selected_groups(): void
    {
        // Correction review #1/#7 — syncGroupsWithAd passe les noms NUS persistés
        // (`3A`) en onlyGroupNames, alors que les CN AD restent préfixés
        // (`Classe_3A`/…). Le filtre de syncFromAd doit matcher chaque CN sur sa
        // base nue, sinon la sync ciblée est un NO-OP (bouton « Synchroniser
        // avec AD » sans effet).
        $service = $this->makeService(
            collect([
                $this->adGroupRow('Classe_3A'),
                $this->adGroupRow('Equipe_3A', 'OU=Equipes'),
                $this->adGroupRow('PP_3A', 'OU=Equipes'),
            ]),
            [],
            [
                'Classe_3A' => [['cn' => 'alice', 'dn' => 'CN=alice,OU=Users,DC=example,DC=local']],
                'Equipe_3A' => [['cn' => 'bob', 'dn' => 'CN=bob,OU=Users,DC=example,DC=local']],
                'PP_3A' => [],
            ],
        );

        User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);
        User::query()->create(['login' => 'bob', 'role' => 'prof', 'is_active' => true]);

        // La ligne nue `3A` existe déjà en SQL (persistée par un import antérieur).
        $group = UserGroup::query()->create([
            'name' => '3A',
            'display_name' => '3A',
            'type' => 'classe',
        ]);

        // Resync ciblé sur l'ID du groupe → onlyGroupNames = ['3A'] (nom nu).
        $count = $service->syncGroupsWithAd([$group->id]);

        $this->assertSame(1, $count);

        // Les membres des 3 CN ont bien été unis sur la ligne nue (pas un no-op).
        $logins = $group->fresh()->users()->pluck('login')->sort()->values()->all();
        $this->assertSame(['alice', 'bob'], $logins);
    }

    #[Test]
    public function it_marks_head_teacher_from_pp_cn_on_import(): void
    {
        // AC8 — sur AD Classe_3A={alice}, Equipe_3A={bob}, PP_3A={bob} : après
        // syncFromAd, la ligne nue `3A` a pour membres {alice,bob} (invariant
        // 4.13) ET (3A,bob).is_head_teacher=true, (3A,alice)=false.
        $service = $this->makeService(
            collect([
                $this->adGroupRow('Classe_3A'),
                $this->adGroupRow('Equipe_3A', 'OU=Equipes'),
                $this->adGroupRow('PP_3A', 'OU=Equipes'),
            ]),
            [],
            [
                'Classe_3A' => [['cn' => 'alice', 'dn' => 'CN=alice,OU=Users,DC=example,DC=local']],
                'Equipe_3A' => [['cn' => 'bob', 'dn' => 'CN=bob,OU=Users,DC=example,DC=local']],
                'PP_3A' => [['cn' => 'bob', 'dn' => 'CN=bob,OU=Users,DC=example,DC=local']],
            ],
        );

        $alice = User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);
        $bob = User::query()->create(['login' => 'bob', 'role' => 'prof', 'is_active' => true]);

        $service->importFromUsersAdGroups();

        $group = UserGroup::query()->where('name', '3A')->firstOrFail();
        $this->assertSame(['alice', 'bob'], $group->users()->pluck('login')->sort()->values()->all());

        $this->assertTrue($this->isHeadTeacher($group->id, $bob->id), 'bob (PP) doit être PP');
        $this->assertFalse($this->isHeadTeacher($group->id, $alice->id), 'alice (élève) ne doit pas être PP');
    }

    #[Test]
    public function it_folds_lowercase_legacy_cn_variants_case_insensitively(): void
    {
        // INVARIANT CIBLE — fold INSENSIBLE À LA CASSE (correctif 4.13/4.14).
        //
        // Sur le parc RÉEL l'AD stocke des CN legacy en MINUSCULES
        // (`classe_3a`/`equipe_3a`/`pp_3a`, majoritaires). Le fold doit les
        // reconnaître au même titre que les CN canoniques SE5 :
        //   - foldPrefixOf('pp_3a') === 'PP_' (forme canonique renvoyée) ;
        //   - detectTypeFromAdGroupName('pp_3a') !== 'custom' (classé equipe) ;
        //   - stripClasseLikePrefix('classe_3a') === '3a', base nue commune.
        // Les 3 CN tout-minuscule strippent en `3a` → MÊME clé de fold → UNE
        // seule ligne nue, type classe, union des membres, flag PP posé.
        $service = $this->makeService(
            collect([
                [
                    'cn' => 'classe_3a',
                    'dn' => 'CN=classe_3a,OU=Classes,OU=Groups,DC=example,DC=local',
                    'description' => '3A',
                ],
                [
                    'cn' => 'equipe_3a',
                    'dn' => 'CN=equipe_3a,OU=Equipes,OU=Groups,DC=example,DC=local',
                    'description' => 'Equipe 3A',
                ],
                [
                    'cn' => 'pp_3a',
                    'dn' => 'CN=pp_3a,OU=Equipes,OU=Groups,DC=example,DC=local',
                    'description' => 'PP 3A',
                ],
            ]),
            [],
            [
                'classe_3a' => [['cn' => 'alice', 'dn' => 'CN=alice,OU=Users,DC=example,DC=local']],
                'equipe_3a' => [['cn' => 'bob', 'dn' => 'CN=bob,OU=Users,DC=example,DC=local']],
                'pp_3a' => [['cn' => 'bob', 'dn' => 'CN=bob,OU=Users,DC=example,DC=local']],
            ],
        );

        $alice = User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);
        $bob = User::query()->create(['login' => 'bob', 'role' => 'prof', 'is_active' => true]);

        $service->importFromUsersAdGroups();

        // (1) UNE seule ligne, au nom nu `3a`, type classe.
        $rows = UserGroup::query()->get(['name', 'type']);
        $this->assertCount(1, $rows, 'les 3 CN minuscules foldent en une seule ligne');
        $this->assertSame('3a', $rows->first()->name);
        $this->assertSame('classe', $rows->first()->type);

        // (2) Union correcte des membres des 3 CN (alice via classe, bob via
        // equipe+pp, dédupliqué).
        $group = UserGroup::query()->where('name', '3a')->firstOrFail();
        $this->assertSame(['alice', 'bob'], $group->users()->pluck('login')->sort()->values()->all());

        // (3) is_head_teacher=true posé pour bob (issu de pp_3a), false pour alice.
        $this->assertTrue($this->isHeadTeacher($group->id, $bob->id), 'bob (pp_3a) doit être PP');
        $this->assertFalse($this->isHeadTeacher($group->id, $alice->id), 'alice (élève) ne doit pas être PP');
    }

    #[Test]
    public function it_folds_mixed_case_legacy_cn_variants_into_one_group(): void
    {
        // Cas MIXTE casse — un CN canonique SE5 (`Classe_3A`) et un CN legacy
        // minuscule (`pp_3a`) cohabitent. Le regroupement par nom nu normalise la
        // clé (`3A`/`3a` → même clé lower dans buildFoldedGroups) : les deux
        // foldent en UNE ligne, et le PP issu de `pp_3a` est bien posé. Le nom
        // d'affichage suit le 1er CN rencontré (`3A`, casse de Classe_3A).
        $service = $this->makeService(
            collect([
                [
                    'cn' => 'Classe_3A',
                    'dn' => 'CN=Classe_3A,OU=Classes,OU=Groups,DC=example,DC=local',
                    'description' => '3A',
                ],
                [
                    'cn' => 'pp_3a',
                    'dn' => 'CN=pp_3a,OU=Equipes,OU=Groups,DC=example,DC=local',
                    'description' => 'PP 3A',
                ],
            ]),
            [],
            [
                'Classe_3A' => [['cn' => 'alice', 'dn' => 'CN=alice,OU=Users,DC=example,DC=local']],
                'pp_3a' => [['cn' => 'bob', 'dn' => 'CN=bob,OU=Users,DC=example,DC=local']],
            ],
        );

        $alice = User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);
        $bob = User::query()->create(['login' => 'bob', 'role' => 'prof', 'is_active' => true]);

        $service->importFromUsersAdGroups();

        // UNE seule ligne (clé de fold insensible à la casse).
        $this->assertSame(1, UserGroup::query()->count());

        $group = UserGroup::query()->firstOrFail();
        $this->assertSame('classe', $group->type);
        $this->assertSame(['alice', 'bob'], $group->users()->pluck('login')->sort()->values()->all());
        $this->assertTrue($this->isHeadTeacher($group->id, $bob->id), 'bob (pp_3a) doit être PP');
    }

    #[Test]
    public function it_marks_head_teacher_idempotently_across_repeated_imports(): void
    {
        // AC8 — un 2e syncFromAd ne change ni membres ni flags.
        $service = $this->makeService(
            collect([
                $this->adGroupRow('Classe_3A'),
                $this->adGroupRow('Equipe_3A', 'OU=Equipes'),
                $this->adGroupRow('PP_3A', 'OU=Equipes'),
            ]),
            [],
            [
                'Classe_3A' => [['cn' => 'alice', 'dn' => 'CN=alice,OU=Users,DC=example,DC=local']],
                'Equipe_3A' => [['cn' => 'bob', 'dn' => 'CN=bob,OU=Users,DC=example,DC=local']],
                'PP_3A' => [['cn' => 'bob', 'dn' => 'CN=bob,OU=Users,DC=example,DC=local']],
            ],
        );

        $alice = User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);
        $bob = User::query()->create(['login' => 'bob', 'role' => 'prof', 'is_active' => true]);

        $service->importFromUsersAdGroups();
        $service->importFromUsersAdGroups();

        $group = UserGroup::query()->where('name', '3A')->firstOrFail();
        $this->assertSame(['alice', 'bob'], $group->users()->pluck('login')->sort()->values()->all());
        $this->assertTrue($this->isHeadTeacher($group->id, $bob->id));
        $this->assertFalse($this->isHeadTeacher($group->id, $alice->id));
    }

    #[Test]
    public function it_marks_multiple_head_teachers(): void
    {
        // AC9 — PP_3A={bob,carol} : les deux arêtes valent true.
        $service = $this->makeService(
            collect([
                $this->adGroupRow('Classe_3A'),
                $this->adGroupRow('Equipe_3A', 'OU=Equipes'),
                $this->adGroupRow('PP_3A', 'OU=Equipes'),
            ]),
            [],
            [
                'Classe_3A' => [['cn' => 'alice', 'dn' => 'CN=alice,OU=Users,DC=example,DC=local']],
                'Equipe_3A' => [],
                'PP_3A' => [
                    ['cn' => 'bob', 'dn' => 'CN=bob,OU=Users,DC=example,DC=local'],
                    ['cn' => 'carol', 'dn' => 'CN=carol,OU=Users,DC=example,DC=local'],
                ],
            ],
        );

        $alice = User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);
        $bob = User::query()->create(['login' => 'bob', 'role' => 'prof', 'is_active' => true]);
        $carol = User::query()->create(['login' => 'carol', 'role' => 'prof', 'is_active' => true]);

        $service->importFromUsersAdGroups();

        $group = UserGroup::query()->where('name', '3A')->firstOrFail();
        $this->assertTrue($this->isHeadTeacher($group->id, $bob->id));
        $this->assertTrue($this->isHeadTeacher($group->id, $carol->id));
        $this->assertFalse($this->isHeadTeacher($group->id, $alice->id));
    }

    #[Test]
    public function it_clears_head_teacher_when_removed_from_pp(): void
    {
        // AC10 — bob était PP puis retiré de PP_3A (reste dans Classe_3A) : au
        // sync suivant, bob reste membre mais (3A,bob).is_head_teacher=false.
        $service = $this->makeService(
            collect([
                $this->adGroupRow('Classe_3A'),
                $this->adGroupRow('Equipe_3A', 'OU=Equipes'),
                $this->adGroupRow('PP_3A', 'OU=Equipes'),
            ]),
            [],
            [
                'Classe_3A' => [['cn' => 'bob', 'dn' => 'CN=bob,OU=Users,DC=example,DC=local']],
                'Equipe_3A' => [['cn' => 'bob', 'dn' => 'CN=bob,OU=Users,DC=example,DC=local']],
                'PP_3A' => [['cn' => 'bob', 'dn' => 'CN=bob,OU=Users,DC=example,DC=local']],
            ],
        );

        $bob = User::query()->create(['login' => 'bob', 'role' => 'prof', 'is_active' => true]);

        $service->importFromUsersAdGroups();
        $group = UserGroup::query()->where('name', '3A')->firstOrFail();
        $this->assertTrue($this->isHeadTeacher($group->id, $bob->id));

        // bob n'est plus dans PP_3A (mais toujours dans Classe_3A).
        $this->adMembersByCn['PP_3A'] = [];

        $service->importFromUsersAdGroups();
        $group = UserGroup::query()->where('name', '3A')->firstOrFail();
        $this->assertSame(['bob'], $group->users()->pluck('login')->all(), 'bob reste membre');
        $this->assertFalse($this->isHeadTeacher($group->id, $bob->id), 'le flag suit l\'état AD (pas de rémanence)');
    }

    #[Test]
    public function it_never_marks_head_teacher_on_non_class_cn(): void
    {
        // AC11 — un Cours_Histoire4A (membre prof) : la ligne existe (type cours)
        // et son arête vaut is_head_teacher=false. Le flag n'est jamais true hors
        // classe/équipe foldée.
        $service = $this->makeService(
            collect([
                $this->adGroupRow('Cours_Histoire4A', 'OU=Cours'),
            ]),
            [],
            [
                'Cours_Histoire4A' => [['cn' => 'prof', 'dn' => 'CN=prof,OU=Users,DC=example,DC=local']],
            ],
        );

        $prof = User::query()->create(['login' => 'prof', 'role' => 'prof', 'is_active' => true]);

        $service->importFromUsersAdGroups();

        $group = UserGroup::query()->where('name', 'Cours_Histoire4A')->firstOrFail();
        $this->assertSame('cours', $group->type);
        $this->assertFalse($this->isHeadTeacher($group->id, $prof->id));
    }

    // =========================================================================
    // Story 4.15 — Écriture SQL→AD 3ᵉ cible PP_<base> (is_head_teacher)
    // =========================================================================

    /**
     * Crée le service + les 3 fixtures users (prof1, prof2, eleve) d'une classe
     * `3A` avec membership AD mutable. Retourne [$service, $prof1, $prof2, $eleve].
     *
     * @return array{0:UserGroupService,1:User,2:User,3:User}
     */
    private function makeClassFixture(): array
    {
        $service = $this->makeService(
            collect([
                $this->adGroupRow('Classe_3A'),
                $this->adGroupRow('Equipe_3A', 'OU=Equipes'),
                $this->adGroupRow('PP_3A', 'OU=Equipes'),
            ]),
            [],
            ['Classe_3A' => [], 'Equipe_3A' => [], 'PP_3A' => []],
            mutableMembership: true,
        );

        $prof1 = User::query()->create([
            'login' => 'prof.un', 'role' => 'prof',
            'dn' => 'CN=prof.un,OU=Users,DC=example,DC=local', 'is_active' => true,
        ]);
        $prof2 = User::query()->create([
            'login' => 'prof.deux', 'role' => 'prof',
            'dn' => 'CN=prof.deux,OU=Users,DC=example,DC=local', 'is_active' => true,
        ]);
        $eleve = User::query()->create([
            'login' => 'eleve.un', 'role' => 'eleve',
            'dn' => 'CN=eleve.un,OU=Users,DC=example,DC=local', 'is_active' => true,
        ]);

        $this->primeNoLdap('prof.un', 'prof.deux', 'eleve.un');

        return [$service, $prof1, $prof2, $eleve];
    }

    #[Test]
    public function it_writes_head_teachers_to_pp_group(): void
    {
        // AC1 — prof1 est PP : il est écrit dans PP_3A ET reste dans Equipe_3A
        // (orthogonalité, parité rwx prof 4.12). L'élève reste dans Classe_3A.
        [$service, $prof1, $prof2, $eleve] = $this->makeClassFixture();

        $service->createGroup([
            'name' => '3A',
            'display_name' => '3A',
            'type' => 'classe',
            'user_ids' => [$prof1->id, $prof2->id, $eleve->id],
            'head_teacher_ids' => [$prof1->id],
        ]);

        $this->assertSame(
            ['CN=prof.un,OU=Users,DC=example,DC=local'],
            $this->addedDnsFor('PP_3A')
        );
        // Orthogonalité : prof1 est aussi dans Equipe_3A (avec prof2).
        $this->assertEqualsCanonicalizing(
            [
                'CN=prof.un,OU=Users,DC=example,DC=local',
                'CN=prof.deux,OU=Users,DC=example,DC=local',
            ],
            $this->addedDnsFor('Equipe_3A')
        );
        // L'élève dans Classe_3A (partition 4.12 inchangée).
        $this->assertSame(
            ['CN=eleve.un,OU=Users,DC=example,DC=local'],
            $this->addedDnsFor('Classe_3A')
        );
    }

    #[Test]
    public function it_clears_pp_group_when_no_head_teacher(): void
    {
        // AC2 — PP_3A pré-peuplé de prof1 ; on repasse head_teacher_ids=[] :
        // prof1 doit être retiré de PP_3A (pas de rémanence). Equipe_/Classe_
        // inchangés (prof1 reste membre prof).
        $service = $this->makeService(
            collect([
                $this->adGroupRow('Classe_3A'),
                $this->adGroupRow('Equipe_3A', 'OU=Equipes'),
                $this->adGroupRow('PP_3A', 'OU=Equipes'),
            ]),
            [],
            [
                'Classe_3A' => [],
                'Equipe_3A' => [['dn' => 'CN=prof.un,OU=Users,DC=example,DC=local']],
                'PP_3A' => [['dn' => 'CN=prof.un,OU=Users,DC=example,DC=local']],
            ],
            mutableMembership: true,
        );

        $prof1 = User::query()->create([
            'login' => 'prof.un', 'role' => 'prof',
            'dn' => 'CN=prof.un,OU=Users,DC=example,DC=local', 'is_active' => true,
        ]);
        $this->primeNoLdap('prof.un');

        $group = UserGroup::query()->create([
            'name' => 'Classe_3A', 'display_name' => '3A', 'type' => 'classe',
        ]);

        $service->updateGroup($group->id, [
            'name' => 'Classe_3A',
            'display_name' => '3A',
            'type' => 'classe',
            'user_ids' => [$prof1->id],
            'head_teacher_ids' => [],
        ]);

        $this->assertSame(
            ['CN=prof.un,OU=Users,DC=example,DC=local'],
            $this->removedDnsFor('PP_3A')
        );
        $this->assertSame([], $this->removedDnsFor('Equipe_3A'));
    }

    #[Test]
    public function it_writes_multiple_head_teachers(): void
    {
        // AC3 — head_teacher_ids=[prof1,prof2] : les deux dans PP_3A.
        [$service, $prof1, $prof2, $eleve] = $this->makeClassFixture();

        $service->createGroup([
            'name' => '3A',
            'display_name' => '3A',
            'type' => 'classe',
            'user_ids' => [$prof1->id, $prof2->id, $eleve->id],
            'head_teacher_ids' => [$prof1->id, $prof2->id],
        ]);

        $this->assertEqualsCanonicalizing(
            [
                'CN=prof.un,OU=Users,DC=example,DC=local',
                'CN=prof.deux,OU=Users,DC=example,DC=local',
            ],
            $this->addedDnsFor('PP_3A')
        );
    }

    #[Test]
    public function it_never_writes_pp_for_non_class_type(): void
    {
        // AC4 — un type cours : aucune écriture sur PP_<base>.
        $service = $this->makeService(
            collect([
                $this->adGroupRow('Cours_Maths5A', 'OU=Cours'),
            ]),
            [],
            ['Cours_Maths5A' => []],
            mutableMembership: true,
        );

        $prof = User::query()->create([
            'login' => 'prof.maths', 'role' => 'prof',
            'dn' => 'CN=prof.maths,OU=Users,DC=example,DC=local', 'is_active' => true,
        ]);
        $this->primeNoLdap('prof.maths');

        $service->createGroup([
            'name' => 'Maths5A',
            'display_name' => 'Maths 5A',
            'type' => 'cours',
            'user_ids' => [$prof->id],
            'head_teacher_ids' => [$prof->id],
        ]);

        $this->assertSame([], $this->addedDnsFor('PP_Maths5A'));
        $this->assertSame([], $this->removedDnsFor('PP_Maths5A'));
    }

    #[Test]
    public function it_ignores_head_teacher_not_in_members(): void
    {
        // AC5 — head_teacher_ids contient un id hors user_ids (ghost) : seul le
        // PP membre est écrit, ghost ignoré (pas d'exception).
        [$service, $prof1, $prof2, $eleve] = $this->makeClassFixture();
        $ghost = User::query()->create([
            'login' => 'ghost', 'role' => 'prof',
            'dn' => 'CN=ghost,OU=Users,DC=example,DC=local', 'is_active' => true,
        ]);
        $this->primeNoLdap('ghost');

        $service->createGroup([
            'name' => '3A',
            'display_name' => '3A',
            'type' => 'classe',
            'user_ids' => [$prof1->id, $eleve->id],
            'head_teacher_ids' => [$prof1->id, $ghost->id],
        ]);

        // ghost n'est pas membre → ignoré ; seul prof1 dans PP_3A.
        $this->assertSame(
            ['CN=prof.un,OU=Users,DC=example,DC=local'],
            $this->addedDnsFor('PP_3A')
        );
    }

    #[Test]
    public function it_persists_head_teacher_pivot_on_save(): void
    {
        // AC6 — après createGroup avec head_teacher_ids=[prof1], le pivot porte
        // (3A,prof1).is_head_teacher=true et false pour prof2/eleve. Le flag
        // converge via le read-back syncFromAd (PP_3A écrit AVANT, D2).
        [$service, $prof1, $prof2, $eleve] = $this->makeClassFixture();

        $service->createGroup([
            'name' => '3A',
            'display_name' => '3A',
            'type' => 'classe',
            'user_ids' => [$prof1->id, $prof2->id, $eleve->id],
            'head_teacher_ids' => [$prof1->id],
        ]);

        $group = UserGroup::query()->where('name', '3A')->firstOrFail();
        $this->assertTrue($this->isHeadTeacher($group->id, $prof1->id), 'prof1 (PP) doit être PP');
        $this->assertFalse($this->isHeadTeacher($group->id, $prof2->id), 'prof2 non-PP');
        $this->assertFalse($this->isHeadTeacher($group->id, $eleve->id), 'eleve non-PP');
    }

    #[Test]
    public function it_is_idempotent_across_repeated_pp_writes(): void
    {
        // AC7 — deux updateGroup consécutifs avec le même head_teacher_ids : au
        // 2e run, aucun add/remove superflu sur PP_3A (diff idempotent).
        $service = $this->makeService(
            collect([
                $this->adGroupRow('Classe_3A'),
                $this->adGroupRow('Equipe_3A', 'OU=Equipes'),
                $this->adGroupRow('PP_3A', 'OU=Equipes'),
            ]),
            [],
            ['Classe_3A' => [], 'Equipe_3A' => [], 'PP_3A' => []],
            mutableMembership: true,
        );

        $prof1 = User::query()->create([
            'login' => 'prof.un', 'role' => 'prof',
            'dn' => 'CN=prof.un,OU=Users,DC=example,DC=local', 'is_active' => true,
        ]);
        $this->primeNoLdap('prof.un');

        $group = UserGroup::query()->create([
            'name' => '3A', 'display_name' => '3A', 'type' => 'classe',
        ]);

        $payload = [
            'name' => '3A',
            'display_name' => '3A',
            'type' => 'classe',
            'user_ids' => [$prof1->id],
            'head_teacher_ids' => [$prof1->id],
        ];

        $service->updateGroup($group->id, $payload);

        // Réinitialiser le journal d'appels avant le 2e run.
        $this->membershipCalls = [];
        $service->updateGroup($group->id, $payload);

        $this->assertSame([], $this->addedDnsFor('PP_3A'), '2e run : aucun add PP_ superflu');
        $this->assertSame([], $this->removedDnsFor('PP_3A'), '2e run : aucun remove PP_ superflu');

        // Pivot stable.
        $this->assertTrue($this->isHeadTeacher($group->fresh()->id, $prof1->id));
    }

    #[Test]
    public function it_keeps_pp_stable_after_syncFromAd_roundtrip(): void
    {
        // AC8 (D2) — après updateGroup (qui appelle syncFromAd en read-back),
        // le flag PP persisté correspond au CN PP_3A projeté. Un syncFromAd
        // ultérieur ne change ni membres ni flag (pas de clignotement).
        [$service, $prof1, $prof2, $eleve] = $this->makeClassFixture();

        $group = UserGroup::query()->create([
            'name' => '3A', 'display_name' => '3A', 'type' => 'classe',
        ]);

        $service->updateGroup($group->id, [
            'name' => '3A',
            'display_name' => '3A',
            'type' => 'classe',
            'user_ids' => [$prof1->id, $prof2->id, $eleve->id],
            'head_teacher_ids' => [$prof1->id],
        ]);

        $this->assertTrue($this->isHeadTeacher($group->id, $prof1->id));
        $this->assertFalse($this->isHeadTeacher($group->id, $prof2->id));

        // syncFromAd ultérieur isolé : l'état AD PP_3A={prof1} → flag stable.
        $service->importFromUsersAdGroups();

        $reloaded = UserGroup::query()->where('name', '3A')->firstOrFail();
        $this->assertTrue($this->isHeadTeacher($reloaded->id, $prof1->id), 'flag stable après read-back');
        $this->assertFalse($this->isHeadTeacher($reloaded->id, $prof2->id));
        $this->assertSame(
            ['eleve.un', 'prof.deux', 'prof.un'],
            $reloaded->users()->pluck('login')->sort()->values()->all()
        );
    }

    #[Test]
    public function it_preserves_head_teachers_when_updateGroup_omits_head_teacher_ids(): void
    {
        // Régression M6 (post-review 4.15) — DISTINCTION clé ABSENTE vs `[]`.
        //
        // L'edit-form / removeMember appelle `updateGroup(... user_ids ...)` SANS
        // la clé `head_teacher_ids`. Avant la correction M6, `$headTeacherUserIds`
        // retombait à `[]` → `PP_<base>` était VIDÉ en AD, puis le read-back
        // `syncFromAd` effaçait le pivot `is_head_teacher` : perte SILENCIEUSE du
        // PP sur une édition sans rapport. On prouve ici que :
        //  (a) la clé ABSENTE PRÉSERVE les PP existants (PP_3A garde prof1, pivot
        //      reste true) ;
        //  (b) `head_teacher_ids => []` EXPLICITE vide bien PP_3A (effacement
        //      volontaire) ;
        //  (c) retirer un membre PP (user_ids sans ce prof, sans la clé) le retire
        //      de PP_ par intersection MAIS préserve les autres PP encore membres.

        // -- (a) clé ABSENTE → PP préservé ------------------------------------
        // Groupe au NOM NU `3A` (comme la ligne foldée persistée) : le read-back
        // `syncFromAd` projette le pivot sur cette même ligne nue (cf. AC8).
        [$service, $prof1, $prof2, $eleve] = $this->makeClassFixture();

        $group = UserGroup::query()->create([
            'name' => '3A', 'display_name' => '3A', 'type' => 'classe',
        ]);

        // 1re écriture : prof1 + prof2 PP (pose le pivot via le read-back).
        $service->updateGroup($group->id, [
            'name' => '3A',
            'display_name' => '3A',
            'type' => 'classe',
            'user_ids' => [$prof1->id, $prof2->id, $eleve->id],
            'head_teacher_ids' => [$prof1->id, $prof2->id],
        ]);

        // Pré-condition : prof1 ET prof2 sont membres de PP_3A en AD, pivot true.
        $this->assertEqualsCanonicalizing(
            [
                'CN=prof.un,OU=Users,DC=example,DC=local',
                'CN=prof.deux,OU=Users,DC=example,DC=local',
            ],
            collect($this->adMembersByCn['PP_3A'])->pluck('dn')->all(),
            'pré-condition : PP_3A contient prof1 + prof2'
        );
        $this->assertTrue($this->isHeadTeacher($group->id, $prof1->id));
        $this->assertTrue($this->isHeadTeacher($group->id, $prof2->id));

        // Réinitialiser le journal pour n'observer que le 2e appel.
        $this->membershipCalls = [];

        // 2e appel : edit-form sauve la liste de membres SANS `head_teacher_ids`.
        $service->updateGroup($group->id, [
            'name' => '3A',
            'display_name' => '3A',
            'type' => 'classe',
            'user_ids' => [$prof1->id, $prof2->id, $eleve->id],
            // pas de head_teacher_ids → la correction M6 dérive les PP du pivot.
        ]);

        // PP_3A contient TOUJOURS prof1 ET prof2 (aucun retrait silencieux)…
        $this->assertEqualsCanonicalizing(
            [
                'CN=prof.un,OU=Users,DC=example,DC=local',
                'CN=prof.deux,OU=Users,DC=example,DC=local',
            ],
            collect($this->adMembersByCn['PP_3A'])->pluck('dn')->all(),
            'M6 : clé absente → PP_3A préservé en AD'
        );
        // …et aucun remove n'a été émis sur PP_3A (PP dérivés du pivot).
        $this->assertSame([], $this->removedDnsFor('PP_3A'), 'M6 : aucun retrait PP_ sur édition sans la clé');
        // Le pivot reste true pour les deux PP.
        $this->assertTrue($this->isHeadTeacher($group->id, $prof1->id), 'M6 : pivot prof1 toujours PP');
        $this->assertTrue($this->isHeadTeacher($group->id, $prof2->id), 'M6 : pivot prof2 toujours PP');

        // -- (c) retrait d'UN PP via removeMember (clé absente) ----------------
        // On retire prof2 des membres (sans head_teacher_ids) : prof2 quitte PP_
        // par intersection, prof1 (toujours membre + PP) est préservé.
        $this->membershipCalls = [];
        $service->updateGroup($group->id, [
            'name' => '3A',
            'display_name' => '3A',
            'type' => 'classe',
            'user_ids' => [$prof1->id, $eleve->id],
            // toujours sans head_teacher_ids.
        ]);

        $this->assertSame(
            ['CN=prof.deux,OU=Users,DC=example,DC=local'],
            $this->removedDnsFor('PP_3A'),
            'M6 : prof2 retiré des membres → retiré de PP_ (intersection)'
        );
        $this->assertSame(
            ['CN=prof.un,OU=Users,DC=example,DC=local'],
            collect($this->adMembersByCn['PP_3A'])->pluck('dn')->all(),
            'M6 : prof1 (encore membre + PP) préservé dans PP_3A'
        );
        $this->assertTrue($this->isHeadTeacher($group->id, $prof1->id), 'M6 : prof1 reste PP après retrait de prof2');

        // -- (b) `[]` EXPLICITE vide bien PP_ ---------------------------------
        $this->membershipCalls = [];
        $service->updateGroup($group->id, [
            'name' => '3A',
            'display_name' => '3A',
            'type' => 'classe',
            'user_ids' => [$prof1->id, $eleve->id],
            'head_teacher_ids' => [], // effacement VOLONTAIRE.
        ]);

        $this->assertSame(
            ['CN=prof.un,OU=Users,DC=example,DC=local'],
            $this->removedDnsFor('PP_3A'),
            'M6 : [] explicite retire le dernier PP'
        );
        $this->assertSame([], collect($this->adMembersByCn['PP_3A'])->pluck('dn')->all(), 'M6 : PP_3A vidé');
        $this->assertFalse($this->isHeadTeacher($group->id, $prof1->id), 'M6 : pivot effacé après [] explicite');
    }

    #[Test]
    public function it_skips_ad_description_write_when_description_unchanged(): void
    {
        // Story 4.15 (Q1/M1) — un toggle PP (oldName==newName, display_name
        // INCHANGÉ) ne doit PAS déclencher d'écriture LDAP de description.
        [$service, $prof1, $prof2, $eleve] = $this->makeClassFixture();

        $group = UserGroup::query()->create([
            'name' => '3A', 'display_name' => '3A', 'type' => 'classe',
        ]);

        // Réinitialiser le journal (la création de fixture peut avoir appelé l'AD).
        $this->descriptionUpdateCalls = [];

        // Toggle PP : même nom, même display_name → description inchangée.
        $service->updateGroup($group->id, [
            'name' => '3A',
            'display_name' => '3A',
            'type' => 'classe',
            'user_ids' => [$prof1->id, $prof2->id, $eleve->id],
            'head_teacher_ids' => [$prof1->id],
        ]);

        $this->assertSame(
            [],
            $this->descriptionUpdateCalls,
            'Q1 : description inchangée → aucun write LDAP de description'
        );
        // Le toggle PP a bien convergé (rien n'a cassé).
        $this->assertTrue($this->isHeadTeacher($group->id, $prof1->id));
    }

    #[Test]
    public function it_writes_ad_description_when_display_name_changes(): void
    {
        // Story 4.15 (Q1) — un changement réel de display_name déclenche TOUJOURS
        // l'écriture LDAP de description (comportement nominal préservé).
        [$service, $prof1, $prof2, $eleve] = $this->makeClassFixture();

        $group = UserGroup::query()->create([
            'name' => '3A', 'display_name' => '3A', 'type' => 'classe',
        ]);

        $this->descriptionUpdateCalls = [];

        $service->updateGroup($group->id, [
            'name' => '3A',
            'display_name' => '3ème A (rénovée)',
            'type' => 'classe',
            'user_ids' => [$prof1->id, $prof2->id, $eleve->id],
        ]);

        $this->assertCount(
            1,
            $this->descriptionUpdateCalls,
            'Q1 : description changée → exactement un write LDAP'
        );
        $this->assertSame('3A', $this->descriptionUpdateCalls[0]['cn']);
        $this->assertSame('3ème A (rénovée)', $this->descriptionUpdateCalls[0]['description']);
    }

    // =========================================================================
    // Story 4.16 — scoping du read-back syncFromAd() de updateGroup
    // =========================================================================

    #[Test]
    public function it_scopes_read_back_to_edited_group_on_update(): void
    {
        // AC1 + AC2 — updateGroup du groupe `3A` (classe) :
        //  - le read-back scopé `onlyGroupNames=['3A']` voit les 3 variantes
        //    Classe_3A/Equipe_3A/PP_3A (le filtre matche le nom nu),
        //  - la ligne nue `3A` reçoit l'union des membres {alice, bob},
        //  - le flag is_head_teacher est re-posé depuis PP_3A (bob=PP).
        // Calqué sur it_targets_folded_bare_names_when_syncing_selected_groups.
        $service = $this->makeService(
            collect([
                $this->adGroupRow('Classe_3A'),
                $this->adGroupRow('Equipe_3A', 'OU=Equipes'),
                $this->adGroupRow('PP_3A', 'OU=Equipes'),
            ]),
            [],
            [
                'Classe_3A' => [['cn' => 'alice', 'dn' => 'CN=alice,OU=Users,DC=example,DC=local']],
                'Equipe_3A' => [['cn' => 'bob', 'dn' => 'CN=bob,OU=Users,DC=example,DC=local']],
                'PP_3A' => [['cn' => 'bob', 'dn' => 'CN=bob,OU=Users,DC=example,DC=local']],
            ],
        );

        User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);
        $bob = User::query()->create(['login' => 'bob', 'role' => 'prof', 'is_active' => true]);
        $this->primeNoLdap('alice', 'bob');

        $group = UserGroup::query()->create([
            'name' => '3A', 'display_name' => '3A', 'type' => 'classe',
        ]);

        // updateGroup sans members (path sans syncRoleAwareAdGroupMembers) :
        // le seul effet est le read-back scopé qui projette les 3 CN.
        $updated = $service->updateGroup($group->id, [
            'name' => '3A',
            'display_name' => '3A',
            'type' => 'classe',
        ]);

        // AC1 — la ligne nue est retournée.
        $this->assertSame('3A', $updated->name);

        // AC2 — les 3 variantes ont bien été repliées (union des membres).
        $logins = $updated->users()->pluck('login')->sort()->values()->all();
        $this->assertSame(['alice', 'bob'], $logins);

        // AC2 — flag PP re-posé depuis PP_3A (bob est PP).
        $this->assertTrue($this->isHeadTeacher($group->id, $bob->id), '4.16 : flag PP bob re-posé par le read-back scopé');
        $alice = User::query()->where('login', 'alice')->firstOrFail();
        $this->assertFalse($this->isHeadTeacher($group->id, $alice->id), '4.16 : alice non PP');
    }

    #[Test]
    public function it_does_not_purge_out_of_scope_groups_on_update(): void
    {
        // AC4 — un second groupe SQL `5C` (absent du lot AD renvoyé lors de
        // l'édition de `3A`) ne doit PAS être supprimé par updateGroup('3A').
        // Preuve que whereNotIn ne tourne PAS en mode scopé.
        $service = $this->makeService(
            collect([
                $this->adGroupRow('Classe_3A'),
                $this->adGroupRow('Equipe_3A', 'OU=Equipes'),
                $this->adGroupRow('PP_3A', 'OU=Equipes'),
                // Note : Classe_5C/Equipe_5C/PP_5C sont ABSENTS du lot AD mocké —
                // simule une réponse AD incomplète ou un groupe hors établissement.
            ]),
            [],
            [
                'Classe_3A' => [],
                'Equipe_3A' => [],
                'PP_3A' => [],
            ],
        );

        // Groupe hors scope préexistant en SQL.
        UserGroup::query()->create([
            'name' => '5C', 'display_name' => '5C', 'type' => 'classe',
        ]);

        $group = UserGroup::query()->create([
            'name' => '3A', 'display_name' => '3A', 'type' => 'classe',
        ]);

        $service->updateGroup($group->id, [
            'name' => '3A',
            'display_name' => '3A',
            'type' => 'classe',
        ]);

        // AC4 — `5C` survit : whereNotIn n'a pas tourné en mode scopé.
        $this->assertTrue(
            UserGroup::query()->where('name', '5C')->exists(),
            '4.16 : le groupe hors scope 5C ne doit PAS être purgé par updateGroup(3A)'
        );
    }

    #[Test]
    public function it_scopes_read_back_to_new_name_on_rename(): void
    {
        // AC3 — rename 3A→3B : l'AD porte déjà Classe_3B/Equipe_3B/PP_3B
        // au moment du read-back. Le scope doit cibler la base nue du NOUVEAU nom
        // `3B` (D2). Un scope sur `3A` (ancien nom) ne verrait rien et la ligne
        // ne convergerait pas.
        $service = $this->makeService(
            collect([
                // Après rename AD, seuls les CN `_3B` existent ; `_3A` disparus.
                $this->adGroupRow('Classe_3B'),
                $this->adGroupRow('Equipe_3B', 'OU=Equipes'),
                $this->adGroupRow('PP_3B', 'OU=Equipes'),
            ]),
            [],
            [
                'Classe_3B' => [['cn' => 'alice', 'dn' => 'CN=alice,OU=Users,DC=example,DC=local']],
                'Equipe_3B' => [['cn' => 'bob', 'dn' => 'CN=bob,OU=Users,DC=example,DC=local']],
                'PP_3B' => [],
            ],
        );

        User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);
        User::query()->create(['login' => 'bob', 'role' => 'prof', 'is_active' => true]);
        $this->primeNoLdap('alice', 'bob');

        // Groupe préexistant au NOM `3A` (la ligne SQL avant rename).
        $group = UserGroup::query()->create([
            'name' => '3A', 'display_name' => '3A', 'type' => 'classe',
        ]);

        // Groupe hors scope `5C` — doit survivre.
        UserGroup::query()->create([
            'name' => '5C', 'display_name' => '5C', 'type' => 'classe',
        ]);

        // Rename 3A → 3B.
        $updated = $service->updateGroup($group->id, [
            'name' => '3B',
            'display_name' => '3B',
            'type' => 'classe',
        ]);

        // AC3 — la ligne SQL converge sur le NOUVEAU nom `3B`.
        $this->assertSame('3B', $updated->name, '4.16 : ligne convergée sur 3B après rename');

        // Les membres des 3 CN 3B ont bien été projetés.
        $logins = $updated->users()->pluck('login')->sort()->values()->all();
        $this->assertSame(['alice', 'bob'], $logins, '4.16 : membres 3B projetés');

        // AC4 — `5C` hors scope n'a pas été purgé.
        $this->assertTrue(
            UserGroup::query()->where('name', '5C')->exists(),
            '4.16 : groupe 5C non purgé lors du rename 3A→3B'
        );
    }

    #[Test]
    public function it_scopes_read_back_for_non_class_type(): void
    {
        // AC5 — updateGroup d'un groupe de type `cours` (nom payload = `Maths`,
        // CN AD = `Cours_Maths`). resolveSqlLookupName('Maths', 'cours') renvoie
        // le CN brut `Cours_Maths` (via resolvePrimaryGroupName) ; le filtre
        // onlyGroupNames matche ce CN brut directement.
        // La ligne SQL `Cours_Maths` converge et aucun autre groupe n'est purgé.
        //
        // Convention : le payload `name` pour les types non-classe est le nom NU
        // sans préfixe (ex. `Maths`), cohérent avec createGroup (cf. test
        // it_keeps_single_target_for_non_classe_types qui passe `Maths5A`).
        $service = $this->makeService(
            collect([
                $this->adGroupRow('Cours_Maths', 'OU=Cours'),
            ]),
            [],
            [
                'Cours_Maths' => [['cn' => 'prof.maths', 'dn' => 'CN=prof.maths,OU=Users,DC=example,DC=local']],
            ],
        );

        User::query()->create([
            'login' => 'prof.maths', 'role' => 'prof',
            'dn' => 'CN=prof.maths,OU=Users,DC=example,DC=local', 'is_active' => true,
        ]);
        $this->primeNoLdap('prof.maths');

        // Groupe hors scope préexistant.
        UserGroup::query()->create([
            'name' => 'Cours_Phys', 'display_name' => 'Cours_Phys', 'type' => 'cours',
        ]);

        // La ligne SQL existante porte le CN brut (tel que projeté par syncFromAd).
        $group = UserGroup::query()->create([
            'name' => 'Cours_Maths', 'display_name' => 'Cours Maths', 'type' => 'cours',
        ]);

        // Le payload `name` est le nom NU `Maths` (sans préfixe Cours_) ;
        // resolveSqlLookupName renvoie `Cours_Maths` = scope ET clé de lookup.
        $updated = $service->updateGroup($group->id, [
            'name' => 'Maths',
            'display_name' => 'Cours Maths',
            'type' => 'cours',
        ]);

        // AC5 — la ligne SQL `Cours_Maths` est retrouvée et convergée.
        $this->assertSame('Cours_Maths', $updated->name, '4.16 : ligne Cours_Maths convergée');

        $logins = $updated->users()->pluck('login')->sort()->values()->all();
        $this->assertSame(['prof.maths'], $logins, '4.16 : membre prof.maths projeté sur Cours_Maths');

        // AC4 — `Cours_Phys` hors scope n'a pas été purgé.
        $this->assertTrue(
            UserGroup::query()->where('name', 'Cours_Phys')->exists(),
            '4.16 : Cours_Phys non purgé lors de updateGroup(Maths/Cours_Maths)'
        );
    }

    #[Test]
    public function it_scopes_read_back_to_edited_group_on_update_with_lowercase_ad_cns(): void
    {
        // 4.16 (#5) — CAS RÉEL : l'AD du parc porte des CN legacy en MINUSCULE
        // (`classe_3a`/`equipe_3a`/`pp_3a`, cf. project_vm_ad_junk_classe_groups).
        // C'est le seul motif qui rend le fix casse-insensible nécessaire : sans
        // lui, `foldPrefixOf('classe_3a')` renverrait null, le filtre `onlyGroupNames`
        // exclurait les 3 CN, le read-back scopé serait un no-op et la ligne ne
        // convergerait pas (RuntimeException « introuvable après synchronisation »).
        // Ce test garde le chemin scopé d'updateGroup contre une régression
        // réintroduisant `str_starts_with` dans le filtre.
        $service = $this->makeService(
            collect([
                $this->adGroupRow('classe_3a'),
                $this->adGroupRow('equipe_3a', 'OU=Equipes'),
                $this->adGroupRow('pp_3a', 'OU=Equipes'),
            ]),
            [],
            [
                'classe_3a' => [['cn' => 'alice', 'dn' => 'CN=alice,OU=Users,DC=example,DC=local']],
                'equipe_3a' => [['cn' => 'bob', 'dn' => 'CN=bob,OU=Users,DC=example,DC=local']],
                'pp_3a' => [['cn' => 'bob', 'dn' => 'CN=bob,OU=Users,DC=example,DC=local']],
            ],
        );

        User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);
        $bob = User::query()->create(['login' => 'bob', 'role' => 'prof', 'is_active' => true]);
        $this->primeNoLdap('alice', 'bob');

        // La ligne SQL existante porte le nom nu minuscule `3a` (tel que projeté
        // par le fold casse-insensible des CN minuscules).
        $group = UserGroup::query()->create([
            'name' => '3a', 'display_name' => '3a', 'type' => 'classe',
        ]);

        // Groupe hors scope — doit survivre (whereNotIn ne tourne pas en scopé).
        UserGroup::query()->create([
            'name' => '5c', 'display_name' => '5c', 'type' => 'classe',
        ]);

        $updated = $service->updateGroup($group->id, [
            'name' => '3a',
            'display_name' => '3a',
            'type' => 'classe',
        ]);

        // Le read-back scopé a VU les 3 CN minuscules → ligne convergée.
        $this->assertSame('3a', $updated->name);

        // Union des membres des 3 variantes minuscules projetée.
        $logins = $updated->users()->pluck('login')->sort()->values()->all();
        $this->assertSame(['alice', 'bob'], $logins, '4.16 : membres des CN minuscules projetés');

        // Flag PP re-posé depuis `pp_3a` malgré la casse.
        $this->assertTrue($this->isHeadTeacher($group->id, $bob->id), '4.16 : flag PP bob re-posé depuis pp_3a');

        // Groupe hors scope `5c` non purgé.
        $this->assertTrue(
            UserGroup::query()->where('name', '5c')->exists(),
            '4.16 : groupe hors scope 5c non purgé'
        );
    }

    /**
     * Lit `is_head_teacher` brut sur l'arête (cross-driver : cast en bool).
     */
    private function isHeadTeacher(int $groupId, int $userId): bool
    {
        $value = \Illuminate\Support\Facades\DB::table('user_group_user')
            ->where('user_group_id', $groupId)
            ->where('user_id', $userId)
            ->value('is_head_teacher');

        return (bool) $value;
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
     * Story 4.15 (Q1) — journal des appels `updateGroupDescription`.
     * Forme : [['cn' => string, 'description' => string], …]
     *
     * @var array<int,array{cn:string,description:string}>
     */
    private array $descriptionUpdateCalls = [];

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
        $this->descriptionUpdateCalls = [];
        $this->adMembersByCn = $groupMembersByCn;

        $groupRepository = $this->createMock(GroupRepository::class);
        $groupRepository->method('getGroupsWithMemberCount')->willReturn($groupsWithMemberCount);
        $groupRepository->method('createGroup')->willReturn(true);
        $groupRepository->method('deleteGroup')->willReturn(true);
        // 4.16 — renameGroup retourne toujours vrai dans les tests (l'AD est mocké).
        $groupRepository->method('renameGroup')->willReturn(true);

        // Story 4.15 (Q1) — journaliser les appels description pour prouver
        // qu'un toggle PP (display_name inchangé, oldName==newName) ne déclenche
        // AUCUN write LDAP de description, et qu'un changement le déclenche.
        $groupRepository->method('updateGroupDescription')->willReturnCallback(
            function (string $cn, string $description): bool {
                $this->descriptionUpdateCalls[] = ['cn' => $cn, 'description' => $description];

                return true;
            }
        );

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
            function (string $cn): Collection {
                // Le read-back `syncFromAd` résout les membres par `cn` (login).
                // Une écriture AD réelle (`addMember`) ne stocke que le `dn` ;
                // on dérive donc le `cn` du DN À LA LECTURE quand il manque, sans
                // muter l'état stocké (préserve les assertions sur adMembersByCn).
                return collect($this->adMembersByCn[$cn] ?? [])->map(
                    static function (array $member): array {
                        if (!isset($member['cn']) && isset($member['dn'])
                            && preg_match('/^CN=([^,]+)/i', (string) $member['dn'], $m) === 1) {
                            $member['cn'] = $m[1];
                        }
                        return $member;
                    }
                );
            }
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
                // Story 4.14 — colonne d'arête (parité avec la migration).
                $table->boolean('is_head_teacher')->default(false);
                $table->primary(['user_group_id', 'user_id']);
            });
            $this->createdTables = true;
        }
    }
}
